<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\Api\RequestManager;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\Api\ApiController as ApiController;

use ShortPixel\Helper\UiHelper as UiHelper;



// Controls,  the glue between the Queue and the Optimisers.
class QueueController
{

  const IN_QUEUE_ACTION_ADDED = 1; 
  const IN_QUEUE_SKIPPED = 2; 


  protected static $lastId; // Last item_id received / send. For catching errors.
  protected $lastQStatus; // last status for reporting purposes.

  //protected $optimiser;
  protected $args;

  public function __construct($args = [])
  {
     $defaults = [
       'is_bulk' => false,
     ];

     $this->args = wp_parse_args($args, $defaults);
  }

  /**
   * Add a single item to the queue
   *
   * @param ImageModel $imageModel 
   * @param array $args
   * @return Object Result object
   */
  public function addItemToQueue(ImageModel $imageModel, $args = [])
  {
      $defaults = array(
        'forceExclusion' => false,
        'action' => 'optimize', 
        'compressionType' => null, 
        'smartcrop' => null, 
        'next_actions' => [], 
        'returndatalist' => [], 
      );
      $args = wp_parse_args($args, $defaults);

      $qItem = QueueItems::getImageItem($imageModel);

      /* QueueItem is basically reset each action to prevent interference between tasks. next_actions should be kept persistent until all tasks done */
      if (count($args['next_actions']) > 0)
      {
         $qItem->data()->next_actions = $args['next_actions'];
      }

      if (is_object($args['returndatalist']))
      {
         $args['returndatalist'] = (array) $args['returndatalist'];
      }
      if (is_array($args['returndatalist']) && count($args['returndatalist']) > 0)
      {
         $qItem->data()->returndatalist = $args['returndatalist'];
      }

      $queue = $this->getQueue($imageModel->get('type'));

      $args = array_filter($args, function ($value) {
        return $value !== null;
      });

        
      // These checks are across all actions. 
      if ($queue->isDuplicateActive($imageModel))
      {
        $qItem->addResult([
            'fileStatus' => ImageModel::FILE_STATUS_UNPROCESSED,
            'is_error' => false,
            'is_done' => true,
            'message' => __('A duplicate of this item is already active in queue. ', 'shortpixel-image-optimiser'),

        ]);

        return $qItem->result(); 

      }
      
      $in_queue = $this->isItemInQueue($imageModel, $args['action']);
      if (is_numeric($in_queue) && $in_queue !== false)
      {

        if (self::IN_QUEUE_ACTION_ADDED == $in_queue)
        {
          $qItem->addResult([
            'fileStatus' => ImageModel::FILE_STATUS_UNPROCESSED,
            'is_error' => false,
            'is_done' => false,
            'message' =>__('Action has been added to queue and will be processed after current actions', 'shortpixel-image-optimiser'),
          ]);
        }

        if (self::IN_QUEUE_SKIPPED == $in_queue)
        {
          $qItem->addResult([
            'fileStatus' => ImageModel::FILE_STATUS_UNPROCESSED,
            'is_error' => false,
            'is_done' => true,
            'message' =>__('This item is already awaiting processing in queue', 'shortpixel-image-optimiser'),
          ]); 
        }

        return $qItem->result();

      }

      $optimizer = $qItem->getApiController($args['action']);

      if (is_null($optimizer))
      {
         Log::addError('No optimiser found for this action, or action missing!', $args);
         $qItem->addResult([
            'fileStatus' => ImageModel::FILE_STATUS_UNPROCESSED,
            'is_error' => true,
            'is_done' => true,
            'message' => __('No action found!', 'shortpixel-image-optimiser'),
         ]);
      }

      $bool = false; 

      if (! is_null($optimizer))
      {
        $optimizer->setCurrentQueue($queue, $this);
        $bool = $optimizer->checkItem($qItem);
      }

      if (true === $bool)
      {
          $status = $optimizer->enQueueItem($qItem, $args);
          $this->lastQStatus = $status->qstatus;
          
          // Not API status does it own messaging.
          if ($status->qstatus !== RequestManager::STATUS_NOT_API)
          {
            $message = '';
            if ($status->numitems > 0)
            {
              
              $message = sprintf(__('Item %s added to Queue. %d items in Queue', 'shortpixel-image-optimiser'), $imageModel->getFileName(), $status->numitems);
  
              // Check if background process is active / this needs activating.
              $cronController = CronController::getInstance();
              $cronController->checkNewJobs();
            }
            else {
              $message = __('No items added to queue', 'shortpixel-image-optimiser');
              //$json->status = 0;
            }
  
            if (! property_exists($qItem->result(), 'message') || strlen($qItem->result->message) <= 0)
            {
              $qItem->addResult([
                'message' => $message,
              ]);
            }
  
          }

      }

      return $qItem->result();
  }

