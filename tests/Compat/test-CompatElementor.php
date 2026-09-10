<?php
/**
 * Cross-plugin compatibility: Elementor (Wave 4).
 *
 * Runs with the REAL Elementor plugin active (bin/test.sh --compat
 * downloads + activates it). Covers
 * build/shortpixel/replacer2/src/Modules/Elementor.php:
 *
 *   - The module AUTO-detects real Elementor via defined('ELEMENTOR_VERSION')
 *     without any manual override filter (a fresh Replacer instance must
 *     register the shortpixel/replacer/custom_replace_query filter).
 *   - End-to-end PNG→JPG conversion through the queue path rewrites the
 *     `_elementor_data` postmeta the way real Elementor stores it (JSON
 *     string wrapped with wp_slash, escaped slashes) and the rewritten
 *     value still round-trips as valid JSON.
 *   - Elementor-rendered images embedded in normal post_content (classic
 *     <img src>) are rewritten too — the base Replacer already handles
 *     these, but we verify it still works with the Elementor module
 *     active (its custom query must not shadow the default path).
 *
 * The Integration suite already exercises the module via the manual
 * override filter (test-ConversionReplacement.php); this suite verifies
 * the SAME behavior kicks in against the real plugin's autoloaded state.
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatElementorTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Elementor is not loaded — run via bin/test.sh --compat.' );
		}

		parent::set_up();

		// Replacer module singletons cache their `add_filter` state at
		// construction — reset so the next getInstance() reruns the
		// module constructor and re-inspects ELEMENTOR_VERSION.
		$this->resetReplacerModuleSingletons();
	}

	public function tear_down() {
		$this->resetReplacerModuleSingletons();

		// Backup files live outside the DB transaction — sweep the tree so
		// one test's backups can't satisfy another test's assertion.
		if ( defined( 'SHORTPIXEL_BACKUP_FOLDER' ) && is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
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
	 * Copy of the queue-path PNG uploader from
	 * tests/Integration/test-ConversionReplacement.php — see the docblock
	 * there for the png2jpg-off-then-on flow rationale. Uses
	 * fixture-small.png (the "large" fixture is already so heavily
	 * PNG-optimized that PNGConverter::checkFileSizeMargin rejects the
	 * JPG re-encode and the .png stays on disk, making assertions vacuous).
	 */
	private function uploadPngForQueuePath( string $fixture = 'fixture-small.png' ): int {
		\wpSPIO()->settings()->png2jpg = 0;
		$id = $this->uploadFixture( $fixture );
		\wpSPIO()->settings()->png2jpg = 1;
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->png2jpg = 1;
		return $id;
	}

	// -------------------------------------------------------------------
	// Coexistence + auto-detection
	// -------------------------------------------------------------------

	public function test_elementor_loads_alongside_spio() {
		$this->assertTrue( defined( 'ELEMENTOR_VERSION' ), 'Elementor version constant must be defined.' );
		$this->assertGreaterThan( 0, did_action( 'elementor/loaded' ), 'Elementor must have finished loading.' );
	}

	/**
	 * With real Elementor loaded, the module must self-activate and register
	 * its custom_replace_query filter — no manual override filter needed.
	 */
	public function test_elementor_module_autodetects_real_plugin_and_registers_filter() {
		// Removing the filter first ensures we only see registrations made
		// by the fresh module instance constructed on getInstance().
		remove_all_filters( 'shortpixel/replacer/custom_replace_query' );

		// Instantiate the Replacer, which calls loadFormats() → module ctors.
		\ShortPixel\Replacer\Replacer::getInstance();

		$this->assertNotFalse(
			has_filter(
				'shortpixel/replacer/custom_replace_query',
				array( \ShortPixel\Replacer\Modules\Elementor::getInstance(), 'addElementor' )
			),
			'The Elementor module must self-register its custom_replace_query filter when real Elementor is loaded.'
		);
	}

	// -------------------------------------------------------------------
	// End-to-end: PNG→JPG through the queue rewrites _elementor_data
	// -------------------------------------------------------------------

	/**
	 * `_elementor_data` postmeta is stored as a JSON string wrapped with
	 * wp_slash (so all forward slashes end up escaped in the DB row).
	 * After PNG→JPG conversion the URL inside the JSON must be rewritten
	 * to the .jpg equivalent, and the result must still round-trip as
	 * valid JSON (module's addSlash escaping must not corrupt structure).
	 */
	public function test_png_conversion_rewrites_elementor_data_json_postmeta() {
		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url, 'Sentinel: source URL must be .png before queue conversion.' );

		$carrier = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$json    = wp_json_encode(
			array(
				array(
					'id'       => 'widget-1',
					'elType'   => 'widget',
					'widgetType' => 'image',
					'settings' => array(
						'image' => array( 'url' => $url, 'id' => $id ),
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
		add_post_meta( $carrier, '_elementor_data', wp_slash( $json ) );

		wp_cache_delete( $carrier, 'post_meta' );
		$before = get_post_meta( $carrier, '_elementor_data', true );
		$this->assertStringContainsString( '.png', $before, 'Sentinel: pre-conversion Elementor JSON must reference .png.' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			'PNG must be converted to JPG through the queue path (fixture-small.png must shrink on JPG re-encode).'
		);

		wp_cache_delete( $carrier, 'post_meta' );
		$after = get_post_meta( $carrier, '_elementor_data', true );

		$this->assertStringNotContainsString(
			'.png',
			$after,
			'Elementor JSON must no longer reference .png after PNG→JPG conversion.'
		);
		$this->assertStringContainsString( '.jpg', $after, 'Elementor JSON must now reference .jpg.' );

		$decoded = json_decode( $after, true );
		$this->assertIsArray( $decoded, 'Rewritten _elementor_data must remain a JSON string (module addSlash escaping must not corrupt structure).' );
		$this->assertSame( 'widget', $decoded[0]['elType'] ?? null, 'Nested structure must survive the URL rewrite.' );
	}

	/**
	 * Elementor-rendered images embedded in plain post_content (a normal
	 * classic <img src>) must still be rewritten with the Elementor module
	 * active — its custom_replace_query is additive, it must not disable
	 * the default post_content rewrite path.
	 */
	public function test_png_conversion_rewrites_classic_img_in_post_content_with_elementor_active() {
		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p><img src="' . esc_url( $url ) . '" alt="classic elementor" /></p>',
			)
		);

		clean_post_cache( $post_id );
		$this->assertStringContainsString( '.png', get_post( $post_id )->post_content, 'Sentinel: pre-conversion post_content must reference .png.' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringNotContainsString( '.png', $content, 'Classic post_content .png must be rewritten.' );
		$this->assertStringContainsString( '.jpg', $content, 'Classic post_content must reference .jpg after conversion.' );
	}
}
