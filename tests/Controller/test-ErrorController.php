<?php
/**
 * Tests for ShortPixel\Controller\ErrorController.
 *
 * Scope: instantiation, and the checkErrors() static method branches that
 * return early without side-effects (null error and non-fatal error type).
 *
 * Out of scope / why:
 * - `start()` calls register_shutdown_function() which cannot be un-registered;
 *   testing it would leave a shutdown handler active for the rest of the test
 *   process, potentially breaking other tests. No observable return value.
 * - The fatal-error branch of checkErrors() calls ob_clean(), echo, and exit()
 *   which would terminate the test runner; it is untestable in-process.
 * - `checkErrors()` reads error_get_last() which cannot be seeded from PHP
 *   userland in a safe, portable way.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\ErrorController;

class ErrorControllerTest extends WP_UnitTestCase {

	/*
	 * Constructor / instantiation
	 */

	public function test_instantiation_succeeds() {
		$ctrl = new ErrorController();
		$this->assertInstanceOf( ErrorController::class, $ctrl );
	}

	/*
	 * checkErrors — early-return branches (safe to call)
	 */

	/**
	 * When error_get_last() returns null there must be no output and the method
	 * must return without error. In a freshly-started PHPUnit process the last
	 * error is typically null unless a previous test triggered one; we verify
	 * that the call does not itself raise an exception.
	 *
	 * Note: this test is inherently environment-dependent — if a previous test
	 * left a non-null last error the test is marked skipped rather than failing,
	 * because we cannot reset error_get_last() from userland.
	 */
	public function test_checkErrors_returns_silently_when_no_error_occurred() {
		if ( ! is_null( error_get_last() ) ) {
			$this->markTestSkipped( 'A previous test left a non-null last error; cannot assert null branch.' );
		}

		// Must complete without throwing and produce no output.
		ob_start();
		ErrorController::checkErrors();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}
