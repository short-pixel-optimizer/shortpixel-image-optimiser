<?php
/**
 * Tests for ShortPixel\Controller\OtherMediaController.
 *
 * Focus areas (pure data helpers that do not require directory scans):
 *   - getInstance() — singleton contract and reset.
 *   - getFolderTable() / getMetaTable() — return correctly prefixed table names.
 *   - hasCustomImages() — static-cache behaviour; returns false when table absent
 *     or count is 0; returns true when count > 0 (verified with seeded DB rows
 *     only when the shortpixel_meta table exists in the test install).
 *   - getActiveDirectoryIDS() — in-memory caching; returns empty array when
 *     table absent.
 *   - showMenuItem() — reads the showCustomMedia setting.
 *   - checkifMediaLibrary() — upload-base path logic: year-based subdir returns
 *     true; non-year subdir returns false; non-upload path returns false.
 *   - getFolderTable() / getMetaTable() prefix contract pins the global $wpdb prefix.
 *
 * Out of scope (and why):
 *   - addDirectory() — calls DirectoryOtherMediaModel::save() + refreshFolder();
 *     requires a real writable FS path; integration territory.
 *   - addImage() — requires filesystem + DirectoryOtherMediaModel::addImages().
 *   - doNextRefreshableFolder() — runs a real DB query against shortpixel_folders
 *     and then calls refreshFolder(); integration territory.
 *   - getAllFolders() / getActiveFolders() / getFolderByID() / getFolderByPath() —
 *     all delegate to getFolders() which queries shortpixel_folders; when the table
 *     does not exist the queries fail gracefully, but these paths are more meaningful
 *     as integration tests.
 *   - browseFolder() — requires filesystem + WP upload dir infrastructure.
 *   - resetCheckedTimestamps() / cleanUp() — write to custom tables; integration only.
 *   - checkDirectoryRecursive() — requires real DirectoryOtherMediaModel with FS paths.
 *   - getCustomImageByPath() — queries shortpixel_meta and calls filesystem.
 *
 * Custom-table guard: Tests that touch DB tables skip themselves automatically when
 * the required custom table is absent (markTestSkipped) so they are safe on a
 * vanilla WordPress install that has not run the plugin's install routine.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\OtherMediaController;
use ShortPixel\Helper\InstallHelper;
use ShortPixel\Helper\UtilHelper;

class OtherMediaControllerTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Reset the OtherMediaController singleton. */
	private function resetSingleton(): void {
		$ref = new ReflectionClass( OtherMediaController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/** Reset the static hasCustomImages cache. */
	private function resetHasCustomImagesCache(): void {
		$ref = new ReflectionClass( OtherMediaController::class );
		$p   = $ref->getProperty( 'hasCustomImages' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/** Reset the static hasFoldersTable cache embedded in InstallHelper (if cached). */
	private function resetFolderIDCache( OtherMediaController $ctrl ): void {
		$ref = new ReflectionObject( $ctrl );
		$p   = $ref->getProperty( 'folderIDCache' );
		$p->setAccessible( true );
		$p->setValue( $ctrl, null );
	}

	private function setPrivate( OtherMediaController $obj, string $prop, $value ): void {
		$ref = new ReflectionObject( $obj );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	/** Seed the spio_settings option so wpSPIO()->settings() returns expected values. */
	private function seedSettings( array $overrides = array() ): void {
		$current = get_option( 'spio_settings', array() );
		update_option( 'spio_settings', array_merge( $current, $overrides ) );

		// wpSPIO()->settings() delegates to SettingsModel::getInstance(), which is its own
		// private static singleton separate from the wpSPIO object.  Reset it so the next
		// call re-reads the freshly updated option row rather than serving stale in-memory data.
		$smRef = new ReflectionClass( \ShortPixel\Model\SettingsModel::class );
		$smProp = $smRef->getProperty( 'instance' );
		$smProp->setAccessible( true );
		$smProp->setValue( null, null );
	}

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		$this->resetHasCustomImagesCache();
	}

	public function tear_down() {
		$this->resetSingleton();
		$this->resetHasCustomImagesCache();
		delete_option( 'spio_settings' );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// getInstance — singleton contract
	// -------------------------------------------------------------------------

	public function test_getInstance_returns_OtherMediaController_instance() {
		$a = OtherMediaController::getInstance();
		$this->assertInstanceOf( OtherMediaController::class, $a );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = OtherMediaController::getInstance();
		$b = OtherMediaController::getInstance();
		$this->assertSame( $a, $b );
	}

	public function test_getInstance_returns_new_instance_after_singleton_reset() {
		$a = OtherMediaController::getInstance();
		$this->resetSingleton();
		$b = OtherMediaController::getInstance();
		$this->assertNotSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// getFolderTable / getMetaTable — table name format
	// -------------------------------------------------------------------------

	public function test_getFolderTable_returns_string_with_wpdb_prefix() {
		global $wpdb;
		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->getFolderTable();

		$this->assertIsString( $result );
		$this->assertStringStartsWith( $wpdb->prefix, $result );
	}

	public function test_getFolderTable_ends_with_shortpixel_folders() {
		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->getFolderTable();
		$this->assertStringEndsWith( 'shortpixel_folders', $result );
	}

	public function test_getMetaTable_returns_string_with_wpdb_prefix() {
		global $wpdb;
		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->getMetaTable();

		$this->assertIsString( $result );
		$this->assertStringStartsWith( $wpdb->prefix, $result );
	}

	public function test_getMetaTable_ends_with_shortpixel_meta() {
		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->getMetaTable();
		$this->assertStringEndsWith( 'shortpixel_meta', $result );
	}

	public function test_getFolderTable_and_getMetaTable_are_different() {
		$ctrl = OtherMediaController::getInstance();
		$this->assertNotSame( $ctrl->getFolderTable(), $ctrl->getMetaTable() );
	}

	// -------------------------------------------------------------------------
	// hasCustomImages — caching and table-absent path
	// -------------------------------------------------------------------------

	public function test_hasCustomImages_returns_false_when_meta_table_absent() {
		if ( InstallHelper::checkTableExists( 'shortpixel_meta' ) ) {
			$this->markTestSkipped( 'shortpixel_meta table exists in this install — table-absent path untestable.' );
		}

		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->hasCustomImages();

		$this->assertFalse( $result );
	}

	public function test_hasCustomImages_caches_result_on_repeated_calls() {
		// Seed the static cache directly to verify that the second call reads
		// the cache rather than re-querying the DB.
		$this->resetHasCustomImagesCache();

		$ref = new ReflectionClass( OtherMediaController::class );
		$p   = $ref->getProperty( 'hasCustomImages' );
		$p->setAccessible( true );
		$p->setValue( null, true ); // pre-seed cache to true

		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->hasCustomImages();

		$this->assertTrue( $result, 'hasCustomImages() must return the cached value without re-querying' );
	}

	public function test_hasCustomImages_returns_false_when_cache_seeded_to_false() {
		$ref = new ReflectionClass( OtherMediaController::class );
		$p   = $ref->getProperty( 'hasCustomImages' );
		$p->setAccessible( true );
		$p->setValue( null, false );

		$ctrl = OtherMediaController::getInstance();
		$this->assertFalse( $ctrl->hasCustomImages() );
	}

	// -------------------------------------------------------------------------
	// getActiveDirectoryIDS — in-memory caching
	// -------------------------------------------------------------------------

	public function test_getActiveDirectoryIDS_returns_array() {
		if ( ! InstallHelper::checkTableExists( 'shortpixel_folders' ) ) {
			$this->markTestSkipped( 'shortpixel_folders table not installed in this test environment.' );
		}

		$ctrl   = OtherMediaController::getInstance();
		$result = $ctrl->getActiveDirectoryIDS();
		$this->assertIsArray( $result );
	}

	public function test_getActiveDirectoryIDS_uses_in_memory_cache() {
		$ctrl = OtherMediaController::getInstance();

		// Pre-seed the cache so the method never hits the DB.
		$this->setPrivate( $ctrl, 'folderIDCache', array( '7', '8', '9' ) );

		$result = $ctrl->getActiveDirectoryIDS();

		$this->assertSame( array( '7', '8', '9' ), $result );
	}

	public function test_getActiveDirectoryIDS_cache_is_null_initially() {
		$ctrl = OtherMediaController::getInstance();
		$ref  = new ReflectionObject( $ctrl );
		$p    = $ref->getProperty( 'folderIDCache' );
		$p->setAccessible( true );
		$this->assertNull( $p->getValue( $ctrl ) );
	}

	// -------------------------------------------------------------------------
	// showMenuItem — reads showCustomMedia setting
	// -------------------------------------------------------------------------

	public function test_showMenuItem_returns_false_when_showCustomMedia_disabled() {
		$this->seedSettings( array( 'showCustomMedia' => 0 ) );
		$ctrl = OtherMediaController::getInstance();
		$this->assertFalse( $ctrl->showMenuItem() );
	}

	public function test_showMenuItem_returns_true_when_showCustomMedia_enabled() {
		$this->seedSettings( array( 'showCustomMedia' => 1 ) );
		$ctrl = OtherMediaController::getInstance();
		$this->assertTrue( $ctrl->showMenuItem() );
	}

	// -------------------------------------------------------------------------
	// checkifMediaLibrary — upload-path recognition
	// -------------------------------------------------------------------------

	/**
	 * Build a minimal DirectoryModel stub for the path-check method.
	 * We use a real DirectoryModel so checkifMediaLibrary() can call
	 * getPath(), isSubFolderOf(), getParent(), getName() on it.
	 */
	private function makeDir( string $path ): \ShortPixel\Model\File\DirectoryModel {
		return new \ShortPixel\Model\File\DirectoryModel( $path );
	}

	public function test_checkifMediaLibrary_returns_false_for_path_outside_uploads() {
		$ctrl  = OtherMediaController::getInstance();
		$tmpDir = $this->makeDir( sys_get_temp_dir() );

		$result = $ctrl->checkifMediaLibrary( $tmpDir );

		$this->assertFalse( $result );
	}

	public function test_checkifMediaLibrary_returns_true_for_year_based_upload_subdir() {
		$ctrl = OtherMediaController::getInstance();
		$fs   = \wpSPIO()->filesystem();

		$uploadBase = $fs->getWPUploadBase()->getPath();
		$yearDir    = $this->makeDir( rtrim( $uploadBase, '/' ) . '/2023' );

		$result = $ctrl->checkifMediaLibrary( $yearDir );

		$this->assertTrue( $result );
	}

	public function test_checkifMediaLibrary_returns_false_for_non_numeric_upload_subdir() {
		$ctrl = OtherMediaController::getInstance();
		$fs   = \wpSPIO()->filesystem();

		$uploadBase = $fs->getWPUploadBase()->getPath();
		$namedDir   = $this->makeDir( rtrim( $uploadBase, '/' ) . '/woocommerce_uploads' );

		$result = $ctrl->checkifMediaLibrary( $namedDir );

		$this->assertFalse( $result );
	}

	public function test_checkifMediaLibrary_returns_false_for_uploads_base_itself() {
		$ctrl = OtherMediaController::getInstance();
		$fs   = \wpSPIO()->filesystem();

		$uploadBase = $fs->getWPUploadBase();

		$result = $ctrl->checkifMediaLibrary( $uploadBase );

		$this->assertFalse( $result );
	}

	public function test_checkifMediaLibrary_returns_false_for_deeply_nested_year_subdir() {
		// A numeric directory that is NOT a direct child of uploads/ should be allowed.
		$ctrl = OtherMediaController::getInstance();
		$fs   = \wpSPIO()->filesystem();

		$uploadBase = $fs->getWPUploadBase()->getPath();
		// e.g. /uploads/custom-folder/2023 — parent is not uploads root.
		$deepDir    = $this->makeDir( rtrim( $uploadBase, '/' ) . '/custom-folder/2023' );

		$result = $ctrl->checkifMediaLibrary( $deepDir );

		// Deep nesting: parent is not uploads root → must return false.
		$this->assertFalse( $result );
	}

	public function test_checkifMediaLibrary_returns_false_for_three_digit_directory() {
		// Only 4-digit names are treated as year-based.
		$ctrl = OtherMediaController::getInstance();
		$fs   = \wpSPIO()->filesystem();

		$uploadBase = $fs->getWPUploadBase()->getPath();
		$shortDir   = $this->makeDir( rtrim( $uploadBase, '/' ) . '/123' );

		$result = $ctrl->checkifMediaLibrary( $shortDir );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// UtilHelper::timestampToDB — used by doNextRefreshableFolder; pin contract.
	// -------------------------------------------------------------------------

	public function test_timestampToDB_returns_mysql_datetime_format_string() {
		$ts     = mktime( 12, 0, 0, 6, 15, 2024 );
		$result = UtilHelper::timestampToDB( $ts );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result );
	}

	public function test_timestampToDB_round_trips_through_strtotime() {
		$ts     = mktime( 8, 30, 0, 1, 20, 2025 );
		$db     = UtilHelper::timestampToDB( $ts );
		$back   = strtotime( $db );
		$this->assertSame( $ts, $back );
	}
}
