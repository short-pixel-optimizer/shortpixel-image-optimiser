<?php

namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


// Represent a thumbnail image / limited image in mediaLibrary.
class MediaLibraryThumbnailModel extends \ShortPixel\Model\Image\ImageModel
{
  //abstract protected function saveMeta();
  //abstract protected function loadMeta();

  public $name;
  public $width;
  public $height;
  public $mime;

  public function __construct($path)
  {
        parent::__construct($path);
        $this->image_meta = new ImageThumbnailMeta();
  }


  protected function loadMeta()
  {

  }

  protected function saveMeta()
  {

  }

  /** Set the meta name of thumbnail. */
  public function setName($name)
  {
     $this->sizeName = $name;
  }

  protected function setMetaObj($metaObj)
  {
     $this->image_meta = $metaObj;
  }

  protected function getMetaObj()
  {
    return $this->image_meta;
  }

  public function getOptimizePaths()
  {
    return array($this->getFullPath());
  }

  public function getOptimizeUrls()
  {
    // return $url
  }

  // !Important . This doubles as  checking excluded image sizes.
  protected function isSizeExcluded()
  {
    return false;
  }


} // class
