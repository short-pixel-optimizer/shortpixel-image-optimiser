<?php
namespace ShortPixel\Model\Backup;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class LocalBackupModel extends BackupModel
{

  
    /*public function createBackupDirectory()
    {

    } */

    // This must be able to create backup for images one-by-one. 
     public function createBackupFile(ImageModel $sourceFile)
     {
        $directory = $this->getBackupDirectory(true);
        $fs = \wpSPIO()->filesystem();
        $imageName = $sourceFile->get('name');

        if (! $directory)
        {
          Log::addWarn('Could not create Backup Directory for ' . $sourceFile->getFullPath());
         // $this->error_message = __('Could not create backup Directory', 'shortpixel-image-optimiser');
          return false;
        }
        
        $backupFile = $fs->getFile($directory . $this->getBackupFileName($sourceFile));

        // Same file exists as backup already, don't overwrite in that case.
        if ($backupFile->exists() && $this->hasBackup($sourceFile) && $backupFile->getFileSize() == $sourceFile->getFileSize())
        {
          $result = true;
        }
        else
        {
          $result = $sourceFile->copy($backupFile);

          // Remove the cache if there, since it will re-ask this to check copy success.
          if (isset($this->backup_files[$imageName])) 
          {
             unset ($this->backup_files[$imageName]); 
          }
        }

        if (! $result)
        {
          Log::addWarn('Creating Backup File failed for ' . $sourceFile->getFullPath());
          return false;
        }

        if ($this->hasBackup($sourceFile))
          return true;
        else
        {
          Log::addWarn('FileModel returns no Backup File for (failed) ' . $sourceFile->getFullPath());
          return false;
        }

     }

     // This one should probably do the whole procedure. 
     // Problem - how to find all the file items here. 
     public function restore(FileModel $targetFile)
     {
         $backupFile = $this->getBackupFile($targetFile); 
         return $backupFile->move($targetFile);
     }

     public function hasBackup(ImageModel $sourceFile)
     {
      $is_main_file = $sourceFile->get('is_main_file');
      $imageName = $sourceFile->get('name');

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

        $backupFile =  $directory . $sourceFile->getBackupFileName();
        
        if (file_exists($backupFile) && ! is_dir($backupFile) )
        {
          $bool = true;
        }
        else {
          $bool = false;
        }

        $this->backup_files[$imageName]  = [
          'has_backup' => $bool, 
          'file' => $backupFile,    
        ];

        return $bool;

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

    public function getBackupFile(ImageModel $sourceFile)
    {

      $imageName = $sourceFile->get('name');
      
      if (true === $this->hasBackup($sourceFile))
       {
          $file = $this->backup_files[$imageName]['file']; 
          $fileObj = new FileModel($file); 
          return $fileObj; 
       }    //      return new FileModel($this->getBackupDirectory() . $this->getBackupFileName() );
       else
       {
         return false;
       }
    }

    	/** Function returns the filename for the backup.  This is an own function so it's possible to manipulate backup file name if needed, i.e. conversion or enumeration */
      public function getBackupFileName(FileModel $fileObj)
      {
            // This can't be mediaItem directly, needs to either use main / thumbs or whatever is requested here. 
         return $fileObj->getFileName();
      }


      protected function getAll()
      {
        
        $objects = $this->mediaItem->get('thumbnails');
        if ($this->mediaItem->isScaled()) {
          $objects[$this->mediaItem->getImageKey('original')] = $this->mediaItem->getOriginalFile();
        }
        
        return $objects; 

      }
      
   
      protected function loadAll()
      {

        $objects = $this->getAll();
        foreach ($objects as $obj)
        {
           $this->hasBackup($obj); 
        }

        $this->full_backup_loaded = true; 
        
      }

      // @todo This one in restore in ImageModel 
      public function restoreAll()
      {
         foreach($this->backup_files as $backupData)
         {
            if (true === $backupData['has_backup'])
            {
                $fileObj = $backupData['file'];
                $fileObj->restore(); // for now invoke it on the imageModel 
            }
         }
      }

      // @todo This one hook into ImageModel, on the pyshical file delete. 
      public function onDeleteAll()
      {
         foreach($this->backup_files as $backupData)
         {
            if (true === $backupData['has_backup'])
            {
                $backupData['file']->delete(); 
            }
         }
      }

}