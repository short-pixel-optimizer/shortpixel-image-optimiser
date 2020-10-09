<?php
namespace ShortPixel\Model\Image;


class ImageMeta extends ImageThumbnailMeta
{
  public $did_keepExif = false;
  public $did_cmyk2rgb = false;
  public $did_png2Jpg = false; // Was this replaced?
//  public $is_optimized = false; // if this is optimized
  public $is_png2jpg = false; // todo implement.

  public $resize;
  public $resizeWidth;
  public $resizeHeight;
  public $actualWidth;
  public $actualHeight;

  public $errorMessage;


} // class
