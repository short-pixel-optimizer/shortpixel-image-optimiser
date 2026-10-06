<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use Shortpixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Helper\UiHelper;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Queue\QueueItemResult;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Abstract base class for all optimizer and action controllers in the image pipeline.
 *
 * Concrete subclasses (OptimizeController, ActionController, OptimizeAiController) each
 * implement a specific processing path: standard API optimization, local/sync actions
 * (restore, reoptimize, PNG-to-JPG), and AI feature processing respectively.
 *
 * Pipeline flow:
 *   QueueController calls enqueueItem() → sendToProcessing() dispatches to ApiController
 *   → ApiController stores the API response on the QueueItem result object
 *   → QueueController calls handleAPIResult() to route results back into the image model
 *   → finishItemProcess() marks the item done or chains the next queued action.
 *
 * @package ShortPixel\Controller\Optimizer
 */
abstract class OptimizerBase
{

    /** @var object ApiController or AiController instance used by the subclass. */
    protected $api;

    /** @var string Short label identifying which API this optimizer uses (e.g. 'optimize', 'ai', 'action'). */
    protected $apiName;

    /** @var \stdClass Base JSON response object shared across a single request cycle. */
    protected $response;

    /** @var object|null The active queue instance (MediaLibraryQueue or CustomQueue); set by QueueController before processing. */
    protected $currentQueue;

    /** @var object|null The QueueController instance; used to distinguish bulk from single-item runs. */
    protected $queueController;

    /** @var QueueItem[]|null Tracks items currently blocked (download in progress) so a PHP shutdown can unblock them. */
    protected static $blockedItems;

    /**
     * Enqueues a single item for processing (not for bulk queue runs).
     *
     * @param QueueItem $qItem The item to enqueue.
     * @param array     $args  Action-specific arguments.
     * @return \stdClass Queue status object.
     */
    public abstract function enqueueItem(QueueItem $qItem, $args = []);

    /**
     * Handles the API (or action) result stored on the queue item after sendToProcessing().
     *
     * Interprets result status codes, updates the image model, fires hooks, and either
     * marks the item done or flags it for retry/failure.
     *
     * @param QueueItem $qItem The queue item whose result should be evaluated.
     * @return void
     */
    public abstract function handleAPIResult(QueueItem $qItem);

    /**
     * Performs any subclass-specific cleanup or response adjustment when an item fails.
     *
     * @param QueueItem $qItem The failed queue item.
     * @return void
     */
    protected abstract function HandleItemError(QueueItem $qItem);

    /**
     * Dispatches the queue item to the appropriate API or local handler.
     *
     * @param QueueItem $qItem The item to process.
     * @return mixed Varies by subclass.
     */
    public abstract function sendToProcessing(QueueItem $qItem);

    /** Check if item is available for action / process
    *
    * @param QueueItem $qItem
    * @return boolean
    */
    public abstract function checkItem(QueueItem $qItem);

    /** @var bool Only register the shutdown function when blocking items; avoids fatal errors during plugin updates/deactivation. */
    public $shutdown_registered = false;

    /** @var static[] Per-class singleton instances keyed by the concrete class name. */
    static $instances = [];

    /** Initialise the default JSON response object; use {@see getInstance()} to obtain instances. */
    public function __construct()
    {
       $this->response = $this->getJsonResponse();
    }


    /**
     * Returns the singleton instance for the concrete subclass that was called.
     *
     * Uses late-static binding so each concrete subclass maintains its own instance.
     *
     * @return static
     */
    public static function getInstance()
    {
      //exit('This call is wron because in it messes with ActionController - Reoptimize ( calls ActionController again instead of OptimizeConrtoller');
      $calledClass = get_called_class();

      if (! isset(static::$instances[$calledClass]))
      {
         static::$instances[$calledClass] = new $calledClass();
      }

        return self::$instances[$calledClass];
    }

    /** Standard fields for JSON response. 
    * 
    * @return stdClass  Json base structure
    */
    protected function getJsonResponse()
    {

      $json = new \stdClass;
      $json->status = null;
      $json->result = null;
      $json->results = null;
      $json->message = null;

      return $json;
    }


    /** Check if the imageModel was properly loading on the qitem. 
     * 
     * @param QueueItem $qItem 
     * @return bool 
     */
    protected function checkImageModel(QueueItem $qItem)
    {

      if (false === $qItem->checkImageModelExists())  // something wrong
      {

        $qItem->addResult([
            'message' => __("File Error. File could not be loaded with this ID ", 'shortpixel-image-optimiser'),
            'apiStatus' => RequestManager::STATUS_NOT_API,
            'fileStatus' => ImageModel::FILE_STATUS_ERROR,
            'is_done' => true,
            'is_error' => true,
        ]);
        return false;
      }

      return true;

    }

    /**
     * Marks a queue item as blocked and persists the block flag to the database.
     *
     * A blocked item is one whose optimized files are being downloaded; blocking prevents
     * a concurrent process from picking it up again. Registers a PHP shutdown function
     * (once per process) to automatically unblock any items that were never explicitly
     * unblocked (e.g. on a fatal error or timeout).
     *
     * @param QueueItem $qItem The item to block.
     * @return void
     */
    protected function blockItem(QueueItem $qItem)
    {
       $qItem->block(true);
       $q = $this->getCurrentQueue($qItem);
       $q->updateItem($qItem);

       self::$blockedItems[$qItem->item_id] = $qItem;

       if (false === $this->shutdown_registered)
         {
            register_shutdown_function([$this, 'checkBlockedItems']);
            $this->shutdown_registered = true;
         }
    }

