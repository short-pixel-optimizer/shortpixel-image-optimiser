<?php
declare(strict_types=1);
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
use stdClass;

/**
 * Orchestrates the two image-optimisation queues (Media Library and Custom).
 *
 * Acts as the glue between caller code (AJAX, WP-CLI, hooks) and the concrete
 * Queue subclasses (MediaLibraryQueue / CustomQueue), which in turn wrap the
 * bundled ShortQ library.  All public entry-points ultimately delegate to one of
 * those two pipelines; the type string 'media' or 'custom' determines which.
 *
 * Typical flow for a single-image request:
 *   1. Caller invokes addItemToQueue() with an ImageModel.
 *   2. QueueController resolves the correct Queue, checks for duplicates and
 *      in-queue status, then delegates to the appropriate ApiController to
 *      validate and enqueue the item.
 *   3. CronController notices the new item and calls processQueue(), which
 *      drives runTick() on each active queue type.
 *   4. runTick() calls Queue::run() (prepare or dequeue), then dispatches each
 *      dequeued item through its ApiController (sendToProcessing / handleAPIResult).
 *
 * Bulk vs. single distinction:
 *   The constructor $args['is_bulk'] flag switches getQueue() between the bulk
 *   queue names ('media'/'custom') and the single-item names
 *   ('mediaSingle'/'customSingle'), keeping bulk and single items in separate
 *   ShortQ queues.
 *
 * @package ShortPixel\Controller
 */
class QueueController
{

  /** @var int Returned by isItemInQueue() when a new action was appended to an existing item. */
  const IN_QUEUE_ACTION_ADDED = 1;
  /** @var int Returned by isItemInQueue() when the item already carries the requested action. */
  const IN_QUEUE_SKIPPED = 2;

  /** @var int|null Last item_id processed; retained for error diagnostics. */
  protected static $lastId;
  /** @var int|null Last queue status code from the most recent enqueue/run operation. */
  protected $lastQStatus;

  /** @var array Runtime arguments: currently only 'is_bulk' (bool). */
  protected $args;

