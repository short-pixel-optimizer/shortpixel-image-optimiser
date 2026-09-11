<?php
/**
 * Cross-plugin compatibility: Yoast SEO (Wave 4).
 *
 * Runs with the REAL wordpress-seo plugin active (bin/test.sh --compat
 * downloads + activates it). Covers
 * build/shortpixel/replacer2/src/Modules/YoastSeo.php — a "housekeeping"
 * module: after the Replacer finishes rewriting URLs, YoastSeo::removeIndexes
 * DELETEs any wp_yoast_indexable row whose `open_graph_image` or
 * `twitter_image` columns still reference the old URL. Yoast then re-builds
 * those indexables on the next request.
 *
 * Gated on defined('WPSEO_VERSION') with no manual override filter, so this
 * behavior can only be exercised with the real plugin loaded — the
 * Integration suite explicitly defers it here (see the docblock on
 * tests/Integration/test-ConversionReplacement.php).
 *
 * Yoast's installer creates wp_yoast_indexable via its own migrations on
 * activation. If the test install ran that activation (bootstrap does), the
 * table exists; if not, we CREATE the minimal shape SPIO's module queries
 * against — the module only issues LIKE-based DELETEs against three
 * columns (twitter_image, open_graph_image), and the harness bootstrap
 * already runs each partner's `activate_` hook.
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatYoastSeoTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			$this->markTestSkipped( 'Yoast SEO is not loaded — run via bin/test.sh --compat.' );
		}

		// DDL auto-commits, so the table must exist BEFORE the test
		// transaction starts in parent::set_up().
		$this->ensureYoastIndexableTable();

		parent::set_up();

		// Module singletons cache their filter registrations at construction —
		// reset so the next Replacer instance's loadFormats() re-runs the
		// YoastSeo constructor and it re-inspects WPSEO_VERSION.
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

	/**
	 * Yoast creates wp_yoast_indexable via its own migrations. If those did
	 * not run (or ran but a previous drop cleared the table), create the
	 * MINIMAL shape SPIO's YoastSeo module queries — it only writes LIKE
	 * DELETEs against open_graph_image + twitter_image, and reads by
	 * primary key. Everything else on the real Yoast table is irrelevant
	 * to the module and safe to omit.
	 */
	private function ensureYoastIndexableTable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'yoast_indexable';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				object_id bigint unsigned NULL,
				object_type varchar(32) NOT NULL DEFAULT 'post',
				open_graph_image text NULL,
				open_graph_image_meta text NULL,
				twitter_image text NULL,
				PRIMARY KEY (id)
			)"
		);
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

	private function insertIndexableRow( string $og_image, ?string $twitter_image = null ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'yoast_indexable',
			array(
				'object_id'        => 1,
				'object_type'      => 'post',
				'open_graph_image' => $og_image,
				'twitter_image'    => $twitter_image ?? $og_image,
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function indexableRowExists( int $id ): bool {
		global $wpdb;
		$row = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'yoast_indexable WHERE id = %d', $id )
		);
		return null !== $row;
	}

	// -------------------------------------------------------------------
	// Coexistence + auto-detection
	// -------------------------------------------------------------------

	public function test_yoast_loads_alongside_spio() {
		$this->assertTrue( defined( 'WPSEO_VERSION' ), 'Yoast WPSEO_VERSION constant must be defined.' );
	}

	/**
	 * With defined('WPSEO_VERSION') true, the module must self-activate and
	 * register its shortpixel/replacer/replace_urls action — no manual
	 * override filter exists for YoastSeo.
	 */
	public function test_yoast_module_autodetects_real_plugin_and_registers_action() {
		remove_all_filters( 'shortpixel/replacer/replace_urls' );

		\ShortPixel\Replacer\Replacer::getInstance();

		$this->assertNotFalse(
			has_action(
				'shortpixel/replacer/replace_urls',
				array( \ShortPixel\Replacer\Modules\YoastSeo::getInstance(), 'removeIndexes' )
			),
			'The YoastSeo module must self-register its replace_urls action when real Yoast is loaded.'
		);
	}

	// -------------------------------------------------------------------
	// End-to-end: PNG→JPG conversion deletes stale indexable rows
	// -------------------------------------------------------------------

	/**
	 * After a PNG→JPG conversion the YoastSeo module must DELETE any
	 * wp_yoast_indexable row whose open_graph_image or twitter_image
	 * references the pre-conversion URL. Yoast re-builds the indexable
	 * on the next request, so deletion is the correct fix — the module
	 * queries with LIKE '%<base>%' twice: once with the base URL, once
	 * with the file name.
	 */
	public function test_png_conversion_deletes_yoast_indexable_rows_referencing_old_url() {
		$id  = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url = wp_get_attachment_url( $id );
		$this->assertStringEndsWith( '.png', $url );

		// Row A references the .png in BOTH og+twitter — must be deleted.
		$row_a = $this->insertIndexableRow( $url, $url );
		// Row B references an unrelated URL — must survive.
		$row_b = $this->insertIndexableRow( 'https://example.com/some-other-image.jpg' );

		$this->assertTrue( $this->indexableRowExists( $row_a ), 'Sentinel: row A (with .png ref) must exist pre-conversion.' );
		$this->assertTrue( $this->indexableRowExists( $row_b ), 'Sentinel: row B (unrelated) must exist pre-conversion.' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ),
			'PNG must be converted to JPG through the queue path.'
		);

		$this->assertFalse(
			$this->indexableRowExists( $row_a ),
			'YoastSeo module must DELETE the indexable row that referenced the pre-conversion .png URL.'
		);
		$this->assertTrue(
			$this->indexableRowExists( $row_b ),
			'YoastSeo module must NOT touch indexable rows that do not reference the converted URL.'
		);
	}

	/**
	 * The module runs its LIKE match against the RELATIVE URL PATH
	 * (search_urls['base'] / ['file'] — see
	 * ShortPixel\Replacer\Replacer::getRelativeURLS: both keys hold a
	 * URL PATH, not a bare filename). This test pins that behavior: a
	 * row that only mentions the bare filename (no URL path prefix at
	 * all) is NOT deleted by the module — the LIKE query needs enough
	 * URL context to match. If a future refactor added filename-only
	 * LIKE support, this test would flip and needs revisiting.
	 */
	public function test_yoast_filename_only_reference_is_left_intact_documents_current_behavior() {
		$id       = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url      = wp_get_attachment_url( $id );
		$filename = wp_basename( $url ); // e.g. fixture-small.png (or a wp_unique_filename variant)

		// A row that mentions ONLY the bare filename — no URL path
		// context that would show up in search_urls['base'|'file'].
		$row_id = $this->insertIndexableRow( 'Some caption referencing ' . $filename );

		$this->assertTrue( $this->indexableRowExists( $row_id ), 'Sentinel: filename-only row must exist pre-conversion.' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$this->assertSame(
			'jpg',
			strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ),
			'PNG must be converted to JPG.'
		);

		$this->assertTrue(
			$this->indexableRowExists( $row_id ),
			'DOCUMENTS CURRENT BEHAVIOR: YoastSeo module keys its LIKE on the URL PATH (search_urls[\'base\'|\'file\']), NOT on the bare filename — a row that only mentions the filename survives. See build/shortpixel/replacer2/src/Modules/YoastSeo.php:38-55.'
		);
	}

	// -------------------------------------------------------------------
	// Manual file rename also purges stale indexables
	// -------------------------------------------------------------------

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does (:1409-1413). */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new \ShortPixel\Model\Queue\QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	/**
	 * The "Change Filename" / AI-filename rename runs the same Replacer
	 * pass as conversions, so an indexable row whose og/twitter image
	 * references the OLD filename must be deleted after a rename (Yoast
	 * rebuilds it on the next request); unrelated rows must survive.
	 */
	public function test_manual_rename_deletes_yoast_indexable_rows_referencing_old_url() {
		$id  = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$url = wp_get_attachment_url( $id );

		$row_a = $this->insertIndexableRow( $url, $url );
		$row_b = $this->insertIndexableRow( 'https://example.com/some-other-image.jpg' );

		$this->assertTrue( $this->indexableRowExists( $row_a ), 'Sentinel: row A (old URL) must exist pre-rename.' );
		$this->assertTrue( $this->indexableRowExists( $row_b ), 'Sentinel: row B (unrelated) must exist pre-rename.' );

		$new_base = 'yoast-rn-' . wp_generate_password( 6, false );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'The rename must report success.' );
		$this->assertStringContainsString( $new_base, get_attached_file( $id ), 'Sanity: _wp_attached_file must carry the new base.' );

		$this->assertFalse(
			$this->indexableRowExists( $row_a ),
			'YoastSeo module must DELETE the indexable row that referenced the pre-rename URL.'
		);
		$this->assertTrue(
			$this->indexableRowExists( $row_b ),
			'YoastSeo module must NOT touch indexable rows unrelated to the renamed file.'
		);
	}
}
