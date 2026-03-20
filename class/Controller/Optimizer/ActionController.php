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

/**
 * Handles non-optimisation image actions such as restore, reoptimize, PNG-to-JPG conversion, and migration.
 *
 * Actions are typically executed directly (not via the async queue) and each one
 * is responsible for marking the queue item as done and setting an appropriate result.
 *
 * @package ShortPixel\Controller\Optimizer
 */
class ActionController extends OptimizerBase
{

   public function __construct()
   {
      parent::__construct();
      $this->apiName = 'action';
   }


  /**
   * Dispatches the queue item to the correct action handler based on its action type.
   *
   * Routes to restoreItem(), reoptimizeItem(), convertPNG(), or migrate() according
   * to the value of the item's action data field.
   *
   * @param QueueItem $item The queue item to process.
   * @return mixed Return value of the dispatched action method, or void if no action matches.
   */
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

  /**
   * Validates the queue item's image model before processing.
   *
   * @param QueueItem $qItem The queue item to validate.
   * @return bool True when the image model exists and is loadable; false otherwise.
   */
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

  /**
   * Handles the API result for an action item after sendToProcessing() has run.
   *
   * Marks the item as failed when an error flag is set, or calls finishItemProcess()
   * when the item is done without error.
   *
   * @param QueueItem $qItem The queue item whose result should be evaluated.
   * @return void
   */
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
   * Enqueues an action item, executing it directly rather than via the async queue.
   *
   * Prepares the queue item for the specified action, runs it synchronously via
   * sendToProcessing(), and then calls handleAPIResult() to finalise the result.
   * Currently only restore and reoptimize prepare the item; png2jpg is handled
   * inline by sendToProcessing().
   *
   * @param QueueItem $qItem The queue item to enqueue and process.
   * @param array     $args  Action arguments; must include 'action' key with the action name.
   * @return void
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
   * Attempts to convert a PNG file to JPG on the local server.
   *
   * Blocks the queue item during conversion, uses the Converter class to perform
   * the local file conversion, then unblocks the item and marks it as done
   * regardless of success. The item will be re-queued for optimisation as a JPG
   * (on success) or continue as PNG (on failure).
   *
   * @param QueueItem $qItem The queue item referencing the PNG image to convert.
   * @return bool True if conversion succeeded; false otherwise.
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

  /**
   * Reoptimizes an image by first restoring it to its original state, then marking
   * it as pending so the optimizer will pick it up again.
   *
   * The filesystem image cache is flushed after a successful restore so the updated
   * file metadata is loaded on the next pass.
   *
   * @param QueueItem $queueItem The queue item referencing the image to reoptimize.
   * @return bool True if the restore succeeded and reoptimization was scheduled; false otherwise.
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

  /**
   * Runs the migration routine on an image model and marks the queue item as done.
   *
   * Migration checks and updates stored metadata without touching the actual image
   * files. The result is always marked as non-API (STATUS_NOT_API) since no API
   * call is made.
   *
   * @param QueueItem $queueItem The queue item referencing the image to migrate.
   * @return mixed Return value of the image model's migrate() method.
   */
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

  /**
   * Restores an image to its pre-optimisation backup.
   *
   * Validates the item, collects response data, and calls the image model's
   * restore() method. When the image was optimised within the last hour the
   * remote API cache is also cleared via dumpMediaItem(). The queue item result
   * is updated with the restored/error file status.
   *
   * @param QueueItem $queueItem The queue item referencing the image to restore.
   * @return bool True if the image was restored successfully; false otherwise.
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
