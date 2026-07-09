<?php
/**
 * Tests for ShortPixel\Model\Backup\BackupModel.
 *
 * Exercises the abstract base's pure-logic surface through
 * SPIO_TestBackupModel — a concrete stub that no-ops every abstract
 * method — plus lightweight ImageModel stubs (declared inside the
 * test class) that let us script `isScaled` / `getOriginalFile` /
 * `getMeta` / `get('is_main_file')` / `getFileName` / `getFileBase`
 * without touching the filesystem.
 *
 * Skipped at the unit level (integration territory):
 *   - Every method that ultimately calls the shipped filesystem
 *     controller (getBackupDirectory, createBackupFile, restore,
 *     hasBackup, onDelete, getBackupFile, getMainBackupFile, loadAll,
 *     renameBackup, getBackupData). Covered by LocalBackupModel's
 *     integration tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Backup\BackupModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Controller\Backup\BackupController;

/**
 * Concrete BackupController stub for constructor tests. The base's
 * constructor takes a BackupController parameter but never invokes any
 * of its methods on the code paths we cover, so a no-op subclass is enough.
 */
class SPIO_TestBackupController extends BackupController {
	public function __construct() {}
	protected function autoRemoveBackups() {}
}

/**
 * Concrete BackupModel stub for base-class tests.
 * Every abstract is a no-op — the tests only exercise the base methods.
 */
class SPIO_TestBackupModel extends BackupModel {
	protected function getBackupDirectory( $create = false ) { return false; }
	public function createBackupFile( ImageModel $sourceFile ) : bool { return false; }
	public function restore( ImageModel $sourceFile ) { return false; }
	public function hasBackup( ImageModel $sourceFile, $strict = false ) : bool { return false; }
	public function onDelete( ImageModel $sourceFile ) : bool { return true; }
	public function getBackupFile( ImageModel $sourceFile ) { return false; }
	public function backupIsMain() {}
	public function getMainBackupFile() { return false; }
	public function renameBackup( $newBaseFileName ) : bool { return true; }
	protected function loadAll() {}
	public function getBackupData() { return $this->backup_files; }
}

class BackupModelTest extends WP_UnitTestCase {

