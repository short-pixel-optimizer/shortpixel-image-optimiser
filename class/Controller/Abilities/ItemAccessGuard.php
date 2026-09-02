<?php
namespace ShortPixel\Controller\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Model\AccessModel;

/**
 * Object-level access checks for item-scoped MCP abilities.
 *
 * Mirrors AjaxController::checkImageAccess() / AccessModel::imageIsEditable()
 * so REST/MCP paths honour the same per-image permission map as AJAX
 *
 * @package ShortPixel\Controller\Abilities
 */
class ItemAccessGuard
{
	/**
	 * Return an error payload when the current user may not access the image
	 *
	 * @param object|false $imageModel Image model from the file system layer
	 * @return array|null Null when access is allowed
	 */
	public static function denyIfNotEditable( $imageModel )
	{
		if ( ! is_object( $imageModel ) ) {
			return [
				'error'   => true,
				'message' => 'Image does not exist or could not be loaded',
			];
		}

		$accessModel = AccessModel::getInstance();

		if ( false === $accessModel->imageIsEditable( $imageModel ) ) {
			return [
				'error'          => true,
				'access_denied'  => true,
				'message'        => 'This user is not allowed to edit this image',
				'id'             => (int) $imageModel->get( 'id' ),
				'type'           => (string) $imageModel->get( 'type' ),
			];
		}

		return null;
	}
}
