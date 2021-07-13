<?php

namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


// Represent a thumbnail image / limited image in mediaLibrary.
class MediaLibraryThumbnailModel extends \ShortPixel\Model\Image\ImageModel
{
  //abstract protected function saveMeta();
  //abstract protected function loadMeta();

  public $name;
/*  public $width;
  public $height;
  public $mime; */
  protected $prevent_next_try = false;
  protected $is_main_file = false;
  protected $id; // this is the parent attachment id
  protected $size; // size of image in WP, if applicable.

  public function __construct($path, $id, $size)
  {
        parent::__construct($path);
        $this->image_meta = new ImageThumbnailMeta();
        $this->id = $id;
        $this->size = $size;
        $this->setWebp();
        $this->setAvif();
  }


  protected function loadMeta()
  {

  }

  protected function saveMeta()
  {

  }

  public function __debugInfo() {
     return array(
      'image_meta' => $this->image_meta,
      'name' => $this->name,
      'path' => $this->getFullPath(),
      'exists' => ($this->exists()) ? 'yes' : 'no',

    );
  }

  /** Set the meta name of thumbnail. */
  public function setName($name)
  {
     $this->name = $name;
  }

  public function getRetina()
  {
      $filebase = $this->getFileBase();
      $filepath = (string) $this->getFileDir();
      $extension = $this->getExtension();

      $retina = new MediaLibraryThumbnailModel($filepath . $filebase . '@2x.' . $extension); // mind the dot in after 2x

      if ($retina->exists())
        return $retina;

      return false;
  }

  /** @todo Might be moved to ImageModel, if customImage also has Webp */
  public function getWebp()
  {
    $fs = \wpSPIO()->filesystem();

    if (! is_null($this->getMeta('webp')))
    {
      $filepath = $this->getFileDir() . $this->getMeta('webp');
      $webp = $fs->getFile($filepath);
      return $webp;
    }


    $double_webp = \wpSPIO()->env()->useDoubleWebpExtension();

    if ($double_webp)
      $filename = $this->getFileName();
    else
      $filename = $this->getFileBase();

    $filename .= '.webp';
    $filepath = $this->getFileDir() . $filename;

    $webp = $fs->getFile($filepath);

    if (! $webp->is_virtual() && $webp->exists())
      return $webp;

    return false;
  }

  public function getAvif()
  {
      $fs = \wpSPIO()->filesystem();
      if (! is_null($this->getMeta('avif')))
      {
        $filepath = $this->getFileDir() . $this->getMeta('avif');
        $avif = $fs->getFile($filepath);
        return $webp;
      }

    return false;
  }

  public function getOptimizeFileType($type = 'webp')
  {
      if ($type == 'webp')
        $file = $this->getWebp();
      elseif ($type == 'avif')
        $file = $this->getAvif();

      if ( ($this->isThumbnailProcessable() || $this->isOptimized()) && $file === false)  // if no file, it can be optimized.
        return true;
      else
        return false;
  }

  /** @todo Might be moved to ImageModel, if customImage also has Webp */
  protected function setWebp()
  {
      $webp = $this->getWebp();
      if ($webp !== false && $webp->exists())
        $this->setMeta('webp', $webp->getFileName() );

  }

  /** @todo Might be moved to ImageModel, if customImage also has Webp */
  protected function setAvif()
  {
      $avif = $this->getAvif();
      if ($avif !== false && $avif->exists())
        $this->setMeta('avif', $avif->getFileName() );

  }

  protected function setMetaObj($metaObj)
  {
     $this->image_meta = $metaObj;
  }

  protected function getMetaObj()
  {
    return $this->image_meta;
  }

  public function getOptimizePaths()
  {
    if (! $this->isProcessable() )
      return array();

    return array($this->getFullPath());
  }

  public function getOptimizeUrls()
  {
    if (! $this->isProcessable() )
      return array();

    $url = $this->getURL();
    if (! $url)
      return array(); //nothing


    return array($url);
  }

  public function getURL()
  {
      if ($this->size == 'original')
        $url = wp_get_original_image_url($this->id);
      else
        $url = wp_get_attachment_image_url($this->id, $this->size);

      return $this->fs()->checkURL($url);
  }

