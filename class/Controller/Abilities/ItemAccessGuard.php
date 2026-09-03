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
 * so REST/MCP paths honour the same per-image permission map as AJAX. The
 * ability's role-level permission_callback (userCanOptimize /
 * userCanManage) gates who may see the tool at all; this guard is the
 * per-attachment second check that stops an editor from optimizing or
 * restoring an image they do not own on multi-author sites where
 * image_user (edit_post) is the effective cap.
 *
 * Called by GenerateAiSeoAbility, GetAiSeoStatusAbility, GetMediaStatusAbility,
 * OptimizeMediaAbility, RestoreMediaAbility, UndoAiSeoAbility — always AFTER
 * the ImageModel has been loaded from FileSystemController and BEFORE any
 * queue mutation or metadata read
 *
 * @package ShortPixel\Controller\Abilities
 */
class ItemAccessGuard
{
	/**
	 * Return an error payload when the current user may not access the image,
	 * or null when the caller should proceed with the ability's real work.
	 *
	 * Two failure modes with distinct payload shapes:
	 *  - non-object $imageModel (fs load returned false): "does not exist"
	 *    error WITHOUT access_denied — nothing to be denied access to.
	 *  - AccessModel::imageIsEditable() === false: access_denied=true plus
	 *    the offending id/type so agents can log the attempted target.
	 *
	 * @param object|false $imageModel ImageModel from FileSystemController::getImage()
	 *                                 (media) or getMediaImage() / a custom image
	 *                                 model. Anything non-object triggers the
	 *                                 "does not exist" branch.
	 * @return array|null Null when access is allowed; error array otherwise
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
