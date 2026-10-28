<?php
/**
 * Cross-plugin compatibility: Breakdance.
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
 *   - End-to-end PNG→JPG conversion and file rename. `_breakdance_data`
 *     is an outer JSON object whose `tree_json_string` value is itself a
 *     JSON string. Every Breakdance writer stores that inner string as the
 *     editor produced it (JSON.stringify, `/` unescaped) or copies it
 *     verbatim, so the stored URLs are single-escaped (`\/`) and the
 *     module's LIKE pattern matches them. Breakdance alone: the Replacer
 *     hands the row to the module with its post_id, loadContent() loads the
 *     tree and saveContent() writes the new URL through save_document().
 *     Pinned defects on this path:
 *       - Breakdance + Elementor: the Elementor module's run uses the same
 *         LIKE pattern and runs first. It rewrites the row with the generic
 *         replacer, which saves `tree_json_string` back as an object
 *         instead of a string — get_tree() then throws a TypeError and the
 *         document no longer loads.
 *       - Breakdance 2.x only: saveContent() passes `false` as
 *         futureLayerMeta to save_document(), which overwrites
 *         `_breakdance_futurelayer_meta`. Breakdance 3.0 skips a false value.
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
	 * Seed `_breakdance_data` the way the Breakdance editor saves it. The
	 * builder serialises the tree with JSON.stringify, which leaves `/`
	 * unescaped, and save_document() hands that string to set_meta() as
	 * `tree_json_string` — so URLs land in the database single-escaped
	 * (`http:\/\/…`).
	 */
	private function seedBreakdanceDocument( int $post_id, string $url ): void {
		$outer = array(
			'tree_json_string' => wp_json_encode( $this->breakdanceTree( $url ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);

		\Breakdance\Data\set_meta( $post_id, '_breakdance_data', $outer );
	}

	/**
	 * A minimal valid Breakdance tree with one image element pointing at
	 * $url. The nodes carry a type because save_document() renders the
	 * post's CSS cache, which reads it.
	 */
	private function breakdanceTree( string $url ): array {
		return array(
			'root' => array(
				'id'       => 'root-node',
				'data'     => array(
					'type'       => 'root',
					'properties' => array(),
				),
				'children' => array(
					array(
						'id'   => 'image-node-1',
						'data' => array(
							'type'       => 'EssentialElements\\Image',
							'properties' => array(
								'image' => array( 'url' => $url ),
							),
						),
					),
				),
			),
		);
	}

	/** The meta_id of the post's `_breakdance_data` row. */
	private function breakdanceMetaId( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_breakdance_data'", $post_id ) );
	}

	/**
	 * Record every row the Replacer hands to load_meta_value for the
	 * breakdance component. Runs before the module's own loadContent
	 * (priority 10), so it sees the row even when the module aborts it.
	 */
	private function captureBreakdanceRows( array &$rows ): callable {
		$capture = function ( $content, $row, $component ) use ( &$rows ) {
			if ( 'breakdance' === $component ) {
				$rows[ (int) $row['meta_id'] ] = $row;
			}
			return $content;
		};
		add_filter( 'shortpixel/replacer/load_meta_value', $capture, 5, 3 );
		return $capture;
	}

	/**
	 * Run the next replacement as on a site without Elementor: the compat
	 * harness activates Elementor too, and its replacer module would
	 * otherwise process the Breakdance row first.
	 */
	private function withoutElementorModule(): void {
		add_filter( 'shortpixel/externals/elementor_is_active', '__return_false' );
		$this->resetReplacerModuleSingletons();
	}

	/**
	 * Decode `_breakdance_data` straight from the database. Returns the
	 * outer payload; its `tree_json_string` must be a string for
	 * Breakdance to load the document.
	 */
	private function storedBreakdanceData( int $post_id ): array {
		global $wpdb;
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_breakdance_data'", $post_id ) );
		return (array) json_decode( $raw, true );
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

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does (:1409-1413). */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new \ShortPixel\Model\Queue\QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	// -------------------------------------------------------------------
	// End-to-end. Documents saved from the Breakdance editor carry
	// single-escaped URLs, which the module's LIKE pattern matches. What
	// happens next depends on whether Elementor is active:
	//
	// - Breakdance alone: the Breakdance run reaches the row with its
	//   post_id, loads the tree through get_tree(), rewrites the URL and
	//   saves the document through save_document().
	// - Breakdance + Elementor: the Elementor module's run builds the same
	//   LIKE pattern, runs before the Breakdance run and handles the row
	//   with the generic replacer. That decodes the nested
	//   `tree_json_string`, rewrites the URL and saves it back as a JSON
	//   object instead of a string; the Breakdance run then finds nothing
	//   left to match. get_tree() throws a TypeError on the stored object,
	//   so the document no longer loads.
	// -------------------------------------------------------------------

	/**
	 * Shape sentinel for the tests below: an editor-saved document stores
	 * its URLs single-escaped and decodes back to the plain URL.
	 */
	public function test_editor_saved_document_stores_urls_single_escaped() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$url     = 'http://example.com/wp-content/uploads/2026/10/editor-image.png';
		$this->seedBreakdanceDocument( $post_id, $url );

		$raw = get_post_meta( $post_id, '_breakdance_data', true );
		$this->assertStringContainsString( str_replace( '/', '\\/', $url ), $raw, 'Editor-saved URLs are stored single-escaped.' );
		$this->assertStringNotContainsString( '\\\\\\/', $raw, 'Editor-saved URLs are not escaped twice.' );

		$tree = \Breakdance\Data\get_tree( $post_id );
		$this->assertSame( $url, $tree['root']['children'][0]['data']['properties']['image']['url'] ?? null, 'get_tree() decodes the plain URL.' );
	}

	/**
	 * On a site without Elementor, converting a PNG to JPG rewrites an
	 * editor-saved Breakdance document to the .jpg: the row reaches the
	 * module with its post_id, so loadContent() can load the tree.
	 */
	public function test_png_conversion_rewrites_editor_saved_breakdance_document() {
		$this->withoutElementorModule();
		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'BD editor sentinel' ) );
		$this->seedBreakdanceDocument( $post_id, $url );
		$meta_id = $this->breakdanceMetaId( $post_id );

		$this->purgeQueueTable();

		$rows    = array();
		$capture = $this->captureBreakdanceRows( $rows );
		$this->optimizeAttachment( $id );
		remove_filter( 'shortpixel/replacer/load_meta_value', $capture, 5 );

		// SENTINELS: the conversion ran and the Breakdance query matched the row.
		$this->assertSame( 'jpg', strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ), 'Sentinel: the PNG was converted to JPG.' );
		$this->assertArrayHasKey( $meta_id, $rows, 'Sentinel: the editor-saved row reached the breakdance component — the LIKE pattern matches single-escaped URLs.' );

		$this->assertSame( $post_id, (int) ( $rows[ $meta_id ]['post_id'] ?? 0 ), 'The row reaches the module with its post_id.' );
		wp_cache_delete( $post_id, 'post_meta' );
		$raw_after = get_post_meta( $post_id, '_breakdance_data', true );
		$this->assertStringNotContainsString( '.png', $raw_after, 'The Breakdance document no longer references the .png.' );
		$this->assertStringContainsString( '.jpg', $raw_after, 'The Breakdance document references the .jpg.' );
		$tree = \Breakdance\Data\get_tree( $post_id );
		$this->assertStringEndsWith( '.jpg', (string) ( $tree['root']['children'][0]['data']['properties']['image']['url'] ?? '' ), 'get_tree() loads the document with the .jpg URL.' );
	}

	/**
	 * On a site without Elementor, renaming a file (manually or with AI)
	 * rewrites an editor-saved Breakdance document to the new filename.
	 */
	public function test_manual_rename_rewrites_editor_saved_breakdance_document() {
		$this->withoutElementorModule();
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$url      = wp_get_attachment_url( $id );
		$old_base = pathinfo( get_attached_file( $id ), PATHINFO_FILENAME );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'BD editor rename sentinel' ) );
		$this->seedBreakdanceDocument( $post_id, $url );
		$meta_id = $this->breakdanceMetaId( $post_id );

		$rows     = array();
		$capture  = $this->captureBreakdanceRows( $rows );
		$new_base = 'bd-editor-rename-' . strtolower( wp_generate_password( 6, false ) );
		$renamed  = $this->renameAttachment( $id, $new_base );
		remove_filter( 'shortpixel/replacer/load_meta_value', $capture, 5 );

		// SENTINELS: the rename ran and the Breakdance query matched the row.
		$this->assertTrue( $renamed, 'Sentinel: the rename succeeded.' );
		$this->assertStringContainsString( $new_base, get_attached_file( $id ), 'Sentinel: _wp_attached_file carries the new base.' );
		$this->assertArrayHasKey( $meta_id, $rows, 'Sentinel: the editor-saved row reached the breakdance component — the LIKE pattern matches single-escaped URLs.' );

		$this->assertSame( $post_id, (int) ( $rows[ $meta_id ]['post_id'] ?? 0 ), 'The row reaches the module with its post_id.' );
		wp_cache_delete( $post_id, 'post_meta' );
		$raw_after = get_post_meta( $post_id, '_breakdance_data', true );
		$this->assertStringNotContainsString( $old_base . '.', $raw_after, 'The Breakdance document no longer references the old name.' );
		$this->assertStringContainsString( $new_base, $raw_after, 'The Breakdance document references the new name.' );
		$tree = \Breakdance\Data\get_tree( $post_id );
		$this->assertStringContainsString( $new_base, (string) ( $tree['root']['children'][0]['data']['properties']['image']['url'] ?? '' ), 'get_tree() loads the document with the new URL.' );
	}

	/**
	 * Pins a known defect: with Elementor also active, converting a PNG to
	 * JPG corrupts an editor-saved Breakdance document. The Elementor run
	 * rewrites the row with the generic replacer and stores
	 * `tree_json_string` as an object, so get_tree() throws.
	 * @todo In the replacer2 module source: keep `_breakdance_data` rows out
	 *       of every run but the breakdance one (e.g. select meta_key in
	 *       Replacer::handleMetaData() and have the Breakdance module return
	 *       null from load_meta_value for that key on other components), and
	 *       have Replacer::replaceContent() re-encode a nested JSON string
	 *       as a string.
	 * When fixed, this test fails; flip it: `tree_json_string` stays a
	 * string and get_tree() returns the tree with the .jpg URL.
	 */
	public function test_png_conversion_with_elementor_active_corrupts_editor_saved_breakdance_document_pinned_for_deferred_fix() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Needs Elementor active alongside Breakdance (bin/test.sh --compat).' );
		}

		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'BD+Elementor sentinel' ) );
		$this->seedBreakdanceDocument( $post_id, $url );
		$this->assertIsString( $this->storedBreakdanceData( $post_id )['tree_json_string'] ?? null, 'Sentinel: tree_json_string is a string before the conversion.' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		// SENTINEL: the conversion ran.
		$this->assertSame( 'jpg', strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ), 'Sentinel: the PNG was converted to JPG.' );

		// THE PIN: tree_json_string was saved back as an object …
		$stored = $this->storedBreakdanceData( $post_id );
		$this->assertIsArray( $stored['tree_json_string'] ?? null, 'Pinned: fixed? tree_json_string is no longer turned into an object — flip this pin.' );

		// … so Breakdance can no longer load the document.
		wp_cache_delete( $post_id, 'post_meta' );
		$thrown = null;
		try {
			\Breakdance\Data\get_tree( $post_id );
		} catch ( \TypeError $e ) {
			$thrown = $e;
		}
		$this->assertInstanceOf( \TypeError::class, $thrown, 'Pinned: fixed? get_tree() loads the document again — flip this pin.' );
	}

	/**
	 * Pins a known defect: with Elementor also active, renaming a file
	 * corrupts an editor-saved Breakdance document. Same cause as the
	 * PNG→JPG pin above.
	 * @todo See the PNG→JPG pin above.
	 * When fixed, this test fails; flip it: `tree_json_string` stays a
	 * string and get_tree() returns the tree with the new name.
	 */
	public function test_manual_rename_with_elementor_active_corrupts_editor_saved_breakdance_document_pinned_for_deferred_fix() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Needs Elementor active alongside Breakdance (bin/test.sh --compat).' );
		}

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$url = wp_get_attachment_url( $id );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'BD+Elementor rename sentinel' ) );
		$this->seedBreakdanceDocument( $post_id, $url );
		$this->assertIsString( $this->storedBreakdanceData( $post_id )['tree_json_string'] ?? null, 'Sentinel: tree_json_string is a string before the rename.' );

		$new_base = 'bd-elementor-rename-' . strtolower( wp_generate_password( 6, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the rename succeeded.' );

		// THE PIN: tree_json_string was saved back as an object …
		$stored = $this->storedBreakdanceData( $post_id );
		$this->assertIsArray( $stored['tree_json_string'] ?? null, 'Pinned: fixed? tree_json_string is no longer turned into an object — flip this pin.' );

		// … so Breakdance can no longer load the document.
		wp_cache_delete( $post_id, 'post_meta' );
		$thrown = null;
		try {
			\Breakdance\Data\get_tree( $post_id );
		} catch ( \TypeError $e ) {
			$thrown = $e;
		}
		$this->assertInstanceOf( \TypeError::class, $thrown, 'Pinned: fixed? get_tree() loads the document again — flip this pin.' );
	}

	// -------------------------------------------------------------------
	// saveContent() against the real save_document(). The end-to-end runs
	// above never reach it today, so these call it directly with a row
	// that carries post_id, as the Replacer will once it selects it.
	// -------------------------------------------------------------------

	/**
	 * The write half of the module works: a rewritten tree goes back
	 * through Breakdance's save_document() and loads with the new URL.
	 */
	public function test_save_content_writes_the_rewritten_tree_through_breakdance() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$old_url = 'http://example.org/wp-content/uploads/2026/10/bd-save-old.png';
		$new_url = 'http://example.org/wp-content/uploads/2026/10/bd-save-new.jpg';
		$this->seedBreakdanceDocument( $post_id, $old_url );

		$module = \ShortPixel\Replacer\Modules\Breakdance::getInstance();
		$row    = array( 'meta_id' => $this->breakdanceMetaId( $post_id ), 'post_id' => $post_id );
		$tree   = $module->loadContent( '', $row, 'breakdance' );
		$this->assertIsArray( $tree, 'Sentinel: loadContent() returns the tree when the row carries post_id.' );

		$tree['root']['children'][0]['data']['properties']['image']['url'] = $new_url;
		$module->saveContent( $tree, $row, 'breakdance' );

		wp_cache_delete( $post_id, 'post_meta' );
		$this->assertIsString( $this->storedBreakdanceData( $post_id )['tree_json_string'] ?? null, 'tree_json_string stays a string.' );
		$loaded = \Breakdance\Data\get_tree( $post_id );
		$this->assertSame( $new_url, $loaded['root']['children'][0]['data']['properties']['image']['url'] ?? null, 'The saved document loads with the new URL.' );
	}

	/**
	 * Calls saveContent() on a seeded document with a future-layer meta and
	 * returns that meta as stored afterwards.
	 *
	 * @return mixed The decoded meta value, or false when it is gone.
	 */
	private function futureLayerMetaAfterSaveContent() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->seedBreakdanceDocument( $post_id, 'http://example.org/wp-content/uploads/2026/10/bd-future-layer.png' );
		\Breakdance\Data\set_meta( $post_id, '_breakdance_futurelayer_meta', '{"sentinel":"keep-me"}' );

		$reflection = new \ReflectionFunction( 'Breakdance\Data\save_document' );
		$this->assertSame( 10, $reflection->getNumberOfParameters(), 'Sentinel: this Breakdance version takes the ten-argument save_document() the module calls with false.' );

		$module = \ShortPixel\Replacer\Modules\Breakdance::getInstance();
		$row    = array( 'meta_id' => $this->breakdanceMetaId( $post_id ), 'post_id' => $post_id );
		$module->saveContent( $module->loadContent( '', $row, 'breakdance' ), $row, 'breakdance' );

		wp_cache_delete( $post_id, 'post_meta' );
		return \Breakdance\Data\get_meta( $post_id, '_breakdance_futurelayer_meta' );
	}

	/**
	 * Breakdance 3.0+ only writes `_breakdance_futurelayer_meta` in
	 * save_document() when a value is passed, so saveContent() passing
	 * `false` leaves the document's future-layer meta in place.
	 */
	public function test_save_content_keeps_future_layer_meta_on_breakdance_3() {
		if ( version_compare( __BREAKDANCE_VERSION, '3.0.0', '<' ) ) {
			$this->markTestSkipped( 'Breakdance ' . __BREAKDANCE_VERSION . ' still overwrites the meta — covered by the pinned test below.' );
		}

		$this->assertSame( '{"sentinel":"keep-me"}', $this->futureLayerMetaAfterSaveContent(), 'The future-layer meta survives the save.' );
	}

	/**
	 * Pins a known defect on Breakdance 2.x: saveContent() passes `false` as
	 * futureLayerMeta to save_document(), which before 3.0 stores it
	 * unconditionally, so every replacement wipes the document's
	 * `_breakdance_futurelayer_meta`. Every PNG→JPG conversion or rename that
	 * rewrites a Breakdance document goes through saveContent() (see the two
	 * end-to-end tests above). Runs only when the Breakdance zip in
	 * tests/partner-plugins/ is a 2.x release.
	 * @todo In the replacer2 Breakdance module: pass the stored value,
	 *       \Breakdance\Data\get_meta($post_id, '_breakdance_futurelayer_meta'),
	 *       instead of false.
	 * When fixed, this test fails; flip it: the future-layer meta survives.
	 */
	public function test_save_content_overwrites_future_layer_meta_on_breakdance_2_pinned_for_deferred_fix() {
		if ( version_compare( __BREAKDANCE_VERSION, '3.0.0', '>=' ) ) {
			$this->markTestSkipped( 'Breakdance ' . __BREAKDANCE_VERSION . ' keeps the meta itself — covered by the test above.' );
		}

		$this->assertFalse( $this->futureLayerMetaAfterSaveContent(), 'Pinned: fixed? The future-layer meta survives the save — flip this pin.' );
	}
}
