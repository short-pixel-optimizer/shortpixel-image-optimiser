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

class SettingsViewController extends \ShortPixel\ViewController
{

     //env
     protected $is_nginx;
     protected $is_htaccess_writable;
		 protected $has_image_library;
		 protected $is_curl_installed;
     protected $is_multisite;
     protected $is_mainsite;
     protected $has_nextgen;
     protected $do_redirect = false;
     protected $disable_heavy_features = false; // if virtual and stateless, might disable heavy file ops.

     protected $quotaData = null;

     protected $keyModel;

     protected $mapper = array(
       'cmyk2rgb' => 'CMYKtoRGBconversion',
     );

     protected $display_part = 'overview';
     protected $all_display_parts = array('overview', 'optimisation','exclusions', 'processing', 'webp','ai', 'integrations', 'debug', 'tools', 'help');
     protected $form_action = 'save-settings';
     protected $view_mode = 'simple'; // advanced or simple
		 protected $is_ajax_save = false; // checker if saved via ajax ( aka no redirect / json return )
		 protected $notices_added = []; // Added notices this run, to report via ajax.

     // Array of updated values to be passed back in the settings page
     protected $returnFormData = []; 

		 protected static $instance;

      public function __construct()
      {
          $this->model = \wpSPIO()->settings();
					$keyControl = ApiKeyController::getInstance();
          $this->keyModel = $keyControl->getKeyModel();

          parent::__construct();
      }

      // default action of controller
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

			public function saveForm()
			{
				 $this->loadEnv();

			}

      public function indicateAjaxSave()
      {
           $this->is_ajax_save = true;
      }

      // this is the nokey form, submitting api key
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

      public function action_end_quick_tour()
      {
          $this->loadEnv();
          $this->checkPost(false);

          $this->model->redirectedSettings = 3;

          $this->doRedirect('reload');
      }

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

			public function action_debug_redirectBulk()
			{
				$this->checkPost(false);

				QueueController::resetQueues();

				$action = isset($_REQUEST['bulk']) ? sanitize_text_field($_REQUEST['bulk']) : null;

				if ($action == 'migrate')
				{
					$this->doRedirect('bulk-migrate');
				}

				if ($action == 'restore')
				{
					$this->doRedirect('bulk-restore');
				}
				if ($action == 'removeLegacy')
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

      public function action_debug_resetquota()
      {

          $this->loadEnv();
					$this->checkPost(false);
          $quotaController = QuotaController::getInstance();
          $quotaController->forceCheckRemoteQuota();
					$this->doRedirect('reload');
      }

      public function action_debug_resetNotices()
      {
          $this->loadEnv();
					$this->checkPost(false);
          Notice::resetNotices();
          $nControl = new Notice(); // trigger reload.
					$this->doRedirect('reload');
      }

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

			public function action_debug_removeProcessorKey()
			{
				$this->checkPost(false);

				$cacheControl = new CacheController();
				$cacheControl->deleteItem('bulk-secret');
				exit('reloading settings would cause processorKey to be set again. Navigate away');
			}

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

      /* Loads the view data and the view */
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
        
         
         //$this->view->latest_ai = $this->getLatestAIExamples();

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


// Basically this whole premise is impossible.
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

        $message = (count($logs) == 0) ? esc_html__('No bulk processing has been performed yet', 'shortpixel-image-optimiser') : sprintf(__('The last bulk processing ran on:  %s','shortpixel-image-optimiser'), '<br>' . $date );

        $bulkblock = new \stdClass;
        $bulkblock->icon = 'ok';
        $bulkblock->message = $message;
        $bulkblock->link = admin_url("upload.php?page=wp-short-pixel-bulk");
        $bulkblock->show_button = (count($logs) == 0) ? true : false;

        $this->view->dashboard->bulkblock = $bulkblock;
        $this->view->dashboard->mainblock = $mainblock;
      }

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

      /** Checks on things and set them for information. */
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

