<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;

use ShortPixel\Controller\QuotaController as QuotaController;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;

class OptimizeController
{
    protected static $instance;

    protected static $results;

    public function __construct()
    {

    }

    public static function getInstance()
    {
       if ( is_null(self::$instance))
          self::$instance = new OptimizeController();

      return self::$instance;
    }

    // Queuing Part

    /* Add Item to Queue should be used for starting manual Optimization
    * Enqueue a single item, put it to front, remove duplicates.
    @return int Number of Items added
    */
    public function addItemToQueue($id, $type = 'media')
    {
        $fs = \wpSPIO()->filesystem();

        if ($type == 'media')
        {
          $queue = MediaLibraryQueue::getInstance();
          $mediaItem = $fs->getMediaImage($id);

        }
        elseif($type == 'custom')
        {
          $queue = CustomQueue::getInstance();
          $mediaItem = $fs->getCustomImage($id);
        }

        $json = $this->getJsonResponse();
        $json->status = 0;

        if (! $mediaItem->isProcessable())
        {
          $json->message = $mediaItem->getProcessableReason();
          $json->has_error = true;
        }
        else
        {
          $numitems = $queue->addSingleItem($mediaItem); // 1 if ok, 0 if not found, false is not processable
          if ($numitems > 0)
          {
            $json->message = sprintf(__('Item %d added to Queue. %d items in Queue', 'shortpixel-image-optimiser'), $id, $numitems);
            $json->status = 1;
          }
          else
          {
            $json->message = __('No items added to queue', 'shortpixel-image-optimiser');
            $json->status = 0;
          }
        }

        return $json;
    }

    public function ajaxAddItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

          $json = $this->addItemToQueue($id, $type);

