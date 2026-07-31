<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\BulkController;

/**
 * Ability: shortpixel/run-queue
 *
 * Advances the optimization queues (media + custom) by running processing
 * ticks within a bounded time budget. Use this to push pending work forward
 * after enqueueing items — call repeatedly until the queues report empty.
 * Pass bulk=true (or omit to auto-detect) when continuing a bulk restore
 *
 * @package ShortPixel\Controller\Abilities
 */
class RunQueueAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: ticks (int, default 10, max 20), bulk (bool, optional)
	 * @return array Run summary + queue status after the run
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$keyController = ApiKeyController::getInstance();
		if ( false === $keyController->keyIsVerified() ) {
			return [ 'error' => true, 'message' => 'The ShortPixel API key is not verified. Configure it in Settings > ShortPixel' ];
		}

		$ticks  = isset( $args['ticks'] ) ? (int) $args['ticks'] : 10;
		$isBulk = self::resolveBulkFlag( $args );

		$runSummary = QueueRunner::run( $ticks, 20, $isBulk );

		$response = [
			'error'      => $runSummary['is_error'],
			'message'    => $runSummary['last_message'],
			'processing' => $runSummary,
		];

		// Attach the queue snapshot so the agent knows whether to call again
		$response['queue_status'] = GetQueueStatusAbility::execute( [ 'bulk' => $isBulk ] );

		return $response;
	}

	/**
	 * Resolve whether to drive bulk queues. Explicit bulk arg wins;
	 * otherwise auto-detect an active bulk / custom operation
	 *
	 * @param array $args Ability input args
	 * @return bool
	 */
	private static function resolveBulkFlag( $args )
	{
		if ( array_key_exists( 'bulk', $args ) ) {
			return (bool) $args['bulk'];
		}

		$bulkControl = BulkController::getInstance();

		if ( true === $bulkControl->isAnyBulkRunning() ) {
			return true;
		}

		if ( false !== $bulkControl->getAnyCustomOperation() ) {
			return true;
		}

		return false;
	}
}
