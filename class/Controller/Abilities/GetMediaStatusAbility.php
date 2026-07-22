<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Model\Image\ImageModel;

/**
 * Ability: shortpixel/get-media-status
 *
 * Returns the optimization status of a single image by ID.
 * Supports both media library and custom media types
 *
 * @package ShortPixel\Controller\Abilities
 */
class GetMediaStatusAbility
{
	/**
	 * Execute the ability callback
	 *
	 * @param array $args Input arguments: id (int, required), type (string, default 'media')
	 * @return array Image status data or error
	 */
	public static function execute( $args )
	{
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$type = isset( $args['type'] ) ? $args['type'] : 'media';

		if ( $id <= 0 ) {
			return [
				'error'   => true,
				'message' => 'A valid image ID is required',
			];
		}

		if ( ! in_array( $type, [ 'media', 'custom' ], true ) ) {
			return [
				'error'   => true,
				'message' => 'Type must be "media" or "custom"',
			];
		}

		$fs = \wpSPIO()->fileSystem();
		$imageModel = $fs->getImage( $id, $type );

		if ( false === $imageModel ) {
			return [
				'error'   => true,
				'message' => sprintf( 'Image not found: ID %d, type "%s"', $id, $type ),
			];
		}

		$status     = $imageModel->getMeta( 'status' );
		$statusLabel = self::statusLabel( $status );

		$data = [
			'id'            => $id,
			'type'          => $type,
			'status'        => $statusLabel,
			'status_code'   => (int) $status,
			'is_optimized'  => $imageModel->isOptimized(),
			'is_processable' => $imageModel->isProcessable(),
		];

		if ( $imageModel->isOptimized() ) {
			$data['improvement_percent'] = $imageModel->getImprovement( false );
			$data['bytes_saved']         = $imageModel->getImprovement( true );
			$data['original_size']       = (int) $imageModel->getMeta( 'originalSize' );
			$data['compressed_size']     = (int) $imageModel->getMeta( 'compressedSize' );
			$data['compression_type']    = (int) $imageModel->getMeta( 'compressionType' );
		}

		if ( method_exists( $imageModel, 'hasBackup' ) ) {
			$data['has_backup'] = $imageModel->hasBackup();
		}

		return $data;
	}

	/**
	 * Convert FILE_STATUS_* constant to human-readable label
	 *
	 * @param int $status Status constant from ImageModel
	 * @return string
	 */
	private static function statusLabel( $status )
	{
		$map = [
			ImageModel::FILE_STATUS_ERROR         => 'error',
			ImageModel::FILE_STATUS_UNPROCESSED   => 'unprocessed',
			ImageModel::FILE_STATUS_PENDING       => 'pending',
			ImageModel::FILE_STATUS_SUCCESS       => 'success',
			ImageModel::FILE_STATUS_RESTORED      => 'restored',
			ImageModel::FILE_STATUS_TORESTORE     => 'to_restore',
			ImageModel::FILE_STATUS_PREVENT       => 'excluded',
			ImageModel::FILE_STATUS_MARKED_DONE   => 'marked_done',
			ImageModel::FILE_STATUS_BAD_METADATA  => 'bad_metadata',
		];

		return $map[ (int) $status ] ?? 'unknown';
	}
}
