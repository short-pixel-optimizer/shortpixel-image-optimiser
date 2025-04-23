<?php
namespace ShortPixel\Controller\Api;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;

use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use \ShortPixel\Model\Queue\QueueItem as QueueItem;
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

    public function processMediaItem(QueueItem $qItem, ImageModel $imageObj)
    {
      if (! is_object($imageObj))
      {
        $qItem->addResult($this->returnFailure(self::STATUS_FAIL, __('Item seems invalid, removed or corrupted.', 'shortpixel-image-optimiser')));
        return;
      }

      $keyControl = ApiKeyController::getInstance();

      //$request = $this->getRequest($requestArgs);
      $requestBody = [
        'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
        'key' => $keyControl->forceGetApiKey(),

        'item_id' => $qItem->item_id,
        'source' => 1, // SPIO
      ];

      if ($qItem->data()->action == 'requestAlt')
      {
        $requestBody['url'] = $qItem->data()->url;
        $requestBody['retry'] = '1'; // when requesting alt, always wants a new one (?) 
      }

      if ($qItem->data()->action == 'retrieveAlt')
      {
        $requestBody['Id'] = $qItem->data()->remote_id;
      }

      // Should always check the results
      $requestParameters = [
        'blocking' => true,
      ];

      Log::addTemp('RequestBody', $requestBody);
      $request = $this->getRequest($requestBody, $requestParameters);
      $this->doRequest($qItem, $request);

    }

    // Should return something that's usefull to set as response on the item.
    protected function handleResponse(QueueItem $qItem, $response)
    {
       $APIresponse = $this->parseResponse($response);//get the actual response from API, its an array
       Log::addTemp('HAndle AI Response! ', $APIresponse);

        // @todo This is probably not something that would happen, since repsonse is from the body. Implement here most error coming from the raw request and returnOk/returnFalse etc.

        //if (true === $qItem->result()->is_error && true === $qItem->result()->is_done )
       // {
              /*   So far nothing needed here, documenting what has been seen.
              401 - Unauthorized
              422 - Unprocessable
                */
            
        /*    $message = __('Ai Failure', 'shortpixel-image-optimiser'); 
            Log::addError('AI API RESULT: ', $APIresponse);
            $qItem->addResult($this->returnFailure(static::STATUS_FAIL, $message));
        }  */
      
        // API seems to return two different formats : 
        // 1.  requestAlt : Object in data, with ID as only return. 
        // 2.  retrieveAlt: Array with first item ( zero index ) 
        
        $apiData = (is_array($APIresponse) && isset($APIresponse['data'])) ? $APIresponse['data'] : false; 

        if (false === $apiData)
        {
            return $this->returnRetry(RequestManager::STATUS_CONNECTION_ERROR, __('AI Api returned without any data. ', 'shortpixel-image-optimiser')) ;
        }

        if ($qItem->data()->action == 'requestAlt')
        {
             if (is_object($apiData) && property_exists($apiData, 'Id'))
             {
              $remote_id = intval($APIresponse['data']->Id);
              $qItem->addResult(['remote_id' => $remote_id]);
              return $this->returnOk();  
             }

        }

        if ($qItem->data()->action == 'retrieveAlt')
        {
            if (is_array($apiData))
            {
              $result = $apiData[0]; 
              $text = property_exists($result, 'Result') ? sanitize_text_field($result->Result) : null;
              $status = property_exists($result, 'Status') ? intval($result->Status) : -1; 

              
              $text = $this->filterResultText($text); 
              
              // Switch known Statii 
              switch ($status)
              {
                  case '-1':  // Error of some kind 
                    $status = RequestManager::STATUS_FAIL; 
                    $message = property_exists($result, 'Error') ? sanitize_text_field($result->Error) : __('Unknown Ai Api Error occured', 'shortpixel-image-optimiser'); 
                    return $this->returnFailure($status, $message); 
                  break; 
                  case '3':  // Success of some kind. 
                  default: 
                    $status = RequestManager::STATUS_SUCCESS; 
                    if (is_null($text) || strlen($text) == 0)
                    {
                        $status = RequestManager::STATUS_FAIL; 
                        return $this->returnFailure(RequestManager::STATUS_FAIL, __('AI could not generate text for this image', 'shortpixel-image-optimiser'));
                    }
                    else
                    {
                    //$qItem->addResult(['retrievedText' => $text]); 
                    return $this->handleSuccess($text, $qItem);
                    
                    //  return $this->returnSuccess(['retrievedText' => $text], RequestManager::STATUS_SUCCESS, __('Retrieved AI Alt Text', 'shortpixel-image-optimiser'));
                    }

                  break;
              }
                             
               
            }
        }
      return $this->returnFailure(0, 'No remote ID?');
    }

    /**
     * Undocumented function
     *
     * @param string $text
     * @param object $qItem
     * @return array Result array via requestManager 
     */
    protected function handleSuccess($text, QueueItem $qItem)
    {
      $qItem->addResult(['retrievedText' => $text]); 
      return $this->returnSuccess(['retrievedText' => $text], RequestManager::STATUS_SUCCESS, __('Retrieved AI Alt Text', 'shortpixel-image-optimiser')); ; 
    }

    protected function doRequest(QueueItem $item, $requestParameters)
    {
        // For now
        if (false === property_exists($item->data, 'remote_id') || is_null($item->data()->remote_id))
        {
           $this->apiEndPoint = $this->main_url . 'api/add-url';
        }
        else {
          $this->apiEndPoint = $this->main_url . 'api/get-url';
        }

        return parent::doRequest($item, $requestParameters);

    }

    /**
     * Simple function to check / process the result text.  I.e by default it's without capitals. 
     *
     * @param [string] $text
     * @return string
     */
    protected function filterResultText($text)
    {
        $text = ucfirst($text);
        return $text; 
       
    }


}
