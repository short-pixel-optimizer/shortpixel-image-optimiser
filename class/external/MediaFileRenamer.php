<?php
namespace ShortPixel;
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
     Log::addTemp('Path Rename! ' . $oldpath . ' ' . $newpath);

     $fs = \wpSPIO()->filesystem();

     $oldFile = $fs->getFile($oldpath);

     if ($oldFile->hasBackup())
     {
         $backupFile = $oldFile->getBackupFile();

         $newFile = $fs->getFile($newpath);
         $newBackupFile =  $fs->getFile($fs->getBackupDirectory($newFile, true) . $newFile->getBackupFileName());

         $backupFile->move($newBackupFile);

         Log::addTemp('NewBackup', $newBackupFile);
     }
     else {
       Log::addTemp('No backup for ' . $oldFile->getFullPath());
     }

  }



} // class

new MediaFileRenamer();
