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

  private $settings;

  public function __construct()
  {
      // Ensure the parent model fields are available and add network-only options.
      $this->model = array_merge($this->model, [
          'disable_site_settings_page' => ['s' => 'boolean', 'default' => false],
      ]);

      parent::__construct();
  }

  public static function getInstance()
  {
      if (is_null(self::$instance))
      {
          self::$instance = new static();
      }
      return self::$instance;
  }

  protected function load()
  {
     $this->settings = get_site_option($this->option_name, array());
     if (! is_array($this->settings)) {
         $this->settings = array();
     }

     if (false === function_exists('register_shutdown_function'))
     {
        Log::addError('Register shutdown function not found!');
     }
     else
     {
        register_shutdown_function([$this, 'onShutdown']);
     }
  }

  protected function save()
  {
       update_site_option($this->option_name, $this->settings);
       $this->updated = false;
  }

} // class
