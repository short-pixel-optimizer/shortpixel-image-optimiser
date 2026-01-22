<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;

use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Converter\Converter as Converter;

class ActionController extends OptimizerBase
{

   public function __construct()
   {
      parent::__construct();
      $this->apiName = 'action';
   }


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
         case 'migrate': 
            return $this->migrate($item);
         break;
         /*case 'remove_background': 
            return $this->removeBackground($item);
         break;  */
      }

  }

  public function checkItem(QueueItem $qItem)
  {
      $check = $this->checkImageModel($qItem); // Does check if Image exist with ID. 
      return $check;
  }

  // @todo See if we need this, more for Apier things
  protected function HandleItemError(QueueItem $qItem)
  {
    return;
  }
  // Same
  public function handleAPIResult(QueueItem $qItem)
  {
      $q = $this->getCurrentQueue($qItem);

     if ($qItem->result()->is_error) {
      Log::addDebug('Item failed, has error ', $qItem->result());
      $q->itemFailed($qItem, true);
      $this->HandleItemError($qItem);
     } 
     elseif (true === $qItem->result()->is_done) 
      {
         $this->finishItemProcess($qItem);
      }
    

     // return;

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
   
   switch($args['action'])
   {
       case 'restore':
          $qItem->newRestoreAction(); // This doesn't do much really.
       break; 
       case 'reoptimize': 
         $qItem->newReOptimizeAction($args);
      break; 
      case 'png2jpg':
      break; 
   }

    if (true === $directAction)
    {
       // The directActions give back booleans, but the whole function must return an queue result object with qstatus and numitems
       $process_result = $this->sendToProcessing($qItem);

    //   $result = new \stdClass;
     //  $result->qstatus = RequestManager::STATUS_NOT_API;

      // The assumption here that will work always because of requeue in reOptimizeItem, should not respond with NO_API response, but with continue process 
/*      if (is_object($process_result))
      {
         $result->qstatus = Queue::RESULT_EMPTY;
         $result->numitems = 1;
      } */

      $this->handleAPIResult($qItem);  
    }
    /*else
    {
      $result = $queue->addQueueItem($qItem);
    } */

    //return $result;
  }

  /**
   * Try to convert a PNGfile to JPG. This is done on the local server.  The file should be converted and then re-added to the queue to be processed as a JPG ( if success ) or continue as PNG ( if not success )
   * @param  Object $item                 Queued item
   * @return boolean Returns success status.
   */
  // @todo Via actions to Optimizers
  protected function convertPNG(QueueItem $qItem)
  {
    $qItem->block(true);
    $queue = $this->getCurrentQueue($qItem);

    $queue->updateItem($qItem);

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

    $qItem->block(false);
   // $this->finishItemProcess($qItem);

    $qItem->addResult([
      'is_done' => true, 
      'message' => __('Image converted', 'shortpixel-image-optimiser'), 
   ]);

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

    $bool = $this->restoreItem($queueItem);

    if (true == $bool) // successful restore.
    {
        $fs = \wpSPIO()->filesystem();
        $fs->flushImageCache();

        // Mark Item ( for results ) as ongoing and such
        $queueItem->addResult([
            'fileStatus' => ImageModel::FILE_STATUS_PENDING, 
            'is_done' => true, 
            'message' => __('Image being reoptimized', 'shortpixel-image-optimiser'), 
        ]);

         // $result = $this->finishItemProcess($queueItem);

          return true;
    }
    else
    {
      Log::addError('Restore Item returned false!');
    }

   return $bool;

  }

  protected function migrate(QueueItem $queueItem)
  {
       $imageModel = $queueItem->imageModel;

       $result = $imageModel->migrate();

       $queueItem->addResult([
         'is_done' => true, 
         'is_error' => false,
         'message' => __('Item migrated / checked ', 'shortpixel-image-optimiser'), 
         'apiStatus' => ApiController::STATUS_NOT_API,
     ]);

       return $result;
  }

  /** Handle Restore Item 
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
           'message' => ResponseController::formatQItem($queueItem),
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
            'message' => ResponseController::formatQItem($queueItem),
            'is_done' => true,
            'is_error' => true,
            'fileStatus' => ImageModel::FILE_STATUS_ERROR,
         ]);
      }

      // no returns here, the result is added to the qItem by reference.
      return $result; // @boolean
      
  }

} // class
