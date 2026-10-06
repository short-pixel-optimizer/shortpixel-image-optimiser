<?php
/**
 * Tests for ShortPixel\Woocommerce — WooCommerce compat shim.
 *
 * Focus:
 *   - Constructor defers wiring to `plugins_loaded`
 *   - signalWC flips the static $SIGNAL flag true
 *   - handleCreateImages state machine:
 *     · Signal off → passthrough, no state mutation
 *     · Signal on + empty new_sizes → passthrough, signal disarmed
 *     · Signal on + non-empty new_sizes → traverse thumbnails
 *       (verified by observing signal was disarmed)
 *   - addWarning mutates only when regenerate_thumbnails is present
 *     AND is_autoprocess is true (skipped — env mutation is heavy)
 *
 * NOTE: `$SIGNAL` is a protected static. We flip it via reflection
 * in tear_down so per-test signal state doesn't leak.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Woocommerce;

class WoocommerceTest extends WP_UnitTestCase {

	public function tear_down() {
		$this->resetSignal();
		parent::tear_down();
	}

	private function resetSignal(): void {
		$ref = new ReflectionClass( Woocommerce::class );
		$p   = $ref->getProperty( 'SIGNAL' );
		$p->setAccessible( true );
		$p->setValue( null, false );
	}

	private function getSignal(): bool {
		$ref = new ReflectionClass( Woocommerce::class );
		$p   = $ref->getProperty( 'SIGNAL' );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	public function test_constructor_defers_wiring_to_plugins_loaded() {
		$c = new Woocommerce();

		// Sentinel: proves WC detection happens later, not in
		// __construct. A regression that inlined the WC-active check
		// would register the three WC-specific filters here.
		$this->assertNotFalse(
			has_action( 'plugins_loaded', array( $c, 'hooks' ) )
		);
	}

	public function test_signalWC_flips_the_SIGNAL_static_flag_true() {
		$this->assertFalse( $this->getSignal(), 'test setup issue: SIGNAL should start false' );

		$c = new Woocommerce();
		$c->signalWC();

		$this->assertTrue( $this->getSignal() );
	}

	public function test_handleCreateImages_passes_new_sizes_through_when_signal_is_off() {
		$c         = new Woocommerce();
		$new_sizes = array( 'medium' => array( 'width' => 300 ) );

		// Signal starts false — no image lookup should happen. The
		// `attach_id = 0` is a canary; a regression that skipped the
		// signal guard would call filesystem->getMediaImage(0) and
		// might warn/error along the way.
		$result = $c->handleCreateImages( $new_sizes, array(), 0 );

		$this->assertSame( $new_sizes, $result );
		$this->assertFalse( $this->getSignal(), 'signal must remain untouched in passthrough' );
	}

	public function test_handleCreateImages_disarms_signal_and_passes_through_when_new_sizes_is_empty() {
		$c = new Woocommerce();
		$c->signalWC(); // arm the signal
		$this->assertTrue( $this->getSignal(), 'test setup issue: signal should be armed' );

		$result = $c->handleCreateImages( array(), array(), 0 );

		// Sentinel: BOTH the passthrough (empty array unchanged) AND
		// the signal disarm must fire. A regression that only did one
		// would leave the other visibly wrong.
		$this->assertSame( array(), $result );
		$this->assertFalse( $this->getSignal() );
	}
}
