<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;

use ShortPixel\Controller\AjaxController as AjaxController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\StatsController as StatsController;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Helper\UiHelper as UiHelper;


class OptimizeController
{
    //protected static $instance;
    protected static $results;

    protected $isBulk = false; // if queueSystem should run on BulkQueues;

    public function __construct()
    {

    }

    // If OptimizeController should use the bulkQueues.
    public function setBulk(bool $bool)
    {
       $this->isBulk = $bool;
    }

    public function getQueue($type)
    {
        $queue = null;

        if ($type == 'media')
        {
            $queueName = ($this->isBulk == true) ? 'media' : 'mediaSingle';
            $queue = new MediaLibraryQueue($queueName);
        }
        if ($type == 'custom')
        {
          $queueName = ($this->isBulk == true) ? 'custom' : 'customSingle';
          $queue = new CustomQueue($queueName);
        }

        $options = $queue->getCustomDataItem('queueOptions');
        if ($options !== false)
        {
            $queue->setOptions($options);
        }
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

        $id = $mediaItem->get('id');
        $type = $mediaItem->get('type');

        $json = $this->getJsonResponse();
        $json->status = 0;
        $json->result = new \stdClass;
        $json->result->item_id = $id;

        // Manual Optimization order should always go trough
        if ($mediaItem->isOptimizePrevented() !== false)
            $mediaItem->resetPrevent();

        $queue = $this->getQueue($mediaItem->get('type'));

        if ($mediaItem === false)
        {
          $json->is_error = true;
          $json->result->is_error = true;
          $json->result->is_done = true;
          $json->result->message = __('Error - item could not be found', 'shortpixel-image-optimiser');
          $json->result->fileStatus = ImageModel::FILE_STATUS_ERROR;
          ResponseController::add()->withMessage($json->message)->asError();
          //return $json;
        }

        if (! $mediaItem->isProcessable())
        {
          $json->result->message = $mediaItem->getProcessableReason();
          $json->result->is_error = true;
          $json->result->is_done = true;
          $json->result->fileStatus = ImageModel::FILE_STATUS_ERROR;
          ResponseController::add()->withMessage($json->result->message)->asError();
        }
        else
        {
          $result = $queue->addSingleItem($mediaItem); // 1 if ok, 0 if not found, false is not processable
          if ($result->numitems > 0)
          {
            $json->result->message = sprintf(__('Item %s added to Queue. %d items in Queue', 'shortpixel-image-optimiser'), $mediaItem->getFileName(), $result->numitems);
            $json->status = 1;
            ResponseController::add()->withMessage($json->result->message);

          }
          else
          {
            $json->message = __('No items added to queue', 'shortpixel-image-optimiser');
            $json->status = 0;
            ResponseController::add()->withMessage($json->message);
          }

            $json->qstatus = $result->qstatus;
            $json->result->fileStatus = ImageModel::FILE_STATUS_PENDING;
            $json->result->is_error = false;
            $json->result->message = __('Optimizing, please wait', 'shortpixel-image-optimiser');
        }

        return $json;
    }

    public function restoreItem(Object $mediaItem)
    {
        $fs = \wpSPIO()->filesystem();

        $json = $this->getJsonResponse();
        $json->status = 0;
        $json->result = new \stdClass;
        $json->result->item_id = $mediaItem->get('id');

        $result = $mediaItem->restore();

        if ($result)
        {
           $json->status = 1;
           $json->result->message = __('Item restored', 'shortpixel-image-optimiser');
           $json->fileStatus = ImageModel::FILE_STATUS_RESTORED;
           $json->result->is_done = true;
        }
        else
        {
           $json->result->message = __('Item not restorable', 'shortpixel-image-optimiser');
           $json->result->is_done = true;
           $json->fileStatus = ImageModel::FILE_STATUS_ERROR;
           $json->result->is_error = true;

        }
        return $json;
    }