	private function getPrivate( BackupModel $m, string $prop ) {
		$ref = new ReflectionClass( BackupModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setPrivate( BackupModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( BackupModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	/**
	 * Build a media-item stub with scriptable convertMeta / scaling /
	 * filebase behaviour. Bypasses the parent chain of constructors
	 * (ImageModel → FileModel) so no filesystem is touched.
	 */
	private function makeMediaItemStub( array $args = array() ) {
		$defaults = array(
			'is_scaled'          => false,
			'file_base'          => 'main-image',
			'original_file_base' => null,
			'file_format'        => null,
			'replacement_base'   => false,
			'is_converted'       => false,
		);
		$args = array_merge( $defaults, $args );

		$imageMeta = new ImageMeta();
		if ( ! is_null( $args['file_format'] ) ) {
			$imageMeta->convertMeta()->setFileFormat( $args['file_format'] );
		}
		if ( $args['replacement_base'] !== false ) {
			$imageMeta->convertMeta()->setReplacementImageBase( $args['replacement_base'] );
		}
		if ( true === $args['is_converted'] ) {
			// setConversionDone() flips isConverted to true.
			$imageMeta->convertMeta()->setConversionDone();
		}

		$originalStub = null;
		if ( ! is_null( $args['original_file_base'] ) ) {
			$originalStub = $this->makeSourceStub( array(
				'is_main_file' => true,
				'file_base'    => $args['original_file_base'],
				'file_name'    => $args['original_file_base'] . '.jpg',
			) );
		}

		return new class( $imageMeta, $args, $originalStub ) extends ImageModel {
			public $stub_is_scaled;
			public $stub_file_base;
			public $stub_original;
			public function __construct( $meta, $args, $original ) {
				$this->image_meta      = $meta;
				$this->stub_is_scaled  = $args['is_scaled'];
				$this->stub_file_base  = $args['file_base'];
				$this->stub_original   = $original;
			}
			public function isScaled() { return $this->stub_is_scaled; }
			public function getFileBase() { return $this->stub_file_base; }
			public function getOriginalFile() { return $this->stub_original; }

			public function getOptimizeUrls() { return array(); }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return array(); }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
		};
	}

	/**
	 * Build a source-file stub (main file or thumbnail role in
	 * getBackupFileName).
	 */
	private function makeSourceStub( array $args = array() ) {
		$defaults = array(
			'is_main_file' => false,
			'file_name'    => 'main-image-150x150.jpg',
			'file_base'    => 'main-image-150x150',
		);
		$args = array_merge( $defaults, $args );

		return new class( $args ) extends ImageModel {
			public $stub_args;
			public function __construct( $args ) {
				$this->stub_args = $args;
			}
			public function get( $name ) {
				if ( $name === 'is_main_file' ) return $this->stub_args['is_main_file'];
				return null;
			}
			public function getFileName() { return $this->stub_args['file_name']; }
			public function getFileBase() { return $this->stub_args['file_base']; }

			public function getOptimizeUrls() { return array(); }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return array(); }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
		};
	}

	private function freshModel(): BackupModel {
		$ref = new ReflectionClass( SPIO_TestBackupModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/*
	 * Constants — sanity pins
	 */

	public function test_status_constants_have_expected_values() {
		$this->assertSame( 1, BackupModel::STATUS_IGNORED );
		$this->assertSame( 2, BackupModel::STATUS_COPIED );
		$this->assertSame( 3, BackupModel::STATUS_BACKUP_OK );
	}

	public function test_error_constants_are_negative() {
		$this->assertSame( -1, BackupModel::ERR_COPY_FAILED );
		$this->assertSame( -2, BackupModel::ERR_BACKUP_EXISTS );
	}

	/*
	 * Constructor — assigns controller and delegates to loadMediaItem
	 */

	public function test_constructor_stores_controller_and_calls_loadMediaItem() {
		$controller = new SPIO_TestBackupController();
		$item       = $this->makeMediaItemStub();

		$m = new SPIO_TestBackupModel( $controller, $item );

		$this->assertSame( $controller, $this->getPrivate( $m, 'controller' ) );
		$this->assertNotNull( $this->getPrivate( $m, 'mediaItem' ) );
	}

	/*
	 * loadMediaItem — clones the source and snapshots isConverted
	 */

	public function test_loadMediaItem_clones_so_source_mutations_do_not_leak() {
		$controller = new SPIO_TestBackupController();
		$item       = $this->makeMediaItemStub();

		$m = new SPIO_TestBackupModel( $controller, $item );

		$internal = $this->getPrivate( $m, 'mediaItem' );
		$this->assertNotSame( $item, $internal );
	}

	public function test_loadMediaItem_captures_isConverted_false_for_pristine_meta() {
		$controller = new SPIO_TestBackupController();
		$item       = $this->makeMediaItemStub( array( 'is_converted' => false ) );

		$m = new SPIO_TestBackupModel( $controller, $item );

		$this->assertFalse( $this->getPrivate( $m, 'isConverted' ) );
	}

	public function test_loadMediaItem_captures_isConverted_true_when_convertMeta_reports_done() {
		$controller = new SPIO_TestBackupController();
		$item       = $this->makeMediaItemStub( array( 'is_converted' => true ) );

		$m = new SPIO_TestBackupModel( $controller, $item );

		$this->assertTrue( $this->getPrivate( $m, 'isConverted' ) );
	}

	/*
	 * __get — read-only exposure of protected properties
	 */

	public function test_get_returns_declared_property_value() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'statusCode', BackupModel::STATUS_BACKUP_OK );
		$this->assertSame( BackupModel::STATUS_BACKUP_OK, $m->statusCode );
	}

	public function test_get_returns_null_for_unknown_property() {
		$this->assertNull( $this->freshModel()->definitely_not_a_field );
	}

	public function test_get_exposes_backup_files_cache() {
		$m     = $this->freshModel();
		$cache = array( 'main' => array( 'has_backup' => true, 'file' => '/x/y.jpg', 'has_own_file' => true ) );
		$this->setPrivate( $m, 'backup_files', $cache );

		$this->assertSame( $cache, $m->backup_files );
	}

	/*
	 * needsRegenerate — pure logic over $backup_files
	 */

	public function test_needsRegenerate_false_when_backup_files_cache_is_empty() {
		$this->assertFalse( $this->freshModel()->needsRegenerate() );
	}

	public function test_needsRegenerate_false_when_every_backup_has_its_own_file() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'backup_files', array(
			'main'  => array( 'has_backup' => true, 'file' => '/x/main.jpg', 'has_own_file' => true ),
			'thumb' => array( 'has_backup' => true, 'file' => '/x/thumb.jpg', 'has_own_file' => true ),
		) );

		$this->assertFalse( $m->needsRegenerate() );
	}

