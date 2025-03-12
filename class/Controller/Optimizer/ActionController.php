<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;

use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Controller\QueueController as QueueController;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Converter\Converter as Converter;

class ActionController extends OptimizerBase
{


  public function sendToProcessing(QueueItem $item)
  {
      switch($item->data()->action)
      {
         case 'restore':
            return $this->restoreItem($item);
         break;
         case 'reoptimize': 
            return $this->reoptimizeItem($item);
         break; 
         case 'png2jpg':
            return $this->convertPNG($item); 
         break;
      }

  }

  public function checkItem(QueueItem $qItem)
  {
      $check = $this->checkImageModel($qItem);
      return $check;
  }

  // @todo See if we need this, more for Apier things
  protected function HandleItemError(QueueItem $item)
  {
    return;
  }
  // Same
  public function handleAPIResult(QueueItem $item)
  {
      return;
  }


  /**
   * EnqueueItem . Enqueues item when needed, actionController is unique in that it has several 'direct' actions that don't require being in a queue.
   *
   * @param QueueItem $qItem
   * @param array $args
   * @return Object
   */
  public function enqueueItem(QueueItem $qItem, $args = [])
  {
      
   $queue = $this->getCurrentQueue($qItem);
   $directAction = true; // By default, execute Actions directly ( not via queue sys )
   
   switch($qItem->data()->action)
   {
       case 'restore':
          //$qItem->newRestoreAction(); // This doesn't do much really.
       break; 
       case 'reoptimize': 
         $qItem->newReOptimizeAction();
      break; 
      case 'png2jpg':
      break; 
   }

    if (true === $directAction)
    {
       // The directActions give back booleans, but the whole function must return an queue result object with qstatus and numitems
       $bool = $this->sendToProcessing($qItem);
       $result = new \stdClass;
       $result->qstatus = ApiController::STATUS_NOT_API;

    }
    else
    {
      $result = $queue->addQueueItem($qItem);
    }

   
    return $result;
  }

  /**
   * Try to convert a PNGfile to JPG. This is done on the local server.  The file should be converted and then re-added to the queue to be processed as a JPG ( if success ) or continue as PNG ( if not success )
   * @param  Object $item                 Queued item
   * @param  Object $mediaQ               Queue object
   * @return boolean Returns success status.
   */
  // @todo Via actions to Optimizers
  protected function convertPNG(QueueItem $qItem)
  {
  //			$item->blocked = true;
    $qItem->block(true);
    $queue = $this->getCurrentQueue($qItem);

   
    $queue->updateItem($qItem);

    $settings = \wpSPIO()->settings();
    $fs = \wpSPIO()->filesystem();

    $imageObj = $qItem->imageModel;

     if ($imageObj === false) // not exist error.
     {
       $qItem->block(false);
       $queue->updateItem($qItem);
     }

      $converter = Converter::getConverter($imageObj, true);
      $bool = false; // init
      if (false === $converter)
      {
         Log::addError('Converter on Convert function returned false ' . $imageObj->get('id'));
         $bool = false;
      }
      elseif ($converter->isConvertable())
      {
        $bool = $converter->convert();
      }

    if ($bool)
    {
       ResponseController::addData($qItem->item_id, 'message', __('PNG2JPG converted', 'shortpixel-image-optimiser'));
    }
    else {
       ResponseController::addData($qItem->item_id, 'message', __('PNG2JPG not converted', 'shortpixel-image-optimiser'));
    }

    // Regardless if it worked or not, requeue the item otherwise it will keep trying to convert due to the flag.
    $imageObj = $fs->getMediaImage($qItem->item_id);

    // Keep compressiontype from object, set in queue, imageModelToQueue
    $imageObj->setMeta('compressionType', $qItem->compressionType);

    $qItem->block(false);
    $queue->updateItem($qItem);

    // Add converted items to the queue for the process
    $queueController = new QueueController(); 
    $queueController->addItemToQueue($imageObj, ['action' => 'optimize'] );
 //   $this->enqueueItem($imageObj);

    return $bool;
  }

