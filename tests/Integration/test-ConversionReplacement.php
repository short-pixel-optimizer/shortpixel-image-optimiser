<?php
/**
 * Integration tests: content-URL replacement across the conversion pipeline
 * with autoMediaLibrary ON (auto-convert at upload / via queue).
 *
 * Covers PNG (local GD converter, driven through the queue path so
 * runReplacer=true fires — the upload-hook path uses runReplacer=false and
 * never rewrites content) and HEIC / TIFF / BMP (ApiConverter path).
 *
 * Content shapes exercised for the PNG queue path:
 *   - Classic post <img src>            (status: publish)
 *   - Gutenberg wp:image block          (status: draft)
 *   - Scheduled post                    (status: future)
 *   - Page                              (post_type: page)
 *   - Plain postmeta (string)
 *   - Serialized-array postmeta
 *   - Elementor-shaped _elementor_data  (JSON, escaped slashes)
 *   - WPBakery-shaped post_content      (urlencoded URL)
 *   - Serialized-OBJECT postmeta        (regression guard — must NOT be
 *     rewritten and must NOT trigger object instantiation)
 *
 * PNG driving choice (documented): the upload-hook path (AdminController::
 * handleImageUploadHook → PNGConverter::convert with runReplacer=false)
 * NEVER runs the replacer, so a content-replacement assertion against it
 * would be vacuous. We therefore drive the queue path (fixture uploaded
 * with png2jpg=0, content created, png2jpg flipped to 1, then optimize),
 * which invokes PNGConverter::filterQueue → the png2jpg queue action →
 * PNGConverter::convert() with runReplacer=true. This is the meaningful
 * content-replacement path in production too (bulk-optimize on
 * previously-uploaded PNGs).
 *
 * PNG fixture choice (documented): `fixture-large.png` is already so
 * heavily PNG-optimized that GD's JPG re-encode is BIGGER than the
 * source, so PNGConverter::checkFileSizeMargin silently rejects
 * (ERROR_RESULTLARGER) and the .png stays on disk. We use
 * `fixture-small.png` which shrinks correctly on re-encode.
 *
 * ApiConverter path — CURRENT BEHAVIOR (documented):
 *   The ApiConverter placeholder mechanism (see class/Controller/
 *   AdminController.php:252 `checkPlaceHolder` filter + ApiConverter::
 *   prepareQueue, class/Model/Converter/ApiConverter.php:125) means
 *   wp_get_attachment_url swaps the file extension to .jpg BEFORE
 *   any content is authored — so real WP content always embeds .jpg
 *   URLs for these formats, and there is nothing for the post-convert
 *   Replacer to rewrite. Additionally, ApiConverter::handleConverted
 *   sets both source_url and target_url on the fresh Replacer to the
 *   same .jpg URL (source is overwritten at ApiConverter.php:274 when
 *   hasPlaceHolder is true; no explicit setTarget() call is made on
 *   the fresh Replacer instance, so target_url is null and
 *   Replacer::replace() short-circuits early — see class/../
 *   replacer2/src/Replacer.php:129).
 *   Net effect: the pre-existing content URL is .jpg from upload
 *   time onwards, and remains .jpg. Test
 *   test_api_conversion_content_urls_survive_placeholder_and_final_swap
 *   PINS this behavior so any future change surfaces via a failing
 *   sentinel.
 *
 * Deferred / out of scope:
 *   - YoastSeo module: gated on defined('WPSEO_VERSION') only, no
 *     override filter — cannot be exercised in-process without a
 *     separate-process test that also creates the wp_yoast_indexable
 *     table. Left to the compat suite.
 *   - Breakdance module: requires real \Breakdance\Data\* functions —
 *     compat suite.
 *   - SmartSlider module: needs its own DB table; not exercised here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class ConversionReplacementTest extends SPIO_IntegrationTestCase {

	/** @var array Filters we register per-test and must remove in tear_down. */
	private $tempFilters = array();

	public function tear_down() {
		foreach ( $this->tempFilters as $entry ) {
			remove_filter( $entry[0], $entry[1], isset( $entry[2] ) ? $entry[2] : 10 );
		}
		$this->tempFilters = array();

		$this->resetReplacerModuleSingletons();

		// Backup files live outside the DB transaction — sweep the tree so
		// one test's backups can't satisfy another test's assertion.
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
		}

		parent::tear_down();
	}

	/** Fresh (uncached) image model for an attachment. */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Register a filter for this test and record it so tear_down removes it.
	 *
	 * The Replacer2 module singletons cache their `add_filter` state at
	 * construction — activating a module mid-test therefore also requires
	 * resetting BOTH the Replacer singleton AND the target module singleton,
	 * so the next Replacer instantiation reruns loadFormats() and the
	 * module constructor sees the filter and self-registers.
	 */
	private function addTempFilter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $cb, $priority, $args );
		$this->tempFilters[] = array( $hook, $cb, $priority );
	}

	/** Null the private static $instance on Replacer + every Modules\* class. */
	private function resetReplacerModuleSingletons(): void {
		$classes = array(
			\ShortPixel\Replacer\Replacer::class,
			\ShortPixel\Replacer\Modules\Elementor::class,
			\ShortPixel\Replacer\Modules\WpBakery::class,
			\ShortPixel\Replacer\Modules\YoastSeo::class,
			\ShortPixel\Replacer\Modules\Breakdance::class,
		);
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$ref = new ReflectionClass( $class );
			if ( $ref->hasProperty( 'instance' ) ) {
				$prop = $ref->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		}
	}

	/**
	 * Upload a PNG WITHOUT triggering conversion at upload (png2jpg=0),
	 * then flip png2jpg to 1 so the subsequent optimize goes through the
	 * queue path (PNGConverter::filterQueue → runReplacer=true). Returns
	 * the attachment id.
	 *
	 * Uses `fixture-small.png` by default — the "large" fixture (3200×2400,
	 * already heavily-optimized PNG) yields a JPG *bigger* than the PNG, so
	 * PNGConverter::checkFileSizeMargin rejects it (ERROR_RESULTLARGER,
	 * silent) and the .png stays on disk. That's real production behavior
	 * for over-optimized source PNGs, but it makes the conversion
	 * assertion vacuous — pick a fixture that actually shrinks.
	 */
	private function uploadPngForQueuePath( string $fixture = 'fixture-small.png' ): int {
		\wpSPIO()->settings()->png2jpg = 0;
		$id = $this->uploadFixture( $fixture );
		\wpSPIO()->settings()->png2jpg = 1;
		$this->resetPluginSingletons();
		// resetPluginSingletons reloads SettingsModel from DB → in-memory
		// mutations vanish unless persisted. Rewrite AFTER the reset.
		\wpSPIO()->settings()->png2jpg = 1;
		return $id;
	}

	/**
	 * Upload an API-convertable format (heic / tiff / bmp) through the
	 * standard upload hook (autoMediaLibrary=1). The hook runs
	 * ApiConverter::filterQueue → prepareQueue synchronously, which:
	 *   - writes a placeholder .jpg alongside the original on disk
	 *   - sets convertMeta->hasPlaceHolder=true (so checkPlaceHolder
	 *     filter starts rewriting wp_get_attachment_url output to .jpg)
	 *   - enqueues a `convert_api` item to be processed by the queue
	 *
	 * By the time seedContent() runs, wp_get_attachment_url returns the
	 * .jpg URL — that's what real WordPress users would embed too.
	 */
	private function uploadForApiConversion( string $fixture ): int {
		$this->purgeQueueTable();
		return $this->uploadFixture( $fixture );
	}

	/**
	 * Build every content carrier once, keyed by (post_id | meta) with
	 * their raw pre-conversion content — used later to assert BOTH that
	 * the old URL was present (sentinel principle 5) and that the new
	 * URL replaced it.
	 *
	 * @return array{
	 *   posts: array<string, int>,
	 *   meta:  array<string, array{post: int, key: string}>,
	 *   raw:   array<string, string>,
	 * }
	 */
	private function seedContent( int $attachment_id, ?string $thumbnail_url = null ): array {
		$url = wp_get_attachment_url( $attachment_id );

		$classic     = '<p>See it: <img src="' . esc_url( $url ) . '" alt="classic" /></p>';
		$gutenberg   = '<!-- wp:image {"id":' . $attachment_id . '} --><figure class="wp-block-image"><img class="wp-image-' . $attachment_id . '" src="' . esc_url( $url ) . '" alt="gb" /></figure><!-- /wp:image -->';
		if ( null !== $thumbnail_url ) {
			$classic .= '<p>Thumb: <img src="' . esc_url( $thumbnail_url ) . '" alt="thumb" /></p>';
		}

		$posts = array(
			'publish'   => self::factory()->post->create( array(
				'post_status'  => 'publish',
				'post_content' => $classic,
			) ),
			'draft'     => self::factory()->post->create( array(
				'post_status'  => 'draft',
				'post_content' => $gutenberg,
			) ),
			'future'    => self::factory()->post->create( array(
				'post_status'  => 'future',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_content' => $classic,
			) ),
			'page'      => self::factory()->post->create( array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $classic,
			) ),
			'wpbakery'  => self::factory()->post->create( array(
				'post_status'  => 'publish',
				// Realistic shortcode-with-image_url style used by WPBakery/VC:
				// URL is urlencoded (every /, :, . encoded).
				'post_content' => '[vc_single_image image_url="' . urlencode( $url ) . '"]',
			) ),
		);

		// Plain-string postmeta on a dedicated carrier.
		$meta_carrier    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => 'meta carrier' ) );
		add_post_meta( $meta_carrier, '_spio_test_string', $url );

		// Serialized-array postmeta.
		add_post_meta(
			$meta_carrier,
			'_spio_test_array',
			array(
				'main'  => $url,
				'nested' => array( 'src' => $url ),
			)
		);

		// Elementor-shaped _elementor_data: JSON string with escaped slashes,
		// as it is stored by Elementor Data\Manager\::save_element_data.
		add_post_meta(
			$meta_carrier,
			'_elementor_data',
			wp_slash( wp_json_encode(
				array(
					array(
						'id'       => 'abc123',
						'elType'   => 'widget',
						'settings' => array(
							'image' => array( 'url' => $url, 'id' => $attachment_id ),
						),
					),
				),
				JSON_UNESCAPED_SLASHES
			) )
		);

		// Serialized-OBJECT postmeta (security regression guard: URLs
		// inside object blobs are intentionally NOT replaced, and NO
		// object of an unknown class may be instantiated. Use a stdClass
		// so we can round-trip round the assertion.
		$obj      = new stdClass();
		$obj->url = $url;
		$obj->tag = 'sentinel';
		$serialized_object = serialize( $obj );
		// Store via update_option to sidestep maybe_serialize on postmeta
		// which would re-serialize on read/write.
		update_option( '_spio_test_object_blob', $serialized_object );

		return array(
			'posts' => $posts,
			'meta'  => array(
				'string'       => array( 'post' => $meta_carrier, 'key' => '_spio_test_string' ),
				'array'        => array( 'post' => $meta_carrier, 'key' => '_spio_test_array' ),
				'elementor'    => array( 'post' => $meta_carrier, 'key' => '_elementor_data' ),
			),
			'object_blob'   => $serialized_object,
			'raw'           => array(
				'url'           => $url,
				'thumbnail_url' => $thumbnail_url,
			),
		);
	}

	/** Assert (sentinel) that every content-carrier holds the pre-conversion URL. */
	private function assertPreConversionState( array $seeded, string $extension ): void {
		$url = $seeded['raw']['url'];
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;
			if ( 'wpbakery' === $label ) {
				$this->assertStringContainsString(
					urlencode( $url ),
					$content,
					"Sentinel: pre-conversion urlencoded URL must be present in the wpbakery carrier for .$extension."
				);
			} else {
				$this->assertStringContainsString(
					'.' . $extension,
					$content,
					"Sentinel: pre-conversion .$extension must be present in the $label carrier."
				);
			}
		}

		foreach ( $seeded['meta'] as $label => $entry ) {
			wp_cache_delete( $entry['post'], 'post_meta' );
			$value = get_post_meta( $entry['post'], $entry['key'], true );
			$flat  = is_string( $value ) ? $value : wp_json_encode( $value );
			$this->assertStringContainsString(
				'.' . $extension,
				$flat,
				"Sentinel: pre-conversion .$extension must be present in the $label meta carrier."
			);
		}

		// Object blob must literally start with 'O:' (PHP object serialization
		// tag) so the "must remain byte-identical after conversion" assertion
		// exercises the intended shape.
		$this->assertStringStartsWith(
			'O:',
			$seeded['object_blob'],
			'Sentinel: object_blob must be an O:… serialized object literal.'
		);
	}

	/** Assert that every content-carrier's old-extension URL was rewritten to .jpg. */
	private function assertPostConversionReplaced( array $seeded, string $old_extension ): void {
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;

			if ( 'wpbakery' === $label ) {
				// The wpbakery module rewrites the urlencoded form of the URL;
				// the raw non-encoded form is not present in this carrier at all.
				$this->assertStringNotContainsString(
					urlencode( $seeded['raw']['url'] ),
					$content,
					"WPBakery carrier ($label) must no longer contain the original urlencoded .$old_extension URL."
				);
				$this->assertMatchesRegularExpression(
					'/(?:\.jpg|%2Ejpg)/i',
					$content,
					"WPBakery carrier ($label) must now reference .jpg (raw or urlencoded)."
				);
				continue;
			}

			$this->assertStringNotContainsString(
				'.' . $old_extension,
				$content,
				"$label post_content must no longer reference .$old_extension after conversion."
			);
			$this->assertStringContainsString(
				'.jpg',
				$content,
				"$label post_content must now reference .jpg."
			);
		}

		foreach ( $seeded['meta'] as $label => $entry ) {
			wp_cache_delete( $entry['post'], 'post_meta' );
			$value = get_post_meta( $entry['post'], $entry['key'], true );
			$flat  = is_string( $value ) ? $value : wp_json_encode( $value );

			$this->assertStringNotContainsString(
				'.' . $old_extension,
				$flat,
				"$label meta carrier must no longer contain .$old_extension after conversion."
			);
			$this->assertStringContainsString(
				'.jpg',
				$flat,
				"$label meta carrier must now contain .jpg after conversion."
			);
		}
	}

	/**
	 * Assert (security regression + no-op guard) that the serialized-OBJECT
	 * option we stored during seedContent is BYTE-IDENTICAL after the
	 * conversion run — Replacer::replaceContent explicitly avoids
	 * instantiating unknown classes (allowed_classes=false unserialize)
	 * and does not walk into them.
	 */
	private function assertObjectBlobUntouched( array $seeded ): void {
		wp_cache_delete( '_spio_test_object_blob', 'options' );
		$after = get_option( '_spio_test_object_blob' );
		$this->assertSame(
			$seeded['object_blob'],
			$after,
			'Serialized-object blob must be untouched (no object instantiation, no URL rewrite inside object payload).'
		);
	}

	// -------------------------------------------------------------------
	// Data provider — all API-converted formats. PNG uses its own test
	// because the upload / setting sequence differs.
	// -------------------------------------------------------------------

	public function apiConvertableFixtures(): array {
		return array(
			'heic' => array( 'fixture-large.heic', 'heic' ),
			'tiff' => array( 'fixture-medium.tiff', 'tiff' ),
			'bmp'  => array( 'fixture-medium.bmp',  'bmp' ),
		);
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	/**
	 * PNG (via the queue path) with the FULL matrix of content shapes.
	 * Includes the Elementor + WPBakery module activations (each gated
	 * on its manual-override filter, module singleton reset so its
	 * constructor sees the filter).
	 */
	public function test_png_queue_path_rewrites_every_content_shape() {
		// Activate Elementor + WPBakery Replacer modules for THIS test.
		$this->addTempFilter( 'shortpixel/externals/elementor_is_active', '__return_true' );
		$this->addTempFilter( 'shortpixel/externals/urlencode_is_active', '__return_true' );
		$this->resetReplacerModuleSingletons();

		$id = $this->uploadPngForQueuePath( 'fixture-small.png' );

		// Pick a thumbnail URL to embed too (base URL rewriting also
		// touches thumbnail URLs via getRelativeURLS).
		$meta          = wp_get_attachment_metadata( $id );
		$thumbnail_url = null;
		if ( ! empty( $meta['sizes'] ) ) {
			$sizeData      = array_values( $meta['sizes'] )[0];
			$uploads       = wp_get_upload_dir();
			$thumbnail_url = trailingslashit( dirname( wp_get_attachment_url( $id ) ) ) . $sizeData['file'];
			$this->assertStringEndsWith( '.png', $thumbnail_url );
		}

		$seeded = $this->seedContent( $id, $thumbnail_url );

		$this->assertPreConversionState( $seeded, 'png' );

		// Drive the queue path — PNGConverter::filterQueue swaps action
		// to png2jpg, which runs convert() with runReplacer=true.
		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			'PNG must be converted to JPG through the queue path.'
		);

		$this->assertPostConversionReplaced( $seeded, 'png' );
		$this->assertObjectBlobUntouched( $seeded );
	}

	/**
	 * HEIC / TIFF / BMP through the ApiConverter path.
	 *
	 * DOCUMENTS CURRENT BEHAVIOR — the placeholder mechanism means
	 * wp_get_attachment_url returns the target `.jpg` URL from the
	 * moment the upload hook runs (checkPlaceHolder filter, see
	 * AdminController::checkPlaceHolder, class/Controller/AdminController.php:252).
	 * As a result:
	 *   - content authored via WP APIs after upload embeds `.jpg` URLs
	 *     from the very start (matches real-user behavior)
	 *   - ApiConverter::handleConverted() does re-run the Replacer, but
	 *     both source_url and target_url are already `.jpg` at that
	 *     point (see class/Model/Converter/ApiConverter.php:274) — the
	 *     replacer effectively no-ops, which is fine because there is
	 *     nothing to rewrite
	 *
	 * Test assertions here reflect that: no `.heic/.tiff/.bmp` will ever
	 * appear in real content, and the URL that IS embedded (the .jpg one)
	 * survives conversion pointing at the eventual real JPG on disk.
	 *
	 * If the plugin ever changes the placeholder strategy (e.g. keeps
	 * the source extension in URLs until the final JPG lands), the
	 * `assertStringContainsString( '.jpg', … )` assertions on the
	 * seeded content will still pass but the sentinel check that
	 * wp_get_attachment_url returns .jpg would fail loudly and this
	 * test needs revisiting.
	 *
	 * @dataProvider apiConvertableFixtures
	 */
	public function test_api_conversion_content_urls_survive_placeholder_and_final_swap( string $fixture, string $extension ) {
		$this->addTempFilter( 'shortpixel/externals/elementor_is_active', '__return_true' );
		$this->addTempFilter( 'shortpixel/externals/urlencode_is_active', '__return_true' );
		$this->resetReplacerModuleSingletons();

		$id = $this->uploadForApiConversion( $fixture );

		// checkPlaceHolder swaps the URL extension to .jpg synchronously
		// on the upload hook — pin that as a sentinel so a future change
		// to that mechanism trips a load-bearing assertion here.
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith(
			'.jpg',
			$url,
			'Sentinel: checkPlaceHolder must swap wp_get_attachment_url to .jpg during ApiConverter upload.'
		);
		$this->assertStringNotContainsString(
			'.' . $extension,
			$url,
			"Sentinel: source extension .$extension must NOT appear in the placeholder-rewritten URL."
		);

		// Some non-JPEG formats don't get thumbnails generated by WP
		// (WP treats HEIC etc. as image-mime but has no sub-size cutter).
		// Only embed a thumbnail URL when metadata actually holds sizes.
		$meta          = wp_get_attachment_metadata( $id );
		$thumbnail_url = null;
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			$sizeData      = array_values( $meta['sizes'] )[0];
			$thumbnail_url = trailingslashit( dirname( $url ) ) . $sizeData['file'];
		}

		$seeded = $this->seedContent( $id, $thumbnail_url );

		// seedContent embeds the placeholder-swapped .jpg URL — verify
		// that pre-optimize state directly instead of the .$extension
		// sentinel used by the PNG test (which does not apply here).
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$this->assertStringContainsString(
				'.jpg',
				get_post( $post_id )->post_content,
				"Sentinel: pre-optimize $label carrier already references .jpg (placeholder rewrite)."
			);
			$this->assertStringNotContainsString(
				'.' . $extension,
				get_post( $post_id )->post_content,
				"Sentinel: source .$extension must not be present in the $label carrier — placeholder already swapped."
			);
		}

		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			"$fixture must be converted to JPG through ApiConverter."
		);

		// Every carrier still references .jpg — the URL survived the
		// placeholder → real-JPG swap intact.
		foreach ( $seeded['posts'] as $label => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;
			if ( 'wpbakery' === $label ) {
				$this->assertMatchesRegularExpression(
					'/(?:\.jpg|%2Ejpg)/i',
					$content,
					"WPBakery carrier ($label) must reference .jpg (raw or urlencoded)."
				);
				continue;
			}
			$this->assertStringContainsString( '.jpg', $content, "$label carrier must still reference .jpg after ApiConverter run." );
			$this->assertStringNotContainsString( '.' . $extension, $content, "$label carrier must not have gained a .$extension reference." );
		}
		foreach ( $seeded['meta'] as $label => $entry ) {
			wp_cache_delete( $entry['post'], 'post_meta' );
			$value = get_post_meta( $entry['post'], $entry['key'], true );
			$flat  = is_string( $value ) ? $value : wp_json_encode( $value );
			$this->assertStringContainsString( '.jpg', $flat, "$label meta must still reference .jpg after ApiConverter run." );
			$this->assertStringNotContainsString( '.' . $extension, $flat, "$label meta must not have gained a .$extension reference." );
		}

		$this->assertObjectBlobUntouched( $seeded );
	}

	/**
	 * Elementor module: with the manual-override filter ACTIVE, an
	 * `_elementor_data` JSON string (with escaped slashes) is rewritten.
	 *
	 * Driven through the PNG queue path (the ApiConverter path would be
	 * vacuous — see the docblock on test_api_conversion_content_urls_survive
	 * — because URLs are placeholder-swapped to .jpg BEFORE the seed).
	 */
	public function test_elementor_module_registers_custom_replace_query_when_active() {
		$this->addTempFilter( 'shortpixel/externals/elementor_is_active', '__return_true' );
		$this->resetReplacerModuleSingletons();

		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url, 'Sentinel: source URL is .png before the queue converts it.' );

		$carrier = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$json    = wp_json_encode(
			array(
				array(
					'elType'   => 'widget',
					'settings' => array( 'image' => array( 'url' => $url, 'id' => $id ) ),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
		add_post_meta( $carrier, '_elementor_data', wp_slash( $json ) );

		wp_cache_delete( $carrier, 'post_meta' );
		$this->assertStringContainsString(
			'.png',
			get_post_meta( $carrier, '_elementor_data', true ),
			'Sentinel: Elementor JSON must reference .png before conversion.'
		);

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		wp_cache_delete( $carrier, 'post_meta' );
		$after = get_post_meta( $carrier, '_elementor_data', true );

		$this->assertStringNotContainsString( '.png', $after, 'Elementor JSON must not still reference .png.' );
		$this->assertStringContainsString( '.jpg', $after, 'Elementor JSON must reference .jpg.' );

		// The Elementor rewrite path re-encodes the JSON with escaped
		// slashes (JSON_UNESCAPED_SLASHES OFF) — verify the value round-
		// trips as a JSON string, not accidentally serialized array.
		$decoded = json_decode( $after, true );
		$this->assertIsArray( $decoded, 'Rewritten _elementor_data must remain a JSON string.' );
	}

	/**
	 * WPBakery module: with the urlencode override filter ACTIVE, a
	 * urlencoded URL in post_content is rewritten to the urlencoded new URL.
	 *
	 * Driven through the PNG queue path — see the Elementor test above
	 * for the reason ApiConverter paths cannot exercise this meaningfully.
	 */
	public function test_wpbakery_module_rewrites_urlencoded_url_when_active() {
		$this->addTempFilter( 'shortpixel/externals/urlencode_is_active', '__return_true' );
		$this->resetReplacerModuleSingletons();

		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url, 'Sentinel: source URL is .png before the queue converts it.' );

		$post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_content' => '[vc_single_image image_url="' . urlencode( $url ) . '"]',
		) );

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			urlencode( $url ),
			get_post( $post_id )->post_content,
			'Sentinel: WPBakery-shaped urlencoded URL must be present pre-conversion.'
		);
		$this->assertStringNotContainsString(
			$url,
			get_post( $post_id )->post_content,
			'Sentinel: the raw (non-encoded) URL must NOT be present — only the encoded form.'
		);

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringNotContainsString(
			urlencode( $url ),
			$content,
			'WPBakery module must rewrite the urlencoded .png URL.'
		);
		$this->assertMatchesRegularExpression(
			'/(?:\.jpg|%2Ejpg)/i',
			$content,
			'Rewritten WPBakery content must reference .jpg (raw or urlencoded).'
		);
	}

	// DEFERRED / documented in file docblock — no test methods here for
	// YoastSeo or Breakdance modules. Both require the real partner-plugin
	// runtimes (WPSEO_VERSION defined + wp_yoast_indexable table,
	// \Breakdance\Data\* functions) and are exercised in the compat suite
	// (tests/Compat/, bin/test.sh --compat) instead. Placeholder skip
	// methods used to live here but interacted badly with PHPUnit 9's
	// data-provider handling in this class (a subtle serialization
	// interaction between markTestSkipped and neighbouring
	// @dataProvider tests caused a hard "Serialization of 'Closure' is
	// not allowed" fatal that aborted the whole integration run — see
	// the git log for the reproduction).
}
