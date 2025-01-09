<?php
namespace ShortPixel\Controller\Api;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;

use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use \ShortPixel\Model\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


class AiController extends RequestManager
{

    protected $main_url;

  // @todo This API probably needs it's own queue to prevent duplications and so on.
  // @todo WP-Cli Implementation of this API  to check how things might go.
  // @todo(3)  Perhaps no queue implementation. Just find a queueItem and send it off directly?
  // @todo(4) Perhaps result object / handling should also be offloaded to QueueItem since it's this part.
  // @todo(5) Figure out how to get ApiController Item to QUeueItem object and sync with the getRequest stuff.
  //  ^^ Key for that lies in queueToMediaItem in Queue.php
    public function __construct()
    {
      $this->main_url = 'https://capi.shortpixel.com/';
    }

    public function processMediaItem(QueueItem $item, ImageModel $imageObj)
    {
      if (! is_object($imageObj))
      {
        $item->result = $this->returnFailure(self::STATUS_FAIL, __('Item seems invalid, removed or corrupted.', 'shortpixel-image-optimiser'));
        return $item;
      }

    //  var_dump($item);

      Log::addTemp('AiContrll request', $item);
      return $this->processItem($item);

    }

    public function processItem(QueueItem $item)
    {
      $keyControl = ApiKeyController::getInstance();

      //$request = $this->getRequest($requestArgs);
      $requestBody = [
        'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
        'key' => $keyControl->forceGetApiKey(),
        'url' => $item->url,
        'item_id' => $item->item_id,
        'source' => 1, // SPIO

      ];

      // Should always check the results
      $requestParameters = [
        'blocking' => true,
      ];

      $request = $this->getRequest($requestBody, $requestParameters);
      $item = $this->doRequest($item, $request);

      return $item;
    }


    protected function handleResponse(QueueItem $item, $response)
    {
       Log::addTemp('HAndle AI Response! ', $response);
       if (! is_null($item->result))
       {
          if (true === $item->result->is_error && true === $item->result->is_done )
          {
                /*   So far nothing needed here, documenting what has been seen.
                401 - Unauthorized
                422 - Unprocessable
                 */
              return $item;
          }
       }
       if (false === $item->result->is_done)
       {
          $item->setData('remote_id', $remote_id);
       }

    }

    protected function doRequest(QueueItem $item, $requestParameters)
    {
        // For now
        if (is_null($item->remote_id))
        {
           $this->apiEndPoint = $this->main_url . 'api/add-url';
        }
        else {
          $this->apiEndPoint = $this->main_url . 'api/get-url';
        }

        return parent::doRequest($item, $requestParameters);

    }


}
