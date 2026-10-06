<?php
/**
 * Tests for ShortPixel\Model\File\FileModel.
 *
 * Uses a per-test-run temp directory with real fixtures for the methods that
 * legitimately need to probe the filesystem (existence, size, read/write,
 * copy/move/delete, MIME detection). Path-parsing tests run against the
 * lazily-populated filename / basename / extension fields.
 *
 * Skipped at the unit level:
 *   - UrlToPath()  → needs a live wpSPIO()->filesystem() URL context
 *   - fileIsRestricted() → needs open_basedir manipulation across PHP process
 *   - checkTrustedMode() → only meaningful with SHORTPIXEL_TRUSTED_MODE = true,
 *     which cannot be un-defined once the plugin has loaded
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\File\FileModel;

class FileModelTest extends WP_UnitTestCase {

	/**
	 * Sandbox directory for the current test run.
	 * @var string
	 */
	private $sandbox;

	public function set_up() {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/spio-filemodel-' . uniqid();
		mkdir( $this->sandbox, 0755, true );
	}

	public function tear_down() {
		if ( is_dir( $this->sandbox ) ) {
			$this->recursiveRmdir( $this->sandbox );
		}
		parent::tear_down();
	}

	private function recursiveRmdir( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: array() as $entry ) {
			if ( is_dir( $entry ) ) {
				$this->recursiveRmdir( $entry );
			} else {
				@unlink( $entry );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Writes a text file under the sandbox and returns its absolute path.
	 */
	private function makeFile( string $name, string $contents = 'hello world' ): string {
		$path = $this->sandbox . '/' . $name;
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Writes a minimal 20-byte valid JPEG under the sandbox and returns its
	 * absolute path. Enough for finfo_open() to report image/jpeg.
	 */
	private function makeJpeg( string $name = 'photo.jpg' ): string {
		$path  = $this->sandbox . '/' . $name;
		$bytes = hex2bin( 'FFD8FFE000104A46494600010100000100010000FFD9' );
		file_put_contents( $path, $bytes );
		return $path;
	}

	private function invokePrivate( FileModel $file, string $method, array $args = array() ) {
		$ref = new ReflectionClass( FileModel::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $file, ...$args );
	}

	/*
	 * Constructor and basic accessors
	 */

	public function test_constructor_stores_raw_path_untouched() {
		$path = $this->sandbox . '/some file.jpg';
		$file = new FileModel( $path );
		$this->assertSame( $path, $file->getRawFullPath() );
	}

	public function test_constructor_null_path_yields_empty_fullpath() {
		$file = new FileModel( null );
		$this->assertSame( '', $file->getFullPath() );
	}

	public function test_toString_returns_the_fullpath() {
		$path = $this->makeFile( 'note.txt' );
		$file = new FileModel( $path );
		$this->assertSame( $path, (string) $file );
	}

	public function test_constructor_trims_surrounding_whitespace() {
		$path    = $this->makeFile( 'trim-me.txt' );
		$padded  = "  {$path}  \n";
		$file    = new FileModel( $padded );

		$this->assertSame( $path, $file->getFullPath() );
		// Raw path is preserved untrimmed for callers that want the input verbatim.
		$this->assertSame( $padded, $file->getRawFullPath() );
	}

	/*
	 * Filename / basename / extension — lazy population via setFileInfo()
	 */

	public function test_getFileName_returns_basename_with_extension() {
		$path = $this->makeFile( 'photo.png' );
		$file = new FileModel( $path );
		$this->assertSame( 'photo.png', $file->getFileName() );
	}

	public function test_getFileBase_returns_basename_without_extension() {
		$path = $this->makeFile( 'photo.png' );
		$file = new FileModel( $path );
		$this->assertSame( 'photo', $file->getFileBase() );
	}

	public function test_getExtension_returns_lowercase_extension() {
		$path = $this->makeFile( 'PICTURE.JPG' );
		$file = new FileModel( $path );
		$this->assertSame( 'jpg', $file->getExtension() );
	}

	public function test_getExtension_only_returns_final_extension_for_double_ext() {
		$path = $this->makeFile( 'archive.jpg.webp' );
		$file = new FileModel( $path );
		$this->assertSame( 'webp', $file->getExtension() );
		$this->assertSame( 'archive.jpg', $file->getFileBase() );
	}

	public function test_getExtension_returns_null_for_no_extension() {
		$path = $this->makeFile( 'no-ext' );
		$file = new FileModel( $path );
		$this->assertNull( $file->getExtension() );
	}

	/*
	 * mb_pathinfo (private) — invoked via reflection
	 */

	public function test_mb_pathinfo_returns_full_component_array() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$out  = $this->invokePrivate( $file, 'mb_pathinfo', array( '/tmp/nested/photo.jpg' ) );

		$this->assertSame( '/tmp/nested', $out['dirname'] );
		$this->assertSame( 'photo.jpg',   $out['basename'] );
		$this->assertSame( 'photo',       $out['filename'] );
		$this->assertSame( 'jpg',         $out['extension'] );
	}

	public function test_mb_pathinfo_handles_multibyte_filenames() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$out  = $this->invokePrivate( $file, 'mb_pathinfo', array( '/tmp/фото.png' ) );

		$this->assertSame( 'фото.png', $out['basename'] );
		$this->assertSame( 'фото',     $out['filename'] );
		$this->assertSame( 'png',      $out['extension'] );
	}

	public function test_mb_pathinfo_option_selects_single_field() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$ext  = $this->invokePrivate( $file, 'mb_pathinfo', array( '/tmp/photo.jpg', PATHINFO_EXTENSION ) );
		$this->assertSame( 'jpg', $ext );
	}

	public function test_mb_pathinfo_no_extension_returns_empty_string() {
		$file = new FileModel( $this->sandbox . '/dummy.txt' );
		$out  = $this->invokePrivate( $file, 'mb_pathinfo', array( '/tmp/no-ext' ) );
		$this->assertSame( '', $out['extension'] );
	}

	/*
	 * Existence and file/directory distinction
	 */

	public function test_exists_true_for_present_file() {
		$file = new FileModel( $this->makeFile( 'here.txt' ) );
		$this->assertTrue( $file->exists() );
	}

	public function test_exists_false_for_missing_path() {
		$file = new FileModel( $this->sandbox . '/never-created.txt' );
		$this->assertFalse( $file->exists() );
	}

	public function test_exists_caches_result_across_calls() {
		$path = $this->makeFile( 'cached.txt' );
		$file = new FileModel( $path );

		$this->assertTrue( $file->exists() );

		// Remove the file behind FileModel's back — the cached "exists" flag
		// must still report true until resetStatus() is called.
		unlink( $path );
		$this->assertTrue( $file->exists() );

		$file->resetStatus();
		$this->assertFalse( $file->exists() );
	}

	public function test_is_readable_reflects_file_state() {
		$file = new FileModel( $this->makeFile( 'readable.txt' ) );
		$this->assertTrue( $file->is_readable() );
	}

	public function test_is_writable_true_for_present_writable_file() {
		$file = new FileModel( $this->makeFile( 'writable.txt' ) );
		$this->assertTrue( $file->is_writable() );
	}

	public function test_is_virtual_defaults_to_false_for_local_paths() {
		$file = new FileModel( $this->makeFile( 'local.txt' ) );
		$this->assertFalse( $file->is_virtual() );
	}

	/*
	 * Size / mtime / ctime / permissions
	 */

	public function test_getFileSize_returns_byte_count() {
		$file = new FileModel( $this->makeFile( 'sized.txt', 'abcdef' ) );
		$this->assertSame( 6, $file->getFileSize() );
	}

	public function test_getFileSize_returns_zero_for_missing_file() {
		$file = new FileModel( $this->sandbox . '/absent.txt' );
		$this->assertSame( 0, $file->getFileSize() );
	}

	public function test_getModified_returns_a_recent_timestamp() {
		$file = new FileModel( $this->makeFile( 'stamped.txt' ) );
		$ts   = $file->getModified();
		$this->assertIsInt( $ts );
		$this->assertGreaterThan( time() - 10, $ts );
	}

	public function test_getCreated_returns_a_recent_timestamp() {
		$file = new FileModel( $this->makeFile( 'created.txt' ) );
		$ts   = $file->getCreated();
		$this->assertIsInt( $ts );
		$this->assertGreaterThan( time() - 10, $ts );
	}

	public function test_getPermissions_returns_masked_bits() {
		$path = $this->makeFile( 'perm.txt' );
		chmod( $path, 0644 );
		$file = new FileModel( $path );

		$this->assertSame( 0644, $file->getPermissions() );
	}

	public function test_setPermissions_changes_the_underlying_mode() {
		$path = $this->makeFile( 'chmod.txt' );
		$file = new FileModel( $path );

		$file->setPermissions( 0600 );
		clearstatcache( true, $path );

		$this->assertSame( 0600, fileperms( $path ) & 0777 );
	}

	/*
	 * Contents / MIME / isImage
	 */

	public function test_getContents_returns_the_file_body() {
		$file = new FileModel( $this->makeFile( 'body.txt', 'payload' ) );
		$this->assertSame( 'payload', $file->getContents() );
	}

	public function test_isImage_true_for_valid_jpeg_bytes() {
		$file = new FileModel( $this->makeJpeg() );
		$this->assertTrue( $file->isImage() );
	}

	public function test_isImage_false_for_missing_file() {
		$file = new FileModel( $this->sandbox . '/no-such-image.jpg' );
		$this->assertFalse( $file->isImage() );
	}

	public function test_getMime_returns_image_jpeg_for_valid_jpeg() {
		$file = new FileModel( $this->makeJpeg() );
		$this->assertSame( 'image/jpeg', $file->getMime() );
	}

	/*
	 * Write operations — create / append / delete / copy / move
	 */

	public function test_create_touches_new_file_when_directory_exists() {
		$path = $this->sandbox . '/created.txt';
		$file = new FileModel( $path );
		$this->assertFalse( $file->exists() );

		$this->assertTrue( $file->create() );
		$this->assertFileExists( $path );
	}

	public function test_create_is_noop_when_file_already_exists() {
		$path = $this->makeFile( 'already.txt' );
		$file = new FileModel( $path );

		// Returns false because the create branch is only entered for missing files.
		$this->assertFalse( $file->create() );
		$this->assertFileExists( $path );
	}

	public function test_append_writes_content_to_existing_file() {
		$path = $this->makeFile( 'log.txt', 'line1' );
		$file = new FileModel( $path );

		$this->assertTrue( $file->append( "\nline2" ) );
		$this->assertSame( "line1\nline2", file_get_contents( $path ) );
	}

	public function test_append_creates_file_when_missing_then_writes() {
		$path = $this->sandbox . '/fresh.log';
		$file = new FileModel( $path );

		$this->assertTrue( $file->append( 'first' ) );
		$this->assertSame( 'first', file_get_contents( $path ) );
	}

	public function test_delete_removes_existing_file() {
		$path = $this->makeFile( 'todelete.txt' );
		$file = new FileModel( $path );

		$this->assertTrue( $file->delete() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_copy_duplicates_contents_to_destination() {
		$sourcePath = $this->makeFile( 'source.txt', 'copyme' );
		$destPath   = $this->sandbox . '/dest.txt';

		$source = new FileModel( $sourcePath );
		$dest   = new FileModel( $destPath );

		$this->assertTrue( $source->copy( $dest ) );
		$this->assertFileExists( $destPath );
		$this->assertSame( 'copyme', file_get_contents( $destPath ) );
		// Source is untouched by a copy.
		$this->assertFileExists( $sourcePath );
	}

	public function test_copy_returns_false_when_source_is_missing() {
		$source = new FileModel( $this->sandbox . '/absent-source.txt' );
		$dest   = new FileModel( $this->sandbox . '/dest.txt' );

		$this->assertFalse( $source->copy( $dest ) );
	}

	public function test_copy_returns_false_when_source_is_zero_bytes() {
		$sourcePath = $this->sandbox . '/empty.txt';
		touch( $sourcePath );
		$destPath = $this->sandbox . '/dest.txt';

		$source = new FileModel( $sourcePath );
		$dest   = new FileModel( $destPath );

		// Non-trusted mode refuses to copy zero-byte sources.
		$this->assertFalse( $source->copy( $dest ) );
		$this->assertFileDoesNotExist( $destPath );
	}

	public function test_move_deletes_source_after_copying() {
		$sourcePath = $this->makeFile( 'to-move.txt', 'moved' );
		$destPath   = $this->sandbox . '/moved.txt';

		$source = new FileModel( $sourcePath );
		$dest   = new FileModel( $destPath );

		$this->assertTrue( $source->move( $dest ) );
		$this->assertFileExists( $destPath );
		$this->assertFileDoesNotExist( $sourcePath );
	}

	/*
	 * getFileDir — provides a DirectoryModel for the file's parent
	 */

	public function test_getFileDir_returns_directory_model_of_parent() {
		$file = new FileModel( $this->makeFile( 'child.txt' ) );
		$dir  = $file->getFileDir();

		$this->assertInstanceOf( \ShortPixel\Model\File\DirectoryModel::class, $dir );
		$this->assertTrue( $dir->exists() );
	}

	/*
	 * resetStatus — clears the cached is_* / exists / filesize flags
	 */

	public function test_resetStatus_clears_cached_writable_and_exists() {
		$path = $this->makeFile( 'reset.txt' );
		$file = new FileModel( $path );

		$file->exists();
		$file->is_writable();

		$file->resetStatus();

		// Read reflection-side to confirm caches were cleared to null.
		$ref = new ReflectionClass( FileModel::class );
		foreach ( array( 'exists', 'is_writable', 'is_readable', 'is_file', 'filesize' ) as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$this->assertNull( $p->getValue( $file ), "Expected {$prop} to be null after resetStatus()." );
		}
	}

	/*
	 * __debuginfo — surfaces the model's key state as an array
	 */

	public function test_debuginfo_exposes_key_fields() {
		$file = new FileModel( $this->makeFile( 'debug.txt' ) );
		$info = $file->__debuginfo();

		$this->assertIsArray( $info );
		foreach ( array( 'fullpath', 'filename', 'filebase', 'directory', 'exists', 'is_writable', 'is_readable', 'is_virtual' ) as $key ) {
			$this->assertArrayHasKey( $key, $info );
		}
		$this->assertTrue( $info['exists'] );
		$this->assertSame( 'debug.txt', $info['filename'] );
	}
}
