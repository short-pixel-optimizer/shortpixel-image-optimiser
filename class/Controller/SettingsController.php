<?php
namespace ShortPixel\Controller;

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

use ShortPixel\NextGenController as NextGenController;

class SettingsController extends \ShortPixel\Controller
{

		 protected static $instance;
     protected $model;

      public function __construct()
      {
          $this->model = \wpSPIO()->settings();
					$keyControl = ApiKeyController::getInstance();
          $this->keyModel = $keyControl->getKeyModel();

          parent::__construct();

					$this->load();
      }

			/* Loads the view data and the view */
			public function load()
			{
			 if (false === $this->keyModel->is_verified()) // supress quotaData alerts when handing unset API's.
			 {
				InstallHelper::checkTables();
			 }

			}

			public function getInstance()
			{
				if (is_null(self::$instance))
				 self::$instance = new static();

			 return self::$instance;
			}


      // this is the nokey form, submitting api key
      public function action_addkey()
      {
        $this->loadEnv();

        $this->checkPost();

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

					$bodyArgs = array(
							'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
							'email' => $email,
							'ip' => isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? sanitize_text_field($_SERVER["HTTP_X_FORWARDED_FOR"]) : sanitize_text_field($_SERVER['REMOTE_ADDR']),
					);

					$affl_id = false;
					$affl_id = (defined('SHORTPIXEL_AFFILIATE_ID')) ? SHORTPIXEL_AFFILIATE_ID : false;
					$affl_id = apply_filters('shortpixel/settings/affiliate', $affl_id); // /af/bla35

					if ($affl_id !== false)
					{
						 $bodyArgs['affiliate'] = $affl_id;
 					}

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
						 Notice::addError( __('Unexpected error obtaining the ShortPixel key. Please contact support about this:', 'shortpixel-image-optimiser') . '  ' . json_encode($body) );
					}

					$this->doRedirect();

			}

			public function action_debug_redirectBulk()
			{
				$this->checkPost();

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
			}

      /** Button in part-debug, routed via custom Action */
      public function action_debug_resetStats()
      {
          $this->loadEnv();
					$this->checkPost();
          $statsController = StatsController::getInstance();
          $statsController->reset();
					$this->doRedirect();
      }

      public function action_debug_resetquota()
      {

          $this->loadEnv();
					$this->checkPost();
          $quotaController = QuotaController::getInstance();
          $quotaController->forceCheckRemoteQuota();
          $this->doRedirect();
      }

      public function action_debug_resetNotices()
      {
          $this->loadEnv();
					$this->checkPost();
          Notice::resetNotices();
          $nControl = new Notice(); // trigger reload.
          $this->doRedirect();
      }

			public function action_debug_triggerNotice()
			{
				$this->checkPost();
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
				 $this->checkPost();

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

				$this->doRedirect();
			}

			public function action_debug_removePrevented()
			{
				$this->loadEnv();
				$this->checkPost();

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
				//$this->loadEnv();
				$this->checkPost();

				$cacheControl = new CacheController();
				$cacheControl->deleteItem('bulk-secret');
				exit('reloading settings would cause processorKey to be set again');
			}

      protected function processSave()
      {
          // Split this in the several screens. I.e. settings, advanced, Key Request IF etc.
          if (isset($this->postData['includeNextGen']) && $this->postData['includeNextGen'] == 1)
          {
              $nextgen = NextGenController::getInstance();
              $previous = $this->model->includeNextGen;
              $nextgen->enableNextGen(true);

              // Reset any integration notices when updating settings.
              AdminNoticesController::resetIntegrationNotices();
          }


					// If the compression type setting changes, remove all queued items to prevent further optimizing with a wrong type.
					if (intval($this->postData['compressionType']) !== intval($this->model->compressionType))
					{
						 OptimizeController::resetQueues();
					}

          if (isset($_POST['apiKey']) && false === $this->keyModel->is_constant())
          // first save all other settings ( like http credentials etc ), then check
          {
              $check_key = sanitize_text_field($_POST['apiKey']);
              $this->keyModel->resetTried(); // reset the tried api keys on a specific post request.
              $this->keyModel->checkKey($check_key);
          }

          // write checked and verified post data to model. With normal models, this should just be call to update() function
          foreach($this->postData as $name => $value)
          {
            $this->model->{$name} = $value;
          }

					// Every save, force load the quota. One reason, because of the HTTP Auth settings refresh.
					$this->loadQuotaData(true);
          // end

          if ($this->do_redirect)
            $this->doRedirect('bulk');
          else {

						$noticeController = Notice::getInstance();
						$notice = Notice::addSuccess(__('Settings Saved', 'shortpixel-image-optimiser'));
						$notice->is_removable = false;
						$noticeController->update();

            $this->doRedirect();
          }
      }


      // This is done before handing it off to the parent controller, to sanitize and check against model.
      protected function processPostData($post, $model = null)
      {
          if (isset($post['display_part']) && strlen($post['display_part']) > 0)
          {
              $this->display_part = sanitize_text_field($post['display_part']);
          }

          // analyse the save button
          if (isset($post['save_bulk']))
          {
            $this->do_redirect = true;
          }

          // handle 'reverse' checkbox.
          $keepExif = isset($post['removeExif']) ? 0 : 1;
          $post['keepExif'] = $keepExif;

          // checkbox overloading
          $png2jpg = (isset($post['png2jpg']) ? (isset($post['png2jpgForce']) ? 2 : 1): 0);
          $post['png2jpg'] = $png2jpg;

          // must be an array
          $post['excludeSizes'] = (isset($post['excludeSizes']) && is_array($post['excludeSizes']) ? $post['excludeSizes']: array());

          $post = $this->processWebp($post);
          $post = $this->processExcludeFolders($post);
        //  $post = $this->processCloudFlare($post);

					$check_key = false;

					/*   This can't be here, no actions in the data check, because of actions
          if (isset($post['apiKey']))

					{
							$check_key = sanitize_text_field($post['apiKey']);
							unset($post['apiKey']); // unset, since keyModel does the saving.
					}

					// first save all other settings ( like http credentials etc ), then check
          if (false === $this->keyModel->is_constant() && $check_key !== false) // don't allow settings key if there is a constant
          {
            $this->keyModel->resetTried(); // reset the tried api keys on a specific post request.
            $this->keyModel->checkKey($check_key);
          } */

				// Field that are in form for other purpososes, but are not part of model and should not be saved.
					$ignore_fields = array(
							'display_part',
							'save_bulk',
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
							'pluginemail'

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






} // class
