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
	 * without waiting for the next recount. The cron and bulk "queue is
	 * empty" checks read these counters, so a change that only lands at the
	 * next recount keeps the single cron event scheduled for minutes.
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

	/**
	 * A timed-out item goes back to waiting in the counters at once: both
	 * counters are moved by inProcessTimeout() without a recount.
	 */
	public function test_timed_out_item_returns_to_waiting_in_the_counters_right_away() {
		$this->q->enqueue( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$items = $this->q->dequeue();
		$this->assertCount( 1, $items, 'Sentinel: dequeue() hands out one item.' );
		$this->assertSame( 1, (int) $this->q->getStatus( 'items' ), 'Sentinel: one item is left waiting.' );

		// Every in-process item counts as timed out.
		$this->q->setOption( 'process_timeout', 0 );
		$updated = $this->q->inProcessTimeout();

		$this->assertSame( 1, (int) $updated, 'Sentinel: one item was requeued.' );
		$this->assertSame( 0, (int) $this->q->getStatus( 'in_process' ), 'The requeued item left in_process.' );
		$this->assertSame( 2, (int) $this->q->getStatus( 'items' ), 'The requeued item is waiting again.' );
	}

	/**
	 * PIN — when the timeout pass falls on a recount, the waiting counter is
	 * overstated by the number of requeued items until the next recount.
	 *
	 * @todo WPQ::inProcessTimeout() (build/shortpixel/shortq) moves the rows
	 *       first and then calls setStatusCount() twice with
	 *       `$update_on_recount` false. That flag only protects the call
	 *       that recounts: the in_process call recounts (the fresh count
	 *       already has the requeued item waiting), the items call no longer
	 *       sees a recount timeout and adds the requeued items on top.
	 *       Suggested fix: call resetInternalCounts() once after the row
	 *       updates instead of the two setStatusCount() calls.
	 *       Fixed when `items` equals the 2 rows waiting in the table.
	 */
	public function test_timeout_pass_on_a_recount_overstates_the_waiting_counter_pinned_for_deferred_fix() {
		$this->q->enqueue( array( array( 'id' => 1 ), array( 'id' => 2 ) ) );
		$items = $this->q->dequeue();
		$this->assertCount( 1, $items, 'Sentinel: dequeue() hands out one item.' );

		$this->q->setOption( 'process_timeout', 0 );
		// The last recount is long ago, so the next counter change recounts.
		$this->q->setStatus( 'last_count_time', 0 );
		$updated = $this->q->inProcessTimeout();

		// SENTINELS: one item was requeued, the table holds 2 waiting rows and the recount ran.
		$this->assertSame( 1, (int) $updated, 'Sentinel: one item was requeued.' );
		$by_status = $this->q->itemSum( 'countbystatus' );
		$this->assertSame( 2, (int) $by_status[ ShortQ::QSTATUS_WAITING ], 'Sentinel: two rows are waiting in the table.' );
		$this->assertGreaterThan( 0, (int) $this->q->getStatus( 'last_count_time' ), 'Sentinel: the counters were recounted.' );
		$this->assertSame( 0, (int) $this->q->getStatus( 'in_process' ), 'Sentinel: in_process is right.' );

		$this->assertSame( 3, (int) $this->q->getStatus( 'items' ), 'PIN: fixed? items equals the 2 waiting rows — flip this pin.' );
	}
}
