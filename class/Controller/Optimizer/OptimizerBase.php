<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\QueueItem as QueueItem;
use Shortpixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\QueueController;
use ShortPixel\Model\Image\ImageModel as ImageModel;


abstract class OptimizerBase
{

    protected $api;

    protected $response; // json response lives here.
    protected $currentQueue;  // trying to keep minimum, but optimize needs to speak to queue for items.

    //public abstract function getQueueItem();
    public abstract function enqueueItem(QueueItem $item);
    public abstract function handleAPIResult(QueueItem $item);
    protected abstract function HandleItemError(QueueItem $item);
    public abstract function sendToProcessing(QueueItem $item);
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

    public function setCurrentQueue($queue)
    {
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
           $queueController = new QueueController();
           $this->currentQueue = $queueController->getQueue($type);
        }

        return $this->currentQueue;
    }

}
