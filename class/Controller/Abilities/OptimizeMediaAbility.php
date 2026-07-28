<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\QuotaController;
use ShortPixel\Model\Image\ImageModel;

/**
 * Ability: shortpixel/optimize-media
 *
 * Enqueues a single image for optimization, then advances the queue
 * synchronously within a bounded time budget. Because optimization is
 * asynchronous (remote API), the job may not finish within one request —
 * the response reports progress and the agent can call
 * shortpixel/run-queue to continue processing
 *
 * @package ShortPixel\Controller\Abilities
 */
class OptimizeMediaAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: id (int, required), type (media|custom),
	 *                    compression (lossy|glossy|lossless), smartcrop (bool),
	 *                    process (bool, default true)
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

		// Pre-flight: verified API key and available quota
		$keyController = ApiKeyController::getInstance();
		if ( false === $keyController->keyIsVerified() ) {
			return [ 'error' => true, 'message' => 'The ShortPixel API key is not verified. Configure it in Settings > ShortPixel' ];
		}

		$quotaController = QuotaController::getInstance();
		if ( false === $quotaController->hasQuota() ) {
			return [ 'error' => true, 'message' => 'ShortPixel quota is exceeded. Buy more credits or wait for the monthly renewal' ];
		}

		$fs = \wpSPIO()->fileSystem();
		$imageModel = $fs->getImage( $id, $type );

		if ( false === $imageModel ) {
			return [ 'error' => true, 'message' => sprintf( 'Image not found: ID %d, type "%s"', $id, $type ) ];
		}

		// Build queue arguments
		$queueArgs = [ 'action' => 'optimize' ];

		if ( isset( $args['compression'] ) ) {
			$compressionType = self::mapCompression( $args['compression'] );
			if ( is_null( $compressionType ) ) {
				return [ 'error' => true, 'message' => 'Compression must be "lossy", "glossy" or "lossless"' ];
			}
			$queueArgs['compressionType'] = $compressionType;
		}

		if ( isset( $args['smartcrop'] ) ) {
			$queueArgs['smartcrop'] = ( true === (bool) $args['smartcrop'] )
				? ImageModel::ACTION_SMARTCROP
				: ImageModel::ACTION_SMARTCROPLESS;
		}

		$queueController = new QueueController();
		$result = $queueController->addItemToQueue( $imageModel, $queueArgs );

		$message = ( is_object( $result ) && property_exists( $result, 'message' ) && ! is_null( $result->message ) )
			? $result->message : '';
		$enqueueError = ( is_object( $result ) && property_exists( $result, 'is_error' ) && true === $result->is_error );

		if ( $enqueueError ) {
			return [
				'error'   => true,
				'message' => strlen( $message ) > 0 ? $message : 'Item could not be added to the queue',
			];
		}

		$response = [
			'error'           => false,
			'id'              => $id,
			'type'            => $type,
			'enqueue_message' => $message,
		];

		// Advance the queue within the request time budget, unless disabled
		$process = isset( $args['process'] ) ? (bool) $args['process'] : true;

		if ( $process ) {
			$runSummary = QueueRunner::run( 10, 20 );
			$response['processing'] = $runSummary;

			// Report the resulting item status so the agent knows if the job completed
			$imageModel = $fs->getImage( $id, $type, false ); // no cache, fresh state
			if ( false !== $imageModel ) {
				$response['is_optimized'] = $imageModel->isOptimized();
				if ( $imageModel->isOptimized() ) {
					$response['improvement_percent'] = $imageModel->getImprovement( false );
					$response['bytes_saved']         = $imageModel->getImprovement( true );
				}
			}
		} else {
			$response['processing'] = [
				'ticks_run'      => 0,
				'stopped_reason' => 'processing_disabled_by_caller',
				'is_error'       => false,
				'last_message'   => 'Item enqueued. Call shortpixel/run-queue to process',
			];
		}

		return $response;
	}

	/**
	 * Map a compression string to the internal ImageModel constant
	 *
	 * @param string $compression lossy | glossy | lossless
	 * @return int|null Compression constant or null when invalid
	 */
	private static function mapCompression( $compression )
	{
		$map = [
			'lossless' => ImageModel::COMPRESSION_LOSSLESS,
			'lossy'    => ImageModel::COMPRESSION_LOSSY,
			'glossy'   => ImageModel::COMPRESSION_GLOSSY,
		];

		return $map[ strtolower( (string) $compression ) ] ?? null;
	}
}
