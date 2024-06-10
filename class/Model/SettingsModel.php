<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class SettingsModel extends \ShortPixel\Model
{
		protected static $instance;

		private $option_name = 'spio_settings';

		private $state_name = 'spio_states';

		private $updated = false;


		protected $model = array(
//        'apiKey' => array('s' => 'string'), // string
//        'verifiedKey' => array('s' => 'int'), // string
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
				'doBackgroundProcess' => array('s' => 'boolean'), // checkbox'
				'showCustomMedia' => array('s' => 'boolean'),
				'mediaLibraryViewMode' => array('s' => 'int'), // set in installhelper
				'currentVersion' => array('s' => 'string'), // last known version of plugin. Used for updating
				'hasCustomFolders' => array('s' => 'int'), // timestamp used for custom folders
				'quotaExceeded' => array('s' => 'int'), // indicator for quota
				'httpProto' => array('s' => 'string'), // Less than optimal setting for using http(s)
				'downloadProto' => array('s' => 'string'), // Less than optimal setting for using http(s) when Downloading
				'activationDate' => array('s' => 'int'), // date of activation
				'redirectedSettings' => array('s' => 'int'), // controls initial redirect to SPIO settings
				'unlistedCounter' => array('s' => 'int'), // counter to prevent checking unlisted files too much
				'currentStats' => array('s' => 'array'), // whatever the current stats are.


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
			 $this->settings = get_option($this->option_name);
			 register_shutdown_function(array($this, 'onShutdown'));
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
				$this->updated = true;
      }
      else {
         Log::addWarn('Setting ' . $name . ' not defined in settingsModel');
      }
    }

    public function setIfEmpty($name, $value)
    {
        if (true === $this->exists($name) && false === $this->isset($name))
        {
           $this->set($name, $value);
					 return true;
        }

				return false;
    }

		// Simple function which can be expanded.
		public function exists($name)
		{
			  return (isset($this->module[$name])) ? true : false;
		}

		public function isset($name)
		{
			return (isset($this->settings[$name])) ? true : false;

		}

		public function deleteOption($name)
		{
				if ($this->exists($name) && $this->isset($name))
				{
					 unset($this->settings[$name]);
					 $this->save();
				}
		}

		private function onShutdown()
		{
				if (true === $this->updated)
				{
						Log::addTemp('Saving Settings');
						$this->save();
				}

		}


  //  public static function



} // class
