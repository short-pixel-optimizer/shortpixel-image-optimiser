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
	protected $is_retina = false; // diffentiate from thumbnail / retina.
  protected $id; // this is the parent attachment id
  protected $size; // size of image in WP, if applicable.

  public function __construct($path, $id, $size)
  {
        parent::__construct($path);
        $this->image_meta = new ImageThumbnailMeta();
        $this->id = $id;
				$this->imageType = self::IMAGE_TYPE_THUMB;
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
			'size' => $this->size,
      'exists' => ($this->exists()) ? 'yes' : 'no',

    );
  }

  /** Set the meta name of thumbnail. */
  public function setName($name)
  {
     $this->name = $name;
  }

	public function setImageType($type)
	{
		 $this->imageType = $type;
	}

  public function getRetina()
  {
      $filebase = $this->getFileBase();
      $filepath = (string) $this->getFileDir();
      $extension = $this->getExtension();

      $retina = new MediaLibraryThumbnailModel($filepath . $filebase . '@2x.' . $extension, $this->id, $this->size); // mind the dot in after 2x
			$retina->setName($this->size);
			$retina->setImageType(self::IMAGE_TYPE_RETINA);

			$retina->is_retina = true;

      if ($retina->exists())
        return $retina;

      return false;
  }



  public function isFileTypeNeeded($type = 'webp')
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



	// get_path param see MediaLibraryModel
  public function getOptimizeUrls()
  {
    if (! $this->isProcessable() )
      return false;

		$url = $this->getURL();

    if (! $url)
		{
      return false; //nothing
		}

    return $url;
  }

  public function getURL()
  {
			$fs = \wpSPIO()->filesystem();

      if ($this->size == 'original' && ! $this->get('is_retina'))
			{
        $url = wp_get_original_image_url($this->id);
			}
      elseif ($this->isUnlisted())
			{
				$url = $fs->pathToUrl($this);
			}
			else
			{
				// We can't trust higher lever function, or any WP functions.  I.e. Woocommerce messes with the URL's if they like so.
				// So get it from intermediate and if that doesn't work, default to pathToUrl - better than nothing.
				// https://app.asana.com/0/1200110778640816/1202589533659780
				$size_array = image_get_intermediate_size($this->id, $this->size);

				if ($size_array === false || ! isset($size_array['url']))
				{
					 $url = $fs->pathToUrl($this);
				}
				elseif (isset($size_array['url']))
				{
					 $url = $size_array['url'];
					 // Even this can go wrong :/
					 if (strpos($url, $this->getFileName() ) === false)
					 {
						 // Taken from image_get_intermediate_size if somebody still messes with the filters.
							$mainurl = wp_get_attachment_url( $this->id);
							$url = path_join( dirname( $mainurl ), $this->getFileName() );
					 }
				}
				else {
						return false;
				}

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
			{
				$this->processable_status = self::P_EXCLUDE_SIZE;
        return false;
			}
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
		{
			$this->processable_status = self::P_EXCLUDE_SIZE;
      return true;
		}
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

	public function hasDBRecord()
	{
			global $wpdb;


			$sql = 'SELECT id FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d AND size = %s';
			$sql = $wpdb->prepare($sql, $this->id, $this->size);

			$id = $wpdb->get_var($sql);

			if (is_null($id))
			{
				 return false;
			}
			elseif (is_numeric($id)) {
				return true;
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
