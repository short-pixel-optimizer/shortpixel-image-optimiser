<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ResponseController;
use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Local-disk implementation of {@see BackupModel}.
 *
 * Stores each media item's backup family under the plugin's configured
 * backup directory (typically `uploads/ShortpixelBackups/…` mirroring the
 * source year/month path). Each thumbnail can either have its own backup
 * file or be covered by the main file's backup (see the `has_own_file`
 * flag on the `backup_files` cache).
 *
 * Supports the "single file backup" setting where thumbnails are not
 * backed up separately — createBackupFile() detects the thumbnail case
 * and defers to a main-file backup instead.
 *
 * @package ShortPixel\Model\Backup
 */
class LocalBackupModel extends BackupModel
{

    /**
     * Copy $sourceFile into the backup directory, or verify an existing
     * backup is usable.
     *
     * Decision tree:
     *   1. Backup directory doesn't exist / can't be created → false.
     *   2. Backup already exists with the same filesize → STATUS_BACKUP_OK, return true.
     *   3. `singleFileBackup` is on AND this is a thumbnail (not the main
     *      file) → recursively back up the main file instead, mark
     *      STATUS_IGNORED.
     *   4. Otherwise, if the source is a virtual (remote / stateless)
     *      file, first materialise it locally via checkVirtualForBackup();
     *      then copy the source to the backup path.
     *
     * On any failure the STATUS_ERR_COPY_FAILED code is stored on
     * `statusCode` and false is returned.
     *
     * @param ImageModel $sourceFile Image to back up.
     * @return bool
     */
     public function createBackupFile(ImageModel $sourceFile) : bool
     {

        $directory = $this->getBackupDirectory(true);
        $fs = \wpSPIO()->filesystem();
        $imageName = $this->getBackupName($sourceFile->get('name'), $sourceFile);
        $settings = \wpSPIO()->settings();

        $mainFile = $this->getMainFile(); 

        if (! $directory)
        {
          Log::addWarn('Could not create Backup Directory for ' . $sourceFile->getFullPath());
         // $this->error_message = __('Could not create backup Directory', 'shortpixel-image-optimiser');
          return false;
        }
      
        $backupFile = $fs->getFile($directory . $this->getBackupFileName($sourceFile));
        $singleBackup = $settings->singleFileBackup; 

        // Same file exists as backup already, don't overwrite in that case.
        if ($backupFile->exists() && $backupFile->getFileSize() == $sourceFile->getFileSize())
        {
          $result = true;
          $this->statusCode = self::STATUS_BACKUP_OK;
        }
        elseif(true === $singleBackup && $mainFile->getFullPath() !== $sourceFile->getFullPath() )
        {
           
           if (false === $this->hasBackup($mainFile, true))
           {
               $bool = $this->createBackupFile($mainFile); 
               $result = $bool;
           }
           else
           {
            $result = true; // Ok 
           }

           $this->statusCode = self::STATUS_IGNORED; 
        }
        else
        {
          $check = true; 
          if (method_exists($sourceFile, 'checkVirtualForBackup'))
          {
              $check = $sourceFile->checkVirtualForBackup();  
          }

          if (true === $check)
          {
              $result = $sourceFile->copy($backupFile);   
          }
          else 
          {
             $result = false; 
          }
          
        }

          // Remove the cache if there, since it will re-ask this to check copy success.
        if (isset($this->backup_files[$imageName])) 
        {
            unset ($this->backup_files[$imageName]); 
        }

        if (false === $result)
        {
          Log::addWarn('Creating Backup File failed for ' . $sourceFile->getFullPath());
          $this->statusCode = self::ERR_COPY_FAILED; 
          return false;
        }


        /* Seemingly doing with backup.  Important here is that hasBackup is not fully 'functional' in checking main-file since during optimizationHandling the thumbnail in question is not optimized yet ( status-wise ) */
        return true;

     }

