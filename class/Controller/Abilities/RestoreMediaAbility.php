<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Queue\QueueItems;

/**
 * Ability: shortpixel/restore-media
 *
 * Restores a single optimized image to its original state from the
 * ShortPixel backup. Mirrors the WP-CLI `wp spio restore` command.
 * Restore operations do not consume optimization credits
 *
 * @package ShortPixel\Controller\Abilities
 */
class RestoreMediaAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: id (int, required), type (media|custom)
	 * @return array Result data or error
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$type = isset( $args['type'] ) ? $args['type'] : 'media';

		if ( $id <= 0 ) {
			return [ 'error' => true, 'message' => 'A valid image ID is required' ];
		}

		if ( ! in_array( $type, [ 'media', 'custom' ], true ) ) {
			return [ 'error' => true, 'message' => 'Type must be "media" or "custom"' ];
		}

		$fs = \wpSPIO()->fileSystem();
		$imageModel = $fs->getImage( $id, $type );

		if ( false === $imageModel ) {
			return [ 'error' => true, 'message' => sprintf( 'Image not found: ID %d, type "%s"', $id, $type ) ];
		}

		$accessDenied = ItemAccessGuard::denyIfNotEditable( $imageModel );
		if ( $accessDenied !== null ) {
			return $accessDenied;
		}

		if ( false === $imageModel->isOptimized() ) {
			return [ 'error' => true, 'message' => 'This image is not optimized, nothing to restore' ];
		}

		if ( method_exists( $imageModel, 'hasBackup' ) && false === $imageModel->hasBackup() ) {
			return [ 'error' => true, 'message' => 'No backup available for this image. Restore is not possible' ];
		}

		// Mark the queue item as a restore action before enqueueing (same flow as WP-CLI restore)
		$qItem = QueueItems::getImageItem( $imageModel );
		$qItem->newRestoreAction();

		$queueController = new QueueController();
		$result = $queueController->addItemToQueue( $imageModel, [ 'action' => 'restore' ] );

		$message = ( is_object( $result ) && property_exists( $result, 'message' ) && ! is_null( $result->message ) )
			? $result->message : '';
		$enqueueError = ( is_object( $result ) && property_exists( $result, 'is_error' ) && true === $result->is_error );

		if ( $enqueueError ) {
			return [
				'error'   => true,
				'message' => strlen( $message ) > 0 ? $message : 'Restore could not be added to the queue',
			];
		}

		// Restore runs through the queue as well - advance it now
		$runSummary = QueueRunner::run( 10, 20 );

		$response = [
			'error'           => false,
			'id'              => $id,
			'type'            => $type,
			'enqueue_message' => $message,
			'processing'      => $runSummary,
		];

		// Report fresh item state so the agent can verify the restore happened
		$imageModel = $fs->getImage( $id, $type, false ); // no cache, fresh state
		if ( false !== $imageModel ) {
			$response['is_optimized'] = $imageModel->isOptimized();
			$response['is_restored']  = ! $imageModel->isOptimized();
		}

		return $response;
	}
}
