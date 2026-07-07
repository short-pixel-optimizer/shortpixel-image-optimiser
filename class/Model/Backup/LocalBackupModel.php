<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ResponseController;
use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class LocalBackupModel extends BackupModel
{

    // This must be able to create backup for images one-by-one. 
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

     // This one should probably do the whole procedure. 
     // Problem - how to find all the file items here. 
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

        return $bool;
    }

    public function getBackupData()
    {
      if (false === $this->full_backup_loaded)
      {
         $this->loadAll(); 
      }

      return $this->backup_files;
    }

    public function backupIsMain()
    {

    }

     /** Checks if there is a backup . This is simplest / less intensive check, should be used for overviews etc
      * 
      * @param ImageModel $sourceFile 
      * @param bool $strict .  Don't look for mainFile. Check used for determine file / prevent loops. 
      * @return bool 
      */
     public function hasBackup(ImageModel $sourceFile, $strict = false) : bool
     {
      $is_main_file = $sourceFile->get('is_main_file');
      $imageName = $this->getBackupname($sourceFile->get('name'), $sourceFile);
      $imageType = $sourceFile->get('imageType');

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

     public function onDelete(ImageModel $sourceFile) : bool
     {
       //$isConverted = $this->isConverted; 
       //$name = $sourceFile->get('name');
       
       if (true === $this->hasBackup($sourceFile))
       {
          $backupFile = $this->getBackupFile($sourceFile);
          if (is_object($backupFile))
          {
             $backupFile->delete();
          }   
       }
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

    /** Get the backup file
     * 
     * @param ImageModel $sourceFile 
     * @return FileModel|false 
     */
    public function getBackupFile(ImageModel $sourceFile)
    {
      $imageName = $this->getBackupName($sourceFile->get('name'), $sourceFile);
      
      if (true === $this->hasBackup($sourceFile, true))
       {
          if (true === $this->backup_files[$imageName]['has_own_file']) // only if own file is set, otherwise file is empty, refering to directory.
          {
            $file = $this->backup_files[$imageName]['file']; 
            $fileObj = new FileModel($file); 
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

    public function getMainBackupFile()
    {
        $mainFile = $this->getMainFile(); 
        $backupFile = $this->getBackupFile($mainFile); 

        return $backupFile; 
    }

  
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