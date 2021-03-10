<?php

namespace ShortPixel\Controller;

use ShortPixel\Controller\View\ListMediaViewController as ListMediaViewController;
use ShortPixel\Controller\View\OtherMediaViewController as OtherMediaViewController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

//use ShortPixel\Controller\BulkController as BulkController;


// Class for containing all Ajax Related Actions.
class AjaxController
{
    const PROCESSOR_ACTIVE = -1;
    const NONCE_FAILED = -2;
    const NO_ACTION = -3;

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
      {
        $secretKey = false;
      }
      return $secretKey;
    }

    public function checkProcessorKey()
    {
      $processKey = $this->getProcessorKey();
      $bulkSecret = isset($_POST['bulk-secret']) ? sanitize_text_field($_POST['bulk-secret']) : false;
      $isBulk = isset($_POST['isBulk']) ? (bool) sanitize_text_field($_POST['isBulk'])  : false;

      Log::addTemp("ProcessKey $processKey - BulkSecret $bulkSecret");


      $is_processor = false;
      if ($processKey == false && $bulkSecret !== false)
      {
          $is_processor = true;
      }
      elseif ($processKey == $bulkSecret)
      {
         $is_processor = true;
      }
      elseif ($isBulk)
      {
         $is_processor = true;
      }

      // Save new ProcessorKey
      if ($is_processor && $bulkSecret !== $processKey)
      {
        $cacheControl = new CacheController();
        $cachedObj = $cacheControl->getItem('bulk-secret');

        $cachedObj->setValue($bulkSecret);
        $cachedObj->setExpires(2 * MINUTE_IN_SECONDS);
        $cachedObj->save();
      }

      if (! $is_processor)
      {
        $json = new \stdClass;
        $json->message = __('Processor is active in another window', 'shortpixel-image-optimiser');
        $json->status = false;
        $json->error = self::PROCESSOR_ACTIVE; // processor active
        $this->send($json);
      }

    }


    /*
    OFF for now since Pkey doesn't need reloading every page refresh. It's meant so not all site users will be optimizing all the time overloading the server. It can be assigned to somebody for a bit. On bulk page, it should be released though */
    /*
    public function ajax_removeProcessorKey()
    {

      $this->checkNonce('exit_process');
      Log::addDebug('Process Exiting');

      $cacheControl = new CacheController();
      $cacheControl->deleteItem('bulk-secret');

      $json = new \stdClass;
      $json->status = 0;
      $this->send($json);

    } */

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
             if ($type == 'custom')
             {
                ob_start();
                   $control = new OtherMediaViewController();
                   $item = \wpSPIO()->filesystem()->getImage($id, 'custom');
                   $control->printItemActions($item);
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
        $this->checkProcessorKey();

        // Notice that POST variables are always string, so 'true', not true.
        $isBulk = (isset($_POST['isBulk']) && $_POST['isBulk'] === 'true') ? true : false;
        $queue = (isset($_POST['queues'])) ? sanitize_text_field($_POST['queues']) : 'media,custom';
        $queues = array_filter(explode(',', $queue), 'trim');

        $control = new OptimizeController($queues);
        $control->setBulk($isBulk);
        $result = $control->processQueue($queues);

        $this->send($result);
    }



    public function ajaxRequest()
    {
        $this->checkNonce('ajax_request');

        $action = isset($_POST['screen_action']) ? sanitize_text_field($_POST['screen_action']) : false;
        $typeArray = isset($_POST['type'])  ? array(sanitize_text_field($_POST['type'])) : array('media', 'custom');
        $id = isset($_POST['id']) ? intval($_POST['id']) : false;

        $json = new \stdClass;
        foreach($typeArray as $type)
        {
          $json->$type = new \stdClass;
          $json->$type->id = $id;
          $json->$type->results = null;
          $json->$type->is_error = false;
          $json->status = false;
        }

        $data = array('id' => $id, 'typeArray' => $typeArray, 'action' => $action);

        if (count($typeArray) == 1) // Actions which need specific type like optimize / restore.
        {
          $data['type'] = $typeArray[0];
          unset($data['typeArray']);
        }

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
           case 'applyBulkSelection':
             $json = $this->applyBulkSelection($json, $data);
           break;
           case 'startBulk':
             $json = $this->startBulk($json, $data);
           break;
           case 'finishBulk':
             $json = $this->finishBulk($json, $data);
           break;
           default:
              $json->$type->message = __('Ajaxrequest - no action found', 'shorpixel-image-optimiser');
              $json->error = self::NO_ACTION;
           break;

        }

        $this->send($json);

    }

    public function getMediaItem($id, $type)
    {
      $fs = \wpSPIO()->filesystem();

      return $fs->getImage($id, $type);

    }

    /** Adds  a single Items to the Single queue */
    public function optimizeItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

          $mediaItem = $this->getMediaItem($id, $type);

          $control = new OptimizeController();
          $json = new \stdClass;
          $json->$type = new \stdClass;

          $json->$type = $control->addItemToQueue($mediaItem);


          return $json;
        //  $this->send($json);
    }

    protected function restoreItem($json, $data)
    {
      $id = $data['id'];
      $type =$data['type'];

      $mediaItem = $this->getMediaItem($id, $type);
      $control = new OptimizeController();

      // @todo Turn back on, when ok.
      $json->$type = $control->restoreItem($mediaItem);

      return $json;
    }

    protected function reOptimizeItem($json, $data)
    {
       $id = $data['id'];
       $type = $data['type'];
       $compressionType = isset($_POST['compressionType']) ? intval($_POST['compressionType']) : 0;
       $mediaItem = $this->getMediaItem($id, $type);

       $control = new OptimizeController();

       $json->$type = $control->reOptimizeItem($mediaItem, $compressionType);
       return $json;
    }

    protected function finishBulk($json, $data)
    {
       $bulkControl = BulkController::getInstance();

       $bulkControl->finishBulk('media');
       $bulkControl->finishBulk('custom');

       $json->status = 1;

       return $json;
    }


    protected function createBulk($json, $data)
    {
        $bulkControl = BulkController::getInstance();
        $stats = $bulkControl->createNewBulk('media');

        $json->media->stats = $stats;

        $stats = $bulkControl->createNewBulk('custom');

        $json->custom->stats = $stats;

        return $json;

    }

    protected function applyBulkSelection($json, $data)
    {
        // These values should always be given!
        $doMedia = filter_var(sanitize_text_field($_POST['mediaActive']), FILTER_VALIDATE_BOOLEAN);
        $doCustom = filter_var(sanitize_text_field($_POST['customActive']), FILTER_VALIDATE_BOOLEAN);
        $doWebp = filter_var(sanitize_text_field($_POST['webpActive']), FILTER_VALIDATE_BOOLEAN);

        \wpSPIO()->settings()->createWebp = $doWebp;

        $bulkControl = BulkController::getInstance();

        if (! $doMedia)
          $bulkControl->finishBulk('media');
        if (! $doCustom)
          $bulkControl->finishBulk('custom');

        $optimizeController = new OptimizeController();
        $optimizeController->setBulk(true);

        $data = $optimizeController->getStartupData();

        $json->media->stats = $data->media->stats;
        $json->custom->stats = $data->custom->stats;
        $json->total = $data->total;

        $json->status = true;

        return $json;


    }

    protected function startBulk($json, $data)
    {
        $bulkControl = BulkController::getInstance();

        // @todo This needs to check type which Q was choosen in UI.
        $result = $bulkControl->startBulk('media');
        $json->media = $result;

        $result = $bulkControl->startBulk('custom');
        $json->custom = $result;

        $this->send($result);
    }

    /** Data for the compare function */
    public function ajax_getComparerData() {

        $this->checkNonce('ajax_request');

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
        $id = isset($_POST['id']) ? intval($_POST['id']) : false;

        if ( $id === false || !current_user_can( 'upload_files' ) && !current_user_can( 'edit_posts' ) )  {

            $json->status = false;
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
          $ret['width'] = $imageObj->getMeta('originalWidth'); // $meta->getActualWidth();
          $ret['height'] = $imageObj->getMeta('originalHeight');

          if (is_null($ret['width']) || $ret['width'] == false)
          {

              $ret['width'] = $imageObj->get('width'); // $imageSizes[0];
              $ret['height']= $imageObj->get('height'); //imageSizes[1];

          }

        $this->send( (object) $ret);
    }

    protected function checkNonce($action)
    {
      if (! wp_verify_nonce($_POST['nonce'], $action))
      {
        $json = new \stdClass;
        $json->message = __('Nonce is missing or wrong', 'shortpixel-image-optimiser');
        $json->status = false;
        $json->error = self::NONCE_FAILED;
        $this->send($json);
      }

    }


    protected function send($json)
    {
        $json->responses = ResponseController::getAll();

        $callback = isset($_POST['callback']) ? sanitize_text_field($_POST['callback']) : false;
        if ($callback)
          $json->callback = $callback; // which type of request we just fullfilled ( response processing )

        $pKey = $this->getProcessorKey();
        if ($pKey !== false)
          $json->processorKey = $pKey;

        wp_send_json($json);
        exit();
    }




}