  /** Reoptimize an item
  *
  * @param Object $queueItem QueueItem
  * @return bool|Object 
  */
 // @todo This should probably be contained in the newAction in QueueItem ( comrpressiontype / args )
  protected function reoptimizeItem(QueueItem $queueItem)
  {
    
    $item_id = $queueItem->item_id; 
    $item_type = $queueItem->imageModel->get('type');
    $bool = $this->restoreItem($queueItem);

    $compressionType = $queueItem->data()->compressionType; 

    if (true == $bool) // successful restore.
    {
        $fs = \wpSPIO()->filesystem();
        $fs->flushImageCache();

        
        // Hard reload since metadata probably removed / changed but still loaded, which might enqueue wrong files.
        $imageModel = $fs->getImage($item_id, $item_type, false);
          //$imageModel->setMeta('compressionType', $compressionType);

          if ($queueItem->data()->smartcrop)
          {
             $imageModel->doSetting('smartcrop', $queueItem->data()->smartcrop);
          }

          $queueController = new QueueController();
            
  /*        $qItem = QueueItems::getImageItem($imageModel);
          $qItem->newOptimizeAction();
          $qItem->data()->compressionType = $compressionType; 
*/
          $args = ['action' => 'optimize', 'compressionType' => $compressionType];
          
          // This is a user triggered thing. If the whole thing is user excluxed, but one ones this, then ok.
          if (false === $imageModel->isProcessable() && true === $imageModel->isUserExcluded())
          {
            $args['forceExclusion'] = true;
//            $qItem->data()->forceExclusion = true; 
          }
          
          $result = $queueController->addItemToQueue($imageModel, $args);

          return $result;
    }

   return $bool;
   //return $json;

  }

  /** 
   * @return boolean
   */
  protected function restoreItem(QueueItem $queueItem)
  {
      $fs = \wpSPIO()->filesystem();

      $check = $this->checkItem($queueItem);

      if (false === $check)
      {
        return false;
      }

      $imageModel = $queueItem->imageModel;
      $item_id = $imageModel->get('id');

      $data = array(
        'item_type' => $imageModel->get('type'),
        'fileName' => $imageModel->getFileName(),
      );
      ResponseController::addData($item_id, $data);

      $optimized = $imageModel->getMeta('tsOptimized');

      if ($imageModel->isRestorable())
      {
        $result = $imageModel->restore();
      }
      else
      {
         $result = false;
         $queueItem->addResult([
           'message' => ResponseController::formatItem($imageModel->get('id')),
           'is_error' => true,
           'is_done' => true,

         ]); // $mediaItem->getReason('restorable');
      }

      // Compat for ancient WP
      $now = function_exists('wp_date') ? wp_date( 'U', time() ) : time();

      // Reset the whole thing after that.

      // Dump this item from server if optimized in the last hour, since it can still be server-side cached.
      if ( ( $now   - $optimized) < HOUR_IN_SECONDS )
      {
         //$api = $this->getAPI('restore'); // @todo This should also be changed.
         $imageModel = $fs->getImage($item_id, $imageModel->get('type'), false);

         //$item = new \stdClass;
         //$item->urls = $mediaItem->getOptimizeUrls();
         //$qItem = QueueItems::getImageItem($imageModel);

         $queueItem->newDumpAction();

        $api = ApiController::getInstance(); //$queueItem->getAPIController();
         $api->dumpMediaItem($queueItem);
      }

      if (true === $result)
      {
         $queueItem->addResult([
             'message' => __('Item restored', 'shortpixel-image-optimiser'),
             'fileStatus' => ImageModel::FILE_STATUS_RESTORED,
             'is_done' => true,
             'success' => true,
         ]);
      }
      else
      {
         $queueItem->addResult([
            'message' => ResponseController::formatItem($imageModel->get('id')),
            'is_done' => true,
            'is_error' => true,
            'fileStatus' => ImageModel::FILE_STATUS_ERROR,
         ]);
      }

      // no returns here, the result is added to the qItem by reference.
      return $result; // @boolean
      //return $json;
  }

} // class
