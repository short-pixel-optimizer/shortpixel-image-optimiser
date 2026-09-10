<?php
/**
 * Cross-plugin compatibility: WPBakery Page Builder (Wave 4).
 *
 * Runs with the REAL WPBakery (js_composer) plugin active. WPBakery is
 * commercial, so bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-plugins/js_composer.zip (present in this repo); without
 * that zip every test here SKIPS.
 *
 * Covers build/shortpixel/replacer2/src/Modules/WpBakery.php:
 *
 *   - The module AUTO-detects real WPBakery via did_action('vc_plugins_loaded')
 *     without any manual override filter, and self-registers its
 *     shortpixel/replacer/custom_replace_query filter.
 *   - End-to-end PNG→JPG conversion rewrites a realistic
 *     `[vc_single_image image_url="<urlencoded>"]` post_content: the
 *     module urlencodes both search and replace URLs so the encoded
 *     form is what gets matched and rewritten in the DB.
 *
 * The Integration suite already exercises the module via the manual
 * override filter (test-ConversionReplacement.php); this suite verifies
 * the SAME behavior kicks in against the real plugin's autoloaded state
 * (specifically the did_action('vc_plugins_loaded') branch of the
 * bakery_is_active() detector).
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatWpBakeryTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		// WPBakery defines WPB_VC_VERSION in its main file and fires
		// vc_plugins_loaded on plugins_loaded via Vc_Manager::pluginsLoaded.
		if ( ! defined( 'WPB_VC_VERSION' ) ) {
			$this->markTestSkipped( 'WPBakery is not loaded — drop js_composer.zip into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}

		parent::set_up();

		$this->resetReplacerModuleSingletons();
	}

	public function tear_down() {
		$this->resetReplacerModuleSingletons();

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

	public function test_wpbakery_loads_alongside_spio() {
		$this->assertTrue( defined( 'WPB_VC_VERSION' ), 'WPBakery WPB_VC_VERSION constant must be defined.' );
		$this->assertGreaterThan(
			0,
			did_action( 'vc_plugins_loaded' ),
			'WPBakery must have fired vc_plugins_loaded (Vc_Manager::pluginsLoaded on plugins_loaded).'
		);
	}

	/**
	 * With real WPBakery loaded (did_action('vc_plugins_loaded') true), the
	 * module must self-activate and register its custom_replace_query
	 * filter — no manual override filter needed.
	 */
	public function test_wpbakery_module_autodetects_real_plugin_and_registers_filter() {
		remove_all_filters( 'shortpixel/replacer/custom_replace_query' );

		\ShortPixel\Replacer\Replacer::getInstance();

		$this->assertNotFalse(
			has_filter(
				'shortpixel/replacer/custom_replace_query',
				array( \ShortPixel\Replacer\Modules\WpBakery::getInstance(), 'addURLEncoded' )
			),
			'The WpBakery module must self-register its custom_replace_query filter when real WPBakery is loaded.'
		);
	}

	// -------------------------------------------------------------------
	// End-to-end: PNG→JPG rewrites urlencoded [vc_single_image] URL
	// -------------------------------------------------------------------

	/**
	 * WPBakery stores image URLs urlencoded inside shortcodes:
	 *   [vc_single_image image_url="https%3A%2F%2Fexample.com%2F…%2Ffoo.png"]
	 * The WpBakery module's addEncode maps urlencode over both search and
	 * replace URL sets, so the DB rewrite matches the encoded form and
	 * writes the encoded new URL back.
	 */
	public function test_png_conversion_rewrites_urlencoded_wpbakery_shortcode() {
		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '[vc_single_image image_url="' . urlencode( $url ) . '"]',
			)
		);

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			urlencode( $url ),
			get_post( $post_id )->post_content,
			'Sentinel: pre-conversion urlencoded .png URL must be present in the WPBakery shortcode.'
		);
		$this->assertStringNotContainsString(
			$url,
			get_post( $post_id )->post_content,
			'Sentinel: raw (non-encoded) URL must NOT be present pre-conversion — only the encoded form.'
		);

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			'PNG must be converted to JPG through the queue path.'
		);

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringNotContainsString(
			urlencode( $url ),
			$content,
			'The WpBakery module must rewrite the urlencoded .png URL out of the shortcode.'
		);
		$this->assertMatchesRegularExpression(
			'/(?:\.jpg|%2Ejpg)/i',
			$content,
			'Rewritten WPBakery shortcode must reference .jpg (raw or urlencoded).'
		);
	}
}
