<?php
namespace ShortPixel;

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class MediaFileRenamer
{


  public function __construct()
  {
      add_action('mfrh_path_renamed', array($this, 'logPath'), 10, 3);
  }


  public function logPath($post, $oldpath, $newpath)
  {

     $fs = \wpSPIO()->filesystem();

     $mediaItem = $fs->getMediaImage($post['ID']);

     if (false === $mediaItem)
     {
             return; 
     }

     if ($mediaItem->hasOriginal())
     {
        $mediaItem->getOriginalFile();        
     }

    $thumbs = $mediaItem->getAllFiles(); 
    
    foreach($thumbs['files'] as $name => $fileObj)
    {
        if ($oldpath == $fileObj->getFullPath())
        {
            $thumbObj = $fileObj; 
            break; 
        }			 
    }

    if (false === isset($thumbObj))
    {
        Log::addWarn('Media File Renamer: requested thumbnail not foud! ', $oldpath);
        return false; 
    }


     $backupModel = BackupController::getBackupModel($mediaItem); 
     //$backupModel = BackupController

     // @todo This needs to figure out somehow which file (by name) is being changed here. 
     // @todo Probably needs to check if it's single backup / more backup files and if all files + thumbnails are being moved or not 
     // @todo Also does this plugin use generatemetadata for new thumbs? Then the whole optimized should be ditched.
     if ($backupModel->hasBackup($thumbObj))
     {
         $backupFile = $backupModel->getBackupFile($thumbObj);

         $newFile = $fs->getFile($newpath);
         $newBackupFile =  $fs->getFile($fs->getBackupDirectory($newFile, true) . $newFile->getFileName());

         $backupFile->move($newBackupFile);
     }

  }



} // class

new MediaFileRenamer();
