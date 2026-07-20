<?php
/**
 * Tests for ShortPixel\SpioCommandBase — the shared base class for
 * both WP-CLI command groups (`wp spio` and `wp spio bulk`).
 *
 * Focus areas (pure helpers that don't touch WP_CLI output):
 *   - getQueueArgument     — parses --queue=… into a queue-name list
 *   - unFormatNumber       — strips comma / period thousand separators
 *   - textBoolean          — localised Yes/No for the settings table
 *   - getQueueController   — factory (default: is_bulk=false)
 *   - showResponses        — pinned no-op stub returning false
 *
 * Skipped at the unit level (WP_CLI-output territory):
 *   - add / run / runClick / status / settings / clear / removebackups —
 *     wire directly into \WP_CLI::Error / Success / line / log, plus the
 *     queue controller's real DB path. Better as integration tests.
 *   - displayResult / displayStatsLine — both stream to \WP_CLI::line.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\SpioCommandBase;
use ShortPixel\Controller\QueueController;

class SpioCommandBaseTest extends WP_UnitTestCase {

	private function invokeProtected( SpioCommandBase $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( SpioCommandBase::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	private function invokePrivate( SpioCommandBase $c, string $method, array $args = array() ) {
		return $this->invokeProtected( $c, $method, $args );
	}

	private function getProtected( object $obj, string $class, string $prop ) {
		$ref = new ReflectionClass( $class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	/*
	 * getQueueArgument — parses the --queue= associative arg into an
	 * ordered list of queue names.
	 */

	public function test_getQueueArgument_defaults_to_both_queues_when_flag_omitted() {
		$c      = new SpioCommandBase();
		$result = $this->invokeProtected( $c, 'getQueueArgument', array( array() ) );

		// Sentinel: assertSame (not assertEquals) catches a regression
		// where order is inverted — the base commands and BulkController
		// iterate this array in-order, so media-then-custom matters.
		$this->assertSame( array( 'media', 'custom' ), $result );
	}

	public function test_getQueueArgument_returns_single_entry_when_flag_scopes_to_one_queue() {
		$c = new SpioCommandBase();

		$this->assertSame(
			array( 'media' ),
			$this->invokeProtected( $c, 'getQueueArgument', array( array( 'queue' => 'media' ) ) )
		);
		// Sentinel: verify with a distinct value so a hardcoded
		// "return ['media']" regression is caught.
		$this->assertSame(
			array( 'custom' ),
			$this->invokeProtected( $c, 'getQueueArgument', array( array( 'queue' => 'custom' ) ) )
		);
	}

	public function test_getQueueArgument_splits_comma_separated_list_into_ordered_entries() {
		$c = new SpioCommandBase();

		$this->assertSame(
			array( 'media', 'custom' ),
			$this->invokeProtected( $c, 'getQueueArgument', array( array( 'queue' => 'media,custom' ) ) )
		);
		// Reverse-order sentinel: proves the split preserves caller
		// order, not that we just re-hardcoded the default.
		$this->assertSame(
			array( 'custom', 'media' ),
			$this->invokeProtected( $c, 'getQueueArgument', array( array( 'queue' => 'custom,media' ) ) )
		);
	}

	public function test_getQueueArgument_sanitizes_each_entry() {
		$c = new SpioCommandBase();

		// sanitize_text_field strips control characters + trims. Verify
		// that path fires per-entry (not just on the raw string).
		$result = $this->invokeProtected(
			$c,
			'getQueueArgument',
			array( array( 'queue' => "media\0,custom\n" ) )
		);

		$this->assertSame( array( 'media', 'custom' ), $result );
	}

	/*
	 * unFormatNumber — strip comma + period so a formatted total can be
	 * compared as an integer for --limit.
	 */

	public function test_unFormatNumber_strips_comma_thousand_separators() {
		$c = new SpioCommandBase();

		$this->assertSame( '1234', $this->invokePrivate( $c, 'unFormatNumber', array( '1,234' ) ) );
	}

	public function test_unFormatNumber_strips_period_thousand_separators() {
		$c = new SpioCommandBase();

		$this->assertSame( '1234', $this->invokePrivate( $c, 'unFormatNumber', array( '1.234' ) ) );
	}

	public function test_unFormatNumber_strips_both_comma_and_period_together() {
		$c = new SpioCommandBase();

		// Sentinel: distinct multi-segment input catches a regression
		// where only one of the two str_replace calls survives.
		$this->assertSame( '9876543', $this->invokePrivate( $c, 'unFormatNumber', array( '9,876,543' ) ) );
		$this->assertSame( '123456', $this->invokePrivate( $c, 'unFormatNumber', array( '1,234.56' ) ) );
	}

	public function test_unFormatNumber_leaves_pure_digit_string_unchanged() {
		$c = new SpioCommandBase();

		$this->assertSame( '4200', $this->invokePrivate( $c, 'unFormatNumber', array( '4200' ) ) );
	}

	/*
	 * textBoolean — Yes/No rendering. The `$colored` arg is force-set to
	 * false at the top of the method because of an upstream php-cli-tools
	 * bug. That "force false" is the sentinel we're pinning.
	 */

	public function test_textBoolean_returns_localised_Yes_for_truthy() {
		$c = new SpioCommandBase();

		$this->assertSame( 'Yes', $this->invokePrivate( $c, 'textBoolean', array( true, false ) ) );
	}

	public function test_textBoolean_returns_localised_No_for_falsy() {
		$c = new SpioCommandBase();

		$this->assertSame( 'No', $this->invokePrivate( $c, 'textBoolean', array( false, false ) ) );
	}

	public function test_textBoolean_ignores_colored_flag_because_of_upstream_bug() {
		$c = new SpioCommandBase();

		// Sentinel: caller passes $colored=true, but the method forces
		// it back to false, so no `%g`/`%r` colorize tokens should leak
		// into the output. Regressions that re-enable coloring (before
		// wp-cli/php-cli-tools#134 is fixed) would surface here.
		$this->assertSame( 'Yes', $this->invokePrivate( $c, 'textBoolean', array( true, true ) ) );
		$this->assertSame( 'No', $this->invokePrivate( $c, 'textBoolean', array( false, true ) ) );
	}

	/*
	 * getQueueController — factory. Defaults to is_bulk=false; caller
	 * can flip it. SpioBulk overrides this to force bulk=true — that
	 * override is tested in test-SpioBulk.php.
	 */

	public function test_getQueueController_returns_a_QueueController_instance() {
		$c      = new SpioCommandBase();
		$result = $this->invokeProtected( $c, 'getQueueController' );

		$this->assertInstanceOf( QueueController::class, $result );
	}

	public function test_getQueueController_defaults_to_non_bulk_mode() {
		$c    = new SpioCommandBase();
		$qc   = $this->invokeProtected( $c, 'getQueueController' );
		$args = $this->getProtected( $qc, QueueController::class, 'args' );

		$this->assertFalse( $args['is_bulk'] );
	}

	public function test_getQueueController_honours_the_bulk_argument_when_true() {
		$c    = new SpioCommandBase();
		$qc   = $this->invokeProtected( $c, 'getQueueController', array( true ) );
		$args = $this->getProtected( $qc, QueueController::class, 'args' );

		// Sentinel pair with the previous test: both branches (default
		// vs. explicit `true`) prove the passed flag actually reaches
		// the constructor rather than being ignored.
		$this->assertTrue( $args['is_bulk'] );
	}

	/*
	 * showResponses — currently a no-op stub. It's called from three
	 * places (run(), SpioBulk::create(), SpioSingle::restore()); if a
	 * regression turns the return type to void, that call chain would
	 * silently break in a way that's hard to spot. Pinning to `false`.
	 */

	public function test_showResponses_returns_false_because_it_is_a_stub_pinned_for_deferred_fix() {
		$c = new SpioCommandBase();

		// Strict `assertSame` (not `assertFalse`) so a regression that
		// returns null / 0 / '' would also fail — those are all falsy
		// but they'd mean the stub was half-replaced.
		$this->assertSame( false, $this->invokeProtected( $c, 'showResponses' ) );
	}
}
