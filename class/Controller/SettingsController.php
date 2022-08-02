<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;
use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\InstallHelper as InstallHelper;

use ShortPixel\Model\ApiKeyModel as ApiKeyModel;


use ShortPixel\NextGenController as NextGenController;


class SettingsController extends \ShortPixel\ViewController
{

     //env
     protected $is_nginx;
     protected $is_verifiedkey;
     protected $is_htaccess_writable;
     protected $is_multisite;
     protected $is_mainsite;
     protected $is_constant_key;
     protected $hide_api_key;
     protected $has_nextgen;
     protected $do_redirect = false;

     protected $quotaData = null;

     protected $keyModel;

     protected $mapper = array(
       'key' => 'apiKey',
       'cmyk2rgb' => 'CMYKtoRGBconversion',
     );

     protected $display_part = 'settings';
		 protected $all_display_parts = array('settings', 'adv-settings', 'cloudflare', 'debug', 'tools');
     protected $form_action = 'save-settings';

      public function __construct()
      {
          $this->model = \wpSPIO()->settings();

					//@todo Streamline this mess. Should run through controller mostly. Risk of desync otherwise.
					$keyControl = ApiKeyController::getInstance();
          $this->keyModel = $keyControl->getKeyModel(); //new ApiKeyModel();

         // $this->keyModel->loadKey();
          $this->is_verifiedkey = $this->keyModel->is_verified();
          $this->is_constant_key = $this->keyModel->is_constant();
          $this->hide_api_key = $this->keyModel->is_hidden();


          parent::__construct();
      }


      // default action of controller
      public function load()
      {

        $this->loadEnv();
        $this->checkPost(); // sets up post data

        $this->model->redirectedSettings = 2; // Prevents any redirects after loading settings

        if ($this->is_form_submit)
        {
          $this->processSave();
        }

        $this->load_settings();
      }

      // this is the nokey form, submitting api key
      public function action_addkey()
      {
        $this->loadEnv();
        $this->checkPost();

        Log::addDebug('Settings Action - addkey ', array($this->is_form_submit, $this->postData) );
        if ($this->is_form_submit && isset($this->postData['apiKey']))
        {
            $apiKey = $this->postData['apiKey'];
            if (strlen(trim($apiKey)) == 0) // display notice when submitting empty API key
            {
              Notice::addError(sprintf(__("The key you provided has %s characters. The API key should have 20 characters, letters and numbers only.",'shortpixel-image-optimiser'), strlen($apiKey) ));
            }
            else
            {
              $this->keyModel->resetTried();
              $this->keyModel->checkKey($this->postData['apiKey']);
            }
        }

        $this->doRedirect();
      }

			public function action_request_new_key()
			{
					$this->loadEnv();
 	        $this->checkPost();

					$email = isset($_POST['pluginemail']) ? trim(sanitize_text_field($_POST['pluginemail'])) : null;

					// Not a proper form post.
					if (is_null($email))
					{
						$this->load();
						return;
					}

					// Old code starts here.
	        if( $this->keyModel->is_verified() === true) {
	            $this->load(); // already verified?
							return;
	        }
	        $params = array(
	            'method' => 'POST',
	            'timeout' => 10,
	            'redirection' => 5,
	            'httpversion' => '1.0',
	            'blocking' => true,
	            'sslverify' => false,
	            'headers' => array(),
	            'body' => array(
	                'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
	                'email' => $email,
	                'ip' => isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? sanitize_text_field($_SERVER["HTTP_X_FORWARDED_FOR"]) : sanitize_text_field($_SERVER['REMOTE_ADDR']),
	            )
	        );

	        $newKeyResponse = wp_remote_post("https://shortpixel.com/free-sign-up-plugin", $params);

					$errorText = __("There was problem requesting a new code. Server response: ", 'shortpixel-image-optimiser');

	        if ( is_object($newKeyResponse) && get_class($newKeyResponse) == 'WP_Error' ) {
	            //die(json_encode((object)array('Status' => 'fail', 'Details' => '503')));
							Notice::addError($errorText . $newKeyResponse->get_error_message() );
	        }
	        elseif ( isset($newKeyResponse['response']['code']) && $newKeyResponse['response']['code'] <> 200 ) {
	            //die(json_encode((object)array('Status' => 'fail', 'Details' =>
							Notice::addError($errorText . $newKeyResponse['response']['code']);
	        }
					$body = $newKeyResponse['body'];
        	$body = json_decode($body);

	        if($body->Status == 'success') {
	            $key = trim($body->Details);
							$valid = $this->keyModel->checkKey($key);
	            //$validityData = $this->getQuotaInformation($key, true, true);

	            if($valid === true) {
	                \ShortPixel\Controller\AdminNoticesController::resetAPINotices();
	                /* Notice::addSuccess(__('Great, you successfully claimed your API Key! Please take a few moments to review the plugin settings below before starting to optimize your images.','shortpixel-image-optimiser')); */
	            }
	        }
					elseif($body->Status == 'existing')
					{
						 Notice::addWarning( sprintf(__('This email address is already in use. Please use your API-key in the "Already have an API key" field. You can obtain your license key via %s your account %s ', 'shortpixel-image-optimiser'), '<a href="https://shortpixel.com/login/">', '</a>') );
					}
					else
					{
						 Notice::addError( __('Unexpected error obtain your ShortPixel key. Please contact support about this', 'shortpixel-image-optimiser') . '  ' . json_encode($body) );
					}

					$this->doRedirect();

			}

