<?php
/**
 * Tests for ShortPixel\SpioBulk — the `wp spio bulk` command group.
 *
 * Focus area at the unit level:
 *   - getQueueController override — ALWAYS forces is_bulk=true,
 *     regardless of the argument passed. This is the only structural
 *     difference between SpioBulk and SpioCommandBase; every base
 *     command inherited into the bulk surface picks up this override
 *     transparently. If the override ever drifts back to honouring the
 *     $bulk flag, `wp spio bulk clear` / `wp spio bulk status` would
 *     silently start operating on the single-item queue.
 *   - Inheritance sanity — SpioBulk is-a SpioCommandBase.
 *
 * Skipped at the unit level (WP_CLI-output + BulkController territory):
 *   - start / auto / create / prepare / finishBulk — all wire directly
 *     into BulkController::getInstance() and stream progress via
 *     \WP_CLI::line / log. Integration tests are the right home.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\SpioBulk;
use ShortPixel\SpioCommandBase;
use ShortPixel\Controller\QueueController;

class SpioBulkTest extends WP_UnitTestCase {

	private function invokeProtected( SpioBulk $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( SpioBulk::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	private function getProtected( object $obj, string $class, string $prop ) {
		$ref = new ReflectionClass( $class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	/*
	 * Inheritance — trivial but worth pinning because the base class
	 * IS the reason base commands are available under `wp spio bulk`.
	 */

	public function test_SpioBulk_extends_SpioCommandBase() {
		$this->assertInstanceOf( SpioCommandBase::class, new SpioBulk() );
	}

	/*
	 * getQueueController override — the meat of this class.
	 */

	public function test_getQueueController_returns_a_QueueController_instance() {
		$c      = new SpioBulk();
		$result = $this->invokeProtected( $c, 'getQueueController' );

		$this->assertInstanceOf( QueueController::class, $result );
	}

	public function test_getQueueController_forces_bulk_mode_even_when_called_with_default_arg() {
		$c    = new SpioBulk();
		$qc   = $this->invokeProtected( $c, 'getQueueController' );
		$args = $this->getProtected( $qc, QueueController::class, 'args' );

		$this->assertTrue( $args['is_bulk'] );
	}

	public function test_getQueueController_forces_bulk_mode_even_when_called_with_false_explicitly() {
		$c    = new SpioBulk();
		$qc   = $this->invokeProtected( $c, 'getQueueController', array( false ) );
		$args = $this->getProtected( $qc, QueueController::class, 'args' );

		// Sentinel: the parent SpioCommandBase::getQueueController honours
		// the $bulk arg, so a `false` here would return non-bulk if the
		// override were removed. Explicit-false is the strongest signal
		// the override still fires.
		$this->assertTrue( $args['is_bulk'] );
	}

	public function test_getQueueController_forces_bulk_mode_when_called_with_true() {
		$c    = new SpioBulk();
		$qc   = $this->invokeProtected( $c, 'getQueueController', array( true ) );
		$args = $this->getProtected( $qc, QueueController::class, 'args' );

		// Rounds out the sentinel triplet (default / false / true) so a
		// regression that only bulk-modes on `true` (i.e. quietly reverts
		// to parent behaviour) would fail the previous test but pass this
		// one — the pair together is what pins the override.
		$this->assertTrue( $args['is_bulk'] );
	}
}