    public function reOptimizeItem(Object $mediaItem, $compressionType)
    {
      $json = $this->restoreItem($mediaItem);

      if ($json->status == 1) // successfull restore.
      {
          // Hard reload since metadata probably removed / changed but still loaded, which might enqueue wrong files.
            $mediaItem = \wpSPIO()->filesystem()->getImage($mediaItem->get('id'), $mediaItem->get('type'));

            $mediaItem->setMeta('compressionType', $compressionType);
            $json = $this->addItemToQueue($mediaItem);
            return $json;
      }

     return $json;

    }

    /** Returns the state of the queue so the startup JS can decide if something is going on and what.  **/
    public function getStartupData()
    {

        $mediaQ = $this->getQueue('media');
        $customQ = $this->getQueue('custom');

        $data = new \stdClass;
        $data->media = new \stdClass;
        $data->custom = new \stdClass;
        $data->total = new \stdClass;

        $data->media->stats = $mediaQ->getStats();
        $data->custom->stats = $customQ->getStats();

        $data->total = $this->calculateStatsTotals($data);

        return $data;

    }


    // Processing Part

    // next tick of items to do.
    // @todo Implement a switch to toggle all processing off.
    /* Processes one tick of the queue
    *
    * @return Object JSON object detailing results of run
    */
    public function processQueue($queueTypes = array())
    {

        $keyControl = ApiKeyController::getInstance();
        if ($keyControl->keyIsVerified() === false)
        {
           $json = $this->getJsonResponse();
           $json->status = false;
           $json->error = AjaxController::APIKEY_FAILED;
           $json->message =  __('Invalid API Key', 'shortpixel-image-optimiser');
           $json->status = false;
           return $json;
        }

        $quotaControl = QuotaController::getInstance();
        if ($quotaControl->hasQuota() === false)
        {
          $json = $this->getJsonResponse();
          $json->error = AjaxController::NOQUOTA;
          $json->status = false;
          $json->message =   __('Quota Exceeded','shortpixel-image-optimiser');
          return $json;
        }

        // @todo Here prevent bulk from running when running flag is off

        // @todo Here prevent a runTick is the queue is empty and done already ( reliably )

        $results = new \stdClass;
        if ( in_array('media', $queueTypes))
        {
          $mediaQ = $this->getQueue('media');
          $results->media = $this->runTick($mediaQ); // run once on mediaQ
        }
        if ( in_array('custom', $queueTypes))
        {
          $customQ = $this->getQueue('custom');
          $results->custom = $this->runTick($customQ);
        }

        $results->total = $this->calculateStatsTotals($results);
    //    $this->checkCleanQueue($results);

        return $results;
    }

    private function runTick($Q)
    {
      $result = $Q->run();
      $results = array();

      // Items is array in case of a dequeue items.
      $items = (isset($result->items) && is_array($result->items)) ? $result->items : array();

      // Only runs if result is array, dequeued items.
      foreach($items as $mainIndex => $item)
      {
            $urls = $item->urls;
            if (property_exists($item, 'png2jpg'))
            {
              $bool = $this->convertPNG($item, $Q);
              //if ($bool == true)
              //  continue; // conversion done, item will be requeued with new urls.
            }

            $item = $this->sendToProcessing($item, $Q);

            $item = $this->handleAPIResult($item, $Q);
            $result->items[$mainIndex] = $item; // replace processed item, should have result now.
      }

      $result->stats = $Q->getStats();
      $json = $this->queueToJson($result);
      $this->checkQueueClean($result, $Q);

      return $json;
    }


    /** Checks and sends the item to processing
    * @param Object $item Item is a stdClass object from Queue. This is not a model, nor a ShortQ Item.
    *
		*/
    public function sendToProcessing(Object $item, $q)
    {

      $api = $this->getAPI();
      if (property_exists($item, 'action'))
      {
           $fs = \wpSPIO()->filesystem();
           $qtype = $q->getType();
           $qtype = strtolower($qtype);

           $imageObj = $fs->getImage($item->item_id, $qtype);
           switch($item->action)
           {
              case 'restore';
                 $imageObj->restore();
              break;
              case 'migrate':
                // Loading the item should already be enough to trigger.
              break;
           }

           $item->result = new \stdClass;
           $item->result->is_done = true; // always done
           $item->result->is_error = false; // for now
           $item->result->apiStatus = ApiController::STATUS_NOT_API;
      }
      else // as normal
      {
        $item = $api->processMediaItem($item);
      }
      return $item;
    }

