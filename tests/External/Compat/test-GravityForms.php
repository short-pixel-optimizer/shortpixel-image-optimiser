<?php
/**
 * Tests for ShortPixel\gravityForms — Gravity Forms compat shim.
 *
 * The whole class is currently DORMANT — the constructor's intended
 * `add_filter('gform_save_field_value', ...)` registration is
 * commented out with a `@todo All this off, because it can only fatal
 * error.` note.
 *
 * Focus:
 *   - Constructor registers NO hooks. If someone accidentally
 *     re-enables the filter registration, this pinned test would
 *     catch it before the fatal-error path could trigger.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\gravityForms;

// gravityforms.php is intentionally NOT in class/plugin.json's autoload
// files list — the whole class is dormant and only loaded on-demand.
// Pull it in explicitly so the test can reach it. Also triggers the
// file-tail `$g = new gravityForms();` line, which is why we
// remove_all_filters() below to reset any bleed from that boot.
require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/class/external/gravityforms.php';

class GravityFormsTest extends WP_UnitTestCase {

	public function test_constructor_registers_no_hooks_pinned_for_dormant_class() {
		remove_all_filters( 'gform_save_field_value' );

		$c = new gravityForms();

		// Sentinel: `has_filter($hook, $callback)` returns priority
		// (int) or false. A regression that uncomments the intended
		// `add_filter('gform_save_field_value', [$this, 'shortPixelGravityForms'])`
		// would flip this to an integer. If Bas ever DOES re-enable
		// the hook (after fixing the fatal-error path), this test
		// needs to be updated first — that's the intended tripwire.
		$this->assertFalse(
			has_filter( 'gform_save_field_value', array( $c, 'shortPixelGravityForms' ) )
		);
	}
}
