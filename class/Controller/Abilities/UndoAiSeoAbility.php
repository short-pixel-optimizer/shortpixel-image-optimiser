<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Queue\QueueItem;

/**
 * Ability: shortpixel/undo-ai-seo
 *
 * Reverts AI-generated SEO metadata (alt, caption, description, post title)
 * back to the values stored before generation. Does not consume credits.
 * Filename renames performed by AI are not reversed. Custom Media is not
 * supported
 *
 * @package ShortPixel\Controller\Abilities
 */
class UndoAiSeoAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: id (int, required)
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

		$fs = \wpSPIO()->fileSystem();
		$imageModel = $fs->getMediaImage( $id );

		if ( false === $imageModel ) {
			return [ 'error' => true, 'message' => sprintf( 'Media Library image not found: ID %d', $id ) ];
		}

		$aiModel = AiDataModel::getModelByAttachment( $id, 'media' );

		if ( false === $aiModel->isSomeThingGenerated()
			&& AiDataModel::AI_STATUS_GENERATED !== (int) $aiModel->getStatus() ) {
			return [
				'error'     => true,
				'message'   => 'No AI SEO data to undo for this image',
				'ai_status' => GetAiSeoStatusAbility::buildStatus( $id ),
			];
		}

		$qItem = new QueueItem( [ 'imageModel' => $imageModel ] );
		$qItem->getAltDataAction();

		$aiController = OptimizeAiController::getInstance();
		$aiController->undoAltData( $qItem );

		AiDataModel::flushModelCache( $id );

		return [
			'error'                => false,
			'id'                   => $id,
			'type'                 => 'media',
			'message'              => 'AI SEO data reverted to original values',
			'filename_note'        => 'Filename renames performed by AI are not reversed',
			'ai_status'            => GetAiSeoStatusAbility::buildStatus( $id ),
		];
	}
}