  /** Check if item and action is already listed in the queue 
   * 
   * @param ImageModel $mediaItem 
   * @return mixed 
   */
  public function isItemInQueue(ImageModel $mediaItem, $action = null)
  {
      $type = $mediaItem->get('type');

      $q = $this->getQueue($type);
      $bool = $q->isItemInQueue($mediaItem->get('id'));

      if (true === $bool)
      { 
        // @todo This queueItem should maybe not to stuffed with 'addresult'm since it's a different object. 
          $queueItem = $q->getItem($mediaItem->get('id'));
          
          if (is_object($queueItem))
          {
              $queueItem->setModel($mediaItem); 
              // @todo If item can be appended, probably add function in queueItem to add next_action and update to database (this q )?
              if (false === is_null($action) && false === $queueItem->data()->hasAction($action))
              {
                  // @todo This probably move up to addItemToQueue, also needs to add additional args
                  $queueItem->data()->addNextAction($action);
                  $q->updateItem($queueItem);

                  $bool = self::IN_QUEUE_ACTION_ADDED;

              }
              elseif(false === is_null($action)) // Only set this is action add is requested, otherwise keep boolean
              {
                  $bool = self::IN_QUEUE_SKIPPED; 

              }
          }

          
      }
      
      // Preventing double queries here

      return $bool;
  }

  // Processing Part

  // next tick of items to do.
  /* Processes one tick of the queue
  *
  * @return Object JSON object detailing results of run
  */
 // @todo This is the main function that starts the processing
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
        // If we are doing something special (restore, migrate etc), it should runs without credits, so we shouldn't be using any.
        $isCustomOperation = false;
        foreach($queueTypes as $qType)
        {
          $queue = $this->getQueue($qType);
          if ($queue && true === $queue->isCustomOperation())
          {
              $isCustomOperation = true;
              break;
          }
        }

