<?php

namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use \Shortpixel\Model\File\FileModel as FileModel;

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



  public function getOptimizeFileType($type = 'webp')
  {
      // pdf extension can be optimized, but don't come with these filetypes
      if ($this->getExtension() == 'pdf')
      {
        return false;
      }

      if ($type == 'webp')
        $file = $this->getWebp();
      elseif ($type == 'avif')
        $file = $this->getAvif();

      if ( ($this->isThumbnailProcessable() || $this->isOptimized()) && $file === false)  // if no file, it can be optimized.
        return true;
      else
        return false;
  }

	// @param FileDelete can be false. I.e. multilang duplicates might need removal of metadata, but not images.
  public function onDelete($fileDelete = true)
  {
			if ($fileDelete == true)
      	$bool = parent::onDelete();
			else {
				$bool = true;
			}

			// minimally reset all the metadata.
			$this->image_meta = new ImageThumbnailMeta();
			return $bool;
  }



  protected function setMetaObj($metaObj)
  {
     $this->image_meta = clone $metaObj;
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

	// get_path param see MediaLibraryModel
  public function getOptimizeUrls($get_path = false)
  {
    if (! $this->isProcessable() )
      return array();

		if (true === $get_path)
		{
			$url = $this->getFullPath();
		}
		else {
			$url = $this->getURL();
		}

    if (! $url)
		{
      return array(); //nothing
		}

    return array($url);
  }

  public function getURL()
  {
			$fs = \wpSPIO()->filesystem();

      if ($this->size == 'original')
			{
        $url = wp_get_original_image_url($this->id);
			}
      elseif ($this->isUnlisted())
				$url = $fs->pathToUrl($this);
			else
			{
				$url = wp_get_attachment_image_url($this->id, $this->size);
			}


      return $this->fs()->checkURL($url);
  }

  // Just a placeholder for abstract, shouldn't do anything.
  public function getImprovements()
  {
     return parent::getImprovements();
  }

  protected function preventNextTry($reason = '')
  {
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
			// if thumbnail processing is off, thumbs are never processable.
			// This is also used by main file, so check for that!
      if ( $this->excludeThumbnails() && $this->is_main_file === false)
        return false;
      else
      {
        $bool = parent::isProcessable();

				return $bool;
      }
  }

	/** Function to check if said thumbnail is a WP-native or something SPIO added as unlisted
	*
	*
	*/
	protected function isUnlisted()
	{
		 	 if (! is_null($this->getMeta('file')))
			 	return true;
			else
				return false;
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
				// Issue with PNG not being scaled on the main file.
				if (! file_exists($backupFile) && $this->is_main_file == true && $this->isScaled())
				{
					 $backupFile = $directory . $this->getOriginalFile()->getFileBase() . '.png';
				}

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

    $bool = parent::restore();

		if ($bool === true)
		{
			 $this->image_meta = new ImageThumbNailMeta();
		}

		return $bool;
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
		 {
			  $directory = $this->getBackupDirectory();
			  $backupFile = $directory . $this->getFileBase() . '.png';

				/* Because WP doesn't support big PNG with scaled for some reason, it's possible it doesn't create them. Which means we end up with a scaled images without backup */
 				if (! file_exists($backupFile) && $this->isScaled())
 				{
 					 $backupFile = $directory . $this->getOriginalFile()->getFileBase() . '.png';
 				}

				return new FileModel($backupFile);

		 }
     else
       return false;
    }
  }




} // class
