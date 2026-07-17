<?php
/**
 * Integration tests: plugin activation lifecycle (Wave 1, fresh install only).
 *
 * Covers the table lifecycle across the plugin's install states:
 *   - activation creates the four custom tables
 *     (shortpixel_folders / _meta / _postmeta / _aipostmeta);
 *   - deactivation PRESERVES tables (user data survives a deactivate);
 *   - uninstallPlugin() also preserves tables — by design, plugin data is
 *     retained unless the user explicitly chooses "remove all data";
 *   - hardUninstall() (the "remove all data" tools action) drops all four
 *     tables and wipes settings.
 *
 * DDL auto-commits in MySQL, so table drops/creates survive the per-test
 * transaction rollback — tear_down() re-runs checkTables() to leave a
 * healthy install for whatever test runs next.
 *
 * Out of scope (Wave 3): upgrade migrations from older plugin versions.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\InstallHelper;

class ActivationLifecycleTest extends SPIO_IntegrationTestCase {

	private const TABLES = array(
		'shortpixel_folders',
		'shortpixel_meta',
		'shortpixel_postmeta',
		'shortpixel_aipostmeta',
	);

	public function set_up() {
		parent::set_up();

		// hardUninstall() and the htaccess writers assume admin context.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// The WP test framework rewrites CREATE TABLE / DROP TABLE into
		// TEMPORARY variants (query filters registered per-test as instance
		// methods in WP_UnitTestCase_Base::set_up()) so schema changes vanish
		// with the connection. This suite tests the REAL DDL lifecycle, so
		// the rewrites must be off. No re-add needed: the next test's
		// parent::set_up() registers them again.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function tear_down() {
		// Whatever the test did to the tables, leave a healthy install behind:
		// DDL auto-commits and is not covered by the test transaction rollback.
		InstallHelper::checkTables();

		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private function dropAllPluginTables(): void {
		$method = new ReflectionMethod( InstallHelper::class, 'removeTables' );
		$method->setAccessible( true );
		$method->invoke( null );
	}

	private function assertTablesExist( string $context ): void {
		foreach ( self::TABLES as $table ) {
			$this->assertTrue(
				InstallHelper::checkTableExists( $table ),
				"Table $table must exist $context."
			);
		}
	}

	private function assertTablesAbsent( string $context ): void {
		foreach ( self::TABLES as $table ) {
			$this->assertFalse(
				InstallHelper::checkTableExists( $table ),
				"Table $table must NOT exist $context."
			);
		}
	}

	// -------------------------------------------------------------------
	// Activation
	// -------------------------------------------------------------------

	public function test_activation_creates_all_custom_tables_on_fresh_install() {
		$this->dropAllPluginTables();
		$this->assertTablesAbsent( 'after simulating a fresh install (pre-activation)' );

		InstallHelper::activatePlugin();

		$this->assertTablesExist( 'after plugin activation' );
	}

	public function test_activation_stamps_current_plugin_version_in_settings() {
		InstallHelper::activatePlugin();

		$this->assertSame(
			SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
			\wpSPIO()->settings()->currentVersion,
			'Activation must record the running plugin version.'
		);
	}

	public function test_activation_is_idempotent_when_tables_already_exist() {
		InstallHelper::activatePlugin();
		InstallHelper::activatePlugin(); // second run must not error or drop data

		$this->assertTablesExist( 'after a repeated activation' );
	}

	// -------------------------------------------------------------------
	// Deactivation
	// -------------------------------------------------------------------

	public function test_deactivation_preserves_custom_tables() {
		InstallHelper::activatePlugin();

		InstallHelper::deactivatePlugin();

		$this->assertTablesExist( 'after deactivation — user data must survive a deactivate' );
	}

	public function test_deactivation_removes_shortpixel_transients() {
		InstallHelper::activatePlugin();
		set_transient( 'shortpixel_test_marker', 'value', 3600 );

		InstallHelper::deactivatePlugin();

		// deactivatePlugin() deletes transients with direct SQL, which
		// bypasses the options cache; flush so get_transient re-reads the
		// DB (equivalent to the fresh request a real deactivation gets).
		wp_cache_flush();

		$this->assertFalse(
			get_transient( 'shortpixel_test_marker' ),
			'Deactivation must clear shortpixel-prefixed transients.'
		);
	}

	// -------------------------------------------------------------------
	// Uninstall (soft) — data retained by design
	// -------------------------------------------------------------------

	public function test_uninstallPlugin_preserves_custom_tables() {
		InstallHelper::activatePlugin();

		InstallHelper::uninstallPlugin();

		$this->assertTablesExist(
			'after uninstallPlugin() — by design, optimization data is retained unless the user picks "remove all data"'
		);
	}

	// -------------------------------------------------------------------
	// Hard uninstall ("remove all data") — everything goes
	// -------------------------------------------------------------------

	public function test_hardUninstall_removes_all_custom_tables() {
		InstallHelper::activatePlugin();
		$this->assertTablesExist( 'before hard uninstall' );

		$_POST['tools-nonce'] = wp_create_nonce( 'remove-all' );
		try {
			InstallHelper::hardUninstall();
		} finally {
			unset( $_POST['tools-nonce'] );
		}

		$this->assertTablesAbsent( 'after hard uninstall ("remove all data")' );
	}

	public function test_hardUninstall_deletes_plugin_settings() {
		InstallHelper::activatePlugin();
		\wpSPIO()->settings()->apiKey = str_repeat( 'b', 20 );

		$_POST['tools-nonce'] = wp_create_nonce( 'remove-all' );
		try {
			InstallHelper::hardUninstall();
		} finally {
			unset( $_POST['tools-nonce'] );
		}

		$this->assertEmpty(
			get_option( 'spio_settings', array() ),
			'Hard uninstall must delete the spio_settings option.'
		);
	}
}
