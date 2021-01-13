<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Controller\ApiController as API;

use \Shortpixel\Model\File\FileModel as FileModel;
/* ImageModel class.
*
*
* - Represents a -single- image entity *not file*.
* - Can be either MediaLibrary, or Custom .
* - Not a replacement of Meta, but might be.
* - Goal: Structural ONE method calls of image related information, and combining information. Same task is now done on many places.
* -- Shortpixel Class should be able to blindly call model for information, correct metadata and such.
*/

abstract class ImageModel extends \ShortPixel\Model\File\FileModel
{
    // File Status Constants
    const FILE_STATUS_ERROR = -1;
    const FILE_STATUS_UNPROCESSED = 0;
    const FILE_STATUS_PENDING = 1;
    const FILE_STATUS_SUCCESS = 2;
    const FILE_STATUS_RESTORED = 3;
    const FILE_STATUS_TORESTORE = 4; // Used for Bulk Restore

    // Compression Option Consts
    const COMPRESSION_LOSSLESS = 0;
    const COMPRESSION_LOSSY = 1;
    const COMPRESSION_GLOSSY = 2;

    // Extension that we process
    const PROCESSABLE_EXTENSIONS = array('jpg', 'jpeg', 'gif', 'png', 'pdf');

    //
    const P_PROCESSABLE = 0;
    const P_FILE_NOT_EXIST  = 1;
    const P_EXCLUDE_EXTENSION = 2;
    const P_EXCLUDE_SIZE  = 3;
    const P_EXCLUDE_PATH  = 4;
    const P_IS_OPTIMIZED = 5;

    protected $image_meta; // metadata Object of the image.

    protected $width;
    protected $height;
    protected $mime;
    protected $url;
    protected $error_message;

    protected $id;

    protected $processable_status = 0;

    //protected $is_optimized = false;
  //  protected $is_image = false;

    abstract public function getOptimizePaths();
    abstract public function getOptimizeUrls();

    abstract protected function saveMeta();
    abstract protected function loadMeta();
    abstract protected function isSizeExcluded();

    abstract protected function getImprovements();

    //abstract public function handleOptimized($tempFiles);

    // Construct
    public function __construct($path)
    {
      parent::__construct($path);

      if (! $this->isExtensionExcluded() && $this->isImage())
      {
         list($width, $height) = @getimagesize($this->getFullPath());
         if ($width)
         {
          $this->width = $width;
         }
         if ($height)
         {
          $this->height = $height;
         }
      }
    }

    /* Check if an image in theory could be processed. Check only exclusions, don't check status etc */
    public function isProcessable()
    {
        if ( $this->isOptimized() || ! $this->exists()  || $this->isPathExcluded() || $this->isExtensionExcluded() || $this->isSizeExcluded()
        )
          return false;
        else
          return true;
    }

    public function exists()
    {
       $result = parent::exists();
       if ($result === false)
       {
          $this->processable_status = self::P_FILE_NOT_EXIST;
       }
       return $result;
    }

