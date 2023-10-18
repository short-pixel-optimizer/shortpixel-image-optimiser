<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

// Image gallery plugins that require a few small extra's
class ImageGalleries
{
  public function __construct()
  {
      add_action('admin_init', array($this, 'addConstants'));
      add_filter('shortpixel/init/optimize_on_screens', array($this, 'add_screen_loads'));
  }

  // This adds constants for mentioned plugins checking for specific suffixes on addUnlistedImages.
	// @integration Envira Gallery
	// @integration Soliloquy
  public function addConstants()
  {
    //if( !defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES')) {
        if(\is_plugin_active('envira-gallery/envira-gallery.php') ||
					 \is_plugin_active('soliloquy-lite/soliloquy-lite.php') ||
					 \is_plugin_active('soliloquy/soliloquy.php') ||
					 \is_plugin_active('envira-gallery-lite/envira-gallery-lite.php')
			 )
		{

						add_filter('shortpixel/image/unlisted_suffixes', array($this, 'envira_suffixes'));
            //define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', '_c,_tl,_tr,_br,_bl');
    //    }

		// not in use?
    //    elseif(defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIX')) {
    //        define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', SHORTPIXEL_CUSTOM_THUMB_SUFFIX);
    //    }
    }

  }

  public function add_screen_loads($screens)
  {

     // Envira Gallery Lite
     $screens[] = 'edit-envira';
     $screens[] = 'envira';

     // Solo Cuy 
     $screens[] = 'edit-soliloquy';
     $screens[] = 'soliloquy';
     return $screens;
  }

	public function envira_suffixes($suffixes)
	{

		 $envira_suffixes = array('_c','_tl','_tr','_br','_bl', '-\d+x\d+');
		 $suffixes = array_merge($suffixes, $envira_suffixes);

		 return $suffixes;
	}



} // class
$c = new ImageGalleries();
