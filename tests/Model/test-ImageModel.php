<?php
/**
 * Tests for ShortPixel\Model\Image\ImageModel (abstract).
 *
 * SESSION 1 (done) — construction + init + simple accessors + meta +
 * record-change tracking + abstract-method contract sentinel.
 *
 * SESSION 2 (done) — exclusion logic:
 *   - matchExcludePattern / matchExcludeRegexPattern (pure matchers)
 *   - isProcessableSize (pure size-range math)
 *   - isPathExcluded / isExtensionExcluded / isSizeExcluded /
 *     isFileSizeExcluded / checkDateExcluded (getExcludePatterns-driven)
 *
 * SESSION 3 (done) — processability state machine:
 *   - isProcessable (cache short-circuit + big OR-chain)
 *   - isProcessableFileType (webp/avif variant gate)
 *   - isProcessableAnyFileType (OR of the above)
 *   - getProcessableReason (P_* → i18n string translator)
 *
 * SESSION 4 (done) — pipelines:
 *   - handleWebp / handleAvif (temp-file → target move, with the
 *     `handleAvif` swallowed-failure bug pinned)
 *   - handleOptimizedFileType (dispatch to handleWebp/handleAvif +
 *     FILETYPE_BIGGER handling for OPTIMIZED_BIGGER / NOT_COMPATIBLE)
 *   - handleOptimized (backup skip paths, no-copy stati, missing-file
 *     early exit, meta updates on success, P_IS_OPTIMIZED side-effect)
 *   - createBackup (only the `shortpixel/image/skip_backup` filter
 *     escape — the real BackupModel path needs a full BackupController
 *     fixture, deferred to integration tests)
 *   - createParamList (settings-off paths, filter escape hatch)
 *
 * SESSION 5 (done) — tail methods:
 *   - getImprovement (percentage + byte-savings math, negative clamp)
 *   - getCountOptimizeData (via _testOptimizeData injection — Finding D
 *     workaround because getOptimizeData isn't declared abstract)
 *   - getImageType (webp/avif companion resolution via meta + convention)
 *   - getBackupModel (cache branch)
 *   - toClass (image_meta delegate)
 *   - setVirtualToReal (virtual → real path transition)
 *   - onDelete (backup + webp/avif companion cleanup, with stubbed
 *     backupModel to avoid BackupController)
 *   - isRestorable (state machine, testable branches only)
 *   - isUserExcluded / cancelUserExclusions (status-code introspection)
 *   - fs (trivial filesystem wrapper)
 *
 * Also NOT covered here (session 4/5 skipped):
 *   - restore() — needs a fully-wired BackupModel + BackupController.
 *   - The main-path createBackup — same reason.
 *   - handleOptimized's is_virtual branches — need a virtual FileModel
 *     fixture, plus the `shortpixel/file/virtual/translate` filter path.
 *   - getBackupModel unset-branch — hits BackupController::getBackupController().
 *   - isRestorable "not writable" / "not directory writable" branches —
 *     chmod-based tests are CI-unfriendly.
 *
 * ImageModel is abstract with 8 abstract methods (getOptimizeUrls,
 * saveMeta, loadMeta, getImprovements, getExcludePatterns,
 * preventNextTry, isOptimizePrevented, resetPrevent). Tests use a
 * minimal test-only concrete subclass that stubs those methods; the
 * `_testPatterns` public property lets tests seed getExcludePatterns
 * per-test without redefining the anonymous class each time, and
 * `_testOptimizePrevented` lets tests simulate the prevented state.
 *
 * AccessModel coupling note: `isProcessableFileType` gates on
 * `AccessModel::getInstance()->isFeatureAvailable($type)`. Rather than
 * seeding that singleton, session-3 tests focus on the settings-off
 * branches (which return false BEFORE the AccessModel gate matters)
 * and the "extension is webp/avif/pdf" gates (which fire AFTER the
 * AccessModel + settings gates). The "settings on + AccessModel true"
 * happy-path is skipped at the unit level — integration territory.
 *
 * NOT covered here (deferred to later sessions):
 *   - Session 4: handleOptimized + handleOptimizedFileType +
 *     handleWebp + handleAvif + restore + createBackup + createParamList
 *   - Session 5: getImprovement, getCountOptimizeData, getImageType,
 *     getBackupModel, toClass, setVirtualToReal, onDelete, isRestorable,
 *     cancelUserExclusions, isUserExcluded, fs
 *
 * Fixture files: a minimal valid 1×1 PNG is written to sys_get_temp_dir()
 * for tests that need setImageSize / isImage / exists to hit real file
 * system state. Cleaned up in tear_down.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\ImageModel;

class ImageModelTest extends WP_UnitTestCase {

	/** @var string[] Absolute paths of fixture files created during tests. */
	private $fixtureFiles = array();

	/** @var mixed Snapshot of the optimizePdfs setting for restore in tear_down. */
	private $savedOptimizePdfs;
	/** @var mixed Snapshot of the createWebp setting. */
	private $savedCreateWebp;
	/** @var mixed Snapshot of the createAvif setting. */
	private $savedCreateAvif;
	/** @var mixed Snapshot of the backupImages setting. */
	private $savedBackupImages;
	/** @var mixed Snapshot of the resizeImages setting. */
	private $savedResizeImages;
	/** @var mixed Snapshot of the useSmartcrop setting. */
	private $savedUseSmartcrop;

	public function set_up() {
		parent::set_up();
		$settings = \wpSPIO()->settings();
		$this->savedOptimizePdfs = $settings->optimizePdfs;
		$this->savedCreateWebp   = $settings->createWebp;
		$this->savedCreateAvif   = $settings->createAvif;
		$this->savedBackupImages = $settings->backupImages;
		$this->savedResizeImages = $settings->resizeImages;
		$this->savedUseSmartcrop = $settings->useSmartcrop;
	}

	public function tear_down() {
		$settings = \wpSPIO()->settings();
		$settings->optimizePdfs = $this->savedOptimizePdfs;
		$settings->createWebp   = $this->savedCreateWebp;
		$settings->createAvif   = $this->savedCreateAvif;
		$settings->backupImages = $this->savedBackupImages;
		$settings->resizeImages = $this->savedResizeImages;
		$settings->useSmartcrop = $this->savedUseSmartcrop;

		remove_all_filters( 'shortpixel/image/skip_backup' );
		remove_all_filters( 'shortpixel/image/imageparamlist' );
		remove_all_filters( 'shortpixel/file/virtual/translate' );

		foreach ( $this->fixtureFiles as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		$this->fixtureFiles = array();
		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function getProtected( ImageModel $m, string $prop ) {
		$ref = new ReflectionClass( ImageModel::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setProtected( ImageModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( ImageModel::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function invokeProtected( ImageModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( ImageModel::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	/*
	 * Fixture builders
	 */

	/**
	 * Write a minimal valid 1×1 PNG to a temp path so setImageSize /
	 * getimagesize / isImage have real file state to inspect.
	 */
	private function makeImageFile( string $extension = 'png' ): string {
		$path = sys_get_temp_dir() . '/spio-imagemodel-test-' . uniqid() . '.' . $extension;
		// Base64-encoded 1×1 red PNG — minimal-valid, decoder-agnostic.
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
	 * Write a zero-byte file with the requested extension. Used by
	 * isFileSizeExcluded tests that need the file to have getFileSize()
	 * === 0 for the "virtual / zero-byte skip" branch.
	 */
	private function makeEmptyFile( string $extension = 'png' ): string {
		$path = sys_get_temp_dir() . '/spio-imagemodel-empty-' . uniqid() . '.' . $extension;
		file_put_contents( $path, '' );
		$this->fixtureFiles[] = $path;
		return $path;
	}

	/**
	 * Write a fixture of a specified size in bytes. Used by
	 * isFileSizeExcluded tests that need real byte-size comparisons.
	 */
	private function makeSizedFile( int $bytes, string $extension = 'png' ): string {
		$path = sys_get_temp_dir() . '/spio-imagemodel-sized-' . uniqid() . '.' . $extension;
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
		$this->fixtureFiles[] = $path;
		return $path;
	}

	/**
	 * Build a concrete instance of ImageModel via an anonymous test-only
	 * subclass. Stubs the 8 abstract methods with trivial returns; the
	 * `$image_meta` is a stdClass with the fields ImageModel expects to
	 * read/write. The public `$_testPatterns` field lets tests seed
	 * `getExcludePatterns()` per-test without redefining the anonymous
	 * class each time.
	 */
	private function makeModel( ?string $path = null ): ImageModel {
		if ( null === $path ) {
			$path = $this->makeImageFile();
		}

		$model = new class( $path ) extends ImageModel {
			/**
			 * Test-only injection point for getExcludePatterns.
			 * `false` mirrors the default "no patterns" return.
			 */
			public $_testPatterns = false;

			/**
			 * Test-only injection point for isOptimizePrevented.
			 * `false` mirrors the default "not prevented" return.
			 * A truthy value (string reason) simulates the "prevented" state.
			 */
			public $_testOptimizePrevented = false;

			/**
			 * Declared on concrete subclasses (CustomImageModel /
			 * MediaLibraryModel / MediaLibraryThumbnailModel) but NOT
			 * on ImageModel itself. handleOptimized reads it via
			 * `$this->is_main_file` on the `shortpixel/image/skip_backup`
			 * filter args; declaring it here avoids an undefined-property
			 * exception under convertNoticesToExceptions=true.
			 */
			protected $is_main_file = true;

			/**
			 * Declared on MediaLibraryThumbnailModel only. createParamList
			 * reads it in the smartcrop+resize interaction branch. Declared
			 * here as false so the branch treats "no size definition" as
			 * the safe path.
			 */
			public $sizeDefinition = false;

			/**
			 * Test-only injection point for getOptimizeData. See Finding D
			 * in the deferred-bugs list: getCountOptimizeData calls
			 * $this->getOptimizeData() but the method isn't declared
			 * abstract at the top of ImageModel — it's an implicit
			 * contract. Session 5 tests exercise getCountOptimizeData via
			 * this stub.
			 */
			public $_testOptimizeData = array();

			public function getOptimizeData() {
				return $this->_testOptimizeData;
			}

			public function __construct( $path ) {
				parent::__construct( $path );
				$this->image_meta          = new stdClass();
				$this->image_meta->status  = 0; // FILE_STATUS_UNPROCESSED
				$this->image_meta->originalWidth  = null;
				$this->image_meta->originalHeight = null;
				$this->image_meta->tsAdded        = null;
				$this->image_meta->webp           = null;
				$this->image_meta->avif           = null;
				// Meta fields handleOptimized writes to.
				$this->image_meta->tsOptimized       = null;
				$this->image_meta->compressedSize    = null;
				$this->image_meta->originalSize      = null;
				$this->image_meta->compressionType   = null;
				$this->image_meta->did_keepExif      = null;
				$this->image_meta->did_cmyk2rgb      = null;
				$this->image_meta->resize            = null;
				$this->image_meta->resizeWidth       = null;
				$this->image_meta->resizeHeight      = null;
				$this->image_meta->resizeType        = null;
				// A stand-in field so setMeta success can be observed without
				// touching a meta field that other logic depends on.
				$this->image_meta->configurable = null;
			}

			// --- 8 abstract stubs (no-op returns). ---
			public function getOptimizeUrls() { return array(); }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return $this->_testPatterns; }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return $this->_testOptimizePrevented; }
			public function resetPrevent() {}
		};

		return $model;
	}

	/*
	 * Abstract-method contract — pinned sentinel.
	 *
	 * Regressions that convert one of these to concrete (or rename) would
	 * silently break every concrete subclass that relies on the abstract
	 * to force the implementation. Sentinel checks all 8 by reflection.
	 */

	public function test_ImageModel_declares_the_expected_8_abstract_methods() {
		$ref             = new ReflectionClass( ImageModel::class );
		$abstractMethods = array();
		foreach ( $ref->getMethods() as $method ) {
			if ( $method->isAbstract() ) {
				$abstractMethods[] = $method->getName();
			}
		}
		sort( $abstractMethods );

		$expected = array(
			'getExcludePatterns',
			'getImprovements',
			'getOptimizeUrls',
			'isOptimizePrevented',
			'loadMeta',
			'preventNextTry',
			'resetPrevent',
			'saveMeta',
		);

		// Sentinel: assertSame catches BOTH missing AND extra abstracts.
		// A regression that removes an abstract (making the base concrete
		// and allowing dodgy subclasses) OR one that adds a new abstract
		// without updating subclasses would fail here.
		$this->assertSame( $expected, $abstractMethods );
	}

	public function test_ImageModel_class_itself_is_declared_abstract() {
		$ref = new ReflectionClass( ImageModel::class );
		$this->assertTrue( $ref->isAbstract() );
	}

	/*
	 * __construct — delegates to FileModel::__construct
	 */

	public function test_constructor_stores_the_path_on_the_parent_FileModel() {
		$path  = $this->makeImageFile();
		$model = $this->makeModel( $path );

		$this->assertSame( $path, $model->getFullPath() );
	}

	/*
	 * verifyImage — seeds originalWidth/originalHeight/tsAdded when null,
	 * skips them when already set. Also invokes setWebp/setAvif.
	 */

	public function test_verifyImage_seeds_originalWidth_and_originalHeight_from_image_dimensions_when_null() {
		$model = $this->makeModel();

		$this->invokeProtected( $model, 'verifyImage' );

		// The fixture is a 1×1 PNG — width and height should be seeded to 1.
		$this->assertSame( 1, $model->getMeta( 'originalWidth' ) );
		$this->assertSame( 1, $model->getMeta( 'originalHeight' ) );
	}

	public function test_verifyImage_seeds_tsAdded_with_the_current_timestamp_when_null() {
		$model = $this->makeModel();
		$before = time();

		$this->invokeProtected( $model, 'verifyImage' );

		$after = time();
		$ts    = $model->getMeta( 'tsAdded' );

		// Sentinel: bounded assertion catches both regressions to a
		// fixed timestamp AND drift into the future / past.
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	public function test_verifyImage_leaves_originalWidth_untouched_when_already_set() {
		$model = $this->makeModel();
		// Pre-seed a non-null value; verifyImage should not overwrite.
		$model->setMeta( 'originalWidth', 4200 );

		$this->invokeProtected( $model, 'verifyImage' );

		$this->assertSame( 4200, $model->getMeta( 'originalWidth' ) );
	}

	public function test_verifyImage_leaves_tsAdded_untouched_when_already_set() {
		$model = $this->makeModel();
		$model->setMeta( 'tsAdded', 1000000 );

		$this->invokeProtected( $model, 'verifyImage' );

		// Sentinel: distinct low value (1000000 = 1970-01-12) so a
		// regression that overwrites with time() would flip to a very
		// large number, failing the assertion loudly.
		$this->assertSame( 1000000, $model->getMeta( 'tsAdded' ) );
	}

	/*
	 * setImageSize — populates $width and $height from getimagesize,
	 * initialises to false when null so subsequent calls short-circuit.
	 */

	public function test_setImageSize_populates_width_and_height_from_a_real_image_file() {
		$model = $this->makeModel(); // 1×1 PNG fixture

		$this->invokeProtected( $model, 'setImageSize' );

		$this->assertSame( 1, $this->getProtected( $model, 'width' ) );
		$this->assertSame( 1, $this->getProtected( $model, 'height' ) );
	}

	public function test_setImageSize_initialises_width_to_false_when_starting_null() {
		$model = $this->makeModel();
		// Ensure both fields start null (fresh instance state).
		$this->setProtected( $model, 'width', null );
		$this->setProtected( $model, 'height', null );

		// Rename the fixture file to a non-image extension so the check
		// at the top of setImageSize (isExtensionExcluded → true) skips
		// getimagesize and leaves the fields at their "false" init state.
		$path = $model->getFullPath();
		$txt  = $path . '.notanimage';
		rename( $path, $txt );
		$this->fixtureFiles[] = $txt;

		// Re-point the model at the renamed file so isExtensionExcluded fires.
		$model2 = $this->makeModel( $txt );
		$this->setProtected( $model2, 'width', null );
		$this->setProtected( $model2, 'height', null );

		$this->invokeProtected( $model2, 'setImageSize' );

		// Sentinel: the null-initialisation branch at lines 301-308 sets
		// both to false. Regression that skipped the init would leave
		// them null, and the assertion would fail with null !== false.
		$this->assertFalse( $this->getProtected( $model2, 'width' ) );
		$this->assertFalse( $this->getProtected( $model2, 'height' ) );
	}

	/*
	 * get() / __get() — property accessor + magic delegate with lazy
	 * width/height loading.
	 */

	public function test_get_returns_null_for_unknown_property_name() {
		$model = $this->makeModel();

		$this->assertNull( $model->get( 'definitely_not_a_property' ) );
	}

	public function test_get_returns_known_property_value() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'id', 99 );

		$this->assertSame( 99, $model->get( 'id' ) );
	}

	public function test_get_lazy_loads_width_when_starting_null() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'width', null );

		// First call should trigger setImageSize() to populate width.
		$result = $model->get( 'width' );

		$this->assertSame( 1, $result );
	}

	public function test___get_magic_accessor_delegates_to_get() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'id', 42 );

		// Sentinel: proves __get is a pure delegate. A regression that
		// bypassed get() and read the property directly would still
		// return 42 for this specific case — so we also check the
		// null-for-unknown behaviour that only get() returns.
		$this->assertSame( 42, $model->id );
		$this->assertNull( $model->definitely_not_a_property );
	}

	/*
	 * getMeta / hasMeta / setMeta — image_meta read/write layer.
	 */

	public function test_getMeta_returns_whole_meta_object_when_called_with_false() {
		$model = $this->makeModel();

		$meta = $model->getMeta( false );

		$this->assertInstanceOf( \stdClass::class, $meta );
	}

	public function test_getMeta_returns_the_field_value_when_field_is_known() {
		$model = $this->makeModel();
		$model->setMeta( 'configurable', 'sentinel_value_xyz' );

		$this->assertSame( 'sentinel_value_xyz', $model->getMeta( 'configurable' ) );
	}

	public function test_getMeta_returns_null_and_logs_warning_for_unknown_field() {
		$model = $this->makeModel();

		$this->assertNull( $model->getMeta( 'unknown_field_name' ) );
	}

	public function test_hasMeta_returns_true_when_the_field_exists_on_image_meta() {
		$model = $this->makeModel();

		$this->assertTrue( $model->hasMeta( 'status' ) );
		$this->assertTrue( $model->hasMeta( 'configurable' ) );
	}

	public function test_hasMeta_returns_false_when_the_field_does_not_exist() {
		$model = $this->makeModel();

		$this->assertFalse( $model->hasMeta( 'definitely_not_a_field' ) );
	}

	public function test_setMeta_writes_the_value_and_flags_recordChanged() {
		$model = $this->makeModel();

		$model->setMeta( 'configurable', 'new_value' );

		$this->assertSame( 'new_value', $model->getMeta( 'configurable' ) );
		$this->assertTrue( $this->invokeProtected( $model, 'didRecordChange' ) );
	}

	public function test_setMeta_does_not_flag_recordChanged_when_value_is_unchanged() {
		$model = $this->makeModel();
		// Seed and consume the initial change flag from constructor time.
		$model->setMeta( 'configurable', 'same_value' );
		$this->setProtected( $model, 'recordChanged', false );

		// Set to the same value again — should not flip the flag.
		$model->setMeta( 'configurable', 'same_value' );

		// Sentinel: strict identity check on the flag. Regression that
		// unconditionally set recordChanged=true on every setMeta call
		// would fail here.
		$this->assertFalse( $this->invokeProtected( $model, 'didRecordChange' ) );
	}

	public function test_setMeta_returns_false_and_does_not_write_for_unknown_field() {
		$model = $this->makeModel();

		$result = $model->setMeta( 'unknown_field_name', 'x' );

		$this->assertFalse( $result );
		// hasMeta still false — nothing was created.
		$this->assertFalse( $model->hasMeta( 'unknown_field_name' ) );
	}

	/*
	 * isImage — virtual + non-virtual + non-existent branches.
	 */

	public function test_isImage_returns_false_when_file_does_not_exist() {
		$model = $this->makeModel();
		// Delete the fixture so exists() returns false.
		@unlink( $model->getFullPath() );

		$this->assertFalse( $model->isImage() );
	}

	public function test_isImage_returns_true_for_a_valid_png_fixture() {
		$model = $this->makeModel();

		$this->assertTrue( $model->isImage() );
	}

	/*
	 * exists() — updates processable_status side-effect on false.
	 */

	public function test_exists_sets_processable_status_to_P_FILE_NOT_EXIST_when_file_missing() {
		$model = $this->makeModel();
		@unlink( $model->getFullPath() );

		$result = $model->exists();

		$this->assertFalse( $result );
		// Sentinel: the side-effect is what pins the status. A regression
		// that returned false without setting the status would leave the
		// downstream getReason() call reporting a stale value.
		$this->assertSame(
			ImageModel::P_FILE_NOT_EXIST,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_exists_returns_true_and_does_not_touch_processable_status_when_file_exists() {
		$model = $this->makeModel();
		// Pre-seed processable_status to a distinct value; exists() should not overwrite.
		$this->setProtected( $model, 'processable_status', ImageModel::P_PROCESSABLE );

		$this->assertTrue( $model->exists() );
		// Sentinel-pair with the previous test: the side-effect is
		// conditional on file NOT existing. If exists() unconditionally
		// touched the status, this assertion would fail.
		$this->assertSame(
			ImageModel::P_PROCESSABLE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	/*
	 * getReason — routes to processable_status vs restorable_status
	 * depending on the $name argument.
	 */

	public function test_getReason_returns_the_processable_reason_string_for_the_processable_status() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_PROCESSABLE );

		$this->assertSame( 'Image Processable', $model->getReason( 'processable' ) );
	}

	public function test_getReason_returns_the_restorable_reason_string_for_the_restorable_status() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'restorable_status', ImageModel::P_RESTORABLE );

		$this->assertSame( 'Image restorable', $model->getReason( 'restorable' ) );
	}

	public function test_getReason_returns_distinct_strings_for_different_processable_status_codes() {
		$model = $this->makeModel();

		$this->setProtected( $model, 'processable_status', ImageModel::P_FILE_NOT_EXIST );
		$this->assertSame( 'File does not exist', $model->getReason( 'processable' ) );

		$this->setProtected( $model, 'processable_status', ImageModel::P_IS_OPTIMIZED );
		$this->assertSame( 'Image is already optimized', $model->getReason( 'processable' ) );

		// Sentinel: three distinct status codes → three distinct
		// messages. Catches a regression that hardcoded a single message
		// or missed one of the case branches.
	}

	/*
	 * getWebp / getAvif — thin delegates to getImageType.
	 */

	public function test_getWebp_returns_false_when_no_webp_variant_exists() {
		$model = $this->makeModel();
		// image_meta->webp is null by default; no companion file on disk.

		$result = $model->getWebp();

		// getImageType returns false when no companion is found.
		$this->assertFalse( $result );
	}

	public function test_getAvif_returns_false_when_no_avif_variant_exists() {
		$model = $this->makeModel();

		$result = $model->getAvif();

		$this->assertFalse( $result );
	}

	/*
	 * isOptimized — status check + side-effect on processable_status.
	 */

	public function test_isOptimized_returns_true_when_meta_status_equals_FILE_STATUS_SUCCESS() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$this->assertTrue( $model->isOptimized() );
	}

	public function test_isOptimized_returns_false_for_other_status_values() {
		$model = $this->makeModel();

		// Try three non-SUCCESS statuses to catch a regression that only
		// checked one specific "not success" value.
		$model->setMeta( 'status', ImageModel::FILE_STATUS_UNPROCESSED );
		$this->assertFalse( $model->isOptimized() );

		$model->setMeta( 'status', ImageModel::FILE_STATUS_ERROR );
		$this->assertFalse( $model->isOptimized() );

		$model->setMeta( 'status', ImageModel::FILE_STATUS_PENDING );
		$this->assertFalse( $model->isOptimized() );
	}

	public function test_isOptimized_side_effect_seeds_processable_status_to_P_IS_OPTIMIZED_when_true() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$model->isOptimized();

		// Sentinel: pins the documented side-effect. A regression that
		// returned true without setting the status would leave later
		// isProcessable() calls repeating work isOptimized() should
		// have cached.
		$this->assertSame(
			ImageModel::P_IS_OPTIMIZED,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isOptimized_does_not_touch_processable_status_when_false() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', null );
		$model->setMeta( 'status', ImageModel::FILE_STATUS_UNPROCESSED );

		$model->isOptimized();

		// Sentinel-pair with the previous test: side-effect is conditional
		// on returning true. Regression that always set the status would
		// fail this null-assertion.
		$this->assertNull( $this->getProtected( $model, 'processable_status' ) );
	}

	/*
	 * recordChanged / didRecordChange — the setMeta side-effect API.
	 */

	public function test_didRecordChange_returns_false_on_a_fresh_model() {
		$model = $this->makeModel();

		$this->assertFalse( $this->invokeProtected( $model, 'didRecordChange' ) );
	}

	public function test_recordChanged_flips_the_flag_true() {
		$model = $this->makeModel();
		$this->invokeProtected( $model, 'recordChanged', array( true ) );

		$this->assertTrue( $this->invokeProtected( $model, 'didRecordChange' ) );
	}

	public function test_recordChanged_can_clear_the_flag_with_false() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'recordChanged', true );

		$this->invokeProtected( $model, 'recordChanged', array( false ) );

		$this->assertFalse( $this->invokeProtected( $model, 'didRecordChange' ) );
	}

	// =============================================================
	// SESSION 2 — exclusion logic
	// =============================================================

	/*
	 * matchExcludePattern — pure substring match. Empty pattern never matches.
	 */

	public function test_matchExcludePattern_returns_true_for_substring_match() {
		$model = $this->makeModel();

		$this->assertTrue(
			$this->invokeProtected( $model, 'matchExcludePattern', array( '/wp-content/uploads/foo.jpg', 'uploads' ) )
		);
	}

	public function test_matchExcludePattern_returns_false_for_no_match() {
		$model = $this->makeModel();

		$this->assertFalse(
			$this->invokeProtected( $model, 'matchExcludePattern', array( '/wp-content/uploads/foo.jpg', 'notpresent' ) )
		);
	}

	public function test_matchExcludePattern_returns_false_for_empty_pattern() {
		$model = $this->makeModel();

		// Sentinel: empty patterns must NOT match ("").
		// A regression that dropped the strlen guard would treat "" as
		// a substring of every string and false-positive every image.
		$this->assertFalse(
			$this->invokeProtected( $model, 'matchExcludePattern', array( '/anything', '' ) )
		);
	}

	public function test_matchExcludePattern_is_case_sensitive() {
		$model = $this->makeModel();

		// Sentinel: pins case-sensitivity. Some settings pages let users
		// paste filenames from OS file browsers; upper/lower case matters.
		$this->assertFalse(
			$this->invokeProtected( $model, 'matchExcludePattern', array( '/wp-content/UPLOADS/foo.jpg', 'uploads' ) )
		);
	}

	/*
	 * matchExcludeRegexPattern — pure regex match. Empty pattern never matches.
	 */

	public function test_matchExcludeRegexPattern_returns_true_for_matching_regex() {
		$model = $this->makeModel();

		$this->assertTrue(
			$this->invokeProtected(
				$model,
				'matchExcludeRegexPattern',
				array( '/wp-content/uploads/2024/06/photo.jpg', '#/uploads/\d{4}/\d{2}/#' )
			)
		);
	}

	public function test_matchExcludeRegexPattern_returns_false_for_non_matching_regex() {
		$model = $this->makeModel();

		$this->assertFalse(
			$this->invokeProtected(
				$model,
				'matchExcludeRegexPattern',
				array( '/wp-content/uploads/photo.jpg', '#/notpresent/#' )
			)
		);
	}

	public function test_matchExcludeRegexPattern_returns_false_for_empty_pattern() {
		$model = $this->makeModel();

		$this->assertFalse(
			$this->invokeProtected( $model, 'matchExcludeRegexPattern', array( '/anything', '' ) )
		);
	}

	public function test_matchExcludeRegexPattern_returns_false_for_invalid_regex_syntax() {
		$model = $this->makeModel();

		// Malformed regex (missing delimiter). preg_match returns false;
		// method's `$m !== false && $m > 0` guard should treat this as
		// "no match" rather than propagating the false.
		$this->assertFalse(
			@$this->invokeProtected( $model, 'matchExcludeRegexPattern', array( '/anything', 'not a valid regex' ) )
		);
	}

	/*
	 * isProcessableSize — pure size-range math.
	 * Rule format: "minW-maxW × minH-maxH" (× / x / X separator).
	 * Return: TRUE when dimensions fall *outside* the excluded range
	 *         (i.e. still processable), FALSE when dimensions match
	 *         (i.e. should be excluded).
	 */

	public function test_isProcessableSize_returns_false_when_dimensions_fall_inside_the_excluded_range() {
		$model = $this->makeModel();

		// 500×500 image, excluded range 100-1000 × 100-1000 → matches → false.
		$this->assertFalse(
			$this->invokeProtected( $model, 'isProcessableSize', array( 500, 500, '100-1000 × 100-1000' ) )
		);
	}

	public function test_isProcessableSize_returns_true_when_width_is_above_the_excluded_range() {
		$model = $this->makeModel();

		// 2000×500, excluded 100-1000 × 100-1000 → width outside → true.
		$this->assertTrue(
			$this->invokeProtected( $model, 'isProcessableSize', array( 2000, 500, '100-1000 × 100-1000' ) )
		);
	}

	public function test_isProcessableSize_returns_true_when_height_is_below_the_excluded_range() {
		$model = $this->makeModel();

		$this->assertTrue(
			$this->invokeProtected( $model, 'isProcessableSize', array( 500, 50, '100-1000 × 100-1000' ) )
		);
	}

	public function test_isProcessableSize_treats_single_dimension_as_min_equals_max() {
		$model = $this->makeModel();

		// Rule "500" → both min and max = 500. Exact match → excluded.
		$this->assertFalse(
			$this->invokeProtected( $model, 'isProcessableSize', array( 500, 500, '500 × 500' ) )
		);

		// Sentinel-pair: 501×500 misses the width bound.
		$this->assertTrue(
			$this->invokeProtected( $model, 'isProcessableSize', array( 501, 500, '500 × 500' ) )
		);
	}

	public function test_isProcessableSize_accepts_x_and_capital_X_as_separators() {
		$model = $this->makeModel();

		// Sentinel: the docblock says × / x / X are all accepted.
		// Regression that only handled one separator would fail one of these.
		$this->assertFalse(
			$this->invokeProtected( $model, 'isProcessableSize', array( 500, 500, '100-1000 x 100-1000' ) )
		);
		$this->assertFalse(
			$this->invokeProtected( $model, 'isProcessableSize', array( 500, 500, '100-1000 X 100-1000' ) )
		);
	}

	public function test_isProcessableSize_omits_height_bounds_when_no_x_separator() {
		$model = $this->makeModel();

		// Rule "100-1000" (no height component) → only width is checked.
		// Any height passes. 500×any is inside width bound → excluded.
		$this->assertFalse(
			$this->invokeProtected( $model, 'isProcessableSize', array( 500, 99999, '100-1000' ) )
		);
	}

	/*
	 * isPathExcluded — dispatches to matchExcludePattern /
	 * matchExcludeRegexPattern based on `type`. Sets processable_status
	 * to P_EXCLUDE_PATH on match. Skips unknown types silently.
	 */

	public function test_isPathExcluded_returns_false_when_no_patterns_configured() {
		$model = $this->makeModel();
		$model->_testPatterns = false;

		$this->assertFalse( $this->invokeProtected( $model, 'isPathExcluded' ) );
	}

	public function test_isPathExcluded_returns_false_and_leaves_status_alone_when_no_patterns_match() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'name', 'value' => 'nonexistent_string' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'isPathExcluded' ) );
		$this->assertNull( $this->getProtected( $model, 'processable_status' ) );
	}

	public function test_isPathExcluded_matches_name_type_against_filename_and_sets_P_EXCLUDE_PATH() {
		// Create a fixture whose filename we can predict.
		$path  = sys_get_temp_dir() . '/spio-imagemodel-namematch-known.png';
		file_put_contents(
			$path,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==' )
		);
		$this->fixtureFiles[] = $path;

		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'name', 'value' => 'namematch-known' ),
		);

		$this->assertTrue( $this->invokeProtected( $model, 'isPathExcluded' ) );
		// Sentinel: pins the status-cache side-effect. Regression that
		// returned true without setting the cache would leave getReason()
		// reporting a stale/wrong reason.
		$this->assertSame(
			ImageModel::P_EXCLUDE_PATH,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isPathExcluded_matches_path_type_against_full_path() {
		$model = $this->makeModel();
		$fullPath = $model->getFullPath();

		// Match against a substring of the directory path.
		$model->_testPatterns = array(
			array( 'type' => 'path', 'value' => dirname( $fullPath ) ),
		);

		$this->assertTrue( $this->invokeProtected( $model, 'isPathExcluded' ) );
	}

	public function test_isPathExcluded_matches_regex_name_type() {
		$path  = sys_get_temp_dir() . '/spio-imagemodel-regex-42.png';
		file_put_contents(
			$path,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==' )
		);
		$this->fixtureFiles[] = $path;

		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'regex-name', 'value' => '#regex-\d+#' ),
		);

		$this->assertTrue( $this->invokeProtected( $model, 'isPathExcluded' ) );
	}

	public function test_isPathExcluded_skips_unknown_types_silently() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'not_a_known_type', 'value' => 'anything' ),
		);

		// Sentinel: unknown types must be silently skipped, not treated
		// as any of the four handled types. Regression that fell into
		// the matcher for unknown types could false-positive on
		// filename patterns.
		$this->assertFalse( $this->invokeProtected( $model, 'isPathExcluded' ) );
	}

	/*
	 * isExtensionExcluded — checks against PROCESSABLE_EXTENSIONS.
	 * PDFs additionally require optimizePdfs setting.
	 */

	public function test_isExtensionExcluded_returns_false_for_processable_extension() {
		$model = $this->makeModel(); // .png fixture

		$this->assertFalse( $this->invokeProtected( $model, 'isExtensionExcluded' ) );
	}

	public function test_isExtensionExcluded_returns_true_for_unlisted_extension_and_sets_P_EXCLUDE_EXTENSION() {
		$path  = $this->makeSizedFile( 100, 'xyz' );
		$model = $this->makeModel( $path );

		// Sentinel-pair: BOTH the return value AND the status cache side-effect.
		$this->assertTrue( $this->invokeProtected( $model, 'isExtensionExcluded' ) );
		$this->assertSame(
			ImageModel::P_EXCLUDE_EXTENSION,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isExtensionExcluded_returns_false_for_pdf_when_optimizePdfs_setting_is_on() {
		\wpSPIO()->settings()->optimizePdfs = true;
		$path  = $this->makeSizedFile( 100, 'pdf' );
		$model = $this->makeModel( $path );

		$this->assertFalse( $this->invokeProtected( $model, 'isExtensionExcluded' ) );
	}

	public function test_isExtensionExcluded_returns_true_for_pdf_and_sets_P_EXCLUDE_EXTENSION_PDF_when_setting_off() {
		\wpSPIO()->settings()->optimizePdfs = false;
		$path  = $this->makeSizedFile( 100, 'pdf' );
		$model = $this->makeModel( $path );

		$this->assertTrue( $this->invokeProtected( $model, 'isExtensionExcluded' ) );
		$this->assertSame(
			ImageModel::P_EXCLUDE_EXTENSION_PDF,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	/**
	 * PINNED for deferred fix — the PDF-extension check at line 1539
	 * uses `===` without lowercasing (`'pdf' === $this->getExtension()`).
	 * A `.PDF` (uppercase) extension bypasses the optimizePdfs setting
	 * check entirely and falls through to the strtolower'd in_array on
	 * PROCESSABLE_EXTENSIONS below — which DOES match `pdf`, so the
	 * file is reported processable regardless of the PDF-off setting.
	 *
	 * Intended behaviour: `.PDF` should behave identically to `.pdf`
	 * with respect to the optimizePdfs setting.
	 *
	 * This test will FAIL until line 1539 is changed to
	 * `strtolower($this->getExtension()) === 'pdf'`.
	 */
	public function test_isExtensionExcluded_treats_uppercase_PDF_the_same_as_lowercase_pinned_for_deferred_fix() {
		\wpSPIO()->settings()->optimizePdfs = false;
		$path  = $this->makeSizedFile( 100, 'PDF' );
		$model = $this->makeModel( $path );

		$this->assertTrue(
			$this->invokeProtected( $model, 'isExtensionExcluded' ),
			'.PDF (uppercase) should be excluded when optimizePdfs is off — same as .pdf. The `===` check bypasses lowercasing.'
		);
	}

	/*
	 * isSizeExcluded — reads width/height, evaluates only "size"-typed rules.
	 */

	public function test_isSizeExcluded_returns_false_when_no_patterns_configured() {
		$model = $this->makeModel();

		$this->assertFalse( $this->invokeProtected( $model, 'isSizeExcluded' ) );
	}

	public function test_isSizeExcluded_ignores_non_size_type_patterns() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'name', 'value' => 'irrelevant' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'isSizeExcluded' ) );
	}

	public function test_isSizeExcluded_returns_true_and_sets_P_EXCLUDE_SIZE_when_size_rule_matches() {
		$model = $this->makeModel(); // 1×1 fixture
		// Force width and height to values that fall inside the excluded range.
		$this->setProtected( $model, 'width', 500 );
		$this->setProtected( $model, 'height', 500 );

		$model->_testPatterns = array(
			array( 'type' => 'size', 'value' => '100-1000 × 100-1000' ),
		);

		$this->assertTrue( $this->invokeProtected( $model, 'isSizeExcluded' ) );
		$this->assertSame(
			ImageModel::P_EXCLUDE_SIZE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isSizeExcluded_returns_false_when_dimensions_fall_outside_the_size_rule() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'width', 5000 );
		$this->setProtected( $model, 'height', 5000 );

		$model->_testPatterns = array(
			array( 'type' => 'size', 'value' => '100-1000 × 100-1000' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'isSizeExcluded' ) );
	}

	/*
	 * isFileSizeExcluded — reads getFileSize(), evaluates "filesize"-typed rules
	 * with `> X UNIT` / `< X UNIT` operators.
	 */

	public function test_isFileSizeExcluded_returns_false_when_no_patterns_configured() {
		$model = $this->makeModel();

		$this->assertFalse( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
	}

	public function test_isFileSizeExcluded_ignores_non_filesize_type_patterns() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'name', 'value' => 'irrelevant' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
	}

	public function test_isFileSizeExcluded_returns_true_when_gt_rule_matches_and_sets_P_EXCLUDE_FILESIZE() {
		// 2000-byte fixture, rule "> 1 KB" → 2000 > 1024 → excluded.
		$path  = $this->makeSizedFile( 2000 );
		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'filesize', 'value' => '> 1 KB' ),
		);

		$this->assertTrue( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
		$this->assertSame(
			ImageModel::P_EXCLUDE_FILESIZE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isFileSizeExcluded_returns_true_when_lt_rule_matches() {
		// 100-byte fixture, rule "< 1 KB" → 100 < 1024 → excluded.
		$path  = $this->makeSizedFile( 100 );
		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'filesize', 'value' => '< 1 KB' ),
		);

		$this->assertTrue( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
	}

	public function test_isFileSizeExcluded_returns_false_when_gt_rule_does_not_match() {
		$path  = $this->makeSizedFile( 100 );
		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'filesize', 'value' => '> 1 KB' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
	}

	public function test_isFileSizeExcluded_skips_zero_byte_files_returning_false() {
		$path  = $this->makeEmptyFile();
		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'filesize', 'value' => '> 1 KB' ),
		);

		// Sentinel: virtual / zero-byte files bail early (see the
		// `if ($filesize <= 0)` guard). A regression that skipped the
		// guard would return true or crash on unit-conversion of a
		// zero-byte file.
		$this->assertFalse( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
	}

	public function test_isFileSizeExcluded_returns_false_for_malformed_rule() {
		$path  = $this->makeSizedFile( 2000 );
		$model = $this->makeModel( $path );
		// Rule missing the unit → explode gives 2 tokens, not 3 → bails.
		$model->_testPatterns = array(
			array( 'type' => 'filesize', 'value' => '> 1' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'isFileSizeExcluded' ) );
	}

	/*
	 * checkDateExcluded — returns the first "date" rule's payload as an
	 * array. Caller is responsible for the actual date comparison.
	 */

	public function test_checkDateExcluded_returns_false_when_no_patterns_configured() {
		$model = $this->makeModel();

		$this->assertFalse( $this->invokeProtected( $model, 'checkDateExcluded' ) );
	}

	public function test_checkDateExcluded_ignores_non_date_type_patterns() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'name', 'value' => 'irrelevant' ),
		);

		$this->assertFalse( $this->invokeProtected( $model, 'checkDateExcluded' ) );
	}

	public function test_checkDateExcluded_returns_date_and_when_payload_for_date_type_rule() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'date', 'value' => '2024-01-01', 'dateWhen' => 'before' ),
		);

		$result = $this->invokeProtected( $model, 'checkDateExcluded' );

		// Sentinel: return shape is a specific 2-key array. A regression
		// that returned just the value string or the whole rule item
		// would fail the shape check.
		$this->assertSame(
			array( 'date' => '2024-01-01', 'when' => 'before' ),
			$result
		);
	}

	public function test_checkDateExcluded_returns_the_first_date_rule_when_multiple_exist() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'date', 'value' => '2024-01-01', 'dateWhen' => 'before' ),
			array( 'type' => 'date', 'value' => '2025-06-15', 'dateWhen' => 'after' ),
		);

		$result = $this->invokeProtected( $model, 'checkDateExcluded' );

		// Sentinel: first-match ordering. A regression that returned
		// the last-matched or an unordered pick would fail here.
		$this->assertSame( '2024-01-01', $result['date'] );
		$this->assertSame( 'before', $result['when'] );
	}

	// =============================================================
	// SESSION 3 — processability state machine
	// =============================================================

	/*
	 * isProcessable cache short-circuit — when processable_status is
	 * already set, the method returns without re-running any checks.
	 */

	public function test_isProcessable_short_circuits_true_when_cache_holds_P_PROCESSABLE() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_PROCESSABLE );

		// Even with an obviously-invalid file (deleted), cache says
		// processable → return true without re-checking. This is the
		// documented behaviour of the cache branch at lines 339-348.
		@unlink( $model->getFullPath() );

		$this->assertTrue( $model->isProcessable() );
	}

	public function test_isProcessable_short_circuits_false_when_cache_holds_a_non_P_PROCESSABLE_status() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_PATH );

		// Even though the file is fine, the cached exclusion status
		// wins. Sentinel-pair with the previous test.
		$this->assertFalse( $model->isProcessable() );
	}

	/*
	 * isProcessable happy path — file exists + writable + no
	 * exclusions + not optimized + not prevented → true + status cached
	 * as P_PROCESSABLE.
	 */

	public function test_isProcessable_returns_true_and_caches_P_PROCESSABLE_for_a_valid_writable_image() {
		$model = $this->makeModel(); // 1x1 png in tmp — readable + writable

		$this->assertTrue( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_PROCESSABLE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	/*
	 * isProcessable — each false-return branch pins its status side-effect.
	 * These tests document the mapping between "what went wrong" and
	 * "which P_* code gets cached", which is what getReason() surfaces
	 * to the user.
	 */

	public function test_isProcessable_returns_false_and_caches_P_FILE_NOT_EXIST_when_file_is_missing() {
		$model = $this->makeModel();
		@unlink( $model->getFullPath() );

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_FILE_NOT_EXIST,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isProcessable_returns_false_and_caches_P_IS_OPTIMIZED_when_already_optimized() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_IS_OPTIMIZED,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isProcessable_returns_false_and_caches_P_EXCLUDE_PATH_when_path_pattern_matches() {
		$model = $this->makeModel();
		$model->_testPatterns = array(
			array( 'type' => 'path', 'value' => dirname( $model->getFullPath() ) ),
		);

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_EXCLUDE_PATH,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isProcessable_returns_false_and_caches_P_EXCLUDE_EXTENSION_for_unsupported_extension() {
		$path  = $this->makeSizedFile( 100, 'xyz' );
		$model = $this->makeModel( $path );

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_EXCLUDE_EXTENSION,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isProcessable_returns_false_and_caches_P_EXCLUDE_SIZE_when_size_rule_matches() {
		$model = $this->makeModel();
		// Force width/height to values inside the excluded range.
		$this->setProtected( $model, 'width', 500 );
		$this->setProtected( $model, 'height', 500 );
		$model->_testPatterns = array(
			array( 'type' => 'size', 'value' => '100-1000 × 100-1000' ),
		);

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_EXCLUDE_SIZE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isProcessable_returns_false_and_caches_P_EXCLUDE_FILESIZE_when_filesize_rule_matches() {
		$path  = $this->makeSizedFile( 2000 );
		$model = $this->makeModel( $path );
		$model->_testPatterns = array(
			array( 'type' => 'filesize', 'value' => '> 1 KB' ),
		);

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_EXCLUDE_FILESIZE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_isProcessable_returns_false_when_isOptimizePrevented_reports_a_reason() {
		$model = $this->makeModel();
		// Anonymous class stub reads this field; any truthy value simulates
		// preventNextTry() having fired earlier with a specific reason.
		$model->_testOptimizePrevented = 'Fatal API error during last attempt';

		$this->assertFalse( $model->isProcessable() );
	}

	public function test_isProcessable_returns_false_and_caches_P_IMAGE_ZERO_SIZE_for_zero_byte_files() {
		$path  = $this->makeEmptyFile();
		$model = $this->makeModel( $path );

		$this->assertFalse( $model->isProcessable() );
		$this->assertSame(
			ImageModel::P_IMAGE_ZERO_SIZE,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	/*
	 * isProcessable pinned regression — cancelUserExclusions bug.
	 *
	 * PINNED for deferred fix — cancelUserExclusions() sets
	 * `processable_status = 0` intending to force a fresh evaluation
	 * on the next isProcessable() call. But P_PROCESSABLE === 0, so
	 * the cache short-circuit at lines 339-348 sees `0 === P_PROCESSABLE`
	 * and returns true immediately — WITHOUT re-running any of the
	 * validity checks. If between cancelUserExclusions and the next
	 * isProcessable call the file gets deleted / preventNextTry fires
	 * / a new exclusion pattern is added, isProcessable will lie.
	 *
	 * Intended behaviour: cancelUserExclusions() should set
	 * `$this->processable_status = null;` so the next isProcessable()
	 * call bypasses the cache and re-evaluates. See ImageModel.php:486.
	 *
	 * This test will FAIL until the fix ships.
	 */
	public function test_isProcessable_reevaluates_after_cancelUserExclusions_pinned_for_deferred_fix() {
		$model = $this->makeModel();
		// Seed a user-excluded state by pattern.
		$model->_testPatterns = array(
			array( 'type' => 'path', 'value' => dirname( $model->getFullPath() ) ),
		);
		$this->assertFalse( $model->isProcessable() );

		// Now cancel the user exclusion. This SHOULD force re-evaluation.
		$model->cancelUserExclusions();

		// Between cancel and the next isProcessable call, delete the file.
		// A correctly-implemented re-evaluation would notice the file
		// is gone and return false with P_FILE_NOT_EXIST.
		@unlink( $model->getFullPath() );

		$result = $model->isProcessable();

		$this->assertFalse(
			$result,
			'isProcessable returned true for a deleted file after cancelUserExclusions — the status=0 seeded by cancelUserExclusions collided with P_PROCESSABLE (=0), bypassing all re-checks.'
		);
	}

	/*
	 * isProcessableFileType — the settings-off branches always return
	 * false, regardless of the AccessModel gate.
	 */

	public function test_isProcessableFileType_returns_false_for_webp_when_createWebp_setting_is_off() {
		\wpSPIO()->settings()->createWebp = false;
		$model = $this->makeModel();

		$this->assertFalse( $model->isProcessableFileType( 'webp' ) );
	}

	public function test_isProcessableFileType_returns_false_for_avif_when_createAvif_setting_is_off() {
		\wpSPIO()->settings()->createAvif = false;
		$model = $this->makeModel();

		$this->assertFalse( $model->isProcessableFileType( 'avif' ) );
	}

	/*
	 * isProcessableAnyFileType — union of the two file-type gates.
	 * When BOTH settings are off, the answer is unambiguously false
	 * (both sub-calls return false regardless of AccessModel state).
	 */

	public function test_isProcessableAnyFileType_returns_false_when_both_webp_and_avif_settings_are_off() {
		\wpSPIO()->settings()->createWebp = false;
		\wpSPIO()->settings()->createAvif = false;
		$model = $this->makeModel();

		$this->assertFalse( $model->isProcessableAnyFileType() );
	}

	/*
	 * getProcessableReason — P_* → i18n string translator.
	 * Exhaustive case coverage so any accidental case-removal or
	 * message-text change surfaces immediately.
	 */

	public function test_getProcessableReason_returns_expected_message_for_each_documented_status() {
		$model = $this->makeModel();

		$expected = array(
			ImageModel::P_PROCESSABLE          => 'Image Processable',
			ImageModel::P_FILE_NOT_EXIST       => 'File does not exist',
			ImageModel::P_EXCLUDE_EXTENSION    => 'Image Extension not processable',
			ImageModel::P_EXCLUDE_SIZE         => 'Image Size Excluded',
			ImageModel::P_EXCLUDE_FILESIZE     => 'Image Filesize excluded',
			ImageModel::P_EXCLUDE_PATH         => 'Image Excluded',
			ImageModel::P_IS_OPTIMIZED         => 'Image is already optimized',
			ImageModel::P_BACKUPDIR_NOTWRITABLE => 'Backup directory is not writable',
			ImageModel::P_BACKUP_EXISTS        => 'Backup already exists',
			ImageModel::P_RESTORABLE           => 'Image restorable',
			ImageModel::P_BACKUP_NOT_EXISTS    => 'Backup does not exist',
			ImageModel::P_NOT_OPTIMIZED        => 'Image is not optimized',
			ImageModel::P_IMAGE_ZERO_SIZE      => 'File seems empty, or failure on image size',
			ImageModel::P_EXCLUDE_DATE         => 'Date is excluded',
		);

		foreach ( $expected as $code => $message ) {
			$this->assertSame(
				$message,
				$model->getProcessableReason( $code ),
				"getProcessableReason($code) should return '$message'"
			);
		}
	}

	public function test_getProcessableReason_falls_back_to_cached_processable_status_when_argument_is_null() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_FILE_NOT_EXIST );

		// Sentinel: null argument routes to $this->processable_status
		// (see the ternary at line 564). A regression that treated null
		// as an unknown code and hit the default would return the
		// "Unknown Issue" message instead.
		$this->assertSame(
			'File does not exist',
			$model->getProcessableReason( null )
		);
	}

	public function test_getProcessableReason_returns_unknown_issue_string_for_a_nonexistent_status_code() {
		$model = $this->makeModel();

		$result = $model->getProcessableReason( 99999 );

		// Sentinel: the default case fires — we don't assert the exact
		// text because it's a sprintf including the code, but it must
		// contain "Unknown" per the docblock's contract.
		$this->assertStringContainsString( 'Unknown', $result );
	}

	public function test_getProcessableReason_default_case_includes_the_status_code_in_the_message() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', 88888 );

		$result = $model->getProcessableReason( 88888 );

		// Sentinel: the default sprintf takes the code as arg. Confirming
		// the code makes it into the output helps operators debug
		// unexpected states from log lines.
		$this->assertStringContainsString( '88888', $result );
	}

	/*
	 * getProcessableReason pinned regression — default case reads the
	 * wrong status field.
	 *
	 * PINNED for deferred fix — the default case at line 626 reads
	 * `$this->processable_status` when it should read the `$status`
	 * argument that was passed in (or resolved from). When getReason()
	 * routes 'restorable' through here with an unknown restorable code,
	 * the default message reports the processable_status instead of
	 * the restorable_status — misleading debug info.
	 *
	 * Reproduce: seed processable_status = P_FILE_NOT_EXIST (=1),
	 * seed restorable_status = 99999 (unknown), call
	 * getReason('restorable'). The default message should mention
	 * "99999" (the restorable code). Currently it mentions "1" (the
	 * processable code) instead.
	 *
	 * This test will FAIL until line 626 uses $status instead of
	 * $this->processable_status.
	 */
	public function test_getProcessableReason_default_case_uses_the_argument_status_not_the_processable_field_pinned_for_deferred_fix() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_FILE_NOT_EXIST );
		$this->setProtected( $model, 'restorable_status', 99999 );

		$result = $model->getReason( 'restorable' );

		// Sentinel: the message should mention the RESTORABLE code (99999),
		// not the PROCESSABLE code (1 = P_FILE_NOT_EXIST). Currently
		// includes "1" because the default case dereferences the wrong
		// property.
		$this->assertStringContainsString(
			'99999',
			$result,
			'getReason(restorable) default message reports processable_status instead of the passed status — bug at ImageModel.php:626'
		);
	}

	// =============================================================
	// SESSION 4 — pipelines
	// =============================================================

	/*
	 * handleWebp — moves an API-produced temp file to the target location
	 * next to the main image.
	 */

	public function test_handleWebp_moves_temp_file_to_target_and_returns_the_target_FileModel() {
		$model = $this->makeModel(); // main image at some tmp path
		$fs    = \wpSPIO()->filesystem();

		// Create the temp source file with distinguishable content
		// so we can verify the move actually happened (not just that
		// something exists at the destination).
		$tempPath = sys_get_temp_dir() . '/spio-webp-tempfile-' . uniqid() . '.webp';
		file_put_contents( $tempPath, 'temp_webp_content_marker' );
		$this->fixtureFiles[] = $tempPath;
		$tempFile = $fs->getFile( $tempPath );

		$result = $this->invokeProtected( $model, 'handleWebp', array( $tempFile ) );

		// Return is a FileModel of the target location.
		$this->assertNotFalse( $result );
		$this->assertTrue( $result->exists(), 'target webp should exist on disk after move' );
		$this->assertFalse( file_exists( $tempPath ), 'source temp file should be gone after move' );
		// Target lives in the same dir as the main image, with .webp extension.
		$this->fixtureFiles[] = $result->getFullPath();
	}

	public function test_handleWebp_skips_copy_and_still_returns_target_when_destination_already_exists() {
		$model = $this->makeModel();
		$fs    = \wpSPIO()->filesystem();

		// Pre-create the target file. handleWebp should NOT overwrite it.
		$targetPath = dirname( $model->getFullPath() ) . '/' . pathinfo( $model->getFullPath(), PATHINFO_FILENAME ) . '.webp';
		file_put_contents( $targetPath, 'preexisting_target_content' );
		$this->fixtureFiles[] = $targetPath;

		$tempPath = sys_get_temp_dir() . '/spio-webp-tempfile-' . uniqid() . '.webp';
		file_put_contents( $tempPath, 'new_temp_content' );
		$this->fixtureFiles[] = $tempPath;
		$tempFile = $fs->getFile( $tempPath );

		$result = $this->invokeProtected( $model, 'handleWebp', array( $tempFile ) );

		$this->assertNotFalse( $result );
		// Sentinel: original target content preserved (not overwritten).
		// A regression that dropped the exists-check would overwrite.
		$this->assertSame( 'preexisting_target_content', file_get_contents( $targetPath ) );
		// The source temp file should NOT have been consumed.
		$this->assertTrue( file_exists( $tempPath ) );
	}

	public function test_handleWebp_returns_false_when_source_move_fails() {
		$model = $this->makeModel();
		$fs    = \wpSPIO()->filesystem();

		// Point tempFile at a file that DOESN'T exist. move() should
		// fail because there's nothing to move.
		$missingPath = sys_get_temp_dir() . '/spio-webp-does-not-exist-' . uniqid() . '.webp';
		$tempFile    = $fs->getFile( $missingPath );

		$result = @$this->invokeProtected( $model, 'handleWebp', array( $tempFile ) );

		// Sentinel: strict `assertSame( false, ... )`. A regression
		// that returned the target FileModel would look truthy and
		// mislead handleOptimizedFileType into recording meta for a
		// variant that doesn't exist — which is EXACTLY the shape of
		// the handleAvif bug pinned below.
		$this->assertSame( false, $result );
	}

	/*
	 * handleAvif — same shape as handleWebp, but the "move failed"
	 * branch is buggy (returns $target instead of false). Session-4
	 * pins that regression.
	 */

	public function test_handleAvif_moves_temp_file_to_target_and_returns_the_target_FileModel() {
		$model = $this->makeModel();
		$fs    = \wpSPIO()->filesystem();

		$tempPath = sys_get_temp_dir() . '/spio-avif-tempfile-' . uniqid() . '.avif';
		file_put_contents( $tempPath, 'temp_avif_content_marker' );
		$this->fixtureFiles[] = $tempPath;
		$tempFile = $fs->getFile( $tempPath );

		$result = $this->invokeProtected( $model, 'handleAvif', array( $tempFile ) );

		$this->assertNotFalse( $result );
		$this->assertTrue( $result->exists() );
		$this->assertFalse( file_exists( $tempPath ) );
		$this->fixtureFiles[] = $result->getFullPath();
	}

	/**
	 * PINNED for deferred fix — handleAvif has NO `return false;`
	 * inside its "move failed" branch. Compare against handleWebp
	 * (lines 1436-1440) which correctly returns false. handleAvif
	 * (lines 1475-1480) just logs a warning and returns $target,
	 * causing handleOptimizedFileType to record `avif` meta for a
	 * variant that doesn't exist on disk.
	 *
	 * Intended behaviour: match handleWebp — `return false;` inside
	 * the `if (! $result)` block.
	 *
	 * This test will FAIL until the fix ships.
	 */
	public function test_handleAvif_returns_false_when_source_move_fails_pinned_for_deferred_fix() {
		$model = $this->makeModel();
		$fs    = \wpSPIO()->filesystem();

		$missingPath = sys_get_temp_dir() . '/spio-avif-does-not-exist-' . uniqid() . '.avif';
		$tempFile    = $fs->getFile( $missingPath );

		$result = @$this->invokeProtected( $model, 'handleAvif', array( $tempFile ) );

		$this->assertSame(
			false,
			$result,
			'handleAvif returned $target (truthy) on move failure — no `return false;` inside the `if (! $result)` block. Compare handleWebp:1436-1440.'
		);
	}

	/*
	 * handleOptimizedFileType — dispatches to handleWebp/handleAvif for
	 * successful downloads, records FILETYPE_BIGGER sentinel for
	 * OPTIMIZED_BIGGER / NOT_COMPATIBLE statuses.
	 */

	public function test_handleOptimizedFileType_no_ops_on_empty_result() {
		$model = $this->makeModel();

		// Baseline snapshot before call.
		$webpBefore = $model->getMeta( 'webp' );
		$avifBefore = $model->getMeta( 'avif' );

		$model->handleOptimizedFileType( array() );

		// Neither webp nor avif meta should have changed.
		$this->assertSame( $webpBefore, $model->getMeta( 'webp' ) );
		$this->assertSame( $avifBefore, $model->getMeta( 'avif' ) );
	}

	public function test_handleOptimizedFileType_records_FILETYPE_BIGGER_when_webp_status_is_OPTIMIZED_BIGGER() {
		$model = $this->makeModel();

		$model->handleOptimizedFileType( array(
			'webp' => array( 'status' => \ShortPixel\Controller\Api\ApiController::STATUS_OPTIMIZED_BIGGER ),
		) );

		$this->assertSame(
			ImageModel::FILETYPE_BIGGER,
			$model->getMeta( 'webp' )
		);
	}

	public function test_handleOptimizedFileType_records_FILETYPE_BIGGER_when_avif_status_is_NOT_COMPATIBLE() {
		$model = $this->makeModel();

		$model->handleOptimizedFileType( array(
			'avif' => array( 'status' => \ShortPixel\Controller\Api\ApiController::STATUS_NOT_COMPATIBLE ),
		) );

		// Sentinel-pair with the previous test — covers BOTH webp/BIGGER
		// AND avif/NOT_COMPATIBLE branches. Regression that only checked
		// one of the two statuses would fail here.
		$this->assertSame(
			ImageModel::FILETYPE_BIGGER,
			$model->getMeta( 'avif' )
		);
	}

	public function test_handleOptimizedFileType_records_webp_meta_filename_when_download_succeeds() {
		$model = $this->makeModel();
		$fs    = \wpSPIO()->filesystem();

		// Provide a real temp file for handleWebp to move.
		$tempPath = sys_get_temp_dir() . '/spio-hoft-webp-' . uniqid() . '.webp';
		file_put_contents( $tempPath, 'webp' );
		$this->fixtureFiles[] = $tempPath;

		$model->handleOptimizedFileType( array(
			'webp' => array( 'file' => $tempPath ),
		) );

		// Meta filename should be set — the exact filename is derived
		// from the main image's basename + '.webp'.
		$webpMeta = $model->getMeta( 'webp' );
		$this->assertNotNull( $webpMeta );
		$this->assertStringEndsWith( '.webp', $webpMeta );

		$this->fixtureFiles[] = dirname( $model->getFullPath() ) . '/' . $webpMeta;
	}

	/*
	 * handleOptimized — main pipeline. Focus on paths that don't need
	 * a real BackupModel + BackupController wiring.
	 */

	public function test_handleOptimized_skips_backup_entirely_when_backupImages_setting_is_false() {
		\wpSPIO()->settings()->backupImages = false;
		$model = $this->makeModel();

		// Give a "no-copy" status so we don't need a real temp file.
		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_UNCHANGED,
				'originalSize' => 100,
			),
		);

		$result = $model->handleOptimized( $results );

		$this->assertTrue( $result );
		// Meta updated to reflect success.
		$this->assertSame( ImageModel::FILE_STATUS_SUCCESS, $model->getMeta( 'status' ) );
	}

	public function test_handleOptimized_skips_createBackup_when_args_isConverted_is_true() {
		\wpSPIO()->settings()->backupImages = true;
		$model = $this->makeModel();

		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_UNCHANGED,
				'originalSize' => 100,
			),
		);
		// isConverted=true bypasses createBackup (converter did it already).
		$result = $model->handleOptimized( $results, array( 'isConverted' => true ) );

		// Sentinel: this test would fail with a "backup couldn't be
		// created" error if isConverted didn't correctly skip the
		// createBackup path (since we don't have a real BackupModel
		// available in the test env).
		$this->assertTrue( $result );
		$this->assertSame( ImageModel::FILE_STATUS_SUCCESS, $model->getMeta( 'status' ) );
	}

	public function test_handleOptimized_respects_the_skip_backup_filter_escape_hatch() {
		\wpSPIO()->settings()->backupImages = true;
		add_filter( 'shortpixel/image/skip_backup', '__return_true' );
		$model = $this->makeModel();

		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_UNCHANGED,
				'originalSize' => 100,
			),
		);
		$result = $model->handleOptimized( $results );

		// Sentinel: the filter must actually short-circuit createBackup.
		// Without the filter (and without isConverted=true), the real
		// BackupModel path would fire and likely fail in test env.
		$this->assertTrue( $result );
	}

	public function test_handleOptimized_returns_false_when_status_needs_a_file_but_result_omits_it() {
		\wpSPIO()->settings()->backupImages = false;
		$model = $this->makeModel();

		// STATUS_SUCCESS (or anything not in the no-copy stati list)
		// with no `image.file` key should log an error and return false.
		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_SUCCESS,
				'originalSize' => 100,
				// deliberately omit 'file'
			),
		);
		$result = @$model->handleOptimized( $results );

		$this->assertFalse( $result );
	}

	public function test_handleOptimized_sets_processable_status_to_P_IS_OPTIMIZED_on_success() {
		\wpSPIO()->settings()->backupImages = false;
		$model = $this->makeModel();

		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_UNCHANGED,
				'originalSize' => 100,
			),
		);
		$model->handleOptimized( $results );

		// Sentinel: pins the "don't let this linger" cache-set at
		// line 1120. A regression that dropped that would leave the
		// next isProcessable() call re-running exclusion checks.
		$this->assertSame(
			ImageModel::P_IS_OPTIMIZED,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	public function test_handleOptimized_writes_originalSize_from_api_when_virtual_and_from_getFileSize_when_local() {
		\wpSPIO()->settings()->backupImages = false;
		$model = $this->makeModel();

		// Non-virtual (default). handleOptimized should use $this->getFileSize()
		// for originalSize, not results.image.originalSize.
		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_UNCHANGED,
				'originalSize' => 999999, // sentinel value — should be IGNORED for non-virtual
			),
		);
		$model->handleOptimized( $results );

		// getFileSize returns the fixture's actual byte size (~70 bytes for the 1×1 PNG).
		// The sentinel value 999999 must NOT appear.
		$this->assertNotSame( 999999, $model->getMeta( 'originalSize' ) );
		$this->assertGreaterThan( 0, $model->getMeta( 'originalSize' ) );
	}

	public function test_handleOptimized_writes_tsOptimized_with_the_current_timestamp_on_success() {
		\wpSPIO()->settings()->backupImages = false;
		$model = $this->makeModel();

		$before  = time();
		$results = array(
			'image' => array(
				'status'       => \ShortPixel\Controller\Api\ApiController::STATUS_UNCHANGED,
				'originalSize' => 100,
			),
		);
		$model->handleOptimized( $results );
		$after = time();

		$ts = $model->getMeta( 'tsOptimized' );
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	/*
	 * createBackup — filter escape hatch only. The main path needs a
	 * real BackupController which is integration territory.
	 */

	public function test_createBackup_returns_true_immediately_when_skip_backup_filter_returns_true() {
		add_filter( 'shortpixel/image/skip_backup', '__return_true' );
		$model = $this->makeModel();

		$result = $this->invokeProtected( $model, 'createBackup' );

		// Sentinel: `assertSame( true, ...)`. A regression that dropped
		// the filter check would fall through to `createBackupFile()`
		// which needs a real BackupModel — the test would probably
		// throw or return false in that case.
		$this->assertSame( true, $result );
	}

	public function test_createBackup_filter_receives_full_path_and_is_main_file_flag() {
		$captured = array();
		add_filter(
			'shortpixel/image/skip_backup',
			function ( $bool, $path, $isMain ) use ( &$captured ) {
				$captured[] = array( 'path' => $path, 'is_main' => $isMain );
				return true;
			},
			10,
			3
		);

		$model = $this->makeModel();
		$this->invokeProtected( $model, 'createBackup' );

		$this->assertCount( 1, $captured );
		$this->assertSame( $model->getFullPath(), $captured[0]['path'] );
		// Fixture defaults is_main_file = true (declared on the anonymous class).
		$this->assertTrue( $captured[0]['is_main'] );
	}

	/*
	 * createParamList — settings-off paths + filter escape.
	 * The smartcrop-vs-resize interaction branches need sizeDefinition
	 * data that only MediaLibraryThumbnailModel populates; skipped here.
	 */

	public function test_createParamList_returns_array_with_url_image_webp_avif_keys() {
		\wpSPIO()->settings()->resizeImages   = 0;
		\wpSPIO()->settings()->useSmartcrop   = false;
		$model = $this->makeModel();

		$args = array(
			'url'         => 'https://example.test/photo.jpg',
			'main_url'    => 'https://example.test/photo.jpg',
			'main_width'  => 100,
			'main_height' => 100,
		);
		$result = $this->invokeProtected( $model, 'createParamList', array( $args ) );

		// Sentinel: pins the return shape. Downstream API request builder
		// depends on all four keys being present.
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'image', $result );
		$this->assertArrayHasKey( 'webp', $result );
		$this->assertArrayHasKey( 'avif', $result );
	}

	public function test_createParamList_forces_smartcrop_false_for_pdf_extension() {
		$path  = $this->makeSizedFile( 100, 'pdf' );
		\wpSPIO()->settings()->optimizePdfs = true;
		\wpSPIO()->settings()->useSmartcrop = true;
		\wpSPIO()->settings()->resizeImages = 0;
		$model = $this->makeModel( $path );

		$args = array(
			'url'         => 'https://example.test/doc.pdf',
			'main_url'    => 'https://example.test/doc.pdf',
			'main_width'  => 100,
			'main_height' => 100,
		);
		$result = $this->invokeProtected( $model, 'createParamList', array( $args ) );

		// Sentinel: PDF forces useSmartcrop = false. When resizeImages
		// is also 0, `resize` ends up NOT set (or 0) in the result — no
		// resize/resize_width/resize_height keys should appear.
		$this->assertArrayNotHasKey( 'resize', $result );
	}

	public function test_createParamList_exposes_the_imageparamlist_filter_for_site_overrides() {
		\wpSPIO()->settings()->resizeImages = 0;
		\wpSPIO()->settings()->useSmartcrop = false;
		add_filter(
			'shortpixel/image/imageparamlist',
			function ( $result, $id, $imageObj ) {
				$result['override_marker'] = 'yes';
				return $result;
			},
			10,
			3
		);
		$model = $this->makeModel();

		$args = array(
			'url'         => 'https://example.test/photo.jpg',
			'main_url'    => 'https://example.test/photo.jpg',
			'main_width'  => 100,
			'main_height' => 100,
		);
		$result = $this->invokeProtected( $model, 'createParamList', array( $args ) );

		// Sentinel: the filter must be able to mutate the final result.
		// A regression that returned before applying the filter (or
		// dropped it) would fail this test.
		$this->assertSame( 'yes', $result['override_marker'] );
	}

	// =============================================================
	// SESSION 5 — tail methods
	// =============================================================

	/**
	 * Minimal stub backupModel that responds to hasBackup() and
	 * onDelete(). Used to bypass BackupController wiring in
	 * getBackupModel / onDelete / isRestorable tests.
	 */
	private function makeStubBackupModel( bool $hasBackup = false, bool $restoreResult = true ) {
		return new class( $hasBackup, $restoreResult ) {
			public $hasBackup;
			public $restoreResult;
			public $onDeleteCalled = false;
			public $restoreCalled  = false;
			public function __construct( $has, $rest ) {
				$this->hasBackup     = $has;
				$this->restoreResult = $rest;
			}
			public function hasBackup( $model ) { return $this->hasBackup; }
			public function onDelete( $model )  { $this->onDeleteCalled = true; }
			public function restore( $model )   { $this->restoreCalled = true; return $this->restoreResult; }
		};
	}

	/*
	 * getImprovement — percentage / byte-savings math.
	 */

	public function test_getImprovement_returns_false_when_image_is_not_optimized() {
		$model = $this->makeModel();
		// status = UNPROCESSED by default → isOptimized() returns false.
		$this->assertFalse( $model->getImprovement() );
	}

	public function test_getImprovement_returns_null_when_original_size_is_zero_or_negative() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 0 );
		$model->setMeta( 'compressedSize', 100 );

		$this->assertNull( $model->getImprovement() );
	}

	public function test_getImprovement_returns_null_when_optimized_size_is_zero_or_negative() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 100 );
		$model->setMeta( 'compressedSize', 0 );

		$this->assertNull( $model->getImprovement() );
	}

	public function test_getImprovement_returns_percentage_savings_by_default_two_decimal_precision() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 1000 );
		$model->setMeta( 'compressedSize', 750 );

		// (1 - 750/1000) * 100 = 25.00
		$this->assertSame( 25.00, $model->getImprovement() );
	}

	public function test_getImprovement_returns_byte_savings_when_int_arg_is_true() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 1000 );
		$model->setMeta( 'compressedSize', 400 );

		// Sentinel-pair with the percentage test — same input, different flag.
		$this->assertSame( 600, $model->getImprovement( true ) );
	}

	public function test_getImprovement_clamps_negative_result_to_zero() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		// Optimized ended up bigger (smartcrop can cause this) → negative diff.
		$model->setMeta( 'originalSize', 500 );
		$model->setMeta( 'compressedSize', 800 );

		// Docblock says negative diff is clamped to 0 (both branches).
		$this->assertSame( 0, $model->getImprovement() );
		$this->assertSame( 0, $model->getImprovement( true ) );
	}

	/*
	 * getCountOptimizeData — depends on getOptimizeData (Finding D:
	 * not declared abstract; the fixture provides a stub).
	 */

	public function test_getCountOptimizeData_returns_empty_urls_and_zero_when_optimize_data_missing_shape() {
		$model = $this->makeModel();
		$model->_testOptimizeData = array(); // missing 'params' + 'urls'

		$result = $model->getCountOptimizeData();

		// Sentinel: exact shape assertion. The return tuple is
		// [urls_array, count_int]. Downstream loop-counters depend on both.
		$this->assertSame( array( array(), 0 ), $result );
	}

	public function test_getCountOptimizeData_aliases_thumbnails_to_image_column() {
		$model = $this->makeModel();
		$model->_testOptimizeData = array(
			'params' => array(
				'medium' => array( 'image' => true, 'webp' => false ),
				'large'  => array( 'image' => true, 'webp' => true ),
			),
			'urls'   => array( 'medium' => 'https://x/m', 'large' => 'https://x/l' ),
			'paths'  => array( 'medium' => 'https://x/m', 'large' => 'https://x/l' ),
		);

		// 'thumbnails' → 'image' column. Both entries have image=true → count 2.
		list( $urls, $count ) = $model->getCountOptimizeData( 'thumbnails' );

		$this->assertSame( 2, $count );
		$this->assertCount( 2, $urls );
	}

	public function test_getCountOptimizeData_filters_webp_column_and_returns_matching_paths() {
		$model = $this->makeModel();
		$model->_testOptimizeData = array(
			'params' => array(
				'medium' => array( 'image' => true, 'webp' => false ),
				'large'  => array( 'image' => true, 'webp' => true ),
			),
			'urls'   => array( 'medium' => 'https://x/m', 'large' => 'https://x/l' ),
			'paths'  => array( 'medium' => 'https://x/m', 'large' => 'https://x/l' ),
		);

		list( $urls, $count ) = $model->getCountOptimizeData( 'webp' );

		// Sentinel-pair with the thumbnails test — different filter,
		// different count. Only 'large' has webp=true.
		$this->assertSame( 1, $count );
		$this->assertSame( array( 'https://x/l' ), $urls );
	}

	/*
	 * getImageType — webp/avif companion resolution.
	 */

	public function test_getImageType_returns_false_when_meta_is_FILETYPE_BIGGER() {
		$model = $this->makeModel();
		// Seed meta with the sentinel — no filesystem check should follow.
		$model->setMeta( 'webp', ImageModel::FILETYPE_BIGGER );

		$this->assertFalse( $this->invokeProtected( $model, 'getImageType', array( 'webp' ) ) );
	}

	public function test_getImageType_returns_FileModel_when_meta_filename_is_set() {
		$model = $this->makeModel();
		// Create a real webp file next to the main image.
		$mainDir = dirname( $model->getFullPath() );
		$webpBasename = pathinfo( $model->getFullPath(), PATHINFO_FILENAME ) . '.webp';
		$webpPath = $mainDir . '/' . $webpBasename;
		file_put_contents( $webpPath, 'webp' );
		$this->fixtureFiles[] = $webpPath;

		$model->setMeta( 'webp', $webpBasename );

		$result = $this->invokeProtected( $model, 'getImageType', array( 'webp' ) );

		$this->assertNotFalse( $result );
		$this->assertSame( $webpPath, $result->getFullPath() );
	}

	public function test_getImageType_falls_back_to_convention_path_when_no_meta_and_file_exists() {
		$model = $this->makeModel();
		// No meta set — but create a conventional .webp on disk.
		$mainDir = dirname( $model->getFullPath() );
		$fileBase = pathinfo( $model->getFullPath(), PATHINFO_FILENAME );
		$webpPath = $mainDir . '/' . $fileBase . '.webp';
		file_put_contents( $webpPath, 'webp' );
		$this->fixtureFiles[] = $webpPath;

		$result = $this->invokeProtected( $model, 'getImageType', array( 'webp' ) );

		$this->assertNotFalse( $result );
		$this->assertSame( $webpPath, $result->getFullPath() );
	}

	public function test_getImageType_returns_false_when_neither_meta_nor_convention_file_exists() {
		$model = $this->makeModel();
		// image_meta->webp is null (default). No .webp file on disk.

		$this->assertFalse(
			$this->invokeProtected( $model, 'getImageType', array( 'webp' ) )
		);
	}

	/*
	 * getBackupModel — cache branch (unset branch hits BackupController,
	 * skipped as integration territory).
	 */

	public function test_getBackupModel_returns_cached_instance_when_backupModel_property_is_set() {
		$model = $this->makeModel();
		$stub  = $this->makeStubBackupModel( true );
		$this->setProtected( $model, 'backupModel', $stub );

		// Sentinel: identity check (assertSame, not assertEquals). A
		// regression that ignored the cache and called BackupController
		// on every invocation would return a different instance.
		$this->assertSame( $stub, $model->getBackupModel() );
	}

	/*
	 * toClass — trivial image_meta delegate.
	 */

	public function test_toClass_delegates_to_image_meta_toClass() {
		$model = $this->makeModel();
		// Replace image_meta with a stub whose toClass returns a sentinel.
		$sentinel = new stdClass();
		$sentinel->marker = 'from_meta_toClass';

		$stubMeta = new class( $sentinel ) {
			public $sentinel;
			public function __construct( $s ) { $this->sentinel = $s; }
			public function toClass() { return $this->sentinel; }
		};
		$this->setProtected( $model, 'image_meta', $stubMeta );

		$result = $this->invokeProtected( $model, 'toClass' );

		$this->assertSame( $sentinel, $result );
	}

	/*
	 * setVirtualToReal — transitions the FileModel state from virtual
	 * to real by pointing at a local path.
	 */

	public function test_setVirtualToReal_updates_fullpath_and_clears_directory_and_flips_is_virtual_false() {
		$model = $this->makeModel();
		// Set up a "virtual" starting state.
		$this->setProtected( $model, 'is_virtual', true );

		$newPath = $this->makeImageFile(); // any writable local path
		$this->invokeProtected( $model, 'setVirtualToReal', array( $newPath ) );

		$this->assertSame( $newPath, $this->getProtected( $model, 'fullpath' ) );
		$this->assertNull( $this->getProtected( $model, 'directory' ) );
		$this->assertFalse( $this->getProtected( $model, 'is_virtual' ) );
	}

	/*
	 * onDelete — cleanup pass. Uses stubbed backupModel to avoid
	 * BackupController wiring; webp/avif companion cleanup uses real
	 * filesystem state.
	 */

	public function test_onDelete_calls_backupModel_onDelete() {
		$model = $this->makeModel();
		$stub  = $this->makeStubBackupModel();
		$this->setProtected( $model, 'backupModel', $stub );

		$model->onDelete();

		// Sentinel: the stub's onDeleteCalled flag flips on invocation.
		// A regression that dropped the backupModel->onDelete() line
		// would leave the flag false.
		$this->assertTrue( $stub->onDeleteCalled );
	}

	public function test_onDelete_deletes_the_webp_companion_when_it_exists_and_main_is_not_webp() {
		$model = $this->makeModel(); // .png fixture
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel() );

		// Create a companion .webp file next to the main image.
		$mainDir = dirname( $model->getFullPath() );
		$webpBase = pathinfo( $model->getFullPath(), PATHINFO_FILENAME ) . '.webp';
		$webpPath = $mainDir . '/' . $webpBase;
		file_put_contents( $webpPath, 'webp' );
		$this->fixtureFiles[] = $webpPath;
		$model->setMeta( 'webp', $webpBase );

		$this->assertTrue( file_exists( $webpPath ), 'test setup issue: webp companion should exist before onDelete' );

		$model->onDelete();

		// Sentinel: webp companion is deleted. Guard clause `!== 'webp'`
		// (main extension check) must NOT prevent this deletion — the
		// main IS .png.
		$this->assertFalse( file_exists( $webpPath ) );
	}

	/*
	 * isRestorable — state machine, testable branches only.
	 */

	public function test_isRestorable_returns_false_and_caches_P_NOT_OPTIMIZED_when_not_optimized_and_no_backup() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel( false ) );

		$result = $model->isRestorable();

		$this->assertFalse( $result );
		$this->assertSame(
			ImageModel::P_NOT_OPTIMIZED,
			$this->getProtected( $model, 'restorable_status' )
		);
	}

	public function test_isRestorable_returns_true_and_caches_P_RESTORABLE_when_backup_exists_and_file_is_writable() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel( true ) );

		$result = $model->isRestorable();

		$this->assertTrue( $result );
		$this->assertSame(
			ImageModel::P_RESTORABLE,
			$this->getProtected( $model, 'restorable_status' )
		);
	}

	public function test_isRestorable_returns_false_and_caches_P_BACKUP_NOT_EXISTS_for_virtual_file_without_backup() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$this->setProtected( $model, 'is_virtual', true );
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel( false ) );

		$result = $model->isRestorable();

		$this->assertFalse( $result );
		$this->assertSame(
			ImageModel::P_BACKUP_NOT_EXISTS,
			$this->getProtected( $model, 'restorable_status' )
		);
	}

	public function test_isRestorable_returns_true_for_virtual_file_with_backup() {
		$model = $this->makeModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$this->setProtected( $model, 'is_virtual', true );
		$this->setProtected( $model, 'backupModel', $this->makeStubBackupModel( true ) );

		$this->assertTrue( $model->isRestorable() );
	}

	/*
	 * isUserExcluded — reads processable_status, matches against the
	 * three user-exclusion codes (path, size, filesize).
	 */

	public function test_isUserExcluded_returns_true_when_status_is_P_EXCLUDE_PATH() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_PATH );

		$this->assertTrue( $model->isUserExcluded() );
	}

	public function test_isUserExcluded_returns_true_when_status_is_P_EXCLUDE_SIZE() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_SIZE );

		$this->assertTrue( $model->isUserExcluded() );
	}

	public function test_isUserExcluded_returns_true_when_status_is_P_EXCLUDE_FILESIZE() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_FILESIZE );

		$this->assertTrue( $model->isUserExcluded() );
	}

	public function test_isUserExcluded_returns_false_for_system_exclusion_codes() {
		$model = $this->makeModel();

		// P_FILE_NOT_EXIST is a system condition, not a user exclusion.
		$this->setProtected( $model, 'processable_status', ImageModel::P_FILE_NOT_EXIST );
		$this->assertFalse( $model->isUserExcluded() );

		// P_EXCLUDE_EXTENSION is also not on the user-exclusion list
		// (it's a system/hardcoded restriction).
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_EXTENSION );
		$this->assertFalse( $model->isUserExcluded() );
	}

	/*
	 * cancelUserExclusions — resets processable_status when it currently
	 * holds a user-exclusion code. NOTE: the reset value is 0 which
	 * collides with P_PROCESSABLE — pinned in session 3 as the
	 * cancelUserExclusions bug (Finding A).
	 */

	public function test_cancelUserExclusions_resets_processable_status_to_zero_when_user_excluded() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_PATH );

		$model->cancelUserExclusions();

		// The current behaviour (reset to 0) is what session 3's pinned
		// test flags as buggy. This test documents the CURRENT contract;
		// the fix will need this test updated to `assertNull` instead.
		$this->assertSame( 0, $this->getProtected( $model, 'processable_status' ) );
	}

	public function test_cancelUserExclusions_leaves_status_untouched_when_not_user_excluded() {
		$model = $this->makeModel();
		$this->setProtected( $model, 'processable_status', ImageModel::P_FILE_NOT_EXIST );

		$model->cancelUserExclusions();

		// Sentinel: system-exclusion status must survive the cancel call.
		// Regression that unconditionally cleared would break by
		// returning P_PROCESSABLE / 0 here.
		$this->assertSame(
			ImageModel::P_FILE_NOT_EXIST,
			$this->getProtected( $model, 'processable_status' )
		);
	}

	/*
	 * fs — trivial wrapper for the filesystem controller.
	 */

	public function test_fs_returns_the_shortpixel_filesystem_controller_instance() {
		$model = $this->makeModel();

		$result = $this->invokeProtected( $model, 'fs' );

		// Sentinel: identity check against the singleton. A regression
		// that constructed a fresh controller would return a different
		// instance.
		$this->assertSame( \wpSPIO()->filesystem(), $result );
	}
}
