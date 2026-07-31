<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\QueueController;

/**
 * Ability: shortpixel/bulk-undo-ai-seo
 *
 * Starts a media-only bulk that reverts AI-generated SEO metadata for all
 * attachments that have AI data stored. Mirrors admin Bulk Undo AI
 * (customOp=bulk-undoAI). Does not consume credits. Filename renames are
 * not reversed. Custom Media is not supported
 *
 * @package ShortPixel\Controller\Abilities
 */
class BulkUndoAiSeoAbility
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
				'message' => 'Bulk undo AI SEO reverts generated metadata site-wide. Pass confirm=true to proceed',
			];
		}

		QueueController::resetQueues();

		$bulkControl = BulkController::getInstance();
		$stats = $bulkControl->createNewBulk( 'media', [
			'customOp' => 'bulk-undoAI',
		] );

		$response = [
			'error'         => false,
			'message'       => 'Bulk undo AI SEO started (media only). Call shortpixel/run-queue with bulk=true until queues are empty',
			'queues'        => [ 'media' ],
			'filename_note' => 'Filename renames performed by AI are not reversed',
			'started'       => [
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
				'last_message'   => 'Bulk undo AI SEO created. Call shortpixel/run-queue with bulk=true to process',
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
