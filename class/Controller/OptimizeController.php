<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;

use ShortPixel\Controller\QuotaController as QuotaController;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\Model\Image\ImageModel as ImageModel;

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

    protected function getQueue(Object $mediaItem)
    {
        $queue = null;

        if ($mediaItem->get('type') == 'media')
          $queue = MediaLibraryQueue::getInstance();

        if ($mediaItem->get('type') == 'custom')
          $queue = CustomQueue::getInstance();

        return $queue;
    }

    // Queuing Part
    /* Add Item to Queue should be used for starting manual Optimization
    * Enqueue a single item, put it to front, remove duplicates.
    @return int Number of Items added
    */
    public function addItemToQueue(Object $mediaItem)
    {
        $fs = \wpSPIO()->filesystem();

        $json = $this->getJsonResponse();
        $json->status = 0;
        $json->id = $id;
        $json->type = $type;

        if ($mediaItem === false)
        {
          $json->is_error = true;
          $json->message = __('Error - item could not be found', 'shortpixel-image-optimiser');
          ResponseController::add()->withMessage($json->message)->asError();
          return $json;
        }

        if (! $mediaItem->isProcessable())
        {
          $json->message = $mediaItem->getProcessableReason();
          $json->has_error = true;
          ResponseController::add()->withMessage($json->message)->asError();
        }
        else
        {
          $numitems = $queue->addSingleItem($mediaItem); // 1 if ok, 0 if not found, false is not processable
          if ($numitems > 0)
          {
            $json->message = sprintf(__('Item %d added to Queue. %d items in Queue', 'shortpixel-image-optimiser'), $id, $numitems);
            $json->status = 1;
            ResponseController::add()->withMessage($json->message);
          }
          else
          {
            $json->message = __('No items added to queue', 'shortpixel-image-optimiser');
            $json->status = 0;
            ResponseController::add()->withMessage($json->message);
          }
        }

        return $json;
    }

    public function restoreItem(Object $mediaItem)
    {
        $fs = \wpSPIO()->filesystem();

        $json = $this->getJsonResponse();
        $json->status = 0;
        $json->result->item_id = $id;

        $result = $mediaItem->restore();

        if ($result)
        {
           $json->status = 1;
           $json->result->message = __('Item restored', 'shortpixel-image-optimiser');
           $json->result->is_done = true;
        }
        else
        {
           $json->result->message = __('Item not restorable', 'shortpixel-image-optimiser');
           $json->result->is_done = false;
           $json->result->is_error = true;

        }
        return $json;
    }

    public function reOptimizeItem(Object $mediaItem, $compressionType)
    {
      $json = $this->restoreItem($mediaItem);

      if ($json->status == 1) // successfull restore.
      {
          $mediaItem->setMeta('compressionType', $compressionType);
          $json = $this->addItemToQueue($mediaItem);
          $this->send($json);
      }

      $this->send($json);


    }
    /** Create a new bulk, enqueue items for bulking */
    public function createBulk()
    {
    //  $this->q->createNewBulk();
       $mediaQ = MediaLibraryQueue::getInstance();
       $mediaQ->createNewBulk(array());
    }

    /*** Start the bulk run */
    public function startBulk()
    {
        $mediaQ = MediaLibraryQueue::getInstance();
        $mediaQ->startBulk();
        $this->processQueue();
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
//        echo "RESULT ---> "; var_dump($result);

        $items = (isset($result->items) && is_array($result->items)) ? $result->items : array();

        // Only runs if result is array, dequeued items.
        foreach($items as $index => $item)
        {
            $urls = $item->urls;
            if (property_exists($item, 'png2jpg'))
              $item = $this->convertPNG($item, $mediaQ);

            $item = $this->sendToProcessing($item);

            $item = $this->handleAPIResult($item, $mediaQ);
            $result->items[$index] = $item; // replace processed item, should have result now.

            Log::addTemp('ProcessQueue Item' . $index,  $item);

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

    protected function convertPNG(Object $item, $mediaQ)
    {
      $settings = \wpSPIO()->settings();
      $fs = \wpSPIO()->filesystem();

      $imageObj = $fs->getMediaImage($item->item_id);

      $result = $imageObj->convertPNG();
      if ($result !== false)
        $imageObj = $result; // returns ImageObj.

      $item->urls = $imageObj->getOptimizeURLS();

      return $item;
    }

    // This is everything sub-efficient.
    protected function handleAPIResult(Object $item, $q)
    {
      $fs = \wpSPIO()->filesystem();

      $result = $item->result;
      if ($result->is_error)
      {
          if (! property_exists($item, 'errors'))
            $item->errors = array();

          $item->errors[] = $result;

          if ($result->is_done || count($item->errors) >= SHORTPIXEL_MAX_FAIL_RETRIES )
          {
             $q->itemFailed($item, true);
             ResponseController::add()->withMessage($result->message)->asError();
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
           {
             $imageItem->setMeta('compressionType', $item->compressionType);

           }
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

    /** Called via Hook when plugins like RegenerateThumbnailsAdvanced Update an thumbnail */
    public function thumbnailsChangedHook($postId, $originalMeta, $regeneratedSizes = array(), $bulk = false)
    {
       $fs = \wpSPIO()->filesystem();
       $settings = \wpSPIO()->settings();
       $imageObj = $fs->getMediaImage($postId);

       if (count($regeneratedSizes) == 0)
        return;

        $metaUpdated = false;
        foreach($regeneratedSizes as $sizeName => $size) {
            if(isset($size['file']))
            {

                //$fileObj = $fs->getFile( (string) $mainFile->getFileDir() . $size['file']);
                $thumb = $imageObj->getThumbnail($sizeName);
                if ($thumb !== false)
                {
                   if ($settings->autoMediaLibrary)
                      $thumb->setMeta('status', ImageModel::FILE_STATUS_PENDING);
                   else
                      $thumb->setMeta('status', ImageModel::FILE_STATUS_UNPROCESSED);

                   $webp = $thumb->getWebp();
                   if ($webp !== false)
                     $webp->delete();

                    $metaUpdated = true;
                }
            }
        }
        if ($metaUpdated)
           $imageObj->saveMeta();
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
            $json->message = sprintf(__('Prepared %s items', 'shortpixel-image-optimiser'), $result->items );
          break;
          case Queue::RESULT_PREPARING_DONE:
            $json->message = sprintf(__('Preparing is done, queue has  %s items ', 'shortpixel-image-optimiser'), $result->items );
          break;
          case Queue::RESULT_EMPTY:
              $json->message  = __('Queue returned no active items', 'shortpixel-image-optimiser');
          break;
          case Queue::RESULT_QUEUE_EMPTY:
              $json->message = __('Queue empty and done', 'shortpixel-image-optimiser');
          break;
          case Queue::RESULT_ITEMS:
            $json->message = sprintf(__("Fetched %d items",  'shortpixel-image-optimiser'), count($result->items));
            $json->results = $result->items;
          break;
          default:
             $json->message = sprintf(__('Unknown Status %s ', 'shortpixel-image-optimiser'), $result->qstatus);
          break;
        }
        $json->status = $result->qstatus;

        if (property_exists($result, 'stats'))
          $json->stats = $result->stats;


        return $json;
    }

    // Communication Part
    protected function getJsonResponse()
    {
      $json = new \stdClass;
      $json->status = null;
      $json->result = null;
      $json->results = null;
//      $json->actions = null;
      $json->has_error = false;
      $json->message = null;

      return $json;
    }

}
