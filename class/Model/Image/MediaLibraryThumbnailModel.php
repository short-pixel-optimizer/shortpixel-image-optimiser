<?php

namespace ShortPixel\Model\Image;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


// Represent a thumbnail image / limited image in mediaLibrary.
class MediaLibraryThumbnailModel extends \ShortPixel\Model\Image\ImageModel
{
  //abstract protected function saveMeta();
  //abstract protected function loadMeta();
  protected $sizeName;

  protected function loadMeta()
  {

  }

  protected function saveMeta()
  {

  }

  public function __construct($path)
  {
      parent::__construct($path);
  }

  protected function setMetaObj($metaObj)
  {
     $this->meta = $metaObj;
  }

  protected function getMetaObj()
  {
    return $this->meta;
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
