<?php

use ShortPixel\Model\DirectoryModel as DirectoryModel;
use function tad\FunctionMockerLe\define as defineFunction;
use function tad\FunctionMockerLe\defineAll;
use function tad\FunctionMockerLe\undefineAll as undefineAll;

class DirectoryModelTest extends \Codeception\Test\Unit {

//	private DirectoryModel $directory;

	protected function _setUp() {

		return parent::_setUp();
	}

	protected function _before() {
		parent::_before();

		// Undefined Wordpress functions during unit tests
		undefineAll();

		// Mocking Wordpress included functions
		defineAll(['wp_normalize_path', 'trailingslashit'], function($path) {
			return $path;
		});
	}

	public function testGetName() {
		$directory = new DirectoryModel("/path/to/directory");
		$this->assertEquals($directory->getPath(), "/path/to/directory");
	}

	public function testGetNameWindows() {
		defineFunction( 'wp_normalize_path', function ( $path ) {
			return "C:/xampp/htdocs/boot_strap/wp-content/themes/boot_Strap/inc";
		} );
		$directory = new DirectoryModel("C:\\xampp\\htdocs\\boot_strap/wp-content/themes/boot_Strap/inc");
		$this->assertEquals($directory->getPath(), "C:/xampp/htdocs/boot_strap/wp-content/themes/boot_Strap/inc");
	}

	public function test__construct() {

	}

	public function testGetPath() {

	}

	public function testCheck() {

	}

	public function testExists() {

	}

	public function testIsSubFolderOf() {

	}

	public function testGetParent() {

	}

	public function testIs_readable() {

	}

	public function testGetModified() {

	}

	public function testIs_writable() {

	}

	public function test__toString() {

	}

	public function testGetRelativePath() {

	}

	public function testGetSubDirectories() {

	}

	public function testGetFiles() {

	}
}
