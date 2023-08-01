<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/** Class for handling changes done by WP in the Image Edit section. **/
class ImageEditorController
{

	protected static $instance;


	public function __construct()
	{

	}

	public static function getInstance()
	{
		if (is_null(self::$instance))
				self::$instance = new ImageEditorController();

		return self::$instance;
	}

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


	/*
	* If SPIO has a backup of this image, load the backup file for editing instead of the (optimized) image
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
