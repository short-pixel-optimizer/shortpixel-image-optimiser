<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;
use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Helper\InstallHelper as InstallHelper;

use ShortPixel\Model\AccessModel as AccessModel;
use ShortPixel\Model\SettingsModel as SettingsModel;
use ShortPixel\Model\ApiKeyModel as ApiKeyModel;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Controller\StatsController as StatsController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\QueueController as QueueController;

use ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\View\BulkViewController as BulkViewController;
use ShortPixel\External\Offload\Offloader;
use ShortPixel\Model\AiDataModel;
use ShortPixel\NextGenController as NextGenController;

/**
 * View controller for the ShortPixel Settings admin page.
 *
 * Renders the settings screen (options-general.php?page=wp-shortpixel-settings)
 * via the `view-settings` template. Handles all sub-sections (tabs) of the page
 * including overview, optimisation, exclusions, processing, WebP/AVIF delivery,
 * AI, integrations, debug tools, and help.
 *
 * Wired up by AdminController on the `admin_menu` hook. On-boarding and
 * quick-tour flows are handled inline via display_part / view_mode state.
 * AJAX-save requests (from the JS-driven settings form) return a JSON
 * response via handleAjaxSave() and exit early without a page redirect.
 *
 * @package ShortPixel\Controller\View
 */
class SettingsViewController extends \ShortPixel\ViewController
{

     /** @var bool Whether the server runs Nginx (no .htaccess rewrite rules). */
     protected $is_nginx;
     /** @var bool Whether the .htaccess file at the site root is writable. */
     protected $is_htaccess_writable;
     /** @var bool Whether at least one PHP image library (GD or Imagick) is available. */
		 protected $has_image_library;
     /** @var bool Whether the cURL extension is installed. */
		 protected $is_curl_installed;
     /** @var bool Whether the site runs as part of a WordPress multisite network. */
     protected $is_multisite;
     /** @var bool Whether the current site is the primary (main) site of the network. */
     protected $is_mainsite;
     /** @var bool Whether the NextGen Gallery plugin is active. */
     protected $has_nextgen;
     /** @var bool Whether a form save should redirect to the bulk page instead of reloading settings. */
     protected $do_redirect = false;
     /** @var bool True when the environment is virtual/stateless and heavy file operations must be skipped. */
     protected $disable_heavy_features = false;

     /** @var object|null Cached quota data object, populated lazily by loadQuotaData(). */
     protected $quotaData = null;

     /** @var \ShortPixel\Model\ApiKeyModel The API key model for key validation and display. */
     protected $keyModel;

     /**
      * POST field name map passed to the parent processPostData() mapper.
      * Translates the HTML form's `cmyk2rgb` checkbox name to the model's
      * `CMYKtoRGBconversion` property name.
      *
      * @var array<string, string>
      */
     protected $mapper = array(
       'cmyk2rgb' => 'CMYKtoRGBconversion',
     );

     /** @var string Active settings tab/section. Defaults to 'overview'. Derived from $_GET['part']. */
     protected $display_part = 'overview';
     /** @var string[] All valid tab identifiers accepted in the 'part' query argument. */
     protected $all_display_parts = array('overview', 'optimisation','exclusions', 'processing', 'webp','ai', 'integrations', 'debug', 'tools', 'help');
     /** @var string Nonce action name for the settings form. */
     protected $form_action = 'save-settings';
     /** @var string Current view mode: 'simple', 'advanced', 'onboarding', or 'page-quick-tour'. */
     protected $view_mode = 'simple';
     /** @var bool True when the form was submitted via the AJAX save path (no full redirect, JSON response). */
		 protected $is_ajax_save = false;
     /** @var array<int, mixed> Notices generated during the current request, reported back in AJAX responses. */
		 protected $notices_added = [];

     /**
      * Accumulates field correction records to be sent back to the JS form.
      * Each entry is an associative array with keys: field, old_value, new_value,
      * hook_query (optional), message (optional).
      *
      * @var array<int, array<string, mixed>>
      */
     protected $returnFormData = [];

		 protected static $instance;
     protected $model;

      /**
       * Initialises the settings model and API key model.
       *
       * Must call parent::__construct() after assigning $this->model so the
       * base ViewController can set up the view object and access checks.
       */
      public function __construct()
      {
          $this->model = \wpSPIO()->settings();
					$keyControl = ApiKeyController::getInstance();
          $this->keyModel = $keyControl->getKeyModel();

          parent::__construct();
      }

      /**
       * Default action: renders the full settings page.
       *
       * Loads environment state, processes any POST submission, advances the
       * onboarding redirect counter (prevents redirect loops), and delegates
       * to load_settings() to populate view data and include the template.
       *
       * @return void
       */
      public function load()
      {
        $this->loadEnv();
        $this->checkPost(); // sets up post data


        if ($this->model->redirectedSettings < 2)
        {
          $this->model->redirectedSettings = 2; // Prevents any redirects after loading settings
        };

        if ($this->is_form_submit)
        {
          $this->processSave();
        }

        $this->load_settings();
      }

      /**
       * Stub entry point for AJAX form saves.
       *
       * Loads environment state so the controller is ready for downstream
       * callers. The actual save logic is triggered via checkPost() / processSave()
       * in the AJAX handler after indicateAjaxSave() has been called.
       *
       * @return void
       */
			public function saveForm()
			{
				 $this->loadEnv();

			}

      /**
       * Marks this request as an AJAX save, suppressing the normal page redirect.
       *
       * When set, doRedirect() will call handleAjaxSave() and exit with a JSON
       * response instead of calling wp_redirect().
       *
       * @return void
       */
      public function indicateAjaxSave()
      {
           $this->is_ajax_save = true;
      }