    /**
     * Removes the block flag from a queue item and persists the change.
     *
     * Also removes the item from the static blocked-items registry so the shutdown
     * handler does not attempt to unblock it a second time.
     *
     * @param QueueItem $qItem The item to unblock.
     * @return void
     */
    protected function unBlockItem(QueueItem $qItem)
    {
       $qItem->block(false);
       $q = $this->getCurrentQueue($qItem);
       $q->updateItem($qItem);

       if (isset(self::$blockedItems[$qItem->item_id]))
       {
          unset(self::$blockedItems[$qItem->item_id]);
       }
    }

    /**
     * PHP shutdown callback: unblocks any items that are still in the blocked registry.
     *
     * Registered automatically by blockItem() the first time a block occurs in a request.
     * Guards against processes that terminated before calling unBlockItem() (e.g. on a
     * fatal error or memory limit breach).
     *
     * @return void
     */
    public function checkBlockedItems()
    {
        if (is_null(self::$blockedItems) || count(self::$blockedItems) == 0)
        {
           return;     
        }

        foreach(self::$blockedItems as $blockedItem) // end of process, unblock hanging items. 
        {
            Log::addWarn('Shutdown unblocking Item: ', $blockedItem);
             $this->unBlockItem($blockedItem);     
        }

    }

    /** Sets the current queue and QueueController.  This is to keep the distinction between single / bulk and set by QueueController
     * 
     * @param object $queue 
     * @param object $queueController 
     * @return void 
     */
    public function setCurrentQueue($queue, $queueController)
    {
       $this->queueController = $queueController;
       $this->currentQueue = $queue;
    }

    /** Get the current set queue and if not available, create one.
     * 
     * @param QueueItem $qItem
     * @return Object
     */
    protected function getCurrentQueue(QueueItem $qItem)
    {
        if (is_null($this->currentQueue))
        {
           $type = $qItem->imageModel->get('type');
           $queueController = $this->getQueueController(); // @todo This probably will mess with bulk setting. Correct for it.
           $this->currentQueue = $queueController->getQueue($type);
        }

        return $this->currentQueue;
    }

   /** Get what is currently set for QueueController, if not, create a new one.
    * 
    * @return QueueController 
    */
    protected function getQueueController()
    {
       if (is_null($this->queueController))
       {
          $this->queueController = new QueueController(); 
       }

       return $this->queueController; 
    }

    /** Finished the Item action.  This main function handles possible next function and if so, put that one in queue.
     * 
     * @param QueueItem $qItem 
     * @return Object Result Object
     */
    protected function finishItemProcess(QueueItem $qItem, $args = []) : QueueItemResult
    {
        $queue = $this->getCurrentQueue($qItem); 
        $fs = \wpSPIO()->filesystem();
        
        // If the action is passed as direct action / out of queue, there might be no queueItem in DB
        if (is_object($qItem->getQueueItem()))
        {
           $queue->itemDone($qItem); 
        }

        // Can happen with actions outside queue / direct action 
        if (true === $qItem->data()->hasNextAction())
        {
            $action = $qItem->data()->popNextAction(); 
            $item_id = $qItem->item_id; 
            $item_type = $qItem->imageModel->get('type');
            $imageModel = $fs->getImage($item_id, $item_type, false);

            $args['action'] = $action; 
            
            $keepArgs = $qItem->data()->getKeepDataArgs();
            if (true === $qItem->data()->hasNextAction())
            {
                $args['next_actions'] = $qItem->data()->next_actions; 
            }
            $args = array_merge($args, $keepArgs);

            Log::addInfo("New Action $action for $item_id with args", $args);

            $queueController = $this->getQueueController(); 
            $result = $queueController->addItemToQueue($imageModel, $args); 
        }

        if (! isset($result))
        {
           $result = $qItem->result(); 
        }

        return $result; 

    }

    /**
     * Attaches before/after preview URLs to the queue item result for display in the bulk UI.
     *
     * Selects the best displayable file via UiHelper::findBestPreview(), then resolves
     * the backup (original) URL and the current (optimized) URL. PDF files are skipped.
     * If no backup exists, only the optimized URL is set and the original is false.
     *
     * @param QueueItem $qItem The item whose result should receive preview URLs.
     * @return QueueItem The same queue item with 'original' and 'optimized' result keys added.
     */
    protected function addPreview(QueueItem $qItem)
    {
      $imageModel = $qItem->imageModel; 
      $showItem = UiHelper::findBestPreview($imageModel); // find smaller / better preview
      $fs = \wpSPIO()->filesystem();

      $original = $optimized = false;

      $backupModel = BackupController::getBackupModel($imageModel); 

      if ($showItem->getExtension() == 'pdf') // non-showable formats here
      {

      } elseif ($backupModel->hasBackup($showItem)) {
        $backupFile = $backupModel->getBackupFile($showItem); 
        if (false === is_object($backupFile))
        {
           $backupFile = $backupModel->getMainBackupFile();
        } // attach backup for compare in bulk
        $backup_url = $fs->pathToUrl($backupFile);
        $original = $backup_url;
        $optimized = $fs->pathToUrl($showItem);
      } else {
        $original = false;
        $optimized = $fs->pathToUrl($showItem);
      }

      $qItem->addResult([
        'original' => $original,
        'optimized' => $optimized,
      ]);

      return $qItem;
    }

}
