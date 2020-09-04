<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class MediaLibraryModel extends \ShortPixel\Model\Image\MediaLibraryThumbnailModel
{
  protected $thumbnails = array(); // thumbnails of this // MediaLibraryThumbnailModel .
  protected $retinas = array(); // retina files - MediaLibraryThumbnailModel (or retina / webp and move to thumbnail? )
  protected $webps = array(); // webp files - MediaLibraryThumbnailModel
  protected $original_file = false; // the original instead of the possibly _scaled one created by WP 5.3 >

  protected $is_scaled = false; // if this is WP 5.3 scaled

  protected $wp_metadata;
  protected $id;

  public function __construct($post_id, $path)
  {
      $this->id = $post_id;

      parent::__construct($path);

      // WP 5.3 and higher. Check for original file.
      if (function_exists('wp_get_original_image_path'))
      {
        $this->setOriginalFile();
      }

      if (! $this->isExtensionExcluded())
        $this->loadMeta();

  }

  public function getOptimizePaths()
  {

     $paths = array();

     if ($this->isProcessable())
        $paths = array($this->getFullPath());

     foreach($this->thumbnails as $thumbObj)
     {
       if ($thumbObj->isProcessable() )
        $paths = array_merge($paths, $thumbObj->getOptimizePaths());
     }

     return $paths;
  }


  public function getOptimizeUrls()
  {
     $fs = \wpSPIO()->filesystem();
     $url = $fs->pathToUrl($this);

     if (! $url)
     {
      return false;
     }

     $urls = array();
     if ($this->isProcessable())
      $urls = array($url);

     if ($this->isScaled())
     {
        $urls = array_merge($urls, $this->original_file->getOptimizeUrls());
     }

     foreach($this->thumbnails as $thumbObj)
     {
        $urls = array_merge($urls, $thumbObj->getOptimizeUrls());
     }
     // @todo Check Unlisted

     // @todo Check Retina's
     return $urls;
  }

  public function getWPMetaData()
  {
      if (is_null($this->wp_metadata))
        $this->wp_metadata = wp_get_attachment_metadata($this->id);

      return $this->wp_metadata;
  }

  // Not sure if it will work like this.
  public function isScaled()
  {
     return $this->is_scaled;
  }


  protected function loadThumbnailsFromWP()
  {
    $wpmeta = $this->getWPMetaData();
//echo "<PRE>"; print_r($wpmeta); echo "</PRE>";
    $thumbnails = array();
    if (isset($wpmeta['sizes']))
    {
          foreach($wpmeta['sizes'] as $name => $data)
          {

             if (isset($data['file']))
             {
               $thumbObj = $this->getThumbnailModel($data['file']);
               $meta = new ImageThumbnailMeta();
               $thumbObj->name = $name;
               $meta->width = (isset($data['width'])) ? $data['width'] : false;
               $meta->height = (isset($data['height'])) ? $data['height'] : false;
               $thumbObj->setMetaObj($meta);
               $thumbnails[$name] = $thumbObj;
             }
          }
    }

    return $thumbnails;
  }

  protected function getRetinas()
  {
      $retinas = array();
      $main = $this->getRetina();

      if ($main)
        $retinas[0] = $main; // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      foreach ($this->thumbnails as $thumbname => $thumbObj)
      {
        $retinaObj = $thumbObj->getRetina();
        if ($retinaObj)
           $retinas[$thumbname] = $retinaObj;
      }

      return $retinas;
  }


  protected function getWebps()
  {
      $webps = array();

      $main = $this->getWebp();
      if ($main)
        $webps[0] = $main;  // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      foreach($this->thumbnails as $thumbname => $thumbObj)
      {
         $webp = $thumbObj->getWebp();
         if ($webp)
          $webps[$thumbname] = $webp;
      }

      return $webps;
  }

  /* Sanity check in process. Should only be called upon special request, or with single image displays. Should check and recheck stats, thumbs, unlistedthumbs and all assumptions of data that might corrupt or change outside of this plugin */
  public function reAcquire()
  {
    //  $this->addUnlistedThumbs();
      //$this->reCheckThumbnails();
      if (\wpSPIO()->settings()->optimizeRetina)
        $this->retinas = $this->getRetinas();

      if (\wpSPIO()->settings()->createWebp)
        $this->webps = $this->getWebps();


      // $this->recount();
  }

  public function handleOptimized($tempFiles)
  {
      $result = parent::handleOptimized($tempFiles);
      if (! $result)
      {
         $this->setMeta('errorMessage', __('Unable to Optimize this Image', 'shortpixel-image-optimizer'));
         $this->saveMeta();
         return false;
      }

      // If thumbnails should not be optimized, they should not be in result Array.
      foreach($this->thumbnails as $thumbnail)
      {
         $thumbnail->handleOptimized($tempFiles);
      }

  }

  private function getThumbnailModel($fileName)
  {
      $path = (string) $this->getFileDir();
      $path = $path . $fileName;

      $thumbObj = new MediaLibraryThumbnailModel($path);
      return $thumbObj;
  }

  protected function loadMeta()
  {
      $metadata = get_post_meta($this->id, '_shortpixel_meta', true);

      $this->image_meta = new ImageMeta();
      $fs = \wpSPIO()->fileSystem();

      if (! $metadata)
      {
            $this->thumbnails = $this->loadThumbnailsFromWP();

            $result = $this->checkLegacy();
            if ($result)
            {
              $metadata = $this->createSave(); // after convert, pretent it's loaded as save ( and save! ) @todo
            /*  echo "metadata from createSave <PRE>";
              print_r($this->getOptimizeUrls());
              echo "</PRE>"; */
            }
      }


      if (is_object($metadata) )
      {
          $this->image_meta->fromClass($metadata->image_meta);
        //  echo "<PRE>IMAGE META"; print_r($this->image_meta); echo "</PRE>";
          $thumbnails = $this->loadThumbnailsFromWP();
          foreach($thumbnails as $name => $thumbObj)
          {
             if (isset($metadata->thumbnails[$name]))
             {
                $thumbMeta = new ImageThumbnailMeta();
                $thumbMeta->fromClass($metadata->thumbnails[$name]);
                //$thumbMeta->set('name', $thumbName);
                $thumbnails[$name]->setMetaObj($thumbMeta);
             }
          }
          $this->thumbnails = $thumbnails;

          if (isset($metadata->retinas))
          {
              foreach($metadata->retinas as $retinaObj)
              {
                  $retMeta = new ImageThumbnailMeta();
                  $retMeta->fromClass($retinaObj);
                  $this->retinas[] = $retMeta;
              }
          }
          if (isset($metadata->webps))
          {
             foreach($metadata->webps as $webp)
             {
                $this->webps[] = $fs->getFile($webp);
             }
          }
      }

      return false;
  }

  private function createSave()
  {
      $metadata = new \stdClass; // $this->image_meta->toClass();
      $metadata->image_meta = $this->image_meta->toClass();
      $thumbnails = array();
      $retinas = array();
      $webps = array();

      foreach($this->thumbnails as $thumbName => $thumbObj)
      {
         $thumbnails[$thumbName] = $thumbObj->toClass();
      }
      foreach($this->retinas as $index => $retinaObj)
      {
         $retinas[$index] = $retinaObj->toClass();
      }
      foreach($this->webps as $index => $webp)
      {
        $webps[$index] = $webp->getFullPath();
      }

      if (count($thumbnails) > 0)
        $metadata->thumbnails = $thumbnails;
      if (count($retinas) > 0)
        $metadata->retinas = $retinas;
      if (count($webps) > 0)
        $metadata->webps = $webps;

      return $metadata;
 }

 public function saveMeta()
 {
     $metadata = $this->createSave();
      $result = update_post_meta($this->id, '_shortpixel_meta', $metadata);

      if ($result === false)
      {
        Log::addError('Saving Metadata of ' . $this->id . ' failed!', $metadata);
      }

  }

  public function getThumbNail($name)
  {
     if (isset($this->thumbnails[$name]))
        return $this->thumbnails[$name];

      return false;
  }


  protected function isSizeExcluded()
  {
    $excludePatterns = \wpSPIO()->settings()->excludePatterns;

    if (! $excludePatterns || ! is_array($excludePatterns) ) // no patterns, nothing excluded
      return false;

    foreach($excludePatterns as $item) {
        $type = trim($item["type"]);
        if($type == "size") {
            //$meta = $meta? $meta : wp_get_attachment_metadata($ID);
            if( $this->width && $this->height
                 && $this->isProcessableSize($this->width, $this->height, $item["value"]) === false){
                   $this->$processable_status = self::P_EXCLUDE_SIZE;
                  return true;
              }
            else
                return false;
          }
     }

  }

  private function isProcessableSize($width, $height, $excludePattern) {

      $ranges = preg_split("/(x|×|X)/",$excludePattern);

      $widthBounds = explode("-", $ranges[0]);
      $minWidth = intval($widthBounds[0]);
      $maxWidth = (!isset($widthBounds[1])) ? intval($widthBounds[0]) : intval($widthBounds[1]);

      $heightBounds = isset($ranges[1]) ? explode("-", $ranges[1]) : false;
      $minHeight = $maxHeight = 0;
      if ($heightBounds)
      {
        $minHeight = intval($heightBounds[0]);
        $maxHeight = (!isset($heightBounds[1])) ? intval($heightBounds[0]) : intval($heightBounds[1]);
      }

      if(   $width >= $minWidth && $width <= $maxWidth
         && ( $heightBounds === false
             || ($height >= $minHeight && $height <= $maxHeight) )) {
          return false;
      }
      return true;
  }

  // Perhaps treat this as  thumbnail? And remove function from FileSystemController?
  protected function setOriginalFile()
  {
    $fs = \wpSPIO()->filesystem();

    if (is_null($this->id))
      return false;

    $originalFile = $fs->getOriginalImage($this->id);

    if ($originalFile->exists() && $originalFile->getFullPath() !== $this->getfullPath() )
    {
      $this->original_file = $originalFile;
      $this->is_scaled = true;
    }

  }

  public function hasOriginal()
  {
     if ($this->original_file)
      return true;
    else
      return false;
  }

  public function getWPMLDuplicates()
  {
    global $wpdb;
    $fs = \wpSPIO()->filesystem();

    $parentId = get_post_meta ($this->id, '_icl_lang_duplicate_of', true );
    if($parentId) $id = $parentId;

  //  $mainFile = $fs->getAttachedFile($id);

    $duplicates = $wpdb->get_col( $wpdb->prepare( "
        SELECT pm.post_id FROM {$wpdb->postmeta} pm
        WHERE pm.meta_value = %s AND pm.meta_key = '_icl_lang_duplicate_of'
    ", $this->id ) );

    //Polylang
    $moreDuplicates = $wpdb->get_results( $wpdb->prepare( "
        SELECT p.ID, p.guid FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->posts} pbase ON p.guid = pbase.guid
     WHERE pbase.ID = %s and p.guid != ''
    ", $this->id ) );

    //MySQL is doing a CASE INSENSITIVE join on p.guid!! so double check the results.
    $guid = false;
    foreach($moreDuplicates as $duplicate) {
        if($duplicate->ID == $this->id) {
            $guid = $duplicate->guid;
        }
    }
    foreach($moreDuplicates as $duplicate) {
        if($duplicate->guid == $guid) {
            $duplicates[] = $duplicate->ID;
        }
    }

    $duplicates = array_unique($duplicates);

    if(!in_array($this->id, $duplicates)) $duplicates[] = $this->id;

    $transTable = $wpdb->get_results("SELECT COUNT(1) hasTransTable FROM information_schema.tables WHERE table_schema='{$wpdb->dbname}' AND table_name='{$wpdb->prefix}icl_translations'");
    if(isset($transTable[0]->hasTransTable) && $transTable[0]->hasTransTable > 0) {
        $transGroupId = $wpdb->get_results("SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = " . $this->id . "");
        if(count($transGroupId)) {
            $transGroup = $wpdb->get_results("SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid = " . $transGroupId[0]->trid);
            foreach($transGroup as $trans) {
                $transFile = $fs->getFile($trans->element_id);
                if($mainFile->getFullPath() == $transFile->getFullPath() ){
                    $duplicates[] = $trans->element_id;
                }
            }
        }
    }
    return array_unique($duplicates);
  }

  /** Removed the current attachment, with hopefully removing everything we set.
  * @todo Should probably not delete files ( SPIO never deletes files ) , other than our backups / webps during restore.
  */
  public function restore()
  {
    //$itemHandler = $this->facade;
    //$itemHandler = new ShortPixelMetaFacade($post_id);
    //$urlsPaths = $itemHandler->getURLsAndPATHs(true, false, true, array(), true, true);
    if ($this->isOptimized())
    {
        if ($backup = $this->hasBackup())
        {
           $backup->move($this);
           foreach($this->thumbnails as $thumbObj)
           {
               if($backup = $thumbObj->hasBackup())
               {
                  $backup->move($thumbObj);
               }
           }
        }
        // @todo move this to some better permanent structure w/ png2jpg class.
        if ($this->getMeta('did_png2Jpg'))
        {
          $png2jpg = $this->meta->getPng2Jpg();
          if (isset($png2jpg['originalFile']))
          {
            $urlsPaths['PATHs'][] = $png2jpg['originalFile'];
          }
          if (isset($png2jpg['originalSizes']))
          {
                foreach($png2jpg['originalSizes'] as $size => $data)
                {
                  if (isset($data['file']))
                  {
                    $filedir = (string) $this->file->getFileDir();
                    $urlsPaths['PATHs'][] = $filedir . $data['file'];
                  }
                }
          }
        }
        if(count($urlsPaths['PATHs'])) {
            Log::addDebug('Removing Backups and Webps', $urlsPaths);
            /* @todo */
          //  \wpSPIO()->getShortPixel()->maybeDumpFromProcessedOnServer($itemHandler, $urlsPaths);
          $webps = $this->getWebps();
          foreach($webps as $webpFile)
              $webpFile->delete();

          //  \wpSPIO()->getShortPixel()->deleteBackupsAndWebPs($urlsPaths['PATHs']);
        }

    } // isOptimized();

    delete_post_meta($this->id, '_shortpixel_meta', true);
  //  wp_delete_attachment($this->id);
    return parent::delete();

  //  $itemHandler->deleteItemCache();
  //  return $itemHandler; //return it because we call it also on replace and on replace we need to follow this by deleting SP metadata, on delete it
  }

  // Convert from old metadata if needed.
  private function checkLegacy()
  {
      $metadata = $this->wp_metadata;

      if (! isset($metadata['ShortPixel']))
      {
        return false;
      }

      $data = $metadata['ShortPixel'];
    //  echo " I MUST CONVERT THIS <PRE>";  print_r($metadata); echo "</PRE>";

       $type = isset($data['type']) ? $this->legacyConvertType($data['type']) : '';

       $improvement = (isset($metadata['ShortPixelImprovement']) && is_numeric($metadata['ShortPixelImprovement']) && $metadata['ShortPixelImprovement'] > 0) ? $metadata['ShortPixelImprovement'] : 0;

       $status = $this->legacyConvertStatus($data, $metadata);

       $error_message = isset($metadata['ShortPixelImprovement']) && ! is_numeric($metadata['ShortPixelImprovement']) ? $metadata['ShortPixelImprovement'] : '';

       $retries = isset($data['Retries']) ? intval($data['Retries']) : 0;

       $optimized_thumbnails = (isset($data['thumbsOptList']) && is_array($data['thumbsOptList'])) ? $data['thumbsOptList'] : array();

       $exifkept = (isset($data['exifKept']) && $data['exifKept']  == 1) ? true : false;

       $tsOptimized = $tsAdded = time();
       if ($status == self::FILE_STATUS_SUCCESS)
       {
         //strtotime($tsOptimized)
         $newdate = \DateTime::createFromFormat('Y-m-d H:i:s', $data['date']);
         $newdate = $newdate->getTimestamp();

        $tsOptimized = $newdate;
        $this->image_meta->tsOptimized = $tsOptimized;
       }

       $this->image_meta->status = $status;
       //$this->image_meta->type = $type;
       $this->image_meta->improvement = $improvement;
       $this->image_meta->compressionType = $type;
       $this->image_meta->compressedSize = $this->getFileSize();
       $this->image_meta->retries = $retries;
       $this->image_meta->tsAdded = $tsAdded;
       $this->image_meta->has_backup = $this->hasBackup();
       $this->image_meta->errorMessage = $error_message;

       $this->image_meta->did_keepExif = $exifkept;

       $this->width = isset($metadata['width']) ? $metadata['width'] : false;
       $this->height = isset($metadata['height']) ? $metadata['height'] : false;


    //   $this->image_meta->did_cmyk2rgb = $exifkept;
      // $this->image_meta->tsOptimized =

       foreach($this->thumbnails as $thumbname => $thumbnailObj) // ThumbnailModel
       {
          if (in_array($thumbnailObj->getFileName(), $optimized_thumbnails))
          {
              $thumbnailObj->image_meta->status = $status;
              $thumbnailObj->image_meta->compressionType = $type;
              $thumbnailObj->image_meta->compressedSize = $thumbnailObj->getFileSize();
              $thumbnailObj->image_meta->improvement = -1; // n/a
              $thumbnailObj->image_meta->tsAdded = $tsAdded;
              $thumbnailObj->image_meta->tsOptimized = $tsOptimized;
              $thumbnailObj->has_backup = $thumbnailObj->hasBackup();

              $this->thumbnails[$thumbname] = $thumbnailObj;
          }
       }

       if (isset($data['retinasOpt']))
       {
           $count = $data['retinasOpt'];
           $retinas = $this->getRetinas();
           foreach($retinas as $index => $retinaObj) // Thumbnail Model
           {
              $retinaObj->image_meta->status = $status;
              $retinaObj->image_meta->compressionType = $type;
              $retinaObj->image_meta->compressedSize = $retinaObj->getFileSize();
              $retinaObj->image_meta->improvement = -1; // n/a
              $retinaObj->image_meta->tsAdded = $tsAdded;
              $retinaObj->image_meta->tsOptimized = $tsOptimized;
              $retinaObj->has_backup = $retinaObj->hasBackup();

              $retinas[$index] = $retinaObj;
           }
           $this->retinas = $retinas;
       }

      if (isset($data['webpCount']))
      {
          $count = $data['webpCount'];
          $webps = $this->getWebps(); // Simple FileModel objects.
          $this->webps = $webps;
      }

//echo "<PRE>"; var_dump($this->createSave());echo "</PRE>";

      //   $is_png2jpg = isset($data[''])

        // $meta = new ImageMeta();
      //   $meta->
         /*"thumbs" => (isset($rawMeta["sizes"]) ? $rawMeta["sizes"] : array()),
         "message" =>(isset($rawMeta["ShortPixelImprovement"]) ? $rawMeta["ShortPixelImprovement"] : null),
         "png2jpg" => (isset($rawMeta["ShortPixelPng2Jpg"]) ? $rawMeta["ShortPixelPng2Jpg"] : false),
         "compressionType" =>(isset($rawMeta["ShortPixel"]["type"])
                 ? ($rawMeta["ShortPixel"]["type"] == 'glossy' ? 2 : ($rawMeta["ShortPixel"]["type"] == "lossy" ? 1 : 0) )
                 : null),
         "thumbsOpt" =>(isset($rawMeta["ShortPixel"]["thumbsOpt"]) ? $rawMeta["ShortPixel"]["thumbsOpt"] : null),
         "thumbsOptList" =>(isset($rawMeta["ShortPixel"]["thumbsOptList"]) ? $rawMeta["ShortPixel"]["thumbsOptList"] : array()),
         'excludeSizes' =>(isset($rawMeta["ShortPixel"]["excludeSizes"]) ? $rawMeta["ShortPixel"]["excludeSizes"] : null),
         "thumbsMissing" =>(isset($rawMeta["ShortPixel"]["thumbsMissing"]) ? $rawMeta["ShortPixel"]["thumbsMissing"] : null),
         //[retinasOpt] => 5
         "retinasOpt" =>(isset($rawMeta["ShortPixel"]["retinasOpt"]) ? $rawMeta["ShortPixel"]["retinasOpt"] : null),
         "thumbsTodo" =>(isset($rawMeta["ShortPixel"]["thumbsTodo"]) ? $rawMeta["ShortPixel"]["thumbsTodo"] : false),
         "tsOptimized" => (isset($rawMeta["ShortPixel"]["date"]) ? $rawMeta["ShortPixel"]["date"] : false),
         "backup" => !isset($rawMeta['ShortPixel']['NoBackup']),
         "status" => (!isset($rawMeta["ShortPixel"]) ? 0
                      : (isset($rawMeta["ShortPixelImprovement"]) && is_numeric($rawMeta["ShortPixelImprovement"])
                        && !(   $rawMeta['ShortPixelImprovement'] == 0
                             && (   isset($rawMeta['ShortPixel']['WaitingProcessing'])
                                 || isset($rawMeta['ShortPixel']['date']) && $rawMeta['ShortPixel']['date'] == '1970-01-01')) ? 2
                         : (isset($rawMeta["ShortPixel"]["WaitingProcessing"]) ? 1
                            : (isset($rawMeta["ShortPixel"]['ErrCode']) ? $rawMeta["ShortPixel"]['ErrCode'] : -500)))),
         "retries" =>(isset($rawMeta["ShortPixel"]["Retries"]) ? $rawMeta["ShortPixel"]["Retries"] : 0),
  */

  /* NEEDS TO BE SET:
  public $status = 0;
  public $compressionType;
  public $compressedSize;
  public $improvement;

  public $tsAdded;
  public $tsOptimized;

  public $has_backup;

  public $did_keepExif = false;
  public $did_cmyk2rgb = false;
  public $did_png2Jpg = false;
  public $is_optimized = false; // if this is optimized
  public $is_png2jpg = false; // todo implement.

  public $resize;
  public $resizeWidth;
  public $resizeHeight;
  public $actualWidth;
  public $actualHeight;

  */
      return true;
  }

  private function legacyConvertType($string_type)
  {
    switch($string_type)
    {
        case 'lossy':
          $type = self::COMPRESSION_LOSSY;
        break;
        case 'lossless':
           $type = self::COMPRESSION_LOSSLESS;
        break;
        case 'glossy':
           $type = self::COMPRESSION_GLOSSY;
        break;
        default:
            $type = -1; //unknown state.
        break;
    }
    return $type;
  }

  /** Old Status can be anything*/
  private function legacyConvertStatus($data, $metadata)
  {
  /*  const FILE_STATUS_UNPROCESSED = 0;
    const FILE_STATUS_PENDING = 1;
    const FILE_STATUS_SUCCESS = 2;
    const FILE_STATUS_RESTORED = 3;
    const FILE_STATUS_TORESTORE = 4; // Used for Bulk Restore */

    // Most Likely Status not saved in metadata, but must be generated from type / lossy and ShortpixelImprovement Metadata.
//    echo "<PRE> LEGACY CONVERT STATUS"; var_dump($data); echo "</PRE>";

  /*  "status" => (!isset($rawMeta["ShortPixel"]) ? 0
                 : (isset($rawMeta["ShortPixelImprovement"]) && is_numeric($rawMeta["ShortPixelImprovement"])
                   && !(   $rawMeta['ShortPixelImprovement'] == 0
                        && (   isset($rawMeta['ShortPixel']['WaitingProcessing'])
                            || isset($rawMeta['ShortPixel']['date']) && $rawMeta['ShortPixel']['date'] == '1970-01-01')) ? 2
                    : (isset($rawMeta["ShortPixel"]["WaitingProcessing"]) ? 1
                       : (isset($rawMeta["ShortPixel"]['ErrCode']) ? $rawMeta["ShortPixel"]['ErrCode'] : -500)))),
*/
    $waiting = isset($data['WaitingProcessing']) ? true : false;
    $error = isset($data['ErrCode']) ? $data['ErrCode'] : -500;

    if (isset($metadata['ShortPixelImprovement']) &&
        is_numeric($metadata["ShortPixelImprovement"]) &&
        is_numeric($metadata["ShortPixelImprovement"]) > 0)
    {
      $status = self::FILE_STATUS_SUCCESS;
    }
    elseif($waiting)
    {
       $status = self::FILE_STATUS_PENDING;
    }
    elseif ($error < 0)
    {
      $status = $error;
    }

    return $status;
  }

  public function __debugInfo() {
      return array(
        'image_meta' => $this->image_meta,
        'thumbnails' => $this->thumbnails,
        'retinas' => $this->retinas,
        'webps' => $this->webps,
        'original_file' => $this->original_file,
        'is_scaled' => $this->is_scaled,
      );

  }

} // class