      /**
       * Handles the "no API key" form — validates and saves a submitted API key.
       *
       * Reads $_POST['apiKey'], sanitizes it, and delegates to ApiKeyModel::checkKey().
       * On success (key verified), reloads the page; on failure, redirects back to
       * the settings page so error notices are displayed.
       *
       * Expected POST field: apiKey (string).
       *
       * @return void Exits via doRedirect().
       */
      public function action_addkey()
      {
        $this->loadEnv();

        $this->checkPost(false);

        if ($this->is_form_submit && isset($_POST['apiKey']))
        {
            $apiKey = sanitize_text_field($_POST['apiKey']);

            if (strlen(trim($apiKey)) == 0) // display notice when submitting empty API key
            {
              Notice::addError(sprintf(__("The key you provided has %s characters. The API key should have 20 characters, letters and numbers only.",'shortpixel-image-optimiser'), strlen($apiKey) ));
            }
            else
            {

            $this->keyModel->resetTried();
            $this->keyModel->checkKey($apiKey);
            }
        }

        if (true === $this->keyModel->is_verified())
        {
          $this->doRedirect('reload');
        }
        else {
          $this->doRedirect();
        }
      }

      /**
       * Handles the "request a new API key" form on the no-key screen.
       *
       * POSTs to the ShortPixel sign-up endpoint with the user's email address.
       * On success the returned key is validated via ApiKeyModel::checkKey() and
       * the page is reloaded. On failure (HTTP error, sign-up error, or duplicate
       * email) an admin notice is added and the page is redirected.
       *
       * Expected POST field: pluginemail (string — the user's email address).
       *
       * @return void Exits via doRedirect().
       */
			public function action_request_new_key()
			{
					$this->loadEnv();
 	        $this->checkPost(false);

					$email = isset($_POST['pluginemail']) ? trim(sanitize_text_field($_POST['pluginemail'])) : null;

					// Not a proper form post.
					if (is_null($email))
					{
						$this->load();
						return;
					}


					$bodyArgs = array(
							'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
							'email' => $email,
							'ip' => isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? sanitize_text_field($_SERVER["HTTP_X_FORWARDED_FOR"]) : sanitize_text_field($_SERVER['REMOTE_ADDR']),
					);

	        $params = array(
	            'method' => 'POST',
	            'timeout' => 10,
	            'redirection' => 5,
	            'httpversion' => '1.0',
	            'blocking' => true,
	            'sslverify' => false,
	            'headers' => array(),
	            'body' => $bodyArgs,
	        );

	        $newKeyResponse = wp_remote_post("https://shortpixel.com/free-sign-up-plugin", $params);

					$errorText = __("There was problem requesting a new code. Server response: ", 'shortpixel-image-optimiser');

	        if ( is_object($newKeyResponse) && get_class($newKeyResponse) == 'WP_Error' ) {
	            //die(json_encode((object)array('Status' => 'fail', 'Details' => '503')));
							Notice::addError($errorText . $newKeyResponse->get_error_message() );
							$this->doRedirect(); // directly redirect because other data / array is not set.
	        }
	        elseif ( isset($newKeyResponse['response']['code']) && $newKeyResponse['response']['code'] <> 200 ) {
	            //die(json_encode((object)array('Status' => 'fail', 'Details' =>
							Notice::addError($errorText . $newKeyResponse['response']['code']);
							$this->doRedirect(); // strange http status, redirect with error.
	        }
					$body = $newKeyResponse['body'];
        	$body = json_decode($body);

	        if($body->Status == 'success') {
	            $key = trim($body->Details);
							$valid = $this->keyModel->checkKey($key);

	            if($valid === true) {
	                \ShortPixel\Controller\AdminNoticesController::resetAPINotices();

	            }
							$this->doRedirect('reload');

	        }
					elseif($body->Status == 'existing')
					{
						 Notice::addWarning( sprintf(__('This email address is already in use. Please use your API-key in the "Already have an API key" field. You can obtain your license key via %s your account %s ', 'shortpixel-image-optimiser'), '<a href="https://shortpixel.com/login/">', '</a>') );
					}
					else
					{
						 Notice::addError( __('Unexpected error obtaining the ShortPixel key. Please contact support about this:', 'shortpixel-image-optimiser') . '  ' . json_encode($body) );

					}
					$this->doRedirect();

			}

      /**
       * Marks the quick-tour as completed and reloads the settings page.
       *
       * Sets redirectedSettings to 3, which switches the view_mode out of
       * 'page-quick-tour' on the next load. Called via a POST from the tour UI.
       *
       * @return void Exits via doRedirect('reload').
       */
      public function action_end_quick_tour()
      {
          $this->loadEnv();
          $this->checkPost(false);

          $this->model->redirectedSettings = 3;

          $this->doRedirect('reload');
      }

      /**
       * Debug action: edits or removes a single SettingsModel field via POST.
       *
       * Reads $_POST['edit_setting'] (field name) and $_POST['new_value'] (raw
       * value). If $_POST['Submit'] equals 'remove', the option is deleted via
       * deleteOption(); otherwise the new value is assigned directly to the model.
       * No model-level validation is applied; the field must already exist in the
       * model (checked via exists()). Redirects back to settings after the change.
       *
       * @return void Exits via doRedirect().
       */
      public function action_debug_editSetting()
      {

        $this->loadEnv();
        $this->checkPost(false);

        $setting_name =  isset($_POST['edit_setting']) ? sanitize_text_field($_POST['edit_setting']) : false;
        $new_value = isset($_POST['new_value']) ? sanitize_text_field($_POST['new_value']) : false;
        $submit_name = isset($_POST['Submit']) ? sanitize_text_field($_POST['Submit']) : false; 

      //  $apiKeyModel = (isset($_POST['apiKeySettings']) && 'true' == $_POST['apikeySettings'])  ? true : false;

      // @todo ApiKeyModel will not really work, for no autosave/ public save, only via keychecks. Will be an issue when updating redirectedSettings, probably move back to settings where it was.
        if ($setting_name !== false && $new_value !== false)
        {
        //    $model = ($apiKeyModel) ? $this->keyModel : $this->model;
            $model = $this->model;
            if ($model->exists($setting_name))
            {
              if ('remove' == $submit_name)
              {
                 $this->model->deleteOption($setting_name);
              }
              else
              {
                 $this->model->$setting_name = $new_value;
              }
              
            }
        }
        

        $this->doRedirect();
      }

