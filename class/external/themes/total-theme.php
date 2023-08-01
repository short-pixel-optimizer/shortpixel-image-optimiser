<?php
namespace ShortPixel\External\Themes;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class TotalTheme
{

  public function __construct()
  {
//    do_action( 'totaltheme/resize-image/after_save_image', $attachment, $intermediate_size );
    add_action( 'totaltheme/resize-image/after_save_image', array($this, 'resizeImage'), 10, 2);
  }

  public function resizeImage($attachment_id, $size)
  {
    $image = \wpSPIO()->filesystem()->getMediaImage($attachment_id);

    if (! is_object($image))
    {
      return;
    }

    $changes = false;
    $thumbObj = $image->getThumbnail($size);
    if (is_object($thumbObj))
    {
      $thumbObj->onDelete(true);
      $changes = true;
    }
    else {
    }

    if ( true === $changes)
    {
      $image->saveMeta();
    }

}

} // class

$t = new TotalTheme();
