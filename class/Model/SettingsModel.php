<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class SettingsModel extends \ShortPixel\Model
{
		private static $instance;

		private $option_name = 'spio_settings';

		private $updated = false;

		protected $model = array(
//        'apiKey' => array('s' => 'string'), // string
//        'verifiedKey' => array('s' => 'int'), // string
        'compressionType' => ['s' => 'int', 'default' => 1], // int
        'resizeWidth' => ['s' => 'int' , 'default' => 0], // int
        'resizeHeight' => ['s' => 'int', 'default' => 0], // int
        'processThumbnails' => ['s' => 'boolean', 'default' => true], // checkbox
	'useSmartcrop' => ['s' => 'boolean', 'default' => false],
        'smartCropIgnoreSizes' => ['s' => 'boolean', 'default' => false],
        'backupImages' => ['s' => 'boolean', 'default' => true], // checkbox
    //    'keepExif' => ['s' => 'int', 'default' => 0], // checkbox
        'resizeImages' => ['s' => 'boolean', 'default' => false],
        'resizeType' => ['s' => 'string', 'default' => null],
        'includeNextGen' => ['s' => 'boolean', 'default' =>  false ], // checkbox
        'png2jpg' => ['s' => 'int', 'default' => 0], // checkbox
        'CMYKtoRGBconversion' => ['s' => 'boolean', 'default' => true], //checkbox
        'createWebp' => ['s' => 'boolean', 'default' => false], // checkbox
        'createAvif' => ['s' => 'boolean', 'default' => false],  // checkbox
        'deliverWebp' => ['s' => 'int', 'default' => 0], // checkbox
        'optimizeRetina' => ['s' => 'boolean', 'default' => false], // checkbox
        'optimizeUnlisted' => ['s' => 'boolean', 'default' => false], // checkbox
        'optimizePdfs' => ['s' => 'boolean', 'default' => true], //checkbox
        'excludePatterns' => ['s' => 'exception', 'default' => array()], //  - processed, multi-layer, so skip
        'siteAuthUser' => ['s' => 'string', 'default' => ''], // string
        'siteAuthPass' => ['s' => 'string', 'default' => ''], // string
        'autoMediaLibrary' => ['s' => 'boolean', 'default' => true], // checkbox
        'excludeSizes' => ['s' => 'array', 'default' => array()], // Array
        'cloudflareZoneID' => ['s' => 'string', 'default' => ''], // string
        'cloudflareToken' => ['s' => 'string', 'default' => ''],
				'doBackgroundProcess' => ['s' => 'boolean', 'default' => false], // checkbox
				'showCustomMedia' => ['s' => 'boolean', 'default' => true], // checkbox
				'mediaLibraryViewMode' => ['s' => 'int', 'default' => false], // set in installhelper
				'currentVersion' => ['s' => 'string', 'default' => null, 'export' => false], // last known version of plugin. Used for updating
				'hasCustomFolders' => ['s' => 'int', 'default' => false], // timestamp used for custom folders
				'quotaExceeded' => ['s' => 'int', 'default' => 0, 'export' => false], // indicator for quota
				'httpProto' => ['s' => 'string', 'default' => 'https'], // Less than optimal setting for using http(s)
				'downloadProto' => ['s' => 'string', 'default' => 'https'], // Less than optimal setting for using http(s) when Downloading
				'activationDate' => ['s' => 'int', 'default' => null, 'export' => false], // date of activation
				'unlistedCounter' => ['s' => 'int', 'default' => 0], // counter to prevent checking unlisted files too much
				'currentStats' => ['s' => 'array', 'default' => array(), 'export' => false], // whatever the current stats are.
        'currentVersion' => ['s' => 'string', 'default' => '', 'export' => false],
				'useCDN' => ['s' => 'boolean', 'default' => false],
				'cdn_css' => ['s' =>  'boolean', 'default' => false],
				'cdn_js' => ['s' => 'boolean', 'default' => false],
				'CDNDomain' => ['s' => 'string', 'default' => 'https://spcdn.shortpixel.ai/spio'],
        'redirectedSettings' => ['s' => 'int', 'default' => 0],
        'exif' => ['s' => 'int', 'default' => 1],
        'exif_ai' => ['s' => 'int', 'default' => 0],
        'cdn_purge_version' => ['s' => 'int', 'default' => 1, 'export' => false],
        'enable_ai' => ['s' => 'boolean', 'default' => true],
        'autoAI' => ['s' => 'boolean', 'default' => false],
        'autoAIBulk' => ['s' => 'boolean', 'default' => false],
        'aiPreserve' => ['s' => 'boolean', 'default' => false ],
        'ai_general_context' => ['s' => 'string', 'default' => 'callback', 'maxlength' => 500],
        'ai_use_post' => ['s' => 'boolean', 'default' => true],
        'ai_gen_alt' => ['s' => 'boolean', 'default' => true],
        'ai_gen_caption' => ['s' => 'boolean', 'default' => true],
        'ai_gen_description' => ['s' => 'boolean', 'default' => true],
        'ai_filename_prefercurrent' => ['s' => 'boolean', 'default' => false],
        'ai_limit_alt_chars' => ['s' => 'int', 'default' => 100, 'max' => 200],
        'ai_alt_context' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_limit_description_chars' => ['s' => 'int', 'default' => 200, 'max' => 500],
        'ai_description_context' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_limit_caption_chars' => ['s' => 'int', 'default' => 150, 'max' => 250],
        'ai_caption_context' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_gen_filename' => ['s' => 'boolean', 'default' => false],
        'ai_limit_filename_chars' => ['s' => 'int', 'default' => 30, 'max' => 200],
        'ai_filename_context' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_use_exif' => ['s' => 'boolean', 'default' => true],
        'ai_language' => ['s' => 'string', 'default' => 'callback'],
     
 

    );

  //  const EXIF_REMOVE = 0;
  //  const EXIF_KEEP = 1;

  //  const ALLOW_AI = 2;
  //  const DENY_AI = 2;

		private $settings;

		public function __construct()
		{
       $this->model['ai_general_context']['default'] = array($this, 'generateContextDefault');
       $this->model['ai_language']['default'] = array($this, 'returnSiteLanguage');

			 $this->load();
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
       $this->settings = $this->check(get_option($this->option_name, []));

       if (false === function_exists('register_shutdown_function'))
       {
          Log::addError('Register shutdown function not found!');
       }
       else
       {
          register_shutdown_function([$this, 'onShutdown']);
       }

       // This is done dual since it seems that -sometimes- for reasons unknown the PHP solution doesn't work. 
       add_action('shutdown', [$this, 'onShutdown']);
			 
		}

		protected function save()
		{
				$res = update_option($this->option_name, $this->settings);
        $this->updated = false; // Prevent double saves with this.
		}

		public function __get($name)
		{
			 if (isset($this->settings[$name]))
			 {
				  return $this->sanitize($name, $this->settings[$name]);
			 }
       elseif (isset($this->model[$name]))
       {
          if (isset($this->model[$name]['default']))
          {
              $default = $this->model[$name]['default']; 
              if (is_array($default))
              {
                  if (is_callable($default))                 
                  {
                    return call_user_func($default);
                  }
              }
              else
              {
                return $default; 
              }

          }

       }
			 else {
			 	Log::addWarn('Call for non-existing setting: ' . $name);
			 }
		}

    protected function generateContextDefault()
    {
       $site_title = get_bloginfo('name'); 
       $wp_url = get_bloginfo('url');

       $string = sprintf('Act like an SEO expert and generate an SEO-friendly ALT tag, caption, and description for the images from %s, titled %s, focusing on keywords and relevance for optimal image SEO.', $wp_url, $site_title);
       return $string;
    }

    protected function returnSiteLanguage()
    {
       return get_locale();
    }

    // This function is meant for version checks ( settings removed / added ) and filter overrides for specific use-cases.
    protected function check($settings)
    {
        if (isset($settings['keepExif']))
        {
          //Notices::addNormal('Dont forget about keepexif');
           $this->set('exif',$settings['keepExif'] );
           unset($settings['keepExif']);
        }

        $settings = apply_filters('shortpixel/settings/check', $settings);
        return $settings;
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
			  return (isset($this->model[$name])) ? true : false;
		}

		public function isset($name)
		{
			return (isset($this->settings[$name])) ? true : false;

		}

    /** Check if this entry in settings should be in import / export function . Some are internal / site only .
     * 
     * @param string $name 
     * @return bool 
     */
    public function forExport($name)
    {
       if (false === $this->exists($name))
       {
         return false; 
       }

       if (isset($this->model[$name]['export']))
       {
          return $this->model[$name]['export'];
       }

       return true; // if no rules, ok .

    }

    public function getExport()
    {
        $data = $this->getData(); 
        $export = []; 
        foreach($data as $name => $value)
        {
           if (false === $this->forExport($name))
           {
             continue; 
           }
           $export[$name] = $value; 
        }

        return $export;
    }


		public function deleteOption($name)
		{
				if ($this->exists($name) && $this->isset($name))
				{
					 unset($this->settings[$name]);
					 $this->save();
				}
		}


    public function deleteAll()
    {
        delete_option($this->option_name);
    }

    /**
     * PHP shutdown function, check if settings are updated and save on closing time.
     * @return null
     *
     *  Note: This is public instead of protected /private because of bug in PHP 7.4 not liking that.
     */
		public function onShutdown()
		{
				if (true === $this->updated)
				{
						$this->save();

				}
		}

} // class