      /**
       * Debug action: resets all queues and redirects to a specific bulk panel.
       *
       * Reads $_REQUEST['bulk'] to determine the target panel. Recognised values:
       * 'migrate', 'restore', 'restoreAI', 'removeLegacy'. All queues are cleared
       * via QueueController::resetQueues() before the redirect.
       *
       * @return void Exits via doRedirect().
       */
			public function action_debug_redirectBulk()
			{
				$this->checkPost(false);

				QueueController::resetQueues();

				$action = isset($_REQUEST['bulk']) ? sanitize_text_field($_REQUEST['bulk']) : null;

				if ('migrate' == $action)
				{
					$this->doRedirect('bulk-migrate');
				}
				elseif ('restore' == $action)
				{
					$this->doRedirect('bulk-restore');
				}
        elseif ('restoreAI' == $action)
        {
          $this->doRedirect('bulk-restoreAI');
        }
				elseif ('removeLegacy' == $action)
				{
					 $this->doRedirect('bulk-removeLegacy');
				}
			}

      /** Button in part-debug, routed via custom Action */
      public function action_debug_resetStats()
      {
          $this->loadEnv();
					$this->checkPost(false);
          $statsController = StatsController::getInstance();
          $statsController->reset();
					$this->doRedirect('reload');
      }

      /**
       * Debug action: forces a fresh remote quota check and reloads the settings page.
       *
       * @return void Exits via doRedirect('reload').
       */
      public function action_debug_resetquota()
      {

          $this->loadEnv();
					$this->checkPost(false);
          $quotaController = QuotaController::getInstance();
          $quotaController->forceCheckRemoteQuota();
					$this->doRedirect('reload');
      }

      /**
       * Debug action: clears all stored admin notices and reloads the settings page.
       *
       * @return void Exits via doRedirect('reload').
       */
      public function action_debug_resetNotices()
      {
          $this->loadEnv();
					$this->checkPost(false);
          Notice::resetNotices();
          $nControl = new Notice(); // trigger reload.
					$this->doRedirect('reload');
      }

      /**
       * Debug action: manually triggers one or all admin notices.
       *
       * Reads $_REQUEST['notice_constant']. When the value is 'trigger-all', every
       * registered notice is triggered via addManual(). Otherwise the matching
       * notice is retrieved by key and triggered individually.
       *
       * @return void Exits via doRedirect().
       */
			public function action_debug_triggerNotice()
			{
				$this->checkPost(false);
				$key = isset($_REQUEST['notice_constant']) ? sanitize_text_field($_REQUEST['notice_constant']) : false;

				if ($key !== false)
				{
					$adminNoticesController = AdminNoticesController::getInstance();

					if ($key == 'trigger-all')
					{
						$notices = $adminNoticesController->getAllNotices();
						foreach($notices as $noticeObj)
						{
							 $noticeObj->addManual();
						}
					}
					else
					{
						$model = $adminNoticesController->getNoticeByKey($key);
						if (is_object($model))
							$model->addManual();
					}
				}
				$this->doRedirect();
			}

      /**
       * Debug action: resets one or all processing queues.
       *
       * Reads $_REQUEST['queue'] (accepted values: 'media', 'custom', 'mediaBulk',
       * 'customBulk', 'all'). When $_REQUEST['use_uninstall'] is present, calls
       * QueueController::uninstallPlugin() instead and exits without a reload notice.
       * On normal reset, adds a success notice and reloads the settings page.
       *
       * @return void Exits via doRedirect('reload').
       */
			public function action_debug_resetQueue()
			{
				 $queue = isset($_REQUEST['queue']) ? sanitize_text_field($_REQUEST['queue']) : null;

				 $this->loadEnv();
				 $this->checkPost(false);

         $uninstall = isset($_REQUEST['use_uninstall']) ? true : false;

				 if (! is_null($queue))
				 {
					 	 	$opt = new QueueController();

              if (true === $uninstall)
              {
                  Log::addDebug("Using Debug UnInstall");
                  QueueController::uninstallPlugin();
                  $this->doRedirect('');
              }
				 		 	$statsMedia = $opt->getQueue('media');
				 			$statsCustom = $opt->getQueue('custom');

              $opt = new QueueController(['is_bulk' => true]);


				 		 	$bulkMedia = $opt->getQueue('media');
				 			$bulkCustom = $opt->getQueue('custom');

				 			$queues = array('media' => $statsMedia, 'custom' => $statsCustom, 'mediaBulk' => $bulkMedia, 'customBulk' => $bulkCustom);

					   if ( strtolower($queue) == 'all')
						 {
							  foreach($queues as $q)
								{
										$q->resetQueue();
								}
						 }
						 else
						 {
							 	$queues[$queue]->resetQueue();
						 }

						 if ($queue == 'all')
						 {
						 	$message = sprintf(__('All items in the queues have been removed and the process is stopped', 'shortpixel-image-optimiser'));
						 }
						 else
						 {
								 $message = sprintf(__('All items in the %s queue have been removed and the process is stopped', 'shortpixel-image-optimiser'), $queue);
 						 }

						 Notice::addSuccess($message);
			 }

				$this->doRedirect('reload');
			}

      /**
       * Debug action: removes all _shortpixel_prevent_optimize post-meta entries.
       *
       * Issues a raw DELETE query against wp_postmeta. Adds a success notice and
       * redirects back to the settings page.
       *
       * @return void Exits via doRedirect().
       */
			public function action_debug_removePrevented()
			{
				$this->loadEnv();
				$this->checkPost(false);

				global $wpdb;
				$sql = 'delete from ' . $wpdb->postmeta . ' where meta_key = %s';

				$sql = $wpdb->prepare($sql, '_shortpixel_prevent_optimize');

				$wpdb->query($sql);

				$message = __('Item blocks have been removed. It is recommended to create a backup before trying to optimize image.', 'shortpixel-image-optimiser');

				Notice::addSuccess($message);
				$this->doRedirect();
			}

      /**
       * Debug action: removes the cached bulk-secret processor key and exits.
       *
       * Deletes the 'bulk-secret' cache item via CacheController and terminates
       * with a plain-text message. A new key is generated automatically when
       * the settings page is next loaded.
       *
       * @return void Exits with a plain-text message (does not redirect).
       */
			public function action_debug_removeProcessorKey()
			{
				$this->checkPost(false);

				$cacheControl = new CacheController();
				$cacheControl->deleteItem('bulk-secret');
				exit('reloading settings would cause processorKey to be set again. Navigate away');
			}

