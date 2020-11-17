<?php
namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class CustomImageModel extends \ShortPixel\Model\Image\ImageModel
{

    protected $folder_id;
    protected $path_md5;

    protected $type = 'custom';


    public function __construct($id)
    {
        $this->id = $id;

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
        $fs = \wpSPIO()->filesytem();
        $url = $fs->pathToUrl($this);

        return array($url);
    }

    // Placeholder function. I think this functionality was not available before
    public function isSizeExcluded()
    {
        return false;
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
        $metaObj->improvement = $imagerow->message;
        $optimizedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $imagerow->ts_optimized);
        $metaObj->tsOptimized = $optimizedDate->getTimestamp();
      }

      $metaObj->compressedSize = intval($imagerow->compressed_size);
      $metaObj->compressionType = intval($imagerow->compression_type);

      if (! is_numeric($imagerow->message))
        $this->error_message = $imagerow->message;


      if (intval($imagerow->keep_exif) == 1)
        $metaObj->did_keepExif = true;

      if(intval($imagerow->cmyk2rgb) == 1)
        $metaObj->did_cmyk2rgb = true;

      if (intval($imagerow->resize) > 1)
        $metaObj->resize = true;

      if (intval($imagerow->resize_width) > 0)
        $metaObj->resizeWidth = intval($imagerow->resize_width);

      if (intval($imagerow->resize_height) > 0)
        $metaObj->resizeHeight = intval($imagerow->resize_height);

      if (intval($imagerow->backup) == 1)
        $metaObj->has_backup = true;

        $addedDate = \DateTime::createFromFormat('Y-m-d H:i:s', $imagerow->ts_added);
        $metaObj->tsAdded = $addedDate->getTimestamp();

        $this->image_meta = $metaObj;
    }

    // @todo
    public function saveMeta()
    {

    }

}