     /**
      * Move the backup file for $sourceFile back to its live location.
      *
      * Special case for converted attachments (`isConverted && needsRegenerate`):
      * when a non-main-file thumbnail is being restored and thumbnails will
      * be regenerated after the main-file restore anyway, this method just
      * deletes the thumbnail via onDelete() instead of trying to restore
      * a non-existent per-thumbnail backup.
      *
      * On missing/unwritable backup or target, records a ResponseController
      * error and returns false. Otherwise moves the backup file to the
      * source's directory, using the *backup file's own name* — this is
      * how the extension swap during a converted-restore works (e.g. the
      * live file is `.jpg`, the backup is `.png`, restore places `.png`
      * next to the `.jpg`; the caller is responsible for the extension
      * housekeeping).
      *
      * @param ImageModel $sourceFile Image to restore.
      * @return bool
      */
     public function restore(ImageModel $sourceFile) : bool
     {
         $fs = \wpSPIO()->filesystem();
         $backupFile = $this->getBackupFile($sourceFile); 
         $imageName = $this->getBackupName($sourceFile->get('name'), $sourceFile); 
        
         $mainFile = $this->getMainFile();
         // If converted, and the thumbnail will be generated anyhow, then just remove it. 
         if ($this->isConverted && $this->needsRegenerate() && $mainFile->getFullPath() !== $sourceFile->getFullPath())
         {
              return $this->onDelete($sourceFile); 
         }

         if (false === $backupFile || false === is_object($backupFile))
         {
           // If not own file, but main file is in play, return OK but this needs a regenerate. 
           if (false === $this->backup_files[$imageName]['has_own_file'])
           {
             // If needs generate, not mainfile, remove the file.
              if ($this->needsRegenerate() && $mainFile->getFullPath() !== $sourceFile->getFullPath())
              {
                 $sourceFile->delete();
              }
              return true; 
           }
           Log::addWarn('Issue with restoring BackupFile, probably missing - ', $backupFile);
           return false; //error
         }

         $targetFile = $fs->getFile( (string) $sourceFile->getFileDir() .  $backupFile->getFileName() );

        if (false === $backupFile->is_readable())
        {
						Log::addError('BackupFile not readable' . $backupFile->getFullPath());
						$response = array(
								'is_error' => true,
								'issue_type' => ResponseController::ISSUE_BACKUP_EXISTS,
								'message' => __('BackupFile not readable. Check file and/or file permissions', 'shortpixel-image-optimiser'),
						);          
						ResponseController::addData($this->mediaItem->get('id'), $response);

           return false; //error
         }
				 elseif (false === $backupFile->is_writable())
				 {
 						Log::addError('BackupFile not writable' . $backupFile->getFullPath());
						 $response = array(
								 'is_error' => true,
								 'issue_type' => ResponseController::ISSUE_FILE_NOTWRITABLE,
								 'message' => __('The backup file is not writable. Check file and/or file permissions', 'shortpixel-image-optimiser'),

						 );
						 ResponseController::addData($this->mediaItem->get('id'), $response);
            return false; //error
				 }
				 if (false === $targetFile->is_writable())
				 {
					 	 Log::addError('Target File not writable' . $targetFile->getFullPath());

						 $response = array(
								 'is_error' => true,
								 'issue_type' => ResponseController::ISSUE_FILE_NOTWRITABLE,
								 'message' => __('Target file not writable. Check file permissions', 'shortpixel-image-optimiser'),

						 );
						 ResponseController::addData($this->mediaItem->get('id'), $response);

						 return false;
				 }

         // Attempt for easy support of different file-extensions / conversions, move backupfile back based on it's own file
				$bool = $backupFile->move($targetFile);

        $this->backup_files = []; // Reset the cache 
        return $bool;
    }

    /**
     * Return the fully-loaded `$backup_files` map, running `loadAll()` on
     * first access so every image in the family has been probed.
     *
     * @return array<string, array{has_backup: bool, file: string|false, has_own_file: bool}>
     */
    public function getBackupData()
    {
      if (false === $this->full_backup_loaded)
      {
         $this->loadAll();
      }

      return $this->backup_files;
    }

    /**
     * Whether backups are stored as a single main-file entry that covers
     * thumbnails.
     *
     * LocalBackupModel writes one backup file per source file (main and
     * every thumbnail get their own), so this is always false.
     *
     * @return bool
     */
    public function backupIsMain()
    {
        return false;
    }

