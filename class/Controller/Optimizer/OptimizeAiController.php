<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\QueueItem as QueueItem;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;


// Class for AI Operations.  In time split off OptimizeController / Optimize actions to a main queue runner seperately.
class OptimizeAiController extends OptimizerBase
{


  public function enQueueItem(QueueItem $queueItem, $args = [])
  {

      $defaults = array(
        'forceExclusion' => false,
        'action' => 'requestAlt',
        'remote_id' => null,
      );
      $args = wp_parse_args($args, $defaults);

     // Also can be done better at some point.
     $json = $this->getJsonResponse();
     $json->status = 0;
     $json->result = new \stdClass;

     $queue = $this->getQueue($mediaItem->get('type'));

    $result = $queue->addSingleItem($mediaItem, $args);

    if ($result->numitems > 0)
    {
      $json->result->message = sprintf(__('Item %s added to Queue. %d items in Queue', 'shortpixel-image-optimiser'), $mediaItem->getFileName(), $result->numitems);
      $json->status = 1;
      $json->qstatus = $result->qstatus;
      $json->result->fileStatus = ImageModel::FILE_STATUS_PENDING;
      $json->result->is_error = false;
      $json->message = __('Alt Request in progress');

      // Check if background process is active / this needs activating.
      $cronController = CronController::getInstance();
      $cronController->checkNewJobs();
    }

    return $json;

  }


  protected function handleAPIResult(QueueItem $item, $q)
  {
      Log::addTemp('HandleApiResult', $item);
      if (property_exists($item->result(), 'remote_id'))
      {
          $remote_id = $item->result()->remote_id;
      }

      $imageObj = $item->get('imageModel');
      $this->addItemToQueue($imageObj, ['action' => 'retrieveAlt', 'remote_id' => $remote_id]);


  }


}
