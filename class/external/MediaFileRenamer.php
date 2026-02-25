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
      //add_action('mfrh_path_renamed', array($this, 'logPath'), 10, 3);
  }


  public function logPath($post, $oldpath, $newpath)
  {

     $fs = \wpSPIO()->filesystem();

     //$oldFile = $fs->getFile($oldpath);

     $backupModel = BackupController::getModelById($post->post_id); 
     //$backupModel = BackupController

     // @todo This needs to figure out somehow which file (by name) is being changed here. 
     if ($oldFile->hasBackup())
     {
         $backupFile = $oldFile->getBackupFile();

         $newFile = $fs->getFile($newpath);
         $newBackupFile =  $fs->getFile($fs->getBackupDirectory($newFile, true) . $newFile->getFileName());

         $backupFile->move($newBackupFile);

     }
     else {
     }

  }



} // class

new MediaFileRenamer();
