<?php

namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

// Future contoller for the edit media metabox view.
class OtherMediaController extends ShortPixelController
{

  //  protected $dataProvider; // spmetadao

    public function __construct()
    {
        parent::__construct();
      //  $this->dataProvider = \wpSPIO()->getShortPixel()->getSpMetaDao();

        $this->loadModel('directory');
        $this->loadModel('directory_othermedia');

    }

    // Get CustomFolder for usage.
    public function getFolders()
    {
        $folders = DirectoryOtherMediaModel::get();
        return $folders;
    }

    public function getFolder($id)
    {
        $folders = DirectoryOtherMediaModel::get(array('id' => $id));

        if (count($folders) > 0)
          return $folders[0];

        return false;
    }

    public function addDirectory($path)
    {
       $fs = \wpSPIO()->filesystem();
       $directory = new DirectoryOtherMediaModel($path);
       $rootDir = $fs->getWPFileBase();
       $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);


       if (! $directory->exists())
       {
          Notices::addError(__('Could not be added, directory not found: ' . $path ,'shortpixel-image-optimiser'));
          return false;
       }
       elseif (! $directory->isSubFolderOf($rootDir))
       {
          Notices::addError( sprintf(__('The %s folder cannot be processed as it\'s not inside the root path of your website (%s).','shortpixel-image-optimiser'),$addedFolder, $rootDir->getPath()));
          return false;
       }
       elseif($directory->isSubFolderOf($backupDir) || $directory->getPath() == $backupDir->getPath() )
       {
          Notices::addError( __('This folder contains the ShortPixel Backups. Please select a different folder.','shortpixel-image-optimiser'));
          return false;
       }

       if (! $directory->hasDBEntry())
       {
          Log::addDebug('Has DB ENTry, on addDirectory');
         if ($directory->save())
          $directory->refreshFolder(0);
       }
       else // if directory is already added, fail silently, but still refresh it.
       {
         $directory->refreshFolder();
       }

       if ($directory->exists() && $directory->getID() > 0)
        return true;

      return false;
    }

    public function refreshFolder($directory, $force = false)
    {

      $updated = $directory->updateFileContentChange();
      $update_time = $directory->getUpdated();
      if ($updated || $force)
      {

        // when forcing, set to never updated.
        if ($force)
        {
          $update_time = 0; // force from begin of times.
        }

        if ($directory->exists() )
        {
          $directory->refreshFolder($update_time);
        }
        else {
          Log::addWarn('Custom folder does not exist: ', $directory);
          return false;
        }
      }

    }

    /** Check directory structure for new files */
    public function refreshFolders($force = false, $expires = 5 * MINUTE_IN_SECONDS)
    {
      $customFolders = $this->getFolders();

      $cache = new CacheController();
      $refreshDelay = $cache->getItem('othermedia_refresh_folder_delay');

      if ($refreshDelay->exists() && ! $force)
      {
        return true;
      }

      $refreshDelay->setExpires($expires);
      $refreshDelay->save();


      foreach($customFolders as $directory) {
        if ($force)
        {
          $cache->deleteItemObject($refreshDelay);
        }

          $this->refreshFolder($directory, $force);

      } // folders

      return true;
    }


}
