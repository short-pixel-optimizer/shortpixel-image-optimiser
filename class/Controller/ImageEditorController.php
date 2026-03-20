<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Handles interactions between ShortPixel and the WordPress image editor.
 *
 * Intercepts image editing operations to serve backup files to the editor and to
 * clean up optimisation metadata and backups when an image is saved after editing.
 *
 * @package ShortPixel\Controller
 */
class ImageEditorController
{

	/** @var ImageEditorController|null Singleton instance */
	protected static $instance;


	public function __construct()
	{

	}

	/**
	 * Return the singleton instance, creating it on first call.
	 *
	 * @return ImageEditorController The singleton instance.
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance))
				self::$instance = new ImageEditorController();

		return self::$instance;
	}

	/**
	 * Build the localisation data array for the image editor JavaScript.
	 *
	 * Provides translated strings and action URLs that are passed to the front-end
	 * script loaded on the WordPress image editor screen.
	 *
	 * @return array Associative array of localisation key/value pairs.
	 */
	public static function localizeScript()
	{
		  $local = array(
			);

			$fs = \wpSPIO()->filesystem();

				//		$local['is_restorable'] = ($mediaImage->isRestorable() ) ? 'true' : 'false';
      //      $local['is_optimized'] = ($mediaImage->isOptimized()) ? 'true' : 'false';
			//			$local['post_id'] = $post_id;

						$local['optimized_text'] = sprintf(__('This image has been optimized by ShortPixel. It is strongly %s recommended %s to restore the image from the backup (if any) before editing it, because after saving the image all optimization data will be lost. If the image is not restored and ShortPixel re-optimizes the new image, this may result in a loss of quality. After you have finished editing, please optimize the image again by clicking "Optimize Now" as this will not happen automatically.', 'shortpixel-image-optimiser'), '<strong>', '</strong>');

            $local['restore_link']  = 'javascript:window.ShortPixelProcessor.screen.RestoreItem(#post_id#)';
	 			    $local['restore_link_text'] = __('Restore the backup now.', 'shortpixel-image-optimiser');
            $local['restore_link_text_unrestorable'] = __(' (This item is not restorable) ', 'shortpixel-image-optimiser');


			return $local;
	}


	/**
	 * Return the backup file path to the WP image editor instead of the optimised file.
	 *
	 * Hooked into the filter that provides the image file path to the WordPress editor.
	 * When a backup exists for the requested size, the backup path is returned so that
	 * editing starts from the original rather than the compressed version.
	 *
	 * @param string $filepath      The original file path supplied by WordPress.
	 * @param int    $attachment_id The attachment post ID.
	 * @param string $size          The requested image size (e.g. 'full', 'thumbnail').
	 * @return string The backup file path when available, or the original $filepath unchanged.
	 */
	public function getImageForEditor( $filepath, $attachment_id, $size)
	{

		$fs = \wpSPIO()->filesystem();
		$mediaImage = $fs->getImage($attachment_id, 'media');

		// Not an image, let's not get into this.
		if (false === $mediaImage)
			return $filepath;

		$imagepath = false;
		if ($size == 'full')
		{
				$optimized_and_backup = ($mediaImage->isOptimized() && $mediaImage->hasBackup());
				if ( true === $optimized_and_backup)
					$imagepath = $mediaImage->getBackupFile()->getFullPath();
		}
		elseif (false !== $mediaImage->getThumbNail($size)) {
			 	$thumbObj = $mediaImage->getThumbNail($size);
				$optimized_and_backup = ($thumbObj->isOptimized() && $thumbObj->hasBackup());

				if (true === $optimized_and_backup)
					$imagepath = $thumbObj->getBackupFile()->getFullPath();
		}

		if (true === $optimized_and_backup)
		{
			 return $imagepath;
		}

		 return $filepath;
	}

	/**
	 * Delete backups and reset optimisation state when an image is saved via the editor.
	 *
	 * Hooked into the WordPress image save filter. Triggers onDelete() on the media
	 * model so that stale backup files and metadata are cleaned up before the newly
	 * edited version is written to disk.
	 *
	 * @param mixed  $null      The null value passed through the filter (returned unchanged).
	 * @param string $filename  The filename being saved.
	 * @param mixed  $image     The image resource or object being saved.
	 * @param string $mime_type The MIME type of the image.
	 * @param int    $post_id   The attachment post ID.
	 * @return mixed The $null value, passed through unchanged.
	 */
	public function saveImageFile( $null, $filename, $image, $mime_type, $post_id		)
	{
			// Check image and if needed, delete backups.
			$fs = \wpSPIO()->filesystem();
			$mediaImage = $fs->getImage($post_id, 'media');

			if (is_object($mediaImage))
			{
				$mediaImage->onDelete();
			}

			return $null;
	}


} //class
