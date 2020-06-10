<?php
namespace ShortPixel\Model\Image;

class ImageThumbnailMeta
{
  public $status = 0;
  public $compressionType;
  public $compressedSize;
  public $improvement;

  public $tsAdded;
  public $tsOptimized;

  public $did_keepExif = false;
  public $did_cmyk2rgb = false;
  public $did_png2Jpg = false;
  public $is_optimized = false; // if this is optimized
  public $is_png2jpg = false; // todo implement.
  public $has_backup;

}

class ImageMeta extends ImageThumbnailMeta
{

  public $resize;
  public $resizeWidth;
  public $resizeHeight;
  public $actualWidth;
  public $actualHeight;

} // class
