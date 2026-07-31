<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Model\AiDataModel;

/**
 * Ability: shortpixel/get-ai-seo-status
 *
 * Returns the AI Image SEO state for a Media Library attachment:
 * generation status, processability, and original/generated/current
 * values for alt, caption, description, post title and filename.
 * Custom Media is not supported for AI SEO
 *
 * @package ShortPixel\Controller\Abilities
 */
class GetAiSeoStatusAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input: id (int, required)
	 * @return array Status payload or error
	 */
	public static function execute( $args = null )
	{
		$args = is_array( $args ) ? $args : [];

		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;

		if ( $id <= 0 ) {
			return [ 'error' => true, 'message' => 'A valid Media Library attachment ID is required' ];
		}

		// AI SEO is media-library only
		if ( isset( $args['type'] ) && 'media' !== $args['type'] ) {
			return [ 'error' => true, 'message' => 'AI Image SEO is only available for Media Library images (type=media)' ];
		}

		$fs = \wpSPIO()->fileSystem();
		$imageModel = $fs->getMediaImage( $id );

		if ( false === $imageModel ) {
			return [ 'error' => true, 'message' => sprintf( 'Media Library image not found: ID %d', $id ) ];
		}

		$payload = self::buildStatus( $id );
		$payload['error'] = false;
		$payload['ai_enabled'] = OptimizeAiController::getInstance()->isAiEnabled();

		return $payload;
	}

	/**
	 * Build a structured AI SEO status payload for an attachment
	 *
	 * Shared by generate/undo abilities so agents always see the same shape
	 *
	 * @param int $id Attachment ID
	 * @return array
	 */
	public static function buildStatus( $id )
	{
		$aiModel = AiDataModel::getModelByAttachment( $id, 'media' );
		$status  = (int) $aiModel->getStatus();

		return [
			'id'                  => (int) $id,
			'type'                => 'media',
			'status'              => self::statusLabel( $status ),
			'status_code'         => $status,
			'has_generated'       => $aiModel->isSomeThingGenerated(),
			'is_processable'      => $aiModel->isProcessable(),
			'processable_reason'  => $aiModel->getProcessableReason(),
			'current_is_different'=> $aiModel->currentIsDifferent(),
			'original'            => self::sanitizeFields( $aiModel->getOriginalData() ),
			'generated'           => self::sanitizeFields( $aiModel->getGeneratedData() ),
			'current'             => self::sanitizeFields( $aiModel->getCurrentData() ),
		];
	}

	/**
	 * Map AI_STATUS_* constant to a human-readable label
	 *
	 * @param int $status
	 * @return string
	 */
	private static function statusLabel( $status )
	{
		$map = [
			AiDataModel::AI_STATUS_NOTHING   => 'nothing',
			AiDataModel::AI_STATUS_GENERATED => 'generated',
		];

		return $map[ (int) $status ] ?? 'unknown';
	}

	/**
	 * Keep only the known SEO fields and cast nulls to empty strings
	 *
	 * @param array $data
	 * @return array
	 */
	private static function sanitizeFields( $data )
	{
		$fields = [ 'alt', 'caption', 'description', 'post_title', 'filebase' ];
		$out    = [];

		foreach ( $fields as $field ) {
			$value = isset( $data[ $field ] ) ? $data[ $field ] : null;
			$out[ $field ] = is_null( $value ) ? '' : (string) $value;
		}

		return $out;
	}
}
