<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\File\FileModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class LocalBackupModel extends BackupModel
{

  
    // This must be able to create backup for images one-by-one. 
     public function create(FileModel $sourceFile)
     {
        // Safety: It should absolutely not be possible to overwrite a backup file.
        if ($this->hasBackup($sourceFile))
        {
           $backupFile = $this->getBackupFile($sourceFile);
 
           // If backupfile is bigger (indicating original file)
           if ($backupFile->getFileSize() == $fileObj->getFileSize())
           {
              return true;
           }
           else
           {
             // Return the backup for a retry.
             if ($this->isRestorable() && ($backupFile->getFileSize() > $this->getFileSize()))
             {
                 Log::addWarn('Backup Failed, File is restorable, try to recover. ' . $this->getFullPath() );
                 $this->restore();
 
                 $this->error_message = __('Backup already exists, but image is recoverable and the plugin will rollback. Will retry to optimize again. ', 'shortpixel-image-optimiser');
             }
 /*						elseif ($backupFile->getFileSize() > $this->getFileSize() && ! $backupFile->is_virtual() ) // Where there is a backup and it's bigger, assume some hickup, but there is backup so hooray
             {
                  Log::addWarn('Backup already exists. Backup file is bigger, so assume that all is good with backup and proceed');
                return true; // ok it.
             } */
             else
             {
               $this->preventNextTry(__('Fatal Issue: The Backup file already exists. The backup seems not restorable, or the original file is bigger than the backup, indicating an error.', 'shortpixel-image-optimiser'));
 
               Log::addError('The backup file already exists and it is bigger than the original file. BackupFile Size: ' . $backupFile->getFileSize() . ' This Filesize: ' . $this->getFileSize(), $this->fullpath);
 
               $this->error_message = __('Backup not possible: it already exists and the original file is bigger.', 'shortpixel-image-optimiser');
             }
 
             return false;
           }
           exit('Fatal error, createbackup protection - this should never reach');
        }
        
        $directory = $this->getBackupDirectory(true);
        $fs = \wpSPIO()->filesystem();
 
        // @Deprecated
        if(apply_filters('shortpixel_skip_backup', false, $this->getFullPath(), $this->is_main_file)){
            return true;
        }
        if(apply_filters('shortpixel/image/skip_backup', false, $this->getFullPath(), $this->is_main_file)){
            return true;
        }
 
        if (! $directory)
        {
           Log::addWarn('Could not create Backup Directory for ' . $this->getFullPath());
           $this->error_message = __('Could not create backup Directory', 'shortpixel-image-optimiser');
           return false;
        }
 
        $backupFile = $fs->getFile($directory . $this->getBackupFileName());
 
        // Same file exists as backup already, don't overwrite in that case.
        if ($backupFile->exists() && $this->hasBackup() && $backupFile->getFileSize() == $this->getFileSize())
        {
           $result = true;
        }
        else
        {
          $result = $this->copy($backupFile);
        }
 
        if (! $result)
        {
           Log::addWarn('Creating Backup File failed for ' . $this->getFullPath());
           return false;
        }
 
        if ($this->hasBackup())
          return true;
        else
        {
           Log::addWarn('FileModel returns no Backup File for (failed) ' . $this->getFullPath());
           return false;
        }
     }

     // This one should probably do the whole procedure. 
     public function restore(FileModel $fileObj)
     {

     }

     public function hasBackup(FileModel $fileObj)
     {
        $directory = $this->getBackupDirectory();
        if (false === $directory)
        {
          return false;
        }

        $backupFile =  $directory . $this->getBackupFileName();
  
        if (file_exists($backupFile) && ! is_dir($backupFile) )
          return true;
        else {
          return false;
        }
     }

         /**
     * Function to get the backupDirectory from the file structure 
     * 
     * @param mixed $fileObj The fileModel 
     * @param bool $create  Create if the backupdirectory not exists yet ( i.e. month structure when it's the first )
     * @return object|boolean  The backupdirectory or false on failure.  
     */
    public function getBackupDirectory($create = false)
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

    public function getBackupFile()
    {
       if ($this->hasBackup())
          return new FileModel($this->getBackupDirectory() . $this->getBackupFileName() );
       else
         return false;
    }

    	/** Function returns the filename for the backup.  This is an own function so it's possible to manipulate backup file name if needed, i.e. conversion or enumeration */
      public function getBackupFileName(FileModel $fileObj)
      {
            // This can't be mediaItem directly, needs to either use main / thumbs or whatever is requested here. 
         return $fileObj->getFileName();
      }


    


}