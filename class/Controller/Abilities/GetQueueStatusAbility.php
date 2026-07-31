<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\QueueController;

/**
 * Ability: shortpixel/get-queue-status
 *
 * Returns the current state of the optimization queues (media and custom):
 * items in queue, in process, done, total, and whether the queue is
 * preparing/running/finished. Same data as `wp spio status`
 *
 * @package ShortPixel\Controller\Abilities
 */
class GetQueueStatusAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: bulk (bool) — when true, report the bulk queues
	 * @return array Queue status data
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$isBulk = ! empty( $args['bulk'] );

		$queueController = new QueueController( [ 'is_bulk' => $isBulk ] );
		$startupData     = $queueController->getStartupData();

		$result = [
			'is_bulk' => $isBulk,
			'queues'  => [],
		];

		foreach ( [ 'media', 'custom' ] as $queueName ) {
			if ( ! isset( $startupData->$queueName->stats ) ) {
				continue;
			}

			$stats = $startupData->$queueName->stats;

			$result['queues'][ $queueName ] = [
				'in_queue'     => (int) $stats->in_queue,
				'in_process'   => (int) $stats->in_process,
				'fatal_errors' => (int) $stats->fatal_errors,
				'done'         => (int) $stats->done,
				'total'        => (int) $stats->total,
				'is_preparing' => (bool) $stats->is_preparing,
				'is_running'   => (bool) $stats->is_running,
				'is_finished'  => (bool) $stats->is_finished,
			];
		}

		if ( isset( $startupData->total->stats ) ) {
			$totalStats = $startupData->total->stats;
			$result['total'] = [
				'in_queue'     => (int) $totalStats->in_queue,
				'in_process'   => (int) $totalStats->in_process,
				'fatal_errors' => (int) $totalStats->fatal_errors,
				'done'         => (int) $totalStats->done,
				'total'        => (int) $totalStats->total,
				'is_running'   => (bool) $totalStats->is_running,
				'is_finished'  => (bool) $totalStats->is_finished,
			];
		}

		return $result;
	}
}
