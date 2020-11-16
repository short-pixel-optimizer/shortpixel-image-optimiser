<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use ShortPixel\Model\ApiKeyModel as ApiKeyModel;

use ShortPixel\NextGen as NextGen;

class SettingsController extends \ShortPixel\Controller
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

     protected $keyModel;

     protected $mapper = array(
       'key' => 'apiKey',
       'cmyk2rgb' => 'CMYKtoRGBconversion',
     );

     protected $display_part = 'settings';
     protected $form_action = 'save-settings';

      public function __construct()
      {
          // @todo Remove Debug Call
          $this->model = new \WPShortPixelSettings();
          $this->keyModel = new ApiKeyModel();

          parent::__construct();
      }

      // glue method.
      public function setShortPixel($pixel)
      {
        parent::setShortPixel($pixel);
        $this->keyModel->shortPixel = $pixel;

        // It's loading here since it can do validation, which requires Shortpixel.
        // Otherwise this should be loaded on construct.
        $this->keyModel->loadKey();
        $this->is_verifiedkey = $this->keyModel->is_verified();
        $this->is_constant_key = $this->keyModel->is_constant();
        $this->hide_api_key = $this->keyModel->is_hidden();
      }

      // default action of controller
      public function load()
      {
        $this->loadEnv();
        $this->checkPost(); // sets up post data

        $this->model->redirectedSe_settingsttings = 2; // Prevents any redirects after loading settings

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

      /* Custom Media, refresh a single Folder */
      public function action_refreshfolder()
      {
         $folder_id = isset($_REQUEST['folder_id']) ? intval($_REQUEST['folder_id']) : false;

         if ($folder_id)
         {
            $otherMediaController = new OtherMediaController();
            $folder = $otherMediaController->getFolderByID($folder_id);

            if ($folder)
            {
               $otherMediaController->refreshFolder($folder, true);
            }

         }

         $this->load();
      }


      public function action_debug_medialibrary()
      {
        $this->loadEnv();

        \WpShortPixelMediaLbraryAdapter::reCountMediaLibraryItems();

        $this->load();
      }



      public function processSave()
      {
          // Split this in the several screens. I.e. settings, advanced, Key Request IF etc.
          if ($this->postData['includeNextGen'] == 1)
          {
              $nextgen = new NextGen();
              $previous = $this->model->includeNextGen;
              $nextgen->nextGenEnabled($previous);

              // Reset any integration notices when updating settings.
              AdminNoticesController::resetIntegrationNotices();
          }

          $check_key = false;
          if (isset($this->postData['apiKey']))
          {
              $check_key = $this->postData['apiKey'];
              unset($this->postData['apiKey']); // unset, since keyModel does the saving.
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
         if ($this->is_verifiedkey) // supress quotaData alerts when handing unset API's.
          $this->loadQuotaData();
        else
          \WpShortPixelDb::checkCustomTables();

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

         $this->view->cloudflare_constant = defined('SHORTPIXEL_CFTOKEN') ? true : false;

         $settings = \wpSPIO()->settings();
         $this->view->dismissedNotices = $settings->dismissedNotices;

         $this->loadView('view-settings');
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

          $this->display_part = isset($_GET['part']) ? sanitize_text_field($_GET['part']) : 'settings';
      }

      /* Temporary function to check if HTaccess is writable.
      * HTaccess is writable if it exists *and* is_writable, or can be written if directory is writable.
      * @todo Should be replaced when File / Folder model are complete. Function check should go there.
      */
      private function HTisWritable()
      {
          if ($this->is_nginx)
            return false;

          if (file_exists(get_home_path() . '.htaccess') && is_writable(get_home_path() . '.htaccess'))
          {
            return true;
          }
          elseif (! file_exists(get_home_path() . '.htaccess') && file_exists(get_home_path()) && is_writable(get_home_path()))
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

        $otherMedia = new OtherMediaController();

        $otherMedia->refreshFolders();
        $customFolders = $otherMedia->getActiveFolders();
        $fs = \wpSPIO()->filesystem();

        $customFolderBase = $fs->getWPFileBase();
        $this->view->customFolderBase = $customFolderBase->getPath();

        if ($this->has_nextgen)
        {
          $ng = NextGen::getInstance();
          $NGfolders = $ng->getGalleries();
          $foldersArray = array();

          foreach($NGfolders as $folder)
          {
            $fsFolder = $fs->getDirectory($folder->getPath());
            $foldersArray[] = $fsFolder->getPath();
          }

          foreach($customFolders as $index => $folder)
          {
            if(in_array($folder->getPath(), $foldersArray )) {
                $folder->setNextGen(true);
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

          // when adding a new custom folder
          if (isset($post['addCustomFolder']) && strlen($post['addCustomFolder']) > 0)
          {
            $folderpath = sanitize_text_field(stripslashes($post['addCustomFolder']));

            $otherMedia = new OtherMediaController();
            $result = $otherMedia->addDirectory($folderpath);
            if ($result)
            {
              Notice::addSuccess(__('Folder added successfully.','shortpixel-image-optimiser'));
            }
          }
          unset($post['addCustomFolder']);

          if(isset($post['removeFolder']) && intval($post['removeFolder']) > 0) {
              //$metaDao = $this->shortPixel->getSpMetaDao();
              $folder_id = intval($post['removeFolder']);
              $otherMedia = new OtherMediaController();
              $folder = $otherMedia->getFolderByID($folder_id);

            //  Log::addDebug('Removing folder ' . $post['removeFolder']);
              $folder->delete();
              //$metaDao->removeFolder( sanitize_text_field($post['removeFolder']) );

          }
          unset($post['removeFolder']);

          if (isset($post['emptyBackup']))
          {
            $this->shortPixel->emptyBackup();
          }
          unset($post['emptyBackup']);


          $post = $this->processWebp($post);
          $post = $this->processExcludeFolders($post);
          $post = $this->processCloudFlare($post);

          parent::processPostData($post);

      }

      /** Function for the WebP settings overload
      *
      */
      protected function processWebP($post)
      {
        $deliverwebp = 0;
        if (! $this->is_nginx)
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
/*                if(count($parts) == 1) {
                    $patterns[] = array("type" =>"name", "value" => str_replace('\\\\','\\',trim($pat)));
                } else {
                    $patterns[] = array("type" =>trim($parts[0]), "value" => str_replace('\\\\','\\',trim($parts[1])));
                } */
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


}