        // Break out of quota if we are on normal operations.
        if (false === $isCustomOperation )
        {
          $quotaControl->forceCheckRemoteQuota(); // on next load check if something happenend when out and asking.
          $json = $this->getJsonResponse();
          $json->error = AjaxController::NOQUOTA;
          $json->status = false;
          $json->message =   __('Quota Exceeded','shortpixel-image-optimiser');
          return $json;
        }
      }

      // @todo Here prevent bulk from running when running flag is off
      // @todo Here prevent a runTick is the queue is empty and done already ( reliably )
      // @todo If once queue exited because of mediaItem, don't run the other one but abort
      $results = new \stdClass;
      $results->status = 1;
      $overlimit = false;
      
      if ( in_array('media', $queueTypes))
      {
        $mediaQ = $this->getQueue('media');
        $results->media = $this->runTick($mediaQ); // run once on mediaQ

        $overlimit = (Queue::RESULT_PREPARING_OVERLIMIT === $results->media->qstatus) ? true : false;

      }
      if (false === $overlimit && in_array('custom', $queueTypes))
      {
        $customQ = $this->getQueue('custom');
        $results->custom = $this->runTick($customQ);
      }

      $results->total = $this->calculateStatsTotals($results);
      $results = $this->numberFormatStats($results);

      return $results;
  }


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
      $data = $this->numberFormatStats($data);

      return $data;
  }


  /** Run the Queue once with X amount of items, send to processor or handle.  */
  // @todo Call by processQueue
  protected function runTick($Q)
  {
    $result = $Q->run();
    $fs = \wpSPIO()->filesystem();

    ResponseController::setQ($Q);

    // Items is array in case of a dequeue items.
    $items = (isset($result->items) && is_array($result->items)) ? $result->items : [];
    $qtype = $Q->getType();
    $qtype = strtolower($qtype);

    /* Only runs if result is array, dequeued items.
       Item is a MediaItem subset of QueueItem
    */
    foreach($items as $mainIndex => $qItem)
    {
          // Note, all these functions change content of QueueItem
          $action = $qItem->data()->action;
          $apiController = $qItem->getAPIController($action);
          $send_to_processing = true; 


          if (is_null($apiController))
          {
            Log::addError('No optimiser found for this action, or action missing!', $qItem);
            $qItem->addResult([
                'fileStatus' => ImageModel::FILE_STATUS_UNPROCESSED,
                'is_error' => true,
                'is_done' => true,
                'message' => __('No action found!', 'shortpixel-image-optimiser'),
            ]);
            
            $Q->itemFailed($qItem, true); 

          }
          else
          {
            $apiController->setCurrentQueue($Q, $this);
          }

          $item_id = $qItem->item_id;

          $imageModel = (! is_null($qItem->imageModel)) ? $qItem->imageModel : $fs->getImage($item_id, $qtype);
          if (is_object($imageModel))
          {
            $qItem->setModel($imageModel);
          }
          
          if (! is_object($imageModel)) // Error in loading imageModel, can't process this. 
          {
            Log::addWarn('ImageObject was empty when send to processing - ' . $item_id);
            $qItem->addResult([
                'apiStatus' => RequestManager::STATUS_NOT_API,
                'message' => __("File Error. Media Item could not be loaded with this ID ", 'shortpixel-image-optimiser'),
                'fileStatus' => ImageModel::FILE_STATUS_ERROR,
                'is_done' => true,
                'is_error' => true,
            ]);
            $Q->itemFailed($qItem, true); 
            $send_to_processing = false; 
          }
          elseif(true === $qItem->block())
          {
            $qItem->addResult([
                'apiStatus' => RequestManager::STATUS_UNCHANGED,
                'message' => __('Item is waiting (blocked)', 'shortpixel-image-optimiser'),
            ]);
            Log::addWarn('Encountered blocked item, processing success? ', $item_id);
            ResponseController::addData($item_id, 'fileName', $imageModel->getFileName());
            $send_to_processing = false; 
          }
          else
          {
            // This used in bulk preview for formatting filename.
            $qItem->addResult(
                ['filename' => $imageModel->getFileName()]
            );

            // Used in WP-CLI
            ResponseController::addData($item_id, 'fileName', $imageModel->getFileName());
          }
        
          $this->setLastID($item_id);

          if (! is_null($apiController) && true === $send_to_processing)
          {
            $apiController->sendToProcessing($qItem);
            $apiController->handleAPIResult($qItem);  
          }
          

          if (true === $qItem->result()->is_error &&  true === $this->args['is_bulk'] )
          {
             $this->LogBulk($qItem);
          }

          $result->items[$mainIndex] = $qItem->result(); // replace processed item, should have result now.
    }

    $result->stats = $Q->getStats();
    $json = $this->queueToJson($result);
    $this->checkQueueClean($result, $Q);

    return $json;
  }

  /**
   * getQueue
   * 
   * Get Queue Object for adding items to it.  This is dependent on the type of image. 
   *
   * @param [string] $type
   * @return Object|boolean Queue object
   */
  public function getQueue($type)
  {
      $queue = null;

      if ($type == 'media')
      {
          $queueName = ($this->args['is_bulk'] == true) ? 'media' : 'mediaSingle';
          $queue = new MediaLibraryQueue($queueName);
      }
      elseif ($type == 'custom')
      {
        $queueName = ($this->args['is_bulk'] == true) ? 'custom' : 'customSingle';
        $queue = new CustomQueue($queueName);
      }
      else
      {
        Log::addInfo("Get Queue $type seems not a queue");
        return false;
      }

      $options = $queue->getCustomDataItem('queueOptions');
      if ($options !== false)
      {
          $queue->setOptions($options);
      }
      return $queue;
  }

  protected function checkQueueClean($result, $q)
  {
      if ($result->qstatus == Queue::RESULT_QUEUE_EMPTY && false === $this->args['is_bulk'])
      {
          $stats = $q->getStats();

          if ($stats->done > 0 || $stats->fatal_errors > 0)
          {
             $q->cleanQueue(); // clean the queue
          }
      }
  }

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

  /** f a result Queue Stdclass to a JSON send Object */
  // Q
  protected function queueToJson($result, $json = false)
  {
      if (! $json)
        $json = $this->getJsonResponse();

      switch($result->qstatus)
      {
        case Queue::RESULT_PREPARING:
          $json->message = sprintf(__('Prepared %s items', 'shortpixel-image-optimiser'), $result->items );
        break;
        case Queue::RESULT_PREPARING_OVERLIMIT:
          $json->message = sprintf(__('Prepared %s items - but went over limit! ', 'shortpixel-image-optimiser'), $result->items );
        break;
        case Queue::RESULT_PREPARING_DONE:
          $json->message = sprintf(__('Preparing is done, queue has %s items ', 'shortpixel-image-optimiser'), $result->stats->total );
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

      return $json;
  }

  protected function setLastID($item_id)
  {
     self::$lastId = $item_id;
  }

  public function getLastQueueStatus()
  {
     return $this->lastQStatus;
  }

  public static function getLastId()
  {
     return self::$lastId;
  }

  public static function resetQueues()
  {
      $queues = array('media', 'mediaSingle', 'custom', 'customSingle');
      foreach($queues as $qName)
      {
          $q = new MediaLibraryQueue($qName);
          $q->activatePlugin();
      }
  }

  // Q
  public static function uninstallPlugin()
  {

    $queues = ['media', 'mediaSingle', 'custom', 'customSingle'];
    foreach($queues as $qName)
    {
        $q = new MediaLibraryQueue($qName);
        $q->uninstall();
    }

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
             if ($key == 'percentage_done')
             {
                if (property_exists($results->custom->stats, 'total') && $results->custom->stats->total == 0)
                   $perc = $value;
                elseif(property_exists($results->media->stats, 'total') && $results->media->stats->total == 0)
                {
                   $perc = $object->stats->$key;
                }
                else
                {
                  $total = $results->custom->stats->total + $results->media->stats->total;
                  $done = $results->custom->stats->done + $results->media->stats->done;
                  $fatal = $results->custom->stats->fatal_errors + $results->media->stats->fatal_errors;
                  $perc = round((100 / $total) * ($done + $fatal), 0, PHP_ROUND_HALF_DOWN);
               //		$perc = round(($object->stats->$key + $value) / 2); //exceptionnes.
                }
                $object->stats->$key  = $perc;
             }
             elseif (is_numeric($object->stats->$key)) // add only if number.
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

  private function numberFormatStats($results) // run the whole stats thing through the numberFormat.
  {
    //qn: array('media', 'custom', 'total')
     foreach($results as $qn => $item)
     {
        if (is_object($item) && property_exists($item, 'stats'))
        {
          foreach($item->stats as $key => $value)
          {
               $raw_value = $value;
               if (is_object($value))
               {
                  foreach($value as $key2 => $val2) // embedded 'images' can happen here.
                  {
                   $value->$key2 = UiHelper::formatNumber($val2, 0);
                  }
               }
               elseif (strpos($key, 'percentage') !== false)
               {
                  $value = UiHelper::formatNumber($value, 2);
               }
               elseif (is_numeric($value))
               {
                  $value = UiHelper::formatNumber($value, 0);
               }

              $results->$qn->stats->$key = $value;
            /*	if (! property_exists($results->$qn->stats, 'raw'))
                $results->$qn->stats->raw = new \stdClass;

              $results->$qn->stats->raw->$key = $raw_value; */
          }
        }
     }
     return $results;
  }

  /**
  * @integration Regenerate Thumbnails Advanced
  * Called via Hook when plugins like RegenerateThumbnailsAdvanced Update an thumbnail
  */
// @todo - move this to the optimiser.
  public function thumbnailsChangedHookLegacy($postId, $originalMeta, $regeneratedSizes = array(), $bulk = false)
  {
      $this->thumbnailsChangedHook($postId, $regeneratedSizes);
  }

  // @todo - move this to the optimiser.
  public function thumbnailsChangedHook($post_id, $sizes)
  {
     $fs = \wpSPIO()->filesystem();
     $settings = \wpSPIO()->settings();
     $imageObj = $fs->getMediaImage($post_id);

     if (! is_object($imageObj))
     {
        Log::addWarn('Thumbnails changed on something thats not object', $imageObj);
        return false;
     }

     Log::addDebug('Regenerated Thumbnails reported', $sizes);

     if (count($sizes) == 0)
      return;

      $metaUpdated = false;
      foreach($sizes as $sizeName => $size) {
          if(isset($size['file']))
          {

              //$fileObj = $fs->getFile( (string) $mainFile->getFileDir() . $size['file']);
              $thumb = $imageObj->getThumbnail($sizeName);
              if ($thumb !== false)
              {

                $thumb->setMeta('status', ImageModel::FILE_STATUS_UNPROCESSED);
                $thumb->onDelete(true);

                $metaUpdated = true;
              }
              else {
                Log::addDebug('Could not find thumbnail to update: ', $thumb);
              }
          }
      }

      if ($metaUpdated)
         $imageObj->saveMeta();



      if (\wpSPIO()->env()->is_autoprocess)
      {
          $imageObj = $fs->getMediaImage($post_id, false);
          if($imageObj->isProcessable())
          {

            $this->addItemToQueue($imageObj);
          }
      }
  }

  // @todo - move this to the optimiser.
  public function scaledImageChangedHook($post_id, $removed = false)
  {
      $fs = \wpSPIO()->filesystem();
      $settings = \wpSPIO()->settings();
      $imageObj = $fs->getMediaImage($post_id);


      if ($imageObj->isScaled())
      {
        $imageObj->setMeta('status', ImageModel::FILE_STATUS_UNPROCESSED);
        $webp = $imageObj->getWebp();
        if (is_object($webp) && $webp->exists())
          $webp->delete();

          $avif = $imageObj->getAvif('avif');
          if (is_object($avif) && $avif->exists())
            $avif->delete();

        // Normally we would use onDelete for this to remove all meta, but since image is the whole object and it would remove all meta, this is not possible.
        $imageObj->setmeta('webp', null);
        $imageObj->setmeta('avif', null);
        $imageObj->setmeta('compressedSize', null);
        $imageObj->setmeta('compressionType', null);
        $imageObj->setmeta('originalWidth', null);
        $imageObj->setmeta('originalHeight', null);
        $imageObj->setmeta('tsOptimized', null);


        if ($imageObj->hasBackup())
        {
           $backup = $imageObj->getBackupFile();
           $backup->delete();
        }
      }

      $imageObj->saveMeta();

      if (false === $removed && \wpSPIO()->env()->is_autoprocess)
      {
          $imageObj = $fs->getMediaImage($post_id, false);
          if($imageObj->isProcessable())
          {
            $this->addItemToQueue($imageObj);
          }
      }
  }

  private function logBulk(QueueItem $qItem)
  {
    $item_id = $qItem->item_id;
    $responseItem = ResponseController::getResponseItem($item_id);

    $type = (is_object($qItem->imageModel)) ? $qItem->imageModel->get('type') : false;

    if (false === $type)
    {
      return;
    }

    $fs = \wpSPIO()->filesystem();
    $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
    $fileLog = $fs->getFile($backupDir->getPath() . 'current_bulk_' . $type . '.log');

    $time = UiHelper::formatTs(time());

    $fileName = $responseItem->fileName;
    $message = ResponseController::formatItem($item_id);

    $fileLog->append($time . '|' . $fileName . '| ' . $item_id . '|' . $message . ';' .PHP_EOL);
  }


} // class
