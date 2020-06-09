<?php

use ShortPixel\Model\DirectoryModel as DirectoryModel;
use function tad\FunctionMockerLe\defineAll;
use function tad\FunctionMockerLe\undefineAll as undefineAll;

class DirectoryModelTest extends \Codeception\Test\Unit {

//	private DirectoryModel $directory;

	protected function _setUp() {

		return parent::_setUp();
	}

	protected function _before() {
		// Undefined Wordpress functions during unit tests
		undefineAll();
		parent::_before();

		// Mocking Wordpress included functions
		defineAll(['wp_normalize_path', 'trailingslashit'], function($path) {
			return $path;
		});
	}

	public function testGetName() {
		$directory = new DirectoryModel("/path/to/directory");
		$this->assertEquals($directory->getPath(), "/path/to/directory");
	}

	public function testGetNameWindow() {
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
