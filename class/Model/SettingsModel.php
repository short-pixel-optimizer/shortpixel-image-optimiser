<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class SettingsModel
{
		protected static $instance;

		private $option_name = 'spio_settings';

		private $state_name = 'spio_states';


		protected $model = array(
        'apiKey' => array('s' => 'string'), // string
        'verifiedKey' => array('s' => 'int'), // string
        'compressionType' => array('s' => 'int'), // int
        'resizeWidth' => array('s' => 'int'), // int
        'resizeHeight' => array('s' => 'int'), // int
        'processThumbnails' => array('s' => 'boolean'), // checkbox
				'useSmartcrop' => array('s' => 'boolean'),
        'backupImages' => array('s' => 'boolean'), // checkbox
        'keepExif' => array('s' => 'int'), // checkbox
        'resizeImages' => array('s' => 'boolean'),
        'resizeType' => array('s' => 'string'),
        'includeNextGen' => array('s' => 'boolean'), // checkbox
        'png2jpg' => array('s' => 'int'), // checkbox
        'CMYKtoRGBconversion' => array('s' => 'boolean'), //checkbox
        'createWebp' => array('s' => 'boolean'), // checkbox
        'createAvif' => array('s' => 'boolean'),  // checkbox
        'deliverWebp' => array('s' => 'int'), // checkbox
        'optimizeRetina' => array('s' => 'boolean'), // checkbox
        'optimizeUnlisted' => array('s' => 'boolean'), // $checkbox
        'optimizePdfs' => array('s' => 'boolean'), //checkbox
        'excludePatterns' => array('s' => 'exception'), //  - processed, multi-layer, so skip
        'siteAuthUser' => array('s' => 'string'), // string
        'siteAuthPass' => array('s' => 'string'), // string
        'frontBootstrap' => array('s' =>'boolean'), // checkbox
        'autoMediaLibrary' => array('s' => 'boolean'), // checkbox
        'excludeSizes' => array('s' => 'array'), // Array
        'cloudflareEmail' => array('s' => 'string'), // string
        'cloudflareAuthKey' => array('s' => 'string'), // string
        'cloudflareZoneID' => array('s' => 'string'), // string
        'cloudflareToken' => array('s' => 'string'),
        'savedSpace' => array('s' => 'skip'),
        'fileCount' => array('s' => 'skip'), // int
        'under5Percent' => array('s' => 'skip'), // int
    );

		protected $settings;
	//	protected $states;

		public function __construct()
		{
			 //$this->checkLegacy();
			 $this->load();

		}

		public static function getInstance()
		{
			 if (is_null(self::$instance))
			 {
					self::$instance = new SettingsModel;
			 }
			 return self::$instance;
		}

		protected function load()
		{
			 $settings = get_option($this->option_name);
		}

		protected function save()
		{
				update_option($this->option_name, $this->settings);
		}

		public function __get($name)
		{
			 if (isset($this->settings[$name]))
			 {
				  return $this->sanitize($name, $this->settings[$name]);
			 }
		}

    public function __set($name, $value)
    {
      $this->set($name, $value);
    }

    protected function set($name, $value)
    {
      if (isset($this->model[$name]))
      {
        $this->settings[$name] =  $this->sanitize($name, $value);
      }
      else {
         Log::addWarn('Setting ' . $name . ' not defined in settingsModel');
      }
    }

    public function setIfEmpty($name, $value)
    {
        if (! isset($this->settings[$name]))
        {
           $this->set($name, $value);
        }
    }

  //  public static function 



} // class