  /**
   * @param array $args Optional constructor arguments. Supports 'is_bulk' (bool, default false).
   */
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
   * For requestAlt actions on WPML-duplicated attachments, each language
   * variant is enqueued separately first (addWpmlAiItemsToQueue), and the
   * duplicate-active check is skipped — every attachment record needs its
   * own AI request, and the just-queued variants must not make the original
   * count as an active duplicate (d55dbeca, fix for #42).
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
        'recent_upload' => false, 
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

      if (true === $args['forceExclusion']) 
      {
         $qItem->data()->forceExclusion = $args['forceExclusion'];
      }

      $queue = $this->getQueue($imageModel->get('type'));

      $args = array_filter($args, function ($value) {
        return $value !== null;
      });

      // When generating AI data for WPML duplicates, queue each language variant separately.
      if ($args['action'] === 'requestAlt' && $imageModel->get('type') !== 'custom' && method_exists($imageModel, 'getWPMLDuplicates'))
      {
          $WPMLduplicates = $imageModel->getWPMLDuplicates(); 
          if (is_array($WPMLduplicates) && count($WPMLduplicates) > 0)
          {
            // @todo This probably not the way,  use the same function to add or something like this? 
            // @todo Also calls duplicates function twice, should fix.  Move the Add WPML to WPML.php via filter here or something like this? 
              $this->addWpmlAiItemsToQueue($imageModel, $WPMLduplicates, $args);
          }
      }

      // This should be @todo  be checked when doing AI actions!
      // These checks are across all actions.
      if ($args['action'] !== 'requestAlt' && true === $queue->isDuplicateActive($imageModel))
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


      unset($in_queue);

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
  
            $result = $qItem->result(); 
            if (! property_exists($result, 'message') || is_null($result->message) || strlen( (string) $result->message) <= 0)
            {
              $qItem->addResult([
                'message' => $message,
              ]);
            }
  
          }

      }

      $result = $qItem->result();
      return $result;
  }

  /**
   * Enqueue the selected item and all WPML language duplicates for AI generation.
   *
   * Each WPML duplicate is a separate attachment record and needs its own AI request.
   *
   * @param ImageModel $imageModel
   * @param array $args
   * @return object
   */
  protected function addWpmlAiItemsToQueue(ImageModel $imageModel, array $duplicateIds, $args)
  {
//      $itemIds = array_unique(array_merge([$imageModel->get('id')], $duplicateIds));
      $queue = $this->getQueue($imageModel->get('type'));
      $fs = \wpSPIO()->filesystem(); 
      $totalItems = 0;
      $skippedItems = 0;

      $resultItem = QueueItems::getImageItem($imageModel);

      foreach ($duplicateIds as $itemId)
      {
          $mediaItem = $fs->getImage($itemId, $imageModel->get('type'));
          if (! is_object($mediaItem)) {
              continue;
          }

          $inQueue = $this->isItemInQueue($mediaItem, 'requestAlt');
          if (self::IN_QUEUE_ACTION_ADDED === $inQueue || self::IN_QUEUE_SKIPPED === $inQueue) {
              $skippedItems++;
              continue;
          }

          $qItem = QueueItems::getImageItem($mediaItem);
          $qItem->requestAltAction($args);
          $status = $queue->addQueueItem($qItem);
          $this->lastQStatus = $status->qstatus;
          $totalItems += $status->numitems;
      }

      if ($totalItems > 0) {
          $message = sprintf(__('%d AI language variants added to the queue.', 'shortpixel-image-optimiser'), $totalItems);
      } else {
          $message = __('No AI items were added to the queue because all language variants were already active.', 'shortpixel-image-optimiser');
      }

      if ($skippedItems > 0) {
          $message .= ' ' . sprintf(_n('%d language variant was already active.', '%d language variants were already active.', $skippedItems, 'shortpixel-image-optimiser'), $skippedItems);
      }

      $resultItem->addResult([
          'fileStatus' => ImageModel::FILE_STATUS_UNPROCESSED,
          'is_error' => false,
          'is_done' => ($totalItems === 0),
          'message' => trim($message),
          'qstatus' => $this->lastQStatus,
          'numitems' => $totalItems,
      ]);

      return $resultItem->result();
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

      $status = 0; 

      if (true === $bool)
      { 
        // @todo This queueItem should maybe not to stuffed with 'addresult'm since it's a different object. 
          $queueItem = $q->getItem($mediaItem->get('id'));
          
          if (is_object($queueItem))
          {
            // @todo There is a problem here, when queueItem return is not an object, only boolean is returned, but we want an integer for check in AddItemToQeueu
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

  /**
   * Processes one tick of the queue for each requested queue type.
   *
   * Guards are applied in order: API key validity, quota availability (bypassed
   * for custom operations such as restore/migrate), then one runTick() call per
   * queue type.  If the media queue returns RESULT_PREPARING_OVERLIMIT the
   * custom queue tick is skipped for that request.
   *
   * Combined stats across both queues are accumulated by calculateStatsTotals()
   * and formatted by numberFormatStats() before returning.
   *
   * @param array $queueTypes Queue type identifiers to process, e.g. ['media', 'custom'].
   * @return \stdClass Result object with per-type and aggregated 'total' stats,
   *                   or an error object with status=false and an error code on failure.
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
      } // No Quota Check 

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


  /**
   * Returns combined stats for both queues without running a processing tick.
   *
   * Used by the bulk UI on page load to render initial counters.
   *
   * @return object Stats object containing media, custom and total sub-objects,
   *                each with a stats property as returned by Queue::getStats().
   */
  public function getStartupData() : object
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


  /**
   * Runs one processing tick on the given Queue.
   *
   * Calls Queue::run() which either prepares items (in preparing state) or
   * dequeues a batch.  For each dequeued QueueItem the method:
   *   1. Resolves the ApiController for the item's action.
   *   2. Loads the ImageModel if not already attached.
   *   3. Skips blocked items or items whose ImageModel failed to load.
   *   4. Delegates to ApiController::sendToProcessing() then handleAPIResult().
   *   5. Logs failed items to the per-type bulk log when running in bulk mode.
   *
   * The final queue stats and converted JSON are returned; a cleanQueue() is
   * triggered automatically when the single-item queue empties.
   *
   * @param Queue $Q The Queue instance to tick (MediaLibraryQueue or CustomQueue).
   * @return \stdClass JSON-formatted result object as produced by queueToJson().
   */
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
          
          // Set the ImageModel if not set. 
          if (is_null($qItem->imageModel) && is_object($imageModel))
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
   * @return Object|boolean Queue object, false if wrong type was given
   */
  public function getQueue($type)
  {
      $queue = null;

      if ($type == 'media')
      {
          $queueName = (true == $this->args['is_bulk']) ? 'media' : 'mediaSingle';
          $queue = new MediaLibraryQueue($queueName);
      }
      elseif ($type == 'custom')
      {
        $queueName = (true == $this->args['is_bulk']) ? 'custom' : 'customSingle';
        $queue = new CustomQueue($queueName);
      }
      else
      {
        Log::addInfo("Get Queue $type seems not a queue");
        return false;
      }

      $options = $queue->getOptions();
      if ($options !== false)
      {
          $queue->setOptions($options);
      }
      return $queue;
  }

  /**
   * Cleans the queue after a single-item run completes.
   *
   * Triggers Queue::cleanQueue() when the queue is empty and not running in
   * bulk mode, but only when there are completed or fatally errored items to
   * remove, avoiding spurious cleans on an already-empty queue.
   *
   * @param \stdClass $result Result object from runTick() containing a qstatus property.
   * @param Queue     $q      The Queue instance that was just ticked.
   * @return void
   */
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

  /**
   * Returns a blank JSON response object with null-initialised standard fields.
   *
   * @return \stdClass Object with status, result, results, and message properties set to null.
   */
  protected function getJsonResponse() : object
  {

    $json = new \stdClass;
    $json->status = null;
    $json->result = null;
    $json->results = null;
    $json->message = null;

    return $json;
  }

  /**
   * Converts a raw Queue result stdClass into a JSON-friendly response object.
   *
   * Switches on $result->qstatus and populates $json->message (and optionally
   * $json->results for RESULT_ITEMS) with a human-readable string.  Stats are
   * copied verbatim when present on $result.  When $json is not provided, a
   * fresh blank response from getJsonResponse() is used.
   *
   * @param \stdClass       $result Raw result from Queue::run().
   * @param \stdClass|false $json   Optional pre-existing response object to populate.
   * @return \stdClass Populated response object with qstatus and optional stats properties.
   */
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

      if (property_exists($result, 'stats'))
        $json->stats = $result->stats;

      return $json;
  }

  /**
   * Stores the most recently processed item ID in the static class property.
   *
   * @param int $item_id The item ID to record.
   * @return void
   */
  protected function setLastID($item_id)
  {
     self::$lastId = $item_id;
  }

  /**
   * Returns the queue status code from the most recent enqueue or run operation.
   *
   * @return int|null One of the Queue::RESULT_* constants, or null before any run.
   */
  public function getLastQueueStatus()
  {
     return $this->lastQStatus;
  }

  /**
   * Returns the item ID that was most recently passed through the processing pipeline.
   *
   * Useful for diagnosing errors: when a fatal PHP error occurs mid-processing,
   * this ID indicates which item caused the problem.
   *
   * @return int|null The last item ID, or null if no item has been processed yet.
   */
  public static function getLastId()
  {
     return self::$lastId;
  }

  /**
   * Resets all four queue instances (bulk and single, for both pipelines) to a clean state.
   *
   * Called on plugin activation to discard any stale queue data from a previous
   * installation or upgrade.
   *
   * @return void
   */
  public static function resetQueues()
  {
      $queues = array('media', 'mediaSingle', 'custom', 'customSingle');
      foreach($queues as $qName)
      {
          $q = new MediaLibraryQueue($qName);
          $q->activatePlugin();
      }
  }

  /** On Uninstall plugin, remove all queue data of this plugin
   * 
   * @return void 
   */
  public static function uninstallPlugin()
  {

    $queues = ['media', 'mediaSingle', 'custom', 'customSingle'];
    foreach($queues as $qName)
    {
        $q = new MediaLibraryQueue($qName);
        $q->uninstall();
    }

  }

  /**
   * Merges per-queue stats into a single 'total' object for bulk reporting.
   *
   * Handles all four cases: media-only, custom-only, neither, or both.  When
   * both are present, custom stats are cloned as the base and media values are
   * added field-by-field.  Special rules apply:
   *   - percentage_done: recalculated from the combined done+fatal/total ratio,
   *     with fallback to the non-zero side when one queue has zero total items.
   *   - bool fields: true wins over false except for is_finished (both must be true).
   *   - object fields (e.g. images sub-object): child numeric values are summed.
   *   - Keys present only in media stats are added as-is to the total.
   *
   * Input format: $results->media->stats and/or $results->custom->stats (stdClass).
   *
   * @param \stdClass $results Object with optional 'media' and 'custom' sub-objects.
   * @return \stdClass|null Object with a 'stats' property containing merged totals,
   *                        or null when neither pipeline has stats.
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
                // True > False in total since this status is true for one of the items. Except for is_finished, only when BOTH are finished. 
                // @todo This logic should perhaps be revised somehow. 
                if ($value === true && $object->stats->$key === false && $key !== 'is_finished')
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
          else // If key does not exist, still add value from media to totals. 
          {
            $object->stats->$key = $value; 
          }
      }


      return $object;
  }

  /**
   * Applies locale-aware number formatting to every stat value in all queue result objects.
   *
   * Iterates over the media, custom and total sub-objects of $results.  For each
   * stats field: embedded objects (e.g. the images sub-object) have their numeric
   * children formatted with 0 decimals; fields whose key contains 'percentage' are
   * formatted with 2 decimals; all other numeric values are formatted with 0 decimals.
   * Non-numeric, non-object values (booleans, strings) are left unchanged.
   *
   * @param \stdClass $results Combined result object from processQueue() or getStartupData().
   * @return \stdClass The same object with formatted stat values applied in-place.
   */
  private function numberFormatStats($results)
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
          }
        }
     }
     return $results;
  }

  /**
   * Legacy hook shim for the Regenerate Thumbnails Advanced integration.
   *
   * Called with the extended four-argument signature used by older versions of
   * RegenerateThumbnailsAdvanced; delegates immediately to thumbnailsChangedHook().
   *
   * @integration Regenerate Thumbnails Advanced
   * @param int   $postId           Attachment post ID.
   * @param mixed $originalMeta     Original attachment meta (unused).
   * @param array $regeneratedSizes Map of size names to size arrays, as passed by the plugin.
   * @param bool  $bulk             Whether the regeneration is a bulk run (unused).
   * @return void
   */
  public function thumbnailsChangedHookLegacy($postId, $originalMeta, $regeneratedSizes = array(), $bulk = false)
  {
      $this->thumbnailsChangedHook($postId, $regeneratedSizes);
  }

  /**
   * Reacts to thumbnail regeneration by marking changed thumbnails as unprocessed.
   *
   * For each size reported as regenerated, the corresponding thumbnail's meta is
   * reset to FILE_STATUS_UNPROCESSED and its optimised file is deleted (onDelete).
   * When auto-processing is enabled and the parent attachment is still processable,
   * it is re-queued for optimisation.
   *
   * @param int   $post_id Attachment post ID.
   * @param array $sizes   Map of size name to size data arrays (must contain a 'file' key).
   * @return void
   */
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

  /**
   * Reacts to a scaled (full-size) image replacement by clearing its optimisation state.
   *
   * When WordPress replaces a scaled image (e.g. after an image edit), the old
   * optimised file, its WebP/AVIF variants, the backup, and all related postmeta
   * fields are wiped so that the new file is treated as unoptimised.  When
   * $removed is false and auto-processing is enabled, the image is re-queued.
   *
   * @param int  $post_id Attachment post ID.
   * @param bool $removed Whether the scaled image was removed (true) rather than replaced.
   * @return void
   */
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

        $backupModel = $imageObj->getBackupModel(); 

        if ($backupModel->hasBackup($imageObj))
        {
           $backup = $backupModel->getBackupFile($imageObj);
           if (is_object($backup))
           {
              $backup->delete();
           }

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

  /**
   * Appends a failed-item entry to the per-type bulk log file during a bulk run.
   *
   * The log is written to the ShortPixel backup directory as
   * `current_bulk_{type}.log`.  Each line is pipe-delimited:
   * `{timestamp}|{filename}|{item_id}|{formatted message}`.
   * Returns silently when the item's type cannot be determined.
   *
   * @param QueueItem $qItem The failed queue item.
   * @return void
   */
  private function logBulk(QueueItem $qItem)
  {
    $item_id = $qItem->item_id;
    $type = (is_object($qItem->imageModel)) ? $qItem->imageModel->get('type') : false;

    if (false === $type)
    {
      return;
    }

    $fs = \wpSPIO()->filesystem();
    $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
    $fileLog = $fs->getFile($backupDir->getPath() . 'current_bulk_' . $type . '.log');

    $time = UiHelper::formatTs(time());

    $fileName = $qItem->imageModel->getFileName();
    $message = ResponseController::formatQItem($qItem);

    $fileLog->append($time . '|' . $fileName . '| ' . $item_id . '|' . $message . ';' .PHP_EOL);
  }


} // class
