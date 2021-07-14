<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use \ShortPixel\ShortPixelPng2Jpg as ShortPixelPng2Jpg;


class MediaLibraryModel extends \ShortPixel\Model\Image\MediaLibraryThumbnailModel
{
  protected $thumbnails = array(); // thumbnails of this // MediaLibraryThumbnailModel .
  protected $retinas = array(); // retina files - MediaLibraryThumbnailModel (or retina / webp and move to thumbnail? )
  //protected $webps = array(); // webp files -
  protected $original_file = false; // the original instead of the possibly _scaled one created by WP 5.3

  protected $is_scaled = false; // if this is WP 5.3 scaled
  protected $do_png2jpg = false; // option to flag this one should be checked / converted to jpg.

  protected $wp_metadata;

  protected $type = 'media';
  protected $is_main_file = true; // for checking

  private $unlistedChecked = false; // limit checking unlisted.

  public function __construct($post_id, $path)
  {
      $this->id = $post_id;

      parent::__construct($path, $post_id, null);

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

     if ($this->isProcessable(true))
        $paths = array($this->getFullPath());

     foreach($this->thumbnails as $thumbObj)
     {
       if ($thumbObj->isProcessable() )
        $paths = array_merge($paths, $thumbObj->getOptimizePaths());
     }

     $paths = array_values(array_unique($paths));
     return $paths;
  }


  public function getOptimizeUrls()
  {
     $settings = \wpSPIO()->settings();

     $url = $this->getURL();

     if (! $url) // If the whole image URL can't be found
     {
      return array();
     }

     $urls = array();
     if ($this->isProcessable(true))
      $urls = array($url);

     if ($this->isScaled())
     {
        $urls = array_merge($urls, $this->original_file->getOptimizeUrls());
     }

     foreach($this->thumbnails as $thumbObj)
     {
        if($thumbObj->isThumbnailProcessable())
          $urls = array_merge($urls, $thumbObj->getOptimizeUrls());
     }

     // @todo Check Retina's
    if ($settings->optimizeRetina)
    {

        foreach($this->retinas as $retinaObj)
        {
           $urls = array_merge($urls, $retinaObj->getOptimizeUrls());
        }
     }
     $urls = array_values(array_unique($urls));
     return $urls;
  }

  // Try to get the URL via WordPress
  public function getURL()
  {
     return $this->fs()->checkURL(wp_get_attachment_url($this->id));
  }