      /**
       * Persists the validated POST data to the settings model after a form submission.
       *
       * Handles side-effects of specific setting changes: resets queues when the
       * compression type changes, triggers integration-notice resets when NextGen
       * is toggled, validates the API key when one is submitted, and registers or
       * validates the CDN domain when CDN settings change.
       *
       * On completion either redirects to the bulk page (when 'save-bulk' was clicked)
       * or reloads the settings page. In AJAX-save mode the redirect is intercepted by
       * handleAjaxSave() which returns a JSON response instead.
       *
       * Assumes $this->postData has already been populated by processPostData().
       *
       * @return void Exits via doRedirect().
       */
      protected function processSave()
      {
          // Split this in the several screens. I.e. settings, advanced, Key Request IF etc.
          if (isset($this->postData['includeNextGen']) && $this->postData['includeNextGen'] == 1)
          {
              $nextgen = NextGenController::getInstance();
              $previous = $this->model->includeNextGen;
          //    $nextgen->enableNextGen(true);

              // Reset any integration notices when updating settings.
              AdminNoticesController::resetIntegrationNotices();
          }

					// If the compression type setting changes, remove all queued items to prevent further optimizing with a wrong type.
					if (intval($this->postData['compressionType']) !== intval($this->model->compressionType))
					{
						 QueueController::resetQueues();
					}

          if (isset($_POST['apiKey']) && false === $this->keyModel->is_constant())
          // first save all other settings ( like http credentials etc ), then check
          {
              $check_key = sanitize_text_field($_POST['apiKey']);
              $this->keyModel->resetTried(); // reset the tried api keys on a specific post request.
              $this->keyModel->checkKey($check_key);
          }

/*          if (isset($this->postData['ai_filename_addsymlink']) && true === $this->postData['ai_filename_addsymlink'])
          {
              $symlink_checked = isset($this->postData['ai_symlink_checked']) ? $this->postData['ai_symlink_checked'] : false; 
              if (false === $symlink_checked)
              {
                 $symlinkTest = UtilHelper::testSymlink(); 

                // If failed, turn this option off again. 
                if (false === $symlinkTest)
                {
                 Notice::addError(__('Test: Symlink could not be created. This means the symlink AI feature will not work. Please check your server configuration', 'shortpixel-image-optimiser'), true);
                 $this->postData['ai_filename_addsymlink'] = false; 
                }
                elseif (true === $symlinkTest) // If Ok, don't repeat check.
                {
                  $this->postData['ai_symlink_checked'] = true; 
                }
              }
          }
*/
          // write checked and verified post data to model. With normal models, this should just be call to update() function
          foreach($this->postData as $name => $value)
          {
            $this->model->{$name} = $value;
          }

					// Check at the model if any checkboxes are not checked.
					$data = $this->model->getData();

					foreach($data as $name => $value)
					{
							$type = $this->model->getType($name);
							if ('boolean' === $type )
							{
                if( ! isset($this->postData[$name]))
                {
								  $this->model->{$name} = false;
                }
                else
                {
                   $this->model->{$name} = true; 
                }
							}
					}

					// Every save, force load the quota. One reason, because of the HTTP Auth settings refresh.
					$this->loadQuotaData(true);
          // end

					if ($this->do_redirect)
					{
            $this->doRedirect('bulk');
					}
					elseif (false === $this->is_ajax_save) {

						$noticeController = Notice::getInstance();
						$notice = Notice::addSuccess(__('Settings Saved', 'shortpixel-image-optimiser'));
						$notice->is_removable = false;
						$noticeController->update();


          }
					  $this->doRedirect();
      }

      /**
       * Populates $this->view with all data required by the settings template and renders it.
       *
       * Loads API key data, dashboard info, quota data (when a key is verified), image
       * size limits, thumbnail-size exclusion options, stats, CDN and offload flags,
       * available language translations, and the hide-banner flag. Determines the
       * correct view_mode ('onboarding', 'page-quick-tour', 'simple', or 'advanced')
       * and then includes the `view-settings` template via loadView().
       *
       * @return void
       */
      public function load_settings()
      {
         $this->view->data = (Object) $this->model->getData();

				 $this->loadAPiKeyData();
         $this->loadDashBoardInfo();

         if ($this->keyModel->is_verified()) // supress quotaData alerts when handing unset API's.
          $this->loadQuotaData();
        else
          InstallHelper::checkTables();

         $statsControl = StatsController::getInstance();

         $this->view->minSizes = $this->getMaxIntermediateImageSize();

				 $excludeOptions = UtilHelper::getWordPressImageSizes();
				 $mainOptions = array(
					 'shortpixel_main_donotuse' =>  array('nice-name' => __('Main (scaled) Image', 'shortpixel-image-optimiser')),
					 'shortpixel_original_donotuse' => array('nice-name' => __('Original Image', 'shortpixel-image-optimiser')),
				 );

				 $excludeOptions = array_merge($mainOptions, $excludeOptions);

         $this->view->allThumbSizes = $excludeOptions;
         $this->view->averageCompression = $statsControl->getAverageCompression();

        // $this->view->savedBandwidth = UiHelper::formatBytes( intval($this->view->data->savedSpace) * 10000,2);

         // @todo this might be converted at some point tho view->env or something to divide better. 
         $offLoader = Offloader::getInstance();
         $this->view->cloudflare_constant = defined('SHORTPIXEL_CFTOKEN') ? true : false;
         $this->view->is_unlimited =  (!is_null($this->quotaData) && $this->quotaData->unlimited) ? true : false;
         $this->view->is_wpoffload = $offLoader->isActive('wp-offload');

         require_once( ABSPATH . 'wp-admin/includes/translation-install.php' );
         $this->view->languages = wp_get_available_translations();
        
         $this->view->hide_banner = false; 
         $bool = apply_filters('shortpixel/settings/no_banner', false);
         if (true === $bool )
            $this->view->hide_banner = true; 

         if ( defined('SHORTPIXEL_NO_BANNER') && SHORTPIXEL_NO_BANNER == true)
         {
           $this->view->hide_banner = true; 
         }
          
         //$this->view->latest_ai = $this->getLatestAIExamples();
				 $this->view->is_unlimited= (!is_null($this->quotaData) && $this->quotaData->unlimited) ? true : false;

         $settings = \wpSPIO()->settings();

				 if ($this->view->data->createAvif == 1)
           $this->avifServerCheck();

         // Set viewMode
				 if (false === $this->view->key->is_verifiedkey)
				 {
					 	$view_mode = 'onboarding';
						$this->display_part = 'nokey';
				 }
         elseif($this->view->data->redirectedSettings < 3 && $this->view->key->is_verifiedkey)
         {
            $view_mode = 'page-quick-tour';
         }
				 else {
					 $view_mode = get_user_option('shortpixel-settings-mode');
	         if (false === $view_mode)
           {
	          $view_mode = $this->view_mode;
           }

				 }

				 $this->view_mode = $view_mode;

				 $this->loadView('view-settings');
      }


