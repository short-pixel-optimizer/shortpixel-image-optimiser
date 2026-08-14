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
 *     uploads/sites/N/ — the path/URL shape most likely to regress;
 *   - the network settings feature (merged in 9eed2de9): the un-stubbed
 *     network admin menu entry, the network_settings_override_enabled read
 *     path on the per-site SettingsModel, and two pinned bugs (#36, #37).
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
		// (Writing through MultiSettingsModel is covered below.)
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
	 * Bug #11 FIXED (4a6edf6e): SettingsModel's $settings/$option_name are
	 * now protected and MultiSettingsModel no longer redeclares them, so the
	 * private-property shadowing is gone — values set through the model reach
	 * the spio_wpmu network option. Flipped from the pinned write-is-lost
	 * assertion.
	 */
	public function test_multisettings_model_write_persists() {
		delete_site_option( 'spio_wpmu' );
		// Precondition sentinel: no stored network settings.
		$this->assertSame( array(), get_site_option( 'spio_wpmu', array() ) );

		$multi                             = new MultiSettingsModel();
		$multi->disable_site_settings_page = true;
		$multi->onShutdown(); // force the deferred save now

		$stored = get_site_option( 'spio_wpmu', array() );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey(
			'disable_site_settings_page',
			$stored,
			'Since 4a6edf6e (bug #11 fix) a value set through MultiSettingsModel must persist to spio_wpmu.'
		);
		$this->assertTrue( (bool) $stored['disable_site_settings_page'] );
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
	// Network settings feature (merged 9eed2de9)
	// -------------------------------------------------------------------

	/**
	 * The network admin menu entry was un-stubbed in the multisite branch:
	 * admin_network_pages() used to bail out with an unconditional `return;`
	 * (@todo). It must now register the ShortPixel submenu under network
	 * Settings and record the page hook (with WPMU's `-network` screen-id
	 * suffix) in $admin_pages so assets load on that screen.
	 */
	public function test_admin_network_pages_registers_network_settings_submenu() {
		global $submenu;

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		\wpSPIO()->admin_network_pages();

		$slugs = array();
		foreach ( (array) ( $submenu['settings.php'] ?? array() ) as $item ) {
			$slugs[] = $item[2];
		}
		$this->assertContains(
			'shortpixel-network-settings',
			$slugs,
			'admin_network_pages() must register the network settings submenu (was a return; stub before the multisite branch).'
		);

		$ref = new ReflectionProperty( \ShortPixel\ShortPixelPlugin::class, 'admin_pages' );
		$ref->setAccessible( true );
		$admin_pages = $ref->getValue( \wpSPIO() );

		// The hook prefix differs between a real admin load ('settings_page_')
		// and the test context ('admin_page_', core menus not registered), so
		// match on the stable slug + '-network' tail only.
		$this->assertNotEmpty(
			preg_grep( '/shortpixel-network-settings-network$/', $admin_pages ),
			'The page hook must be recorded with the -network suffix WPMU appends to screen ids.'
		);
	}

	/**
	 * network_settings_override_enabled: when the network stores a value for
	 * a setting, the per-site SettingsModel must return the network value
	 * instead of the site's own stored value.
	 */
	public function test_network_override_makes_site_settings_read_network_value() {
		// Site explicitly stores the opposite value.
		$settings             = \wpSPIO()->settings();
		$settings->createWebp = false;
		$settings->onShutdown();

		update_site_option(
			'spio_wpmu',
			array(
				'network_settings_override_enabled' => true,
				'createWebp'                        => true,
			)
		);
		$this->resetPluginSingletons();

		$this->assertTrue( \wpSPIO()->settings()->isNetworkOverrideEnabled() );
		$this->assertTrue(
			(bool) \wpSPIO()->settings()->createWebp,
			'With the override enabled, a network-stored value must win over the site-stored value.'
		);
	}

	/** With the toggle off, network-stored values must NOT leak into sites. */
	public function test_network_values_do_not_apply_when_override_is_disabled() {
		$settings             = \wpSPIO()->settings();
		$settings->createWebp = false;
		$settings->onShutdown();

		update_site_option(
			'spio_wpmu',
			array(
				'network_settings_override_enabled' => false,
				'createWebp'                        => true,
			)
		);
		$this->resetPluginSingletons();

		$this->assertFalse( \wpSPIO()->settings()->isNetworkOverrideEnabled() );
		$this->assertFalse(
			(bool) \wpSPIO()->settings()->createWebp,
			'With the override disabled, the site-stored value must be used.'
		);
	}

	/**
	 * PINNED bug #36: SettingsModel::getNetworkSettingValue() gates on
	 * $network_model->exists($name) — which checks the MODEL SCHEMA, not the
	 * stored network values (MultiSettingsModel::isset() exists for that but
	 * is unused). Since every regular setting is in the schema, and
	 * MultiSettingsModel::__get() falls back to model DEFAULTS for unstored
	 * names, enabling the override masks EVERY site-stored setting with the
	 * default — even when the network admin never configured that setting.
	 *
	 * Here: the site stores compressionType=2, the network stores ONLY the
	 * toggle, yet the site reads the default (1) instead of its own 2.
	 *
	 * FLIP when fixed (exists() → stored-value check): the assertion below
	 * fails with compressionType=2 — then assert 2 (site fallback) instead.
	 */
	public function test_pin36_override_masks_site_values_with_network_defaults() {
		$settings                  = \wpSPIO()->settings();
		$settings->compressionType = 2;
		$settings->onShutdown();

		update_site_option( 'spio_wpmu', array( 'network_settings_override_enabled' => true ) );
		$this->resetPluginSingletons();

		$this->assertEquals(
			1,
			\wpSPIO()->settings()->compressionType,
			'PINNED bug #36 — network override returns the model default (1) instead of falling back to the site-stored value (2). When getNetworkSettingValue() checks stored network values instead of the model schema, flip this to assert 2.'
		);
	}

	/**
	 * PINNED bug #37: checkActionAccess() denies `toolsRemoveAll` /
	 * `toolsRemoveBackup` on multisite whenever $env->is_network_admin is
	 * false — but these actions arrive via admin-ajax.php, where WordPress's
	 * is_network_admin() is ALWAYS false (no current_screen, WP_NETWORK_ADMIN
	 * undefined). So on multisite even a super admin clicking the buttons on
	 * the network settings screen is denied: the gate should use a capability
	 * check (e.g. is_super_admin() / manage_network), not the request context.
	 *
	 * This test reproduces the AJAX reality: super admin, no network screen.
	 *
	 * FLIP when fixed: checkActionAccess() will return true for the super
	 * admin and no WPDieException is thrown — then assert the allowed path.
	 */
	public function test_pin37_super_admin_is_denied_sitewide_tools_in_ajax_context() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		// admin-ajax.php reality: no admin screen object is set.
		unset( $GLOBALS['current_screen'] );
		$this->assertFalse( is_network_admin(), 'Precondition: AJAX requests never run as network admin.' );
		$this->assertTrue( \wpSPIO()->env()->is_multisite );

		// The capability itself allows the user — the denial below comes
		// exclusively from the is_network_admin gate.
		$this->assertTrue(
			\ShortPixel\Model\AccessModel::getInstance()->userIsAllowed( 'is_admin_user' ),
			'Precondition: the super admin passes the capability check.'
		);

		$controller = \ShortPixel\Controller\AjaxController::getInstance();
		$method     = new ReflectionMethod( \ShortPixel\Controller\AjaxController::class, 'checkActionAccess' );
		$method->setAccessible( true );

		// wp_send_json() terminates with an uncatchable plain `die` outside an
		// ajax context, and even the default AJAX wp_die handler plain-dies.
		// Force the ajax path AND swap in a throwing die-handler so the JSON
		// termination becomes a catchable exception (hooks are restored by the
		// WP test framework after every test).
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function ( $message ) {
					throw new WPDieException( is_scalar( $message ) ? (string) $message : 'wp_die' );
				};
			}
		);

		$denied = false;
		ob_start();
		try {
			$method->invoke( $controller, 'toolsRemoveAll', 'is_admin_user' );
		} catch ( WPDieException $e ) {
			$denied = true;
		}
		$output = ob_get_clean();

		$this->assertTrue(
			$denied,
			'PINNED bug #37 — a super admin is denied toolsRemoveAll because is_network_admin() is false during AJAX. When the gate switches to a capability check, flip this test to assert the action is allowed.'
		);

		$json = json_decode( $output );
		$this->assertSame( \ShortPixel\Controller\AjaxController::NO_ACCESS, $json->error );
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
