<?php
/**
 * Tests for ShortPixel\Model\Backup\LocalBackupModel.
 *
 * Focus: the pure-logic surface added on top of the abstract base.
 * The base's contract (constants, __get, needsRegenerate,
 * getBackupFileName, loadMediaItem clone/isConverted snapshot) is
 * covered by test-BackupModel; here we test only what
 * LocalBackupModel adds on its own.
 *
 * Skipped at the unit level (integration territory):
 *   - createBackupFile / restore / onDelete / hasBackup / renameBackup /
 *     getBackupDirectory / getBackupFile / getMainBackupFile / loadAll —
 *     every one calls into the shipped filesystem controller
 *     (getFile / getBackupDirectory / copy / move / delete / exists).
 *     Covered by the future integration harness.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Backup\LocalBackupModel;
use ShortPixel\Model\Image\ImageModel;

class LocalBackupModelTest extends WP_UnitTestCase {

	private function getPrivate( LocalBackupModel $m, string $prop ) {
		$ref = new ReflectionClass( LocalBackupModel::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setPrivate( LocalBackupModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( LocalBackupModel::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function invokePrivate( LocalBackupModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( LocalBackupModel::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	private function freshModel(): LocalBackupModel {
		$ref = new ReflectionClass( LocalBackupModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/**
	 * Build an ImageModel stub scripted for the `type` + `hasOriginal` +
	 * `imageType` triple that getMainFile / getBackupName inspect.
	 * Bypasses the parent constructor chain so no filesystem is touched.
	 */
	private function makeItemStub( array $args = array() ) {
		$defaults = array(
			'type'         => 'media',
			'has_original' => false,
			'image_type'   => null,
			'file_name'    => 'file.jpg',
			'file_base'    => 'file',
			'is_main_file' => false,
			'original'     => null,
		);
		$args = array_merge( $defaults, $args );

		return new class( $args ) extends ImageModel {
			public $stub;
			public function __construct( $args ) {
				$this->stub = $args;
			}
			public function get( $name ) {
				if ( $name === 'type' ) return $this->stub['type'];
				if ( $name === 'imageType' ) return $this->stub['image_type'];
				if ( $name === 'is_main_file' ) return $this->stub['is_main_file'];
				return null;
			}
			public function hasOriginal() : bool { return (bool) $this->stub['has_original']; }
			public function getOriginalFile() { return $this->stub['original']; }
			public function getFileName() { return $this->stub['file_name']; }
			public function getFileBase() { return $this->stub['file_base']; }

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

	/*
	 * Inheritance sanity — constants + type
	 */

	public function test_extends_BackupModel_and_inherits_status_constants() {
		$this->assertTrue( is_subclass_of( LocalBackupModel::class, \ShortPixel\Model\Backup\BackupModel::class ) );
		$this->assertSame( 1, LocalBackupModel::STATUS_IGNORED );
		$this->assertSame( -1, LocalBackupModel::ERR_COPY_FAILED );
	}

	/*
	 * backupIsMain — LocalBackupModel writes one backup file per source
	 * file (main + every thumbnail get their own), so this is always
	 * false. The deferred-bug empty body has been fixed.
	 */

	public function test_backupIsMain_returns_false() {
		$this->assertFalse( $this->freshModel()->backupIsMain() );
	}

	/*
	 * getMainFile (private) — media-type + hasOriginal routing
	 */

	public function test_getMainFile_returns_original_when_media_item_is_scaled_media() {
		$original  = $this->makeItemStub( array( 'is_main_file' => true ) );
		$mediaItem = $this->makeItemStub( array(
			'type'         => 'media',
			'has_original' => true,
			'original'     => $original,
		) );

		$m = $this->freshModel();
		$this->setPrivate( $m, 'mediaItem', $mediaItem );

		$this->assertSame( $original, $this->invokePrivate( $m, 'getMainFile' ) );
	}

	public function test_getMainFile_returns_mediaItem_when_media_type_has_no_original() {
		$mediaItem = $this->makeItemStub( array(
			'type'         => 'media',
			'has_original' => false,
		) );

		$m = $this->freshModel();
		$this->setPrivate( $m, 'mediaItem', $mediaItem );

		$this->assertSame( $mediaItem, $this->invokePrivate( $m, 'getMainFile' ) );
	}

	public function test_getMainFile_returns_mediaItem_when_type_is_not_media() {
		$mediaItem = $this->makeItemStub( array(
			'type'         => 'custom',
			// Even if hasOriginal is true, the type gate rejects it.
			'has_original' => true,
			'original'     => $this->makeItemStub(),
		) );

		$m = $this->freshModel();
		$this->setPrivate( $m, 'mediaItem', $mediaItem );

		$this->assertSame( $mediaItem, $this->invokePrivate( $m, 'getMainFile' ) );
	}

	/*
	 * getBackupName (private) — retina prefixing
	 */

	public function test_getBackupName_passes_non_retina_names_through_unchanged() {
		$source = $this->makeItemStub( array( 'image_type' => ImageModel::IMAGE_TYPE_THUMB ) );

		$out = $this->invokePrivate( $this->freshModel(), 'getBackupName', array( 'medium', $source ) );

		$this->assertSame( 'medium', $out );
	}

	public function test_getBackupName_prefixes_retina_variants_with_retina_underscore() {
		$source = $this->makeItemStub( array( 'image_type' => ImageModel::IMAGE_TYPE_RETINA ) );

		$out = $this->invokePrivate( $this->freshModel(), 'getBackupName', array( 'medium', $source ) );

		$this->assertSame( 'retina_medium', $out );
	}

	public function test_getBackupName_does_not_prefix_main_or_original_image_types() {
		foreach ( array(
			ImageModel::IMAGE_TYPE_MAIN,
			ImageModel::IMAGE_TYPE_ORIGINAL,
			ImageModel::IMAGE_TYPE_THUMB,
			ImageModel::IMAGE_TYPE_DUPLICATE,
		) as $imageType ) {
			$source = $this->makeItemStub( array( 'image_type' => $imageType ) );
			$out    = $this->invokePrivate( $this->freshModel(), 'getBackupName', array( 'x', $source ) );
			$this->assertSame( 'x', $out, "Image type $imageType should not be prefixed" );
		}
	}

	/*
	 * getBackupData — short-circuits when the cache has already been loaded
	 */

	public function test_getBackupData_returns_cached_backup_files_when_already_fully_loaded() {
		$m = $this->freshModel();

		$cache = array(
			'main'  => array( 'has_backup' => true, 'file' => '/x/main.jpg', 'has_own_file' => true ),
			'thumb' => array( 'has_backup' => true, 'file' => '/x/thumb.jpg', 'has_own_file' => true ),
		);
		$this->setPrivate( $m, 'backup_files', $cache );
		$this->setPrivate( $m, 'full_backup_loaded', true );

		// full_backup_loaded=true so loadAll (which would touch the filesystem)
		// must NOT run — the cache is returned as-is.
		$this->assertSame( $cache, $m->getBackupData() );
	}
}
