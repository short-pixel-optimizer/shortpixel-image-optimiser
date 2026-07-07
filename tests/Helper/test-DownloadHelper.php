<?php
/**
 * Tests for ShortPixel\Helper\DownloadHelper.
 *
 * Coverage is intentionally limited to the parts that can be exercised without
 * making real HTTP calls: singleton behaviour, environment bootstrapping, and
 * the initial state of the last-error accessor. The download pipeline itself
 * (downloadFile / downloadURLMethod / remoteGetMethod / moveDownload /
 * setPreferredProtocol) requires HTTP mocking and a real filesystem target and
 * is better covered by integration tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\DownloadHelper;

class DownloadHelperTest extends WP_UnitTestCase {

	public function test_getInstance_returns_singleton() {
		$a = DownloadHelper::getInstance();
		$b = DownloadHelper::getInstance();
		$this->assertInstanceOf( DownloadHelper::class, $a );
		$this->assertSame( $a, $b, 'getInstance() must return the same instance on repeated calls.' );
	}

	public function test_constructor_loads_download_url_function() {
		// download_url() lives in wp-admin/includes/file.php which is not loaded on
		// the frontend. Instantiating DownloadHelper must pull it in.
		DownloadHelper::getInstance();
		$this->assertTrue( function_exists( 'download_url' ), 'DownloadHelper::checkEnv() should require wp-admin/includes/file.php.' );
	}

	public function test_getLastError_is_null_before_any_download_attempt() {
		// Re-create the singleton with reflection so we get a fresh instance
		// unaffected by any earlier tests in the same run.
		$ref  = new ReflectionClass( DownloadHelper::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		$helper = DownloadHelper::getInstance();
		$this->assertNull( $helper->getLastError() );
	}

	/*
	 * getMaxDownloadTime (private — invoked via reflection while temporarily
	 * overriding PHP's max_execution_time ini value).
	 */

	private function invokeGetMaxDownloadTime(): int {
		$ref    = new ReflectionClass( DownloadHelper::class );
		$method = $ref->getMethod( 'getMaxDownloadTime' );
		$method->setAccessible( true );
		return (int) $method->invoke( DownloadHelper::getInstance() );
	}

	public function test_getMaxDownloadTime_caps_at_twenty_five_seconds() {
		// executionTime = 60 → min(50, 25) = 25.
		$previous = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '60' );
		try {
			$this->assertSame( 25, $this->invokeGetMaxDownloadTime() );
		} finally {
			ini_set( 'max_execution_time', $previous );
		}
	}

	public function test_getMaxDownloadTime_leaves_ten_second_buffer_below_cap() {
		// executionTime = 30 → min(20, 25) = 20.
		$previous = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '30' );
		try {
			$this->assertSame( 20, $this->invokeGetMaxDownloadTime() );
		} finally {
			ini_set( 'max_execution_time', $previous );
		}
	}

	public function test_getMaxDownloadTime_enforces_ten_second_minimum() {
		// executionTime = 15 → min(5, 25) = 5 → floored to 10.
		$previous = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '15' );
		try {
			$this->assertSame( 10, $this->invokeGetMaxDownloadTime() );
		} finally {
			ini_set( 'max_execution_time', $previous );
		}
	}

	public function test_getMaxDownloadTime_treats_zero_execution_time_as_edge_case() {
		// executionTime = 0 (unlimited in PHP CLI) → min(-10, 25) = -10 → floored to 10.
		$previous = ini_get( 'max_execution_time' );
		ini_set( 'max_execution_time', '0' );
		try {
			$this->assertSame( 10, $this->invokeGetMaxDownloadTime() );
		} finally {
			ini_set( 'max_execution_time', $previous );
		}
	}
}
