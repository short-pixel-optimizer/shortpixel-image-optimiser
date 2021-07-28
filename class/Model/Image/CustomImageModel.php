<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

// @todo Custom Model for adding files, instead of meta DAO.
class CustomImageModel extends \ShortPixel\Model\Image\ImageModel
{

    protected $folder_id;
    protected $path_md5;

    protected $type = 'custom';

    protected $thumbnails = array(); // placeholder, should return empty.
    protected $retinas = array(); // placeholder, should return empty.

    protected $in_db = false;
    protected $is_stub = false;

    protected $is_main_file = true;

    const FILE_STATUS_PREVENT = -10;

    public function __construct(int $id)
    {
        $this->id = $id;

        if ($id > 0)
          $this->loadMeta();
        else
        {

          $this->fullpath = ''; // stub
          $this->image_meta = new ImageMeta();
          $this->is_stub = true;
        }
        parent::__construct($this->fullpath);
    }

    public function setFolderId(int $folder_id)
    {
        $this->folder_id = $folder_id;
    }


  public function getOptimizePaths()
    {
      if (! $this->isProcessable())
        return;

       $paths = array();

       if (! $this->image_meta->status == self::FILE_STATUS_SUCCESS)
            $paths = array($this->getFullPath());

        return $paths;
    }

    public function getOptimizeUrls()
    {

        $fs = \wpSPIO()->filesystem();
        if ($this->is_virtual())
          $url = $this->getFullPath();
        else
          $url = $fs->pathToUrl($this);

        if ($this->isProcessable())
          return array($url);

        return array();
    }

    public function count($type)
    {
      // everything is 1 on 1 in the customModel
      switch($type)
      {
         case 'thumbnails':
            return 0;
         break;
         case 'webps':
            $count = count($this->getWebps());
         break;
         case 'avifs':
            $count = count($this->getAvifs());
         break;
         case 'retinas':
           $count = count($this->getRetinas());
         break;
      }


      return $count; // 0 or 1
    }

    /* Check if an image in theory could be processed. Check only exclusions, don't check status etc */
    public function isProcessable()
    {
        $bool = parent::isProcessable();

        if (! $bool)
        {
          // Todo check if Webp / Acif is active, check for unoptimized items
          if ($this->isProcessableFileType('webp'))
            $bool = true;
          if ($this->isProcessableFileType('avif'))
             $bool = true;

        }

        return $bool;
    }


    protected function getWebps()
    {
      $fs = \wpSPIO()->filesystem();
      $webp = $fs->getFile($this->getFileDir() . $this->getFileBase() . '.webp');


      if (! $webp->exists())
      {
        return array($webp);
      }

      $double_webp = \wpSPIO()->env()->useDoubleWebpExtension();

      if ($double_webp)
        $filename = $this->getFileName();
      else
        $filename = $this->getFileBase();

      $filename .= '.webp';
      $filepath = $this->getFileDir() . $filename;

      $webp = $fs->getFile($filepath);

      $webps = array();
      if ($webp->exists())
        $webps = array($webp);

      return $webps;
    }

    protected function getAvifs()
    {
      $fs = \wpSPIO()->filesystem();
      $avif = $fs->getFile($this->getFileDir() . $this->getFileBase() . '.avif');

      $avifs = array();

      if ($avif->exists())
        $avifs[]= $avif;

      return $avifs;
    }

    /** Get FileTypes that might be optimized. Checking for setting should go via isProcessableFileType! */
    public function getOptimizeFileType($type = 'webp')
    {
        if ($type == 'webp')
        {
          $types = $this->getWebps();
        }
        elseif ($type == 'avif')
        {
            $types = $this->getAvifs();
        }

        $toOptimize = array();
        $fs = \WPSPIO()->filesystem();

        if (count($types) == 0)
          return array($fs->pathToUrl($this));
        else
          return array();

    }

    public function restore()
    {
       do_action('shortpixel_before_restore_image', $this->get('id'));
       do_action('shortpixel/image/before_restore', $this);

       $bool = parent::restore();

       if ($bool)
        $this->saveMeta();

        return true;

    }

    // Placeholder function. I think this functionality was not available before
    public function isSizeExcluded()
    {
        return false;
    }

    public function handleOptimized($downloadResults)
    {
       $bool = parent::handleOptimized($downloadResults);

       if ($bool)
       {
         $this->setMeta('customImprovement', parent::getImprovement());
         $this->saveMeta();
       }


       return $bool;
    }

    public function loadMeta()
    {

      global $wpdb;

      $sql = 'SELECT * FROM '  . $wpdb->prefix . 'shortpixel_meta where id = %d';
      $sql = $wpdb->prepare($sql, $this->id);

      $imagerow = $wpdb->get_row($sql);

      if (! is_object($imagerow))
        return false;

      $this->in_db = true; // record found.

      $metaObj = new ImageMeta();

      $this->fullpath = $imagerow->path;
      $this->folder_id = $imagerow->folder_id;
      $this->path_md5 = $imagerow->path_md5;

      $status = intval($imagerow->status);
      $metaObj->status = $status;

      if ($status == ImageModel::FILE_STATUS_SUCCESS)
      {
        $metaObj->customImprovement = $imagerow->message;
      }


      $metaObj->compressedSize = intval($imagerow->compressed_size);
      $metaObj->compressionType = intval($imagerow->compression_type);

      if (! is_numeric($imagerow->message) && ! is_null($imagerow->message))
        $metaObj->errorMessage = $imagerow->message;

      $metaObj->did_keepExif = (intval($imagerow->keep_exif) == 1)  ? true : false;

      $metaObj->did_cmyk2rgb = (intval($imagerow->cmyk2rgb) == 1) ? true : false;

      $metaObj->resize = (intval($imagerow->resize) > 1) ? true : false;

      if (intval($imagerow->resize_width) > 0)
        $metaObj->resizeWidth = intval($imagerow->resize_width);

      if (intval($imagerow->resize_height) > 0)
        $metaObj->resizeHeight = intval($imagerow->resize_height);

        //$metaObj->has_backup = (intval($imagerow->backup) == 1) ? true : false;

        $addedDate = $this->DBtoTimestamp($imagerow->ts_added);
        $metaObj->tsAdded = $addedDate;

        $optimizedDate = $this->DBtoTimestamp($imagerow->ts_optimized);
        $metaObj->tsOptimized = $optimizedDate;

        $this->image_meta = $metaObj;
    }