    public function getProcessableReason()
    {
      $message = false;
      switch($this->processable_status)
      {
         case self::P_PROCESSABLE:
            $message = __('Image Processable', 'shortpixel-image-optimiser');
         break;
         case self::P_FILE_NOT_EXIST:
            $message = __('File does not exist', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_EXTENSION:
            $message = __('Image Extension Excluded', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_SIZE:
            $message = __('Image Size Excluded', 'shortpixel-image-optimiser');
         break;
         case self::P_EXCLUDE_PATH:
            $message = __('Image Path Excluded', 'shortpixel-image-optimiser');
         break;
         case self::P_IS_OPTIMIZED:
            $message = __('Image is already optimized', 'shortpixel-image-optimiser');
         break;
         default:
            $message = __(sprintf('Unknown Issue, Code %s',  $this->processable_status), 'shortpixel-image-optimiser');
         break;
      }

      return $message;
    }

    public function isImage()
    {
        if (! $this->exists())
          return false;

        $this->mime = mime_content_type($this->getFullPath());
        if (strpos($this->mime, 'image') >= 0)
           return true;
        else
          return false;
    }

    public function get($name)
    {
       if ( isset($this->$name))
        return $this->$name;

       return null;
    }

    public function getMeta($name = false)
    {
      if (! property_exists($this->image_meta, $name))
      {
          Log::addWarn('GetMeta on Undefined Property : ' . $name);
          return null;
      }

      return $this->image_meta->$name;
    }

    public function setMeta($name, $value)
    {
      if (! property_exists($this->image_meta, $name))
      {
          Log::addDebug('Writing meta non existing' . $name);
          return false;
      }
      else
        $this->image_meta->$name = $value;
    }

    public function isOptimized()
    {
      if ($this->getMeta('status') == self::FILE_STATUS_SUCCESS)
      {
          $this->processable_status = self::P_IS_OPTIMIZED;
          return true;
      }

      return false;
    }

    public function getImprovement($int = false)
    {
        if ($this->isOptimized())
        {
            $original = $this->getMeta('originalSize');
            $optimized = $this->getMeta('compressedSize');

            //$diff = $original - $optimized;
            if ($original == 0 || $optimized == 0)
              return null;

            if (! $int)
              return number_format(100.0 * (1.0 - $optimized / $original), 2);
            else
              return $original - $optimized;

        }
        else
          return null;
    }


    /** Handles an Optimized Image in a general way
    *
    * - This function doesn't handle any specifics like custom / thumbnails or anything else, just for a general image
    * - This function doesn't save metadata, that's job of subclass
    *
    * @param Array TemporaryFiles . Files from API optimizer with KEY of filename and FileModel Temporary File
    */
    public function handleOptimized($downloadResults)
    {
        $settings = \wpSPIO()->settings();

        foreach($downloadResults as $urlName => $resultObj)
        {
            if ($urlName != $this->getFileName())
              continue;
            // Check for same filename.
          //  echo " $urlName " . $this->getFileName();
            Log::addTemp(" $urlName searching " . $this->getFileName() );

              if ($settings->backupImages)
              {
                  $backupok = $this->createBackup();
                  if (! $backupok)
                  {
                    ResponseController::add()->withMessage(sprintf(__('Could not create backup for %s, optimization failed. Please check file permissions - %s', 'shortpixel-image-optimiser'), $this->getFileName(), $this->getFullPath() ))->asImportant()->asError();
                    return false;
                  }
              }

              $originalSize = $this->getFileSize();

              if ($resultObj->status == API::STATUS_UNCHANGED)
              {
                $copyok = true;
                $optimizedSize = $this->getFileSize();
                $tempFile = null;
              }
              else
              {
                $tempFile = $resultObj->file;
                $copyok = $tempFile->copy($this);
                $optimizedSize  = $tempFile->getFileSize();
              }

              if ($copyok)
              {
            //
                 $webpFile = $this->getFileBase() . '.webp';
                 Log::addTemp('Checking Webp as ' . $webpFile);
                 if (isset($downloadResults[$webpFile]) && isset($downloadResults[$webpFile]->file)) // check if there is webp with same filename
                 {
                    $webpResult = $this->handleWebp($downloadResults[$webpFile]->file);
                     if ($webResult === false)
                       Log::addWarn('Webps available, but copy failed ' . $downloadResults[$webpFile]->file->getFullPath());
                     else
                       $this->setMeta('webp', $webpResult->getFileName());
                 }

                 $this->setMeta('status', self::FILE_STATUS_SUCCESS);
                 $this->setMeta('tsOptimized', time());
                 $this->setMeta('compressedSize', $optimizedSize);
                 $this->setMeta('originalSize', $originalSize);
              //   $this->setMeta('improvement', $originalSize - $optimizedSize);
                 $this->setMeta('did_keepExif', $settings->keepExif);
                 $this->setMeta('did_cmyk2rgb', $settings->CMYKtoRGBconversion);

                 // Not set before in this case.
                 if (is_null($this->getMeta('compressionType')) || $this->getMeta('compressionType') === false)
                 {
                    $this->setMeta('compressionType', $settings->compressionType);
                 }

                 if ($settings->resizeImages)
                 {

                   $resizeWidth = $settings->resizeWidth;
                   $resizeHeight = $settings->resizeHeight;

                   if ($resizeWidth == $this->width || $resizeHeight == $this->height)  // resized.
                   {
                       $this->setMeta('resizeWidth', $width);
                       $this->setMeta('resizeHeight', $height);
                       $this->setMeta('resize', false);
                   }
                   else
                     $this->setMeta('resize', false);
                 }


                 if ( $tempFile)
                  $tempFile->delete();

              }
              else
              {
                Log::addError('Copy failed for  ' . $this->getFullPath() );
                ResponseController::add()->withMessage( sprintf(__('Could not copy optimized image %s from temporary files. Please check file permissions  %s' , 'shortpixel-image-optimiser'), $this->getFileName(), $this->getFullPath()))->asImportant()->asError();
                return false;
              }
              return true;
              break;

        }

        Log::addWarn('Could not find images of this item in tempfile -' . $this->id . '(' . $this->getFullPath() . ')', array_keys($downloadResults) );
        return null;
    }

    public function isRestorable()
    {
        if ($this->hasBackup() && $this->is_writable() && $this->isOptimized() )
        {
          return true;
        }
        else
        {
          if (! $this->is_writable())
          {
              ResponseController::add()->withMessage(__('This file cannot be restored due to file permissions - not writable : ' . $this->getFullPath(), 'shortpixel-image-optimiser'))->asError();
              Log::addTemp('Restore - Not Writable ' . $this->getFullPath() );
          }
          if (! $this->hasBackup())
            Log::addTemp('Backup not found for file: ', $this);
          if (! $this->isOptimized())
            Log::addTemp('Images seems not optimized', $this);
           return false;
        }
    }

    /** Restores a backup to original file *
    *
    * **NOTE** This function only moves the file but doesn't save the meta, which should reflect the changes!
    */
    public function restore()
    {
        if (! $this->isRestorable())
        {
            return false; // no backup / everything not writable.
        }

        $backupFile = $this->getBackupFile();

        if (! $backupFile)
        {
          Log::addWarn('Issue with restoring BackupFile, probably missing - ', $backupFile);
          return false; //error
        }

        if (! $backupFile->is_readable())
        {
            ResponseController::add()->withMessage(__('BackupFile not readable. Check file and/or file permissions', 'shortpixel-image-optimiser'));
           return false; //error
         }
        $bool = $backupFile->move($this);

        if ($bool)
          $this->image_meta = new ImageMeta(); // wipe metadata for this image.
        else
        {
          ResponseController::add()->withMessage(__('Moving Backupfile failed' , 'shortpixel-image-optimiser'));
          Log::addTemp('moving file failed'); // @todo Proper error here.
        }
        return $bool;
    }

    /** When an image is deleted
    *
    *  Handle an image delete i.e. by WordPress or elsehow.
    */
    public function onDelete()
    {
       if ($this->hasBackup())
        $this->restore();

//       $this->deleteMeta();

    }

    protected function handleWebp(FileModel $tempFile)
    {
         $fs = \wpSPIO()->filesystem();

      //   $webp = $fs->getFile( (string) $tempFile->getFileDir() . $tempFile->getFileBase() . '.webp');

            $target = $fs->getFile( (string) $this->getFileDir() . $this->getFileBase() . '.webp');
        Log::addTemp('handle Webp for ' . $this->getFullPath() . ' Target ' . $target->getFullPath() );
            if( (defined('SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION') && SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION) || $target->exists()) {
                 $target = $fs->getFile((string) $this->getFileDir() . $this->getFileName() . '.webp'); // double extension, if exists.
            }
            $result = $tempFile->copy($target);
            if (! $result)
              Log::addWarn('Could not copy Webp to destination ' . $target->getFullPath() );
            return $target;
      //   }

         return false;
    }


    protected function isPathExcluded()
    {
        $excludePatterns = \wpSPIO()->settings()->excludePatterns;

        if(!$excludePatterns || !is_array($excludePatterns)) { return false; }

        foreach($excludePatterns as $item) {
            $type = trim($item["type"]);
            if(in_array($type, array("name", "path"))) {
                $pattern = trim($item["value"]);
                $target = ($type == "name") ? $this->getFileName() : $this->getFullPath();
                if( self::matchExcludePattern($target, $pattern) ) { //search as a substring if not
                    $this->processable_status = self::P_EXCLUDE_PATH;
                    return true;
                }
            }
        }
        return false;
    }

    protected function isExtensionExcluded()
    {
        if (in_array($this->getExtension(), self::PROCESSABLE_EXTENSIONS))
        {
            return false;
        }

        $this->processable_status = self::P_EXCLUDE_EXTENSION;
        return true;
    }

    protected function matchExcludePattern($target, $pattern) {
        if(strlen($pattern) == 0)  // can happen on faulty input in settings.
          return false;

        $first = substr($pattern, 0,1);

        if ($first == '/')
        {
          if (@preg_match($pattern, false) !== false)
          {
            $m = preg_match($pattern,  $target);
            if ($m !== false && $m > 0) // valid regex, more hits than zero
            {
              return true;
            }
          }
        }
        else
        {
          if (strpos($target, $pattern) !== false)
          {
            return true;
          }
        }
        return false;
    }

    /** Convert Image Meta to A Class */
    protected function toClass()
    {
        return $this->image_meta->toClass();
    }


    protected function createBackup()
    {
        // Safety: It should absolutely not be possible to overwrite a backup file.
       if ($this->hasBackup())
       {
          $backupFile = $this->getBackupFile();
          Log::addTemp('BackupFile Size ' . $backupFile->getFileSize() . ' This Filesize' . $this->getFileSize());
          if ($backupFile->getFileSize() == $this->getFileSize())
          {   return true; }
          else
          { return false; }
       }
       $directory = $this->getBackupDirectory(true);
       $fs = \wpSPIO()->filesystem();

       if(apply_filters('shortpixel_skip_backup', false, $this->getFullPath())){
           return true;
       }

       if (! $directory)
       {
          Log::addWarn('Could not create Backup Directory for ' . $this->getFullPath());
          return false;
       }

       $backupFile = $fs->getFile($directory . $this->getFileName());

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

    private function addUnlistedThumbs()
    {
      // @todo weak call. See how in future settings might come via central provider.
      $settings = new \WPShortPixelSettings();

      // must be media library, setting must be on.
      if($this->facade->getType() != \ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE
         || ! $settings->optimizeUnlisted) {
        return 0;
      }

      $this->facade->removeSPFoundMeta(); // remove all found meta. If will be re-added here every time.
      $meta = $this->meta; //$itemHandler->getMeta();

      Log::addDebug('Finding Thumbs on path' . $meta->getPath());
      $thumbs = \WpShortPixelMediaLbraryAdapter::findThumbs($meta->getPath());

      $fs = \wpSPIO()->filesystem();
      $mainFile = $this->file;

      // Find Thumbs returns *full file path*
      $foundThumbs = \WpShortPixelMediaLbraryAdapter::findThumbs($mainFile->getFullPath());

        // no thumbs, then done.
      if (count($foundThumbs) == 0)
      {
        return 0;
      }
      //first identify which thumbs are not in the sizes
      $sizes = $meta->getThumbs();
      $mimeType = false;

      $allSizes = array();
      $basepath = $mainFile->getFileDir()->getPath();

      foreach($sizes as $size) {
        // Thumbs should have filename only. This is shortpixel-meta ! Not metadata!
        // Provided filename can be unexpected (URL, fullpath), so first do check, get filename, then check the full path
        $sizeFileCheck = $fs->getFile($size['file']);
        $sizeFilePath = $basepath . $sizeFileCheck->getFileName();
        $sizeFile = $fs->getFile($sizeFilePath);

        //get the mime-type from one of the thumbs metas
        if(isset($size['mime-type'])) { //situation from support case #9351 Ramesh Mehay
            $mimeType = $size['mime-type'];
        }
        $allSizes[] = $sizeFile;
      }

      foreach($foundThumbs as $id => $found) {
          $foundFile = $fs->getFile($found);

          foreach($allSizes as $sizeFile) {
              if ($sizeFile->getExtension() !== $foundFile->getExtension())
              {
                $foundThumbs[$id] = false;
              }
              elseif ($sizeFile->getFileName() === $foundFile->getFileName())
              {
                  $foundThumbs[$id] = false;
              }
          }
      }
          // add the unfound ones to the sizes array
          $ind = 1;
          $counter = 0;
          // Assumption:: there is no point in adding to this array since findThumbs should find *all* thumbs that are relevant to this image.
          /*while (isset($sizes[ShortPixelMeta::FOUND_THUMB_PREFIX . str_pad("".$start, 2, '0', STR_PAD_LEFT)]))
          {
            $start++;
          } */
      //    $start = $ind;

          foreach($foundThumbs as $found) {
              if($found !== false) {
                  Log::addDebug('Adding File to sizes -> ' . $found);
                  $size = getimagesize($found);
                  Log::addDebug('Add Unlisted, add size' . $found );

                  $sizes[\ShortPixelMeta::FOUND_THUMB_PREFIX . str_pad("".$ind, 2, '0', STR_PAD_LEFT)]= array( // it's a file that has no corresponding thumb so it's the WEBP for the main file
                      'file' => \ShortPixelAPI::MB_basename($found),
                      'width' => $size[0],
                      'height' => $size[1],
                      'mime-type' => $mimeType
                  );
                  $ind++;
                  $counter++;
              }
          }
          if($ind > 1) { // at least one thumbnail added, update
              $meta->setThumbs($sizes);
              $this->facade->updateMeta($meta);
          }

        return $counter;
    }

} // model
