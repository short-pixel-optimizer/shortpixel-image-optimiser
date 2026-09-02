<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\QuotaController;

/**
 * Ability: shortpixel/bulk-generate-ai-seo
 *
 * Starts a media-only bulk that generates AI Image SEO for processable
 * attachments (doMedia=false, doAi=true). Mirrors the admin bulk when only
 * AI is selected. Consumes AI credits. Asynchronous: call run-queue until
 * queues empty. Custom Media is not supported
 *
 * @package ShortPixel\Controller\Abilities
 */
class BulkGenerateAiSeoAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: confirm (bool, required true), process (bool)
	 * @return array Result data or error
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		if ( empty( $args['confirm'] ) || true !== (bool) $args['confirm'] ) {
			return [
				'error'   => true,
				'message' => 'Bulk AI SEO generation consumes AI credits. Pass confirm=true to proceed',
			];
		}

		$keyController = ApiKeyController::getInstance();
		if ( false === $keyController->keyIsVerified() ) {
			return [ 'error' => true, 'message' => 'The ShortPixel API key is not verified. Configure it in Settings > ShortPixel' ];
		}

		$aiController = OptimizeAiController::getInstance();
		if ( false === $aiController->isAiEnabled() ) {
			return [ 'error' => true, 'message' => 'AI Image SEO is disabled. Enable it in Settings > ShortPixel > AI' ];
		}

		$quotaController = QuotaController::getInstance();
		$quota = $quotaController->getQuota();
		if ( false === (bool) $quota->AIUnlimited && (int) $quota->ai->remaining <= 0 ) {
			return [ 'error' => true, 'message' => 'AI credits are exhausted. Buy more AI credits or wait for the monthly renewal' ];
		}

		$settings = \wpSPIO()->settings();
		$previousAutoAiBulk = (bool) $settings->autoAIBulk;

		$bulkControl = BulkController::getInstance();
		$stats = $bulkControl->createNewBulk( 'media', [
			'doMedia'                   => false,
			'doAi'                      => true,
			'allowAiWithoutBulkSetting' => true,
		] );

		$response = [
			'error'                 => false,
			'message'               => 'Bulk AI SEO generation started (media only). Call shortpixel/run-queue with bulk=true until queues are empty',
			'queues'                => [ 'media' ],
			'do_media'              => false,
			'do_ai'                 => true,
			'auto_ai_bulk_previous' => $previousAutoAiBulk,
			'auto_ai_bulk_set'      => false,
			'started'               => [
				'media' => self::normalizeStats( $stats ),
			],
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
				'last_message'   => 'Bulk AI SEO created. Call shortpixel/run-queue with bulk=true to process',
				'is_bulk'        => true,
			];
			$response['queue_status'] = GetQueueStatusAbility::execute( [ 'bulk' => true ] );
		}

		return $response;
	}

	/**
	 * Convert a stats object to a plain array for the ability response
	 *
	 * @param object|null $stats
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