      /* Custom Media, refresh a single Folder */
      public function action_refreshfolder()
      {
         $folder_id = isset($_REQUEST['folder_id']) ? intval($_REQUEST['folder_id']) : false;

         if ($folder_id)
         {
            $otherMediaController = OtherMediaController::getInstance();
            $dirObj = $otherMediaController->getFolderByID($folder_id);

            if ($dirObj)
            {
               $dirObj->refreshFolder(true);
            }

         }
				 else
				 {
				 	Log::addWarn('RefreshFolder without folder id '. $folder_id );
				 }

         $this->load();
      }


			public function action_debug_redirectBulk()
			{

	 			OptimizeController::resetQueues();

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
				//upload.php?page=wp-short-pixel-bulk&panel=bulk-migrate

				//upload.php?page=wp-short-pixel-bulk&panel=bulk-restore

			}

      /** Button in part-debug, routed via custom Action */
      public function action_debug_resetStats()
      {
          $this->loadEnv();
          $statsController = StatsController::getInstance();
          $statsController->reset();

          $this->load();
      }

      public function action_debug_resetquota()
      {

          $this->loadEnv();

          $quotaController = QuotaController::getInstance();
          $quotaController->forceCheckRemoteQuota();

          $this->load();
      }

      public function action_debug_resetNotices()
      {
          $this->loadEnv();
          Notice::resetNotices();
          $nControl = new Notice(); // trigger reload.


          $this->load();
      }

