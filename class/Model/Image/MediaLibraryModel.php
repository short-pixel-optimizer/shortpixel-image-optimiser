<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class MediaLibraryModel extends \ShortPixel\Model\Image\MediaLibraryThumbnailModel
{
  protected $thumbnails = array(); // thumbnails of this // MediaLibraryThumbnailModel .
  protected $retinas = array(); // retina files - MediaLibraryThumbnailModel (or retina / webp and move to thumbnail? )
  protected $webps = array(); // webp files - MediaLibraryThumbnailModel
  protected $original_file = false; // the original instead of the possibly _scaled one created by WP 5.3 >

  protected $id; // attachment id

  protected $is_scaled = false; // if this is WP 5.3 scaled

  protected $wp_metadata;

  public function __construct($post_id, $path)
  {
      $this->id = $post_id;

      parent::__construct($path);

      //$this->file = $fs->getFile($this->meta->getPath() ); //$fs->getAttachedFile($post_id);

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
     if (! $this->isProcessable())
       return;

     $paths = array();

     if (! $this->image_meta->status == self::FILE_STATUS_SUCCESS)
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
     $url = $this->safeGetURL();
     if (! $url)
     {
      return false;
     }
     $urls = array($url);
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
  public function is_scaled()
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
               $thumbObj->width = (isset($data['width'])) ? $data['width'] : false;
               $thumbObj->height = (isset($data['height'])) ? $data['height'] : false;
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
              echo "metadata from createSave <PRE>";
              //print_r($metadata);
              echo "</PRE>";
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
        Log::addError('Saving Metadata of ' . $this->id . ' failed!');
      }

  }

  protected function getThumbNail($name)
  {
     if (isset($this->thumbnails[$name]))
        return $this->thumbnails[$name];

      return false;
  }


  private function safeGetUrl() {
      $attURL = wp_get_attachment_url($this->id);
      if(!$attURL || !strlen($attURL)) {
        //  throw new Exception("Post metadata is corrupt (No attachment URL for $id)", ShortPixelAPI::ERR_POSTMETA_CORRUPT);
        Log::addError('Post metadata is corrupt (No attachment URL for ' . $this->id .')');
        return false;
      }

      $parsed = parse_url($attURL);
      if ( !isset($parsed['scheme']) ) {//no absolute URLs used -> we implement a hack

         if (isset($parsed['host'])) // This is for URL's for // without http or https. hackhack.
         {
           $scheme = is_ssl() ? 'https:' : 'http:';
           return $scheme. $attURL;

         }
         return self::getHomeUrl() . ltrim($attURL,'/');//get the file URL
      }
      else {
          return $attURL;//get the file URL
      }
  }

  protected function isSizeExcluded()
  {
    $excludePatterns = \wpSPIO()->settings()->excludePatterns;
    foreach($excludePatterns as $item) {
        $type = trim($item["type"]);
        if($type == "size") {
            $meta = $meta? $meta : wp_get_attachment_metadata($ID);
            if(   isset($meta["width"]) && isset($meta["height"])
                 && $this->isProcessableSize($meta["width"], $meta["height"], $excludePattern["value"]) === false){
                  return false;
              }
            else
                return true;
          }
     }

  }

  private function isProcessableSize($width, $height, $excludePattern) {
      $ranges = preg_split("/(x|×)/",$excludePattern);
      $widthBounds = explode("-", $ranges[0]);
      if(!isset($widthBounds[1])) $widthBounds[1] = $widthBounds[0];
      $heightBounds = isset($ranges[1]) ? explode("-", $ranges[1]) : false;
      if(!isset($heightBounds[1])) $heightBounds[1] = $heightBounds[0];
      if(   $width >= 0 + $widthBounds[0] && $width <= 0 + $widthBounds[1]
         && (   $heightBounds === false
             || ($height >= 0 + $heightBounds[0] && $height <= 0 + $heightBounds[1]))) {
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

    $originalFile = $fs->getOriginalPath($this->id);

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
