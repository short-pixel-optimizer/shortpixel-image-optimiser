<?php
/**
 * Tests for visualComp — Visual Composer (WPBakery) compat shim.
 *
 * Focus:
 *   - Constructor registers `shortpixel/init/automedialibrary`
 *   - check_vcinline passes $bool through when vc_action doesn't
 *     exist (test env baseline)
 *
 * NOTE: The `vc_action() == 'vc_inline'` branch can't be exercised
 * without loading the real WPBakery plugin, so it's skipped at the
 * unit level.
 *
 * Global namespace: `visualComp` is declared outside `ShortPixel\`
 * (the only file in class/external/ that isn't namespaced).
 *
 * @package Shortpixel_Image_Optimiser
 */

class VisualCompTest extends WP_UnitTestCase {

	public function test_constructor_registers_the_automedialibrary_filter_on_this_instance() {
		$c = new visualComp();
		$this->assertNotFalse(
			has_filter( 'shortpixel/init/automedialibrary', array( $c, 'check_vcinline' ) )
		);
	}

	public function test_check_vcinline_passes_bool_through_when_vc_action_function_is_missing() {
		$c = new visualComp();

		// In test env WPBakery isn't loaded → vc_action doesn't exist
		// → the guarded branch never fires and $bool is returned
		// unchanged.
		$this->assertTrue( $c->check_vcinline( true ) );
		// Sentinel: distinct value catches a hardcoded `return true`
		// regression.
		$this->assertFalse( $c->check_vcinline( false ) );
	}
}
