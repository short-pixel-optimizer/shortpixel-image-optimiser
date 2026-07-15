<?php
/**
 * Tests for ShortPixel\Model\Image\MediaLibraryModel (concrete subclass of
 * MediaLibraryThumbnailModel → ImageModel → FileModel).
 *
 * SESSION 1 (done) — construction + simple accessors + collection accessors.
 *
 * SESSION 2 (done) — Meta CRUD + DB roundtrips (saveMeta + createRecord,
 * deleteMeta, hasDBRecord, cleanupDatabase safety belt, createDuplicateRecord,
 * didAnyRecordChange, resetRecordChanges, hasBackup / getBackupFile deprecated
 * shims). loadMeta + getDBMeta full paths deferred to session 5 because they
 * cascade into loadThumbnailsFromWP and checkLegacy which need a real WP
 * attachment fixture.
 *
 * SESSION 3 (done) — state machine + prevention:
 *   - cancelUserExclusions override (delegates to parent + walks thumbs +
 *     flushes optimizeData cache)
 *   - isSomethingOptimized / getSomethingOptimized (main-only + thumb-only paths)
 *   - isOptimizePrevented (post-meta read + cache + side-effects)
 *   - preventNextTry, markCompleted, resetPrevent (post-meta write + status
 *     transitions + saveMeta trigger)
 *   - isDateExcluded — 2 pinned regressions (same $options-unguarded bug
 *     as CustomImageModel::isDateExcluded, plus a MediaLib-specific
 *     get_post()-returns-null bug)
 *   - isProcessable + isRestorable overrides (delegation to parent when
 *     parent already returns true — the deep branches need real WP
 *     attachments and are deferred to session 5)
 *
 * SESSION 4 (done) — pipelines (the testable subset):
 *   - getOptimizeUrls (thin wrapper — returns array_values of urls)
 *   - getOptimizeData (empty-URL early return + caching)
 *   - getImprovements (nothing-optimized false, main-only shape, main+thumbs
 *     shape, totalpercentage rounding)
 *   - dropFromQueue (smoke test — no crash)
 *   - onDelete (smoke + delegation chain via stubs)
 *
 * SESSION 5 (done) — property getters + legacy converters + tree walkers +
 * WP metadata cleanup + 2 pinned regressions on legacyConvertStatus:
 *   - hasOriginal / getOriginalFile / getParent / returnTrue
 *   - legacyConvertType (all 4 mapping branches)
 *   - legacyConvertStatus (4 status branches + 2 PINNED bugs)
 *   - getThumbnailModel / getThumbObjects (walker over thumbs + retinas +
 *     scaled original)
 *   - removeLegacyShortPixel / removeLegacy (post-meta + WP metadata cleanup)
 *   - __debugInfo (shape sentinel)
 *
 * STILL DEFERRED (integration territory — will need a full-fixture pass):
 *   - handleOptimized full flow (200 LOC + BackupModel + WPML)
 *   - restore full flow + restoreConversion (200+ LOC)
 *   - loadMeta / getDBMeta cascade (needs real WP attachment + checkLegacy)
 *   - isProcessable / isRestorable deep branches with real thumbs
 *   - setOriginalFile (fires during construction; indirectly tested)
 *   - getWPMLDuplicates (needs WPML tables)
 *   - loadThumbnailsFromWP / addUnlisted / loadLooseItems (WP attachment fixture)
 *   - conversionPrepare / Failed / Success (need BackupModel)
 *   - migrate / checkLegacy / checkLegacyFileTypeFileName (massive; migration flow)
 *   - wpCreateImageSizes / generateThumbnails (WP thumbnail regen wiring)
 *
 * SESSION 3 (deferred) — State machine (isProcessable/isRestorable/
 * cancelUserExclusions overrides, isSomethingOptimized/getSomethingOptimized,
 * isOptimizePrevented/preventNextTry/markCompleted/resetPrevent, isDateExcluded).
 *
 * SESSION 4 (deferred) — Pipelines (getOptimizeUrls/getOptimizeData,
 * handleOptimized 200 LOC, getImprovements, restore 200+ LOC,
 * restoreConversion, onDelete, dropFromQueue).
 *
 * SESSION 5 (deferred) — Original/parent/WPML + conversion + legacy +
 * unlisted (setOriginalFile, hasOriginal, getOriginalFile, getParent,
 * getWPMLDuplicates, getThumbnailModel, loadThumbnailsFromWP,
 * conversionPrepare/Failed/Success, migrate, checkLegacy* + legacyConvert*,
 * removeLegacy*, wpCreateImageSizes, generateThumbnails, returnTrue,
 * __debugInfo, getThumbObjects, loadLooseItems, checkUnlistedForNotice,
 * addUnlisted).
 *
 * Fixture strategy: construct with `$post_id = 0` to skip the loadMeta DB
 * path. Use a real .png tmp fixture for filesystem-touching accessors.
 * The `checkUnlistedForNotice` static (`$unlistedNoticeChecked`) is reset
 * per-test via reflection so the constructor's unlisted-scan doesn't leak
 * state between tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\MediaLibraryModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Helper\InstallHelper;

class MediaLibraryModelTest extends WP_UnitTestCase {

	/** @var string[] Absolute paths of fixture files created during tests. */
	private $fixtureFiles = array();

	/** @var mixed Snapshot of the optimizeUnlisted setting. */
	private $savedOptimizeUnlisted;
	/** @var mixed Snapshot of the unlistedCounter setting. */
	private $savedUnlistedCounter;

	public function set_up() {
		parent::set_up();

		// Ensure SPIO tables exist for tests that touch shortpixel_postmeta.
		InstallHelper::checkTables();

		// Clean state per test — session 2 inserts real rows.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_postmeta' );

		// Reset the per-request `$unlistedNoticeChecked` static so
		// checkUnlistedForNotice runs its normal path in every test,
		// not just the first one.
		$ref = new ReflectionClass( MediaLibraryModel::class );
		$p   = $ref->getProperty( 'unlistedNoticeChecked' );
		$p->setAccessible( true );
		$p->setValue( null, false );

		// Snapshot the two settings checkUnlistedForNotice reads/mutates
		// so the constructor's unlisted-scan side-effects don't leak.
		$settings = \wpSPIO()->settings();
		$this->savedOptimizeUnlisted = $settings->optimizeUnlisted;
		$this->savedUnlistedCounter  = $settings->unlistedCounter;

		// Force optimizeUnlisted=true so checkUnlistedForNotice takes the
		// "already active" early return at line 3622-3623 — no counter
		// mutation, no scan, no AdminNoticesController touch.
		$settings->optimizeUnlisted = true;
	}

	public function tear_down() {
		$settings = \wpSPIO()->settings();
		$settings->optimizeUnlisted = $this->savedOptimizeUnlisted;
		$settings->unlistedCounter  = $this->savedUnlistedCounter;

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_postmeta' );

		foreach ( $this->fixtureFiles as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		$this->fixtureFiles = array();
		parent::tear_down();
	}

	/*
	 * Reflection helpers — walk the inheritance chain (MediaLibraryModel
	 * → MediaLibraryThumbnailModel → ImageModel → FileModel).
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
		$path = sys_get_temp_dir() . '/spio-medialib-test-' . uniqid() . '.' . $extension;
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
	 * Build a stub MediaLibraryModel with post_id=0 (skips loadMeta DB
	 * query) and a real .png fixture path.
	 */
	private function makeStubModel( ?string $path = null ): MediaLibraryModel {
		if ( null === $path ) {
			$path = $this->makeImageFile();
		}
		return new MediaLibraryModel( 0, $path );
	}

	/*
	 * __construct — post_id=0 stub state.
	 */

	public function test_constructor_with_post_id_zero_sets_imageType_to_IMAGE_TYPE_MAIN() {
		$model = $this->makeStubModel();

		// imageType is set AFTER the parent constructor (which overwrites it)
		// specifically for the main file. Regression that dropped the
		// re-assignment at line 121 would leave this as IMAGE_TYPE_THUMB.
		$this->assertSame(
			ImageModel::IMAGE_TYPE_MAIN,
			$this->getProtected( $model, 'imageType' )
		);
	}

	public function test_constructor_stores_the_post_id_on_the_instance() {
		$path  = $this->makeImageFile();
		$model = new MediaLibraryModel( 0, $path );

		$this->assertSame( 0, $model->get( 'id' ) );
	}

	public function test_constructor_seeds_image_meta_with_a_fresh_ImageMeta_instance() {
		$model = $this->makeStubModel();

		$meta = $model->getMeta( false );
		$this->assertInstanceOf( ImageMeta::class, $meta );
	}

	public function test_constructor_sets_type_marker_to_media() {
		$model = $this->makeStubModel();

		// The 'media' type marker is what BackupController and
		// QueueController route on — regression that dropped this would
		// silently misroute the model.
		$this->assertSame( 'media', $this->getProtected( $model, 'type' ) );
	}

	public function test_constructor_marks_instance_as_main_file() {
		$model = $this->makeStubModel();

		// is_main_file = true is what handleOptimized's `isConverted`
		// derivation + the skip_backup filter args depend on.
		$this->assertTrue( $this->getProtected( $model, 'is_main_file' ) );
	}

	/*
	 * isScaled — reflects the $is_scaled property populated by
	 * setOriginalFile() during construction. For a stub with a fresh
	 * (non-scaled) fixture the answer is false.
	 */

	public function test_isScaled_returns_false_for_a_non_scaled_fixture() {
		$model = $this->makeStubModel();

		$this->assertFalse( $model->isScaled() );
	}

	public function test_isScaled_returns_the_current_is_scaled_property_value() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'is_scaled', true );

		// Sentinel: isScaled just returns the property — a regression
		// that hardcoded the return value would fail this.
		$this->assertTrue( $model->isScaled() );
	}

	/*
	 * getImageKey — reserved-key routing for main / original / unknown.
	 */

	public function test_getImageKey_returns_the_reserved_main_key_for_main_arg() {
		$model = $this->makeStubModel();

		// The reserved keys are what the size-keyed data structures
		// (urls, paths, params) use for the main file. Pin the exact
		// value from the class const so a rename shows up here.
		$mainKey = $this->getProtected( $model, 'mainImageKey' );
		$this->assertSame( $mainKey, $model->getImageKey( 'main' ) );
	}

	public function test_getImageKey_returns_the_reserved_original_key_for_original_arg() {
		$model = $this->makeStubModel();

		$originalKey = $this->getProtected( $model, 'originalImageKey' );
		$this->assertSame( $originalKey, $model->getImageKey( 'original' ) );
	}

	public function test_getImageKey_returns_null_for_unknown_key() {
		$model = $this->makeStubModel();

		// Falling off the end of the method returns implicit null —
		// pin that contract so a regression that returned '' or the
		// arg back would fail here.
		$this->assertNull( $model->getImageKey( 'not_a_recognized_key' ) );
	}

	/*
	 * doSetting — records forceSettings AND flushes the optimize-data cache.
	 */

	public function test_doSetting_stores_key_value_pair_in_forceSettings() {
		$model = $this->makeStubModel();

		$model->doSetting( 'smartcrop', ImageModel::ACTION_SMARTCROP );

		$force = $this->getProtected( $model, 'forceSettings' );
		$this->assertSame( ImageModel::ACTION_SMARTCROP, $force['smartcrop'] );
	}

	public function test_doSetting_flushes_the_optimizeData_cache_side_effect() {
		$model = $this->makeStubModel();
		// Pre-seed a cached optimizeData so we can observe the flush.
		$this->setProtected( $model, 'optimizeData', array( 'sentinel' => 'cached' ) );

		$model->doSetting( 'smartcrop', ImageModel::ACTION_SMARTCROP );

		// Sentinel: pins the doSetting → flushOptimizeData → optimizeData=null
		// side-effect chain. Regression that dropped the flush would
		// leave stale cache data leaking into next getOptimizeData call.
		$this->assertNull( $this->getProtected( $model, 'optimizeData' ) );
	}

	/*
	 * flushOptimizeData — trivial cache clearer, but pinned separately
	 * because doSetting delegates to it.
	 */

	public function test_flushOptimizeData_clears_the_cached_optimizeData_to_null() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'optimizeData', array( 'x' => 1 ) );

		$model->flushOptimizeData();

		$this->assertNull( $this->getProtected( $model, 'optimizeData' ) );
	}

	/*
	 * count — per-type counter with $args['thumbs_only'] refinement.
	 */

	public function test_count_returns_zero_for_thumbnails_when_none_registered() {
		$model = $this->makeStubModel();
		// $thumbnails starts as [] on this class.

		$this->assertSame( 0, $model->count( 'thumbnails' ) );
	}

	public function test_count_returns_thumbnail_count_from_the_registered_array() {
		$model = $this->makeStubModel();
		// Seed a fake thumbnails map — count() reads `count($this->get('thumbnails'))`,
		// so any array of the right size works.
		$this->setProtected( $model, 'thumbnails', array(
			'medium' => new stdClass(),
			'large'  => new stdClass(),
			'full'   => new stdClass(),
		) );

		$this->assertSame( 3, $model->count( 'thumbnails' ) );
	}

	public function test_count_returns_zero_for_webps_when_no_companion_files_exist() {
		$model = $this->makeStubModel();

		$this->assertSame( 0, $model->count( 'webps' ) );
	}

	public function test_count_returns_zero_for_avifs_when_no_companion_files_exist() {
		$model = $this->makeStubModel();

		$this->assertSame( 0, $model->count( 'avifs' ) );
	}

	public function test_count_returns_zero_for_unknown_type_via_default_branch() {
		$model = $this->makeStubModel();

		// Sentinel: covers the `switch` default (no matching case →
		// $count stays 0 and returns). Regression that fell off the
		// end with an undefined variable would throw under strict handling.
		$this->assertSame( 0, $model->count( 'not_a_known_type' ) );
	}

	/*
	 * getThumbNail — lookup in the $thumbnails map. Returns null when
	 * the size doesn't exist.
	 */

	public function test_getThumbNail_returns_false_for_a_size_not_in_the_thumbnails_map() {
		$model = $this->makeStubModel();

		// Sentinel: strict `assertFalse` (not `assertNull`). The method
		// returns `false` via the `return false;` fall-through at line
		// 1829 — a regression that changed to null would still be
		// falsy but wouldn't match downstream `=== false` callsites.
		$this->assertFalse( $model->getThumbNail( 'nonexistent-size' ) );
	}

	public function test_getThumbNail_returns_the_registered_thumbnail_object_for_a_known_size() {
		$model = $this->makeStubModel();
		$sentinel = new stdClass();
		$sentinel->marker = 'test-only-thumb';
		$this->setProtected( $model, 'thumbnails', array( 'medium' => $sentinel ) );

		// Sentinel: identity check (assertSame) — pins that getThumbNail
		// returns the exact stored object, not a clone or copy.
		$this->assertSame( $sentinel, $model->getThumbNail( 'medium' ) );
	}

	/*
	 * getWebps / getAvifs / getRetinas — protected collection accessors.
	 * For a stub with no companion files these return empty arrays.
	 */

	public function test_getWebps_returns_empty_array_when_no_companion_files_exist() {
		$model = $this->makeStubModel();

		$result = $this->invokeProtected( $model, 'getWebps' );

		$this->assertSame( array(), $result );
	}

	public function test_getAvifs_returns_empty_array_when_no_companion_files_exist() {
		$model = $this->makeStubModel();

		$result = $this->invokeProtected( $model, 'getAvifs' );

		// Sentinel-pair with getWebps — same shape, different companion type.
		$this->assertSame( array(), $result );
	}

	public function test_getRetinas_returns_empty_array_when_no_retinas_exist() {
		$model = $this->makeStubModel();

		$result = $this->invokeProtected( $model, 'getRetinas' );

		$this->assertSame( array(), $result );
	}

	/*
	 * getWPMetaData — delegates to wp_get_attachment_metadata for the
	 * post_id. For post_id=0 (stub) this returns false; the method
	 * caches on $this->wp_metadata.
	 */

	public function test_getWPMetaData_returns_something_falsy_for_stub_post_id_zero() {
		$model = $this->makeStubModel();

		$result = $model->getWPMetaData();

		// wp_get_attachment_metadata(0) → false. Regression that
		// eagerly returned an empty array (instead of false) would
		// mislead callers checking `if ($result === false)`.
		$this->assertFalse( $result );
	}

	// =============================================================
	// SESSION 2 — Meta CRUD + DB roundtrips
	// =============================================================

	/**
	 * Test-only attach_id sentinel — high enough that real fixtures
	 * generated by WP factories won't collide.
	 */
	private const TEST_ATTACH_ID = 9990001;

	/**
	 * Build a stub with a distinct attach_id (via reflection) so DB
	 * tests can insert/query without colliding with the post_id=0 stub
	 * that other tests use.
	 */
	private function makeModelWithId( int $attachId, ?string $path = null ): MediaLibraryModel {
		$model = $this->makeStubModel( $path );
		$this->setProtected( $model, 'id', $attachId );
		return $model;
	}

	private function postmetaTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'shortpixel_postmeta';
	}

	private function countRowsFor( int $attachId ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->postmetaTable() . ' WHERE attach_id = %d', $attachId )
		);
	}

	/*
	 * saveMeta / createRecord — INSERT path via the shortpixel_postmeta table.
	 * saveMeta delegates to saveDBMeta which calls createRecord for the main
	 * file (plus every thumbnail via getThumbObjects). With an empty
	 * $thumbnails array the only inserted row is the main.
	 */

	public function test_saveMeta_inserts_a_single_row_for_the_main_file_when_no_thumbnails() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$model->saveMeta();

		$this->assertSame( 1, $this->countRowsFor( self::TEST_ATTACH_ID ) );
	}

	public function test_saveMeta_INSERT_captures_the_databaseID_onto_image_meta() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$model->saveMeta();

		// Sentinel: the main-file INSERT path at createRecord line 1552
		// writes `$wpdb->insert_id` back onto image_meta->databaseID
		// via setMeta. A regression that dropped this would leave the
		// next save trying to INSERT again instead of UPDATE.
		$dbId = $model->getMeta( 'databaseID' );
		$this->assertGreaterThan( 0, $dbId );
	}

	public function test_saveMeta_creates_row_with_correct_attach_id_and_image_type_main() {
		global $wpdb;
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$model->saveMeta();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT attach_id, image_type, parent, size FROM ' . $this->postmetaTable() . ' WHERE attach_id = %d',
				self::TEST_ATTACH_ID
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( self::TEST_ATTACH_ID, (int) $row->attach_id );
		$this->assertSame( ImageModel::IMAGE_TYPE_MAIN, (int) $row->image_type );
		// Sentinel: main-file rows have parent=0 (see line 1481) and
		// size=NULL (line 1443). Regression that swapped these would
		// break getDBMeta's ORDER BY parent ASC routing.
		$this->assertSame( 0, (int) $row->parent );
		$this->assertNull( $row->size );
	}

	/*
	 * deleteMeta — removes every row for this attach_id.
	 */

	public function test_deleteMeta_removes_all_rows_for_this_attach_id() {
		global $wpdb;
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->saveMeta();
		$this->assertSame( 1, $this->countRowsFor( self::TEST_ATTACH_ID ), 'test setup: row should exist' );

		$model->deleteMeta();

		$this->assertSame( 0, $this->countRowsFor( self::TEST_ATTACH_ID ) );
	}

	public function test_deleteMeta_does_not_affect_other_attach_ids() {
		global $wpdb;
		// Seed a row for a different attach_id.
		$other = self::TEST_ATTACH_ID + 1;
		$wpdb->insert(
			$this->postmetaTable(),
			array(
				'attach_id'  => $other,
				'parent'     => 0,
				'image_type' => ImageModel::IMAGE_TYPE_MAIN,
				'status'     => 0,
			),
			array( '%d', '%d', '%d', '%d' )
		);

		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->saveMeta();
		$model->deleteMeta();

		// Sentinel: verify sibling rows survive. A regression that
		// dropped the `WHERE attach_id = %s` filter (turning deleteMeta
		// into a table wipe) would fail here.
		$this->assertSame( 1, $this->countRowsFor( $other ) );
	}

	/*
	 * hasDBRecord — probes shortpixel_postmeta for a MAIN-typed row.
	 */

	public function test_hasDBRecord_returns_false_when_no_row_exists_for_the_attach_id() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$this->assertFalse( $model->hasDBRecord() );
	}

	public function test_hasDBRecord_returns_true_after_saveMeta_creates_the_main_row() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->saveMeta();

		$this->assertTrue( $model->hasDBRecord() );
	}

	public function test_hasDBRecord_returns_false_when_only_thumbnail_rows_exist_without_a_main_row() {
		global $wpdb;
		// Insert a thumb-only row (size != NULL, image_type=THUMB).
		$wpdb->insert(
			$this->postmetaTable(),
			array(
				'attach_id'  => self::TEST_ATTACH_ID,
				'parent'     => self::TEST_ATTACH_ID,
				'image_type' => ImageModel::IMAGE_TYPE_THUMB,
				'size'       => 'medium',
				'status'     => 0,
			),
			array( '%d', '%d', '%d', '%s', '%d' )
		);

		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		// Sentinel: hasDBRecord specifically queries for
		// `size IS NULL AND image_type = MAIN` (line 2815). A regression
		// that dropped either filter would return true here.
		$this->assertFalse( $model->hasDBRecord() );
	}

	/*
	 * cleanupDatabase — safety belt against wiping every row when the
	 * $records list is empty.
	 */

	public function test_cleanupDatabase_bails_out_without_deleting_when_records_is_empty() {
		global $wpdb;
		// Seed two rows for this attach_id.
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->saveMeta();
		$wpdb->insert(
			$this->postmetaTable(),
			array(
				'attach_id'  => self::TEST_ATTACH_ID,
				'parent'     => self::TEST_ATTACH_ID,
				'image_type' => ImageModel::IMAGE_TYPE_THUMB,
				'size'       => 'thumbnail',
				'status'     => 0,
			),
			array( '%d', '%d', '%d', '%s', '%d' )
		);
		$this->assertSame( 2, $this->countRowsFor( self::TEST_ATTACH_ID ), 'test setup: 2 rows should exist' );

		// Invoke cleanupDatabase with an empty records list.
		$this->invokeProtected( $model, 'cleanupDatabase', array( array() ) );

		// Sentinel: the safety belt at lines 1627-1629 MUST bail out.
		// A regression that ran the DELETE with no `id NOT IN (...)`
		// exclusions would wipe both rows here.
		$this->assertSame( 2, $this->countRowsFor( self::TEST_ATTACH_ID ) );
	}

	public function test_cleanupDatabase_deletes_rows_not_in_the_kept_records_list() {
		global $wpdb;
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->saveMeta();
		$mainId = (int) $model->getMeta( 'databaseID' );

		// Insert an "orphan" row that saveMeta didn't produce.
		$wpdb->insert(
			$this->postmetaTable(),
			array(
				'attach_id'  => self::TEST_ATTACH_ID,
				'parent'     => self::TEST_ATTACH_ID,
				'image_type' => ImageModel::IMAGE_TYPE_THUMB,
				'size'       => 'orphaned-size',
				'status'     => 0,
			),
			array( '%d', '%d', '%d', '%s', '%d' )
		);
		$this->assertSame( 2, $this->countRowsFor( self::TEST_ATTACH_ID ) );

		// cleanupDatabase with only the main id in the "keep" list —
		// the orphan should be deleted.
		$this->invokeProtected( $model, 'cleanupDatabase', array( array( $mainId ) ) );

		$this->assertSame( 1, $this->countRowsFor( self::TEST_ATTACH_ID ) );
	}

	/*
	 * createDuplicateRecord — inserts an IMAGE_TYPE_DUPLICATE stub linking
	 * a duplicate attachment to the "parent" (this model).
	 */

	public function test_createDuplicateRecord_inserts_a_duplicate_row_pointing_at_the_parent() {
		global $wpdb;
		$parentId = self::TEST_ATTACH_ID;
		$dupId    = self::TEST_ATTACH_ID + 10;
		$model    = $this->makeModelWithId( $parentId );

		$this->invokeProtected( $model, 'createDuplicateRecord', array( $dupId ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT attach_id, parent, image_type FROM ' . $this->postmetaTable() . ' WHERE attach_id = %d',
				$dupId
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( $dupId, (int) $row->attach_id );
		$this->assertSame( $parentId, (int) $row->parent );
		$this->assertSame( ImageModel::IMAGE_TYPE_DUPLICATE, (int) $row->image_type );
	}

	public function test_createDuplicateRecord_sets_parent_property_on_the_model() {
		$parentId = self::TEST_ATTACH_ID;
		$dupId    = self::TEST_ATTACH_ID + 20;
		$model    = $this->makeModelWithId( $parentId );

		$this->invokeProtected( $model, 'createDuplicateRecord', array( $dupId ) );

		// Sentinel: the method also flips imageType and stores the
		// parent id on $this so subsequent loadMeta() calls route
		// correctly (line 1604-1606).
		$this->assertSame( $parentId, $this->getProtected( $model, 'parent' ) );
		$this->assertSame(
			ImageModel::IMAGE_TYPE_DUPLICATE,
			$this->getProtected( $model, 'imageType' )
		);
	}

	/*
	 * didAnyRecordChange / resetRecordChanges — walk main + thumbnails.
	 */

	public function test_didAnyRecordChange_returns_false_on_a_fresh_model_with_no_changes() {
		$model = $this->makeStubModel();

		$this->assertFalse( $this->invokeProtected( $model, 'didAnyRecordChange' ) );
	}

	public function test_didAnyRecordChange_returns_true_when_the_main_recordChanged_flag_is_set() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'recordChanged', true );

		$this->assertTrue( $this->invokeProtected( $model, 'didAnyRecordChange' ) );
	}

	public function test_resetRecordChanges_clears_the_main_recordChanged_flag() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'recordChanged', true );

		$this->invokeProtected( $model, 'resetRecordChanges' );

		$this->assertFalse( $this->getProtected( $model, 'recordChanged' ) );
	}

	/*
	 * hasBackup / getBackupFile — deprecated shims that log a warning
	 * and delegate to the stubbed backupModel.
	 */

	public function test_hasBackup_delegates_to_backupModel_hasBackup_and_returns_its_value() {
		$model = $this->makeStubModel();
		$stubBackup = new class {
			public function hasBackup( $m ) { return 'sentinel-hasBackup-return'; }
		};
		$this->setProtected( $model, 'backupModel', $stubBackup );

		// Sentinel: identity between stub return and shim return.
		// Regression that dropped the delegation would break the
		// backwards-compat contract.
		$this->assertSame( 'sentinel-hasBackup-return', $model->hasBackup() );
	}

	public function test_getBackupFile_returns_false_when_backupModel_hasBackup_returns_a_non_object() {
		$model = $this->makeStubModel();
		$stubBackup = new class {
			// hasBackup returns non-object → getBackupFile returns false per line 2469-2472
			public function hasBackup( $m ) { return false; }
		};
		$this->setProtected( $model, 'backupModel', $stubBackup );

		$this->assertFalse( $model->getBackupFile() );
	}

	public function test_getBackupFile_returns_the_file_object_when_backupModel_hasBackup_returns_one() {
		$model = $this->makeStubModel();
		$fileSentinel = new stdClass();
		$fileSentinel->marker = 'sentinel-backup-file';

		$stubBackup = new class( $fileSentinel ) {
			public $file;
			public function __construct( $f ) { $this->file = $f; }
			public function hasBackup( $m ) { return $this->file; }
		};
		$this->setProtected( $model, 'backupModel', $stubBackup );

		$this->assertSame( $fileSentinel, $model->getBackupFile() );
	}

	// =============================================================
	// SESSION 3 — state machine + prevention
	// =============================================================

	/**
	 * Build a stub thumbnail object that tracks whether isOptimized() /
	 * cancelUserExclusions() were called and lets tests configure the
	 * isOptimized return value. Enough shape for isSomethingOptimized,
	 * getSomethingOptimized, and cancelUserExclusions to walk it.
	 */
	private function makeStubThumb( bool $optimizedReturn = false ) {
		return new class( $optimizedReturn ) {
			public $optimizedReturn;
			public $cancelUserExclusionsCalled = false;
			public function __construct( $r ) { $this->optimizedReturn = $r; }
			public function isOptimized() { return $this->optimizedReturn; }
			public function cancelUserExclusions() { $this->cancelUserExclusionsCalled = true; }
			public function didRecordChange() { return false; }
			public function recordChanged( $bool ) {}
			public function get( $key ) { return null; }
			public function toClass() { return new stdClass(); }
		};
	}

	/*
	 * cancelUserExclusions override — delegates to parent, walks thumbs,
	 * flushes optimizeData cache.
	 */

	public function test_cancelUserExclusions_flushes_the_optimizeData_cache() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'optimizeData', array( 'sentinel' => 'cached' ) );

		$model->cancelUserExclusions();

		// Sentinel: pins the final `$this->optimizeData = null;` line at 175.
		// Regression that dropped the flush would leave stale optimize
		// data cached across the exclusion-cancel boundary.
		$this->assertNull( $this->getProtected( $model, 'optimizeData' ) );
	}

	public function test_cancelUserExclusions_walks_thumbnails_and_calls_cancelUserExclusions_on_each() {
		$model = $this->makeStubModel();
		$thumb1 = $this->makeStubThumb();
		$thumb2 = $this->makeStubThumb();
		$this->setProtected( $model, 'thumbnails', array( 'medium' => $thumb1, 'large' => $thumb2 ) );

		$model->cancelUserExclusions();

		// Sentinel-pair: BOTH thumbnails must be visited. A regression
		// that only visited the first (e.g. broke out of the loop early)
		// or only the last would fail on the other.
		$this->assertTrue( $thumb1->cancelUserExclusionsCalled );
		$this->assertTrue( $thumb2->cancelUserExclusionsCalled );
	}

	/*
	 * isSomethingOptimized — walks main + thumbs, one optimized is enough.
	 */

	public function test_isSomethingOptimized_returns_false_when_nothing_is_optimized() {
		$model = $this->makeStubModel();
		// Fresh model: main is UNPROCESSED, no thumbnails registered.

		$this->assertFalse( $model->isSomethingOptimized() );
	}

	public function test_isSomethingOptimized_returns_true_when_the_main_file_is_optimized() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$this->assertTrue( $model->isSomethingOptimized() );
	}

	public function test_isSomethingOptimized_returns_true_when_only_a_thumbnail_is_optimized() {
		$model = $this->makeStubModel();
		// Main stays UNPROCESSED; a single thumb reports optimized.
		$this->setProtected( $model, 'thumbnails', array(
			'medium' => $this->makeStubThumb( true ),
		) );

		// Sentinel: the "main isn't optimized but a thumb is" branch
		// exists specifically because users can exclude the main file
		// while a previously-optimized thumb remains. Regression that
		// short-circuited on main-only isOptimized would fail here.
		$this->assertTrue( $model->isSomethingOptimized() );
	}

	/*
	 * getSomethingOptimized — returns $this if main is optimized, else the
	 * first optimized thumbnail, else false.
	 */

	public function test_getSomethingOptimized_returns_false_when_nothing_is_optimized() {
		$model = $this->makeStubModel();

		$this->assertFalse( $model->getSomethingOptimized() );
	}

	public function test_getSomethingOptimized_returns_self_identity_when_main_is_optimized() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		// Sentinel: identity check (assertSame). A regression that
		// returned a fresh clone or the meta object would still be
		// truthy but fail the identity.
		$this->assertSame( $model, $model->getSomethingOptimized() );
	}

	public function test_getSomethingOptimized_returns_the_first_optimized_thumbnail_when_main_is_not_optimized() {
		$model = $this->makeStubModel();
		// Two thumbs — first NOT optimized, second IS. Should return the second.
		$unoptimized = $this->makeStubThumb( false );
		$optimized   = $this->makeStubThumb( true );
		$this->setProtected( $model, 'thumbnails', array(
			'medium' => $unoptimized,
			'large'  => $optimized,
		) );

		$this->assertSame( $optimized, $model->getSomethingOptimized() );
	}

	/*
	 * isOptimizePrevented — reads `_shortpixel_prevent_optimize` post meta,
	 * caches on $optimizePrevented, sets processable_status +
	 * optimizePreventedReason side-effects on hit.
	 */

	public function test_isOptimizePrevented_returns_false_when_no_prevent_meta_is_set() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		// No _shortpixel_prevent_optimize meta on this attach_id.

		$this->assertFalse( $model->isOptimizePrevented() );
	}

	public function test_isOptimizePrevented_returns_true_when_the_prevent_meta_is_set() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		update_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', 'fatal error last attempt' );

		$this->assertTrue( $model->isOptimizePrevented() );

		// Cleanup — set_up/tear_down doesn't touch post meta for this id.
		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	public function test_isOptimizePrevented_sets_processable_status_and_reason_side_effects_on_positive_hit() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		update_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', 'sentinel-reason-xyz' );

		$model->isOptimizePrevented();

		$this->assertSame(
			ImageModel::P_OPTIMIZE_PREVENTED,
			$this->getProtected( $model, 'processable_status' )
		);
		// Sentinel-pair: BOTH the status AND the reason must be set,
		// because getProcessableReason() reads the reason for the
		// P_OPTIMIZE_PREVENTED case (see ImageModel::getProcessableReason).
		$this->assertSame(
			'sentinel-reason-xyz',
			$this->getProtected( $model, 'optimizePreventedReason' )
		);

		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	public function test_isOptimizePrevented_uses_the_cache_and_skips_the_post_meta_read_on_second_call() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		// Pre-seed the cache with a fixed value.
		$this->setProtected( $model, 'optimizePrevented', false );

		// Even if post meta says "yes prevented", the cache wins.
		update_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', 'sentinel' );

		$this->assertFalse( $model->isOptimizePrevented() );

		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	/*
	 * preventNextTry — writes post meta, sets image_meta->status, persists.
	 */

	public function test_preventNextTry_writes_reason_to_shortpixel_prevent_optimize_post_meta() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$this->invokeProtected( $model, 'preventNextTry', array( 'reason string' ) );

		$this->assertSame(
			'reason string',
			get_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', true )
		);

		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	public function test_preventNextTry_sets_status_meta_to_FILE_STATUS_PREVENT_by_default() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$this->invokeProtected( $model, 'preventNextTry', array( 'reason' ) );

		$this->assertSame( ImageModel::FILE_STATUS_PREVENT, $model->getMeta( 'status' ) );

		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	public function test_preventNextTry_honours_custom_status_argument() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$this->invokeProtected(
			$model,
			'preventNextTry',
			array( 'done', ImageModel::FILE_STATUS_MARKED_DONE )
		);

		// Sentinel: default is FILE_STATUS_PREVENT (-10); explicit override
		// to FILE_STATUS_MARKED_DONE (-11) proves the second arg reaches the
		// setMeta call.
		$this->assertSame( ImageModel::FILE_STATUS_MARKED_DONE, $model->getMeta( 'status' ) );

		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	/*
	 * markCompleted — thin wrapper around preventNextTry with a custom status.
	 */

	public function test_markCompleted_delegates_to_preventNextTry_with_the_passed_status() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$model->markCompleted( 'user-marked complete', ImageModel::FILE_STATUS_MARKED_DONE );

		$this->assertSame( 'user-marked complete', get_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', true ) );
		$this->assertSame( ImageModel::FILE_STATUS_MARKED_DONE, $model->getMeta( 'status' ) );

		delete_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize' );
	}

	/*
	 * resetPrevent — deletes post meta + resets negative status +
	 * clears optimizePrevented cache.
	 */

	public function test_resetPrevent_deletes_the_shortpixel_prevent_optimize_post_meta() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		update_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', 'was prevented' );
		$this->assertNotEmpty(
			get_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', true ),
			'test setup: post meta should exist'
		);

		$model->resetPrevent();

		$this->assertSame(
			'',
			get_post_meta( self::TEST_ATTACH_ID, '_shortpixel_prevent_optimize', true )
		);
	}

	public function test_resetPrevent_transitions_negative_status_back_to_UNPROCESSED_and_saves() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->setMeta( 'status', ImageModel::FILE_STATUS_PREVENT );

		$model->resetPrevent();

		// Sentinel: negative statuses (< 0) get reset to UNPROCESSED
		// so the queue picks the item up again. Regression that dropped
		// the `< 0` guard would leave FILE_STATUS_SUCCESS records
		// wrongly reset to UNPROCESSED after a resetPrevent call.
		$this->assertSame( ImageModel::FILE_STATUS_UNPROCESSED, $model->getMeta( 'status' ) );
	}

	public function test_resetPrevent_does_not_touch_status_when_it_is_non_negative() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );

		$model->resetPrevent();

		// Sentinel-pair with the previous test: SUCCESS (positive)
		// must survive resetPrevent. A regression that unconditionally
		// wrote UNPROCESSED would fail here.
		$this->assertSame( ImageModel::FILE_STATUS_SUCCESS, $model->getMeta( 'status' ) );
	}

	public function test_resetPrevent_clears_the_optimizePrevented_cache() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		$this->setProtected( $model, 'optimizePrevented', true );

		$model->resetPrevent();

		$this->assertNull( $this->getProtected( $model, 'optimizePrevented' ) );
	}

	/*
	 * isDateExcluded — two pinned regressions.
	 *
	 * Both are the same shape as the CustomImageModel::isDateExcluded
	 * pinned bug — dereferencing return values without checking their
	 * type first.
	 */

	/**
	 * PINNED for deferred fix — MediaLibraryModel::isDateExcluded at line
	 * 2392 calls `checkDateExcluded()` and then dereferences
	 * `$options['date']` at line 2400 without checking that
	 * checkDateExcluded() returned an array (it returns false when no
	 * date rule matches).
	 *
	 * Same bug as CustomImageModel::isDateExcluded pinned in session 2
	 * of CustomImageModel tests. When called via isProcessable the outer
	 * `false !== checkDateExcluded()` guard protects — but direct calls
	 * (from subclasses / integration) fatal.
	 *
	 * Intended behaviour: return false safely when checkDateExcluded()
	 * returns false.
	 */
	public function test_isDateExcluded_does_not_crash_when_no_date_rule_configured_pinned_for_deferred_fix() {
		// Create a real post so get_post() returns a real object — we
		// want to isolate the checkDateExcluded=false branch.
		$postId = $this->factory->post->create();
		$model  = $this->makeModelWithId( $postId );

		try {
			$result = $this->invokeProtected( $model, 'isDateExcluded' );
			$this->assertSame(
				false,
				$result,
				'isDateExcluded should return false safely when no date rule exists.'
			);
		} catch ( \Throwable $t ) {
			$this->fail(
				'isDateExcluded threw on no-date-rule state — checkDateExcluded() returned false but the method tried $options["date"] anyway. Bug at MediaLibraryModel.php:2400. Message: ' . $t->getMessage()
			);
		} finally {
			wp_delete_post( $postId, true );
		}
	}

	/**
	 * PINNED for deferred fix — MediaLibraryModel::isDateExcluded at line
	 * 2394-2395 calls `get_post($this->id)` and then reads
	 * `$post->post_date` without checking that `$post` is an object.
	 * When `$this->id` doesn't resolve to an existing post (deleted
	 * attachment, race, stub with id=0), `get_post` returns null and the
	 * property read fatals under strict handling.
	 *
	 * Intended behaviour: return false safely when the post doesn't exist.
	 *
	 * This is a MediaLibrary-specific bug — CustomImageModel doesn't have
	 * this shape because it reads timestamps from image_meta directly.
	 */
	public function test_isDateExcluded_does_not_crash_when_post_does_not_exist_pinned_for_deferred_fix() {
		// Use a post_id that definitely doesn't resolve to any real post.
		$model = $this->makeModelWithId( 999999999 );

		try {
			$result = $this->invokeProtected( $model, 'isDateExcluded' );
			$this->assertSame(
				false,
				$result,
				'isDateExcluded should return false safely when the post does not exist.'
			);
		} catch ( \Throwable $t ) {
			$this->fail(
				'isDateExcluded threw when the post does not exist — get_post() returned null but the method tried $post->post_date anyway. Bug at MediaLibraryModel.php:2394-2395. Message: ' . $t->getMessage()
			);
		}
	}

	// =============================================================
	// SESSION 4 — pipelines (testable subset)
	// =============================================================

	/**
	 * Build a stub thumbnail with configurable getImprovement returns.
	 * Used for getImprovements tests that need thumbnail improvement math.
	 */
	private function makeImprovementThumb( bool $optimized, ?float $perc, ?int $size, string $name = 'thumb' ) {
		return new class( $optimized, $perc, $size, $name ) {
			public $optimized;
			public $perc;
			public $size;
			public $name;
			public function __construct( $o, $p, $s, $n ) {
				$this->optimized = $o;
				$this->perc      = $p;
				$this->size      = $s;
				$this->name      = $n;
			}
			public function isOptimized() { return $this->optimized; }
			public function getImprovement( $int = false ) {
				return $int ? $this->size : $this->perc;
			}
		};
	}

	/*
	 * getOptimizeUrls — thin wrapper around getOptimizeData()['urls'].
	 */

	public function test_getOptimizeUrls_returns_empty_array_when_no_processable_targets_exist() {
		$model = $this->makeStubModel();
		// wp_get_attachment_url(0) returns the site URL (not false) in the
		// WP test env, so the "empty URL" early exit at getOptimizeData:231
		// doesn't trigger. Instead force the not-processable path via the
		// isProcessable cache — then the main-URL-add branch at line 250
		// skips and `urls` stays empty.
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_PATH );

		$result = $model->getOptimizeUrls();

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	/*
	 * getOptimizeData — empty-URL / not-processable path + cache behaviour.
	 */

	public function test_getOptimizeData_returns_empty_parameters_shape_when_nothing_is_processable() {
		$model = $this->makeStubModel();
		// Same rationale as the getOptimizeUrls test above — force the
		// not-processable branch so `urls` stays empty regardless of
		// whether the site URL is reachable.
		$this->setProtected( $model, 'processable_status', ImageModel::P_EXCLUDE_PATH );

		$result = $model->getOptimizeData();

		// Shape sentinel: the pre-initialised parameters array has
		// `urls`, `params`, and `returnParams` keys but no populated entries.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'urls', $result );
		$this->assertArrayHasKey( 'params', $result );
		$this->assertArrayHasKey( 'returnParams', $result );
		$this->assertSame( array(), $result['urls'] );
		$this->assertSame( array(), $result['params'] );
	}

	public function test_getOptimizeData_caches_the_computed_result_on_optimizeData() {
		$model = $this->makeStubModel();
		// Pre-seed a fake cached value so we can observe the cache hit.
		$sentinel = array( 'urls' => array( 'cached' => 'sentinel_url' ), 'params' => array(), 'returnParams' => array() );
		$this->setProtected( $model, 'optimizeData', $sentinel );

		$result = $model->getOptimizeData();

		// Sentinel: identity match on the cached array. The method
		// returns cached data when `optimizeData` is not null AND
		// include_thumbs is true (see line 209). A regression that
		// dropped the cache check would recompute and produce empty urls.
		$this->assertSame( $sentinel, $result );
	}

	/*
	 * getImprovements — walks main + thumbnails, aggregates percentage +
	 * size totals, returns the MediaLibrary payload shape.
	 */

	public function test_getImprovements_returns_false_when_nothing_is_optimized() {
		$model = $this->makeStubModel();

		$this->assertFalse( $model->getImprovements() );
	}

	public function test_getImprovements_returns_main_entry_when_only_main_is_optimized() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 1000 );
		$model->setMeta( 'compressedSize', 750 );
		// getImprovement (percentage) → 25.00; getImprovement(true) → 250.

		$result = $model->getImprovements();

		$this->assertArrayHasKey( 'main', $result );
		$this->assertArrayHasKey( 'totalpercentage', $result );
		$this->assertArrayHasKey( 'totalsize', $result );
		// main tuple: [percentage, byte-savings].
		$this->assertSame( 25.00, $result['main'][0] );
		$this->assertSame( 250, $result['main'][1] );
		// totalsize matches the byte-savings for a single-item family.
		$this->assertSame( 250, $result['totalsize'] );
		// totalpercentage = round(perc / count) — with count=1, it's round(25).
		$this->assertSame( 25.0, $result['totalpercentage'] );
	}

	public function test_getImprovements_includes_thumbnails_entry_when_a_thumb_is_optimized() {
		$model = $this->makeStubModel();
		// Main NOT optimized; single thumb IS.
		$this->setProtected( $model, 'thumbnails', array(
			'medium' => $this->makeImprovementThumb( true, 40.0, 800, 'medium' ),
		) );

		$result = $model->getImprovements();

		// Sentinel: thumbnails entry is created only when at least one
		// thumb is optimized. A regression that pre-created the key
		// would fail the "not present when nothing optimized" test above.
		$this->assertArrayHasKey( 'thumbnails', $result );
		$this->assertArrayHasKey( 'medium', $result['thumbnails'] );
		$this->assertSame( array( 40.0, 800 ), $result['thumbnails']['medium'] );
		$this->assertSame( 800, $result['totalsize'] );
	}

	public function test_getImprovements_averages_totalpercentage_across_main_and_thumbnails() {
		$model = $this->makeStubModel();
		// Main: 30% / 300 bytes
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 1000 );
		$model->setMeta( 'compressedSize', 700 );
		// Thumb: 60% / 600 bytes
		$this->setProtected( $model, 'thumbnails', array(
			'medium' => $this->makeImprovementThumb( true, 60.0, 600, 'medium' ),
		) );

		$result = $model->getImprovements();

		// Sentinel: aggregation math. total percentage = round((30 + 60) / 2) = 45.
		// A regression that summed instead of averaged would produce 90.
		$this->assertSame( 45.0, $result['totalpercentage'] );
		// totalsize is a SUM, not average: 300 + 600 = 900.
		$this->assertSame( 900, $result['totalsize'] );
	}

	public function test_getImprovements_skips_unoptimized_thumbnails_from_the_totals() {
		$model = $this->makeStubModel();
		$model->setMeta( 'status', ImageModel::FILE_STATUS_SUCCESS );
		$model->setMeta( 'originalSize', 1000 );
		$model->setMeta( 'compressedSize', 500 );

		$this->setProtected( $model, 'thumbnails', array(
			'medium' => $this->makeImprovementThumb( false, 99.0, 999, 'medium' ), // unoptimized — should be ignored
			'large'  => $this->makeImprovementThumb( true, 40.0, 400, 'large' ),
		) );

		$result = $model->getImprovements();

		// Sentinel-pair: the unoptimized thumb's 99% / 999 bytes must
		// NOT contribute. Only main (50%) + large (40%) count.
		$this->assertSame( 45.0, $result['totalpercentage'] ); // round((50 + 40) / 2)
		$this->assertSame( 900, $result['totalsize'] ); // 500 (main savings) + 400 (large)
		$this->assertArrayNotHasKey( 'medium', $result['thumbnails'] );
	}

	/*
	 * dropFromQueue — instantiates single + bulk queue controllers and
	 * calls dropItem on each. Same smoke shape as CustomImageModel's test.
	 */

	public function test_dropFromQueue_runs_without_crashing_on_a_stub_model() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$model->dropFromQueue();

		// A regression that broke the queue-controller construction chain
		// or the dropItem call would surface as a fatal here.
		$this->assertTrue( true, 'reached this point → dropFromQueue chain intact' );
	}

	/*
	 * onDelete — walks parent + thumbs + retinas + AI + meta + queue.
	 * Full-flow verification needs real BackupModel / AI / WPML wiring.
	 * Here we test the smoke path (no crash) + the "no WPML duplicates
	 * means fileDelete=true" contract via a stubbed backupModel.
	 */

	public function test_onDelete_removes_the_shortpixel_postmeta_row_via_deleteMeta_chain() {
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );
		// Seed a row so we can observe its deletion.
		$model->saveMeta();
		$this->assertSame( 1, $this->countRowsFor( self::TEST_ATTACH_ID ), 'test setup: row should exist' );

		// Stub backupModel so parent::onDelete doesn't need the real controller.
		$stubBackup = new class {
			public function onDelete( $m ) {}
			public function hasBackup( $m ) { return false; }
			public function getModel( $m ) { return $this; }
		};
		$this->setProtected( $model, 'backupModel', $stubBackup );

		$model->onDelete();

		// Sentinel: onDelete's chain must reach deleteMeta (line 1748).
		// Regression that dropped the deleteMeta call would leave the
		// row orphaned.
		$this->assertSame( 0, $this->countRowsFor( self::TEST_ATTACH_ID ) );
	}

	// =============================================================
	// SESSION 5 — property getters + legacy converters + walkers
	// =============================================================

	/*
	 * hasOriginal / getOriginalFile — thin getters over the
	 * $original_file property, populated during construction by
	 * setOriginalFile() when the attachment is a scaled variant.
	 */

	public function test_hasOriginal_returns_false_when_original_file_is_not_set() {
		$model = $this->makeStubModel();
		// Fresh stub — no scaled original detected during construction.

		$this->assertFalse( $model->hasOriginal() );
	}

	public function test_hasOriginal_returns_true_when_original_file_property_is_set() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'original_file', new stdClass() );

		// Sentinel: strict `assertTrue` — a regression that returned
		// the object itself (truthy but not `true`) would fail the
		// `: bool` return-type contract.
		$this->assertTrue( $model->hasOriginal() );
	}

	public function test_getOriginalFile_returns_false_when_no_original_exists() {
		$model = $this->makeStubModel();

		$this->assertFalse( $model->getOriginalFile() );
	}

	public function test_getOriginalFile_returns_the_original_file_object_when_set() {
		$model = $this->makeStubModel();
		$sentinel = new stdClass();
		$sentinel->marker = 'original-file-sentinel';
		$this->setProtected( $model, 'original_file', $sentinel );

		// Sentinel: identity check. Regression that returned a copy or
		// hasOriginal's bool result would fail here.
		$this->assertSame( $sentinel, $model->getOriginalFile() );
	}

	/*
	 * getParent — reads the private $parent property populated by
	 * getDBMeta when this instance is a WPML duplicate.
	 */

	public function test_getParent_returns_false_when_parent_property_is_null() {
		$model = $this->makeStubModel();
		// Fresh stub has $parent = null.

		$this->assertFalse( $model->getParent() );
	}

	public function test_getParent_returns_the_parent_id_when_numeric() {
		$model = $this->makeStubModel();
		$this->setProtected( $model, 'parent', 42 );

		$this->assertSame( 42, $model->getParent() );
	}

	/*
	 * returnTrue — trivial WP-filter callback.
	 */

	public function test_returnTrue_returns_boolean_true() {
		$model = $this->makeStubModel();

		// Sentinel: strict `assertSame( true, ... )`. Regression that
		// returned 1 / 'yes' / any other truthy value would fail —
		// this method's whole purpose is to be a filter callback
		// where `=== true` matters.
		$this->assertSame( true, $model->returnTrue() );
	}

	/*
	 * legacyConvertType — maps legacy string compression types onto the
	 * modern COMPRESSION_* constants.
	 */

	public function test_legacyConvertType_maps_all_four_legacy_strings_to_the_correct_constants() {
		$model = $this->makeStubModel();

		$expected = array(
			'lossy'    => ImageModel::COMPRESSION_LOSSY,
			'lossless' => ImageModel::COMPRESSION_LOSSLESS,
			'glossy'   => ImageModel::COMPRESSION_GLOSSY,
			'unknown'  => -1,   // default branch
		);

		foreach ( $expected as $input => $expectedResult ) {
			$this->assertSame(
				$expectedResult,
				$this->invokeProtected( $model, 'legacyConvertType', array( $input ) ),
				"legacyConvertType('$input') should return $expectedResult"
			);
		}
	}

	/*
	 * legacyConvertStatus — maps legacy ShortPixel data blocks onto
	 * FILE_STATUS_* codes.
	 */

	public function test_legacyConvertStatus_returns_FILE_STATUS_SUCCESS_when_ShortPixelImprovement_is_positive() {
		$model = $this->makeStubModel();

		$data = array();
		$metadata = array( 'ShortPixelImprovement' => 25 );

		$this->assertSame(
			ImageModel::FILE_STATUS_SUCCESS,
			$this->invokeProtected( $model, 'legacyConvertStatus', array( $data, $metadata ) )
		);
	}

	public function test_legacyConvertStatus_returns_FILE_STATUS_PENDING_when_WaitingProcessing_is_set() {
		$model = $this->makeStubModel();

		$data = array( 'WaitingProcessing' => true );
		$metadata = array();

		$this->assertSame(
			ImageModel::FILE_STATUS_PENDING,
			$this->invokeProtected( $model, 'legacyConvertStatus', array( $data, $metadata ) )
		);
	}

	public function test_legacyConvertStatus_returns_FILE_STATUS_ERROR_for_backup_fail_ErrCode() {
		$model = $this->makeStubModel();

		$data = array( 'ErrCode' => 'backup-fail' );
		$metadata = array();

		$this->assertSame(
			ImageModel::FILE_STATUS_ERROR,
			$this->invokeProtected( $model, 'legacyConvertStatus', array( $data, $metadata ) )
		);
	}

	public function test_legacyConvertStatus_passes_through_negative_ErrCode_value() {
		$model = $this->makeStubModel();

		$data = array( 'ErrCode' => -42 );
		$metadata = array();

		// Negative ErrCode passes through as the status (used to preserve
		// the exact error code from the legacy schema).
		$this->assertSame(
			-42,
			$this->invokeProtected( $model, 'legacyConvertStatus', array( $data, $metadata ) )
		);
	}

	/**
	 * PINNED for deferred fix — MediaLibraryModel::legacyConvertStatus
	 * at line 3546 has `is_numeric($metadata["ShortPixelImprovement"]) > 0`.
	 * That parses as `(is_numeric(...) === true) > 0`, i.e. `true > 0`,
	 * which is always true. The intended check was against the VALUE
	 * being > 0 — `$metadata["ShortPixelImprovement"] > 0`. So ANY numeric
	 * ShortPixelImprovement (including 0 or a negative like -1) gets
	 * mapped to FILE_STATUS_SUCCESS.
	 *
	 * Intended behaviour: only positive improvement values should route
	 * to SUCCESS. Zero improvement means the API accepted but didn't
	 * reduce the file — should NOT be flagged as an optimization success.
	 *
	 * This test will FAIL (returns SUCCESS instead of the negative ErrCode
	 * fallback) until Bas fixes line 3546.
	 */
	public function test_legacyConvertStatus_does_not_map_zero_improvement_to_SUCCESS_pinned_for_deferred_fix() {
		$model = $this->makeStubModel();

		// Zero improvement + a negative ErrCode fallback. The correct
		// behaviour: skip SUCCESS, fall to $error < 0 branch → return -100.
		// Buggy behaviour: return FILE_STATUS_SUCCESS anyway because
		// is_numeric(0) === true > 0 is true.
		$data = array( 'ErrCode' => -100 );
		$metadata = array( 'ShortPixelImprovement' => 0 );

		$this->assertSame(
			-100,
			$this->invokeProtected( $model, 'legacyConvertStatus', array( $data, $metadata ) ),
			'legacyConvertStatus mapped 0 improvement to SUCCESS — bug at line 3546: `is_numeric(...) > 0` always evaluates to `true > 0` which is true. Should be `$metadata["ShortPixelImprovement"] > 0`.'
		);
	}

	/**
	 * PINNED for deferred fix — MediaLibraryModel::legacyConvertStatus
	 * has a branch coverage gap: when none of the four `if`/`elseif`
	 * conditions fire, `$status` is never assigned. The final
	 * `return $status;` reads an undefined variable, which returns null
	 * (in most PHP configs) or throws under strict warning-to-exception
	 * configurations.
	 *
	 * Trigger: legacy data with a non-numeric `ErrCode` string that
	 * isn't 'backup-fail' or 'write-fail' — e.g. `'unknown-error'`.
	 * The `$error < 0` comparison against a non-numeric string is
	 * false in PHP, so none of the branches fire.
	 *
	 * Intended behaviour: define a default $status (e.g. FILE_STATUS_UNPROCESSED
	 * or FILE_STATUS_ERROR) so the return is always a valid int
	 * FILE_STATUS_* code.
	 *
	 * This test will FAIL until Bas adds a default $status assignment.
	 * Current observed behaviour: returns null (env-dependent — may
	 * throw under stricter configs).
	 */
	public function test_legacyConvertStatus_returns_int_status_code_for_unknown_ErrCode_string_pinned_for_deferred_fix() {
		$model = $this->makeStubModel();

		// Non-numeric ErrCode that isn't 'backup-fail' or 'write-fail'.
		// No WaitingProcessing. No ShortPixelImprovement.
		$data = array( 'ErrCode' => 'unrecognised-error-string' );
		$metadata = array();

		try {
			$result = $this->invokeProtected( $model, 'legacyConvertStatus', array( $data, $metadata ) );
			$this->assertIsInt(
				$result,
				'legacyConvertStatus returned a non-int (probably null) for an unknown ErrCode — `$status` is never assigned when none of the branches fire. Bug at MediaLibraryModel.php:3537-3558. Fix: add a default $status assignment.'
			);
		} catch ( \Throwable $t ) {
			// Some PHP configs promote the undefined-variable notice
			// to an exception. Same bug, different symptom.
			$this->fail(
				'legacyConvertStatus threw on unknown ErrCode string — same undefined-$status bug at MediaLibraryModel.php:3537-3558. Message: ' . $t->getMessage()
			);
		}
	}

	/*
	 * getThumbnailModel — factory for MediaLibraryThumbnailModel instances.
	 */

	public function test_getThumbnailModel_returns_a_MediaLibraryThumbnailModel_instance() {
		$path  = $this->makeImageFile();
		$model = $this->makeModelWithId( self::TEST_ATTACH_ID );

		$result = $this->invokeProtected( $model, 'getThumbnailModel', array( $path, 'medium' ) );

		$this->assertInstanceOf( \ShortPixel\Model\Image\MediaLibraryThumbnailModel::class, $result );
	}

	/*
	 * getThumbObjects — merges thumbnails + retinas (with `retina_`
	 * prefix) + the scaled original into a single walker.
	 */

	public function test_getThumbObjects_returns_empty_array_when_no_thumbnails_retinas_or_original() {
		$model = $this->makeStubModel();

		$result = $this->invokeProtected( $model, 'getThumbObjects' );

		$this->assertSame( array(), $result );
	}

	public function test_getThumbObjects_includes_thumbnails_keyed_by_size_name() {
		$model = $this->makeStubModel();
		$thumb1 = $this->makeStubThumb();
		$thumb2 = $this->makeStubThumb();
		$this->setProtected( $model, 'thumbnails', array(
			'medium' => $thumb1,
			'large'  => $thumb2,
		) );

		$result = $this->invokeProtected( $model, 'getThumbObjects' );

		// Keys preserved as-is for thumbnails.
		$this->assertArrayHasKey( 'medium', $result );
		$this->assertArrayHasKey( 'large', $result );
		$this->assertSame( $thumb1, $result['medium'] );
		$this->assertSame( $thumb2, $result['large'] );
	}

	public function test_getThumbObjects_includes_scaled_original_under_the_originalImageKey() {
		$model = $this->makeStubModel();
		$original = new stdClass();
		$original->marker = 'original-sentinel';
		$this->setProtected( $model, 'is_scaled', true );
		$this->setProtected( $model, 'original_file', $original );

		$result = $this->invokeProtected( $model, 'getThumbObjects' );

		$originalKey = $this->getProtected( $model, 'originalImageKey' );
		// Sentinel: pins the "scaled originals join the walker under
		// the reserved originalImageKey" contract at line 2994. Regression
		// that dropped the isScaled check would omit the original.
		$this->assertArrayHasKey( $originalKey, $result );
		$this->assertSame( $original, $result[ $originalKey ] );
	}

	/*
	 * removeLegacyShortPixel — public entry that removes legacy WP
	 * metadata keys via removeLegacy() AND deletes the two
	 * `_shortpixel_was_converted` / `_shortpixel_status` post-meta flags.
	 */

	public function test_removeLegacyShortPixel_deletes_the_two_legacy_post_meta_keys_when_removeLegacy_returns_true() {
		// Need a real attachment id — wp_get_attachment_metadata (which
		// removeLegacy reads) returns false for non-attachment post ids,
		// which means removeLegacy() would return false and skip the
		// post-meta cleanup at line 2967.
		$attachId = $this->factory->post->create( array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/png',
		) );

		// Seed both post-meta flags AND a legacy WP metadata key so
		// removeLegacy() returns true and gates the post-meta deletion.
		update_post_meta( $attachId, '_shortpixel_was_converted', 1 );
		update_post_meta( $attachId, '_shortpixel_status', 2 );
		wp_update_attachment_metadata( $attachId, array( 'ShortPixel' => array( 'sentinel' => 'legacy' ) ) );

		$model = $this->makeModelWithId( $attachId );
		$model->removeLegacyShortPixel();

		// Sentinel-pair: BOTH post-meta keys must be deleted. A
		// regression that only deleted one would fail on the other.
		$this->assertSame( '', get_post_meta( $attachId, '_shortpixel_was_converted', true ) );
		$this->assertSame( '', get_post_meta( $attachId, '_shortpixel_status', true ) );

		wp_delete_post( $attachId, true );
	}

	/*
	 * removeLegacy — strips ShortPixel / ShortPixelImprovement /
	 * ShortPixelPng2Jpg from wp_attachment_metadata.
	 */

	public function test_removeLegacy_returns_true_when_it_stripped_a_legacy_key() {
		// Real attachment required — see removeLegacyShortPixel test above
		// for the wp_get_attachment_metadata / attachment-id coupling.
		$attachId = $this->factory->post->create( array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/png',
		) );
		wp_update_attachment_metadata( $attachId, array( 'ShortPixel' => array( 'x' => 1 ) ) );

		$model  = $this->makeModelWithId( $attachId );
		$result = $this->invokeProtected( $model, 'removeLegacy' );

		$this->assertTrue( $result );

		// Sentinel: the legacy key must be gone from wp metadata.
		$metadata = wp_get_attachment_metadata( $attachId );
		$this->assertArrayNotHasKey( 'ShortPixel', is_array( $metadata ) ? $metadata : array() );

		wp_delete_post( $attachId, true );
	}

	public function test_removeLegacy_returns_false_when_no_legacy_keys_are_present() {
		// Real attachment with NO legacy metadata seeded.
		$attachId = $this->factory->post->create( array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/png',
		) );
		$model = $this->makeModelWithId( $attachId );

		$result = $this->invokeProtected( $model, 'removeLegacy' );

		$this->assertFalse( $result );

		wp_delete_post( $attachId, true );
	}

	/*
	 * __debugInfo — returns a compact representation for var_dump().
	 */

	public function test___debugInfo_returns_the_documented_array_shape() {
		$model = $this->makeStubModel();

		$result = $model->__debugInfo();

		// Sentinel: the exact key set from lines 3572-3583. Adding a
		// key to the code without updating this test would fail here;
		// dropping one would leave stale docs.
		$expected = array( 'id', 'exists', 'is_virtual', 'fullpath', 'width', 'height',
		                   'image_meta', 'thumbnails', 'retinas', 'original_file',
		                   'is_scaled', 'imageType' );
		$this->assertSame( $expected, array_keys( $result ) );
	}
}
