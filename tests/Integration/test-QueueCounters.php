<?php
/**
 * ShortQ queue counters (build/shortpixel/shortq) on a real queue table.
 *
 * WPQ keeps per-queue counters (items, in_process, done, errors,
 * fatal_errors) in its status option and changes them through
 * setStatusCount() as items move. A full recount from the table only runs
 * on a fresh status, when a counter would go negative, or after
 * `timeout_recount` seconds. Between recounts every change must still land,
 * or the bulk progress and the "queue is empty" checks (cron unscheduling,
 * bulk finish) lag behind for minutes.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\ShortQ\ShortQ;

class QueueCountersTest extends SPIO_IntegrationTestCase {

	private const SLUG = 'spiotestq';

	/** @var \ShortPixel\ShortQ\Queue\WPQ */
	private $q;

	public function set_up() {
		parent::set_up();
		$shortQ  = new ShortQ( self::SLUG );
		$this->q = $shortQ->getQueue( 'counters' );
		$this->q->setOption( 'mode', 'wait' );
		$this->q->install();
		$this->q->resetQueue();
	}

	public function tear_down() {
		$this->q->unInstall();
		parent::tear_down();
	}

	/**
	 * Marking a dequeued item done moves it from in_process to done at once,
	 * without waiting for the next recount.
	 *
	 * @todo WPQ::setStatusCount() (build/shortpixel/shortq, module update
	 *       cd5201c0) only writes the counter when a recount happened, so
	 *       outside a recount every change is dropped: done stays 0 and
	 *       in_process stays 1 here, and the single cron event is never
	 *       unscheduled (CronDispatchTest). Suggested fix, verified against
	 *       this file and the cron / bulk / API-failure integration tests:
	 *       `if ($recounted && false === $update_on_recount) return true;`
	 *       followed by `return $this->setStatus($name, $count + $change, $savenow);`.
	 *       This test stays red until then.
	 */
	public function test_item_done_moves_the_item_from_in_process_to_done_right_away() {
		$this->q->enqueue( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$items = $this->q->dequeue();

		// SENTINEL: one item was handed out and counted as in process.
		$this->assertCount( 1, $items, 'Sentinel: dequeue() hands out one item (numitems = 1).' );
		$this->assertSame( 1, (int) $this->q->getStatus( 'in_process' ), 'Sentinel: the dequeued item is in process.' );

		$this->q->itemDone( $items[0] );

		$this->assertSame( 1, (int) $this->q->getStatus( 'done' ), 'The done counter follows itemDone() right away.' );
		$this->assertSame( 0, (int) $this->q->getStatus( 'in_process' ), 'The in_process counter follows itemDone() right away.' );
	}

	/**
	 * A fatal failure is counted at once as well.
	 */
	public function test_fatal_item_failure_is_counted_right_away() {
		$this->q->enqueue( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$items = $this->q->dequeue();
		$this->assertCount( 1, $items, 'Sentinel: dequeue() hands out one item.' );

		$this->q->itemFailed( $items[0], true );

		$this->assertSame( 1, (int) $this->q->getStatus( 'fatal_errors' ), 'The fatal_errors counter follows itemFailed() right away.' );
	}
}
