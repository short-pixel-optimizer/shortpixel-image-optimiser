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
  protected $is_main_file = false;

  public function __construct($path)
  {
        parent::__construct($path);
        $this->image_meta = new ImageThumbnailMeta();
        $this->setWebp();
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

    if ($webp->exists())
      return $webp;

    return false;
  }

  protected function setWebp()
  {
      $webp = $this->getWebp();
      if ($webp !== false && $webp->exists())
        $this->setMeta('webp', $webp->getFileName() );

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
    $fs = \wpSPIO()->filesystem();
    // return $url
    if (! $this->isProcessable() )
      return array();

    return array($fs->pathToUrl($this));
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


  protected function isThumbnailProcessable()
  {
      if ( $this->excludeThumbnails()) // if thumbnail processing is off, thumbs are never processable.
        return false;
      else
      {
        //echo "EXIST" . $this->getFullPath(); var_dump($this->exists());
        //echo "OPtimized"; var_dump($this->isOptimized());
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