      /**
       * Populates $this->view->dashboard with summary blocks for the settings dashboard panel.
       *
       * Builds a mainblock (overall health, optimised image count) and a bulkblock
       * (last bulk-processing date and a start-bulk link). Both are attached to
       * $this->view->dashboard as stdClass properties.
       *
       * @return void
       */
      public function loadDashBoardInfo()
      {
        $bulkController = BulkController::getInstance();
        $logs = $bulkController->getLogs();

        $this->view->dashboard  = new \stdClass;
        $mainblock = new \stdClass;

        $mainblock->ok = true;
        $mainblock->icon = 'ok';
        $mainblock->cocktail = true;
        $mainblock->header = __('Everything running smoothly.', 'shortpixel-image-optimiser');
        $mainblock->message = __('Keep calm and carry on', 'shortpixel-image-optimiser');

        if (false === $this->view->key->is_verifiedkey)
        {
						/*
						$mainblock->ok = false;
            $mainblock->header = __('Issue with API Key', 'shortpixel-image-optimiser');
            $mainblock->message = __('Add your API Key to start optimizing', 'shortpixel-image-optimiser');
            $mainblock->cocktail = false;
            $mainblock->icon = 'alert';
						*/
        }
				else { // If not errors
						 $statsController = StatsController::getInstance();

						 $media_total = $statsController->find('media', 'images');
						 $custom_total = $statsController->find('custom', 'images');

						 $custom_text = ($custom_total > 0) ? sprintf(esc_html__('and %s custom images ', 'shortpixel-image-optimiser'), $custom_total) : '';
            // $mainblock->message = '';

             if ($media_total > 0)
             {
						         $mainblock->message = sprintf(esc_html__('%s media items %s optimized', 'shortpixel-image-optimiser'), $media_total, $custom_text);
                     $total_sum = intval($media_total) + intval($custom_text);
                     $mainblock->optimized = sprintf(esc_html__('%s', 'shortpixel-image-optimiser'), $total_sum);
             }

				}

        $BulkViewController = BulkViewController::getInstance();

        $logs = $BulkViewController->getLogs();
        $date = '';

        if (count($logs) > 0)
        {
           $latest = $logs[0];
           $date = $latest['date'];
        }

        $message = (count($logs) == 0) ? esc_html__('No bulk processing has been performed yet', 'shortpixel-image-optimiser') : sprintf(__('The last bulk processing ran on:  %s','shortpixel-image-optimiser'), $date );

        $bulkblock = new \stdClass;
        $bulkblock->icon = 'ok';
        $bulkblock->message = $message;
        $bulkblock->link = admin_url("upload.php?page=wp-short-pixel-bulk");
        $bulkblock->show_button = (count($logs) == 0) ? true : false;

        $this->view->dashboard->bulkblock = $bulkblock;
        $this->view->dashboard->mainblock = $mainblock;
      }

      /**
       * Populates $this->view->key with API key display properties.
       *
       * Builds a stdClass with is_verifiedkey, is_constant_key, hide_api_key,
       * apiKey (masked when hidden or a network constant), is_editable, and
       * can_validate flags. The view template reads these to decide which key
       * controls to show.
       *
       * @return void
       */
			protected function loadAPiKeyData()
			{
				 $keyController = ApiKeyController::getInstance();

				 $keyObj = new \stdClass;
//				 $this->view->key = new \stdClass;
				 // $this->keyModel->loadKey();

				 $keyObj->is_verifiedkey = $this->keyModel->is_verified();
				 $keyObj->is_constant_key = $this->keyModel->is_constant();
				 $keyObj->hide_api_key = $this->keyModel->is_hidden();
				 $keyObj->apiKey = $keyController->getKeyForDisplay();
        // $keyObj->redirectedSettings =

				 $showApiKey = false;

				 if (true === $keyObj->hide_api_key)
				 {
					  $keyObj->apiKey = '***************';
				 }
				 elseif($this->is_multisite && $keyObj->is_constant_key)
				 {
					 $keyObj->apiKey = esc_html__('Multisite API Key','shortpixel-image-optimiser');
				 }
				 else {
				 	 $showApiKey = true;
				 }

				 $canValidate = false;

				 $keyObj->is_editable = (! $keyObj->is_constant_key && $showApiKey) ? true : false; ;
				 $keyObj->can_validate = $canValidate;

				 $this->view->key = $keyObj;
			}

      /**
       * Placeholder for AVIF server-compatibility checks.
       *
       * The original check has been superseded by logic inside the model itself.
       * This method is intentionally a no-op and is kept to preserve the call
       * site in load_settings() without breaking anything.
       *
       * @return void
       */
			protected function avifServerCheck()
      {
           return;
           /*

            This has been superseeded in hacky solution in the Model tiself.
    			$noticeControl = AdminNoticesController::getInstance();
					$notice = $noticeControl->getNoticeByKey('MSG_AVIF_ERROR');

          if (is_object($notice))
          {
					     $notice->check();
          } */
      }

