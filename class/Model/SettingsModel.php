<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Persisted plugin settings model.
 *
 * Every user-configurable field the plugin exposes is declared in the
 * $model array (inherited from {@see \ShortPixel\Model}) with its
 * sanitisation type, default value, optional max / maxlength constraints,
 * and an "export" flag that controls import/export inclusion.
 *
 * Settings are lazy-loaded from a single options row ("spio_settings"),
 * read via the magic __get accessor, mutated via __set (which sanitises
 * the value and marks the model dirty), and persisted on request shutdown
 * so many sets during a single request only cost one DB write.
 *
 * Access the singleton via wpSPIO()->settings().
 *
 * @package ShortPixel\Model
 */
class SettingsModel extends \ShortPixel\Model
{
		private static $instance;

		protected $option_name = 'spio_settings';

		protected $updated = false;

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
        'singleFileBackup' => ['s' => 'boolean', 'default' => false],
        'autoRemoveBackups' => ['s' => 'boolean', 'default' => false], 
        'autoRemoveBackupsPeriod' => ['s' => 'string', 'default' => null],
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
        // Controls how generated AI data is written back into post content:
        // - 'none'    : never modify post content (Media-Library-only)
        // - 'missing' : only fill in empty/missing in-content alt/caption (safe default)
        // - 'overwrite': overwrite existing in-content alt/caption
        'ai_content_replace' => ['s' => 'string', 'default' => 'missing'],
        'ai_general_context' => ['s' => 'string', 'default' => 'callback', 'maxlength' => 500],
        'ai_use_post' => ['s' => 'boolean', 'default' => true],
        'ai_gen_alt' => ['s' => 'boolean', 'default' => true],
        'ai_gen_caption' => ['s' => 'boolean', 'default' => true],
        'ai_gen_description' => ['s' => 'boolean', 'default' => true],
        'ai_gen_post_title' => ['s' => 'boolean', 'default' => true], 
        'ai_limit_alt_chars' => ['s' => 'int', 'default' => 100, 'max' => 200],
        'ai_alt_context' => ['s' => 'string', 'default' => '', 'maxlength' => 500],
        'ai_alt_prefix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_alt_postfix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_limit_description_chars' => ['s' => 'int', 'default' => 200, 'max' => 500],
        'ai_description_context' => ['s' => 'string', 'default' => '', 'maxlength' => 500],
        'ai_description_prefix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_description_postfix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_limit_caption_chars' => ['s' => 'int', 'default' => 150, 'max' => 250],
        'ai_caption_context' => ['s' => 'string', 'default' => '', 'maxlength' => 500],
        'ai_caption_prefix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_caption_postfix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_post_title_context' => ['s' => 'string',  'default' => '', 'maxlength' => 500], 
        'ai_post_title_prefix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_post_title_postfix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_limit_post_title_chars' => ['s' => 'string', 'default' => 50, 'max' => 100],
        'ai_gen_filename' => ['s' => 'boolean', 'default' => false],
        'ai_limit_filename_chars' => ['s' => 'int', 'default' => 30, 'max' => 200],
        'ai_filename_context' => ['s' => 'string', 'default' => '', 'maxlength' => 500],
        'ai_filename_prefix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_filename_postfix' => ['s' => 'string', 'default' => '', 'maxlength' => 200],
        'ai_filename_prefercurrent' => ['s' => 'boolean', 'default' => false],
        'ai_filename_addsymlink' => ['s' => 'boolean', 'default' => true], 
        'ai_symlink_checked' => ['s' => 'boolean', 'default' => false], 
        'ai_use_exif' => ['s' => 'boolean', 'default' => true],
        'ai_language' => ['s' => 'string', 'default' => 'callback'],
    );

  //  const EXIF_REMOVE = 0;
  //  const EXIF_KEEP = 1;

  //  const ALLOW_AI = 2;
  //  const DENY_AI = 2;

		protected $settings;

		/** @var \ShortPixel\Model\MultiSettingsModel|null Cached network settings model when multisite overrides are enabled. */
		protected $networkSettingsModel = null;

		/**
		 * Wires late-bound defaults for AI settings (which depend on the
		 * current site's URL, name and locale, so they can't be baked into the
		 * model array declaration) and loads the persisted values.
		 */
		public function __construct()
		{
       $this->model['ai_general_context']['default'] = array($this, 'generateContextDefault');
       $this->model['ai_language']['default'] = array($this, 'returnSiteLanguage');

			 $this->load();
		}

		/**
		 * Returns the singleton instance, creating it on first access.
		 *
		 * @return static
		 */
		public static function getInstance()
		{
			 if (is_null(self::$instance))
			 {
					self::$instance = new static();
			 }
			 return self::$instance;
		}

		/**
		 * Reads the persisted settings row into memory and registers the
		 * shutdown hooks that persist any in-memory changes at request end.
		 *
		 * The save is deferred to shutdown so multiple sets during a single
		 * request only cost one DB write. Both PHP's register_shutdown_function
		 * and WordPress's "shutdown" action are used because the PHP-level
		 * hook is occasionally observed not to fire.
		 *
		 * @return void
		 */
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

		/**
		 * Persists the current in-memory settings to the WordPress options
		 * table and clears the dirty flag so a subsequent shutdown does not
		 * double-save.
		 *
		 * @return void
		 */
		protected function save()
		{
				$res = update_option($this->option_name, $this->settings);
        $this->updated = false; // Prevent double saves with this.
		}

		/**
		 * Magic getter — returns a setting value by name, sanitised on read.
		 *
		 * When multisite network overrides are enabled (see
		 * isNetworkOverrideEnabled()), a network-provided value takes precedence
		 * over the per-site stored value. Otherwise, when the setting has not
		 * been explicitly stored, falls back to the model's declared default.
		 * Callable defaults are invoked so late-binding values (e.g. current
		 * locale) resolve at read time. Emits a log warning for unknown setting
		 * names.
		 *
		 * @param string $name Setting name.
		 * @return mixed|null Sanitised setting value, its default, or null when
		 *                    the setting is not part of the model.
		 */
		public function __get($name)
		{
			 if ($this->isNetworkOverrideEnabled())
			 {
				 $network_value = $this->getNetworkSettingValue($name);
				 if (null !== $network_value)
				 {
					 return $network_value;
				 }
			 }

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
              return $default; 

          }

       }
			 else {
			 	Log::addWarn('Call for non-existing setting: ' . $name);
			 }
		}

    /**
     * Late-bound default for the ai_general_context field.
     *
     * Builds a sensible starting-point prompt that includes the current
     * site's URL and title so an operator has a reasonable initial value
     * on a fresh install.
     *
     * @return string
     */
    protected function generateContextDefault()
    {
       $site_title = get_bloginfo('name');
       $wp_url = get_bloginfo('url');

       $string = sprintf('Act like an SEO expert and generate an SEO-friendly ALT tag, caption, and description for the images from %s, titled %s, focusing on keywords and relevance for optimal image SEO.', $wp_url, $site_title);
       return $string;
    }

    /**
     * Late-bound default for the ai_language field.
     *
     * Returns the site's current WordPress locale so AI-generated content
     * defaults to the same language as the site.
     *
     * @return string Locale string, e.g. "en_US".
     */
    protected function returnSiteLanguage()
    {
       return get_locale();
    }

    /**
     * Applies version-migration and filter overrides to the persisted
     * settings array on load.
     *
     * Currently handles the "keepExif" → "exif" rename (from the legacy
     * setting name) and dispatches the "shortpixel/settings/check" filter
     * so integrations can override values.
     *
     * @param array $settings Raw settings array as returned from get_option().
     * @return array Possibly-adjusted settings array.
     */
    protected function check($settings)
    {
        if (isset($settings['keepExif']))
        {
          //Notices::addNormal('Dont forget about keepexif');
           $this->set('exif',$settings['keepExif'] );
           $settings['exif'] = $settings['keepExif'];
           unset($settings['keepExif']);
        }

        $settings = apply_filters('shortpixel/settings/check', $settings);
        return $settings;
    }

    /**
     * Magic setter — stores a setting value by name.
     *
     * Delegates to the internal set() method so validation and the
     * "updated" flag stay in one place.
     *
     * @param string $name  Setting name.
     * @param mixed  $value New value (will be sanitised per model rules).
     * @return void
     */
    public function __set($name, $value)
    {
      $this->set($name, $value);
    }

    /**
     * Sanitises and stores a setting value, marking the model dirty so the
     * shutdown hook will persist it.
     *
     * No-op with a logged warning when the setting name is not part of the
     * model.
     *
     * @param string $name  Setting name.
     * @param mixed  $value Raw value.
     * @return void
     */
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

    /**
     * Sets a setting only when it has not been explicitly stored yet.
     *
     * Useful during install/upgrade to establish a starting value without
     * overwriting a user's existing choice.
     *
     * @param string $name  Setting name.
     * @param mixed  $value Value to store when the setting is empty.
     * @return bool True when the value was written, false when the setting
     *              was already present or is not part of the model.
     */
    public function setIfEmpty($name, $value)
    {
        if (true === $this->exists($name) && false === $this->isset($name))
        {
           $this->set($name, $value);
					 return true;
        }

				return false;
    }

		/**
		 * Reports whether a name is declared in the model.
		 *
		 * @param string $name Setting name.
		 * @return bool
		 */
		public function exists($name)
		{
			  return (isset($this->model[$name])) ? true : false;
		}

		/**
		 * Reports whether a setting has been explicitly stored (as opposed to
		 * merely having a default). Also true when an enabled multisite network
		 * override provides a value for the setting.
		 *
		 * @param string $name Setting name.
		 * @return bool
		 */
		public function isset($name)
		{
			if ($this->isNetworkOverrideEnabled())
			{
				$network_value = $this->getNetworkSettingValue($name);
				if (null !== $network_value)
				{
					return true;
				}
			}

			return (isset($this->settings[$name])) ? true : false;

		}

		/**
		 * Returns the network settings model when multisite overrides are active.
		 *
		 * @return \ShortPixel\Model\MultiSettingsModel|null
		 */
		protected function getNetworkSettingsModel()
		{
			if (is_null($this->networkSettingsModel) && function_exists('is_multisite') && is_multisite())
			{
				if (class_exists('\ShortPixel\Model\MultiSettingsModel'))
				{
					$this->networkSettingsModel = \ShortPixel\Model\MultiSettingsModel::getInstance();
				}
			}

			return $this->networkSettingsModel;
		}

		/**
		 * Returns a network-scope setting value when network override mode is enabled.
		 *
		 * @param string $name Setting name.
		 * @return mixed|null
		 */
		protected function getNetworkSettingValue($name)
		{
			$network_model = $this->getNetworkSettingsModel();
			if (! is_object($network_model) || ! method_exists($network_model, 'exists'))
			{
				return null;
			}

			if ($network_model->exists($name))
			{
				return $network_model->{$name};
			}

			return null;
		}

		/**
		 * Reports whether network-wide settings should override the per-site values.
		 *
		 * @return bool
		 */
		public function isNetworkOverrideEnabled()
		{
			$network_model = $this->getNetworkSettingsModel();
			if (! is_object($network_model) || ! method_exists($network_model, 'exists'))
			{
				return false;
			}

			if (! $network_model->exists('network_settings_override_enabled'))
			{
				return false;
			}

			return (bool) $network_model->network_settings_override_enabled;
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

    /**
     * Returns the subset of settings that are safe to export or import — i.e.
     * settings whose model entry does not carry `'export' => false`.
     *
     * Site-specific runtime fields such as currentStats, quotaExceeded and
     * activationDate are flagged as non-exportable so import files stay
     * portable between sites.
     *
     * @return array<string, mixed>
     */
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


		/**
		 * Removes a single setting from the persisted options and writes the
		 * change back to the DB immediately.
		 *
		 * @param string $name Setting name.
		 * @return void
		 */
		public function deleteOption($name)
		{
				if ($this->exists($name) && $this->isset($name))
				{
					 unset($this->settings[$name]);
					 $this->save();
				}
		}

    /**
     * Removes the plugin's entire settings option row from the database and
     * clears the dirty flag so no shutdown hook rewrites it.
     *
     * Used during the "hard uninstall" flow.
     *
     * @return void
     */
    public function deleteAll()
    {
        delete_option($this->option_name);
        $this->updated = false; // prevent any save request going here.
    }

    /**
     * Records legacy activation state for backward compatibility with older
     * plugin versions.
     *
     * Called from InstallHelper::activatePlugin(). Stores the activation
     * date under the old option name and clears an unused legacy counter.
     *
     * @return void
     */
    public function onActivate()
    {
      // Legacy
      update_option( 'wp-short-pixel-activation-date', time(), 'no');
      delete_option( 'wp-short-pixel-current-total-files');
    }

    /**
     * Deletes the collection of legacy option rows that older plugin
     * versions used to track bulk-processing, notices and stats state.
     *
     * Called from InstallHelper::deactivatePlugin() so the WP options table
     * is kept clean when the plugin is disabled.
     *
     * @return void
     */
    public function onDeactivate()
    {
        delete_option('wp-short-pixel-activation-notice');
				delete_option('wp-short-pixel-bulk-last-status'); // legacy shizzle
				delete_option('wp-short-pixel-current-total-files');
				delete_option('wp-short-pixel-remove-settings-on-delete-plugin');

				// Bulk State machine legacy
				$bulkLegacyOptions = array(
						'wp-short-pixel-bulk-type',
						'wp-short-pixel-bulk-last-status',
						'wp-short-pixel-query-id-start',
						'wp-short-pixel-query-id-stop',
						'wp-short-pixel-bulk-count',
						'wp-short-pixel-bulk-previous-percent',
						'wp-short-pixel-bulk-processed-items',
						'wp-short-pixel-bulk-done-count',
						'wp-short-pixel-last-bulk-start-time',
						'wp-short-pixel-last-bulk-success-time',
						'wp-short-pixel-bulk-running-time',
						'wp-short-pixel-cancel-pointer',
						'wp-short-pixel-skip-to-custom',
						'wp-short-pixel-bulk-ever-ran',
						'wp-short-pixel-flag-id',
						'wp-short-pixel-failed-imgs',
						'bulkProcessingStatus',
						'wp-short-pixel-prioritySkip',
				);

				$removedStats = array(
						'wp-short-pixel-helpscout-optin',
						'wp-short-pixel-activation-notice',
						'wp-short-pixel-dismissed-notices',
						'wp-short-pixel-media-alert',
				);

				$removedOptions = array(
						'wp-short-pixel-remove-settings-on-delete-plugin',
						'wp-short-pixel-custom-bulk-paused',
						'wp-short-pixel-last-back-action',
						'wp-short-pixel-front-bootstrap',
				);

        // Settings completely removed during the settings redo
        $settingsRevamp = [
          'wp-short-pixel-cloudflareAPIEmail',
          'wp-short-pixel-cloudflareAuthKey',
          'wp-short-pixel-front-bootstrap',
					'wp-short-pixel-api-retries',
					'wp-short-pixel-total-optimized',
					'wp-short-pixel-total-original',
					'wp-short-pixel-download-archive',
					'wp-short-pixel-converted-png2jpg',
          'wp-short-pixel-savedSpace',
          'wp-short-pixel-fileCount',
          'wp-short-pixel-files-under-5-percent',
        ];

				$toRemove = array_merge($bulkLegacyOptions, $removedStats, $removedOptions, $settingsRevamp);

				foreach($toRemove as $option)
				{
					 delete_option($option);
				}
    
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