     /**
      * Whether a backup exists for the given image.
      *
      * Cheap first path: an already-populated cache entry short-circuits
      * to its stored `has_backup` value. Otherwise consults the filesystem
      * and, when no dedicated backup is found for a non-strict-mode
      * thumbnail lookup, falls back to checking whether the main file
      * has one that covers it (populating `has_own_file = false` on the
      * cache entry so `needsRegenerate()` knows to trigger a regen after
      * restore).
      *
      * @param ImageModel $sourceFile Image to look up.
      * @param bool       $strict     When true, skip the main-file fallback.
      *                               Used to prevent recursion when the
      *                               caller already knows about the main file.
      * @return bool
      */
     public function hasBackup(ImageModel $sourceFile, $strict = false) : bool
     {
      $imageName = $this->getBackupName($sourceFile->get('name'), $sourceFile);

      if (isset($this->backup_files[$imageName]))
      {
        $backupData = $this->backup_files[$imageName];
        if (isset($backupData['has_backup']))
        {
           return $backupData['has_backup'];
        }
      }

        $directory = $this->getBackupDirectory(false);
        if (false === $directory)
        {
          return false;
        }

        $backupFile =  $directory . $this->getBackupFileName($sourceFile);
        
        if (file_exists($backupFile) && ! is_dir($backupFile) )
        {
          $bool = true;
        }
        else {
          $bool = false;
        }

        // Check if the backup is at the main level. 
        // Only possible with mediaLibraryModel 
        $has_own_file = true; 
        if (false === $bool)
        {
          $backupFile = false; 
          $has_own_file = false; 

          // Check if main has a backup and use that if needed. 
          // @todo - This main file, can be originalfile as well, which is then not marked as main :/ 
          $mainFile = $this->getMainFile(); // This main file can be different than is_main_file, in case of -scaled 
          if (false === $strict && $sourceFile->isOptimized() && $mainFile->getFullPath() !== $sourceFile->getFullPath())
          {

           if ($mainFile->getFullPath() !== $sourceFile->getFullPath())
           {
            $bool = $this->hasBackup($mainFile, true);
           }
          }
        }  

        $this->backup_files[$imageName]  = [
          'has_backup' => $bool, 
          'file' => $backupFile,
          'has_own_file' => $has_own_file, 
        ];

        return $bool;
     }

     /**
      * Delete the backup file (if any) belonging to $sourceFile.
      *
      * Returns true when there was nothing to delete or when the delete
      * succeeded. Returns false only when a delete was attempted and
      * the underlying filesystem call reported failure.
      *
      * @param ImageModel $sourceFile Image whose backup should be removed.
      * @return bool
      */
     public function onDelete(ImageModel $sourceFile) : bool
     {
       if (true === $this->hasBackup($sourceFile))
       {
          $backupFile = $this->getBackupFile($sourceFile);
          if (is_object($backupFile))
          {
             return $backupFile->delete();
          }
       }
       $this->backup_files = []; // Remove the cache
       return true;
     }

     /**
      * Rename backup files to match a new base filename.
      * Handles both single file and multi-file backups (thumbnails, retina, etc.)
      * Used when renaming the original image file (e.g., via AI rename feature)
      *
      * @param string $newBaseFileName The new base filename (without extension)
      * @return bool True on success, false on failure
      */
     public function renameBackup($newBaseFileName) : bool
     {
          $this->loadAll();
          
          $fs = \wpSPIO()->filesystem();
          $backupDirectory = $this->getBackupDirectory(false);
          
          if (false === $backupDirectory)
          {
               Log::addWarn('Backup directory not found for ' . $this->mediaItem->getFullPath());
               return false;
          }
          
          $mainFile = $this->getMainFile();
          $oldBaseFileName = $mainFile->getFileBase();
          $newBackupFiles = [];
          $success = true;
          
          // Iterate through all existing backups and rename them
          foreach ($this->backup_files as $imageName => $backupData)
          {
               if (false === $backupData['has_backup'] || false === $backupData['has_own_file'])
               {
                    continue; // Skip backups that don't have their own files
               }
               
               $oldBackupPath = $backupData['file'];
               if (false === $oldBackupPath)
               {
                    continue;
               }
               
               try
               {
                    $oldBackupFile = $fs->getFile($oldBackupPath);
                    
                    if (false === $oldBackupFile->exists())
                    {
                         Log::addWarn('Backup file not found: ' . $oldBackupPath);
                         continue;
                    }
                    
                    // Construct the new backup filename by replacing the old base name
                    $oldFileName = $oldBackupFile->getFileName();
                    $newFileName = str_replace($oldBaseFileName, $newBaseFileName, $oldFileName);
                    
                    // Build the full path for the new backup file
                    $newBackupPath = $backupDirectory->getPath() . $newFileName;
                    $newBackupFile = $fs->getFile($newBackupPath);
                    
                    // Prevent conflicts - if target already exists, log and skip
                    if ($newBackupFile->exists())
                    {
                         Log::addWarn('Target backup file already exists: ' . $newBackupPath);
                         $success = false;
                         continue;
                    }
                    
                    // Perform the rename/move
                    if (false === $oldBackupFile->move($newBackupFile))
                    {
                         Log::addError('Failed to rename backup file from ' . $oldBackupPath . ' to ' . $newBackupPath);
                         $success = false;
                         continue;
                    }
                                        
                    // Update the cache with the new backup file path
                    $newImageName = str_replace($oldBaseFileName, $newBaseFileName, $imageName);
                    $newBackupFiles[$newImageName] = [
                         'has_backup' => $backupData['has_backup'],
                         'file' => $newBackupPath,
                         'has_own_file' => $backupData['has_own_file'],
                    ];
               }
               catch (\Exception $e)
               {
                    Log::addError('Exception while renaming backup: ' . $e->getMessage());
                    $success = false;
               }
          }
          
          // Update the backup_files cache with renamed entries
          $this->backup_files = $newBackupFiles;
          
          return $success;
     }


