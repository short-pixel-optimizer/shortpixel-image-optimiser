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

	public function testGetPath() {
		$directory = $this->directoryFactory('Linux');
		$this->assertEquals($directory->getPath(), "/path/to/directory");
	}

	public function testGetPathWindows() {
		defineFunction( 'wp_normalize_path', function ( $path ) {
			return "C:/xampp/htdocs/boot_strap/wp-content/themes/boot_Strap/inc";
		} );
		$directory = $this->directoryFactory('Windows');
		$this->assertEquals($directory->getPath(), "C:/xampp/htdocs/boot_strap/wp-content/themes/boot_Strap/inc");
	}

	public function test__construct() {

	}

	public function testGetName() {

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

	/**
	 * @param string $string
	 *
	 * @return DirectoryModel
	 */
	private function directoryFactory( string $string): DirectoryModel {

		switch ($string){
			case 'Windows':
				$directory = new DirectoryModel( "C:\\xampp\\htdocs\\boot_strap/wp-content/themes/boot_Strap/inc" );
				break;
			default:
				$directory = new DirectoryModel( "/path/to/directory" );
				break;
		}

		return $directory;
	}
}
