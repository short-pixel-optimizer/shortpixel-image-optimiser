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
 *     path on the per-site SettingsModel (network defaults win by design —
 *     ex-#36, closed as intended), the fixed #37 super-admin AJAX access
 *     (4acf1395), and pinned bug #39 (is_super_admin missing from the
 *     AccessModel caps map).
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
	 * INTENDED behavior (was reported as bug #36, closed as by-design 2026-08-17):
	 * with the network override enabled, the network level is authoritative for
	 * ALL settings — getNetworkSettingValue() gates on the model SCHEMA, so
	 * settings the network admin never configured resolve to the network model
	 * DEFAULT rather than falling back to the site-stored value.
	 *
	 * Here: the site stores compressionType=2, the network stores ONLY the
	 * toggle, and the site reads the network default (1) — by design.
	 *
	 * A future option may let users choose which settings stay site-level;
	 * revisit this test when that lands.
	 */
	public function test_override_applies_network_defaults_even_for_unstored_settings() {
		$settings                  = \wpSPIO()->settings();
		$settings->compressionType = 2;
		$settings->onShutdown();

		update_site_option( 'spio_wpmu', array( 'network_settings_override_enabled' => true ) );
		$this->resetPluginSingletons();

		$this->assertEquals(
			1,
			\wpSPIO()->settings()->compressionType,
			'With the override enabled the network level is authoritative: unstored settings resolve to the network default (1), not the site-stored value (2). Intended behavior (ex-#36, closed by design).'
		);
	}

	/**
	 * Bug #37 FIXED (4acf1395): the is_network_admin request-context gate was
	 * removed from checkActionAccess(); `toolsRemoveAll` / `toolsRemoveBackup`
	 * now pass the 'is_super_admin' access level to AccessModel instead. Since
	 * is_network_admin() is always false during admin-ajax.php, the old gate
	 * denied even super admins — this regression test reproduces the AJAX
	 * reality (super admin, no network screen) and asserts access is granted.
	 */
	public function test_super_admin_is_allowed_sitewide_tools_in_ajax_context() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		// admin-ajax.php reality: no admin screen object is set.
		unset( $GLOBALS['current_screen'] );
		$this->assertFalse( is_network_admin(), 'Precondition: AJAX requests never run as network admin.' );
		$this->assertTrue( \wpSPIO()->env()->is_multisite );

		list( $allowed, $output ) = $this->invokeCheckActionAccess( 'toolsRemoveAll', 'is_super_admin' );

		$this->assertTrue(
			$allowed,
			'Bug #37 fixed (4acf1395) — a super admin must be allowed to run toolsRemoveAll from the AJAX context. A denial here means the is_network_admin request-context gate (or similar) is back.'
		);
		$this->assertSame( '', $output, 'No JSON error must be emitted on the allowed path.' );
	}

	/**
	 * PINNED bug #39: the 'is_super_admin' access level used by the #37 fix
	 * (4acf1395) is NOT defined in AccessModel::setDefaultPermissions(), so
	 * getCap() falls back to the default 'manage_options' — a capability every
	 * regular site administrator holds on multisite. The intended restriction
	 * ("super admins only" per the commit message) therefore does not restrict
	 * anything: a plain subsite admin can still run the site-wide destructive
	 * tools (Remove All Data / Remove Backups).
	 *
	 * FLIP when fixed (caps map entry or real is_super_admin() check): the
	 * invocation below will be denied — then assert $allowed is false and the
	 * JSON error is NO_ACCESS.
	 */
	public function test_pin39_regular_site_admin_can_still_run_sitewide_tools() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertFalse( is_super_admin( $admin_id ), 'Precondition: a plain administrator, not a super admin.' );
		unset( $GLOBALS['current_screen'] );
		$this->assertTrue( \wpSPIO()->env()->is_multisite );

		list( $allowed, $output ) = $this->invokeCheckActionAccess( 'toolsRemoveAll', 'is_super_admin' );

		$this->assertTrue(
			$allowed,
			"PINNED bug #39 — 'is_super_admin' is missing from the AccessModel caps map, so it falls back to 'manage_options' and a regular site admin passes. When the check truly restricts to super admins, flip this to assert denial."
		);
	}

	/**
	 * Invoke AjaxController::checkActionAccess() so that a denial is
	 * observable instead of fatal.
	 *
	 * wp_send_json() terminates with an uncatchable plain `die` outside an
	 * ajax context, and even the default AJAX wp_die handler plain-dies.
	 * Force the ajax path AND swap in a throwing die-handler so the JSON
	 * termination becomes a catchable exception (hooks are restored by the
	 * WP test framework after every test).
	 *
	 * @param string $action The ajax action name to check.
	 * @param string $access The AccessModel access level string.
	 * @return array{0: bool, 1: string} [allowed, captured JSON output].
	 */
	private function invokeCheckActionAccess( $action, $access ) {
		$controller = \ShortPixel\Controller\AjaxController::getInstance();
		$method     = new ReflectionMethod( \ShortPixel\Controller\AjaxController::class, 'checkActionAccess' );
		$method->setAccessible( true );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function ( $message ) {
					throw new WPDieException( is_scalar( $message ) ? (string) $message : 'wp_die' );
				};
			}
		);

		$allowed = false;
		ob_start();
		try {
			$allowed = (bool) $method->invoke( $controller, $action, $access );
		} catch ( WPDieException $e ) {
			$allowed = false;
		}
		$output = ob_get_clean();

		return array( $allowed, $output );
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

	// -------------------------------------------------------------------
	// Network settings save pipeline (MultiSiteViewController::processSave)
	// -------------------------------------------------------------------

	/**
	 * Run MultiSiteViewController::processSave() against a crafted $_POST and
	 * return the decoded handleAjaxSave() JSON response.
	 *
	 * processSave() terminates through wp_send_json(); the ajax filters below
	 * turn that plain die into a catchable WPDieException (see
	 * invokeCheckActionAccess for the rationale). Persistence to spio_wpmu is
	 * deferred to a PHP shutdown handler in production, so the model flush is
	 * triggered here explicitly on the controller's own model instance.
	 *
	 * @param array $postFields Form fields as posted by the network settings form.
	 * @return object|null Decoded JSON response of handleAjaxSave().
	 */
	private function runNetworkProcessSave( array $postFields ) {
		$_POST = $postFields;

		$controller = new \ShortPixel\Controller\View\MultiSiteViewController();

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function ( $message ) {
					throw new WPDieException( is_scalar( $message ) ? (string) $message : 'wp_die' );
				};
			}
		);

		$method = new ReflectionMethod( \ShortPixel\Controller\View\MultiSiteViewController::class, 'processSave' );
		$method->setAccessible( true );

		$ob_level = ob_get_level();
		ob_start();
		try {
			$method->invoke( $controller );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
		// The save flow (ErrorController fatal capture) can open an extra
		// buffer that the wp_die exception skips closing — unwind to ours,
		// keeping the innermost captured output (the JSON).
		$output = '';
		while ( ob_get_level() > $ob_level ) {
			$output = ob_get_clean() . $output;
		}
		$_POST = array();

		// Flush the controller's own MultiSettingsModel instance the way the
		// registered PHP shutdown handler would at real request end.
		$ref  = new ReflectionObject( $controller );
		$prop = $ref->getProperty( 'model' );
		$prop->setAccessible( true );
		$prop->getValue( $controller )->onShutdown();

		return json_decode( (string) $output );
	}

	/**
	 * The full network save leg: posted fields must be sanitized, applied to
	 * the MultiSettingsModel and persisted into the spio_wpmu network option.
	 */
	public function test_network_save_persists_posted_settings_to_spio_wpmu() {
		$response = $this->runNetworkProcessSave(
			array(
				'network_settings_override_enabled' => 'on',
				'createWebp'                        => 'on',
				'compressionType'                   => '2',
			)
		);

		$this->assertIsObject( $response, 'processSave must terminate through wp_send_json with a JSON body.' );
		$this->assertTrue( $response->result, 'The ajax save response must report success.' );

		$stored = get_site_option( 'spio_wpmu' );
		$this->assertIsArray( $stored, 'The network save must write the spio_wpmu network option.' );
		$this->assertTrue( (bool) $stored['network_settings_override_enabled'], 'The posted override toggle must persist.' );
		$this->assertTrue( (bool) $stored['createWebp'], 'A posted boolean must persist as true.' );
		$this->assertEquals( 2, $stored['compressionType'], 'A posted scalar must persist sanitized.' );
	}

	/**
	 * Unchecked checkboxes are absent from the POST body; processSave() must
	 * collapse every stored-but-unposted boolean to false (the same behavior
	 * the site-level save has — and the class of bug behind earlier settings
	 * regressions).
	 */
	public function test_network_save_collapses_unposted_booleans_to_false() {
		update_site_option(
			'spio_wpmu',
			array(
				'network_settings_override_enabled' => true,
				'createWebp'                        => true,
			)
		);

		$response = $this->runNetworkProcessSave(
			array(
				// createWebp deliberately NOT posted — an unchecked checkbox.
				'network_settings_override_enabled' => 'on',
				'compressionType'                   => '1',
			)
		);

		$this->assertIsObject( $response );
		$this->assertTrue( $response->result );

		$stored = get_site_option( 'spio_wpmu' );
		$this->assertFalse( (bool) $stored['createWebp'], 'A stored boolean missing from the POST must collapse to false.' );
		$this->assertTrue( (bool) $stored['network_settings_override_enabled'], 'The posted toggle must stay enabled.' );
	}

	// -------------------------------------------------------------------
	// Site settings menu gating (ShortPixelPlugin::admin_pages)
	// -------------------------------------------------------------------

	/**
	 * Register the admin pages as a site admin and report whether the
	 * per-site ShortPixel settings submenu was added.
	 */
	private function siteSettingsMenuRegistered(): bool {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Isolate from menu state leaked by other tests in this process.
		$GLOBALS['submenu'] = array();

		\wpSPIO()->admin_pages();

		$entries = isset( $GLOBALS['submenu']['options-general.php'] ) ? $GLOBALS['submenu']['options-general.php'] : array();
		foreach ( $entries as $entry ) {
			if ( isset( $entry[2] ) && 'wp-shortpixel-settings' === $entry[2] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * With network_settings_override_enabled OR disable_site_settings_page
	 * set at network level, admin_pages() must not register the per-site
	 * settings page for subsite admins.
	 */
	public function test_site_settings_menu_is_suppressed_by_network_gating() {
		update_site_option( 'spio_wpmu', array( 'network_settings_override_enabled' => true ) );
		$this->assertFalse( $this->siteSettingsMenuRegistered(), 'The override toggle must hide the site settings menu.' );

		update_site_option( 'spio_wpmu', array( 'disable_site_settings_page' => true ) );
		$this->assertFalse( $this->siteSettingsMenuRegistered(), 'disable_site_settings_page must hide the site settings menu.' );
	}

	/** Without any network gating, the site settings menu must register normally. */
	public function test_site_settings_menu_registers_without_network_gating() {
		update_site_option( 'spio_wpmu', array() );
		$this->assertTrue( $this->siteSettingsMenuRegistered(), 'With no network gating the site settings menu must be present.' );
	}

	// -------------------------------------------------------------------
	// Override scope + isolation
	// -------------------------------------------------------------------

	/**
	 * The network override is stored network-wide, so it must win over the
	 * locally stored value on ANY subsite — not just the main site (which the
	 * earlier override tests cover).
	 */
	public function test_network_override_applies_on_subsite() {
		update_site_option(
			'spio_wpmu',
			array(
				'network_settings_override_enabled' => true,
				'createWebp'                        => true,
			)
		);

		$this->createAndEnterSubsite();

		$settings             = \wpSPIO()->settings();
		$settings->createWebp = false; // site-stored value on the subsite
		$settings->onShutdown();
		$this->resetPluginSingletons();

		$this->assertTrue( \wpSPIO()->settings()->isNetworkOverrideEnabled(), 'The network toggle must be visible from the subsite.' );
		$this->assertTrue(
			(bool) \wpSPIO()->settings()->createWebp,
			'The network-stored value must win over the subsite-stored value.'
		);

		$this->leaveSubsite();
	}

	/**
	 * The API key lives in the per-site spio_key option (ApiKeyModel), outside
	 * the SettingsModel schema — the network override must never mask it.
	 * Pinning this explicitly so a refactor that routes the key through the
	 * settings override path gets caught.
	 */
	public function test_api_key_is_not_masked_by_network_override() {
		update_site_option(
			'spio_wpmu',
			array(
				'network_settings_override_enabled' => true,
				'apiKey'                            => str_repeat( 'x', 20 ), // must never be consulted
			)
		);
		$this->resetPluginSingletons();

		$keyControl = \ShortPixel\Controller\ApiKeyController::getInstance();
		$this->assertSame(
			str_repeat( 'a', 20 ),
			$keyControl->getKeyModel()->getKey(),
			'The API key comes from the per-site spio_key option and must not be masked by the network override.'
		);
		$this->assertTrue( $keyControl->keyIsVerified(), 'The site key must stay verified with the override enabled.' );
	}

	// -------------------------------------------------------------------
	// Network settings page rendering (#38 regression surface)
	// -------------------------------------------------------------------

	/**
	 * Render the network settings page and assert the Network Control tab is
	 * functional: the override toggle input exists (part-network-override.php
	 * present — the file whose absence was bug #38) and the form posts the
	 * 'save-multi-settings' screen action.
	 */
	public function test_network_settings_page_renders_override_toggle_and_form_action() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$_POST = array();

		$controller = new \ShortPixel\Controller\View\MultiSiteViewController();
		ob_start();
		$controller->load();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'id="tab-network"', $html, 'The Network Control tab must render (bug #38 fixed — part-network-override.php present).' );
		$this->assertStringContainsString( 'name="network_settings_override_enabled"', $html, 'The override toggle input must be present.' );
		$this->assertStringContainsString( 'value="save-multi-settings"', $html, 'The form_action field must post the network save action.' );
	}

	// -------------------------------------------------------------------
	// Network value sanitization on the site read path
	// -------------------------------------------------------------------

	/**
	 * Values stored dirty at network level (bad type, markup) must come out
	 * sanitized when a site reads them through the override path
	 * (SettingsModel::__get → MultiSettingsModel::__get → sanitize()).
	 */
	public function test_dirty_network_values_are_sanitized_on_site_read() {
		update_site_option(
			'spio_wpmu',
			array(
				'network_settings_override_enabled' => true,
				'compressionType'                   => '2<script>alert(1)</script>',
				'createWebp'                        => 'yes',
			)
		);
		$this->resetPluginSingletons();

		$settings = \wpSPIO()->settings();
		$this->assertSame( 2, $settings->compressionType, 'A dirty int must be cast clean by the sanitizer on the override read path.' );
		$this->assertTrue( (bool) $settings->createWebp, 'A truthy string must sanitize to a boolean true.' );
	}
}
