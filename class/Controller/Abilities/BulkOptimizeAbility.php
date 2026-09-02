<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\QuotaController;

/**
 * Ability: shortpixel/bulk-optimize
 *
 * Starts a bulk optimization of all unoptimized images, mirroring the
 * admin Bulk Optimize / WP-CLI `wp spio bulk create` flow. Consumes
 * ShortPixel credits. Processing is asynchronous — call
 * shortpixel/run-queue until queues empty
 *
 * @package ShortPixel\Controller\Abilities
 */
class BulkOptimizeAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: confirm (bool, required true), queues (media|custom),
	 *                    process (bool, default true), do_ai (bool, optional)
	 * @return array Result data or error
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		if ( empty( $args['confirm'] ) || true !== (bool) $args['confirm'] ) {
			return [
				'error'   => true,
				'message' => 'Bulk optimize consumes ShortPixel credits. Pass confirm=true to proceed',
			];
		}

		$keyController = ApiKeyController::getInstance();
		if ( false === $keyController->keyIsVerified() ) {
			return [ 'error' => true, 'message' => 'The ShortPixel API key is not verified. Configure it in Settings > ShortPixel' ];
		}

		$quotaController = QuotaController::getInstance();
		if ( false === $quotaController->hasQuota() ) {
			return [ 'error' => true, 'message' => 'ShortPixel quota is exceeded. Buy more credits or wait for the monthly renewal' ];
		}

		$queues = self::normalizeQueues( isset( $args['queues'] ) ? $args['queues'] : null );
		if ( is_null( $queues ) ) {
			return [
				'error'   => true,
				'message' => 'Queues must be "media", "custom", or an array of those values',
			];
		}

		$bulkControl = BulkController::getInstance();
		$started     = [];

		$doAi = array_key_exists( 'do_ai', $args )
			? (bool) $args['do_ai']
			: (bool) \wpSPIO()->settings()->autoAIBulk;

		foreach ( $queues as $qname ) {
			$bulkArgs = [];
			if ( 'media' === $qname ) {
				$bulkArgs = [
					'doMedia'                   => true,
					'doAi'                      => $doAi,
					'allowAiWithoutBulkSetting' => $doAi,
				];
			}

			$stats = $bulkControl->createNewBulk( $qname, $bulkArgs );
			$started[ $qname ] = self::normalizeStats( $stats );
		}

		$response = [
			'error'   => false,
			'message' => 'Bulk optimize started. Call shortpixel/run-queue (bulk mode) until queues are empty',
			'queues'  => $queues,
			'do_ai'   => $doAi,
			'started' => $started,
		];

		$process = isset( $args['process'] ) ? (bool) $args['process'] : true;

		if ( $process ) {
			$response['processing']   = QueueRunner::run( 10, 20, true );
			$response['queue_status'] = GetQueueStatusAbility::execute( [ 'bulk' => true ] );
		} else {
			$response['processing'] = [
				'ticks_run'      => 0,
				'stopped_reason' => 'processing_disabled_by_caller',
				'is_error'       => false,
				'last_message'   => 'Bulk optimize created. Call shortpixel/run-queue with bulk=true to process',
				'is_bulk'        => true,
				'stats_reset'    => false,
			];
			$response['queue_status'] = GetQueueStatusAbility::execute( [ 'bulk' => true ] );
		}

		return $response;
	}

	/**
	 * Normalize the queues argument to a list of valid queue names
	 *
	 * @param mixed $queues String, array, or null (default both)
	 * @return array|null Queue names, or null when invalid
	 */
	private static function normalizeQueues( $queues )
	{
		if ( is_null( $queues ) || '' === $queues ) {
			return [ 'media', 'custom' ];
		}

		if ( is_string( $queues ) ) {
			$queues = array_map( 'trim', explode( ',', $queues ) );
		}

		if ( ! is_array( $queues ) ) {
			return null;
		}

		$allowed    = [ 'media', 'custom' ];
		$normalized = [];

		foreach ( $queues as $q ) {
			$q = is_string( $q ) ? strtolower( trim( $q ) ) : '';
			if ( ! in_array( $q, $allowed, true ) ) {
				return null;
			}
			$normalized[] = $q;
		}

		$normalized = array_values( array_unique( $normalized ) );

		return count( $normalized ) > 0 ? $normalized : null;
	}

	/**
	 * Convert a stats object to a plain array for the ability response
	 *
	 * @param object|null $stats Queue stats from createNewBulk
	 * @return array
	 */
	private static function normalizeStats( $stats )
	{
		if ( ! is_object( $stats ) ) {
			return [];
		}

		return [
			'total'        => isset( $stats->total ) ? (int) $stats->total : 0,
			'in_queue'     => isset( $stats->in_queue ) ? (int) $stats->in_queue : 0,
			'is_preparing' => isset( $stats->is_preparing ) ? (bool) $stats->is_preparing : false,
			'is_running'   => isset( $stats->is_running ) ? (bool) $stats->is_running : false,
			'is_finished'  => isset( $stats->is_finished ) ? (bool) $stats->is_finished : false,
		];
	}
}
