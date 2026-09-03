<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\StatsController;

/**
 * Shared queue-driving helper for action abilities.
 *
 * Runs bounded, synchronous processing ticks on the optimization queues —
 * the same mechanism WP-CLI uses (`wp spio run --ticks`), adapted for
 * REST/MCP context where the request has a hard time budget.
 *
 * Optimization is asynchronous by design (ShortQ queue + remote API), so a
 * single request can rarely finish a job completely. This runner advances
 * the queue as far as the time budget allows and reports progress; callers
 * (AI agents) are expected to call shortpixel/run-queue again when items
 * remain
 *
 * When `$isBulk` is true, ticks run against the bulk queues. Bulk mode also
 * auto-calls BulkController::startBulk() when preparation finishes, because
 * bulk queues do not start processing automatically
 *
 * @package ShortPixel\Controller\Abilities
 */
class QueueRunner
{
	/** @var int Hard ceiling for the time budget in seconds, regardless of input */
	const MAX_TIME_BUDGET = 25;

	/** @var int Hard ceiling for the number of ticks per request */
	const MAX_TICKS = 20;

	/** @var int Seconds to wait between ticks, giving the remote API time to process */
	const TICK_WAIT = 2;

	/**
	 * Run processing ticks until the queues are empty, an error occurs,
	 * or the tick/time budget runs out
	 *
	 * @param int  $maxTicks   Maximum number of ticks to run (capped at MAX_TICKS)
	 * @param int  $timeBudget Maximum seconds to spend (capped by MAX_TIME_BUDGET and PHP max_execution_time)
	 * @param bool $isBulk     When true, drive the bulk queues and auto-start after prepare
	 * @return array Summary: ticks_run, stopped_reason, is_error, last_message, is_bulk, stats_reset
	 */
	public static function run( $maxTicks = 10, $timeBudget = 20, $isBulk = false )
	{
		$maxTicks   = min( max( 1, (int) $maxTicks ), self::MAX_TICKS );
		$timeBudget = self::clampTimeBudget( $timeBudget );
		$isBulk     = (bool) $isBulk;

		// REST/MCP (and cron) do not load wp-admin; converters need these helpers
		self::ensureAdminIncludes();

		$queueController = new QueueController( [ 'is_bulk' => $isBulk ] );

		$startTime     = time();
		$ticksRun      = 0;
		$stoppedReason = 'tick_limit_reached';
		$isError       = false;
		$lastMessage   = '';
		$statsReset    = false;

		while ( $ticksRun < $maxTicks ) {

			// Stop when the next tick (plus wait) would exceed the time budget
			if ( ( time() - $startTime ) + self::TICK_WAIT >= $timeBudget ) {
				$stoppedReason = 'time_budget_reached';
				break;
			}

			$results = $queueController->processQueue( [ 'media', 'custom' ] );
			$ticksRun++;

			// Hard failure (invalid API key, quota exceeded on normal operations)
			if ( is_object( $results ) && property_exists( $results, 'status' ) && false === $results->status ) {
				$stoppedReason = 'error';
				$isError       = true;
				$lastMessage   = property_exists( $results, 'message' ) ? $results->message : 'Queue processing failed';
				break;
			}

			$combinedStatus = self::getCombinedStatus( $results );

			if ( Queue::RESULT_QUEUE_EMPTY === $combinedStatus ) {
				$stoppedReason = 'queues_empty';
				if ( true === $isBulk ) {
					$statsReset = self::finalizeBulkRun();
					if ( true === $statsReset ) {
						$lastMessage = 'Bulk finished; statistics cache reset';
					}
				}
				break;
			}

			// Bulk queues stay idle after prepare until startBulk is called
			if ( Queue::RESULT_PREPARING_DONE === $combinedStatus ) {
				if ( true === $isBulk ) {
					BulkController::getInstance()->startBulk( [ 'media', 'custom' ] );
					$lastMessage = 'Bulk preparation done, processing started';
					continue;
				}

				$stoppedReason = 'preparing_done';
				break;
			}

			// Give the remote API breathing room before checking again
			if ( $ticksRun < $maxTicks ) {
				sleep( self::TICK_WAIT );
			}
		}

		return [
			'ticks_run'      => $ticksRun,
			'stopped_reason' => $stoppedReason,
			'is_error'       => $isError,
			'last_message'   => $lastMessage,
			'is_bulk'        => $isBulk,
			'stats_reset'    => $statsReset,
		];
	}

	/**
	 * Finish bulk queues and invalidate cached dashboard stats
	 *
	 * Always resets StatsController after a completed bulk so the
	 * WEEK_IN_SECONDS currentStats cache does not show stale counts
	 *
	 * @return bool True when statistics cache was reset
	 */
	private static function finalizeBulkRun()
	{
		$bulkControl = BulkController::getInstance();

		$bulkControl->finishBulk( 'media' );
		$bulkControl->finishBulk( 'custom' );

		StatsController::getInstance()->reset();

		return true;
	}

	/**
	 * Combined queue status — the lowest (earliest-in-process) status of
	 * both queues, mirroring the JS processor and WP-CLI behavior:
	 * do not halt until the slower queue is also done
	 *
	 * @param object $results Result object from QueueController::processQueue()
	 * @return int Queue::RESULT_* constant
	 */
	private static function getCombinedStatus( $results )
	{
		$mediaStatus = $customStatus = 100;

		if ( property_exists( $results, 'media' ) && property_exists( $results->media, 'qstatus' ) ) {
			$mediaStatus = $results->media->qstatus;
		}
		if ( property_exists( $results, 'custom' ) && property_exists( $results->custom, 'qstatus' ) ) {
			$customStatus = $results->custom->qstatus;
		}

		return min( $mediaStatus, $customStatus );
	}

	/**
	 * Clamp the time budget to safe values: never more than MAX_TIME_BUDGET,
	 * never more than the PHP max execution time minus a safety margin
	 *
	 * @param int $timeBudget Requested budget in seconds
	 * @return int Clamped budget
	 */
	private static function clampTimeBudget( $timeBudget )
	{
		$budget = min( max( 5, (int) $timeBudget ), self::MAX_TIME_BUDGET );

		if ( defined( 'SHORTPIXEL_MAX_EXECUTION_TIME' ) && SHORTPIXEL_MAX_EXECUTION_TIME > 10 ) {
			$budget = min( $budget, SHORTPIXEL_MAX_EXECUTION_TIME - 5 );
		}

		return $budget;
	}

	/**
	 * Load wp-admin helpers missing outside the admin bootstrap (REST, MCP, cron)
	 *
	 * Same pattern as AdminController::loadCronCompat()
	 *
	 * @return void
	 */
	private static function ensureAdminIncludes()
	{
		if ( false === function_exists( 'download_url' ) ) {
			include_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		if ( false === function_exists( 'wp_generate_attachment_metadata' ) ) {
			include_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}
}
