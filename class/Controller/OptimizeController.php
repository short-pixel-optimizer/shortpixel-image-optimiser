<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\QuotaController as QuotaController;


class OptimizeController
{
    protected static $instance;



    public function __construct()
    {

    }

    public function getInstance()
    {
       if ( is_null(self::$instance))
          self::$instance = new OptimizeController();

      return self::$instance;
    }


    // Queuing Part

    /* Add Item to Queue should be used for starting manual Optimization
    * Enqueue a single item, put it to front, remove duplicates.
    */
    public function addItemToQueue($id, $type = 'media')
    {
        if ($type == 'media')
        {
          $queue = MediaLibraryQueue::getInstance();
        }
        elseif($type == 'custom')
        {
          $queue = CustomQueue::getInstance();
        }

        $result = $queue->addSingleItem($id);

        return $result;
    }

    public function ajaxAddItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

          $result = $this->addItemToQueue($id, $type);
          $json->status = $result;
          $json->message = __('Item added to Queue', 'shortpixel-image-optimiser');

          $this->jsonResponse($json);
    }


    public function createBulk()
    {
       $mediaQ = MediaLibraryQueue::getInstance();
       $mediaQ->createNewBulk();
    }

    public function ajaxCreateBulk()
    {

    }


    // Processing Part

    // next tick of items to do.
    // @todo Implement a switch to toggle all processing off.
    public function processQueue()
    {

        $keyControl = ApiKeyController::getInstance();
        if (! $keyControl->keyIsVerified())
        {
           $json = $this->getJsonResponse();
           $json->error = true;
           $json->message =  __('Invalid API Key', 'shortpixel-image-optimiser');
           return $json;
        }

        $quotaControl = QuotaController::getInstance();
        if ( ! $quotaControl->hasQuota())
        {
          $json = $this->getJsonResponse();
          $json->error = true;
          $json->message =   __('Quota Exceeded','shortpixel-image-optimiser');
          return $json;
        }


        $mediaQ = MediaLibraryQueue::getInstance();
        $items = $mediaQ->deQueue();
        $api = $this->getAPI();

        foreach($items as $item)
        {
            $urls = $item->urls;
            if ($item->tries == 0)
            {
                $blocking = false; // first time, don't block.
            }

            $api->doRequests($urls, $blocking);
        }

        $customQ = CustomQueue::getInstance();

    }

    public function ajaxProcessQueue()
    {
        if (isset($_POST['bulk-secret']))
        {
          $secret = sanitize_text_field($_POST['bulk-secret']);
          $cacheControl = new \ShortPixel\Controller\CacheController();
          $cachedObj = $cacheControl->getItem('bulk-secret');

          if (! $cachedObj->exists())
          {
             $cachedObj->setValue($secret);
             $cachedObj->setExpires(3 * MINUTE_IN_SECONDS);
             $cacheControl->storeItemObject($cachedObj);
          }
        }

        $result = $this->processQueue();
        $this->jsonResponse($result);
    }

    protected function getAPI()
    {
       return \ShortPixelAPI::getInstance();
    }

    // Communication Part
    protected function getJsonResponse()
    {
      $json = new \stcdClass;
      $json->status = null;
      $json->result = null;
      $json->actions = null;
      $json->error = false;
      $json->message = null;

      return $json;
    }

    protected function jsonResponse($json)
    {
        wp_send_json($json);
        exit();
    }




}