      /**
       * Reads environment flags from wpSPIO()->env() and populates protected properties.
       *
       * Sets $this->is_nginx, $this->has_image_library, $this->is_curl_installed,
       * $this->is_htaccess_writable, $this->is_multisite, $this->is_mainsite,
       * $this->has_nextgen, $this->disable_heavy_features, and $this->display_part
       * (from the validated 'part' GET parameter).
       *
       * Must be called at the start of every public action method.
       *
       * @return void
       */
      protected function loadEnv()
      {
          $env = wpSPIO()->env();

          $this->is_nginx = $env->is_nginx;
          $this->has_image_library = ($env->is_gd_installed || $env->is_imagick_installed); // Any library 
          $this->is_curl_installed = $env->is_curl_installed;

          $this->is_htaccess_writable = $this->HTisWritable();

          $this->is_multisite = $env->is_multisite;
          $this->is_mainsite = $env->is_mainsite;
          $this->has_nextgen = $env->has_nextgen;

          $this->disable_heavy_features = (false === \wpSPIO()->env()->useVirtualHeavyFunctions()) ? true : false;

          $this->display_part = (isset($_GET['part']) && in_array($_GET['part'], $this->all_display_parts) ) ? sanitize_text_field($_GET['part']) : 'overview';
      }

      /**
       * Renders an anchor tag for a settings sub-page navigation link.
       *
       * Builds a URL pointing to the settings page with the given 'part' argument,
       * appends an 'active' CSS class when the link's part matches $this->display_part,
       * and optionally prepends or appends a dashicon.
       *
       * @param array $args {
       *     @type string       $part           Settings tab identifier (query arg value). Default ''.
       *     @type string       $title          Link text. Default 'Title'.
       *     @type string|false $icon           Dashicon class name without prefix, or false for none. Default false.
       *     @type string       $icon_position  'left' or 'right'. Default 'left'.
       *     @type string       $class          Additional CSS classes for the anchor. Default 'anchor-link'.
       * }
       * @return string HTML anchor tag.
       */
      protected function settingLink($args)
      {
          $defaults = [
             'part' => '',
             'title' => __('Title', 'shortpixel-image-optimiser'),
             'icon' => false,
             'icon_position' => 'left',
             'class' => 'anchor-link',

          ];

          $args = wp_parse_args($args, $defaults);

          $link = esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=' . $args['part'] ));
          $active = ($this->display_part == $args['part']) ? ' active ' : '';

          $title = $args['title'];

          $class = $active . $args['class'];

          if (false !== $args['icon'])
          {
             $icon  = '<i class="' . esc_attr($args['icon']) . '"></i>';
             if ($args['icon_position'] == 'left')
               $title = $icon . $title;
             else
               $title = $title . $icon;
          }

          $html = sprintf('<a href="%s" class="%s" data-menu-link="%s" %s >%s</a>', $link, $class, $args['part'], $active, $title);

          return $html;
      }

      /**
       * Checks whether the site's .htaccess file can be written.
       *
       * Returns false immediately on Nginx (no .htaccess support). Otherwise
       * delegates to FileModel::is_writable(), which returns true when the file
       * exists and is writable, or when it does not yet exist but the directory
       * itself is writable.
       *
       * @return bool True if writable; false on Nginx or when the file is not writable.
       */
      private function HTisWritable()
      {
          if ($this->is_nginx)
            return false;

					$file = \wpSPIO()->filesystem()->getFile(get_home_path() . '.htaccess');
					if ($file->is_writable())
					{
						 return true;
					}

          return false;
      }

