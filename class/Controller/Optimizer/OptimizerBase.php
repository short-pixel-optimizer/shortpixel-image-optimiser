<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use Shortpixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\QueueController;
use ShortPixel\Helper\UiHelper;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use stdClass;

abstract class OptimizerBase
{

    protected $api;
    protected $apiName; 

    protected $response; // json response lives here.
    protected $currentQueue;  // trying to keep minimum, but optimize needs to speak to queue for items.
    protected $queueController; // Needed to keep track of bulk /non-bulk

    public abstract function enqueueItem(QueueItem $qItem, $args = []);
    public abstract function handleAPIResult(QueueItem $qItem);
    protected abstract function HandleItemError(QueueItem $qItem);
    public abstract function sendToProcessing(QueueItem $qItem);

    /** Check if item is available for action / process
    * 
    * @param QueueItem $qItem 
    * @return boolean 
    */
    public abstract function checkItem(QueueItem $qItem);

    static $instances = []; 

    public function __construct()
    {
       $this->response = $this->getJsonResponse();
    }


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
    protected function finishItemProcess(QueueItem $qItem, $args = [])
    {
        $queue = $this->getCurrentQueue($qItem); 
        $fs = \wpSPIO()->filesystem();
        // If the action is passed as direct action / out of queue, there might be no queueItem in DB
        if (is_object($qItem->getQueueItem()))
        {
           $queue->itemDone($qItem); 
        }

         Log::addTemp('FinishItemProcess: ', $qItem->data());
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
               Log::addTemp('Finishing, next actions: ', $qItem->data()->next_actions);
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

    protected function addPreview(QueueItem $qItem)
    {
      $imageModel = $qItem->imageModel; 
      $showItem = UiHelper::findBestPreview($imageModel); // find smaller / better preview
      $fs = \wpSPIO()->filesystem();

      $original = $optimized = false;

      if ($showItem->getExtension() == 'pdf') // non-showable formats here
      {
        //								 $item->result->original = false;
//								 $item->result->optimized = false;
      } elseif ($showItem->hasBackup()) {
        $backupFile = $showItem->getBackupFile(); // attach backup for compare in bulk
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
