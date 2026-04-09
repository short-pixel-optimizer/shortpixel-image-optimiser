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
				
				$backupModel = $imageObj->getBackupModel();

				$thumbs = $imageObj->getAllFiles(); 

				foreach($thumbs['files'] as $name => $fileObj)
				{
					if ($filePath == $fileObj->getFullPath())
					{
						$thumbObj = $fileObj;
						break;
					}			 
				}

				if (false === is_object($thumbObj))
				{
					Log::addWarn('Uncode remove - thumbnail not found for ' . $filePath);
					return false;
				}

				// Check Webp
				$webpObj = $thumbObj->getImageType('webp'); 
				if (false !== $webpObj)
				{
					$webpObj->delete();
				}
				
				$avifObj = $thumbObj->getImageType('avif'); 
				if (false !== $avifObj)
				{
					$avifObj->delete();
				}

				if ($backupModel->hasBackup($thumbObj))
				{
						$backupObj = $backupModel->OnDelete($thumbObj);
				}
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
          $control = new \Shortpixel\Controller\QueueController();
          $control->addItemToQueue($mediaItem);
      }

    }
} // class

$u = new UncodeController();
