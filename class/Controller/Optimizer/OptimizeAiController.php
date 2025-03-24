<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\QueueItem as QueueItem;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;


// Class for AI Operations.  In time split off OptimizeController / Optimize actions to a main queue runner seperately.
class OptimizeAiController extends OptimizerBase
{
   
  public function __construct()
  {
     parent::__construct();
     $this->api = AiController::getInstance();
     $this->apiName = 'ai';
  }
  

  protected function HandleItemError(QueueItem $qItem) { 

      // Change to chance the result / message with specific errors. 
      switch($qItem->result()->apiStatus)
      {
          case '422' :  // Unprocessable Item 
              // No different message than API 
          break; 
  

      }

      
      return;
  }


  public function sendToProcessing(QueueItem $qItem) { 

//    $imageModel = $qItem->imageModel; 

//    $result = $queueController->addItemToQueue($imageModel, $args);

    $this->api->processMediaItem($qItem, $qItem->imageModel);


  }

    
  public function checkItem(QueueItem $qItem) { 
      return true;

  }


  public function enqueueItem(QueueItem $qItem, $args = [])
  {

    $action = $qItem->data()->action; 

    $queue = $this->getCurrentQueue($qItem);
    $directAction = true; 

    switch($action)
    {
        case 'requestAlt': 
            $qItem->requestAltAction();
            
        break;
        case 'retrieveAlt': 
            $qItem->retrieveAltAction($qItem->data()->remote_id);
            $directAction = false; 
        break; 
    }

    if (true === $directAction)
    {
       // The directActions give back booleans, but the whole function must return an queue result object with qstatus and numitems
       $this->sendToProcessing($qItem);
       $this->handleAPIResult($qItem);
      
       $result = new \stdClass; 
       $result->qstatus = Queue::RESULT_ITEMS;
       $result->numitems = 1;
       $qItem->addResult([
        'message' => __('Request for Alt text send to Shortpixel AI', 'shortpixel-image-optimiser')]);

    }
    else
    {
      $result = $queue->addQueueItem($qItem);
    }

    return $result;
  }


  public function handleAPIResult(QueueItem $qItem)
  {
      Log::addTemp('HandleApiResult', $qItem->result());
      $queue = $this->currentQueue;

      $qItem->addResult(['apiName' => $this->apiName]);

      if ($qItem->result()->is_error && true === $qItem->result()->is_done )  {
       
        Log::addDebug('Item failed, has error on done ', $qItem->result());
        $queue->itemFailed($qItem, true);
        $this->HandleItemError($qItem);
        return; 
      }

      // Result for requestAlt 
      if (property_exists($qItem->result(), 'remote_id'))
      {
          $remote_id = $qItem->result()->remote_id;
      }
      else
      {
          if ($qItem->data()->action == 'requestAlt')
          {
              Log::addError('RequestAlt result without remote_id', $qItem->result() );
              $queue->itemFailed($qItem, true);
              $this->HandleItemError($qItem);
              return; 
          }
      }

      // Result for retrieveAlt
      if (property_exists($qItem->result(), 'retrievedText'))
      {
          $text = $qItem->result()->retrievedText; 
          $item_id = $qItem->item_id; 
          $bool = update_post_meta($item_id, '_wp_attachment_image_alt', $text);

         if (false === $bool)
         {
             Log::addWarn('Failed to add alt text to postmeta?' . $item_id, $text);
         }

          $qItem->addResult([
            'apiStatus' => RequestManager::STATUS_SUCCESS,
            'fileStatus' => ImageModel::FILE_STATUS_SUCCESS
          ]);

          $queue->itemDone($qItem);
          return;
      }

      $imageObj = $qItem->imageModel;
      $queueController = new QueueController();

      $queueController->addItemToQueue($imageObj, ['action' => 'retrieveAlt', 'remote_id' => $remote_id]);

  }



}