      /* Temporary function to check if HTaccess is writable.
      * HTaccess is writable if it exists *and* is_writable, or can be written if directory is writable.
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

			// @param Force.  needed on settings save because it sends off the HTTP Auth
      protected function loadQuotaData($force = false)
      {
        $quotaController = QuotaController::getInstance();

				if ($force === true)
				{
					 $quotaController->forceCheckRemoteQuota();
					 $this->quotaData = null;
				}

        if (is_null($this->quotaData))
          $this->quotaData = $quotaController->getQuota(); //$this->shortPixel->checkQuotaAndAlert();


        $quotaData = $this->quotaData;

        $remainingImages = $quotaData->total->remaining; // $quotaData['APICallsRemaining'];
        $remainingImages = ( $remainingImages < 0 ) ? 0 : $this->formatNumber($remainingImages, 0);

        $this->view->remainingImages = $remainingImages;

      }


			/** This is done before handing it off to the parent controller, to sanitize and check against model.
			* @param $post Array (raw) $_POST object
			**/
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

        
				// Field that are in form for other purpososes, but are not part of model and should not be saved.
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
							'exclusion-type',
							'exclusion-value',
							'exclusion-minwidth',
							'exclusion-maxwidth',
							'exclusion-minheight',
							'exclusion-maxheight',
							'exclusion-width',
							'exclusion-height',
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

      protected function addReturnFormData($data)
      {
        
          $this->returnFormData[] = $data; 

      }

      /** Function for the WebP settings overload
      *
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

              if ($type == 'deliverWebpAltered')
              {
                  if ($altering == 'deliverWebpAlteredWP')
                  {
                      $deliverwebp = 2;
                  }
                  elseif($altering = 'deliverWebpAlteredGlobal')
                  {
                      $deliverwebp = 1;
                  }
              }
              elseif ($type == 'deliverWebpUnaltered') {
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
          //$first = substr($pattern, 0,1);
          if ($type == 'regex-name' || $type == 'regex-path')
          {
            if ( @preg_match($pattern, false) === false)
            {
               $accepted[$index]['has-error'] = true;
               Notice::addWarning(sprintf(__('Regular Expression Pattern %s returned an error. Please check if the expression is correct. %s * Special characters should be escaped. %s * A regular expression must be contained between two slashes  ', 'shortpixel-image-optimiser'), $pattern, "<br>", "<br>" ));
            }
          }
        }

        $post['excludePatterns'] = $accepted;


        return $post; // @todo The switch to check regex patterns or not.

        if(isset($post['excludePatterns']) && strlen($post['excludePatterns'])) {
            $items = explode(',', $post['excludePatterns']);
            foreach($items as $pat) {
                $parts = explode(':', $pat);
                if (count($parts) == 1)
                {
                  $type = 'name';
                  $value = str_replace('\\\\','\\', trim($parts[0]));
                }
                else
                {
                  $type = trim($parts[0]);
                  $value = str_replace('\\\\','\\',trim($parts[1]));
                }

                if (strlen($value) > 0)  // omit faulty empty statements.
                  $patterns[] = array('type' => $type, 'value' => $value);

            }

        }


			  foreach($patterns as $pair)
				{
						$pattern = $pair['value'];
						//$first = substr($pattern, 0,1);
						if ($type == 'regex-name' || $type == 'regex-path')
						{
						  if ( @preg_match($pattern, false) === false)
							{
								 Notice::addWarning(sprintf(__('Regular Expression Pattern %s returned an error. Please check if the expression is correct. %s * Special characters should be escaped. %s * A regular expression must be contained between two slashes  ', 'shortpixel-image-optimiser'), $pattern, "<br>", "<br>" ));
							}
						}
				}
        $post['excludePatterns'] = $patterns;
        return $post;
      }


			/**
			* Each form save / action results in redirect
			*
			**/
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
        elseif($redirect == 'bulk')
        {
          $url = admin_url("upload.php?page=wp-short-pixel-bulk");
        }
				elseif($redirect == 'bulk-migrate')
				{
					 $url = admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-migrate');
				}
				elseif ($redirect == 'bulk-restore')
				{
						$url = admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-restore');
				}
				elseif ($redirect == 'bulk-removeLegacy')
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
