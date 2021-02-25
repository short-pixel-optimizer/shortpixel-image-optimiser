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

    public function __construct(int $id)
    {
        $this->id = $id;

        if ($id > 0)
          $this->loadMeta();

        parent::__construct($this->fullpath);
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
        $url = $fs->pathToUrl($this);

        if ($this->isProcessable())
          return array($url);

        return array();
    }

    public function restore()
    {
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
         $this->setMeta('customImprovement', $this->getImprovement());
         $this->saveMeta();
       }


       return $bool;
    }

    public function loadMeta()
    {
      $metadao = \wpSPIO()->getShortPixel()->getSpMetaDao();
      $imagerow = $metadao->getItem($this->id);

      if (count($imagerow) > 0)
        $imagerow = $imagerow[0];
      else
        return false;

      $metaObj = new ImageMeta();

      $this->fullpath = $imagerow->path;
      $this->folder_id = $imagerow->folder_id;
      $this->path_md5 = $imagerow->path_md5;

      $status = intval($imagerow->status);
      $metaObj->status = $status;

      if ($status == ImageModel::FILE_STATUS_SUCCESS)
      {
        $metaObj->customImprovement = $imagerow->message;
        $optimizedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $imagerow->ts_optimized);
        $metaObj->tsOptimized = $optimizedDate->getTimestamp();
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

        $addedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $imagerow->ts_added);
        $metaObj->tsAdded = $addedDate->getTimestamp();

        $optimizedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $imagerow->ts_optimized);
        $metaObj->tsOptimized = $optimizedDate->getTimestamp();


        $this->image_meta = $metaObj;
    }

    public function setStub(string $path)
    {
       $this->fullpath = $path;
       $this->path_md5 = md5($this->fullpath);
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
            'message' => $message,
            'ts_added' => $added->format('Y-m-d H:i:s'),
            'ts_optimized' => $optimized->format('Y-m-d H:i:s'),
       );
Log::addDebug('Save Custom Meta', $data);
       $format = array(
            '%d', '%d', '%d','%d','%d','%d','%d','%d','%d', '%d',  '%s','%s', '%s',
       );

       $res = $wpdb->update($table, $data, $where, $format);

      if ($res !== false)
        return true;
      else
        return false;
    }




    public function getImprovements()
    {
      return array(); // we have no thumbnails.
    }
}
