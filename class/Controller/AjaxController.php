<?php

namespace ShortPixel\Controller;


use ShortPixel\Controller\View\ListMediaViewController as ListMediaViewController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

//use ShortPixel\Controller\BulkController as BulkController;

// Class for containing all Ajax Related Actions.
class AjaxController
{
    private static $instance;

    public static function getInstance()
    {
       if (is_null(self::$instance))
         self::$instance = new AjaxController();

      return self::$instance;
    }

    // Support for JS Processor
    public function getProcessorKey()
    {
      // Get a Secret Key.
      $cacheControl = new CacheController();
      $bulkSecret = $cacheControl->getItem('bulk-secret');

      $secretKey = $bulkSecret->getValue();
      if (is_null($secretKey) || strlen($secretKey) == 0)
        $secretKey = false;


      return $secretKey;
    }


    public function ajax_removeProcessorKey()
    {
      Log::addDebug('Ajax HIT - Process Exiting');
      Log::addDebug($_POST);
        $this->checkNonce('exit_process');
      Log::addDebug('Process Exiting');

        $cacheControl = new CacheController();
        $cacheControl->deleteItem('bulk-secret');

        $json = new \stdClass;
        $json->status = 0;
        $this->send($json);

    }

    public function ajax_getItemView()
    {
        $this->checkNonce('item_view');

          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
          $id = isset($_POST['id']) ? intval($_POST['id']) : false;

          if ($id > 0)
          {
             if ($type == 'media')
             {
               ob_start();
                  $control = new ListMediaViewController();
                  $control->doColumn('wp-shortPixel', $id);
                $result = ob_get_contents();
                ob_end_clean();
             }
          }

          $json = new \stdClass;
          $json->$type = new \stdClass;
          $json->$type->result = $result;
          $json->$type->id = $id;
          $json->$type->results = null;
          $json->$type->is_error = false;
          $json->status = true;

          $this->send($json);
    }


    public function ajax_processQueue()
    {
        $this->checkNonce('processing');

        if (isset($_POST['bulk-secret']))
        {
          $secret = sanitize_text_field($_POST['bulk-secret']);
          $cacheControl = new CacheController();
          $cachedObj = $cacheControl->getItem('bulk-secret');

          if (! $cachedObj->exists())
          {
             $cachedObj->setValue($secret);
             $cachedObj->setExpires(3 * MINUTE_IN_SECONDS);
             $cacheControl->storeItemObject($cachedObj);
          }
        }

        $control = OptimizeController::getInstance();
        $result = $control->processQueue();

        $this->send($result);
    }



    public function ajaxRequest()
    {
        $this->checkNonce('ajax_request');

        $action = isset($_POST['screen_action']) ? sanitize_text_field($_POST['screen_action']) : false;
        $type = isset($_POST['type'])  ? sanitize_text_field($_POST['type']) : 'media';
        $id = isset($_POST['id']) ? intval($_POST['id']) : false;

        $json = new \stdClass;
        $json->$type = new \stdClass;

        $json->$type->id = $id;
        $json->$type->results = null;
        $json->$type->is_error = false;
        $json->status = 0;

        $data = array('id' => $id, 'type' => $type, 'action' => $action);

        switch($action)
        {
           case 'restoreItem':
              $json = $this->restoreItem($json, $data);
           break;
           case 'reOptimizeItem':
             $json = $this->reOptimizeItem($json, $data);
           break;
           case 'optimizeItem':
             $json = $this->optimizeItem($json, $data);
           break;
           case 'createBulk':
             $json = $this->createBulk($json, $data);
           break;
           case 'startBulk':
             $json = $this->startBulk($json, $data);
           break;
           default:
              $json->$type->message = __('Ajaxrequest - no action found', 'shorpixel-image-optimiser');
           break;

        }

        $this->send($json);

    }

    public function getMediaItem($id, $type)
    {
      $fs = \wpSPIO()->filesystem();
      return $fs->getImage($id, $type);


    }

    public function optimizeItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

          $mediaItem = $this->getMediaItem($id, $type);

          $control = OptimizeController::getInstance();
          $json->$type = $control->addItemToQueue($mediaItem);

          ResponseController::add()->withMessage('TESTING');
          return $json;
        //  $this->send($json);
    }

    public function restoreItem($json, $data)
    {
      $id = $data['id'];
      $type =$data['type']; // isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

      $mediaItem = $this->getMediaItem($id, $type);
      $control = OptimizeController::getInstance();
      // @todo Turn back on, when ok.
      $json->$type = $control->restoreItem($mediaItem);

      return $json;
    }

    public function reOptimizeItem($json, $data)
    {
       $id = $data['id'];
       $type = $data['type'];
       $compressionType = isset($_POST['compressionType']) ? intval($_POST['compressionType']) : 0;
       $mediaItem = $this->getMediaItem($id, $type);

       $control = OptimizeController::getInstance();

       $json->$type = $control->reOptimizeItem($mediaItem, $compressionType);
       return $json;
    }


    public function createBulk($json, $data)
    {
        $bulkControl = BulkController::getInstance();
        $stats = $bulkControl->createNewBulk('media');

        $json->media->stats = $stats;

        return $json;

    }

    public function startBulk($json, $data)
    {
        $bulkControl = BulkController::getInstance();
        $result = $bulkControl->startBulk('media');

        $this->send($result);
    }

    /** Data for the compare function */
    public function ajax_getComparerData() {

        $this->checkNonce('ajax_request');

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
        $id = isset($_POST['id']) ? intval($_POST['id']) : false;

        if ( $id === false || !current_user_can( 'upload_files' ) && !current_user_can( 'edit_posts' ) )  {

            $json->status = 0;
            $json->id = $id;
            $json->message = __('Error - item to compare could not be found or no access', 'shortpixel-image-optimiser');
          //  ResponseController::add()->withMessage($json->message)->asError();
            $this->send($json);
        }

        $ret = array();
        $fs = \wpSPIO()->filesystem();

        $imageObj = $fs->getImage($id, $type);

        $backupFile = $imageObj->getBackupFile();
        if (is_object($backupFile))
          $backup_url = $fs->pathToUrl($backupFile);
        else
          $backup_url = '';

        $ret['origUrl'] = $backup_url; // $backupUrl . $urlBkPath . $meta->getName();

          $ret['optUrl'] = $fs->pathToUrl($imageObj); // $uploadsUrl . $meta->getWebPath();
          $ret['width'] = $imageObj->getMeta('actualWidth'); // $meta->getActualWidth();
          $ret['height'] = $imageObj->getMeta('actualWidth');

          if (is_null($ret['width']))
          {

              $ret['width'] = $imageObj->get('width'); // $imageSizes[0];
              $ret['height']= $imageObj->get('height'); //imageSizes[1];

          }

        $this->send($ret);
    }

    protected function checkNonce($action)
    {
      if (! wp_verify_nonce($_POST['nonce'], $action))
      {
        $json = new \stdClass;
        $json->message = __('Nonce is missing or wrong', 'shortpixel-image-optimiser');
        $json->status = false;
        $this->send($json);
      }

    }

    protected function send($json)
    {
        $json->responses = ResponseController::getAll();

        $callback = isset($_POST['callback']) ? sanitize_text_field($_POST['callback']) : false;
        if ($callback)
          $json->callback = $callback; // which type of request we just fullfilled ( response processing )

        wp_send_json($json);
        exit();
    }


    /** Generate the action output for an item, via UIHelper */
    protected function getActions($id)
    {

    }


}