          $this->jsonResponse($json);
    }

    public function ajaxRestoreItem()
    {
      $id = intval($_POST['id']);
      $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

      $json = $this->restoreItem($id, $type);

      $this->jsonResponse($json);
    }

    public function restoreItem($id, $type = 'media')
    {
        $fs = \wpSPIO()->filesystem();

        if ($type == 'media')
        {
          $mediaItem = $fs->getMediaImage($id);
        }
        elseif($type == 'custom')
        {
          $mediaItem = $fs->getCustomImage($id);
        }

        $json = $this->getJsonResponse();
        $json->status = 0;

        $result = $mediaItem->restore();

        if ($result)
        {
           $json->status = 1;
           $json->message = __('Item restored', 'shortpixel-image-optimiser');
        }
        else
        {
           $json->message = __('Item not restorable', 'shortpixel-image-optimiser');
        }
        return $json;
    }


    public function createBulk()
    {
       //$mediaQ = MediaLibraryQueue::getInstance();
       //$mediaQ->createNewBulk();
    }

    public function ajaxCreateBulk()
    {

    }


    // Processing Part

    // next tick of items to do.
    // @todo Implement a switch to toggle all processing off.
    /* Processes one tick of the queue
    *
    * @return Object JSON object detailing results of run
    */
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
        $result = $mediaQ->run();
        $results = array();
        echo "RESULT ---> "; var_dump($result);

        $items = (isset($result->items) && is_array($result->items)) ? $result->items : array();
      //  $json = $this->queueToJson($result);


        foreach($items as $index => $item)
        {
            $urls = $item->urls;
            $item = $this->sendToProcessing($item);
            $item = $this->handleAPIResult($item, $mediaQ);
            $result->items[$index] = $item; // replace processed item, should have result now.


          //  $result = $api->doRequests($urls, $blocking);
        }
        $json = $this->queueToJson($result);
        $results['media'] = $json;


        $customQ = CustomQueue::getInstance();
        $results['custom'] = array(); // @otodo Implement

        return $results;
    }

    /** Checks and sends the item to processing
    * @param Object $item Item is a stdClass object from Queue. This is not a model, nor a ShortQ Item.
    * @todo Check if PNG2JPG Processing is needed.
    */
    public function sendToProcessing(Object $item)
    {

      $api = $this->getAPI();
      $item = $api->processMediaItem($item);

      return $item;
    }

    // This is everything sub-efficient.
    protected function handleAPIResult($item, $q)
    {
      $fs = \wpSPIO()->filesystem();
      $responseControl = new ResponseController();
echo "OPTIMIZECONTROL RESULT"; var_dump($item);
      $result = $item->result;
      if ($result->is_error)
      {
          if (! property_exists($item, 'errors'))
            $item->errors = array();

          $item->errors[] = $result;

          if ($result->is_done || count($item->errors) >= SHORTPIXEL_MAX_FAIL_RETRIES )
          {
             $q->itemFailed($item, true);
             $responseController->withMessage($result->message)->asError();
          }
          else
          {
              $q->itemFailed($item, false);
          }
      }
      elseif ($result->is_done)
      {
         if ($result->status == ApiController::STATUS_SUCCESS )
         {
           $queue_name = $q->getQueueName();
           if ($queue_name == 'Media')
           {
              $imageItem = $fs->getMediaImage($item->item_id);
           }
           elseif ($queue_name == 'Custom')
           {
             $imageItem = $fs->getCustomImage($item->item_id);
           }

           $tempFiles = array();

           // Set the metadata decided on APItime.
           if (isset($item->compressionType))
             $imageItem->setMeta('compressionType', $item->compressionType);

           /*foreach($result->files as $index => $fileResult)
           {
              $tempFiles[$index] = $fileResult->file;
           } */
           Log::addTemp('Going to Handle Optimize --> ', array_keys($result->files));
           if (count($result->files) > 0 )
           {
              $optimizeResult = $imageItem->handleOptimized($result->files);
              $item->result->improvements = $imageItem->getImprovements();

              if ($optimizeResult)
              {
                 $item->result->status = ApiController::STATUS_SUCCESS;
                 $item->result->message = sprintf(__('Image %s optimized', 'shortpixel-image-optimiser'), $item->item_id);
               }
               else
              {
                 $item->result->status = ApiController::STATUS_ERROR;
                 $item->result->message = sprintf(__('Image %s optimized with errors', 'shortpixel-image-optimiser'), $item->item_id);
              }
              //$item->results =// $imageItem->getImprovements();
           }
           else
           {
              Log::addWarn('Api returns Success, but result has no files', $result);
              $item->result->status = ApiController::STATUS_FAIL;

              return false;
           }

         }
         $q->itemDone($item);
      //   return $result;
      }

      return $item;

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
       return ApiController::getInstance();
    }

    /** Convert a result Queue Stdclass to a JSON send Object */
    protected function queueToJson($result, $json = false)
    {
        if (! $json)
          $json = $this->getJsonResponse();

        switch($result->qstatus)
        {
          case Queue::RESULT_PREPARING:
            $json->message = sprintf(__('Prepared %s items', 'shortpixel-image-optimiser'), count($result->items) );
          break;
          case Queue::RESULT_EMPTY:
              $json->message  = __('Empty Queue', 'shortpixel-image-optimiser');
          break;
          case Queue::RESULT_ITEMS:
            $json->message = sprintf(__("Fetched %d items",  'shortpixel-image-optimiser'), count($result->items));
            $json->results = $result->items;
          break;
          default:
             $json->message = __('Unknown Status', 'shortpixel-image-optimiser');
          break;
        }
        $json->status = $result->qstatus;

        return $json;
    }

    // Communication Part
    protected function getJsonResponse()
    {
      $json = new \stdClass;
      $json->status = null;
      $json->result = null;
      $json->results = null;
      $json->actions = null;
      $json->has_error = false;
      $json->message = null;

      return $json;
    }

    protected function jsonResponse($json)
    {
        wp_send_json($json);
        exit();
    }




}
