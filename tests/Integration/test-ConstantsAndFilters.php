<?php
/**
 * Integration tests: wp-config.php constants take effect (manual plan 9.2).
 *
 * Each test method covers one documented wp-config constant and asserts its
 * documented effect through the plugin's real code path.  Only constants that
 * are (a) NOT already defined by the test harness at bootstrap time, and
 * (b) safe to define once for the whole process are tested here.
 *
 * Constants not tested, and why:
 *  - SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION / SHORTPIXEL_USE_DOUBLE_AVIF_EXTENSION:
 *    already define()d to false in wp-shortpixel.php before the test process
 *    starts; cannot be redefined.  The "false" default is covered in
 *    tests/Model/test-EnvironmentModel.php.
 *  - SHORTPIXEL_DEBUG: already define()d to false in wp-shortpixel.php.
 *  - SHORTPIXEL_BACKUP_FOLDER: already define()d from the uploads base path;
 *    tested in test_backup_folder_constant_is_defined_and_path_based_on_uploads()
 *    below (confirming the constant is consumed, not redefined).
 *  - SHORTPIXEL_CUSTOM_THUMB_SUFFIXES / SHORTPIXEL_CUSTOM_THUMB_INFIXES: the
 *    consuming method (MediaLibraryModel::addUnlisted()) is protected, requires a
 *    real attachment on disk, and merges the value through a filter that would need
 *    a second define() call in each test. Not safely testable in-process.
 *  - SHORTPIXEL_NO_BANNER: consumed inside SettingsViewController::loadSettings(),
 *    which requires a full admin-page context (quota data, language list, etc.).
 *    Not safely testable without a rendered settings view.
 *  - SHORTPIXEL_SKIP_FEEDBACK: consumed in class/view/shortpixel-feedback.php,
 *    a view-only template; no testable code path without rendering.
 *  - SHORTPIXEL_HTTP_AUTH_USER / SHORTPIXEL_HTTP_AUTH_PASSWORD: used in
 *    QuotaController to set HTTP Basic Auth; exercising it would require a live
 *    HTTP request.  Not testable here.
 *  - SHORTPIXEL_CFTOKEN / SHORTPIXEL_CFZONE: consumed in the Cloudflare
 *    integration class constructor; requires Cloudflare zone activation.
 *
 * PROCESS ISOLATION: define()d constants live until the PHP process dies, so
 * this file is EXCLUDED from the shared Integration testsuite (its
 * SHORTPIXEL_API_KEY / SILENT_MODE / TRUSTED_MODE defines would poison every
 * test that runs after it). It runs as the separate "IntegrationIsolated"
 * testsuite — a second phpunit invocation made by bin/test.sh --integration.
 * runInSeparateProcess is NOT available in this harness (shared WP bootstrap);
 * each test still guards with defined() + markTestSkipped when a constant is
 * already present in the process.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminNoticesController;
use ShortPixel\Model\ApiKeyModel;
use ShortPixel\Model\EnvironmentModel;

class ConstantsAndFiltersTest extends SPIO_IntegrationTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Calls a protected or private method through reflection, walking the
	 * class hierarchy until the method is found.
	 *
	 * @param object $obj    Object to invoke the method on.
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Return value of the method.
	 */
	private function invokeMethod( object $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( $obj );
		while ( ! $ref->hasMethod( $method ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	/**
	 * Writes a protected or private property through reflection, walking the
	 * class hierarchy until the property is found.
	 *
	 * @param object $obj   Target object.
	 * @param string $prop  Property name.
	 * @param mixed  $value Value to set.
	 */
	private function setPrivateProp( object $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( $obj );
		while ( ! $ref->hasProperty( $prop ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	// -------------------------------------------------------------------------
	// 9.2a — SHORTPIXEL_SILENT_MODE
	// -------------------------------------------------------------------------

	/**
	 * When SHORTPIXEL_SILENT_MODE is true, AdminNoticesController's constructor
	 * sets the silent-mode flag and bails early (no notice-polling actions are
	 * registered). isSilentMode() must return true on the constructed instance.
	 *
	 * We bypass the singleton and construct a fresh instance so the constant
	 * can be observed without resetting shared singleton state.
	 *
	 * Manual plan row: 9.2 (SHORTPIXEL_SILENT_MODE)
	 */
	public function test_silent_mode_constant_sets_notice_controller_flag() {
		if ( defined( 'SHORTPIXEL_SILENT_MODE' ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_SILENT_MODE is already defined in this process; cannot redefine.' );
		}

		define( 'SHORTPIXEL_SILENT_MODE', true );

		// Create a fresh AdminNoticesController instance, bypassing the singleton,
		// so the constructor runs against the just-defined constant.
		$ref  = new ReflectionClass( AdminNoticesController::class );
		$ctrl = $ref->newInstanceWithoutConstructor();

		// Seed the property to the non-silent default before calling __construct.
		$this->setPrivateProp( $ctrl, 'silent_mode', false );

		// Invoke the constructor which reads SHORTPIXEL_SILENT_MODE.
		$ctor = $ref->getConstructor();
		$ctor->setAccessible( true );
		$ctor->invoke( $ctrl );

		$this->assertTrue(
			$ctrl->isSilentMode(),
			'When SHORTPIXEL_SILENT_MODE is true, AdminNoticesController::isSilentMode() must return true. (plan 9.2)'
		);
	}

	// -------------------------------------------------------------------------
	// 9.2b — SHORTPIXEL_TRUSTED_MODE
	// -------------------------------------------------------------------------

	/**
	 * When SHORTPIXEL_TRUSTED_MODE is true, EnvironmentModel::useTrustedMode()
	 * must return true.
	 *
	 * useTrustedMode() reads the constant at call-time (not in the constructor),
	 * so the existing singleton instance is safe to use once the constant is
	 * defined.
	 *
	 * Manual plan row: 9.2 (SHORTPIXEL_TRUSTED_MODE)
	 */
	public function test_trusted_mode_constant_enables_environment_trusted_mode() {
		if ( defined( 'SHORTPIXEL_TRUSTED_MODE' ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_TRUSTED_MODE is already defined in this process (e.g. by Pantheon shim); cannot redefine.' );
		}

		define( 'SHORTPIXEL_TRUSTED_MODE', true );

		$this->assertTrue(
			EnvironmentModel::getInstance()->useTrustedMode(),
			'When SHORTPIXEL_TRUSTED_MODE is true, EnvironmentModel::useTrustedMode() must return true. (plan 9.2)'
		);
	}

	// -------------------------------------------------------------------------
	// 9.2c — SHORTPIXEL_BACKUP_FOLDER (pre-defined by the harness)
	// -------------------------------------------------------------------------

	/**
	 * SHORTPIXEL_BACKUP_FOLDER is defined by wp-shortpixel.php from the
	 * uploads base directory at plugin load time. Asserts that the constant is
	 * present, is a non-empty string, and contains the uploads base path — i.e.
	 * that the bootstrap code that computes and define()s it actually ran.
	 *
	 * Manual plan row: 9.2 (SHORTPIXEL_BACKUP_FOLDER)
	 */
	public function test_backup_folder_constant_is_defined_and_path_based_on_uploads() {
		$this->assertTrue(
			defined( 'SHORTPIXEL_BACKUP_FOLDER' ),
			'SHORTPIXEL_BACKUP_FOLDER must be defined by the plugin bootstrap. (plan 9.2)'
		);

		$folder = SHORTPIXEL_BACKUP_FOLDER;

		$this->assertIsString(
			$folder,
			'SHORTPIXEL_BACKUP_FOLDER must be a string path.'
		);
		$this->assertNotEmpty(
			$folder,
			'SHORTPIXEL_BACKUP_FOLDER must not be an empty string.'
		);

		// The backup folder must be a subdirectory of the uploads base.
		$uploads = wp_get_upload_dir();
		$uploads_base = $uploads['basedir'];

		$this->assertStringContainsString(
			basename( $uploads_base ),
			$folder,
			'SHORTPIXEL_BACKUP_FOLDER must contain the uploads base directory name. (plan 9.2)'
		);
	}

	// -------------------------------------------------------------------------
	// 9.2d — SHORTPIXEL_API_KEY
	// -------------------------------------------------------------------------

	/**
	 * When SHORTPIXEL_API_KEY is defined (wp-config style), ApiKeyModel::loadKey()
	 * clears any DB-stored key and uses the constant value instead. is_constant()
	 * must report true and the key must match the constant.
	 *
	 * Moved here from test-SettingsAjaxSave.php: this test define()s
	 * SHORTPIXEL_API_KEY, so it may only run in this isolated-process suite.
	 *
	 * Manual plan rows: 9.2 (SHORTPIXEL_API_KEY) + 1.18 (wp-config key precedence)
	 */
	public function test_wp_config_defined_key_takes_precedence_over_settings() {
		if ( defined( 'SHORTPIXEL_API_KEY' ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_API_KEY is already defined in this process; cannot redefine.' );
		}

		// Without the constant: ApiKeyModel should report is_constant = false
		// and use the DB key (baseline: 20 a's from spioSetUpBaseline).
		$this->resetPluginSingletons();
		$keyModel = new ApiKeyModel();
		$keyModel->loadKey();

		$this->assertFalse( $keyModel->is_constant(), 'Without SHORTPIXEL_API_KEY constant, is_constant must be false' );
		$this->assertSame( str_repeat( 'a', 20 ), $keyModel->getKey(), 'DB key must be used when no constant is defined' );

		// Simulate the constant being defined by writing a different key to the DB.
		// Then define the constant (possible exactly once per test run).
		update_option( 'spio_key', array(
			'apiKey'      => str_repeat( 'b', 20 ),
			'verifiedKey' => true,
			'apiKeyTried' => '',
		) );

		define( 'SHORTPIXEL_API_KEY', str_repeat( 'c', 20 ) );

		$this->resetPluginSingletons();
		$keyModel2 = new ApiKeyModel();
		$keyModel2->loadKey();

		$this->assertTrue( $keyModel2->is_constant(), 'With SHORTPIXEL_API_KEY defined, is_constant must be true. (plan 9.2/1.18)' );
		$this->assertSame(
			str_repeat( 'c', 20 ),
			$keyModel2->getKey(),
			'Constant key must override DB key (plan 1.18)'
		);

		// The DB key must have been blanked (ApiKeyModel::loadKey() clears it when a constant is present).
		$stored = get_option( 'spio_key' );
		$this->assertSame( '', $stored['apiKey'] ?? 'not-cleared', 'loadKey() must clear the DB apiKey when using a constant' );
	}

	// -------------------------------------------------------------------------
	// 9.2e — SHORTPIXEL_HIDE_API_KEY
	// -------------------------------------------------------------------------

	/**
	 * When SHORTPIXEL_HIDE_API_KEY is true, ApiKeyModel::__construct() sets
	 * key_is_hidden to true and ApiKeyModel::is_hidden() returns true.
	 *
	 * Guards with markTestSkipped when the constant is already defined (e.g.
	 * when SHORTPIXEL_API_KEY was defined above in the same process run,
	 * which does not define this constant, but environment may).
	 *
	 * Manual plan row: 9.2 (SHORTPIXEL_HIDE_API_KEY)
	 */
	public function test_hide_api_key_constant_sets_is_hidden_flag_on_model() {
		if ( defined( 'SHORTPIXEL_HIDE_API_KEY' ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_HIDE_API_KEY is already defined in this process; cannot redefine.' );
		}

		define( 'SHORTPIXEL_HIDE_API_KEY', true );

		// Construct a fresh ApiKeyModel via the real constructor.
		$model = new ApiKeyModel();

		$this->assertTrue(
			$model->is_hidden(),
			'When SHORTPIXEL_HIDE_API_KEY is true, ApiKeyModel::is_hidden() must return true. (plan 9.2)'
		);
	}
}
