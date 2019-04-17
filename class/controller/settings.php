<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger as Log;
use ShortPixel\DebugItem as DebugItem;

class SettingsController extends shortPixelController
{
     protected $model;

     //env
     protected $is_nginx;
     protected $has_verifiedkey;
     protected $is_htaccess_writable;
     protected $is_multisite;
     protected $is_mainsite;
     protected $is_constant_key;
     protected $hide_api_key;
     protected $has_nextgen;

     protected $quotaData = null;


      public function __construct()
      {
          $this->model = new \WPShortPixelSettings();
          parent::__construct();

          $this->loadModel('notice');

          // @todo Remove Debug Call
          Log::logLevel(DebugItem::LEVEL_DEBUG);
      }

      public function load()
      {
        $this->model->redirectedSettings = 2; // not sure what this does.
        $this->loadEnv();

        if ($this->is_form_submit)
        {
          $this->processSave();
        }

        $this->display_part = isset($_GET['part']) ? sanitize_text_field($_GET['part']) : 'settings';
        $this->load_settings();

      }

      public function processSave()
      {
          Log::addDebug('postData', $this->postData);
          // Split this in the several screens. I.e. settings, advanced, Key Request IF etc.
      }

      public function load_settings()
      {
         $this->loadQuotaData();
         $this->view->data = (Object) $this->model->getData();
         $this->view->minSizes = $this->getMaxIntermediateImageSize();
         $this->view->customFolders= $this->loadCustomFolders();
         $this->view->allThumbSizes = $this->shortPixel->getAllThumbnailSizes();
         $this->view->averageCompression = $this->shortPixel->getAverageCompression();

         $this->view->savedBandwidth = \WpShortPixel::formatBytes($this->view->data->savedSpace * 10000,2);


         Log::addDebug($this->view);
         //Log::addDebug($this->display_part);
         $this->loadView('view-settings');
      }

      /** Checks on things and set them for information. */
      public function loadEnv()
      {
          $verified_key = $this->model->verifiedKey;
          $this->is_verifiedkey = ($verified_key) ? true : false;

          $this->is_nginx = strpos($_SERVER["SERVER_SOFTWARE"], 'nginx') !== false ? true : false;
          $this->is_gd_installed = function_exists('imagecreatefrompng');
          $this->is_curl_installed = function_exists('curl_init');

          $this->is_htaccess_writable = $this->HTisWritable();

          $this->is_multisite = (function_exists("is_multisite") && is_multisite()) ? true : false;
          $this->is_mainsite = is_main_site();

          $this->is_constant_key = (defined("SHORTPIXEL_API_KEY")) ? true : false;
          $this->hide_api_key = (defined("SHORTPIXEL_HIDE_API_KEY")) ? true : false;

          $this->has_nextgen = \ShortPixelNextGenAdapter::hasNextGen();

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
           // @todo Add notice here.
        }

        return $customFolders;
      }

      protected function processPostData($post)
      {
        parent::processPostData($post);
        $model_fields = $this->model->getModel();
        $post_fields = array_keys($this->postData);

        $missing_fields = array_diff($model_fields, $post_fields);

        /** Way to get unchecked checkboxes and the lot. **/
        foreach($missing_fields as $field_name)
        {
          if ($this->model->getType($field_name) === 'boolean')
          {
            $this->postData[$field_name] = 0;
          }
        }


      }


}