    public function setStub(string $path, bool $load = true)
    {
       $this->fullpath = $path;
       $this->path_md5 = md5($this->fullpath);


       global $wpdb;

       $sql = 'SELECT id from '  . $wpdb->prefix . 'shortpixel_meta where path =  %s';
       $sql = $wpdb->prepare($sql, $path);

       $result = $wpdb->get_var($sql);
       if ( ! is_null($result)  )
       {
          $this->in_db = true;
          $this->id = $result;
          if ($load)
            $this->loadMeta();
       }
       else
       {
          $this->image_meta = new ImageMeta();
          $this->image_meta->compressedSize = 0;
          $this->image_meta->tsOptimized = 0;
          $this->image_meta->tsAdded = time();

       }

    }

    protected function preventNextTry($reason = '')
    {
        $this->setMeta('errorMessage', $reason);
        $this->setMeta('status', SELF::FILE_STATUS_PREVENT);
        $this->saveMeta();
    }

    public function isOptimizePrevented()
    {
         $status = $this->getMeta('status');

         if ($status == self::FILE_STATUS_PREVENT )
         {
            return $this->getMeta('errorMessage');
         }

         return false;
    }

    public function resetPrevent()
    {
        $this->setMeta('status', self::FILE_STATUS_UNPROCESSED);
        $this->setMeta('errorMessage', '');
        $this->saveMeta();
    }

    public function saveMeta()
    {
        global $wpdb;

       $table = $wpdb->prefix . 'shortpixel_meta';
       $where = array('id' => $this->id);

       $metaObj = $this->image_meta;

       if (! is_null($metaObj->customImprovement) && is_numeric($metaObj->customImprovement))
        $message = $metaObj->customImprovement;
       elseif (! is_null($metaObj->errorMessage))
        $message = $metaObj->errorMessage;
       else
        $message = null;

      $optimized = new \DateTime();
      $optimized->setTimestamp($metaObj->tsOptimized);

      $added = new \DateTime();
      $added->setTimeStamp($metaObj->tsAdded);

       $data = array(
            'folder_id' => $this->folder_id,
            'compressed_size' => $metaObj->compressedSize,
            'compression_type' => $metaObj->compressionType,
            'keep_exif' =>  ($metaObj->did_keepExif) ? 1 : 0,
            'cmyk2rgb' =>  ($metaObj->did_cmyk2rgb) ? 1 : 0,
            'resize' =>  ($metaObj->resize) ? 1 : 0,
            'resize_width' => $metaObj->resizeWidth,
            'resize_height' => $metaObj->resizeHeight,
            'backup' => ($this->hasBackup()) ? 1 : 0,
            'status' => $metaObj->status,
            'retries' => 0, // this is unused / legacy
            'message' => $message, // this is used for improvement line.
            'ts_added' => $this->timestampToDB($metaObj->tsAdded),
            'ts_optimized' => $this->timestampToDB($metaObj->tsOptimized),
            'path' => $this->getFullPath(),
            'path_md5' => md5($this->getFullPath()), // this is legacy
       );
       // The keys are just for readability.
       $format = array(
            'folder_id' => '%d',
            'compressed_size' => '%d',
            'compression_type' => '%d' ,
            'keep_exif' => '%d' ,
            'cmyk2rgb' => '%d' ,
            'resize' => '%d' ,
            'resize_width' => '%d',
            'resize_height' => '%d',
            'backup' => '%d',
            'status' => '%d',
            'retries' => '%d', // this is unused / legacy
            'message' => '%s', // this is used for improvement line.
            'ts_added' => '%s',
            'ts_optimized' => '%s' ,
            'path' => '%s',
            'path_md5' => '%s' , // this is legacy
       );


      // Log::addTemp('Save Custom Meta', $data);
      $is_new = false;

       if ($this->in_db)
      {
        $res = $wpdb->update($table, $data, $where, $format); // result is amount rows updated.
      }
      else
      {
        $is_new = true;
        $res = $wpdb->insert($table, $data, $format); // result is new inserted id
      }

      if ($is_new)
      {
         $this->id = $wpdb->insert_id;
      }

      if ($res !== false)
        return true;
      else
        return false;
    }

    public function getImprovement($int = false)
    {
       return $this->getMeta('customImprovement');
    }

    public function getImprovements()
    {
      $improvements = array();
      /*$totalsize = $totalperc = $count = 0;
      if ($this->isOptimized())
      {
         $perc = $this->getImprovement();
         $size = $this->getImprovement(true);
         $totalsize += $size;
         $totalperc += $perc;
         $improvements['main'] = array($perc, $size);
         $count++;
      } */
      $improvements['main'] = array($this->getImprovement(), 0);

      return $this->improvements;

    //  return $improvements; // we have no thumbnails.
    }

    private function timestampToDB($timestamp)
    {
        return date("Y-m-d H:i:s", $timestamp);
    }

    private function DBtoTimestamp($date)
    {
        return strtotime($date);
    }
}
