<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\QueueController;

/**
 * Ability: shortpixel/bulk-restore
 *
 * Starts a bulk restore of all optimized images from backup (de-optimize),
 * mirroring the admin Bulk Restore All flow (AjaxController::startRestoreAll).
 * Destructive and non-reversible. Does not consume optimization credits.
 * Processing is asynchronous — call shortpixel/run-queue until queues empty.
 *
 * Permission: gated on userCanManage (manage_options) since c83f344d —
 * this used to accept editors (userCanOptimize), which was too weak for a
 * site-wide destructive action. Editors can still bulk-optimize; only
 * bulk-restore and bulk-undo-ai-seo were tightened
 *
 * @package ShortPixel\Controller\Abilities
 */
class BulkRestoreAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: confirm (bool, required true), queues (media|custom),
	 *                    process (bool, default true)
	 * @return array Result data or error
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		if ( empty( $args['confirm'] ) || true !== (bool) $args['confirm'] ) {
			return [
				'error'   => true,
				'message' => 'Bulk restore is destructive and non-reversible. Pass confirm=true to proceed',
			];
		}

		$queues = self::normalizeQueues( isset( $args['queues'] ) ? $args['queues'] : null );
		if ( is_null( $queues ) ) {
			return [
				'error'   => true,
				'message' => 'Queues must be "media", "custom", or an array of those values',
			];
		}

		// Same safety reset as the admin Bulk Restore All handler
		QueueController::resetQueues();

		$bulkControl = BulkController::getInstance();
		$started     = [];

		foreach ( $queues as $qname ) {
			$stats = $bulkControl->createNewBulk( $qname, [ 'customOp' => 'bulk-restore' ] );
			$started[ $qname ] = self::normalizeStats( $stats );
		}

		$response = [
			'error'   => false,
			'message' => 'Bulk restore started. Call shortpixel/run-queue (bulk mode) until queues are empty',
			'queues'  => $queues,
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
				'last_message'   => 'Bulk restore created. Call shortpixel/run-queue with bulk=true to process',
				'is_bulk'        => true,
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

		$allowed = [ 'media', 'custom' ];
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