      /**
       * Returns the maximum width and height among all registered intermediate image sizes.
       *
       * Iterates over WordPress's standard sizes (thumbnail, medium, large) and any
       * additional sizes registered via add_image_size(). The result is used on the
       * settings page to guide the minimum-size exclusion field.
       *
       * @return array{width: int, height: int} Maximum dimensions found, each at least 100px.
       */
      protected function getMaxIntermediateImageSize() {
          global $_wp_additional_image_sizes;

          $width = 0;
          $height = 0;
          $get_intermediate_image_sizes = get_intermediate_image_sizes();

          // Create the full array with sizes and crop info
          if(is_array($get_intermediate_image_sizes)) foreach( $get_intermediate_image_sizes as $_size ) {
              if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
                  $width = max($width, get_option( $_size . '_size_w' ));
                  $height = max($height, get_option( $_size . '_size_h' ));
                  //$sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
              } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
                  $width = max($width, $_wp_additional_image_sizes[ $_size ]['width']);
                  $height = max($height, $_wp_additional_image_sizes[ $_size ]['height']);
                  //'crop' =>  $_wp_additional_image_sizes[ $_size ]['crop']
              }
          }
          return array('width' => max(100, $width), 'height' => max(100, $height));
      }

      /**
       * Loads and caches quota data from QuotaController, populating $this->view->remainingImages.
       *
       * When $force is true the local cache ($this->quotaData) and the remote quota
       * cache are both invalidated first, which is necessary after settings saves that
       * may change HTTP Auth credentials. Values below zero are clamped to zero.
       *
       * @param bool $force True to force a fresh remote quota check. Default false.
       * @return void
       */
      protected function loadQuotaData($force = false)
      {
        $quotaController = QuotaController::getInstance();

				if ($force === true)
				{
					 $quotaController->forceCheckRemoteQuota();
					 $this->quotaData = null;
				}

        if (is_null($this->quotaData))
          $this->quotaData = $quotaController->getQuota(); 


        $quotaData = $this->quotaData;

        $remainingImages = $quotaData->total->remaining; 
        $remainingImages = ( $remainingImages < 0 ) ? 0 : $this->formatNumber($remainingImages, 0);

        $this->view->remainingImages = $remainingImages;

      }


      /**
       * Pre-processes raw POST data before delegating to the parent sanitizer.
       *
       * Handles settings-specific transformations before the generic
       * ViewController::processPostData() is called:
       * - Captures display_part and save-bulk redirect flag.
       * - Inverts the 'exif' checkbox (stored as "keep EXIF", submitted when unchecked).
       * - Collapses the two-part png2jpg checkbox into a 0/1/2 integer.
       * - Normalises excludeSizes to an array.
       * - Delegates WebP/AVIF delivery type to processWebP().
       * - Delegates exclusion-pattern building to processExcludeFolders().
       * - Validates and saves the API key when present and not a constant.
       * - Validates and optionally corrects the CDN domain.
       * - Prevents AI sub-options from being saved when AI is disabled.
       * - Strips UI-only fields that must not be passed to the model.
       *
       * @param array $post Raw $_POST data.
       * @return void
       */
      protected function processPostData($post)
      {
          if (isset($post['display_part']) && strlen($post['display_part']) > 0)
          {
              $this->display_part = sanitize_text_field($post['display_part']);
          }

          // analyse the save button
          if (isset($post['save-bulk']))
          {
            $this->do_redirect = true;
          }

          // handle 'reverse' checkbox.
          $exif = isset($post['exif']) ? 0 : 1;
          $post['exif'] = $exif;

          // checkbox overloading
          $png2jpg = (isset($post['png2jpg']) ? (isset($post['png2jpgForce']) ? 2 : 1): 0);
          $post['png2jpg'] = $png2jpg;

          // must be an array
          $post['excludeSizes'] = (isset($post['excludeSizes']) && is_array($post['excludeSizes']) ? $post['excludeSizes']: array());

          $post = $this->processWebp($post);
          $post = $this->processExcludeFolders($post);
        //  $post = $this->processCloudFlare($post);

					$check_key = false;

          if (isset($post['apiKey']) && false === $this->keyModel->is_constant())
					{
							$check_key = sanitize_text_field($post['apiKey']);
		          $this->keyModel->resetTried(); // reset the tried api keys on a specific post request.
              $this->keyModel->checkKey($check_key);

            if (false === $this->keyModel->is_verified())
            {
                $this->doRedirect('reload');
            }
            unset($post['apiKey']); // unset, since keyModel does the saving.

          }

          $post_useCDN = isset($post['useCDN']) ? true : false; 
          $post_CDNDomain = isset($post['CDNDomain']) ? sanitize_text_field($post['CDNDomain']) : ''; 

          $setting_useCDN = $this->model->useCDN; 
          $setting_CDNDomain = $this->model->CDNDomain; 

          $CDNcontroller = new \ShortPixel\Controller\Front\CDNController();

          if ($post_useCDN !== $setting_useCDN)
          {
              
              if (true === $post_useCDN)
              {
                 $CDNcontroller->registerDomain(); 
              }
              else{
                // Deregister off for now.
               // $controller->registerDomain(['action' => 'deregister']);
              }
          }

          if ($post_useCDN)
          {
              $check = $CDNcontroller->validateCDNDomain($post_CDNDomain);
              if (true !== $check)
              {
                 $this->addReturnFormData([
                    'field' => 'CDNDomain', 
                    'old_value' => $post_CDNDomain, 
                    'new_value' => $check, 
                    'hook_query' => 'info.useCDN', 
                    'message' => sprintf(__('CDN Domain has been changed from %s to %s . SPIO needs a path component', 'shortpixel-image-optimiser'), $post_CDNDomain, $check),
                 ]);
                 $post['CDNDomain'] = $check;
              }
          }

        if (false === isset($post['enable_ai']))
        {
             if (isset($post['autoAI']))
             {
                unset($post['autoAI']);
             }
             if (isset($post['autoAIBulk']))
             {
                unset($post['autoAIBulk']);
             }
        }

        
				// Field that are in form for other purposes, but are not part of model and should not be saved.
					$ignore_fields = array(
							'display_part',
							'save-bulk',
							'save',
							'removeExif',
							'png2jpgForce',
							'sp-nonce',
							'_wp_http_referer',
							'validate', // validate button from nokey part
							'new-index',
							'edit-exclusion',
              'exclusions', 
							'exclusion-type',
							'exclusion-value',
							'exclusion-minwidth',
							'exclusion-maxwidth',
							'exclusion-minheight',
							'exclusion-maxheight',
							'exclusion-width',
							'exclusion-height',
              'exclusion-filesize-value',
              'exclusion-filesize-denom',
              'exclusion-filesize-operator',
              'exclusion-when',
							'apply-select',
							'screen_action',
							'tools-nonce',
							'confirm',
							'tos',  // toss checkbox in nokey
							'pluginemail',
              'nonce',
              'action',
              'form-nonce',
              'request_url', 
              'login_apiKey',
              'ajaxSave',
              'ai_preview_image_id',
              'offload-active',

					);

					foreach($ignore_fields as $ignore)
					{
						 if (isset($post[$ignore]))
						 {
						 		unset($post[$ignore]);
						 }
					}

          parent::processPostData($post);

      }

      /**
       * Appends a field-correction record to $this->returnFormData.
       *
       * Each record is sent back to the JavaScript form via the AJAX-save JSON
       * response so the frontend can update displayed values without a full reload.
       *
       * @param array<string, mixed> $data Record with at minimum: 'field', 'old_value', 'new_value'.
       * @return void
       */
      protected function addReturnFormData($data)
      {

          $this->returnFormData[] = $data;

      }

      /**
       * Normalises WebP/AVIF delivery POST fields into a single integer setting.
       *
       * Reads the deliverWebp checkbox plus deliverWebpType / deliverWebpAlteringType
       * sub-fields and collapses them into a single integer stored as $post['deliverWebp']:
       *   0 = disabled, 1 = global htaccess/Nginx rewrite, 2 = WP Picture tag, 3 = htaccess passthrough.
       *
       * When not running on Nginx and delivery mode 3 is selected, writes the
       * corresponding htaccess rewrite rules via UtilHelper::alterHtaccess(). In all
       * other cases existing rules are removed first.
       *
       * @param array $post Raw POST data array (modified in place via return).
       * @return array Modified POST data with deliverWebp collapsed and type sub-fields removed.
       */
      protected function processWebP($post)
      {
        $deliverwebp = 0;
        if (! $this->is_nginx)
          UtilHelper::alterHtaccess(false, false); // always remove the statements.

			  $webpOn = isset($post['createWebp']) && $post['createWebp'] == 1;
				$avifOn = isset($post['createAvif']) && $post['createAvif'] == 1;

            if (isset($post['deliverWebp']) && $post['deliverWebp'] == 1)
            {
              $type = isset($post['deliverWebpType']) ? $post['deliverWebpType'] : '';
              $altering = isset($post['deliverWebpAlteringType']) ? $post['deliverWebpAlteringType'] : '';

              if ('deliverWebpAltered' == $type )
              {
                  if ('deliverWebpAlteredWP' == $altering)
                  {
                      $deliverwebp = 2;
                  }
                  elseif('deliverWebpAlteredGlobal' == $altering )
                  {
                      $deliverwebp = 1;
                  }
              }
              elseif ('deliverWebpUnaltered' == $type) {
                $deliverwebp = 3;
              }
            }

        if (! $this->is_nginx && $deliverwebp == 3) // deliver webp/avif via htaccess, write rules
        {
          UtilHelper::alterHtaccess(true, true);
        }

         $post['deliverWebp'] = $deliverwebp;
         unset($post['deliverWebpAlteringType']);
         unset($post['deliverWebpType']);

         return $post;
      }

      /**
       * Parses and validates the exclusion-pattern POST data into a structured array.
       *
       * Reads $post['exclusions'] (an array of JSON-encoded exclusion objects sent
       * by the JS exclusion editor). Each entry is decoded, then validated: regex
       * patterns are checked via preg_match(), date patterns via DateTime constructor.
       * Invalid entries are flagged with 'has-error' => true and an admin notice is
       * added. The cleaned array is stored as $post['excludePatterns'].
       *
       * When no exclusions key is present, $post['excludePatterns'] is set to [] and
       * the original array is returned unchanged.
       *
       * @param array $post Raw POST data array.
       * @return array Modified POST data with excludePatterns populated.
       */
      protected function processExcludeFolders($post)
      {
        $patterns = array();

        if (false === isset($post['exclusions']))
        {
					 $post['excludePatterns'] = [];
           return $post;
        }

        $exclusions  = $post['exclusions'];
        $accepted = array();
        foreach($exclusions as $index => $exclusions)
        {
            $accepted[] = json_decode(html_entity_decode( stripslashes($exclusions)), true);
        }

        foreach($accepted as $index => $pair)
        {
          $pattern = $pair['value'];
          $type = $pair['type'];

          if ($type == 'regex-name' || $type == 'regex-path')
          {
            if ( @preg_match($pattern, false) === false)
            {
               $accepted[$index]['has-error'] = true;
               Notice::addWarning(sprintf(__('Regular Expression Pattern %s returned an error. Please check if the expression is correct. %s * Special characters should be escaped. %s * A regular expression must be contained between two slashes  ', 'shortpixel-image-optimiser'), $pattern, "<br>", "<br>" ));
            }
          }
          if ('date' === $type)
          { 
             try {
              $date = new \DateTime($pattern);
             }
             catch (\Exception $e)
             {
               Notice::addWarning(sprintf(__('Date format %s return an error %s . Accepted are formats that are valid for PHP dateFormat', 'shortpixel-image-optimiser'), 
                 $pattern, $e->getMessage()
             ));
             }
          }
        }

        $post['excludePatterns'] = $accepted;


        return $post; 

      }


      /**
       * Performs the post-action redirect (or AJAX response) after any form save or debug action.
       *
       * Accepted $redirect values:
       *   'self' / 'reload' — back to the current settings tab.
       *   'bulk'            — Media Library Bulk page.
       *   'bulk-migrate'    — Bulk page with migrate panel.
       *   'bulk-restore'    — Bulk page with restore panel.
       *   'bulk-restoreAI'  — Bulk page with AI-restore panel.
       *   'bulk-removeLegacy' — Bulk page with remove-legacy panel.
       *   '' / null         — wp_redirect to $url (which may be null; potential redirect to null).
       *
       * When $this->is_ajax_save is true, intercepts the redirect and calls
       * handleAjaxSave() which sends a JSON response and exits. Otherwise calls
       * wp_redirect() followed by exit().
       *
       * @param string $redirect Redirect target identifier. Default 'self'.
       * @return void Exits via wp_redirect() or handleAjaxSave().
       */
      protected function doRedirect($redirect = 'self')
      {

        $url = null;


        if ($redirect == 'self'  || $redirect == 'reload')
        {
          if (true === $this->is_ajax_save)
          {
              $url = $this->url;
          }
          else {
            $url = esc_url_raw(add_query_arg('part', $this->display_part, $this->url));
            $url = remove_query_arg('noheader', $url); // has url
            $url = remove_query_arg('sp-action', $url); // has url
          }
        }
        elseif('bulk' == $redirect )
        {
          $url = admin_url("upload.php?page=wp-short-pixel-bulk");
        }
				elseif('bulk-migrate' == $redirect)
				{
					 $url = admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-migrate');
				}
				elseif ('bulk-restore' == $redirect)
				{
						$url = admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-restore');
				}
        elseif ('bulk-restoreAI' == $redirect)
        {
            $url = admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-restoreAI');
        }
				elseif ('bulk-removeLegacy' == $redirect)
				{
						$url = admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-removeLegacy');
				}

        if (true === $this->is_ajax_save)
				{
					$this->handleAjaxSave($redirect, $url);
				}

        wp_redirect($url);
        exit();
      }

      /**
       * Builds and sends the JSON response for an AJAX settings save.
       *
       * Collects any new admin notices generated during the request, includes them
       * as formatted HTML in the response, attaches a redirect URL when the save
       * requires one, and appends any returnFormData corrections. Calls
       * NoticeController::update() to dismiss one-time notices, then exits via
       * wp_send_json().
       *
       * @param string       $redirect The redirect target identifier passed from doRedirect().
       * @param string|false $url      The resolved redirect URL, or false when not applicable.
       * @return void Exits via wp_send_json().
       */
			protected function handleAjaxSave($redirect, $url = false)
			{
						// Intercept new notices and add them
						// Return JSON object with status of save action
						$json = new \stdClass;
						$json->result = true;


						$noticeController = Notice::getInstance();

						$json->notices = $noticeController->getNewNotices();
						if(count($json->notices) > 0)
						{
							$json->display_notices = [];
							foreach($json->notices as $notice)
							{
								$json->display_notices[] = $notice->getForDisplay(['class' => 'is_ajax', 'is_removable' => false]);
							}
						}
						if ($redirect !== 'self')
						{
              $json->redirect = ($url !== false && ! is_null($url) ) ? $url : $redirect;
						}

            if (count($this->returnFormData) > 0)
            {
               $json->returnFormData = $this->returnFormData;
            }

						$noticeController->update(); // dismiss one-time ponies
						wp_send_json($json);
						exit();
			}



}
