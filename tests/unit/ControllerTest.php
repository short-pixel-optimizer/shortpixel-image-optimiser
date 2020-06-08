<?php

use ShortPixel\Controller;

class ControllerTest extends \Codeception\Test\Unit {

	public function testInit() {
		$controller = $this->make('ShortPixel\Controller', ['checkUserPrivileges' => true]);
	}

	public function test__construct() {
		$controller = new Controller();
	}

	public function testSetControllerURL() {

	}

	public function testLoadView() {

	}

	public function testSetShortPixel() {

	}
}
