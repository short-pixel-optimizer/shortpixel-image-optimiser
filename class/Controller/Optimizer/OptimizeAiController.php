<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use ShortPixel\ViewController as ViewController;


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

      $qItem->addResult(['qstatus' => Queue::RESULT_ERROR]);
      return;
  }


  public function sendToProcessing(QueueItem $qItem) { 

    $this->api->processMediaItem($qItem, $qItem->imageModel);

  }

    
  public function checkItem(QueueItem $qItem) { 
      return true;

  }


  public function enqueueItem(QueueItem $qItem, $args = [])
  {

    $action = $args['action']; // $qItem->data()->action; 

    $queue = $this->getCurrentQueue($qItem);
    $directAction = true; 

    switch($action)
    {
        case 'requestAlt': 
            $qItem->requestAltAction();
            
        break;
        case 'retrieveAlt': 
            $qItem->retrieveAltAction($args['remote_id']);
            $directAction = false; 
        break; 
    }


    if (true === $directAction)
    {
       // The directActions give back booleans, but the whole function must return an queue result object with qstatus and numitems
       $this->sendToProcessing($qItem);
       $this->handleAPIResult($qItem);
      
       $result = $qItem->result();
 
        // Probably not as is should be, but functional
       if ($result->is_error === false)
       {
            $result = new \stdClass; 
            $result->qstatus = Queue::RESULT_ITEMS;
            $result->numitems = 1;
            $qItem->addResult([
            'message' => __('Request for Alt text send to Shortpixel AI', 'shortpixel-image-optimiser')]);
        }

    }
    else
    {
      $result = $queue->addQueueItem($qItem);
    }

    return $result;
  }


  public function handleAPIResult(QueueItem $qItem)
  {
      $queue = $this->currentQueue;

      $qItem->addResult(['apiName' => $this->apiName]);

      if ($qItem->result()->is_error && true === $qItem->result()->is_done )  {
       
        Log::addDebug('Item failed, has error on done ', $qItem->result());
        $queue->itemFailed($qItem, true);
        $this->HandleItemError($qItem);
        return; 
      }

      // Result for requestAlt 
      $apiStatus = $qItem->result()->apiStatus; 

      if ($apiStatus == RequestManager::STATUS_WAITING)
      {
        return; 
      }
      elseif (property_exists($qItem->result(), 'remote_id'))
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

          $current_alt = get_post_meta($item_id, '_wp_attachment_image_alt', true);

          $ai_metadata = get_post_meta($item_id, 'shortpixel_alt_requests', true); 

          if (false === is_array($ai_metadata))
          {
            $ai_metadata = []; 
          }

         $ai_metadata['original_alt'] = $current_alt;
            
          $ai_metadata['result_alt'] = $text;

          $bool = update_post_meta($item_id, 'shortpixel_alt_requests', $ai_metadata); 
          
          if (false === $bool)
          {
              Log::addWarn('Save alt requests failed? - ' . $item_id, $ai_metadata);
          }
          
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
      $queueController = $this->getQueueController();
      $queueController->addItemToQueue($imageObj, ['action' => 'retrieveAlt', 'remote_id' => $remote_id]);

  }

  public function undoAltData(QueueItem $qItem)
  {
       $altData = $this->getAltData($qItem);
       $item_id = $qItem->item_id;
    
       $bool = update_post_meta($item_id, '_wp_attachment_image_alt', $altData['original_alt']);

       if (true === $bool)
       {
          $bool = delete_post_meta($item_id, 'shortpixel_alt_requests');
       }

       if (false === $bool)
       {
          Log::addError('Undo post meta removal failed?');
       }

       return $this->getAltData($qItem); 
  }

public function getAltData(QueueItem $qItem)
{
    $item_id = $qItem->item_id; 
    $metadata = get_post_meta($item_id, 'shortpixel_alt_requests', true);
    $current_alt = get_post_meta($item_id, '_wp_attachment_image_alt', true);

    if (false === is_array($metadata))
    {
         $metadata = [
            'original_alt' => $current_alt, 
            'result_alt' => false, 
            'snippet' => false, 
         ];
    }

    // Check for changes
    if ($metadata['result_alt'] !== false && $metadata['original_alt'] !== false)
    {
        // If both result / original are not the current, this indicates that the current alt has been manually changed and should replace our original alt. 
        if ($metadata['result_alt'] !== $current_alt && $metadata['original_alt'] !== $current_alt)
        {
            $metadata['original_alt'] = $current_alt; 
            $bool = update_post_meta($item_id, 'shortpixel_alt_requests', $metadata); 

        }

    }


    $image_url = $qItem->imageModel->getUrl();

    // Check if it's our data. 
    $has_data = ($metadata['original_alt'] !== false && $metadata['result_alt'] !== false) ? true : false; 
    if ($current_alt !== $metadata['result_alt'])
    {
         $has_data = false; 
    }


    $view = new ViewController();
    $view->addData([
            'item_id' => $item_id, 
            'orginal_alt' => $metadata['original_alt'], 
            'result_alt' => $metadata['result_alt'], 
            'has_data' => $has_data, 
            'image_url' => $image_url, 
            'current_alt' => $current_alt, 
        ]);

    $metadata['snippet'] = $view->returnView('snippets/part-aitext');

    $metadata['action'] = $qItem->data()->action;
    $metadata['item_id'] = $item_id;

    return $metadata; 
}



} // class 
