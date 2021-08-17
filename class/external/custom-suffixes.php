<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

class CustomSuffixes
{
  public function __construct()
  {
      add_action('admin_init', array($this, 'addConstants'));
  }

  // This adds constants for mentioned plugins checking for specific suffixes on addUnlistedImages.
	// @integration Envira Gallery
	// @integration Soliloquy
  public function addConstants()
  {
    if( !defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES')) {
        if(\is_plugin_active('envira-gallery/envira-gallery.php') || \is_plugin_active('soliloquy-lite/soliloquy-lite.php') || \is_plugin_active('soliloquy/soliloquy.php')) {
            define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', '_c,_tl,_tr,_br,_bl');
        }
        elseif(defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIX')) {
            define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', SHORTPIXEL_CUSTOM_THUMB_SUFFIX);
        }
    }
  }

} // class
$c = new CustomSuffixes();
