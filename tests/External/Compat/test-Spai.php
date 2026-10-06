<?php
/**
 * Tests for ShortPixel\Spai — SPAI (adaptive images) compat shim.
 *
 * Focus:
 *   - Constructor defers wiring to `plugins_loaded`
 *   - preventCache defines DONOTCDN idempotently
 *
 * Skipped: `addHooks` gates on `plugin_active('spai')` + `wp_doing_ajax()`
 * + `$_REQUEST['action']` — those combine to require both a
 * live SPAI install and a fake AJAX context. Better as integration.
 *
 * NOTE: DONOTCDN is a global constant. Once defined, it stays
 * defined for the whole process — so `preventCache` may hit its
 * `defined()` short-circuit on second+ calls. Both branches reach
 * the same observable state (constant true), so tests can only
 * verify the post-condition, not which branch fired.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Spai;

class SpaiTest extends WP_UnitTestCase {

	public function test_constructor_defers_wiring_to_plugins_loaded() {
		$c = new Spai();

		// Sentinel: proves the class is NOT wiring hooks in
		// __construct directly. A regression that inlined the SPAI
		// detection into __construct would register a hook here even
		// on non-SPAI installs.
		$this->assertNotFalse(
			has_action( 'plugins_loaded', array( $c, 'addHooks' ) )
		);
	}

	public function test_preventCache_defines_DONOTCDN_to_true() {
		$c = new Spai();
		$c->preventCache();

		// Once defined this constant is sticky for the whole process
		// — but the post-condition ("defined and true") is what we
		// care about.
		$this->assertTrue( defined( 'DONOTCDN' ) );
		$this->assertTrue( DONOTCDN );
	}

	public function test_preventCache_is_idempotent_and_does_not_throw_on_second_call() {
		$c = new Spai();
		$c->preventCache();
		$c->preventCache(); // second call hits the `defined()` guard

		// Sentinel: proves the guard fires — a regression that
		// dropped the `!defined()` check would trigger a
		// "constant already defined" NOTICE, which under phpunit.xml's
		// `convertNoticesToExceptions=true` would throw.
		$this->assertTrue( defined( 'DONOTCDN' ) );
	}
}
