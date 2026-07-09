<?php
/**
 * Smoke tests confirming the plugin bootstraps inside the WordPress test suite.
 *
 * These are intentionally light — they verify the CI toolchain (WP test lib +
 * plugin autoloader) is wired up correctly before adding heavier integration
 * tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

class PluginLoadedTest extends WP_UnitTestCase {

	public function test_plugin_bootstrap_function_exists() {
		$this->assertTrue( function_exists( 'wpSPIO' ), 'wpSPIO() bootstrap function should be defined once the plugin is loaded.' );
	}

	public function test_plugin_constants_are_defined() {
		$this->assertTrue( defined( 'SHORTPIXEL_PLUGIN_FILE' ) );
		$this->assertTrue( defined( 'SHORTPIXEL_PLUGIN_DIR' ) );
		$this->assertTrue( defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ) );
	}

	public function test_autoloader_resolves_namespaced_class() {
		$this->assertTrue( class_exists( 'ShortPixel\\Helper\\UtilHelper' ), 'PSR-4 autoloader should resolve ShortPixel\\Helper\\UtilHelper.' );
	}
}