    protected function convertPNG(Object $item, $mediaQ)
    {
      $settings = \wpSPIO()->settings();
      $fs = \wpSPIO()->filesystem();

      $imageObj = $fs->getMediaImage($item->item_id);

      $bool = $imageObj->convertPNG();
      if ($bool !== false) // It worked.
      {
        $imageObj = $fs->getMediaImage($item->item_id);
        $this->addItemToQueue($imageObj);

      }
        //$imageObj = $result; // returns ImageObj.

    //  $item->urls = $imageObj->convertergetOptimizeURLS();

      return $bool;
    }

    // This is everything sub-efficient.
    /* Handles the Queue Item API result .
    */
    protected function handleAPIResult(Object $item, $q)
    {
      $fs = \wpSPIO()->filesystem();

      $qtype = $q->getType();
      $qtype = strtolower($qtype);

      $imageItem = $fs->getImage($item->item_id, $qtype);
      // If something is in the queue for long, but somebody decides to trash the file in the meanwhile.
      if ($imageItem === false)
      {
        $item->result->message = __("File Error. File could not be loaded with this ID ", 'shortpixel-image-optimiser');
        $item->result->apiStatus = ApiController::STATUS_NOT_API;
        $item->fileStatus = ImageModel::FILE_STATUS_ERROR;
        $item->result->is_done = true;
        $item->result->is_error = true;
      }
      else
        $item->result->filename = $imageItem->getFileName();

      $result = $item->result;

			$quotaController = QuotaController::getInstance();
			$statsController = StatsController::getInstance();

      if ($result->is_error)
      {
          // Check ApiStatus, and see what is what for error
          // https://shortpixel.com/api-docs
          $apistatus = $result->apiStatus;

          if ($apistatus == ApiController::STATUS_ERROR ) // File Error - between -100 and -300
          {
              $item->fileStatus = ImageModel::FILE_STATUS_ERROR;
          }
          // Out of Quota (partial / full)
          elseif ($apistatus == ApiController::STATUS_QUOTA_EXCEEDED)
          {
              $item->result->error = AjaxController::NOQUOTA;
							$quotaController->setQuotaExceeded();
          }
          elseif ($apistatus == ApiController::STATUS_NO_KEY)
          {
              $item->result->error = AjaxController::APIKEY_FAILED;
          }
          elseif($apistatus == ApiController::STATUS_QUEUE_FULL || $apistatus == ApiController::STATUS_MAINTENANCE ) // Full Queue / Maintenance mode
          {
              $item->result->error = AjaxController::SERVER_ERROR;
          }

          if ($result->is_done || $item->tries >= SHORTPIXEL_MAX_FAIL_RETRIES )
          {
             // These are cloned, because queue changes object's properties
             Log::addDebug('HandleApiResult - Item failed ' . $item->item_id);
             $q->itemFailed($item, true);
             $this->HandleItemError($item);
             ResponseController::add()->withMessage($result->message)->asError();
          }
          else
          {
            // These are cloned, because queue changes object's properties
            // No specific error, try again.
              $q->itemFailed($item, false);
          }
      }
      elseif ($result->is_done)
      {
         if ($result->apiStatus == ApiController::STATUS_SUCCESS )
         {
           $tempFiles = array();

           // Set the metadata decided on APItime.
           if (isset($item->compressionType))
           {
             $imageItem->setMeta('compressionType', $item->compressionType);
           }

           Log::addDebug('Going to Handle Optimize --> ', array_keys($result->files) );
           if (count($result->files) > 0 )
           {
						 	// Dump Stats, Dump Quota. Refresh
							$quotaController->forceCheckRemoteQuota();
							$statsController->reset();

              $optimizeResult = $imageItem->handleOptimized($result->files); // returns boolean or null
              $item->result->improvements = $imageItem->getImprovements();

              if ($optimizeResult)
              {
                 $item->result->apiStatus = ApiController::STATUS_SUCCESS;
                 $item->fileStatus = ImageModel::FILE_STATUS_SUCCESS;
                 $item->result->message = sprintf(__('Image %s optimized', 'shortpixel-image-optimiser'), $imageItem->getFileName());
                 do_action( 'shortpixel_image_optimised', $imageItem->get('id'), $imageItem, $item );

               }
               else
              {
                 $item->result->apiStatus = ApiController::STATUS_ERROR;
                 $item->fileStatus = ImageModel::FILE_STATUS_ERROR;
              //   $item->result->message = sprintf(__('Image not optimized with errors', 'shortpixel-image-optimiser'), $item->item_id);
                 $item->result->message = ' ' .  $imageItem->getLastErrorMessage();
                 $item->result->is_error = true;

              }

              unset($item->result->files);

              $item->result->queuetype = $qtype;
              if ($imageItem->hasBackup())
              {
                $backupFile = $imageItem->getBackupFile(); // attach backup for compare in bulk
                $backup_url = $fs->pathToUrl($backupFile);
                 $item->result->original = $backup_url;
              }
              else
                $item->result->original = false;

              $item->result->optimized = $fs->pathToUrl($imageItem);


           }
           // This was not a request process, just handle it and mark it as done.
           elseif ($result->apiStatus == ApiController::STATUS_NOT_API)
           {
              // Nothing here.
           }
           else
           {
              Log::addWarn('Api returns Success, but result has no files', $result);
              $item->result->is_error = true;
              $item->result->message += sprintf(__('Image %s API returned succes, but without images', 'shortpixel-image-optimiser'), $item->item_id);
              $item->result->apiStatus = ApiController::STATUS_FAIL;

           }

         }

         if ($item->result->is_error)
         {
          $q->itemFailed($item, true);
          $this->HandleItemError($item);
         }
         else
         {
           if ($imageItem->isProcessable() && $result->apiStatus !== ApiController::STATUS_NOT_API)
           {
              Log::addDebug('Item with ID' . $imageItem->item_id . ' still has processables');
                $this->addItemToQueue($imageItem); // requeue for further processing.
           }
           else
            $q->itemDone($item);
         }
      }
      else
      {
          if ($result->apiStatus == ApiController::STATUS_UNCHANGED)
          {
              $item->fileStatus = ImageModel::FILE_STATUS_PENDING;
              $item->result->message .= sprintf(__(' Pass %d', 'shortpixel-image-optimiser'), intval($item->tries) );
              $q->itemFailed($item, false); // register as failed, retry in x time, q checks timeouts
          }
      }

      Log::addDebug('Optimizecontrol - Item has a result ', $item);
      return $item;

    }

