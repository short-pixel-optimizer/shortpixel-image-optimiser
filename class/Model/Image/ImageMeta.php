<?php
namespace ShortPixel\Model\Image;


class ImageMeta extends ImageThumbnailMeta
{
  public $did_keepExif;
  public $did_cmyk2rgb;
  public $did_png2jpg; // Was this replaced?
//  public $is_optimized = false; // if this is optimized
//  public $is_png2jpg = false; // todo implement.

  public $errorMessage;

  public $wasConverted = false;


} // class