  // Just a placeholder for abstract, shouldn't do anything.
  public function getImprovements()
  {
     return parent::getImprovements();
  }

  // From FileModel.  BackupFile is different when png2jpg was converted.
  /*public function getBackupFile()
  {
     if ($this->getMeta('did_png2jpg') == true)
     {
       $backupFile = new FileModel($this->getBackupDirectory() . $this->getFileBase()  . '.png');
       if ($backupFile->exists()) // Check for original PNG
       {
          return $backupFile;
       }
       else // Backup (haha) in case something went wrong.
       {
          $backupFile = parent::getBackupFile();
          if (is_object($backupFile))
            return $backupFile;
       }
       return false; // Error State
    }
    else
    {
       return parent::getBackupFile();
    }
  } */


  //public function isRestorable()
  protected function preventNextTry($reason = '')
  {
      Log::addTemp('Thumb : preventNextTry ' . $reason);
      $this->prevent_next_try = $reason;
  }

  // Don't ask thumbnails this, only the main image
  public function isOptimizePrevented()
  {
     return false;
  }

  // Don't ask thumbnails this, only the main image
  public function resetPrevent()
  {
     return null;
  }

  protected function isThumbnailProcessable()
  {
      if ( $this->excludeThumbnails()) // if thumbnail processing is off, thumbs are never processable.
        return false;
      else
      {

        return parent::isProcessable();
      }
  }


  // !Important . This doubles as  checking excluded image sizes.
  protected function isSizeExcluded()
  {
    $excludeSizes = \wpSPIO()->settings()->excludeSizes;
    if (is_array($excludeSizes) && in_array($this->name, $excludeSizes))
      return true;

    return false;
  }

  protected function excludeThumbnails()
  {
    return (! \wpSPIO()->settings()->processThumbnails);
  }

  public function hasBackup()
  {
      if (! $this->getMeta('did_png2jpg'))
      {
          return parent::hasBackup();
      }
      else
      {
        $directory = $this->getBackupDirectory();
        if (! $directory)
          return false;

          $backupFile =  $directory . $this->getFileBase() . '.png';

        if (file_exists($backupFile) && ! is_dir($backupFile) )
          return true;
        else {
          return false;
        }
      }
  }

  public function restore()
  {
    if ($this->is_virtual())
    {
       $fs = \wpSPIO()->filesystem();
       $filepath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);

       $this->setVirtualToReal($filepath);
    }

    return parent::restore();
  }

  protected function createBackup()
  {
    if ($this->is_virtual()) // download remote file to backup.
    {
      $fs = \wpSPIO()->filesystem();
      $filepath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);
      $result = $fs->downloadFile($this->getURL(), $filepath); // download remote file for backup.

      if ($result == false)
      {
        $this->preventNextTry(__('Fatal Issue: Remote virtual file could not be downloaded for backup', 'shortpixel-image-optimiser'));
        Log::addError('Remote file download failed to: ' . $filepath, $this->getURL());
        $this->error_message = __('Remote file could not be downloaded' . $this->getFullPath(), 'shortpixel-image-optimiser');

        return false;
      }

      $this->setVirtualToReal($filepath);
    }

    return parent::createBackup();

  }

  private function setVirtualToReal($fullpath)
  {
    $this->resetStatus();
    $this->fullpath = $fullpath;
    $this->directory = null; //reset directory
    $this->is_virtual = false; // stops being virtual
    $this->setFileInfo();
    Log::addTemp('Debug Translated File Info -- ' . $this->getFullPath() .  ' ' . $this->getFileDir());

  }

  /** Tries to retrieve an *existing* BackupFile. Returns false if not present.
  * This file might not be writable.
  * To get writable directory reference to backup, use FileSystemController
  */
  public function getBackupFile()
  {
    if (! $this->getMeta('did_png2jpg'))
    {
        return parent::getBackupFile();
    }
    else
    {
     if ($this->hasBackup())
        return new FileModel($this->getBackupDirectory() . $this->getFileBase() . '.png' );
     else
       return false;
    }
  }




} // class