    protected function HandleItemError($item)
    {
        if ($this->isBulk)
        {
          $fs = \wpSPIO()->filesystem();
          $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
          $fileLog = $fs->getFile($backupDir->getPath() . 'current_bulk.log');

          $time = UiHelper::formatTs(time());
          $fileName = $item->result->filename;
          $message = $item->result->message; // getLastErrorMessage();
          $item_id = $item->item_id;

          $fileLog->append($time . '|' . $fileName . '| ' . $item_id . '|' . $message . ';' .PHP_EOL);
        }
    }

    protected function checkQueueClean($result, $q)
    {
        if ($result->qstatus == Queue::RESULT_QUEUE_EMPTY && ! $this->isBulk)
        {
            $stats = $q->getStats();

            if ($stats->done > 0 || $stats->fatal_errors > 0)
            {
               $q->cleanQueue(); // clean the queue
            }
        }
    }


    /**
		* @integration Regenerate Thumbnails Advanced
		* Called via Hook when plugins like RegenerateThumbnailsAdvanced Update an thumbnail
		*/
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
                  $thumb->setMeta('status', ImageModel::FILE_STATUS_UNPROCESSED);

                   $webp = $thumb->getWebp();
                   if ($webp !== false)
                     $webp->delete();

									 $avif = $thumb->getAvif();
									 if ($avif !== false)
									 	 $avif->delete();

