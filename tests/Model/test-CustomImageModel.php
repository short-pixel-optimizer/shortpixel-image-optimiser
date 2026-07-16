<?php
/**
 * Tests for ShortPixel\Model\Image\CustomImageModel (concrete subclass of ImageModel).
 *
 * SESSION 1 (done) — construction + abstract implementations + meta
 * accessors + prevented-state trio + improvement shape.
 *
 * SESSION 2 (done) — state-machine overrides + pipeline entry points
 * + specifics:
 *   - handleOptimized (2 regression sentinels guarding the $files-undefined
 *     early-exit fix)
 *   - isDateExcluded (1 regression sentinel guarding the $options-not-array
 *     guard, plus before/after rule branches via subclass override)
 *   - setStub (existing DB row vs no matching row)
 *   - getWebps / getAvifs (empty and companion-exists shapes)
 *   - getOptimizeFileType (pdf branch, no companion, has companion)
 *   - dropFromQueue (smoke — no crash)
 *   - onDelete (delegates to backupModel via stub)
 *
 * NOT covered here (deferred to integration tests):
 *   - isProcessable full flow (needs real exclusion patterns via UtilHelper)
 *   - isRestorable override (needs BackupModel wiring)
 *   - restore full flow (BackupModel + filesystem interplay)
 *   - handleOptimized happy path (parent::handleOptimized + saveMeta DB write)
 *   - saveMeta INSERT/UPDATE (requires shortpixel_meta table + full fixture)
 *
 * CustomImageModel is a concrete subclass, so no abstract-method stubs
 * are needed at the fixture layer. Construction goes through
 * `new CustomImageModel($id)`. Passing `$id = 0` builds an unpopulated
 * stub without touching the database.
 *
 * Fixture files: minimal valid 1×1 PNG files written to sys_get_temp_dir()
 * for tests that need real file state (getURL, getWebp, etc.). Cleaned
 * up in tear_down.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\CustomImageModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Helper\InstallHelper;

class CustomImageModelTest extends WP_UnitTestCase {

	/** @var string[] Absolute paths of fixture files created during tests. */
	private $fixtureFiles = array();

	public function set_up() {
		parent::set_up();

		// Ensure SPIO's own tables (shortpixel_meta / shortpixel_folders)
		// exist. In the WP test harness the plugin loads via
		// _manually_load_plugin() but activation hooks — where
		// InstallHelper::activatePlugin normally creates these tables —
		// don't fire. Same pattern used in test-DirectoryOtherMediaModel.
		InstallHelper::checkTables();

		// Clean state so per-test inserts don't collide with prior runs.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_meta' );
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_meta' );

		foreach ( $this->fixtureFiles as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		$this->fixtureFiles = array();
		parent::tear_down();
	}

	/*
	 * Reflection helpers — walk the inheritance chain (CustomImageModel
	 * → ImageModel → FileModel) so we can access properties/methods
	 * declared anywhere in the hierarchy.
	 */

	private function getProtected( object $obj, string $prop ) {
		$ref = new ReflectionClass( $obj );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	private function setProtected( object $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( $obj );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	private function invokeProtected( object $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( $obj );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	/*
	 * Fixture builders
	 */

	private function makeImageFile( string $extension = 'png' ): string {
		$path = sys_get_temp_dir() . '/spio-custom-imagemodel-' . uniqid() . '.' . $extension;
		file_put_contents(
			$path,
			base64_decode(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=='
			)
		);
		$this->fixtureFiles[] = $path;
		return $path;
	}

	/**
	 * Build a stub CustomImageModel (id=0 → skips DB load, creates empty
	 * ImageMeta). Optionally pre-set a real fullpath via reflection so
	 * filesystem-dependent methods (getURL etc.) have coherent state.
	 *
	 * Seeds tsAdded/tsOptimized so `saveMeta()` doesn't fatal. Since
	 * commit 399b29e2 the class declares `strict_types=1`, which turns
	 * the previous `Deprecated: DateTime::setTimestamp() passing null`
	 * warning into a hard TypeError inside `saveMeta()`. ImageThumbnailMeta's
	 * constructor seeds tsAdded to `time()`, but tsOptimized stays null —
	 * force both to a safe integer so any test-driven path through
	 * saveMeta() survives.
	 */
	private function makeStubModel( ?string $path = null ): CustomImageModel {
		$model = new CustomImageModel( 0 );
		$model->setMeta( 'tsAdded', time() );
		$model->setMeta( 'tsOptimized', 0 );
		if ( $path !== null ) {
			$this->setProtected( $model, 'fullpath', $path );
		}
		return $model;
	}

	/**
	 * Minimal stub backupModel with configurable hasBackup return.
	 * Used to bypass BackupController wiring for resetPrevent tests.
	 */
	private function makeStubBackupModel( bool $hasBackup = false ) {
		return new class( $hasBackup ) {
			public $hasBackup;
			public function __construct( $has ) { $this->hasBackup = $has; }
			public function hasBackup( $model, $any = false ) { return $this->hasBackup; }
			public function onDelete( $model ) {}
		};
	}

	/*
	 * __construct — stub path (id <= 0) skips DB load entirely.
	 */

	public function test_constructor_with_id_zero_creates_a_stub_with_empty_ImageMeta() {
		$model = new CustomImageModel( 0 );

		$this->assertTrue( $this->getProtected( $model, 'is_stub' ) );
		$this->assertInstanceOf( ImageMeta::class, $this->getProtected( $model, 'image_meta' ) );
		$this->assertSame( '', $model->getFullPath() );
		// in_db defaults false; stub construction doesn't touch it.
		$this->assertFalse( $this->getProtected( $model, 'in_db' ) );
	}

	public function test_constructor_stores_the_id_on_the_instance() {
		$model = new CustomImageModel( 0 );
		$this->assertSame( 0, $model->get( 'id' ) );

		// Sentinel with a distinct positive value — but we use 0 for the
		// stub-only path to avoid the loadMeta DB query firing here.
		$model2 = new CustomImageModel( 42 );
		$this->assertSame( 42, $model2->get( 'id' ) );
	}

	public function test_constructor_with_positive_id_leaves_in_db_false_when_no_matching_row() {
		// Use a large-enough id that no real row exists (WP_UnitTestCase
		// rolls back per-test, so the table is empty at test start).
		$model = new CustomImageModel( 999999999 );

		$this->assertFalse( $this->getProtected( $model, 'in_db' ) );
	}

	/*
	 * setFolderId — trivial setter.
	 */

	public function test_setFolderId_stores_the_folder_id_on_the_instance() {
		$model = $this->makeStubModel();
		$model->setFolderId( 42 );

		$this->assertSame( 42, $this->getProtected( $model, 'folder_id' ) );
	}

	/*
	 * isScaled — custom images are never WordPress-scaled.
	 */

	public function test_isScaled_always_returns_false() {
		$model = $this->makeStubModel();

		// Sentinel: strict `assertSame( false, ... )` — a regression
		// that returned null or 0 would pass assertFalse but not this.
		$this->assertSame( false, $model->isScaled() );
	}

	/*
	 * doSetting — records overrides in $forceSettings.
	 */

	public function test_doSetting_stores_the_key_value_pair_in_forceSettings() {
		$model = $this->makeStubModel();

		$model->doSetting( 'smartcrop', ImageModel::ACTION_SMARTCROP );

		$force = $this->getProtected( $model, 'forceSettings' );
		$this->assertArrayHasKey( 'smartcrop', $force );
		$this->assertSame( ImageModel::ACTION_SMARTCROP, $force['smartcrop'] );
	}

	public function test_doSetting_accepts_multiple_settings_and_preserves_prior_entries() {
		$model = $this->makeStubModel();

		$model->doSetting( 'smartcrop', ImageModel::ACTION_SMARTCROP );
		$model->doSetting( 'compressionType', ImageModel::COMPRESSION_GLOSSY );

		$force = $this->getProtected( $model, 'forceSettings' );
		// Sentinel: verify BOTH entries survive. A regression that
		// overwrote instead of merged would drop the earlier one.
		$this->assertSame( ImageModel::ACTION_SMARTCROP, $force['smartcrop'] );
		$this->assertSame( ImageModel::COMPRESSION_GLOSSY, $force['compressionType'] );
	}

	/*
	 * getAllUrls — always a single-element array wrapping getURL().
	 */

	public function test_getAllUrls_returns_single_element_array_wrapping_getURL() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$result = $model->getAllUrls();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( $model->getURL(), $result[0] );
	}

	/*
	 * count — per-type counter. Custom images have no thumbnails or
	 * retinas; webp/avif reflect companion existence.
	 */

	public function test_count_returns_zero_for_thumbnails_always() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$this->assertSame( 0, $model->count( 'thumbnails' ) );
	}

	public function test_count_returns_zero_for_webps_when_no_companion_file_exists() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$this->assertSame( 0, $model->count( 'webps' ) );
	}

	public function test_count_returns_zero_for_avifs_when_no_companion_file_exists() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$this->assertSame( 0, $model->count( 'avifs' ) );
	}

	public function test_count_returns_zero_for_unknown_type_via_default_case() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		// Sentinel: covers the `default: $count = 0;` branch. A regression
		// that removed the default (falling off the end with an undefined
		// variable) would throw under convertNoticesToExceptions=true.
		$this->assertSame( 0, $model->count( 'not_a_known_type' ) );
	}

	/*
	 * getURL — delegates to filesystem->pathToUrl. We test that a
	 * non-empty string comes back, not the specific URL shape (which
	 * depends on WordPress test env config).
	 */

	public function test_getURL_returns_a_string_for_a_real_fixture_file() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$result = $model->getURL();

		$this->assertIsString( $result );
	}

	/*
	 * isSomethingOptimized / getSomethingOptimized — mirror
	 * isOptimized for custom images (no thumbnails to consider).
	 */

	public function test_isSomethingOptimized_returns_false_for_unoptimized_image() {
		$model = $this->makeStubModel();
		// Default status is unpopulated on stub ImageMeta.
		$this->assertFalse( $model->isSomethingOptimized() );
	}

	public function test_isSomethingOptimized_returns_true_after_setMeta_status_SUCCESS() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$this->assertTrue( $model->isSomethingOptimized() );
	}

	public function test_getSomethingOptimized_returns_self_when_optimized() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		// Sentinel: identity assertion (assertSame). A regression that
		// returned a fresh clone would still be truthy but fail this.
		$this->assertSame( $model, $model->getSomethingOptimized() );
	}

	public function test_getSomethingOptimized_returns_false_when_not_optimized() {
		$model = $this->makeStubModel();

		$this->assertSame( false, $model->getSomethingOptimized() );
	}

	/*
	 * isOptimizePrevented — reads status meta; returns errorMessage for
	 * PREVENT / MARKED_DONE codes, else false. Also flips
	 * processable_status to P_OPTIMIZE_PREVENTED as a side-effect.
	 */

	public function test_isOptimizePrevented_returns_errorMessage_when_status_is_FILE_STATUS_PREVENT() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_PREVENT );
		$model->setMeta( 'errorMessage', 'API-side fatal on last attempt' );

		$result = $model->isOptimizePrevented();

		$this->assertSame( 'API-side fatal on last attempt', $result );
		// Sentinel: pins the side-effect. Regression that returned the
		// reason without setting the status cache would leave later
		// isProcessable/getReason calls reporting stale info.
		$this->assertSame(
			ImageModel::P_OPTIMIZE_PREVENTED,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isOptimizePrevented_returns_errorMessage_when_status_is_FILE_STATUS_MARKED_DONE() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_MARKED_DONE );
		$model->setMeta( 'errorMessage', 'Manually marked as done by operator' );

		// Sentinel-pair with the PREVENT test: BOTH codes route through
		// the same isOptimizePrevented branch. A regression that only
		// handled PREVENT would slip past this.
		$this->assertSame( 'Manually marked as done by operator', $model->isOptimizePrevented() );
	}

	public function test_isOptimizePrevented_returns_false_for_a_normal_status() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_UNPROCESSED );

		$this->assertSame( false, $model->isOptimizePrevented() );
	}

	/*
	 * preventNextTry — writes reason + status to meta, then persists.
	 * Signature: preventNextTry($reason = '', $status = FILE_STATUS_PREVENT).
	 */

	public function test_preventNextTry_writes_errorMessage_and_status_to_image_meta() {
		$model = $this->makeStubModel();
		// Force in_db=true so saveMeta hits the UPDATE branch (which
		// harmlessly matches zero rows in test env) instead of INSERT.
		$this->setProtected( $model, 'in_db', true );

		$this->invokeProtected( $model, 'preventNextTry', array( 'test reason' ) );

		$this->assertSame( 'test reason', $model->getMeta( 'errorMessage' ) );
		$this->assertSame(
			ImageModel::FILE_STATUS_PREVENT,
			$model->getMeta( 'status' )
		);
	}

	public function test_preventNextTry_honours_custom_status_argument() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'in_db', true );

		$this->invokeProtected(
			$model,
			'preventNextTry',
			array( 'done', ImageModel::FILE_STATUS_MARKED_DONE )
		);

		// Sentinel: the second-arg default is FILE_STATUS_PREVENT (-10);
		// this test proves the caller can override to MARKED_DONE (-11).
		$this->assertSame(
			ImageModel::FILE_STATUS_MARKED_DONE,
			$model->getMeta( 'status' )
		);
	}

	/*
	 * markCompleted — thin wrapper around preventNextTry.
	 */

	public function test_markCompleted_delegates_to_preventNextTry_with_the_passed_status() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'in_db', true );

		$model->markCompleted( 'user-marked complete', ImageModel::FILE_STATUS_MARKED_DONE );

		$this->assertSame( 'user-marked complete', $model->getMeta( 'errorMessage' ) );
		$this->assertSame(
			ImageModel::FILE_STATUS_MARKED_DONE,
			$model->getMeta( 'status' )
		);
	}

	/*
	 * getParent — custom images have no parent hierarchy.
	 */

	public function test_getParent_always_returns_false() {
		$model = $this->makeStubModel();

		$this->assertSame( false, $model->getParent() );
	}

	/*
	 * resetPrevent — clears the prevent flag. Status transitions to
	 * SUCCESS when a backup exists (file was previously optimized) or
	 * UNPROCESSED otherwise.
	 */

	public function test_resetPrevent_sets_status_UNPROCESSED_and_clears_errorMessage_when_no_backup() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'in_db', true );
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel( false ) );

		// Pre-seed prevented state so the transition is observable.
		$model->setMeta( 'status', ImageModel::FILE_STATUS_PREVENT );
		$model->setMeta( 'errorMessage', 'was prevented' );

		$model->resetPrevent();

		$this->assertSame( ImageModel::FILE_STATUS_UNPROCESSED, $model->getMeta( 'status' ) );
		$this->assertSame( '', $model->getMeta( 'errorMessage' ) );
	}

	public function test_resetPrevent_sets_status_SUCCESS_when_a_backup_still_exists_on_disk() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'in_db', true );
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel( true ) );

		$model->setMeta( 'status', ImageModel::FILE_STATUS_PREVENT );

		$model->resetPrevent();

		// Sentinel-pair with the no-backup test: SAME reset action,
		// DIFFERENT terminal status based on backup presence. Regression
		// that hardcoded either status would fail one of the pair.
		$this->assertSame( ImageModel::FILE_STATUS_SUCCESS, $model->getMeta( 'status' ) );
	}

	/*
	 * getImprovement — returns customImprovement meta directly.
	 * The $int arg is documented as ignored (compat shim).
	 */

	public function test_getImprovement_returns_customImprovement_meta_value() {
		$model = $this->makeStubModel();
		$model->setMeta( 'customImprovement', 42.5 );

		$this->assertSame( 42.5, $model->getImprovement() );
	}

	public function test_getImprovement_ignores_int_argument_as_documented_compat_shim() {
		$model = $this->makeStubModel();
		$model->setMeta( 'customImprovement', 42.5 );

		// Sentinel: the docblock explicitly says $int is accepted for
		// signature compatibility but ignored. A regression that started
		// honoring $int (e.g. by returning byte savings) would return
		// something other than 42.5 here.
		$this->assertSame( 42.5, $model->getImprovement( true ) );
		$this->assertSame( 42.5, $model->getImprovement( false ) );
	}

	public function test_getImprovement_returns_null_when_customImprovement_meta_is_unset() {
		$model = $this->makeStubModel();
		// customImprovement is null on a fresh ImageMeta.

		$this->assertNull( $model->getImprovement() );
	}

	/*
	 * getImprovements — MediaLibraryModel-shape payload with a single
	 * `main` entry (custom images have no thumbnails).
	 */

	public function test_getImprovements_returns_main_entry_and_totalpercentage_with_the_improvement_value() {
		$model = $this->makeStubModel();
		$model->setMeta( 'customImprovement', 25 );

		$result = $model->getImprovements();

		// Shape sentinel: keys must be `main` (tuple) + `totalpercentage`.
		$this->assertArrayHasKey( 'main', $result );
		$this->assertArrayHasKey( 'totalpercentage', $result );
		// main[0] is the raw improvement value stored on customImprovement
		// (25 as passed in); main[1] is always 0 for custom images.
		$this->assertSame( array( 25, 0 ), $result['main'] );
		// totalpercentage runs through round() which returns FLOAT in PHP,
		// so the strict assertion needs 25.0 not 25.
		$this->assertSame( 25.0, $result['totalpercentage'] );
	}

	public function test_getImprovements_coerces_null_improvement_to_zero_in_the_payload() {
		$model = $this->makeStubModel();
		// customImprovement is null on a fresh ImageMeta.

		$result = $model->getImprovements();

		// Sentinel: null → 0 fallback at line 1066. A regression that
		// dropped the null-guard would surface null in the payload,
		// which downstream consumers likely don't handle.
		$this->assertSame( array( 0, 0 ), $result['main'] );
		// round(0) returns float 0.0 — same coercion note as the
		// non-null test above.
		$this->assertSame( 0.0, $result['totalpercentage'] );
	}

	/*
	 * getOptimizeUrls — delegates to getOptimizeData()['urls'] and
	 * returns array_values.
	 *
	 * For an isProcessable=false stub (no valid backing file), the
	 * urls array stays empty — array_values on an empty array returns
	 * an empty array.
	 */

	public function test_getOptimizeUrls_returns_empty_array_when_optimize_data_has_no_urls() {
		$model = $this->makeStubModel();
		// No file → isProcessable false → getOptimizeData['urls'] stays empty.

		$result = $model->getOptimizeUrls();

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	// =============================================================
	// SESSION 2 — state-machine overrides + pipelines + specifics
	// =============================================================

	/**
	 * Build a CustomImageModel subclass that lets tests inject a canned
	 * checkDateExcluded() return. Used by isDateExcluded before/after
	 * tests to avoid seeding real exclusion rules via UtilHelper.
	 */
	private function makeModelWithDateOptions( $dateOptions ): CustomImageModel {
		$model = new class( 0 ) extends CustomImageModel {
			public $_testDateOptions = false;
			protected function checkDateExcluded() {
				return $this->_testDateOptions;
			}
		};
		$model->_testDateOptions = $dateOptions;
		return $model;
	}

	/*
	 * handleOptimized — regression sentinels for the $files-undefined
	 * early-exit at line 506 / 509.
	 *
	 * When `$optimizeData` is missing the `files` OR `data` key, the
	 * `if (isset(...) && isset(...))` guard used to skip assignment to
	 * $files — while the code below UNCONDITIONALLY dereferenced
	 * $files[0] on two lines, throwing "Undefined variable $files"
	 * under convertNoticesToExceptions=true. The fix adds an early
	 * `return false;` inside the else branch so callers get a clean
	 * false back instead of a fatal.
	 *
	 * These tests guard against re-introduction of the crash.
	 */

	/**
	 * Regression sentinel: handleOptimized must return false (not fatal)
	 * when the payload lacks the `files` key. Before the fix, the guarded
	 * $files assignment was skipped but the code below still dereferenced
	 * $files[0], throwing "Undefined variable $files".
	 */
	public function test_handleOptimized_does_not_crash_when_optimizeData_lacks_files_key() {
		$model = $this->makeStubModel();
		// Payload lacks 'files' → guard at line 493 fails → $files undefined.
		$payload = array( 'data' => array( 'foo' => 'bar' ) );

		// The buggy code path walks through createBackup → preventNextTry →
		// saveMeta, which fires an INSERT on an unpopulated model row
		// (folder_id NULL, empty path/name). Suppress wpdb's error dump so
		// the console output stays readable — the test's assertion is what
		// matters, not the noise.
		[ $model, $result, $threw, $msg ] = $this->runWithSuppressedDbErrors( function () use ( $model, $payload ) {
			return @$model->handleOptimized( $payload );
		}, $model );

		if ( $threw ) {
			$this->fail( 'handleOptimized threw on missing files key — regression of the $files-undefined bug at CustomImageModel.php:506,509. The guard should short-circuit with `return false;` before dereferencing $files. Message: ' . $msg );
		}
		// Sentinel: expected to return false (early exit).
		$this->assertSame(
			false,
			$result,
			'handleOptimized should return false when files key is missing, not proceed to dereference undefined $files.'
		);
	}

	/**
	 * Regression sentinel: handleOptimized must return false (not fatal)
	 * when the payload has `files` but lacks `data`. The AND-guard around
	 * the $files assignment short-circuits on the missing `data` key, so
	 * without the early-return fix the code below still tried to
	 * dereference undefined $files.
	 */
	public function test_handleOptimized_does_not_crash_when_optimizeData_lacks_data_key() {
		$model = $this->makeStubModel();
		// Payload lacks 'data' → same guard fails (AND, not OR) → $files
		// still undefined even though 'files' key is present.
		$payload = array( 'files' => array( array( 'image' => array() ) ) );

		[ $model, $result, $threw, $msg ] = $this->runWithSuppressedDbErrors( function () use ( $model, $payload ) {
			return @$model->handleOptimized( $payload );
		}, $model );

		if ( $threw ) {
			$this->fail( 'handleOptimized threw on missing data key — regression of the AND-guard short-circuit bug at CustomImageModel.php:506,509. Message: ' . $msg );
		}
		$this->assertSame( false, $result );
	}

	/**
	 * Runs a closure with wpdb error output suppressed AND the resulting
	 * echoed error HTML captured & discarded via output buffering.
	 *
	 * `$wpdb->hide_errors()` covers the plain-text branch of `print_error()`;
	 * the HTML branch (`<div id="error">…</div>`) is still echoed directly.
	 * Wrapping in ob_start()/ob_end_clean() drops that too.
	 *
	 * Returns [ $model, $result, $threw, $msg ] — $model is passed through
	 * unchanged for convenience in destructuring the caller's fixture.
	 */
	private function runWithSuppressedDbErrors( callable $fn, $model ): array {
		global $wpdb;
		$prevSuppress = $wpdb->suppress_errors( true );
		$prevShow     = $wpdb->hide_errors();
		ob_start();
		$threw  = false;
		$msg    = '';
		$result = null;
		try {
			$result = $fn();
		} catch ( \Throwable $t ) {
			$threw = true;
			$msg   = $t->getMessage();
		} finally {
			ob_end_clean();
			$wpdb->suppress_errors( $prevSuppress );
			if ( $prevShow ) {
				$wpdb->show_errors();
			}
		}
		return array( $model, $result, $threw, $msg );
	}

	/*
	 * isDateExcluded — pinned regression + before/after rule branches.
	 */

	/**
	 * Regression sentinel: isDateExcluded must return false safely when
	 * no date rule is configured, even when called directly (bypassing
	 * isProcessable's outer `false !== checkDateExcluded()` guard).
	 *
	 * Before the fix, the method dereferenced `$options['date']` without
	 * checking that checkDateExcluded() had returned an array — it
	 * returns false when no date rule matches, so direct callers hit an
	 * unguarded dereference. The fix adds a `false === $options` guard
	 * at the top of isDateExcluded, mirroring the pattern the parent's
	 * checkDateExcluded already establishes.
	 */
	public function test_isDateExcluded_does_not_crash_when_no_date_rule_configured() {
		$model = $this->makeStubModel();
		// Seed tsAdded so DateTime setTimestamp() doesn't complain about null.
		$model->setMeta( 'tsAdded', time() );
		$model->setMeta( 'tsOptimized', 0 );

		try {
			$result = $this->invokeProtected( $model, 'isDateExcluded' );
			$this->assertSame(
				false,
				$result,
				'isDateExcluded should return false safely when no date rule exists.'
			);
		} catch ( \Throwable $t ) {
			$this->fail(
				'isDateExcluded threw on no-date-rule state — regression of the unguarded $options["date"] dereference at CustomImageModel.php:752. Message: ' . $t->getMessage()
			);
		}
	}

	public function test_isDateExcluded_returns_true_for_before_rule_when_item_date_precedes_rule_date() {
		// Item added in 2020, rule says "before 2024-01-01" → excluded.
		$model = $this->makeModelWithDateOptions(
			array( 'date' => '2024-01-01', 'when' => 'before' )
		);
		$model->setMeta( 'tsAdded', strtotime( '2020-06-15' ) );
		$model->setMeta( 'tsOptimized', 0 );

		$result = $this->invokeProtected( $model, 'isDateExcluded' );

		$this->assertTrue( $result );
		// Sentinel: pins the P_EXCLUDE_DATE side-effect at line 783.
		$this->assertSame(
			ImageModel::P_EXCLUDE_DATE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isDateExcluded_returns_true_for_after_rule_when_item_date_follows_rule_date() {
		// Item added in 2024-06, rule says "after 2024-01-01" → excluded.
		$model = $this->makeModelWithDateOptions(
			array( 'date' => '2024-01-01', 'when' => 'after' )
		);
		$model->setMeta( 'tsAdded', strtotime( '2024-06-15' ) );
		$model->setMeta( 'tsOptimized', 0 );

		$result = $this->invokeProtected( $model, 'isDateExcluded' );

		// Sentinel-pair with the before-rule test: SAME method, mirror-image
		// rule, both branches must fire. A regression that broke one branch
		// would fail one of the pair.
		$this->assertTrue( $result );
	}

	public function test_isDateExcluded_uses_tsOptimized_when_greater_than_zero_over_tsAdded() {
		// tsAdded = 2020 (would trigger before-2024 rule), tsOptimized = 2024
		// (would NOT trigger). Method should prefer tsOptimized.
		$model = $this->makeModelWithDateOptions(
			array( 'date' => '2022-01-01', 'when' => 'before' )
		);
		$model->setMeta( 'tsAdded', strtotime( '2020-01-01' ) );
		$model->setMeta( 'tsOptimized', strtotime( '2024-01-01' ) );

		$result = $this->invokeProtected( $model, 'isDateExcluded' );

		// If method used tsAdded (2020), it'd be < 2022 → excluded (true).
		// Since it should use tsOptimized (2024), it's > 2022 → NOT excluded.
		$this->assertFalse( $result );
	}

	/*
	 * setStub — populates the model from a path, checking the DB for an
	 * existing row.
	 */

	public function test_setStub_seeds_fresh_ImageMeta_when_no_matching_DB_row_exists() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel();
		$before = time();

		// No row in shortpixel_meta for this path (fresh test env).
		$model->setStub( $path, true );
		$after = time();

		$this->assertSame( $path, $model->getFullPath() );
		$this->assertFalse( $this->getProtected( $model, 'in_db' ) );

		// ImageMeta seeded with sensible defaults.
		$meta = $model->getMeta( false );
		$this->assertInstanceOf( ImageMeta::class, $meta );
		$this->assertSame( 0, $meta->compressedSize );
		$this->assertSame( 0, $meta->tsOptimized );
		// tsAdded bounded to time()-window; sentinel against a hardcoded value.
		$this->assertGreaterThanOrEqual( $before, $meta->tsAdded );
		$this->assertLessThanOrEqual( $after, $meta->tsAdded );
	}

	public function test_setStub_sets_path_md5_from_the_given_path() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel();

		$model->setStub( $path, false );

		$this->assertSame( md5( $path ), $this->getProtected( $model, 'path_md5' ) );
	}

	public function test_setStub_loads_meta_when_existing_row_and_load_flag_true() {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_meta';
		$path  = $this->makeImageFile();

		// Seed a real DB row so setStub finds it.
		$wpdb->insert(
			$table,
			array(
				'folder_id'       => 1,
				'compressed_size' => 500,
				'compression_type' => 1,
				'keep_exif'       => 0,
				'cmyk2rgb'        => 0,
				'resize'          => 0,
				'resize_width'    => 0,
				'resize_height'   => 0,
				'backup'          => 0,
				'status'          => ImageModel::FILE_STATUS_SUCCESS,
				'retries'         => 0,
				'message'         => '25.50',
				'ts_added'        => gmdate( 'Y-m-d H:i:s' ),
				'ts_optimized'    => gmdate( 'Y-m-d H:i:s' ),
				'path'            => $path,
				'name'            => basename( $path ),
				'path_md5'        => md5( $path ),
			)
		);
		$insertedId = $wpdb->insert_id;

		$model = $this->makeStubModel();
		$model->setStub( $path, true );

		// Sentinel: in_db flips true AND the id gets captured AND meta
		// hydrated from the row (status = SUCCESS, compressedSize = 500).
		$this->assertTrue( $this->getProtected( $model, 'in_db' ) );
		$this->assertSame( (int) $insertedId, (int) $model->get( 'id' ) );
		$this->assertSame( ImageModel::FILE_STATUS_SUCCESS, $model->getMeta( 'status' ) );
		$this->assertSame( 500, $model->getMeta( 'compressedSize' ) );

		// Cleanup — WP_UnitTestCase rolls back but be explicit.
		$wpdb->delete( $table, array( 'id' => $insertedId ) );
	}

	/*
	 * getWebps / getAvifs — single-element array wrappers around
	 * getWebp / getAvif, with array_filter to drop the false return.
	 */

	public function test_getWebps_returns_empty_array_when_no_webp_companion_exists() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$result = $this->invokeProtected( $model, 'getWebps' );

		// getWebp returns false → wrapped as [false] → array_filter drops → [].
		$this->assertSame( array(), $result );
	}

	public function test_getWebps_returns_single_element_array_when_webp_companion_exists() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		// Create a matching .webp companion on disk.
		$webpBase = pathinfo( $path, PATHINFO_FILENAME ) . '.webp';
		$webpPath = dirname( $path ) . '/' . $webpBase;
		file_put_contents( $webpPath, 'webp' );
		$this->fixtureFiles[] = $webpPath;

		$result = $this->invokeProtected( $model, 'getWebps' );

		$this->assertCount( 1, $result );
	}

	public function test_getAvifs_returns_empty_array_when_no_avif_companion_exists() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );

		$result = $this->invokeProtected( $model, 'getAvifs' );

		// Sentinel-pair with getWebps empty test — same shape, different type.
		$this->assertSame( array(), $result );
	}

	/*
	 * getOptimizeFileType — pdf short-circuit + companion-existence
	 * logic.
	 */

	public function test_getOptimizeFileType_returns_empty_array_for_pdf_extension() {
		$path  = $this->makeImageFile( 'pdf' );
		$model = $this->makeStubModel( $path );

		$result = $model->getOptimizeFileType( 'webp' );

		// Sentinel: PDF short-circuits regardless of other state. A regression
		// that removed the pdf guard would attempt to check for companions
		// (which don't apply to PDFs).
		$this->assertSame( array(), $result );
	}

	public function test_getOptimizeFileType_returns_empty_when_no_companion_and_not_processable_and_not_optimized() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );
		// Force not-processable via cache seed to skip filesystem re-evaluation.
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_PATH );

		$result = $model->getOptimizeFileType( 'webp' );

		$this->assertSame( array(), $result );
	}

	public function test_getOptimizeFileType_returns_single_url_when_no_companion_but_image_is_optimized() {
		$path  = $this->makeImageFile();
		$model = $this->makeStubModel( $path );
		// Mark optimized. Now getOptimizeFileType('webp') should offer the main
		// URL for webp generation.
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$result = $model->getOptimizeFileType( 'webp' );

		// Sentinel: exactly one URL returned. Regression that dropped the
		// isOptimized branch would return empty here.
		$this->assertCount( 1, $result );
	}

	/*
	 * dropFromQueue — instantiates single + bulk queue controllers and
	 * calls dropItem on each. Smoke test: no crash + method reaches the
	 * end.
	 */

	public function test_dropFromQueue_runs_without_crashing_on_a_stub_model() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'id', 999999 );

		// The method has no return value — we just verify it completes.
		// A regression that broke the queue-controller construction chain
		// would surface as a fatal here.
		$model->dropFromQueue();
		$this->assertTrue( true, 'reached this point → dropFromQueue chain intact' );
	}

	/*
	 * onDelete — delegates to parent::onDelete (backup cleanup) +
	 * deleteMeta + dropFromQueue.
	 */

	public function test_onDelete_invokes_backupModel_onDelete_via_parent() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'id', 999999 );

		$stubBackup = new class {
			public $onDeleteCalled = false;
			public function onDelete( $model ) { $this->onDeleteCalled = true; }
		};
		$this->setProtected( $model, 'backupModel', $stubBackup );

		$model->onDelete();

		// Sentinel: the parent::onDelete chain fires the backupModel's
		// onDelete. deleteMeta and dropFromQueue also run but their
		// side-effects are harder to observe here — this assertion pins
		// the delegation path.
		$this->assertTrue( $stubBackup->onDeleteCalled );
	}
}
