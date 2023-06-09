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
					'test' => 'test',
			);

			$fs = \wpSPIO()->filesystem();

			if (isset($_REQUEST['post']))
			{
				$post_id  = intval($_REQUEST['post']);
				$mediaImage = $fs->getImage($post_id, 'media');
				if ($mediaImage)
				{
						$local['is_restorable'] = ($mediaImage->isRestorable() || $mediaImage->isOptimized() ) ? 'true' : 'false';
						$local['post_id'] = $post_id;

						$local['optimized_text'] = sprintf(__('This image is optimized. It\'s strongly %s recommended %s to restore the image before editing it.  After saving the image all optimization data will be lost. When the image is not restored Shortpixel will re-optimize the result which could result in quality loss', 'shortpixel-image-optimiser'), '<strong>', '</strong>');
						$local['restore_link']  = 'javascript:window.ShortPixelProcessor.screen.RestoreItem(' . $post_id  . ')';
						$local['restore_link_text'] = __('Restore backup now', 'shortpixel-image-optimiser');

				}

			}


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
