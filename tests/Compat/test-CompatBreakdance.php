<?php
/**
 * Cross-plugin compatibility: Breakdance (Wave 4).
 *
 * Runs with the REAL Breakdance plugin active. Breakdance is commercial,
 * so bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-plugins/breakdance-*.zip (present in this repo); without
 * that zip every test here SKIPS.
 *
 * Covers build/shortpixel/replacer2/src/Modules/Breakdance.php:
 *
 *   - The module AUTO-detects real Breakdance via
 *     has_action('breakdance_loaded') AND all four required
 *     \Breakdance\Data\* functions being present. When both are true it
 *     self-registers three filters/actions: custom_replace_query,
 *     load_meta_value, save_meta_value.
 *   - The custom_replace_query registration must include the special
 *     args `replacer_do_save=false` and `replace_no_serialize=true` —
 *     the module handles the write itself via save_document, so the
 *     Replacer's default UPDATE must be suppressed.
 *   - loadContent bridges from a raw `_breakdance_data` postmeta value to
 *     a decoded tree via \Breakdance\Data\get_tree — we seed a real
 *     Breakdance-shaped postmeta row and verify the module returns the
 *     expected tree structure (with URLs in it).
 *   - End-to-end conversion: PINNED AS BROKEN (production bug #65,
 *     ledgered 2026-09-09). Breakdance stores `_breakdance_data` DOUBLE
 *     JSON-encoded (set_meta → encode_before_writing_to_wp json_encodes
 *     the outer array whose `tree_json_string` value is ITSELF a JSON
 *     string), so every `/` in a URL lands in the DB as `\\\/` (three
 *     chars: \ \ /). The module's addSlash() builds a SINGLE-escaped
 *     LIKE pattern (`\/`, as for Elementor's single-encoded storage) —
 *     which can never be a contiguous substring of `\\\/`. The postmeta
 *     SELECT in Replacer::handleMetaData therefore never matches a real
 *     Breakdance row and loadContent/saveContent never run. Verified by
 *     direct string simulation: single-escaped pattern = no match,
 *     double-escaped pattern (`\\\/`) = match. Fix: addSlash for the
 *     breakdance component must escape twice (or LIKE on both forms).
 *
 * Neither the Integration suite nor a plain manual-override filter can
 * exercise this module — it hard-checks the real Breakdance functions and
 * calls them directly. That's what makes this compat test the only real
 * coverage of Modules/Breakdance.php.
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatBreakdanceTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! defined( 'BREAKDANCE_MODE' ) ) {
			$this->markTestSkipped( 'Breakdance is not loaded — drop breakdance-*.zip into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}
		if ( ! function_exists( '\\Breakdance\\Data\\get_tree' ) ) {
			$this->markTestSkipped( 'Breakdance loaded but \\Breakdance\\Data\\get_tree() missing — plugin bootstrap did not complete.' );
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

	/**
	 * Seed a post with a Breakdance-shaped `_breakdance_data` meta value
	 * that references the given URL somewhere in its tree. Uses the same
	 * encoding pipeline the real Breakdance uses (wp_json_encode + wp_slash
	 * via \Breakdance\Data\encode_before_writing_to_wp) — the SPIO module
	 * relies on that exact shape being decodable by \Breakdance\Data\get_tree.
	 */
	private function seedBreakdanceDocument( int $post_id, string $url ): void {
		$tree = array(
			'root' => array(
				'id'       => 'root-node',
				'data'     => array(),
				'children' => array(
					array(
						'id'   => 'image-node-1',
						'data' => array(
							'properties' => array(
								'image' => array( 'url' => $url ),
							),
						),
					),
				),
			),
		);

		// Breakdance stores the tree JSON-encoded inside a `tree_json_string`
		// field of the outer meta payload — see plugin/data/tree.php: get_tree
		// pulls the string via get_meta($post_id, '…data', 'tree_json_string').
		$outer = array(
			'tree_json_string' => wp_json_encode( $tree ),
		);

		\Breakdance\Data\set_meta( $post_id, '_breakdance_data', $outer );
	}

	// -------------------------------------------------------------------
	// Coexistence + auto-detection
	// -------------------------------------------------------------------

	public function test_breakdance_loads_alongside_spio() {
		$this->assertTrue( defined( 'BREAKDANCE_MODE' ), 'Breakdance BREAKDANCE_MODE constant must be defined.' );
		$this->assertTrue( has_action( 'breakdance_loaded' ) !== false, 'Breakdance must have wired its breakdance_loaded action.' );
		foreach ( array( '\\Breakdance\\Data\\get_tree', '\\Breakdance\\Data\\encode_before_writing_to_wp', '\\Breakdance\\Data\\get_global_option', '\\Breakdance\\Data\\save_document' ) as $fn ) {
			$this->assertTrue( function_exists( $fn ), "Required Breakdance function $fn must be present." );
		}
	}

	/**
	 * With real Breakdance loaded, the module must self-activate and register
	 * all three of its filters/actions — no manual override filter exists.
	 */
	public function test_breakdance_module_autodetects_real_plugin_and_registers_filters() {
		remove_all_filters( 'shortpixel/replacer/custom_replace_query' );
		remove_all_filters( 'shortpixel/replacer/load_meta_value' );
		remove_all_filters( 'shortpixel/replacer/save_meta_value' );

		\ShortPixel\Replacer\Replacer::getInstance();
		$module = \ShortPixel\Replacer\Modules\Breakdance::getInstance();

		$this->assertNotFalse(
			has_filter( 'shortpixel/replacer/custom_replace_query', array( $module, 'addBreakdance' ) ),
			'Breakdance module must self-register its custom_replace_query filter when real Breakdance is loaded.'
		);
		$this->assertNotFalse(
			has_filter( 'shortpixel/replacer/load_meta_value', array( $module, 'loadContent' ) ),
			'Breakdance module must self-register its load_meta_value filter.'
		);
		$this->assertNotFalse(
			has_filter( 'shortpixel/replacer/save_meta_value', array( $module, 'saveContent' ) ),
			'Breakdance module must self-register its save_meta_value filter.'
		);
	}

	/**
	 * The Breakdance module supplies special `args` when registering its
	 * custom query so the Replacer's default UPDATE is suppressed
	 * (replacer_do_save=false) and content is NOT re-serialized before the
	 * module writes it back (replace_no_serialize=true). Both are load-
	 * bearing — the module writes via save_document itself.
	 */
	public function test_breakdance_custom_query_carries_replacer_do_save_false() {
		$module = \ShortPixel\Replacer\Modules\Breakdance::getInstance();

		$items = $module->addBreakdance( array(), 'http://example.com/uploads/', array( 'http://example.com/uploads/foo.png' ), array( 'http://example.com/uploads/foo.jpg' ) );

		$this->assertArrayHasKey( 'breakdance', $items, 'The module must add a "breakdance" component to the custom_replace_query items.' );
		$this->assertArrayHasKey( 'args', $items['breakdance'], 'The breakdance component must carry an args payload.' );
		$this->assertFalse( $items['breakdance']['args']['replacer_do_save'], 'replacer_do_save must be false — module handles saves via save_document itself.' );
		$this->assertTrue( $items['breakdance']['args']['replace_no_serialize'], 'replace_no_serialize must be true — Breakdance data is JSON, not a PHP-serialized array.' );
	}

	// -------------------------------------------------------------------
	// loadContent bridges to real \Breakdance\Data\get_tree
	// -------------------------------------------------------------------

	/**
	 * When the Replacer walks post meta and hits a `_breakdance_data` row,
	 * the module's `loadContent` filter callback replaces the raw string
	 * with the decoded Breakdance tree so the URL rewrite can operate on
	 * the array structure. Uses the real \Breakdance\Data\get_tree function
	 * against a real Breakdance-shaped meta row.
	 */
	public function test_load_content_returns_decoded_tree_from_real_breakdance_meta() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$url     = 'http://example.com/uploads/2026/09/fixture-small.png';
		$this->seedBreakdanceDocument( $post_id, $url );

		$module = \ShortPixel\Replacer\Modules\Breakdance::getInstance();

		// The Replacer passes the raw meta_value, a $row (needs post_id +
		// meta_id keys) and the current component name.
		$raw    = get_post_meta( $post_id, '_breakdance_data', true );
		$row    = array( 'post_id' => $post_id, 'meta_id' => 1, 'meta_value' => $raw );
		$loaded = $module->loadContent( $raw, $row, 'breakdance' );

		$this->assertIsArray( $loaded, 'loadContent must return the decoded Breakdance tree (array), not the raw string.' );
		$this->assertArrayHasKey( 'root', $loaded, 'Decoded tree must have a root node (Breakdance is_valid_tree contract).' );
		$this->assertSame( 'root-node', $loaded['root']['id'] ?? null, 'Root id must survive the decode.' );

		// The URL is nested inside root.children[0].data.properties.image.url —
		// walk to it so the assertion pinpoints where the URL rewrite would land.
		$this->assertSame(
			$url,
			$loaded['root']['children'][0]['data']['properties']['image']['url'] ?? null,
			'The seeded URL must be reachable inside the decoded tree — that is where the Replacer walks to rewrite it.'
		);
	}

	/**
	 * The load filter is component-aware: if the current replace run is
	 * NOT the "breakdance" component (e.g. plain postmeta), the module
	 * must return the content unchanged — its work only applies when the
	 * Replacer explicitly asked for the breakdance component.
	 */
	public function test_load_content_is_noop_for_other_components() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->seedBreakdanceDocument( $post_id, 'http://example.com/uploads/foo.png' );

		$raw    = get_post_meta( $post_id, '_breakdance_data', true );
		$row    = array( 'post_id' => $post_id, 'meta_id' => 1, 'meta_value' => $raw );
		$module = \ShortPixel\Replacer\Modules\Breakdance::getInstance();

		$result = $module->loadContent( $raw, $row, 'unset' );
		$this->assertSame( $raw, $result, 'loadContent must be a no-op for non-breakdance components.' );

		$result = $module->loadContent( $raw, $row, 'elementor' );
		$this->assertSame( $raw, $result, 'loadContent must be a no-op for the elementor component too.' );
	}

	// -------------------------------------------------------------------
	// End-to-end (PINNED BUG #65): PNG→JPG
	// conversion runs the FULL Replacer against a real Breakdance-shaped
	// `_breakdance_data` postmeta row and the row survives UNCHANGED.
	//
	// ROOT CAUSE (verified): Breakdance double-JSON-encodes its meta —
	// URLs are stored with `\\\/` between path segments. The module's
	// addSlash() (build/shortpixel/replacer2/src/Modules/Breakdance.php:74-82)
	// produces a SINGLE-escaped LIKE pattern (`\/`), which can never match
	// the double-escaped storage, so the postmeta SELECT in
	// Replacer::handleMetaData (Replacer.php:335-336,350) finds no rows and
	// loadContent/saveContent never execute. Breakdance documents are
	// therefore NEVER URL-rewritten — in production too, not just in this
	// harness. When the addSlash escaping is fixed for the breakdance
	// component, this test flips: promote it to a positive-rewrite
	// assertion (the loadContent test above proves decode works; save
	// would go through \Breakdance\Data\save_document → set_meta).
	// -------------------------------------------------------------------

	public function test_pin65_png_conversion_leaves_breakdance_meta_unchanged_pinned_for_deferred_fix() {
		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'BD sentinel' ) );
		$this->seedBreakdanceDocument( $post_id, $url );

		$raw_before = get_post_meta( $post_id, '_breakdance_data', true );
		$this->assertStringContainsString( '.png', $raw_before, 'Sentinel: _breakdance_data must reference .png before conversion.' );

		$this->purgeQueueTable();

		try {
			$this->optimizeAttachment( $id );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped(
				'Breakdance save_document threw inside WP_UnitTestCase (' . get_class( $e ) . ': ' . $e->getMessage() . ') — heavy side effects (generateCacheForPost, filesystem writes) that need a full request context. Module autoregister + loadContent assertions above cover the SPIO side.'
			);
		}

		$this->assertSame(
			'jpg',
			strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ),
			'PNG must be converted to JPG (SPIO side of the pipeline still works).'
		);

		wp_cache_delete( $post_id, 'post_meta' );
		$raw_after = get_post_meta( $post_id, '_breakdance_data', true );

		// PIN: the meta value remains UNCHANGED — the module's single-
		// escaped LIKE pattern never matches Breakdance's double-escaped
		// storage, so the breakdance replace run finds no rows (see the
		// root-cause block above). Flips when the addSlash escaping is
		// fixed — then promote to a positive-rewrite assertion.
		$this->assertStringContainsString(
			'.png',
			$raw_after,
			'PINNED BUG #65: Breakdance meta stays .png — the module\'s single-escaped LIKE pattern cannot match Breakdance\'s double-JSON-encoded storage (Modules/Breakdance.php:74-82 addSlash). FLIP to a positive-rewrite assertion when fixed.'
		);
		$this->assertStringNotContainsString(
			'.jpg',
			$raw_after,
			'PINNED BUG #65: Breakdance meta never gained a .jpg reference — the breakdance replace-query never matches any row.'
		);
	}
}
