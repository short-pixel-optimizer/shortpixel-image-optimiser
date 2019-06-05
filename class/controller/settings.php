<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger as Log;
use ShortPixel\DebugItem as DebugItem;
use ShortPixel\NoticeController as Notice;


class SettingsController extends shortPixelController
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
     protected $postkey_needs_validation = false;

     protected $quotaData = null;

     protected $mapper = array(
       'key' => 'apiKey',
       'cmyk2rgb' => 'CMYKtoRGBconversion',
     );

     protected $display_part = 'settings';

      public function __construct()
      {
          // @todo Remove Debug Call
          $this->model = new \WPShortPixelSettings();


          parent::__construct();

      }

      // default action of controller
      public function load()
      {
        $this->loadEnv();
        $this->checkPost(); // sets up post data

        $this->model->redirectedSettings = 2; // not sure what this does.
        $this->checkKey(); // needs post data


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

        Log::addDebug($this->postData);
        if ($this->is_form_submit && isset($this->postData['apiKey']))
        {
            $this->checkKey();
            if (isset($this->postData['verifiedKey']) && $this->postData['verifiedKey'])
            {
              $this->model->apiKey = $this->postData['apiKey'];
              $this->model->verifiedKey = $this->postData['verifiedKey'];
            }
        }

        $this->doRedirect();
        //exit();

      }

      public function processSave()
      {
          Log::addDebug('after process postData', $this->postData);
          // Split this in the several screens. I.e. settings, advanced, Key Request IF etc.

          if ($this->postData['includeNextGen'] == 1)
          {
              $nextgen = new NextGen($this->shortPixel);
              $previous = $this->model->includeNextGen;
              $nextgen->nextGenEnabled($previous);
          }

          // write checked and verified post data to model. With normal models, this should just be call to update() function
          foreach($this->postData as $name => $value)
          {
            $this->model->{$name} = $value;
          }

          // end
          if ($this->do_redirect)
            $this->doRedirect('bulk');
          else {
            $this->doRedirect();
          }
      }

      /* Loads the view data and the view */
      public function load_settings()
      {
         $this->loadQuotaData();
         $this->view->data = (Object) $this->model->getData();
         if (($this->is_constant_key))
             $this->view->data->apiKey = SHORTPIXEL_API_KEY;

         $this->view->minSizes = $this->getMaxIntermediateImageSize();
         $this->view->customFolders= $this->loadCustomFolders();
         $this->view->allThumbSizes = $this->shortPixel->getAllThumbnailSizes();
         $this->view->averageCompression = $this->shortPixel->getAverageCompression();
         $this->view->savedBandwidth = \WpShortPixel::formatBytes($this->view->data->savedSpace * 10000,2);
         $this->view->resources = wp_remote_post($this->model->httpProto . "://shortpixel.com/resources-frag");
         if (is_wp_error($this->view->resources))
            $this->view->resources = null;

         $this->loadView('view-settings');
      }

      /** Checks on things and set them for information. */
      public function loadEnv()
      {
          $this->is_nginx = strpos($_SERVER["SERVER_SOFTWARE"], 'nginx') !== false ? true : false;
          $this->is_gd_installed = function_exists('imagecreatefrompng');
          $this->is_curl_installed = function_exists('curl_init');

          $this->is_htaccess_writable = $this->HTisWritable();

          $this->is_multisite = (function_exists("is_multisite") && is_multisite()) ? true : false;
          $this->is_mainsite = is_main_site();

          $this->has_nextgen = \ShortPixelNextGenAdapter::hasNextGen();

          $this->display_part = isset($_GET['part']) ? sanitize_text_field($_GET['part']) : 'settings';
      }

      /** Check if everything is OK with the Key **/
      public function checkKey()
      {
          $this->is_constant_key = (defined("SHORTPIXEL_API_KEY")) ? true : false;
          $this->hide_api_key = (defined("SHORTPIXEL_HIDE_API_KEY")) ? SHORTPIXEL_HIDE_API_KEY : false;

          $verified_key = $this->model->verifiedKey;
          $this->is_verifiedkey = ($verified_key) ? true : false;

          $key_in_db = $this->model->apiKey;

          //Log::addDebug()
          if($this->is_constant_key)
          {
              if (strlen(SHORTPIXEL_API_KEY) <> 20)
              {
                $this->noticeApiKeyLength(SHORTPIXEL_API_KEY);
              }
              elseif ($key_in_db != SHORTPIXEL_API_KEY)
              {
                $this->validateKey(SHORTPIXEL_API_KEY);
              }
          }
          elseif ($this->postkey_needs_validation)
          {
            $key = isset($this->postData['apiKey']) ? $this->postData['apiKey'] : $this->model->apiKey;
            if (strlen($key) <> 20)
            {
                $this->NoticeApiKeyLength($key);
            }
            else // key good to go.
            {
              $this->validateKey($key);
            }
          } // postkey_needs_validation
      }

      /** Check remotely if key is alright **/
      public function validateKey($key)
      {
        Log::addDebug('Validating Key ' . $key);
        // first, save Auth to satisfy getquotainformation
        if ($this->is_form_submit)
        {
          if (strlen($this->postData['siteAuthUser']) > 0 || strlen($this->postData['siteAuthPass']) > 0)
          {
            $this->model->siteAuthUser = $this->postData['siteAuthUser'];
            $this->model->siteAuthPass = $this->postData['siteAuthPass'];
          }
        }

         $this->quotaData = $this->shortPixel->getQuotaInformation($key, true, 'validate', $this->postData);
         $this->is_verifiedkey = ($this->quotaData['APIKeyValid']) ? true : false;

         Log::addDebug('Verify Result', $this->quotaData);

         if ($this->is_form_submit) // are we saving a form?
         {
           $this->postData['verifiedKey'] = $this->is_verifiedkey;
           $this->postData['apiKey'] = $key;
         }
         else { // if not, put it to the model directly.
           $this->model->verifiedKey = $this->is_verifiedkey;
           $this->model->apiKey = $key;
         }


         if (! $this->is_verifiedkey)
         {
            Notice::addError(sprintf(__('Error during verifying API key: %s','shortpixel-image-optimizer'), $this->quotaData['Message'] ));
         }
         elseif ($this->is_form_submit) {
           $this->processNewKey();
         }

      }

      /** Process some things when key has been added. This is from original wp-short-pixel.php */
      protected function processNewKey()
      {
        $lastStatus = $this->model->bulkLastStatus;
        if(isset($lastStatus['Status']) && $lastStatus['Status'] == \ShortPixelAPI::STATUS_NO_KEY) {
            $this->model->bulkLastStatus = null;
        }
        //display notification
        $urlParts = explode("/", get_site_url());
        if( $this->quotaData['DomainCheck'] == 'NOT Accessible'){
            $notice = array("status" => "warn", "msg" => __("API Key is valid but your site is not accessible from our servers. Please make sure that your server is accessible from the Internet before using the API or otherwise we won't be able to optimize them.",'shortpixel-image-optimiser'));
            Notice::addWarning($notice);
        } else {
            if ( function_exists("is_multisite") && is_multisite() && !defined("SHORTPIXEL_API_KEY"))
                $notice = __("Great, your API Key is valid! <br>You seem to be running a multisite, please note that API Key can also be configured in wp-config.php like this:",'shortpixel-image-optimiser')
                    . "<BR> <b>define('SHORTPIXEL_API_KEY', '". $this->postData['apiKey'] ."');</b>";
            else
                $notice = __('Great, your API Key is valid. Please take a few moments to review the plugin settings below before starting to optimize your images.','shortpixel-image-optimiser');

            Notice::addSuccess($notice);
        }

        //test that the "uploads"  have the right rights and also we can create the backup dir for ShortPixel
        if ( !file_exists(SHORTPIXEL_BACKUP_FOLDER) && ! \ShortPixelFolder::createBackUpFolder() )
        {
            $notice = sprintf(__("There is something preventing us to create a new folder for backing up your original files.<BR>Please make sure that folder <b>%s</b> has the necessary write and read rights.",'shortpixel-image-optimiser'),
                                 WP_CONTENT_DIR . '/' . SHORTPIXEL_UPLOADS_NAME );
           Notice::addError($notice);
        }
      }

      /* Temporary function to check if HTaccess is writable.
      * HTaccess is writable if it exists *and* is_writable, or can be written if directory is writable.
      * @todo Should be replaced when File / Folder model are complete. Function check should go there.
      */
      private function HTisWritable()
      {
          if ($this->is_nginx)
            return false;

          if (file_exists(get_home_path() . 'htaccess') && is_writable(get_home_path() . 'htaccess'))
          {
            return true;
          }
          if (file_exists(get_home_path()) && is_writable(get_home_path()))
          {
            return true;
          }
          return false;
          //  (is_writable(get_home_path() . 'htaccess')) ? true : false;
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

      protected function loadQuotaData()
      {
        // @todo Probably good idea to put this in a 2-5 min transient or so.
        if (is_null($this->quotaData))
          $this->quotaData = $this->shortPixel->checkQuotaAndAlert();

        $quotaData = $this->quotaData;
        $this->view->thumbnailsToProcess = isset($quotaData['totalFiles']) ? ($quotaData['totalFiles'] - $quotaData['mainFiles']) - ($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles']) : 0;

        $remainingImages = $quotaData['APICallsRemaining'];
        $remainingImages = ( $remainingImages < 0 ) ? 0 : number_format($remainingImages);
        $this->view->remainingImages = $remainingImages;

        $this->view->totalCallsMade = array( 'plan' => $quotaData['APICallsMadeNumeric'] , 'oneTime' => $quotaData['APICallsMadeOneTimeNumeric'] );

      }

      protected function loadCustomFolders()
      {
        $notice = null;
        $customFolders = $this->shortPixel->refreshCustomFolders($notice);

        if (! is_null($notice))
        {
          $message = $notice['msg'];
          if ($notice['status'] == 'error')
            Notice::addError($message);
          else
            Notice::addNormal($message);


        }

        if ($this->has_nextgen)
        {
          $ngg = array_map(array('ShortPixelNextGenAdapter','pathToAbsolute'), \ShortPixelNextGenAdapter::getGalleries());
          for($i = 0; $i < count($customFolders); $i++) {
              if(in_array($customFolders[$i]->getPath(), $ngg )) {
                  $customFolders[$i]->setType("NextGen");
                }
              }
        }
        return $customFolders;
      }

      // This is done before handing it off to the parent controller, to sanitize and check against model.
      protected function processPostData($post)
      {
        Log::addDebug('raw post data', $post);

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

          // key check, if validate is set to valid, check the key
          if (isset($post['validate']))
          {
            if ($post['validate'] == 'validate')
              $this->postkey_needs_validation = true;

            unset($post['validate']);
          }

          if (isset($post['addCustomFolder']) && strlen($post['addCustomFolder']) > 0)
          {
            $folder = sanitize_text_field(stripslashes($post['addCustomFolder']));
            $uploadPath = realpath(SHORTPIXEL_UPLOADS_BASE);

            $metaDao = $this->shortPixel->getSpMetaDao();
            $folderMsg = $metaDao->newFolderFromPath($folder, $uploadPath, \WPShortPixel::getCustomFolderBase());
            $is_warning = true;
            if(!$folderMsg) {
                //$notice = array("status" => "success", "msg" => __('Folder added successfully.','shortpixel-image-optimiser'));
                $folderMsg = __('Folder added successfully.','shortpixel-image-optimiser');

                $is_warning = false;
            }
            if ($is_warning)
              Notice::addWarning($folderMsg);
            else
              Notice::addNormal($folderMsg);

            $this->model->hasCustomFolders = time();
          }
          unset($post['addCustomFolder']);

          if(isset($post['removeFolder']) && strlen( trim($post['removeFolder'])) > 0) {
              $metaDao = $this->shortPixel->getSpMetaDao();
              Log::addDebug('Removing folder ' . $post['removeFolder']);
              $metaDao->removeFolder( sanitize_text_field($post['removeFolder']) );

          }
          unset($post['removeFolder']);

          if (isset($post['emptyBackup']))
          {
            $this->shortPixel->emptyBackup();
          }
          unset($post['emptyBackup']);


          $post = $this->processWebp($post);
          $post = $this->processExcludeFolders($post);

          parent::processPostData($post);

      }

      /** Function for the WebP settings overload
      *
      */
      protected function processWebP($post)
      {
        $deliverwebp = 0;
        \WPShortPixel::alterHtaccess(true); // always remove the statements.

        if (isset($post['createWebp']) && $post['createWebp'] == 1)
        {
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
        }

        if (! $this->is_nginx && $deliverwebp == 3) // unaltered wepb via htaccess
        {
          \WPShortPixel::alterHtaccess();
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
                if(count($parts) == 1) {
                    $patterns[] = array("type" =>"name", "value" => str_replace('\\\\','\\',trim($pat)));
                } else {
                    $patterns[] = array("type" =>trim($parts[0]), "value" => str_replace('\\\\','\\',trim($parts[1])));
                }
            }

        }
        $post['excludePatterns'] = $patterns;
        return $post;
      }


      protected function doRedirect($redirect = 'self')
      {
        if ($redirect == 'self')
        {
          $url = add_query_arg('part', $this->display_part);
          $url = remove_query_arg('noheader', $url);
          $url = remove_query_arg('sp-action', $url);
        }
        elseif($redirect == 'bulk')
        {
          $url = "upload.php?page=wp-short-pixel-bulk";
        }
        Log::addDebug('Redirecting: ', $url );
        wp_redirect($url);
        exit();
      }

      protected function NoticeApiKeyLength($key)
      {
        $KeyLength = strlen($key);

        $notice =  sprintf(__("The key you provided has %s characters. The API key should have 20 characters, letters and numbers only.",'shortpixel-image-optimiser'), $KeyLength)
                   . "<BR> <b>"
                   . __('Please check that the API key is the same as the one you received in your confirmation email.','shortpixel-image-optimiser')
                   . "</b><BR> "
                   . __('If this problem persists, please contact us at ','shortpixel-image-optimiser')
                   . "<a href='mailto:help@shortpixel.com?Subject=API Key issues' target='_top'>help@shortpixel.com</a>"
                   . __(' or ','shortpixel-image-optimiser')
                   . "<a href='https://shortpixel.com/contact' target='_blank'>" . __('here','shortpixel-image-optimiser') . "</a>.";
        Notice::addError($notice);
      }
}
