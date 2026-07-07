<?php
/**
 * Tests for ShortPixel\Model\Image\MediaLibraryThumbnailModel.
 *
 * Covers the state assignments, small logic gates, and no-op contracts that
 * don't need the WordPress media library, a live attachment or files on disk.
 *
 * Skipped at the unit level (integration territory):
 *   - getRetina()               → walks the filesystem for the @2x variant
 *   - isFileTypeNeeded()        → reads WebP / AVIF companions via getWebp / getAvif
 *   - onDelete()                → parent's onDelete deletes real files
 *   - getOptimizeUrls()         → depends on isProcessable() which touches WP settings + FS
 *   - getURL()                  → wp_get_original_image_url / image_get_intermediate_size
 *   - getImprovements()         → delegates to parent's (unused) implementation
 *   - getExcludePatterns()      → calls UtilHelper::getExclusions on live settings
 *   - hasDBRecord()             → DB query
 *   - restore()                 → requires a real backup file
 *   - checkVirtualForBackup()   → remote HTTP through DownloadHelper
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\MediaLibraryThumbnailModel;
use ShortPixel\Model\Image\ImageThumbnailMeta;
use ShortPixel\Model\Image\ImageModel;

class MediaLibraryThumbnailModelTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedProcessThumbnails;
	/** @var mixed */
	private $savedExcludeSizes;

	public function set_up() {
		parent::set_up();
		$settings                     = \wpSPIO()->settings();
		$this->savedProcessThumbnails = $settings->processThumbnails;
		$this->savedExcludeSizes      = $settings->excludeSizes;
	}

	public function tear_down() {
		$settings                     = \wpSPIO()->settings();
		$settings->processThumbnails  = $this->savedProcessThumbnails;
		$settings->excludeSizes       = $this->savedExcludeSizes;
		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function freshModel(): MediaLibraryThumbnailModel {
		$ref = new ReflectionClass( MediaLibraryThumbnailModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function setPrivate( MediaLibraryThumbnailModel $m, string $prop, $value ): void {
		$this->setPrivateOn( MediaLibraryThumbnailModel::class, $m, $prop, $value );
	}

	private function getPrivate( MediaLibraryThumbnailModel $m, string $prop ) {
		return $this->getPrivateOn( MediaLibraryThumbnailModel::class, $m, $prop );
	}

	private function invokePrivate( MediaLibraryThumbnailModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( MediaLibraryThumbnailModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	/**
	 * Walk the parent chain (MediaLibraryThumbnailModel → ImageModel →
	 * FileModel) to find the declaring class for a property, so protected
	 * fields declared on parents can be reflected safely.
	 */
	private function setPrivateOn( string $class, $instance, string $prop, $value ): void {
		$ref = new ReflectionClass( $class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $instance, $value );
	}

	private function getPrivateOn( string $class, $instance, string $prop ) {
		$ref = new ReflectionClass( $class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $instance );
	}

	/*
	 * Constructor — records id / imageType / size and seeds an ImageThumbnailMeta
	 */

	public function test_constructor_assigns_id_size_and_imageType() {
		$path = sys_get_temp_dir() . '/spio-mlthumb-' . uniqid() . '.jpg';
		$m    = new MediaLibraryThumbnailModel( $path, 42, 'medium' );

		$this->assertSame( 42, $this->getPrivate( $m, 'id' ) );
		$this->assertSame( 'medium', $this->getPrivate( $m, 'size' ) );
		$this->assertSame( ImageModel::IMAGE_TYPE_THUMB, $this->getPrivate( $m, 'imageType' ) );
	}

	public function test_constructor_seeds_a_fresh_ImageThumbnailMeta() {
		$path = sys_get_temp_dir() . '/spio-mlthumb-' . uniqid() . '.jpg';
		$m    = new MediaLibraryThumbnailModel( $path, 1, 'thumbnail' );

		$meta = $this->getPrivate( $m, 'image_meta' );
		$this->assertInstanceOf( ImageThumbnailMeta::class, $meta );
	}

	/*
	 * loadMeta / saveMeta — documented no-ops
	 */

	public function test_loadMeta_is_a_noop() {
		$m = $this->freshModel();
		// Signature is protected; reflection is needed to invoke it directly.
		$before = $this->getPrivate( $m, 'image_meta' );
		$this->invokePrivate( $m, 'loadMeta' );
		$this->assertSame( $before, $this->getPrivate( $m, 'image_meta' ) );
	}

	public function test_saveMeta_is_a_noop() {
		$m      = $this->freshModel();
		$before = $this->getPrivate( $m, 'image_meta' );
		$this->invokePrivate( $m, 'saveMeta' );
		$this->assertSame( $before, $this->getPrivate( $m, 'image_meta' ) );
	}

	/*
	 * Simple setters — setName / setSizeDefinition / setImageType
	 */

	public function test_setName_stores_size_slug() {
		$m = $this->freshModel();
		$m->setName( 'medium_large' );
		$this->assertSame( 'medium_large', $m->name );
	}

	public function test_setSizeDefinition_stores_wp_size_definition() {
		$m   = $this->freshModel();
		$def = array( 'width' => 300, 'height' => 200, 'crop' => false );
		$m->setSizeDefinition( $def );
		$this->assertSame( $def, $this->getPrivate( $m, 'sizeDefinition' ) );
	}

	public function test_setImageType_overrides_the_constructor_default() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'imageType', ImageModel::IMAGE_TYPE_THUMB );

		$m->setImageType( ImageModel::IMAGE_TYPE_RETINA );

		$this->assertSame( ImageModel::IMAGE_TYPE_RETINA, $this->getPrivate( $m, 'imageType' ) );
	}

	/*
	 * setMetaObj / getMetaObj — clone-on-set contract
	 */

	public function test_setMetaObj_clones_so_external_mutations_do_not_leak() {
		$m        = $this->freshModel();
		$external = new ImageThumbnailMeta();
		$external->status = 2;

		$this->invokePrivate( $m, 'setMetaObj', array( $external ) );

		// Mutate the source — the internal copy must not change.
		$external->status = 99;

		$internal = $this->invokePrivate( $m, 'getMetaObj' );
		$this->assertSame( 2, $internal->status );
		$this->assertNotSame( $external, $internal );
	}

	public function test_getMetaObj_returns_the_stored_meta() {
		$m    = $this->freshModel();
		$meta = new ImageThumbnailMeta();
		$this->setPrivate( $m, 'image_meta', $meta );

		$this->assertSame( $meta, $this->invokePrivate( $m, 'getMetaObj' ) );
	}

	/*
	 * __debugInfo — asserts the expected shape without asserting on the FS state
	 */

	public function test_debugInfo_returns_the_expected_key_set() {
		$path = sys_get_temp_dir() . '/spio-mlthumb-' . uniqid() . '.jpg';
		$m    = new MediaLibraryThumbnailModel( $path, 42, 'medium' );

		$out = $m->__debugInfo();

		$this->assertSame(
			array( 'image_meta', 'name', 'path', 'size', 'width', 'height', 'exists', 'is_virtual', 'wordpress_size' ),
			array_keys( $out )
		);
		$this->assertSame( 'medium', $out['size'] );
		$this->assertSame( 'no', $out['exists'] );
		$this->assertSame( 'no', $out['is_virtual'] );
	}

	/*
	 * Prevent-contract — thumbnails don't own the prevent flag
	 */

	public function test_preventNextTry_records_the_reason_on_the_local_field() {
		$m = $this->freshModel();
		$this->invokePrivate( $m, 'preventNextTry', array( 'because of X' ) );
		$this->assertSame( 'because of X', $this->getPrivate( $m, 'prevent_next_try' ) );
	}

	public function test_isOptimizePrevented_is_always_false_for_thumbnails() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'prevent_next_try', 'not-false-value' );
		$this->assertFalse( $m->isOptimizePrevented() );
	}

	public function test_resetPrevent_is_always_null_for_thumbnails() {
		$this->assertNull( $this->freshModel()->resetPrevent() );
	}

	/*
	 * isUnlisted — driven by meta->file
	 */

	public function test_isUnlisted_true_when_meta_file_is_set() {
		$m    = $this->freshModel();
		$meta = new ImageThumbnailMeta();
		$meta->file = 'some-unlisted-file.jpg';
		$this->setPrivate( $m, 'image_meta', $meta );

		$this->assertTrue( $this->invokePrivate( $m, 'isUnlisted' ) );
	}

	public function test_isUnlisted_false_when_meta_file_is_null() {
		$m    = $this->freshModel();
		$meta = new ImageThumbnailMeta();
		$meta->file = null;
		$this->setPrivate( $m, 'image_meta', $meta );

		$this->assertFalse( $this->invokePrivate( $m, 'isUnlisted' ) );
	}

	/*
	 * excludeThumbnails — flips based on the processThumbnails setting
	 */

	public function test_excludeThumbnails_true_when_processThumbnails_off() {
		\wpSPIO()->settings()->processThumbnails = false;
		$this->assertTrue( $this->invokePrivate( $this->freshModel(), 'excludeThumbnails' ) );
	}

	public function test_excludeThumbnails_false_when_processThumbnails_on() {
		\wpSPIO()->settings()->processThumbnails = true;
		$this->assertFalse( $this->invokePrivate( $this->freshModel(), 'excludeThumbnails' ) );
	}

	/*
	 * isThumbnailProcessable — the "excluded because processThumbnails=false"
	 * branch. The other branch (falls through to parent::isProcessable) is
	 * integration territory because the parent walks the filesystem.
	 */

	public function test_isThumbnailProcessable_returns_false_for_thumbnails_when_processThumbnails_off() {
		\wpSPIO()->settings()->processThumbnails = false;

		$m = $this->freshModel();
		$this->setPrivate( $m, 'is_main_file', false );
		$this->setPrivate( $m, 'imageType', ImageModel::IMAGE_TYPE_THUMB );

		$this->assertFalse( $this->invokePrivate( $m, 'isThumbnailProcessable' ) );
		$this->assertSame( ImageModel::P_EXCLUDE_SIZE, $this->getPrivate( $m, 'processable_status' ) );
	}

	public function test_isThumbnailProcessable_does_not_exclude_the_main_file() {
		\wpSPIO()->settings()->processThumbnails = false;

		$m = $this->freshModel();
		$this->setPrivate( $m, 'is_main_file', true );
		$this->setPrivate( $m, 'imageType', ImageModel::IMAGE_TYPE_MAIN );

		// With is_main_file=true, the excludeThumbnails guard is skipped so
		// the "excluded" branch does not fire — the processable_status stays
		// at its uncached default. (The parent::isProcessable call that
		// follows is integration territory and is not asserted on here.)
		$this->invokePrivate( $m, 'isThumbnailProcessable' );

		$this->assertNotSame( ImageModel::P_EXCLUDE_SIZE, $this->getPrivate( $m, 'processable_status' ) );
	}

	public function test_isThumbnailProcessable_does_not_exclude_the_unscaled_original() {
		\wpSPIO()->settings()->processThumbnails = false;

		$m = $this->freshModel();
		$this->setPrivate( $m, 'is_main_file', false );
		$this->setPrivate( $m, 'imageType', ImageModel::IMAGE_TYPE_ORIGINAL );

		$this->invokePrivate( $m, 'isThumbnailProcessable' );

		$this->assertNotSame( ImageModel::P_EXCLUDE_SIZE, $this->getPrivate( $m, 'processable_status' ) );
	}

	/*
	 * isSizeExcluded — the "size name is in $settings->excludeSizes" branch.
	 * (The parent branch that evaluates size-range exclusion rules needs
	 * width/height from the FS and lives in the integration tier.)
	 */

	public function test_isSizeExcluded_true_when_this_size_name_is_in_the_settings_list() {
		\wpSPIO()->settings()->excludeSizes = array( 'medium', 'large' );

		$m       = $this->freshModel();
		$m->name = 'medium';

		$this->assertTrue( $this->invokePrivate( $m, 'isSizeExcluded' ) );
		$this->assertSame( ImageModel::P_EXCLUDE_SIZE, $this->getPrivate( $m, 'processable_status' ) );
	}

	public function test_isSizeExcluded_ignores_non_array_settings_value() {
		\wpSPIO()->settings()->excludeSizes = 'not-an-array';

		$m       = $this->freshModel();
		$m->name = 'medium';

		// Should NOT trigger the P_EXCLUDE_SIZE branch; the parent
		// exclusion path is exercised instead, which returns false for a
		// fresh instance with no configured patterns.
		$before = $this->getPrivate( $m, 'processable_status' );
		$this->invokePrivate( $m, 'isSizeExcluded' );
		$this->assertSame( $before, $this->getPrivate( $m, 'processable_status' ) );
	}

	/*
	 * isProcessableFileType — the "excludeThumbnails && !is_main_file → false"
	 * short-circuit. Fallthrough to parent is not asserted on here (it needs
	 * settings + on-disk companion detection).
	 */

	public function test_isProcessableFileType_returns_false_when_processThumbnails_off_and_not_main_file() {
		\wpSPIO()->settings()->processThumbnails = false;

		$m = $this->freshModel();
		$this->setPrivate( $m, 'is_main_file', false );

		$this->assertFalse( $m->isProcessableFileType( 'webp' ) );
		$this->assertFalse( $m->isProcessableFileType( 'avif' ) );
	}
}