                    $metaUpdated = true;
                }
            }
        }

        if ($metaUpdated)
           $imageObj->saveMeta();

				if (\wpSPIO()->env()->is_autoprocess)
				{
						$this->addItemToQueue($imageObj);
				}
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
          case Queue::RESULT_RECOUNT: // This one should probably not happen.
             $json->has_error = true;
             $json->message = sprintf(__('Bulk preparation seems to be interrupted. Restart the queue or continue without accurate count', 'shortpixel-image-optimiser'));
          break;
          default:
             $json->message = sprintf(__('Unknown Status %s ', 'shortpixel-image-optimiser'), $result->qstatus);
          break;
        }
        $json->qstatus = $result->qstatus;
        //$json->

        if (property_exists($result, 'stats'))
          $json->stats = $result->stats;

      /*  Log::addDebug('JSON RETURN', $json);
        if (property_exists($result,'items'))
          Log::addDebug('Result Items', $result->items); */


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
    //  $json->has_error = false;// probably unused
      $json->message = null;

      return $json;
    }

    /** Tries to calculate total stats of the process for bulk reporting
    *  Format of results is   results [media|custom](object) -> stats
    */
    private function calculateStatsTotals($results)
    {
        $has_media = $has_custom = false;

        if (property_exists($results, 'media') &&
            is_object($results->media) &&
            property_exists($results->media,'stats') && is_object($results->media->stats))
        {
          $has_media = true;
        }

        if (property_exists($results, 'custom') &&
            is_object($results->custom) &&
            property_exists($results->custom, 'stats') && is_object($results->custom->stats))
        {
          $has_custom = true;
        }

        $object = new \stdClass;  // total

        if ($has_media && ! $has_custom)
        {
           $object->stats = $results->media->stats;
           return $object;
        }
        elseif(! $has_media && $has_custom)
        {
           $object->stats = $results->custom->stats;
           return $object;
        }
        elseif (! $has_media && ! $has_custom)
        {
            return null;
        }

        // When both have stats. Custom becomes the main. Calculate media stats over it. Clone, important!
        $object->stats = clone $results->custom->stats;

        if (property_exists($object->stats, 'images'))
          $object->stats->images = clone $results->custom->stats->images;

        foreach ($results->media->stats as $key => $value)
        {
            if (property_exists($object->stats, $key))
            {
               if (is_numeric($object->stats->$key)) // add only if number.
               {
                $object->stats->$key += $value;
               }
               elseif(is_bool($object->stats->$key))
               {
                  // True > False in total since this status is true for one of the items.
                  if ($value === true && $object->stats->$key === false)
                     $object->stats->$key = true;
               }
               elseif (is_object($object->stats->$key)) // bulk object, only numbers.
               {
                  foreach($results->media->stats->$key as $bKey => $bValue)
                  {
                      $object->stats->$key->$bKey += $bValue;
                  }
               }
            }
        }


        return $object;
    }

    public static function activatePlugin()
    {
      $mediaQ = new MediaLibraryQueue();
      //$customQ = new CustomQueue();

      $mediaQ->activatePlugin();
    //  $customQ->activatePlugin();
    }

    public static function uninstallPlugin()
    {
      //$mediaQ = MediaLibraryQueue::getInstance();
      //$queue = new MediaLibraryQueue($queueName);
      $queues = array('media', 'mediaSingle', 'custom', 'customSingle');
      foreach($queues as $qName)
      {
          $q = new MediaLibraryQueue($qName);
          $q->uninstall();
      }

    }


} // class
