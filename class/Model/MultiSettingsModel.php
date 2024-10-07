<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class MultiSettingsModel extends \ShortPixel\Model\SettingsModel
{

  private static $instance;
  private $option_name = 'spio_wpmu';
  private $updated = false;


  protected $model = [

  ];


  private $settings;


  protected function load()
  {
     $this->settings = get_site_option($this->option_name, array());
     register_shutdown_function(array($this, 'onShutdown'));
  }


} // class
