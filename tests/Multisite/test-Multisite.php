<?php
/**
 * Multisite behavior tests.
 *
 * Run under a multisite WordPress test install (WP_MULTISITE=1 — see
 * bin/test.sh --ms). Every test self-skips on a single-site install so
 * accidentally selecting this suite without the env flag yields skips,
 * not failures.
 *
 * Coverage targets the plugin's real multisite surface:
 *   - per-site custom tables (wp_N_shortpixel_*) via $wpdb->prefix;
 *   - per-site 'spio_settings' isolation vs the network-wide 'spio_wpmu'
 *     option (MultiSettingsModel);
 *   - the full optimization pipeline on a subsite, whose uploads live in
 *     uploads/sites/N/ — the path/URL shape most likely to regress.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\InstallHelper;
use ShortPixel\Model\MultiSettingsModel;

class MultisiteTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite suite needs a multisite test install — run via bin/test.sh --ms (WP_MULTISITE=1).' );
		}
		parent::set_up();
	}

	public function tear_down() {
		// A mid-test assertion failure can leave us switched to a subsite;
		// unwind before the base tear_down runs its main-site cleanup.
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		parent::tear_down();
	}

	/**
	 * Switch to a fresh subsite and give it the same healthy-install
	 * baseline the base class gives the main site: SPIO tables, verified
	 * key, settings, empty queue/meta tables (all per-site on multisite).
	 */
	private function createAndEnterSubsite(): int {
		$blog_id = (int) self::factory()->blog->create();
		switch_to_blog( $blog_id );

		InstallHelper::checkTables();

		update_option(
			'spio_key',
			array(
				'apiKey'      => str_repeat( 'a', 20 ),
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);

		$this->resetPluginSingletons();

		$settings                     = \wpSPIO()->settings();
		$settings->quotaExceeded      = 0;
		$settings->backupImages       = 1;
		$settings->autoMediaLibrary   = 1;
		$settings->redirectedSettings = 1;

		$this->resetPluginSingletons();
		$this->purgeQueueTable();
		$this->purgeMetaTable();

		return $blog_id;
	}

	/** Leave the subsite and drop every per-site cache picked up there. */
	private function leaveSubsite(): void {
		restore_current_blog();
		$this->resetPluginSingletons();
	}

	public function test_environment_detects_multisite() {
		$this->assertTrue( is_multisite() );
		$this->assertTrue( \wpSPIO()->env()->is_multisite );
	}

	public function test_subsite_gets_its_own_shortpixel_tables() {
		global $wpdb;

		$main_prefix = $wpdb->prefix;
		$blog_id     = $this->createAndEnterSubsite();
		$sub_prefix  = $wpdb->prefix;

		$this->assertNotSame( $main_prefix, $sub_prefix, 'switch_to_blog must change the table prefix' );

		// The WP test framework rewrites mid-test DDL to CREATE TEMPORARY
		// TABLE, and temp tables are invisible to SHOW TABLES — so assert
		// queryability instead of catalog presence.
		foreach ( array( 'shortpixel_meta', 'shortpixel_folders', 'shortpixel_postmeta', 'shortpixel_aipostmeta' ) as $table ) {
			$sub_table = $sub_prefix . $table;
			$count     = $wpdb->get_var( "SELECT COUNT(*) FROM `$sub_table`" );
			$this->assertSame( '', $wpdb->last_error, "checkTables() on a subsite must create a queryable $sub_table" );
			$this->assertIsNumeric( $count );
		}

		$this->leaveSubsite();

		// The main site keeps its own set under the base prefix.
		$main_table = $main_prefix . 'shortpixel_meta';
		$this->assertSame(
			$main_table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $main_table ) )
		);
	}

	public function test_settings_are_isolated_per_site() {
		// Non-default value on the main site (default compressionType is 1).
		// SettingsModel defers its DB write to shutdown — onShutdown() forces
		// the save NOW, before singleton resets/blog switches drop it.
		$settings                  = \wpSPIO()->settings();
		$settings->compressionType = 2;
		$settings->onShutdown();

		$this->createAndEnterSubsite();

		$this->assertEquals(
			1,
			\wpSPIO()->settings()->compressionType,
			'A fresh subsite must start from setting defaults, not the main site values'
		);

		$sub_settings                  = \wpSPIO()->settings();
		$sub_settings->compressionType = 0;
		$sub_settings->onShutdown();

		$this->leaveSubsite();

		$this->assertEquals(
			2,
			\wpSPIO()->settings()->compressionType,
			'Subsite settings writes must not leak into the main site'
		);
	}

	public function test_network_settings_are_shared_across_sites() {
		// Written the way admin_pages() reads it: the raw network option.
		// (Writing through MultiSettingsModel is pinned as broken below.)
		update_site_option( 'spio_wpmu', array( 'disable_site_settings_page' => true ) );

		$this->createAndEnterSubsite();

		// Network options are prefix-less (wp_sitemeta) — the same row must
		// be visible from any site. This is what admin_pages() reads to
		// suppress the subsite settings page.
		$network_settings = get_site_option( 'spio_wpmu', array() );
		$this->assertNotEmpty( $network_settings['disable_site_settings_page'] ?? null );

		$this->leaveSubsite();
	}

	/**
	 * PINNED (bug, reported 2026-07-18): MultiSettingsModel redeclares
	 * $settings and $option_name as PRIVATE, shadowing SettingsModel's
	 * equally-private properties. The parent's magic __set() writes to the
	 * PARENT's slot, while the child's overridden save() persists the
	 * never-modified CHILD slot — so nothing set through the model ever
	 * reaches the spio_wpmu network option (and __get() likewise reads the
	 * empty parent slot, i.e. defaults, not stored values). The whole
	 * network-settings screen pipeline (MultiSiteViewController →
	 * processSave()/getData()) is a silent no-op; currently masked in
	 * production because admin_network_pages() is stubbed out.
	 *
	 * This pins the BUGGY behaviour so the suite stays green. When the fix
	 * lands (e.g. make both properties protected), this test FAILS — then
	 * flip it to assert the value IS persisted.
	 */
	public function test_multisettings_model_write_is_lost_pinned() {
		delete_site_option( 'spio_wpmu' );
		// Precondition sentinel: no stored network settings.
		$this->assertSame( array(), get_site_option( 'spio_wpmu', array() ) );

		$multi                             = new MultiSettingsModel();
		$multi->disable_site_settings_page = true;
		$multi->onShutdown(); // force the deferred save now

		$stored = get_site_option( 'spio_wpmu', array() );
		$this->assertIsArray( $stored );
		$this->assertArrayNotHasKey(
			'disable_site_settings_page',
			$stored,
			'MultiSettingsModel property shadowing appears FIXED — flip this pinned test to assert persistence.'
		);
	}

	public function test_subsite_upload_paths_and_urls_use_sites_subdirectory() {
		$blog_id = $this->createAndEnterSubsite();

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$path          = get_attached_file( $attachment_id );

		$this->assertStringContainsString(
			'/sites/' . $blog_id . '/',
			$path,
			'Subsite uploads must land in uploads/sites/N/'
		);

		$fs  = \wpSPIO()->filesystem();
		$url = $fs->pathToUrl( $fs->getFile( $path ) );

		$this->assertStringContainsString(
			'/uploads/sites/' . $blog_id . '/',
			$url,
			'pathToUrl must map a subsite file back into the subsite uploads URL'
		);

		wp_delete_attachment( $attachment_id, true );
		$this->uploadedAttachments = array();
		$this->leaveSubsite();
	}

	public function test_optimization_pipeline_runs_on_a_subsite() {
		global $wpdb;

		$blog_id = $this->createAndEnterSubsite();

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $attachment_id );

		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$this->assertTrue( $imageModel->isOptimized(), 'The queue pipeline must optimize an image uploaded on a subsite' );

		$this->assertNotEmpty(
			$this->api->requests,
			'Optimization must have gone through the (mocked) API'
		);

		// The optimization meta must land in the SUBSITE's own table.
		$meta_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->prefix . 'shortpixel_postmeta`' );
		$this->assertGreaterThan( 0, $meta_rows, 'Optimization meta must be written to the per-site shortpixel_postmeta table' );

		wp_delete_attachment( $attachment_id, true );
		$this->uploadedAttachments = array();
		$this->leaveSubsite();
	}

	// -------------------------------------------------------------------
	// Plan 1.13 — per-subsite API key is isolated from main site
	// -------------------------------------------------------------------

	/**
	 * Each subsite must store its own spio_key option independently.
	 * Writing a different key on the subsite must not alter the main site's key,
	 * and reading the main site's key on the subsite must not be visible.
	 *
	 * Plan row: 1.13 — per-subsite API key is isolated from main site.
	 *
	 * NOTE: The multisite harness note applies — SettingsModel defers save to
	 * shutdown; call onShutdown() to force the write before switching blogs.
	 * Delete subsite attachments BEFORE restore_current_blog (leaveSubsite()).
	 *
	 * @see class/Model/ApiKeyModel.php — option_name = 'spio_key' (per-site in multisite)
	 */
	public function test_subsite_api_key_is_isolated_from_main_site() {
		// Confirm main-site baseline: 20 a's from spioSetUpBaseline().
		$main_key = get_option( 'spio_key' );
		$this->assertSame(
			str_repeat( 'a', 20 ),
			$main_key['apiKey'],
			'Precondition: main site has the baseline API key'
		);

		// Create a subsite and give it its own different key.
		$blog_id     = $this->createAndEnterSubsite();
		$subsite_key = str_repeat( 'z', 20 );

		update_option( 'spio_key', array(
			'apiKey'      => $subsite_key,
			'verifiedKey' => true,
			'apiKeyTried' => '',
		) );

		// Force settings save (SettingsModel defers to shutdown hook).
		$settings = \wpSPIO()->settings();
		$settings->onShutdown();

		// Read back the subsite key while still on the subsite.
		$stored_on_subsite = get_option( 'spio_key' );
		$this->assertSame(
			$subsite_key,
			$stored_on_subsite['apiKey'],
			'Subsite spio_key must store the key written on the subsite'
		);

		// Delete any attachments before switching back (multisite harness requirement).
		foreach ( $this->uploadedAttachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->uploadedAttachments = array();

		// Switch back to main site.
		$this->leaveSubsite();

		// Main site must still have its original key.
		$main_key_after = get_option( 'spio_key' );
		$this->assertSame(
			str_repeat( 'a', 20 ),
			$main_key_after['apiKey'],
			'Main site spio_key must not be affected by a key written on the subsite'
		);

		// The subsite key must not be visible on the main site option.
		$this->assertNotSame(
			$subsite_key,
			$main_key_after['apiKey'],
			'Subsite key must be isolated and not bleed into the main site spio_key option'
		);
	}
}
