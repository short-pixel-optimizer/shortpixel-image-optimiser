<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class MediaLibraryModel extends \ShortPixel\Model\Image\MediaLibraryThumbnailModel
{
  protected $thumbsnails = array(); // thumbnails of this // MediaLibraryThumbnailModel .
  protected $retinas = array(); // retina files - MediaLibraryThumbnailModel (or retina / webp and move to thumbnail? )
  protected $webps = array(); // webp files - MediaLibraryThumbnailModel
  protected $original_file; // the original instead of the possibly _scaled one created by WP 5.3 >

  protected $post_id; // attachment id

  protected $is_scaled = false; // if this is WP 5.3 scaled

  protected $wp_metadata;

  public function __construct($post_id, $path)
  {
      $this->post_id = $post_id;

      parent::__construct($path);

      //$this->file = $fs->getFile($this->meta->getPath() ); //$fs->getAttachedFile($post_id);

      // WP 5.3 and higher. Check for original file.
      if (function_exists('wp_get_original_image_path'))
      {
        $this->setOriginalFile();
      }

      $this->loadMeta();

  }

  public function getOptimizePaths()
  {
     if (! $this->isProcessable())
       return;

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
     return $urls;
  }

  public function getWPMetaData()
  {
      if (is_null($this->wp_metadata))
        $this->wp_metadata = wp_get_attachment_metadata($this->post_id);

      return $this->wp_metadata;
  }

  protected function loadThumbnails()
  {
    $wpmeta = $this->getWPMetaData();
echo "<PRE>"; print_r($wpmeta); echo "</PRE>";
    return array();

  }

  protected function loadMeta()
  {
      $meta = get_post_meta($this->post_id, 'shortpixel_meta', true);

      if (! $meta)
      {
          $meta = $this->checkLegacy();
          if (! $meta)
          {
            $meta = new \stdClass;
            $meta->image_meta = new ImageMeta();
            $meta->thumbnails = $this->loadThumbnails();
          }
          //$facade = new \ShortPixelMetaFacade($this->post_id);
      }

      if (is_object($meta))
      {
        $this->image_meta = $meta;
        foreach($meta->thumbnails as $thumbName => $thumbObj)
        {
            $this->thumbnails[$thumbName] = $thumbObj;
        }

        return true;
      }

      return false;
  }

  protected function saveMeta()
  {

      $result = update_post_meta($this->post_id, 'shortpixel_meta', $this->meta);

      if ($result === false)
      {
        Log::addError('Saving Metadata of ' . $this->post_id . ' failed!');
      }

  }

  private function checkLegacy()
  {
      $metadata = $this->wp_metadata;

      if ( isset($metadata['ShortPixel']))
      {
         echo " I MUST CONVERT THIS ";
         $data = $metadata['ShortPixel'];
         $meta = new ImageMeta();
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
      }
  }

  private function safeGetUrl() {
      $attURL = wp_get_attachment_url($this->post_id);
      if(!$attURL || !strlen($attURL)) {
        //  throw new Exception("Post metadata is corrupt (No attachment URL for $id)", ShortPixelAPI::ERR_POSTMETA_CORRUPT);
        Log::addError('Post metadata is corrupt (No attachment URL for ' . $this->post_id .')');
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

    if (is_null($this->post_id))
      return false;

    $originalFile = $fs->getOriginalPath($this->post_id);

    if ($originalFile->exists() && $originalFile->getFullPath() !== $this->getfullPath() )
    {
      $this->original_file = $originalFile;
      $this->is_scaled = true;
    }

  }


  // Not sure if it will work like this.
  public function is_scaled()
  {
     return $this->is_scaled;
  }





} // class
