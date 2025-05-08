<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use Shortpixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\QueueController;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


abstract class OptimizerBase
{

    protected $api;
    protected $apiName; 

    protected $response; // json response lives here.
    protected $currentQueue;  // trying to keep minimum, but optimize needs to speak to queue for items.
    protected $queueController; // Needed to keep track of bulk /non-bulk

    //public abstract function getQueueItem();

    public abstract function enqueueItem(QueueItem $qItem, $args = []);
    public abstract function handleAPIResult(QueueItem $qItem);
    protected abstract function HandleItemError(QueueItem $qItem);

    public abstract function sendToProcessing(QueueItem $qItem);

    /**
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

    public function setCurrentQueue($queue, $queueController)
    {
       $this->queueController = $queueController;
       $this->currentQueue = $queue;
    }

    /**
     * getCurrentQueue
     *
     * @param [type] $qItem
     * @return Object
     */
    protected function getCurrentQueue($qItem)
    {
        if (is_null($this->currentQueue))
        {
           $type = $qItem->imageModel->get('type');
           $queueController = $this->getQueueController(); // @todo This probably will mess with bulk setting. Correct for it.
           $this->currentQueue = $queueController->getQueue($type);
        }

        return $this->currentQueue;
    }

    protected function getQueueController()
    {
       if (is_null($this->queueController))
       {
          $this->queueController = new QueueController(); 
       }

       return $this->queueController; 
    }


    protected function finishItemProcess(QueueItem $qItem)
    {
        $queue = $this->getCurrentQueue($qItem); 
        $fs = \wpSPIO()->filesystem();
        // Can happen with actions outside queue / direct action 
        if ($qItem->getQueueItem() !== false)
        {
          $queue->itemDone($qItem); 
        }
        if (true === $qItem->data()->hasNextAction())
        {
            $action = $qItem->data()->popNextAction(); 
            $item_id = $qItem->item_id; 
            $item_type = $qItem->imageModel->get('type');
            $imageModel = $fs->getImage($item_id, $item_type, false);

            $args = [
              'action' => $action, 
            ];

            
            $keepArgs = $qItem->data()->getKeepDataArgs();
            $args = array_merge($args, $keepArgs);
            

/*            foreach($keepData as $name => $value)
            {
                  // Only arg parsed, take value from this data. 
                  if (is_numeric($name) && property_exists($qItem->data(), $value))
                  {
                     if (false === is_null($qItem->data()->$value))
                     {
                      $args[$value] = $this->$value;                       
                     }
                  }
                  elseif (false === is_null($value))
                  {
                      $args[$name]  = $value; 
                  }
            }
*/
            Log::addInfo("New Action $action with args", $args);


            $queueController = $this->getQueueController(); 
            $result = $queueController->addItemToQueue($imageModel, $args); 
        }

        if (! isset($result))
        {
           $result = $qItem->result(); 
        }

        return $result; 

    }

}
