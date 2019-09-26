<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

/* ImageModel class.
*
*
* - Represents a -single- image *not file*.
* - Can be either MediaLibrary, or Custom .
* - Not a replacement of Meta, but might be.
* - Goal: Structural ONE method calls of image related information, and combining information. Same task is now done on many places.
* -- Shortpixel Class should be able to blindly call model for information, correct metadata and such.
*/
class ImageModel extends ShortPixelModel
{

    private $file;  // the file representation
    private $meta; // metadata of the image.
    private $facade; // ShortPixelMetaFacade

    protected $thumbsnails = array(); // thumbnails of this


    public function __construct()
    {

    }

    public function setByPostID($post_id)
    {
      // Set Meta
      $fs = new FileSystemController();
      $this->facade = new \ShortPixelMetaFacade($post_id);
      $this->meta = $this->facade->getMeta();

      $file = get_attached_file($post_id);
      $this->file = $fs->getFile($file);

    }

    public function getMeta()
    {
      return $this->meta;
    }

    public function getFile()
    {
      return $this->file;
    }

    /* Sanity check in process. Should only be called upon special request, or with single image displays. Should check and recheck stats, thumbs, unlistedthumbs and all assumptions of data that might corrupt or change outside of this plugin */
    public function reAcquire()
    {
        $this->addUnlistedThumbs();
        $this->reCheckThumbnails();

        // $this->recount();
    }

    // Rebuild the ThumbsOptList and others to fix old info, wrong builds.
    private function reCheckThumbnails()
    {
       // Redo only on non-processed images.
       if ($this->meta->getStatus() != \ShortPixelMeta::FILE_STATUS_SUCCESS)
       {
         return;
       }
       $data = $this->facade->getRawMeta();
       $oldList = array();
       if (isset($data['ShortPixel']['thumbsOptList']))
       {
        $oldList = $data['ShortPixel']['thumbsOptList'];
        unset($data['ShortPixel']['thumbsOptList']); // reset the thumbsOptList, so unset to get what the function thinks should be there.
       }
       list($includedSizes, $thumbsCount)  = \WpShortPixelMediaLbraryAdapter::getThumbsToOptimize($data, $this->file->getFullPath() );

       // When identical, save the check and the Dbase update.
       if ($oldList === $includedSizes)
       {
          return;
       }

       $newList = array();
       foreach($this->meta->getThumbsOptList() as $index => $item)
       {
         if ( in_array($item, $includedSizes))
         {
            $newList[] = $item;
         }
       }

       $this->meta->setThumbsOptList($newList);
       $this->facade->updateMeta($this->meta);

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

      $fs = new FileSystemController();
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
