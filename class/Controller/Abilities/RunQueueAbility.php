<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;

/**
 * Ability: shortpixel/run-queue
 *
 * Advances the optimization queues (media + custom) by running processing
 * ticks within a bounded time budget. Use this to push pending work forward
 * after enqueueing items — call repeatedly until the queues report empty
 *
 * @package ShortPixel\Controller\Abilities
 */
class RunQueueAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: ticks (int, default 10, max 20)
	 * @return array Run summary + queue status after the run
	 */
	public static function execute( $args )
	{
		$keyController = ApiKeyController::getInstance();
		if ( false === $keyController->keyIsVerified() ) {
			return [ 'error' => true, 'message' => 'The ShortPixel API key is not verified. Configure it in Settings > ShortPixel' ];
		}

		$ticks = isset( $args['ticks'] ) ? (int) $args['ticks'] : 10;

		$runSummary = QueueRunner::run( $ticks, 20 );

		$response = [
			'error'      => $runSummary['is_error'],
			'message'    => $runSummary['last_message'],
			'processing' => $runSummary,
		];

		// Attach the queue snapshot so the agent knows whether to call again
		$response['queue_status'] = GetQueueStatusAbility::execute( [] );

		return $response;
	}
}
