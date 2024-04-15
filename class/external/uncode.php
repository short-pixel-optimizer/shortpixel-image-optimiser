<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class UncodeController
{
	 function __construct()
	 {
		  $this->addHooks();
	 }

	 protected function addHooks()
	 {
		  add_action('uncode_delete_crop_image', array($this, 'removedMetaData'), 10, 2);
      add_action( 'uncode_after_new_crop', array($this, 'after_new_crop'), 10, 5 );
	 }

	 public function removedMetaData($attach_id, $filePath)
	 {
		  	$fs = \wpSPIO()->filesystem();
				$imageObj = $fs->getImage($attach_id, 'media', false);
				$imageObj->saveMeta();

				$fileObj = $fs->getFile($filePath);
				if ($fileObj->hasBackup())
				{
						$backupObj = $fileObj->getBackupFile();
						$backupObj->delete();
				}

				// Check Webp
				$webpObj = $fs->getFile( (string) $fileObj->getFileDir() . $fileObj->getFileBase() . '.webp');
				if ($webpObj->exists())
					 $webpObj->delete();

			  // Check Avif
 				$avifObj = $fs->getFile( (string) $fileObj->getFileDir() . $fileObj->getFileBase() . '.avif');
 				if ($avifObj->exists())
 					 $avifObj->delete();

	 }

   public function after_new_crop( $media_id, $url, $width, $height,  $attachment_key ) {
      // $media_id       - ID of the main full image
    	// $url            - URL of the crop
    	// $width          - Width of the crop
    	// $height         - Height of the crop
    	// $attachment_key - Key of the crop in attachment_meta

      $fs = \wpSPIO()->filesystem();
      $mediaItem = $fs->getImage($media_id, 'media');
      if ($mediaItem->isProcessable())
      {
          $control = new \Shortpixel\Controller\OptimizeController();
          $control->addItemToQueue($mediaItem);
      }

    }
} // class

$u = new UncodeController();
