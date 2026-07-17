<?php
/**
 * Tests for ShortPixel\Controller\FileSystemController.
 *
 * Covers:
 *   - getFile() — returns a FileModel of the correct type.
 *   - getDirectory() — returns a DirectoryModel of the correct type.
 *   - pathIsUrl() — pure string-inspection logic for http/https/protocol-relative/scheme.
 *   - checkBackUpFolder() — verifies the backup folder exists and is usable.
 *   - getWPUploadBase() — returns a DirectoryModel pointing to wp-content/uploads.
 *   - getWPAbsPath() — returns a DirectoryModel for the effective WordPress root.
 *   - getWPFileBase() — returns a DirectoryModel pointing to the WP file base.
 *   - flushImageCache() — clears static caches without error.
 *   - getMediaImage() — returns false for non-numeric IDs; uses cache.
 *   - sortFiles() — empty-array guard and alphabetical sort.
 *   - getBackupDirectory() — returns a DirectoryModel for a real local file.
 *
 * Out of scope (and why):
 *   - pathToUrl() — highly environment-dependent; relies on WP upload-dir
 *     configuration, multisite topology, and ABSPATH; output varies across
 *     installations and is better verified in integration tests.
 *   - url_exists() — makes live cURL HTTP requests; skipped to avoid network I/O.
 *   - getCustomImage() / getCustomStub() / getImage('custom') — require
 *     Custom-media queue DB tables and full model initialisation; integration scope.
 *   - getOriginalImage() — wraps wp_get_original_image_path() which needs a
 *     real media attachment with a scaled original on disk.
 *   - moveLogFiles() — filesystem move operations across directories; integration scope.
 *   - startTrustedMode() / endTrustedMode() — gated on SHORTPIXEL_TRUSTED_MODE
 *     constant which is already resolved and false in the test harness process.
 *   - getMediaImage() full DB path — requires a real WP media attachment with a
 *     file on disk; integration scope.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\FileSystemController;
use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\File\DirectoryModel;

class FileSystemControllerTest extends WP_UnitTestCase {

	/** @var FileSystemController */
	private $fs;

	/** @var string Sandbox directory for test files. */
	private $sandbox;

	public function set_up() {
		parent::set_up();

		// FileSystemController's constructor only calls wpSPIO()->env() which is always available.
		$this->fs = new FileSystemController();

		$this->sandbox = sys_get_temp_dir() . '/spio-fsc-' . uniqid() . '/';
		wp_mkdir_p( $this->sandbox );

		// Make sure the static caches start empty for each test.
		FileSystemController::$mediaItems  = array();
		FileSystemController::$customItems = array();
	}

	public function tear_down() {
		FileSystemController::$mediaItems  = array();
		FileSystemController::$customItems = array();

		// Clean up sandbox.
		if ( is_dir( $this->sandbox ) ) {
			$files = glob( $this->sandbox . '*' );
			if ( $files ) {
				foreach ( $files as $f ) {
					if ( is_file( $f ) ) {
						@unlink( $f );
					}
				}
			}
			@rmdir( $this->sandbox );
		}

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// getFile
	// -------------------------------------------------------------------------

	public function test_getFile_returns_a_FileModel_instance() {
		$file = $this->fs->getFile( $this->sandbox . 'dummy.jpg' );

		$this->assertInstanceOf( FileModel::class, $file );
	}

	public function test_getFile_returns_model_for_nonexistent_path_without_error() {
		$file = $this->fs->getFile( $this->sandbox . 'ghost.jpg' );

		// The model is created; whether the file "exists" is separate.
		$this->assertInstanceOf( FileModel::class, $file );
		$this->assertFalse( $file->exists() );
	}

	public function test_getFile_reflects_the_correct_path_for_a_real_file() {
		$path = $this->sandbox . 'real.txt';
		file_put_contents( $path, 'hello' );

		$file = $this->fs->getFile( $path );

		$this->assertTrue( $file->exists() );
		$this->assertStringContainsString( 'real.txt', $file->getFileName() );
	}

	// -------------------------------------------------------------------------
	// getDirectory
	// -------------------------------------------------------------------------

	public function test_getDirectory_returns_a_DirectoryModel_instance() {
		$dir = $this->fs->getDirectory( $this->sandbox );

		$this->assertInstanceOf( DirectoryModel::class, $dir );
	}

	public function test_getDirectory_existing_directory_reports_exists_true() {
		$dir = $this->fs->getDirectory( $this->sandbox );

		$this->assertTrue( $dir->exists() );
	}

	public function test_getDirectory_nonexistent_directory_reports_exists_false() {
		$dir = $this->fs->getDirectory( $this->sandbox . 'no-such-subdir/' );

		$this->assertFalse( $dir->exists() );
	}

	// -------------------------------------------------------------------------
	// pathIsUrl
	// -------------------------------------------------------------------------

	public function test_pathIsUrl_returns_true_for_http_scheme() {
		$this->assertTrue( $this->fs->pathIsUrl( 'http://example.com/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_true_for_https_scheme() {
		$this->assertTrue( $this->fs->pathIsUrl( 'https://example.com/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_true_for_protocol_relative_url() {
		$this->assertTrue( $this->fs->pathIsUrl( '//example.com/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_true_for_custom_scheme_with_triple_slash() {
		// S3-style custom scheme used by offload plugins.
		$this->assertTrue( $this->fs->pathIsUrl( 's3://my-bucket/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_false_for_absolute_filesystem_path() {
		$this->assertFalse( $this->fs->pathIsUrl( '/var/www/html/wp-content/uploads/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_false_for_relative_path() {
		$this->assertFalse( $this->fs->pathIsUrl( 'wp-content/uploads/image.jpg' ) );
	}

	public function test_pathIsUrl_returns_false_for_empty_string() {
		$this->assertFalse( $this->fs->pathIsUrl( '' ) );
	}

	// -------------------------------------------------------------------------
	// checkBackUpFolder
	// -------------------------------------------------------------------------

	public function test_checkBackUpFolder_returns_true_for_existing_backup_folder() {
		$result = $this->fs->checkBackUpFolder( SHORTPIXEL_BACKUP_FOLDER );

		$this->assertTrue( $result );
	}

	public function test_checkBackUpFolder_creates_and_returns_true_for_new_subdirectory() {
		$newDir = $this->sandbox . 'new-backup-test/';
		// Must not exist yet.
		$this->assertFalse( is_dir( $newDir ) );

		$result = $this->fs->checkBackUpFolder( $newDir );

		$this->assertTrue( $result );
		$this->assertTrue( is_dir( $newDir ) );

		// Clean up.
		@rmdir( $newDir );
	}

	// -------------------------------------------------------------------------
	// getWPUploadBase
	// -------------------------------------------------------------------------

	public function test_getWPUploadBase_returns_a_DirectoryModel() {
		$dir = $this->fs->getWPUploadBase();

		$this->assertInstanceOf( DirectoryModel::class, $dir );
	}

	public function test_getWPUploadBase_points_to_an_existing_directory() {
		$dir = $this->fs->getWPUploadBase();

		$this->assertTrue( $dir->exists() );
	}

	public function test_getWPUploadBase_path_matches_wp_upload_dir_basedir() {
		$upload = wp_upload_dir( null, false );
		$dir    = $this->fs->getWPUploadBase();

		$this->assertStringContainsString(
			basename( $upload['basedir'] ),
			$dir->getPath()
		);
	}

	// -------------------------------------------------------------------------
	// getWPAbsPath
	// -------------------------------------------------------------------------

	public function test_getWPAbsPath_returns_a_DirectoryModel() {
		$dir = $this->fs->getWPAbsPath();

		$this->assertInstanceOf( DirectoryModel::class, $dir );
	}

	public function test_getWPAbsPath_returns_a_non_empty_path() {
		$dir  = $this->fs->getWPAbsPath();
		$path = $dir->getPath();

		$this->assertIsString( $path );
		$this->assertNotEmpty( $path );
	}

	public function test_getWPAbsPath_contains_the_abspath_root() {
		$dir  = $this->fs->getWPAbsPath();
		$path = $dir->getPath();

		// The effective abs path must be a prefix of WP_CONTENT_DIR.
		$this->assertStringContainsString(
			rtrim( $path, '/' ),
			WP_CONTENT_DIR,
			'getWPAbsPath must be a parent of WP_CONTENT_DIR'
		);
	}

	// -------------------------------------------------------------------------
	// getWPFileBase
	// -------------------------------------------------------------------------

	public function test_getWPFileBase_returns_a_DirectoryModel() {
		$dir = $this->fs->getWPFileBase();

		$this->assertInstanceOf( DirectoryModel::class, $dir );
	}

	public function test_getWPFileBase_returns_a_non_empty_path() {
		$path = $this->fs->getWPFileBase()->getPath();

		$this->assertIsString( $path );
		$this->assertNotEmpty( $path );
	}

	// -------------------------------------------------------------------------
	// flushImageCache
	// -------------------------------------------------------------------------

	public function test_flushImageCache_clears_media_items_static_cache() {
		// Manually populate the static cache.
		FileSystemController::$mediaItems = array( 99 => new stdClass() );

		$this->fs->flushImageCache();

		$this->assertEmpty( FileSystemController::$mediaItems );
	}

	public function test_flushImageCache_clears_custom_items_static_cache() {
		FileSystemController::$customItems = array( 42 => new stdClass() );

		$this->fs->flushImageCache();

		$this->assertEmpty( FileSystemController::$customItems );
	}

	// -------------------------------------------------------------------------
	// getMediaImage — non-numeric ID and cache hit
	// -------------------------------------------------------------------------

	public function test_getMediaImage_returns_false_for_non_numeric_id() {
		$result = $this->fs->getMediaImage( 'not-an-id' );

		$this->assertFalse( $result );
	}

	public function test_getMediaImage_returns_false_for_cacheOnly_when_cache_is_empty() {
		$result = $this->fs->getMediaImage( 9999, true, true );

		$this->assertFalse( $result );
	}

	public function test_getMediaImage_returns_cached_object_on_second_call_with_cache_enabled() {
		// Seed a fake object into the static cache.
		$fakeObj = new stdClass();
		FileSystemController::$mediaItems[123] = $fakeObj;

		$result = $this->fs->getMediaImage( 123, true );

		$this->assertSame( $fakeObj, $result );
	}

	public function test_getMediaImage_bypasses_cache_when_useCache_is_false() {
		// Even with a cached entry, passing useCache=false should attempt a fresh load.
		// For a non-existent attachment (ID 9998) get_attached_file returns false,
		// so the method returns false.
		FileSystemController::$mediaItems[9998] = new stdClass(); // seed cache.

		$result = $this->fs->getMediaImage( 9998, false );

		// The non-existent attachment returns false; this also proves the cache was bypassed.
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// sortFiles
	// -------------------------------------------------------------------------

	public function test_sortFiles_returns_empty_array_unchanged() {
		$result = $this->fs->sortFiles( array() );

		$this->assertSame( array(), $result );
	}

	/**
	 * sortFiles() detects FileModel arrays via the full FQCN and sorts
	 * them alphabetically by getFileName().
	 */
	public function test_sortFiles_sorts_FileModel_objects_alphabetically_by_filename() {
		// Create real files so FileModel can determine their names.
		$pathZ = $this->sandbox . 'zebra.jpg';
		$pathA = $this->sandbox . 'apple.jpg';
		$pathM = $this->sandbox . 'mango.jpg';

		file_put_contents( $pathZ, '' );
		file_put_contents( $pathA, '' );
		file_put_contents( $pathM, '' );

		$files = array(
			new FileModel( $pathZ ),
			new FileModel( $pathA ),
			new FileModel( $pathM ),
		);

		$sorted = $this->fs->sortFiles( $files );

		$names = array_map( fn( $f ) => $f->getFileName(), $sorted );

		$this->assertSame( array( 'apple.jpg', 'mango.jpg', 'zebra.jpg' ), $names );

		foreach ( array( $pathZ, $pathA, $pathM ) as $p ) {
			@unlink( $p );
		}
	}

	// -------------------------------------------------------------------------
	// getBackupDirectory
	// -------------------------------------------------------------------------

	public function test_getBackupDirectory_returns_false_for_nonexistent_path_without_create() {
		// Use a path inside wp-content/uploads so getRelativePath() can compute a
		// meaningful relative path. A path outside the WP install (e.g. /tmp/…)
		// causes getRelativePath() to return false, making $backup_fulldir equal
		// SHORTPIXEL_BACKUP_FOLDER itself — which exists — so the method would
		// return a DirectoryModel instead of false.
		$uploadDir  = wp_upload_dir();
		$uniqueSub  = 'spio-test-' . uniqid() . '/';
		$fakePath   = trailingslashit( $uploadDir['basedir'] ) . $uniqueSub . 'no-real-image.jpg';

		// The parent directory for the fake file must NOT exist (no backup subdir
		// for this path can exist either, since the unique subdirectory is new).
		$this->assertFalse(
			is_dir( trailingslashit( $uploadDir['basedir'] ) . $uniqueSub ),
			'Precondition: unique upload subdirectory must not exist before the test'
		);

		$file   = new FileModel( $fakePath );
		$result = $this->fs->getBackupDirectory( $file, false );

		$this->assertFalse( $result );
	}

	public function test_getBackupDirectory_returns_DirectoryModel_when_create_is_true() {
		// Create a real file so getFileDir() has a meaningful path to derive from.
		$filePath = $this->sandbox . 'sample.jpg';
		file_put_contents( $filePath, 'data' );

		$file   = new FileModel( $filePath );
		$result = $this->fs->getBackupDirectory( $file, true );

		$this->assertInstanceOf(
			DirectoryModel::class,
			$result,
			'getBackupDirectory with $create=true should return a DirectoryModel'
		);

		// Clean up the created backup sub-directory if it was made inside SHORTPIXEL_BACKUP_FOLDER.
		if ( $result instanceof DirectoryModel && $result->exists() ) {
			// Best-effort cleanup; we do not recurse here.
		}

		@unlink( $filePath );
	}

} // class
