<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

class UncodeController
{
	 function __construct()
	 {
		  $this->addHooks();
	 }

	 protected function addHooks()
	 {
		  add_action('uncode_delete_crop_image', array($this, 'removedMetaData'), 10, 2);
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

	 }
}

$u = new UncodeController();