	public function test_needsRegenerate_true_when_at_least_one_entry_is_covered_but_lacks_own_file() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'backup_files', array(
			'main'  => array( 'has_backup' => true, 'file' => '/x/main.jpg', 'has_own_file' => true ),
			'thumb' => array( 'has_backup' => true, 'file' => false, 'has_own_file' => false ),
		) );

		$this->assertTrue( $m->needsRegenerate() );
	}

	public function test_needsRegenerate_false_when_entry_has_no_backup_even_if_no_own_file() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'backup_files', array(
			'thumb' => array( 'has_backup' => false, 'file' => false, 'has_own_file' => false ),
		) );

		$this->assertFalse( $m->needsRegenerate() );
	}

	/*
	 * getBackupFileName — the four-branch string-manipulation contract
	 */

	public function test_getBackupFileName_returns_verbatim_filename_when_no_conversion_metadata_is_set() {
		$controller = new SPIO_TestBackupController();
		$mediaItem  = $this->makeMediaItemStub();
		$m          = new SPIO_TestBackupModel( $controller, $mediaItem );

		$source = $this->makeSourceStub( array(
			'is_main_file' => false,
			'file_name'    => 'main-image-150x150.jpg',
			'file_base'    => 'main-image-150x150',
		) );

		$this->assertSame( 'main-image-150x150.jpg', $m->getBackupFileName( $source ) );
	}

	public function test_getBackupFileName_uses_stored_extension_when_only_fileFormat_is_set() {
		$controller = new SPIO_TestBackupController();
		$mediaItem  = $this->makeMediaItemStub( array( 'file_format' => 'png' ) );
		$m          = new SPIO_TestBackupModel( $controller, $mediaItem );

		$source = $this->makeSourceStub( array(
			'is_main_file' => false,
			'file_name'    => 'main-image-150x150.jpg',
			'file_base'    => 'main-image-150x150',
		) );

		// The source's live extension (.jpg) is swapped for the stored one (.png).
		$this->assertSame( 'main-image-150x150.png', $m->getBackupFileName( $source ) );
	}

	public function test_getBackupFileName_uses_replacement_base_for_the_main_file() {
		$controller = new SPIO_TestBackupController();
		$mediaItem  = $this->makeMediaItemStub( array(
			'file_base'        => 'main-image',
			'file_format'      => 'png',
			'replacement_base' => 'image_abc123',
		) );
		$m = new SPIO_TestBackupModel( $controller, $mediaItem );

		$source = $this->makeSourceStub( array(
			'is_main_file' => true,
			'file_name'    => 'main-image.jpg',
			'file_base'    => 'main-image',
		) );

		$this->assertSame( 'image_abc123.png', $m->getBackupFileName( $source ) );
	}

	public function test_getBackupFileName_str_replaces_mainFileBase_with_replacement_for_thumbnails() {
		$controller = new SPIO_TestBackupController();
		$mediaItem  = $this->makeMediaItemStub( array(
			'file_base'        => 'main-image',
			'file_format'      => 'png',
			'replacement_base' => 'image_abc123',
		) );
		$m = new SPIO_TestBackupModel( $controller, $mediaItem );

		$source = $this->makeSourceStub( array(
			'is_main_file' => false,
			'file_name'    => 'main-image-150x150.jpg',
			'file_base'    => 'main-image-150x150',
		) );

		$this->assertSame( 'image_abc123-150x150.jpg', $m->getBackupFileName( $source ) );
	}

	public function test_getBackupFileName_uses_unscaled_original_filebase_when_media_item_is_scaled() {
		$controller = new SPIO_TestBackupController();
		$mediaItem  = $this->makeMediaItemStub( array(
			'is_scaled'          => true,
			'file_base'          => 'main-image-scaled',
			'original_file_base' => 'main-image',
			'file_format'        => 'png',
			'replacement_base'   => 'image_abc123',
		) );
		$m = new SPIO_TestBackupModel( $controller, $mediaItem );

		$source = $this->makeSourceStub( array(
			'is_main_file' => false,
			'file_name'    => 'main-image-300x300.jpg',
			'file_base'    => 'main-image-300x300',
		) );

		// The str_replace uses the *original* filebase (main-image), not the
		// scaled one (main-image-scaled), so the 300x300 thumbnail matches.
		$this->assertSame( 'image_abc123-300x300.jpg', $m->getBackupFileName( $source ) );
	}

	public function test_getBackupFileName_falls_back_to_verbatim_when_replacement_base_is_empty_string() {
		$controller = new SPIO_TestBackupController();
		$mediaItem  = $this->makeMediaItemStub( array( 'file_format' => 'png', 'replacement_base' => false ) );
		$m          = new SPIO_TestBackupModel( $controller, $mediaItem );

		$source = $this->makeSourceStub( array(
			'is_main_file' => false,
			'file_name'    => 'thumb.jpg',
			'file_base'    => 'thumb',
		) );

		// replacement_base=false but file_format='png' → basename+.png.
		$this->assertSame( 'thumb.png', $m->getBackupFileName( $source ) );
	}
}