     /**
     * Function to get the backupDirectory from the file structure 
     * 
     * @param mixed $fileObj The fileModel 
     * @param bool $create  Create if the backupdirectory not exists yet ( i.e. month structure when it's the first )
     * @return object|boolean  The backupdirectory or false on failure.  
     */
    protected function getBackupDirectory($create = false)
    {
        if (is_null($this->mediaItem->getFileDir()))
        {
            Log::addWarn('Could not establish FileDir ' . $this->mediaItem->getFullPath());
            return false;
        }

        $fs = \wpSPIO()->filesystem();
    
        if (is_null($this->backupDirectory))
        {
          $directory = $fs->getBackupDirectory($this->mediaItem, $create);
    
          if ($directory === false || ! $directory->exists()) // check if exists. FileModel should not attempt to create.
          {
            return false;
          }
          elseif ($directory !== false)
          {
            $this->backupDirectory = $directory;
          }
          else
          {
            return false;
          }
        }
    
        return $this->backupDirectory;
    }

    /**
     * Return a FileModel pointing at the backup file for $sourceFile.
     *
     * Runs hasBackup() in strict mode first, so the main-file fallback
     * from hasBackup() does not paper over "this thumbnail has no
     * dedicated backup" — callers that want the covering main-file
     * backup should explicitly call getMainBackupFile().
     *
     * When a dedicated backup exists but `has_own_file` is false (the
     * thumbnail is covered by the main file), returns false.
     *
     * @param ImageModel $sourceFile Image to look up.
     * @return FileModel|false
     */
    public function getBackupFile(ImageModel $sourceFile)
    {
      $fs = \wpSPIO()->filesystem();
      $imageName = $this->getBackupName($sourceFile->get('name'), $sourceFile);

      if (true === $this->hasBackup($sourceFile, true))
       {
          if (true === $this->backup_files[$imageName]['has_own_file']) // only if own file is set, otherwise file is empty, refering to directory.
          {
            $file = $this->backup_files[$imageName]['file'];
            $fileObj = $fs->getFile($file);
            return $fileObj;
          }
          else
          {
             return false; 
          }
       }
       else
       {
         return false;
       }
    }

    /**
     * Return the backup file that represents the "main" backup for this
     * media item — the unscaled original when scaled, otherwise the
     * live main file.
     *
     * @return FileModel|false
     */
    public function getMainBackupFile()
    {
        $mainFile = $this->getMainFile();
        $backupFile = $this->getBackupFile($mainFile);

        return $backupFile;
    }


      /**
       * Walk every file that belongs to the media item (main + thumbnails
       * + retinas + original) and call hasBackup() on each to fully
       * populate the `$backup_files` cache. Sets the `$full_backup_loaded`
       * guard so subsequent getBackupData() / renameBackup() calls skip
       * this work.
       *
       * @return void
       */
      protected function loadAll()
      {
        $filesArray = $this->mediaItem->getAllFiles();
        $files = $filesArray['files'];

        foreach ($files as $obj)
        {
           $this->hasBackup($obj);
        }

        $this->full_backup_loaded = true;
      }


      /**
       * Return the ImageModel that owns the "canonical" main backup for
       * this media item.
       *
       * For Media Library items that have a WP 5.3+ unscaled original,
       * that original is the main backup (thumbnails were cut from it);
       * for everything else (custom images, non-scaled media items), the
       * media item itself is used.
       *
       * @return ImageModel
       */
      private function getMainFile()
      {
          if ('media' === $this->mediaItem->get('type') && $this->mediaItem->hasOriginal())
          {
             return $this->mediaItem->getOriginalFile();
          }
          else
          {
             return $this->mediaItem;
          }
      }

      /**
       * Compute the key used in the `$backup_files` cache.
       *
       * Retina variants are prefixed with `retina_` so they don't collide
       * with the same-named non-retina thumbnail entry.
       *
       * @param string     $imageName  Base image name (typically the WP size name).
       * @param ImageModel $sourceFile Image, used to inspect the imageType constant.
       * @return string
       */
      private function getBackupName($imageName, $sourceFile) : string
      {
         $imageType = $sourceFile->get('imageType');


        if (ImageModel::IMAGE_TYPE_RETINA === $imageType)
        {
            $imageName = 'retina_' . $imageName;
        }

        return $imageName;
      }

}