			public function action_debug_resetQueue()
			{
				 $queue = isset($_REQUEST['queue']) ? sanitize_text_field($_REQUEST['queue']) : null;
				 $this->loadEnv();

				 if (! is_null($queue))
				 {
					 	 	$opt = new OptimizeController();
				 		 	$statsMedia = $opt->getQueue('media');
				 			$statsCustom = $opt->getQueue('custom');

				 			$opt->setBulk(true);

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


				 $this->load();
			}

			public function action_debug_removeProcessorKey()
			{
				//$this->loadEnv();

				$cacheControl = new CacheController();
				$cacheControl->deleteItem('bulk-secret');
				exit('reloading settings would cause processorKey to be set again');
			}


      public function processSave()
      {
          // Split this in the several screens. I.e. settings, advanced, Key Request IF etc.
          if (isset($this->postData['includeNextGen']) && $this->postData['includeNextGen'] == 1)
          {
              $nextgen = NextGenController::getInstance();
              $previous = $this->model->includeNextGen;
              $nextgen->enableNextGen($previous);

              // Reset any integration notices when updating settings.
              AdminNoticesController::resetIntegrationNotices();
          }

          $check_key = false;
          if (isset($this->postData['apiKey']))
          {
              $check_key = $this->postData['apiKey'];
              unset($this->postData['apiKey']); // unset, since keyModel does the saving.
          }

					// If the compression type setting changes, remove all queued items to prevent further optimizing with a wrong type.
					if (intval($this->postData['compressionType']) !== intval($this->model->compressionType))
					{
						 OptimizeController::resetQueues();
					}

          // write checked and verified post data to model. With normal models, this should just be call to update() function
          foreach($this->postData as $name => $value)
          {
            $this->model->{$name} = $value;
          }

          // first save all other settings ( like http credentials etc ), then check
          if (! $this->keyModel->is_constant() && $check_key !== false) // don't allow settings key if there is a constant
          {
            $this->keyModel->resetTried(); // reset the tried api keys on a specific post request.
            $this->keyModel->checkKey($check_key);
          }


					// Every save, force load the quota. One reason, because of the HTTP Auth settings refresh.
					$this->loadQuotaData(true);

          // end

          if ($this->do_redirect)
            $this->doRedirect('bulk');
          else {
						Notice::addSuccess(__('Settings Saved', 'shortpixel-image-optimiser'));
            $this->doRedirect();
          }
      }

      /* Loads the view data and the view */
      public function load_settings()
      {
         if ($this->is_verifiedkey) // supress quotaData alerts when handing unset API's.
          $this->loadQuotaData();
        else
          InstallHelper::checkTables();

				 $keyController = ApiKeyController::getInstance();

         $this->view->data = (Object) $this->model->getData();

         $this->view->data->apiKey = $keyController->getKeyForDisplay();


         $this->loadStatistics();
				 $this->checkCloudFlare();


         $statsControl = StatsController::getInstance();

         $this->view->minSizes = $this->getMaxIntermediateImageSize();
         $this->view->customFolders= $this->loadCustomFolders();
         $this->view->allThumbSizes = $this->getAllThumbnailSizes();
         $this->view->averageCompression = $statsControl->getAverageCompression();
         $this->view->savedBandwidth = UiHelper::formatBytes( intval($this->view->data->savedSpace) * 10000,2);
         //$this->view->resources = wp_remote_post($this->model->httpProto . "://shortpixel.com/resources-frag");

         /*if (is_wp_error($this->view->resources))
            $this->view->resources = null; */

         $this->view->cloudflare_constant = defined('SHORTPIXEL_CFTOKEN') ? true : false;

         $settings = \wpSPIO()->settings();
         $this->view->dismissedNotices = $settings->dismissedNotices;

				 if ($this->view->data->createAvif == 1)
           $this->avifServerCheck();

         $this->loadView('view-settings');
      }

			  protected function avifServerCheck()
      {
          $cache = new CacheController();
          if (apply_filters('shortpixel/avifcheck/override', false) === true)
          { return; }

          if ($cache->getItem('avif_server_check')->exists() === false)
          {
             $url = \WPSPIO()->plugin_url('res/img/test.avif');
             $headers = get_headers($url);
             $is_error = true;

             // Defaults.
             $error_message = __('Avif server test failed. Your server might not be configured to display AVIF files properly. Serving Avif might cause your images to not load. Check your images, disable the AVIF option or update your web server configuration.', 'shortpixel-image-optimiser');
             $error_detail = __('The request did not return valid HTTP Headers. Check if the plugin is allowed to get ' . $url, 'shortpixel-image-optimiser');

             $contentType = null;
             $response = $headers[0];

             if (is_array($headers) )
             {
                foreach($headers as $index => $header)
                {
                    if ( strpos(strtolower($header), 'content-type') !== false )
										{
											// This is another header that can interrupt.
											if (strpos(strtolower($header), 'x-content-type-options') === false)
											{
                      	$contentType = $header;
											}
										}
                }

                // http not ok, redirect etc. Shouldn't happen.
                 if (is_null($response) || strpos($response, '200') === false)
                 {
                   $error_detail = sprintf(__('The AVIF check could not be completed, because the plugin couldn\'t fetch  %s %s %s. %s Please check the security/firewall settings and try again', 'shortpixel-image-optimiser'), '<a href="' . $url . '">', $url, '</a>', '<br>');
                 }
                 elseif(is_null($contentType) || strpos($contentType, 'avif') === false)
                 {
                   $error_detail = sprintf(__('The necessary Content-type header for AVIF files wasn\'t found, please check this with your Hosting and/or CDN provider. For more details about how to fix this, %s check this article %s', 'shortpixel_image_optimiser'), '<a href="https://shortpixel.com/blog/avif-mime-type-delivery-apache-nginx/" target="_blank"> ', '</a>');

                 }
                 else
                 {
                    $is_error = false;
                 }
             }

             if ($is_error)
             {
							   $noticeController = Notice::getInstance();
					       $notice = $noticeController->getNoticeByID(AdminNoticesController::MSG_AVIF_ERROR);
								 if ($notice && $notice->isDismissed() === true)
							 	 {
									 // Do Nothing
								 }
								 else
								 {
		                $notice = Notice::addError('<h4>' . $error_message . '</h4><p>' . $error_detail . '</p><p class="small">' . __('Returned Headers for :<br>', 'shortpixel-image-optimiser') . print_r($headers, true) .  '</p>');
		                Notice::makePersistent($notice, AdminNoticesController::MSG_AVIF_ERROR, MONTH_IN_SECONDS);
								 }

             }
             else
             {
									 Notice::removeNoticeByID(AdminNoticesController::MSG_AVIF_ERROR);

                   $item = $cache->getItem('avif_server_check');
                   $item->setValue(time());
                   $item->setExpires(WEEK_IN_SECONDS);
                   $cache->storeItemObject($item );
             }
          }
      }

      protected function loadStatistics()
      {
				/*
        $statsControl = StatsController::getInstance();
        $stats = new \stdClass;

        $stats->totalOptimized = $statsControl->find('totalOptimized');
        $stats->totalOriginal = $statsControl->find('totalOriginal');
        $stats->mainOptimized = $statsControl->find('media', 'images');


        // used in part-g eneral
        $stats->thumbnailsToProcess =  $statsControl->thumbNailsToOptimize(); // $totalImages - $totalOptimized;

//        $stats->totalFiles = $statsControl->find('media', '')


        $this->view->stats = $stats;
				*/
      }

			/** @todo Remove this check in Version 5.1 including all data on the old CF token */
			protected function checkCloudFlare()
			{
          $settings = \wpSPIO()->settings();


				 $authkey = $settings->cloudflareAuthKey;
				 $this->view->hide_cf_global = true;

				 if (strlen($authkey) > 0)
				 {
					 $message = '<h3> ' . __('Cloudflare', 'shortpixel-image-optimiser') . '</h3>';
					 $message .= '<p>' . __('It appears that you are using the Cloudflare Global API key. As it is not as safe as the Cloudflare Token, it will be removed in the next version. Please, switch to the token.', 'shortpixel-image-optimiser') . '</p>';
				 	 $message .= '<p>' . sprintf(__('%s How to set up the Cloudflare Token %s', 'shortpixel-image-optimiser'), '<a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank">', '</a>') . '</p>';

					  Notice::addNormal($message);
						$this->view->hide_cf_global = false;
				 }

			}

      /** Checks on things and set them for information. */
      protected function loadEnv()
      {
          $env = wpSPIO()->env();

          $this->is_nginx = $env->is_nginx;
          $this->is_gd_installed = $env->is_gd_installed;
          $this->is_curl_installed = $env->is_curl_installed;

          $this->is_htaccess_writable = $this->HTisWritable();

          $this->is_multisite = $env->is_multisite;
          $this->is_mainsite = $env->is_mainsite;
          $this->has_nextgen = $env->has_nextgen;

          $this->display_part = (isset($_GET['part']) && in_array($_GET['part'], $this->all_display_parts) ) ? sanitize_text_field($_GET['part']) : 'settings';
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

      private function getAllThumbnailSizes()
      {
        global $_wp_additional_image_sizes;

        $sizes_names = get_intermediate_image_sizes();
        $sizes = array();
        foreach ( $sizes_names as $size ) {
            $sizes[ $size ][ 'width' ] = intval( get_option( "{$size}_size_w" ) );
            $sizes[ $size ][ 'height' ] = intval( get_option( "{$size}_size_h" ) );
            $sizes[ $size ][ 'crop' ] = get_option( "{$size}_crop" ) ? get_option( "{$size}_crop" ) : false;
        }
        if(function_exists('wp_get_additional_image_sizes')) {
            $sizes = array_merge($sizes, wp_get_additional_image_sizes());
        } elseif(is_array($_wp_additional_image_sizes)) {
            $sizes = array_merge($sizes, $_wp_additional_image_sizes);
        }

        $sizes = apply_filters('shortpixel/settings/image_sizes', $sizes);
        return $sizes;
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

      protected function loadCustomFolders()
      {

        $otherMedia = OtherMediaController::getInstance();

        $otherMedia->refreshFolders();
        $customFolders = $otherMedia->getActiveFolders();
        $fs = \wpSPIO()->filesystem();

        $customFolderBase = $fs->getWPFileBase();
        $this->view->customFolderBase = $customFolderBase->getPath();

        if ($this->has_nextgen)
        {
          $ng = NextGenController::getInstance();
          $NGfolders = $ng->getGalleries();
          $foldersArray = array();

          foreach($NGfolders as $folder)
          {
            $fsFolder = $fs->getDirectory($folder->getPath());
            $foldersArray[] = $fsFolder->getPath();
          }

        }

        return $customFolders;
      }

      // This is done before handing it off to the parent controller, to sanitize and check against model.
      protected function processPostData($post)
      {

          if (isset($post['display_part']) && strlen($post['display_part']) > 0)
          {
              $this->display_part = sanitize_text_field($post['display_part']);
          }
          unset($post['display_part']);

          // analyse the save button
          if (isset($post['save_bulk']))
          {
            $this->do_redirect = true;
          }
          unset($post['save_bulk']);
          unset($post['save']);

          // handle 'reverse' checkbox.
          $keepExif = isset($post['removeExif']) ? 0 : 1;
          $post['keepExif'] = $keepExif;
          unset($post['removeExif']);

          // checkbox overloading
          $png2jpg = (isset($post['png2jpg']) ? (isset($post['png2jpgForce']) ? 2 : 1): 0);
          $post['png2jpg'] = $png2jpg;
          unset($post['png2jpgForce']);

          // must be an array
          $post['excludeSizes'] = (isset($post['excludeSizes']) && is_array($post['excludeSizes']) ? $post['excludeSizes']: array());



          // when adding a new custom folder
          if (isset($post['addCustomFolder']) && strlen($post['addCustomFolder']) > 0)
          {
            $folderpath = sanitize_text_field(stripslashes($post['addCustomFolder']));

            $otherMedia = OtherMediaController::getInstance();;
            $result = $otherMedia->addDirectory($folderpath);
            if ($result)
            {
              Notice::addSuccess(__('Folder added successfully.','shortpixel-image-optimiser'));
            }
          }
          unset($post['addCustomFolder']);

          if(isset($post['removeFolder']) && intval($post['removeFolder']) > 0) {
              $folder_id = intval($post['removeFolder']);
              $otherMedia = OtherMediaController::getInstance();
              $dirObj = $otherMedia->getFolderByID($folder_id);

              if ($dirObj === false)
                return;

              $dirObj->delete();

          }
          unset($post['removeFolder']);

          if (isset($post['emptyBackup']))
          {
						  if (wp_verify_nonce($_POST['tools-nonce'], 'empty-backup'))
							{
								$dir = \wpSPIO()->filesystem()->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
	              $dir->recursiveDelete();
							}
							else {
								exit('Invalid Nonce in empty backups');
							}

          }
          unset($post['emptyBackup']);


          $post = $this->processWebp($post);
          $post = $this->processExcludeFolders($post);
          $post = $this->processCloudFlare($post);

          parent::processPostData($post);

					// Unset front optimization when automedialib. is off
					if ($this->postData['autoMediaLibrary'] == 0)
					{
						 $this->postData['frontBootstrap'] = 0;
					}

      }

      /** Function for the WebP settings overload
      *
      */
      protected function processWebP($post)
      {
        $deliverwebp = 0;
        if (! $this->is_nginx)
          \ShortPixelTools::alterHtaccess(false, false); // always remove the statements.

			  $webpOn = isset($post['createWebp']) && $post['createWebp'] == 1;
				$avifOn = isset($post['createAvif']) && $post['createAvif'] == 1;

    //    if ($webpOn || $avifOn)
    //    {
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
      //  }

        if (! $this->is_nginx && $deliverwebp == 3) // deliver webp/avif via htaccess, write rules
        {
          \ShortPixelTools::alterHtaccess(true, true);
        }

         $post['deliverWebp'] = $deliverwebp;
         unset($post['deliverWebpAlteringType']);
         unset($post['deliverWebpType']);

         return $post;
      }

      protected function processExcludeFolders($post)
      {
        $patterns = array();
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
								 Notice::addWarning(sprintf(__('Regular Expression Pattern %s returned an error. Please check if the expression is correct. %s * Special characters should be escaped. %s * A regular expression must be contained between two slashes  ', 'shortpixel-image-optimser'), $pattern, "<br>", "<br>" ));
							}
						}
				}
        $post['excludePatterns'] = $patterns;
        return $post;
      }

      protected function processCloudFlare($post)
      {
        if (isset($post['cf_auth_switch']) && $post['cf_auth_switch'] == 'token')
        {
            if (isset($post['cloudflareAuthKey']))
              unset($post['cloudflareAuthKey']);

            if (isset($post['cloudflareEmail']))
              unset($post['cloudflareEmail']);

        }
        elseif (isset($post['cloudflareAuthKey']) && $post['cf_auth_switch'] == 'global')
        {
            if (isset($post['cloudflareToken']))
               unset($post['cloudflareToken']);
        }

        return $post;
      }


      protected function doRedirect($redirect = 'self')
      {
        if ($redirect == 'self')
        {

          $url = esc_url_raw(add_query_arg('part', $this->display_part));
          $url = remove_query_arg('noheader', $url); // has url
          $url = remove_query_arg('sp-action', $url); // has url

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

        wp_redirect($url);
        exit();
      }


}
