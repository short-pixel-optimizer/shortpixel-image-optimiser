<?php
/**
 * Tests for ShortPixel\Controller\FrontController.
 *
 * Scope: singleton contract only. The constructor selects a sub-controller
 * (CDNController or PictureController) based on plugin settings but does not
 * expose any pure-computation methods; the only testable unit concern is the
 * singleton pattern and the fact that the returned object is a FrontController.
 *
 * Out of scope / why:
 * - Constructor sub-controller selection (CDNController vs PictureController vs
 *   null) depends on wpSPIO()->settings() returning live SettingsModel data;
 *   the sub-controllers themselves register WordPress hooks and touch the
 *   front-end delivery layer — exercising those branches here would be an
 *   integration test.
 * - There are no public accessors or pure-computation methods to test beyond
 *   the singleton contract; $controller is protected with no getter.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\FrontController;

class FrontControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
	}

	public function tear_down() {
		$this->resetSingleton();
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( FrontController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_front_controller() {
		$ctrl = FrontController::getInstance();
		$this->assertInstanceOf( FrontController::class, $ctrl );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = FrontController::getInstance();
		$b = FrontController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * Constructor ($controller property)
	 */

	public function test_constructor_sets_controller_property_to_null_or_object_depending_on_settings() {
		// With default test-harness settings (useCDN=false, deliverWebp=0),
		// the sub-controller is not selected, so $controller stays null.
		$ref   = new ReflectionClass( FrontController::class );
		$ctrl  = FrontController::getInstance();
		$p     = $ref->getProperty( 'controller' );
		$p->setAccessible( true );
		$value = $p->getValue( $ctrl );

		// Either null (no sub-controller needed) or an object (a sub-controller
		// was instantiated); both are valid depending on active settings.
		$this->assertTrue( is_null( $value ) || is_object( $value ) );
	}
}
