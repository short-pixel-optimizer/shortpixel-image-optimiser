<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\QuotaController;
use ShortPixel\Model\AiDataModel;

/**
 * Ability: shortpixel/generate-ai-seo
 *
 * Enqueues AI Image SEO generation (alt, caption, description, title,
 * optional filename) for a Media Library attachment, then advances the
 * queue within a time budget. Generation is asynchronous (requestAlt +
 * retrieveAlt): if it does not finish in one call, use run-queue.
 * Consumes AI credits. Custom Media is not supported
 *
 * @package ShortPixel\Controller\Abilities
 */
class GenerateAiSeoAbility
{
	/**
	 * Map friendly ability args to internal ai_gen_* setting keys
	 *
	 * @var array<string, string>
	 */
	const FIELD_OVERRIDES = [
		'gen_alt'         => 'ai_gen_alt',
		'gen_caption'     => 'ai_gen_caption',
		'gen_description' => 'ai_gen_description',
		'gen_post_title'  => 'ai_gen_post_title',
		'gen_filename'    => 'ai_gen_filename',
	];

	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: id (int, required), process (bool),
	 *                    preview_only (bool), gen_* field overrides (bool)
	 * @return array Result data or error
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;

		if ( $id <= 0 ) {
			return [ 'error' => true, 'message' => 'A valid Media Library attachment ID is required' ];
		}

		if ( isset( $args['type'] ) && 'media' !== $args['type'] ) {
			return [ 'error' => true, 'message' => 'AI Image SEO is only available for Media Library images (type=media)' ];
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
		$aiUnlimited = (bool) $quota->AIUnlimited;
		$aiRemaining = (int) $quota->ai->remaining;

		if ( false === $aiUnlimited && $aiRemaining <= 0 ) {
			return [ 'error' => true, 'message' => 'AI credits are exhausted. Buy more AI credits or wait for the monthly renewal' ];
		}

		$fs = \wpSPIO()->fileSystem();
		$imageModel = $fs->getMediaImage( $id );

		if ( false === $imageModel ) {
			return [ 'error' => true, 'message' => sprintf( 'Media Library image not found: ID %d', $id ) ];
		}

		$aiModel = AiDataModel::getModelByAttachment( $id, 'media' );
		if ( false === $aiModel->isProcessable() ) {
			return [
				'error'              => true,
				'message'            => 'Image is not processable for AI SEO: ' . $aiModel->getProcessableReason(),
				'processable_reason' => $aiModel->getProcessableReason(),
				'ai_status'          => GetAiSeoStatusAbility::buildStatus( $id ),
			];
		}

		$queueArgs = [ 'action' => 'requestAlt' ];

		if ( isset( $args['preview_only'] ) && true === (bool) $args['preview_only'] ) {
			$queueArgs['preview_only'] = true;
		}

		foreach ( self::FIELD_OVERRIDES as $inputKey => $settingKey ) {
			if ( isset( $args[ $inputKey ] ) ) {
				$queueArgs[ $settingKey ] = (bool) $args[ $inputKey ];
			}
		}

		$queueController = new QueueController();
		$result = $queueController->addItemToQueue( $imageModel, $queueArgs );

		$message = ( is_object( $result ) && property_exists( $result, 'message' ) && ! is_null( $result->message ) )
			? $result->message : '';
		$enqueueError = ( is_object( $result ) && property_exists( $result, 'is_error' ) && true === $result->is_error );

		if ( $enqueueError ) {
			return [
				'error'   => true,
				'message' => strlen( $message ) > 0 ? $message : 'Item could not be added to the AI SEO queue',
			];
		}

		$response = [
			'error'           => false,
			'id'              => $id,
			'type'            => 'media',
			'enqueue_message' => $message,
			'preview_only'    => isset( $queueArgs['preview_only'] ) ? true : false,
		];

		$process = isset( $args['process'] ) ? (bool) $args['process'] : true;

		if ( $process ) {
			$runSummary = QueueRunner::run( 10, 20 );
			$response['processing'] = $runSummary;

			AiDataModel::flushModelCache( $id );
			$response['ai_status'] = GetAiSeoStatusAbility::buildStatus( $id );
		} else {
			$response['processing'] = [
				'ticks_run'      => 0,
				'stopped_reason' => 'processing_disabled_by_caller',
				'is_error'       => false,
				'last_message'   => 'Item enqueued. Call shortpixel/run-queue to process',
			];
			$response['ai_status'] = GetAiSeoStatusAbility::buildStatus( $id );
		}

		return $response;
	}
}
