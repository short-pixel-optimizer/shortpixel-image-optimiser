<?php
/**
 * Tests for ShortPixel\Model\File\DirectoryModel.
 *
 * Uses a per-test-run sandbox directory populated with real fixtures for the
 * methods that need to touch the filesystem (existence, sub-directory / file
 * enumeration, size, mkdir/rmdir, permission checks).
 *
 * Skipped at the unit level:
 *   - getRelativePath()        → depends on get_home_path() and the WP install layout
 *   - reverseConstructPath()   → tightly coupled to specific WP paths
 *   - constructUsualDirectories() → tightly coupled to specific WP paths
 *   - recursiveDelete()        → the safety check refuses to delete anything
 *                                outside the WP uploads dir; not safely testable
 *                                against a temp sandbox
 *   - virtual URL constructor branch → needs a live filesystem controller URL context
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\File\DirectoryModel;
use ShortPixel\Model\File\FileModel;

class DirectoryModelTest extends WP_UnitTestCase {

	/**
	 * Sandbox directory for the current test run.
	 * @var string
	 */
	private $sandbox;

	public function set_up() {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/spio-dirmodel-' . uniqid();
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

	private function makeFile( string $relPath, string $contents = 'x' ): string {
		$path = $this->sandbox . '/' . $relPath;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $path, $contents );
		return $path;
	}

	private function makeDir( string $relPath ): string {
		$path = $this->sandbox . '/' . $relPath;
		if ( ! is_dir( $path ) ) {
			mkdir( $path, 0755, true );
		}
		return $path;
	}

	private function invokePrivate( DirectoryModel $dir, string $method, array $args = array() ) {
		$ref = new ReflectionClass( DirectoryModel::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $dir, ...$args );
	}

	/*
	 * Constructor and path accessors
	 */

	public function test_getPath_returns_path_with_trailing_slash() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertSame( trailingslashit( $this->sandbox ), $dir->getPath() );
	}

	public function test_toString_returns_the_path() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertSame( trailingslashit( $this->sandbox ), (string) $dir );
	}

	public function test_getName_returns_directory_basename() {
		$sub = $this->makeDir( 'my-sub' );
		$dir = new DirectoryModel( $sub );
		$this->assertSame( 'my-sub', $dir->getName() );
	}

	public function test_empty_path_input_yields_empty_path() {
		$dir = new DirectoryModel( '' );
		$this->assertSame( '', $dir->getPath() );
	}

	public function test_file_path_input_strips_to_parent_directory() {
		// Constructor detects a file-like input (pathinfo extension present) and
		// stores the parent directory instead.
		$filePath = $this->makeFile( 'note.txt' );
		$dir      = new DirectoryModel( $filePath );

		$this->assertSame( trailingslashit( $this->sandbox ), $dir->getPath() );
	}

	/*
	 * Existence probes
	 */

	public function test_exists_true_for_present_directory() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->exists() );
	}

	public function test_exists_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/never-created' );
		$this->assertFalse( $dir->exists() );
	}

	public function test_is_readable_reflects_state() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->is_readable() );
	}

	public function test_is_writable_reflects_state() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->is_writable() );
	}

	public function test_is_virtual_null_or_false_for_local_paths() {
		$dir = new DirectoryModel( $this->sandbox );
		// The is_virtual field is left null for local paths (only set to true
		// in the URL branch of the constructor). Accept either loose falsy.
		$this->assertNotTrue( $dir->is_virtual() );
	}

	/*
	 * Metadata — getModified, getPermissions, getPermissionRecursive
	 */

	public function test_getModified_returns_a_recent_timestamp() {
		$dir = new DirectoryModel( $this->sandbox );
		$ts  = $dir->getModified();
		$this->assertIsInt( $ts );
		$this->assertGreaterThan( time() - 30, $ts );
	}

	public function test_getPermissions_returns_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/absent' );
		$this->assertFalse( $dir->getPermissions() );
	}

	public function test_getPermissions_returns_integer_for_existing_directory() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertIsInt( $dir->getPermissions() );
	}

	public function test_getPermissionRecursive_returns_ancestor_permissions_when_self_missing() {
		$missing = $this->sandbox . '/nested/deep/never';
		$dir     = new DirectoryModel( $missing );

		// Walks up to the sandbox, which exists and has permission bits.
		$perms = $dir->getPermissionRecursive();
		$this->assertIsInt( $perms );
	}

	/*
	 * Parent / subfolder relationships
	 */

	public function test_getParent_returns_directory_model_for_the_parent_dir() {
		$sub = $this->makeDir( 'child' );
		$dir = new DirectoryModel( $sub );

		$parent = $dir->getParent();
		$this->assertInstanceOf( DirectoryModel::class, $parent );
		$this->assertSame( trailingslashit( $this->sandbox ), $parent->getPath() );
	}

	public function test_isSubFolderOf_true_when_path_starts_with_parent_path() {
		$sub = $this->makeDir( 'child/grand' );

		$parentDir = new DirectoryModel( $this->sandbox );
		$subDir    = new DirectoryModel( $sub );

		$this->assertTrue( $subDir->isSubFolderOf( $parentDir ) );
	}

	public function test_isSubFolderOf_false_for_identical_paths() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertFalse( $dir->isSubFolderOf( $dir ) );
	}

	public function test_isSubFolderOf_false_for_unrelated_paths() {
		$other = new DirectoryModel( sys_get_temp_dir() );
		$sub   = new DirectoryModel( $this->makeDir( 'unrelated' ) );

		$another = new DirectoryModel( $this->sandbox . '-different' );
		$this->assertFalse( $sub->isSubFolderOf( $another ) );
	}

	/*
	 * getFiles / getSubDirectories / getFolderSize
	 */

	public function test_getFiles_returns_file_models_for_direct_children() {
		$this->makeFile( 'a.txt' );
		$this->makeFile( 'b.txt' );
		$this->makeDir( 'ignored-subdir' );

		$dir   = new DirectoryModel( $this->sandbox );
		$files = $dir->getFiles();

		$this->assertIsArray( $files );
		$this->assertCount( 2, $files );
		foreach ( $files as $file ) {
			$this->assertInstanceOf( FileModel::class, $file );
		}
	}

	public function test_getFiles_returns_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/never' );
		$this->assertFalse( $dir->getFiles() );
	}

	public function test_getFiles_respects_include_files_filter() {
		$this->makeFile( 'keep-me.txt' );
		$this->makeFile( 'skip-me.txt' );

		$dir   = new DirectoryModel( $this->sandbox );
		$files = $dir->getFiles( array( 'include_files' => array( 'keep' ) ) );

		$this->assertCount( 1, $files );
		$this->assertStringContainsString( 'keep-me.txt', $files[0]->getRawFullPath() );
	}

	public function test_getFiles_respects_exclude_files_filter() {
		$this->makeFile( 'keep-me.txt' );
		$this->makeFile( 'drop-me.txt' );

		$dir   = new DirectoryModel( $this->sandbox );
		$files = $dir->getFiles( array( 'exclude_files' => array( 'drop' ) ) );

		$this->assertCount( 1, $files );
		$this->assertStringContainsString( 'keep-me.txt', $files[0]->getRawFullPath() );
	}

	public function test_getFiles_drops_webp_when_companion_source_exists() {
		// A .webp alongside a .jpg with the same basename is treated as a
		// generated variant and hidden from the listing.
		//
		// Note: this filter branch only runs when getFiles() sees at least one
		// non-null filter argument (has_filters=true), so pass a benign date
		// gate that keeps every real file.
		$this->makeFile( 'photo.jpg' );
		$this->makeFile( 'photo.webp' );
		$this->makeFile( 'standalone.webp' );

		$dir   = new DirectoryModel( $this->sandbox );
		$files = $dir->getFiles( array( 'date_older' => PHP_INT_MAX ) );

		$names = array_map(
			static function ( FileModel $f ) {
				return $f->getFileName();
			},
			$files
		);
		$this->assertContains( 'photo.jpg',       $names );
		$this->assertContains( 'standalone.webp', $names );
		$this->assertNotContains( 'photo.webp',   $names, 'Companion webp should be filtered out.' );
	}

	public function test_getSubDirectories_returns_directory_models_for_children() {
		$this->makeDir( 'child-a' );
		$this->makeDir( 'child-b' );
		$this->makeFile( 'file.txt' );

		$dir  = new DirectoryModel( $this->sandbox );
		$subs = $dir->getSubDirectories();

		$this->assertIsArray( $subs );
		$this->assertCount( 2, $subs );
		foreach ( $subs as $sub ) {
			$this->assertInstanceOf( DirectoryModel::class, $sub );
			$this->assertTrue( $sub->exists() );
		}
	}

	public function test_getSubDirectories_returns_false_for_missing_directory() {
		$dir = new DirectoryModel( $this->sandbox . '/nope' );
		$this->assertFalse( $dir->getSubDirectories() );
	}

	public function test_getFolderSize_sums_direct_and_nested_file_sizes() {
		$this->makeFile( 'a.txt',       str_repeat( 'a', 100 ) );
		$this->makeFile( 'sub/b.txt',   str_repeat( 'b', 250 ) );
		$this->makeFile( 'sub/c.txt',   str_repeat( 'c', 150 ) );

		$dir = new DirectoryModel( $this->sandbox );
		$this->assertSame( 500, $dir->getFolderSize() );
	}

	/*
	 * check() — creates missing directory and applies write permissions
	 */

	public function test_check_creates_missing_directory() {
		$path = $this->sandbox . '/nested/created-by-check';
		$dir  = new DirectoryModel( $path );
		$this->assertFalse( $dir->exists() );

		$this->assertTrue( $dir->check() );
		$this->assertDirectoryExists( $path );
	}

	public function test_check_returns_true_for_already_existing_writable_directory() {
		$dir = new DirectoryModel( $this->sandbox );
		$this->assertTrue( $dir->check( true ) );
	}

	public function test_check_is_idempotent_on_existing_directory() {
		$path = $this->makeDir( 'idempotent' );
		$dir  = new DirectoryModel( $path );

		$this->assertTrue( $dir->check() );
		$this->assertTrue( $dir->check() );
		$this->assertDirectoryExists( $path );
	}

	/*
	 * delete()
	 */

	public function test_delete_removes_empty_directory() {
		$path = $this->makeDir( 'to-remove' );
		$dir  = new DirectoryModel( $path );

		$this->assertTrue( $dir->delete() );
		$this->assertDirectoryDoesNotExist( $path );
	}

	public function test_delete_returns_false_for_non_empty_directory() {
		$path = $this->makeDir( 'not-empty' );
		$this->makeFile( 'not-empty/leaf.txt' );

		$dir = new DirectoryModel( $path );
		// rmdir() refuses to remove a directory with contents.
		$this->assertFalse( @$dir->delete() );
		$this->assertDirectoryExists( $path );
	}

	/*
	 * fileFilter (private) — invoked via reflection
	 */

	public function test_fileFilter_include_files_substring_match() {
		$path = $this->makeFile( 'wanted.txt' );
		$dir  = new DirectoryModel( $this->sandbox );
		$file = new FileModel( $path );

		$this->assertTrue(  $this->invokePrivate( $dir, 'fileFilter', array( $file, array( 'include_files' => array( 'wanted' ), 'exclude_files' => null, 'date_newer' => null, 'date_older' => null, 'date_created_older' => null ) ) ) );
		$this->assertFalse( $this->invokePrivate( $dir, 'fileFilter', array( $file, array( 'include_files' => array( 'other' ),  'exclude_files' => null, 'date_newer' => null, 'date_older' => null, 'date_created_older' => null ) ) ) );
	}

	public function test_fileFilter_exclude_files_substring_match() {
		$path = $this->makeFile( 'blocked.log' );
		$dir  = new DirectoryModel( $this->sandbox );
		$file = new FileModel( $path );

		$this->assertFalse( $this->invokePrivate( $dir, 'fileFilter', array( $file, array( 'include_files' => null, 'exclude_files' => array( 'blocked' ), 'date_newer' => null, 'date_older' => null, 'date_created_older' => null ) ) ) );
	}

	public function test_fileFilter_date_newer_gate() {
		$path = $this->makeFile( 'oldish.txt' );
		// Push mtime a day into the past so a "newer than now" filter drops it.
		touch( $path, time() - DAY_IN_SECONDS );

		$dir  = new DirectoryModel( $this->sandbox );
		$file = new FileModel( $path );

		$this->assertFalse( $this->invokePrivate( $dir, 'fileFilter', array( $file, array( 'include_files' => null, 'exclude_files' => null, 'date_newer' => time(), 'date_older' => null, 'date_created_older' => null ) ) ) );
	}

	public function test_fileFilter_default_args_keep_the_file() {
		$path = $this->makeFile( 'kept.txt' );
		$dir  = new DirectoryModel( $this->sandbox );
		$file = new FileModel( $path );

		$this->assertTrue( $this->invokePrivate( $dir, 'fileFilter', array( $file, array( 'include_files' => null, 'exclude_files' => null, 'date_newer' => null, 'date_older' => null, 'date_created_older' => null ) ) ) );
	}
}