  /** Get FileTypes that might be optimized. Checking for setting should go via isProcessableFileType! */
  public function getOptimizeFileType($type = 'webp')
  {
      if ($type = 'webp')
      {
        $types = $this->getWebps();
      }
      elseif ($type == 'avif')
      {
          $types = $this->getAvifs();
      }

      $toOptimize = array();

      // main file.
      if (! isset($types[0]))
      {
          // The isProcessable(true) is very important, since non-strict calls of this function check this function as well ( resulting in loop )
          if ($this->isProcessable(true) || $this->isOptimized())
          {

            $toOptimize[] = $this->getURL(); // $fs->pathToUrl($this);

          }
      }
      if ($this->isScaled() ) // scaled image
      {
        if ($this->original_file->getOptimizeFileType($type) )
            $toOptimize[] = $this->original_file->getURL(); //$fs->pathToUrl($this->original_file);
      }

      foreach($this->thumbnails as $thumbName => $thumbObj)
      {
          if ($thumbObj->getOptimizeFileType($type))
              $toOptimize[] = $thumbObj->getURL(); //$fs->pathToUrl($thumbObj);
      }

      return array_values(array_unique($toOptimize));
      //foreach($types as $index => $)
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


  /** Loads an array of Thumbnailmodels based on sizes available in WordPress metadata
  **  @return Array consisting ofMediaLibraryThumbnailModel
  **/
  protected function loadThumbnailsFromWP()
  {
    $wpmeta = $this->getWPMetaData();

    if (is_null($this->originalWidth))
      $this->originalWidth = $wpmeta['width'];

    if (is_null($this->originalWidth))
      $this->originalHeight = $wpmeta['height'];


    $thumbnails = array();
    if (isset($wpmeta['sizes']))
    {
          foreach($wpmeta['sizes'] as $name => $data)
          {
             if (isset($data['file']))
             {
               $thumbObj = $this->getThumbnailModel($this->getFileDir() . $data['file'], $name);

               $meta = new ImageThumbnailMeta();
               $thumbObj->setName($name);
               $meta->originalWidth = (isset($data['width'])) ? $data['width'] : null; // get from WP
               $meta->originalHeight = (isset($data['height'])) ? $data['height'] : null;
               $thumbObj->setMetaObj($meta);
               $thumbnails[$name] = $thumbObj;
             }
          }
    }

    return $thumbnails;
  }

  protected function getRetinas()
  {
      if (! is_null($this->retinas))
        return $this->retinas;

      $retinas = array();
      $main = $this->getRetina();

      if ($main)
        $retinas[0] = $main; // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      if ($this->isScaled())
      {
        $retscaled = $this->original_file->getRetina();
        if ($retscaled)
          $retinas[1] = $retscaled; //see main
      }

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
      if ($this->isScaled())
      {
        $webp = $this->original_file->getWebp();
        if ($webp)
          $webps[1] = $webp; //see main
      }

      return $webps;
  }

  protected function getAvifs()
  {
      $avifs = array();
      $main = $this->getAvif();

      if ($main)
        $avifs[0] = $main;  // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

      foreach($this->thumbnails as $thumbname => $thumbObj)
      {
         $avif = $thumbObj->getAvif();
         if ($avif)
          $avifs[$thumbname] = $avif;
      }
      if ($this->isScaled())
      {
        $avif = $this->original_file->getAvif();
        if ($avif)
          $avifs[1] = $avif; //see main
      }

      return $avifs;
  }

  // @todo Needs unit test.
  public function count($type)
  {
      switch($type)
      {
         case 'thumbnails' :
           $count = count($this->thumbnails);
         break;
         case 'webps':
            $count = count(array_unique($this->getWebps()));
         break;
         case 'avifs':
            $count = count(array_unique($this->getAvifs()));
         break;
         case 'retinas':
           $count = count(array_unique($this->getRetinas()));
         break;
      }

      return $count;

  }

  /* Sanity check in process. Should only be called upon special request, or with single image displays. Should check and recheck stats, thumbs, unlistedthumbs and all assumptions of data that might corrupt or change outside of this plugin */
  public function reAcquire()
  {
      $this->addUnlisted();
      //$this->reCheckThumbnails();
      if (\wpSPIO()->settings()->optimizeRetina)
        $this->retinas = $this->getRetinas();

      $this->webps = $this->getWebps();

  }

  public function handleOptimized($tempFiles)
  {
    //  Log::addTemp('TEMPFILES, HandleOptimized', $tempFiles);
       Log::addTemp('MediaLibraryModel :: HandleOptimized');
      $return = true;
      if (! $this->isOptimized() ) // main file might not be contained in results
      {
          $result = parent::handleOptimized($tempFiles);
          if (! $result)
          {

             return false;
          }
      }

      $this->handleOptimizedFileType($tempFiles);

      $optimized = array();

      // If thumbnails should not be optimized, they should not be in result Array.
      foreach($this->thumbnails as $thumbnail)
      {

         $thumbnail->handleOptimizedFileType($tempFiles); // check for webps /etc

         if ($thumbnail->isOptimized())
          continue;
         // @todo Find here which one is handles, since sizes can have duplicate files ( ie multiple size pointing to same filename, make local array if duplicate comes up / don't reprocess ). Same needed in restore.

         if (!$thumbnail->isProcessable())
           continue; // when excluded.

         $filebase = $thumbnail->getFileBase();
         $result = false;

         if (isset($optimized[$filebase]))
         {
           $thumbnail->setMetaObj($optimized[$filebase]);
         }
         else
         {
          $result = $thumbnail->handleOptimized($tempFiles);
         }

         Log::addTemp('Thumbnail, result', array($result,$thumbnail->get('prevent_next_try')));
         if ($result)
         {
            $optimized[$filebase]  = $thumbnail->getMetaObj();
         }
         elseif ($thumbnail->get('prevent_next_try') !== false) // in case of fatal issues.
         {
              $this->preventNextTry($thumbnail->get('prevent_next_try'));
              $return = false; //failed
         }
      }

      if ($this->isScaled() )
      {
          $original_file = $this->getOriginalFile();
          $original_file->handleOptimizedFileType($tempFiles);

          if (! $original_file->isOptimized())
          {

            $result = $original_file->handleOptimized($tempFiles);
            if (! $result &&  $original_file->get('prevent_next_try') !== false)
            {
                $this->preventNextTry($original_file->get('prevent_next_try'));
                $return = false; // failed.
            }


            Log::addDebug('Is Scaled, Original file handled optimized', $original_file);
          }
          $this->original_file = $original_file;
      }

      // @todo Check for WPML Duplicates
      $this->saveMeta();


      return $return;
  }

  public function getImprovements()
  {
        $improvements = array();
        $count = 0;
        $totalsize = 0;
        $totalperc = 0;

        if ($this->isOptimized())
        {
           $perc = $this->getImprovement();
           $size = $this->getImprovement(true);
           $totalsize += $size;
           $totalperc += $perc;
           $improvements['main'] = array($perc, $size);
           $count++;
        }
        foreach($this->thumbnails as $thumbObj)
        {
           if (! $thumbObj->isOptimized())
             continue;

           if (! isset($improvements['thumbnails']))
           {
                $improvements['thumbnails'] = array();
           }
           $perc = $thumbObj->getImprovement();
           $size = $thumbObj->getImprovement(true);
           $totalsize += $size;
           $totalperc += $perc;
           $improvements['thumbnails'][$thumbObj->name] = array($perc, $size);
           $count++;
        }

        if ($count == 0)
          return false; // no improvements;

        $improvements['totalpercentage']  = round($totalperc / $count);
        $improvements['totalsize'] = $totalsize;
        return $improvements;
  }


  /** @param String Full Path to the Thumbnail File
  *   @return Object ThumbnailModel
  * */
  private function getThumbnailModel($path, $size)
  {
      $thumbObj = new MediaLibraryThumbnailModel($path, $this->id, $size);
      return $thumbObj;
  }

  protected function loadMeta()
  {
      $metadata = get_post_meta($this->id, '_shortpixel_meta', true); // ShortPixel MetaData
      $settings = \wpSPIO()->settings();

      $this->image_meta = new ImageMeta();
      $fs = \wpSPIO()->fileSystem();

      if (! $metadata)
      {
            // Thumbnails is a an array of ThumbnailModels
            $this->thumbnails = $this->loadThumbnailsFromWP();

            $result = $this->checkLegacy();
            if ($result)
            {
              $metadata = $this->createSave(); // after convert, pretent it's loaded as save ( and save! ) @todo
              $this->saveMeta();

            }
      }
      elseif (is_object($metadata) )
      {
          $this->image_meta->fromClass($metadata->image_meta);

          // Loads thumbnails from the WordPress installation to ensure fresh list, discover later added, etc.
          $thumbnails = $this->loadThumbnailsFromWP();


          foreach($thumbnails as $name => $thumbObj)
          {
             if (isset($metadata->thumbnails[$name])) // Check WP thumbs against our metadata.
             {
                $thumbMeta = new ImageThumbnailMeta();
                $thumbMeta->fromClass($metadata->thumbnails[$name]); // Load Thumbnail data from our saved Meta in model

                // Only get data from WordPress meta if we don't have that yet.

                if (is_null($thumbMeta->originalWidth))
                  $thumbObj->setMeta('originalWidth', $thumbObj->get('width'));

                if (is_null($thumbMeta->originalHeight))
                  $thumbObj->setMeta('originalHeight', $thumbObj->get('height'));

                if (is_null($thumbMeta->tsAdded))
                  $thumbObj->setMeta('tsAdded', time());

                $thumbnails[$name]->setMetaObj($thumbMeta);
                unset($metadata->thumbnails[$name]);
             }
          }

          // Load Thumbnails.
          if (isset($metadata->thumbnails) && count($metadata->thumbnails) > 0) // unlisted in WordPress metadata sizes. Might be special unlisted one, one that was removed etc.
          {
             foreach($metadata->thumbnails as $name => $thumbMeta) // <!-- ThumbMeta is Object
             {
               // Load from Class and file, might be an unlisted one. Meta doesn't save file info, so without might prove a problem!

               // If file is not set, it's indication it's not a unlisted image, we can't add it. 
               if (! property_exists($thumbMeta, 'file'))
                 continue;

               $thumbObj = $this->getThumbnailModel($this->getFileDir() . $thumbMeta->file, $name);

               $newMeta = new ImageThumbnailMeta();
               $newMeta->fromClass($thumbMeta);
               //$thumbObj = $this->getThumbnailModel($this->getFileDir() . $thumbmeta['file']);
               //$meta = new ImageThumbnailMeta();
               //$meta->fromClass($thumbMeta); // Load Thumbnail data from our saved Meta in model
               $thumbObj->setMetaObj($newMeta);
               $thumbObj->setName($name);
               if ($thumbObj->exists()) // if we exist.
               {
                $thumbnails[$name] = $thumbObj;
               }

             }
          }
          $this->thumbnails = $thumbnails;

          if (isset($metadata->retinas))
          {
              $retinas = $this->getRetinas();
              foreach($metadata->retinas as $name => $retinaMeta)
              {
                  if (isset($retinas[$name]))
                  {
                    $retfile = $retinas[$name];
                    $retinaObj = $this->getThumbnailModel($retfile->getFullPath(), $name);
                    $retMeta = new ImageThumbnailMeta();
                    $retMeta->fromClass($retinaMeta);
                    $retinaObj->setMetaObj($retMeta);
                    $this->retinas[$name] = $retinaObj;
                  }
              }
          }

          if (isset($metadata->original_file))
          {
              $orFile = $this->getOriginalFile();
              if ($orFile)
              {
                $orMeta = new ImageThumbnailMeta();
                $orMeta->fromClass($metadata->original_file);
                $orFile->setMetaObj($orMeta);
                $this->original_file = $orFile;
              }
          }

      }

      // settings defaults
      if (is_null($this->getMeta('originalHeight')))
        $this->setMeta('originalHeight', $this->get('height') );

      if (is_null($this->getMeta('originalWidth')))
        $this->setMeta('originalWidth', $this->get('width') );
  }

  private function createSave()
  {
      $metadata = new \stdClass; // $this->image_meta->toClass();
      $metadata->image_meta = $this->image_meta->toClass();
      $thumbnails = array();
      $retinas = array();
    //  $webps = array();

      foreach($this->thumbnails as $thumbName => $thumbObj)
      {
         $thumbnails[$thumbName] = $thumbObj->toClass();
      }
      foreach($this->retinas as $index => $retinaObj)
      {
         $retinas[$index] = $retinaObj->toClass();
      }
      /*foreach($this->webps as $index => $webp)
      {
        $webps[$index] = $webp->getFullPath();
      } */

      if (count($thumbnails) > 0)
        $metadata->thumbnails = $thumbnails;
      if (count($retinas) > 0)
        $metadata->retinas = $retinas;
      /*if (count($webps) > 0)
        $metadata->webps = $webps; */

      if ($this->isScaled())
      {
        Log::addTemp('SaveMeta CreateSave Original File', $this->original_file);
        $metadata->original_file = $this->original_file->toClass();
      }

      return $metadata;
 }

 public function saveMeta()
 {
     $metadata = $this->createSave();
     // There is no point checking for errors since false is returned on both failure and no field changed.
     update_post_meta($this->id, '_shortpixel_meta', $metadata);

     if ($this->isOptimized())
     {
        update_post_meta($this->id, '_shortpixel_optimized', $this->getImprovement() );
        update_post_meta($this->id, '_shortpixel_optdate', $this->getMeta('tsOptimized'));
     }
  }

  /** Delete the Shortpixel Meta */
  public function deleteMeta()
  {
     Log::addTemp('Deleting ShortPixel Meta ' . $this->id);
     $bool = delete_post_meta($this->id, '_shortpixel_meta');
     if (! $bool)
      Log::addWarn('Delete Post Meta failed');

     delete_post_meta($this->id, '_shortpixel_optimized');

     return $bool;
  }

  /** Ondelete is trigger by WordPress deleting an image. SPIO should delete it's data, and backups */
  public function onDelete()
  {
      parent::onDelete();

      foreach($this->thumbnails as $thumbObj)
      {
        $thumbObj->onDelete();
      }

      if ($this->isScaled())
      {
         $originalFile = $this->getOriginalFile();
         $originalFile->onDelete();
      }


      $this->deleteMeta();
  }

  public function getThumbNail($name)
  {
     if (isset($this->thumbnails[$name]))
        return $this->thumbnails[$name];

      return false;
  }

  /* Check if an image in theory could be processed. Check exclusions, thumbnails etc */
  /* @param Strict Boolean Check only the main image, don't check thumbnails */
  public function isProcessable($strict = false)
  {
      $bool = true;
      $bool = parent::isProcessable();

      $settings = \wpSPIO()->settings();

      if ($this->getExtension() == 'png' && $settings->png2jpg)
        $this->do_png2jpg = true;

      if($strict)
        return $bool;

      // Adds unlisted files to thumbnails array, if needed.
      // This is bound to be bad for performance and not good for big sites!
      $this->addUnlisted();

      if (! $bool) // if parent is not processable, check if thumbnails are, can still have a work to do.
      {

          foreach($this->thumbnails as $thumbnail)
          {

            $bool = $thumbnail->isThumbnailProcessable();

            if ($bool === true) // Is Processable just needs one job
              return true;
          }

          // check if original image is optimized.
          if ($this->isScaled())
          {
             $bool = $this->getOriginalFile()->isThumbnailProcessable();
             if ($bool === true)
              return true;
          }
      }

      // Todo check if Webp / Acif is active, check for unoptimized items
      if ($this->isProcessableFileType('webp'))
        $bool = true;
      if ($this->isProcessableFileType('avif'))
         $bool = true;


      return $bool;
  }



  public function isRestorable()
  {
      $bool = true;
      $bool = parent::isRestorable();

      if (! $bool) // if parent is not processable, check if thumbnails are, can still have a work to do.
      {
          foreach($this->thumbnails as $thumbnail)
          {
            if (! $thumbnail->isOptimized())
               continue;

            $bool = $thumbnail->isRestorable();

            if ($bool === true) // Is Restorable just needs one job
              return true;
          }
          if ($this->isScaled() && ! $bool)
          {
             $originalFile = $this->getOriginalFile();
             $bool = $originalFile->isRestorable();
          }
      }

      return $bool;
  }

  public function convertPNG()
  {
      $settings = \wpSPIO()->settings();
      $bool = false;
      if ($this->getExtension() == 'png')
      {
          if ($settings->backupImages == 1)
          {
             $backupok = $this->createBackup();
             if (! $backupok)
             {
               ResponseController::add()->withMessage(sprintf(__('Could not create backup for %s, optimization failed. Please check file permissions - %s', 'shortpixel-image-optimiser'), $this->getFileName(), $this->getFullPath() ))->asImportant()->asError();
               return false;
             }
          }

          $pngConvert = new ShortPixelPng2Jpg();
          $bool = $pngConvert->convert($this);
          if ($bool == true)
          {
             $bool = true; // placeholder maybe
          }
          elseif ($settings->backupImages == 1)
             $this->restore(); // failed, remove backups.
      }

      if ($bool)
      {
        $this->setMeta('did_png2jpg', true);

        $mainfile = \wpSPIO()->filesystem()->getfile($this->getFileDir() . $this->getFileBase() . '.jpg');
        Log::addTemp('Removing old files' . $this->getFullPath());

        if ($mainfile->exists()) // if new exists, remove old
        {
            $this->delete(); // remove the old file.
            $this->fullpath = $mainfile->getFullPath();
            $this->resetStatus();
            $this->setFileInfo();

        }

        // After Convert, reload new meta.
        $this->thumbnails = $this->loadThumbnailsFromWP();

        foreach($this->thumbnails as $thumbObj)
        {
            $file = \wpSPIO()->filesystem()->getfile($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.jpg');
            $thumbObj->setMeta('did_png2jpg', true);
            if ($file->exists()) // if new exists, remove old
            {
              Log::addTemp('Removing Thumb: ' . $thumbObj->getFullPath() );
                $thumbObj->delete(); // remove the old file.
                $thumbObj->fullpath = $file->getFullPath();
                $thumbObj->resetStatus();
                $thumbObj->setFileInfo();
            }
        }

        // Update
        $this->saveMeta();
      //  Log::addTemp('Doing Construct ' . $mainfile->getFullPath() );
        //$this->__construct($this->id, $mainfile->getFullPath() );
    //    Log::addTemp('Reconstructed', $this);

      }

    //  $this->loadMeta();

      return $bool;
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
            $width = $this->get('width');
            $height = $this->get('height');
            if( $width && $height
                 && $this->isProcessableSize($width, $height, $item["value"]) === false){
                   $this->processable_status = self::P_EXCLUDE_SIZE;
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

  public function getOriginalFile()
  {
      if ($this->hasOriginal())
        return $this->original_file;
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
        $sql = $wpdb->prepare("SELECT trid FROM {$wpdb->prefix}icl_translations WHERE element_id = %d", $this->id);
        $transGroupId = $wpdb->get_results($sql);
        if(count($transGroupId)) {
            $sql = $wpdb->prepare("SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE trid = %d", $transGroupId[0]->trid);
            $transGroup = $wpdb->get_results($sql);
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

  /* Protect this image from being optimized. This flag should be unset by UX / Retry button on front */
  protected function preventNextTry($reason = 1)
  {
      //Log::addError('Call resulted in preventNextTry on thumbnailModel');
      //exit('Fatal error : Prevent Next Try should not be run on thumbnails');
      Log::addWarn($this->get('id') . ' preventing next try: ' . $reason);
      update_post_meta($this->id, '_shortpixel_prevent_optimize', $reason);

  }

  public function isOptimizePrevented()
  {
       $reason = get_post_meta($this->id, '_shortpixel_prevent_optimize', true);
       if ($reason === false || strlen($reason) == 0)
         return false;
       else
         return $reason;
  }

  public function resetPrevent()
  {
      Log::addTemp('Reset Preventor');
      delete_post_meta($this->id, '_shortpixel_prevent_optimize');
  }

  /** Removed the current attachment, with hopefully removing everything we set.
  * @todo Should probably not delete files ( SPIO never deletes files ) , other than our backups / webps during restore.
  */
  public function restore()
  {

    $fs = \wpSPIO()->filesystem();

    if ($this->getMeta('did_png2jpg'))
    {
        $backupFile = $fs->getFile($this->getBackupDirectory() . $this->getFileBase() . '.png'); // check backup.

        if ($backupFile->exists())
        {
          $this->delete(); // delete the jpg
          $this->fullpath = $fs->getFile($this->getFileDir() . $this->getFileBase() . '.png');
          $this->resetStatus();
          $this->setFileInfo();
          $this->create(); // empty placeholder file.
          Log::addTemp('Restoring png : ' . $this->fullpath);
          if ($this->exists())
            Log::addTemp('Exists');
        }
    }

    do_action('shortpixel_before_restore_image', $this->get('id'));
    do_action('shortpixel/image/before_restore', $this);

    $cleanRestore = true;

    $bool = parent::restore();

    if (! $bool)
    {
       Log::addTemp('Restoring main file failed ' . $this->getFullPath());
       $cleanRestore = false;
    }

    $this->setMeta('did_png2jpg', false);
    $restored = array();

    foreach($this->thumbnails as $thumbObj)
    {
          $filebase = $thumbObj->getFileBase();

          if ($thumbObj->getMeta('did_png2jpg'))
          {
              $backupFile = $fs->getFile($thumbObj->getBackupDirectory() . $thumbObj->getFileBase() . '.png');
              if ($backupFile->exists())
              {
                $thumbObj->delete(); // delete the jpg
                $thumbObj->fullpath = $fs->getFile($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.png'); // reset path to .png
                $thumbObj->resetStatus();
                $thumbObj->setFileInfo();
                $thumbObj->create(); // empty placeholder file.

                Log::addTemp('Restoring thumbnail png : ' . $thumbObj->fullpath);

              }
          }

          if (isset($restored[$filebase]))
          {
            $bool = true;  // this filebase already restored. In case of duplicate sizes.
            $thumbObj->imageMeta = new ImageMeta();
          }
          elseif ($thumbObj->isRestorable())
            $bool = $thumbObj->restore();

          if (! $bool)
            $cleanRestore = false;
          else
          {
             $restored[$filebase] = true;
             $thumbObj->setMeta('did_png2jpg', false);
          }

    }

    if ($this->isScaled())
    {
       $originalFile = $this->getOriginalFile();
       if ($originalFile->getMeta('did_png2jpg'))
       {
           $backupFile = $fs->getFile($originalFile->getBackupDirectory() . $originalFile->getFileBase() . '.png');
           if ($backupFile->exists())
           {
             $originalFile->delete();
             $originalFile->fullpath = $fs->getFile($originalFile->getFileDir() . $originalFile->getFileBase() . '.png'); // reset path to .png
             $originalFile->resetStatus();
             $originalFile->setFileInfo();
             $originalFile->create(); // empty placeholder file.

             Log::addTemp('Restoring original png : ' . $originalFile->fullpath);

           }
       }

       $originalFile->restore();
    }

        $webps = $this->getWebps();
        foreach($webps as $webpFile)
            $webpFile->delete();

        $avifs = $this->getAvifs();
        foreach($avifs as $avifFile)
            $avifFile->delete();

        if ($cleanRestore)
        {
            Log::addTemp('Restore clean : Deleting metadata');
            $this->deleteMeta();
        }
        else
          $this->saveMeta(); // Save if something is not restored.

        do_action('shortpixel_after_restore_image', $this->id, $this, $cleanRestore);
        return $bool;
  }

  /** This function will recreate thumbnails. This is -only- needed for very special cases, i.e. offload */
  public function wpCreateImageSizes()
  {
    add_filter('as3cf_wait_for_generate_attachment_metadata', array($this, 'returnTrue'));

    $fullpath = $this->getFullPath();
    if ($this->isScaled()) // if scaled, the original file is the main file for thumbnail base
    {
       $originalFile = $this->getOriginalFile();
       $fullpath = $originalFile->getFullPath();
    }
    $res = \wp_create_image_subsizes($fullpath, $this->id);
    Log::addTemp('result', $res);
    Log::addTemp('dir' . (string) $this->getFileDir(), \scandir($this->getFileDir()));

    remove_filter('as3cf_wait_for_generate_attachment_metadata', array($this, 'returnTrue'));

  }


  public function returnTrue()
  {
     return true;
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

      if (count($data) == 0)  // This can happen. Empty array is still nothing to convert.
        return false;


      // This is a switch to prevent converted items to reconvert when the new metadata is removed ( i.e. restore )
      $was_converted = get_post_meta($this->id, '_shortpixel_was_converted', true);
      if ($was_converted == true)
      {
        Log::addDebug('This item was converted, not converting again');
        return false;
      }

      echo " I MUST CONVERT THIS <PRE>";  print_r($metadata); echo "</PRE>";
    //  echo "*** EXPORT: "; var_export($metadata); echo " *** ";
       $type = isset($data['type']) ? $this->legacyConvertType($data['type']) : '';

       $improvement = (isset($metadata['ShortPixelImprovement']) && is_numeric($metadata['ShortPixelImprovement']) && $metadata['ShortPixelImprovement'] > 0) ? $metadata['ShortPixelImprovement'] : 0;

       $status = $this->legacyConvertStatus($data, $metadata);

       $error_message = isset($metadata['ShortPixelImprovement']) && ! is_numeric($metadata['ShortPixelImprovement']) ? $metadata['ShortPixelImprovement'] : '';

       $retries = isset($data['Retries']) ? intval($data['Retries']) : 0;
       $optimized_thumbnails = (isset($data['thumbsOptList']) && is_array($data['thumbsOptList'])) ? $data['thumbsOptList'] : array();
       $exifkept = (isset($data['exifKept']) && $data['exifKept']  == 1) ? true : false;

       $tsAdded = time();

       if ($status == self::FILE_STATUS_SUCCESS)
       {
         //strtotime($tsOptimized)
         $newdate = \DateTime::createFromFormat('Y-m-d H:i:s', $data['date']);
         $newdate = $newdate->getTimestamp();

        $tsOptimized = $newdate;
        $this->image_meta->tsOptimized = $tsOptimized;
       }

       $this->image_meta->wasConverted = true;
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

       $webp = $this->getWebp();
       if ($webp)
        $this->image_meta->webp = $webp->getFileName();

       $this->width = isset($metadata['width']) ? $metadata['width'] : false;
       $this->height = isset($metadata['height']) ? $metadata['height'] : false;


       if (isset($metadata['ShortPixelPng2Jpg']))
       {
           $this->image_meta->did_png2jpg = true; //setMeta('did_png2jpg', true);
           $did_jpg2png = true;
       }
       else
           $did_jpg2png = false;
    //   $this->image_meta->did_cmyk2rgb = $exifkept;
      // $this->image_meta->tsOptimized =

       foreach($this->thumbnails as $thumbname => $thumbnailObj) // ThumbnailModel
       {
          if (in_array($thumbnailObj->getFileName(), $optimized_thumbnails))
          {
              $thumbnailObj->image_meta->status = $status;
              $thumbnailObj->image_meta->compressionType = $type;
              $thumbnailObj->image_meta->compressedSize = $thumbnailObj->getFileSize();
              $thumbnailObj->image_meta->did_jpg2png = $did_jpg2png;
          //    $thumbnailObj->image_meta->improvement = -1; // n/a
              if ($thumbnailObj->hasBackup())
              {
                $backup = $thumbnailObj->getBackupFile();
                $thumbnailObj->image_meta->originalSize = $backup->getFileSize();
              }

              $thumbnailObj->image_meta->tsAdded = $tsAdded;
              if (isset($tsOptimized))
                $thumbnailObj->image_meta->tsOptimized = $tsOptimized;

              $thumbnailObj->has_backup = $thumbnailObj->hasBackup();

              $webp = $thumbnailObj->getWebp();
              if ($webp)
              {
                 $thumbnailObj->image_meta->webp = $webp->getFileName();
              }

              if (strpos($thumbname, 'sp-found') !== false) // File is 'unlisted', also save file information.
              {
                 $thumbnailObj->image_meta->file = $thumbnailObj->getFileName();
              }

              $this->thumbnails[$thumbname] = $thumbnailObj;

          }
       }

       if ($this->isScaled())
       {
         $originalFile = $this->original_file;
         if (in_array($originalFile->getFileName(), $optimized_thumbnails))
         {

           $originalFile->image_meta->status = $status;
           $originalFile->image_meta->compressionType = $type;
           $originalFile->image_meta->compressedSize = $originalFile->getFileSize();
           $originalFile->image_meta->did_jpg2png = $did_jpg2png;
       //    $thumbnailObj->image_meta->improvement = -1; // n/a
           if ($thumbnailObj->hasBackup())
           {
             $backup = $originalFile->getBackupFile();
             $originalFile->image_meta->originalSize = $backup->getFileSize();
           }

           $originalFile->image_meta->tsAdded = $tsAdded;
           $originalFile->image_meta->tsOptimized = $tsOptimized;
           $originalFile->has_backup = $originalFile->hasBackup();

           $webp = $originalFile->getWebp();
           if ($webp)
           {
              $originalFile->image_meta->webp = $webp->getFileName();
           }

           if (strpos($thumbname, 'sp-found') !== false) // File is 'unlisted', also save file information.
           {
              $originalFile->image_meta->file = $originalFile->getFileName();
           }

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
              if ($status == self::FILE_STATUS_SUCCESS)
                $retinaObj->image_meta->compressedSize = $retinaObj->getFileSize();
              else
                $retinaObj->image_meta->originalSize = $retinaObj->getFileSize();
            //  $retinaObj->image_meta->improvement = -1; // n/a
              $retinaObj->image_meta->tsAdded = $tsAdded;
              $retinaObj->image_meta->tsOptimized = $tsOptimized;
              $retinaObj->image_meta->did_jpg2png = $did_jpg2png;
              if ($retinaObj->hasBackup())
              {
                $retinaObj->has_backup = true;
                if ($status == self::FILE_STATUS_SUCCESS)
                  $retinaObj->image_meta->originalSize = $retinaObj->getBackupFile()->getFileSize();
              }

              $retinas[$index] = $retinaObj;
           }
           $this->retinas = $retinas;
           Log::addTemp('Retinas', $retinas);
           if ($count !== count($retinas))
           {
              Log::addWarning("Conversion: $count retinas expected in legacy, " . count($retinas) . 'found', $retinas);
           }
       }


       update_post_meta($this->id, '_shortpixel_was_converted', true);
       delete_post_meta($this->id, '_shortpixel_status');
      /*if (isset($data['webpCount']))
      {
          $count = $data['webpCount'];
          $webps = $this->getWebps(); // Simple FileModel objects.
          $this->webps = $webps;
      } */

      // FOR TESTING @todo remove
//echo "<PRE><strong>RESULT</strong>";
//print_r($this->thumbnails);
//print_r($this->createSave());echo "</PRE>";
    // *** END TESTING ***/


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
         "backup" => !isset($rawMeta['ShortPixebugel']['NoBackup']),
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
        'original_file' => $this->original_file,
        'is_scaled' => $this->is_scaled,
      );

  }

  /** Adds Unlisted Image to the Media Library Item
  * This function is called in IsProcessable
  */
  protected function addUnlisted()
  {
       // Setting must be active.
       if (! \wpSPIO()->settings()->optimizeUnlisted )
         return;

      // Don't check this more than once per run-time.
      if ( $this->unlistedChecked )
      {
          return;
      }

      // addUnlisted is called by IsProcessable, file might not exist.
      // If virtual, we can't read dir, don't do it.
      if (! $this->exists() || $this->is_virtual())
      {
          $this->unlistedChecked = true;
         return;
      }

        $currentFiles = array($this->getFileName());
        foreach($this->thumbnails as $thumbObj)
          $currentFiles[] = $thumbObj->getFileName();

        if ($this->isScaled())
           $currentFiles[] = $this->getOriginalFile()->getFileName();

        $base = ($this->isScaled() ) ? $this->getOriginalFile()->getFileBase() : $this->getFileBase();
        $ext = $this->getExtension();
        $path = (string) $this->getFileDir();

        $pattern = '/^' . preg_quote($base, '/') . '-\d+x\d+\.'. $ext .'/';

        $thumbs = array();

        $all_files = scandir($path,  SCANDIR_SORT_NONE);
        $result_files = array_values(preg_grep($pattern, $all_files));

        $unlisted = array_diff($result_files, $currentFiles);

        if( defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES') ){
            $suffixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_SUFFIXES);
            if (is_array($suffixes))
                {
                  foreach ($suffixes as $suffix){

                      $pattern = '/^' . preg_quote($base, '/') . '-\d+x\d+'. $suffix . '\.'. $ext .'/';
                      $thumbs = array_values(preg_grep($pattern, $all_files));
                      if (count($thumbs) > 0)
                        $unlisted = array_merge($unlisted, $thumbs);
                      //array_merge($thumbs, self::getFilesByPattern($dirPath, $pattern));
                      /*foreach($thumbsCandidates as $th) {
                          if(preg_match($pattern, $th)) {
                              $thumbs[]= $th;
                          }
                      } */
                  }
                }
            }
            if( defined('SHORTPIXEL_CUSTOM_THUMB_INFIXES') ){
                $infixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_INFIXES);
                if (is_array($infixes))
                {
                  foreach ($infixes as $infix){
                      //$thumbsCandidates = @glob($base . $infix  . "-*." . $ext);
                      $pattern = '/^' . preg_quote($base, '/') . $infix . '-\d+x\d+' . '\.'. $ext .'/';
                      $thumbs = array_values(preg_grep($pattern, $all_files));
                      if (count($thumbs) > 0)
                        $unlisted = array_merge($unlisted, $thumbs);
                    //  $thumbs = array_merge($thumbs, self::getFilesByPattern($dirPath, $pattern));

                      /*foreach($thumbsCandidates as $th) {
                          if(preg_match($pattern, $th)) {
                              $thumbs[]= $th;
                          }
                      } */
                  }
                }
            }
      //  }

      // Quality check on the thumbs. Must exist,  must be same extension.

      $added = false;
      foreach($unlisted as $unName)
      {
          $thumbObj = $this->getThumbnailModel($path . $unName, $unName);
          if ($thumbObj->getExtension() == 'webp') // ignore webp files.
          {
            continue;
          }
          elseif ($thumbObj->is_readable()) // exclude webps
          {
            $thumbObj->setName($unName);
            $thumbObj->setMeta('originalWidth', $thumbObj->get('width'));
            $thumbObj->setMeta('originalHeight', $thumbObj->get('height'));
            $thumbObj->setMeta('file', $thumbObj->getFileName() );
            $this->thumbnails[$unName] = $thumbObj;
            $added = true;
            Log::addTemp('Unlisted Thumb: ', $thumbObj);
          }
          else
          {
            Log::addWarn("Unlisted Image $unName is not readable (permission error?)");
          }
      }

      if ($added)
        $this->saveMeta(); // Save it when we are adding images.

      $this->unlistedChecked = true;


      /* Returns array with full path, as string */
      //return $results;
  }

} // class
