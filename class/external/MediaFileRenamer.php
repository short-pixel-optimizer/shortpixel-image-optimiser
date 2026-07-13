<?php
namespace ShortPixel;

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Media File Renamer plugin compatibility shim.
 *
 * Media File Renamer (MFR) renames uploaded files on disk (e.g. from
 * `IMG_1234.jpg` to a slug-based name derived from the post title).
 * When it does that, SPIO's backup copy of the original — which is
 * stored under the OLD filename — becomes orphaned in the backup
 * directory.
 *
 * Hook wiring: `mfrh_path_renamed` fires after every rename. This
 * class listens for it and moves the corresponding backup file into
 * the backup directory that matches the NEW path.
 *
 * IMPORTANT: the three `@todo` markers below acknowledge the current
 * implementation is a first pass:
 *
 *   1. Doesn't fully handle single-backup vs. per-file-backup modes.
 *   2. Doesn't decide whether MFR uses `generatemetadata` for new
 *      thumbs (in which case the whole optimised state should be
 *      discarded, not migrated).
 *   3. Only migrates ONE backup file (the one matching `$oldpath`),
 *      not sibling variants.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class MediaFileRenamer
{


  /**
   * Register the MFR rename hook. No plugin-active gate — the hook
   * only fires when MFR is installed and running.
   */
  public function __construct()
  {
      add_action('mfrh_path_renamed', array($this, 'logPath'), 10, 3);
  }


  /**
   * Move SPIO's backup file to match a MediaFileRenamer rename.
   *
   * Flow:
   *   1. Load the MediaImage for the renamed post.
   *   2. Walk `getAllFiles()` (main + original + all thumbnails) to
   *      find the one whose full path matches `$oldpath` — that's
   *      the specific variant being renamed by MFR right now.
   *   3. If a backup exists for that variant, compute the target
   *      backup path under the NEW filename and move the backup file
   *      there.
   *
   * Returns `false` when the specific variant can't be located in the
   * image's file list (log-then-abort). Silent no-op when there's no
   * backup to move.
   *
   * @param array  $post    Post row from MFR (indexed array — `$post['ID']` is the attachment ID).
   * @param string $oldpath Absolute path of the file BEFORE MFR renamed it.
   * @param string $newpath Absolute path of the file AFTER MFR renamed it.
   * @return false|void `false` when the target variant can't be found; otherwise void.
   */
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
        Log::addWarn('Media File Renamer: requested thumbnail not found! ', $oldpath);
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
