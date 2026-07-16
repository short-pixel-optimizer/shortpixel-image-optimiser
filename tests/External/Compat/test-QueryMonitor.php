<?php
/**
 * Tests for ShortPixel\QueryMonitor — QM AJAX-dispatch silencer.
 *
 * Focus:
 *   - Constructor registers `shortpixel/queue/prepare_items`
 *   - addDispatchFilter registers `qm/dispatch/ajax`
 *   - dispatchFilter returns strict false (pinned sentinel)
 *   - panelEnd is a documented no-op (dead scaffolding)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\QueryMonitor;

class QueryMonitorTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		remove_all_actions( 'shortpixel/queue/prepare_items' );
		remove_all_filters( 'qm/dispatch/ajax' );
	}

	public function tear_down() {
		remove_all_actions( 'shortpixel/queue/prepare_items' );
		remove_all_filters( 'qm/dispatch/ajax' );
		parent::tear_down();
	}

	public function test_constructor_registers_the_prepare_items_action_on_this_instance() {
		$c = new QueryMonitor();

		$this->assertNotFalse(
			has_action( 'shortpixel/queue/prepare_items', array( $c, 'addDispatchFilter' ) )
		);
	}

	public function test_addDispatchFilter_registers_dispatchFilter_at_priority_20() {
		$c = new QueryMonitor();
		$c->addDispatchFilter();

		// Sentinel: assertSame( 20, ... ) — priority is load-bearing
		// (must run after QM's own default). A regression that dropped
		// the priority arg would default to 10 and this test would fail.
		$this->assertSame(
			20,
			has_filter( 'qm/dispatch/ajax', array( $c, 'dispatchFilter' ) )
		);
	}

	public function test_dispatchFilter_returns_strict_false_regardless_of_args() {
		$c = new QueryMonitor();

		// Sentinel: strict `assertSame( false, ... )` (not
		// `assertFalse`). A regression returning 0 / null / '' would
		// still be falsy but wouldn't sit correctly on QM's filter
		// chain (which uses `=== false`).
		$this->assertSame( false, $c->dispatchFilter() );
	}

	public function test_panelEnd_is_a_documented_noop_returning_null() {
		$c = new QueryMonitor();

		// Pinned no-op — legacy scaffolding for an unfinished feature.
		// If someone starts implementing panelEnd, the sentinel here
		// forces the test to be updated first, catching accidental
		// re-enablement.
		$this->assertNull( $c->panelEnd( null, null ) );
	}
}
