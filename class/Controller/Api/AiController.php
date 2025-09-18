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
    protected $auth_token = 'spio_ai_jwt_token';

    const AI_STATUS_INVALID_URL = 2;
    const AI_STATUS_OVERQUOTA = 3; 


    public function __construct()
    {
      $this->main_url = 'https://capi-gpt.shortpixel.com/';
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
        'item_id' => $qItem->item_id,
        'source' => 1, // SPIO
      ];

      if ($qItem->data()->action == 'requestAlt')
      {
        $requestBody['url'] = $qItem->data()->url;
        $paramlist = $qItem->data()->paramlist; 
        if (is_object($paramlist))
        {
          $paramlist = (array) $paramlist; 
        }
        if (! is_array($paramlist)) // not serious paramlist then
        {
           $paramlist = []; 
        }

        $requestBody = array_merge($requestBody, $paramlist);
        $requestBody['retry'] = '1'; // when requesting alt, always wants a new one (?) 
      }

      if ($qItem->data()->action == 'retrieveAlt')
      {
        $requestBody['id'] = $qItem->data()->remote_id;
      }

      
      $token = get_transient($this->auth_token);
      // Token doesn't seem to work normally.
      /*if ($token !== false)
      {
         $auth = $token; 
      }
      else
      { */
        $auth = 'ApiKey ' . $keyControl->forceGetApiKey();
     // }


      // Should always check the results
      $requestParameters = [
        'blocking' => true,
        'headers' => [
            'Authorization' => $auth,  
            'Content-Type' => 'application/json',
        ]
      ];

      $request = $this->getRequest($requestBody, $requestParameters);
      $this->doRequest($qItem, $request);

    }

    // Should return something that's usefull to set as response on the item.
    protected function handleResponse(QueueItem $qItem, $response)
    {
       $apiData = $this->parseResponse($response);//get the actual response from API, its an array
   //    Log::addTemp('Response AI ', $response);
       Log::addTemp('HAndle AI Response! ', $apiData);

       
        // List all the random crap that might return. 
        $id = isset($apiData['id']) ? intval($apiData['id']) : false; 
        $jwt = isset($apiData['jwt']) ? sanitize_text_field($apiData['jwt']) : false; 
        $status = isset($apiData['status']) ? intval($apiData['status']) : false;
        
        $error = isset($apiData['error']) ? sanitize_text_field($apiData['error']) : false; 
        $is_error = (false !== $error) ? true : false; 

        if (false !== $jwt)
        {
          $authKey = get_transient($this->auth_token);
          if (false === $authKey || $jwt !== $authKey)
          {
             set_transient($this->auth_token, $jwt, HOUR_IN_SECONDS);
             Log::addTemp('Setting auth key trans');
          }

        }

        // @todo This is probably not something that would happen, since repsonse is from the body. Implement here most error coming from the raw request and returnOk/returnFalse etc.

        //if (true === $qItem->result()->is_error && true === $qItem->result()->is_done )
       // {
              /*   So far nothing needed here, documenting what has been seen.
              401 - Unauthorized
              422 - Unprocessable
                */

      
        // API seems to return two different formats : 
        // 1.  requestAlt : Object in data, with ID as only return. 
        // 2.  retrieveAlt: Array with first item ( zero index ) 
        
        if (false === $apiData)
        {
            return $this->returnRetry(RequestManager::STATUS_CONNECTION_ERROR, __('AI Api returned without any data. ', 'shortpixel-image-optimiser')) ;
        }

        if ($qItem->data()->action == 'requestAlt')
        {
            if (false === $id)
            {
               return $this->returnRetry(RequestManager::STATUS_WAITING, __('Response without result object', 'shortpixel-image-optimiser'));
            }
            

            
            if (false !== $id)
            {
              $remote_id = intval($id);
              $qItem->addResult(['remote_id' => $remote_id]);
              
              return $this->returnSuccess(['remote_id' => $remote_id], RequestManager::STATUS_SUCCESS, __('Request for image SEO data sent to ShortPixel AI', 'shortpixel-image-optimiser'));  
            }
            elseif(self::AI_STATUS_OVERQUOTA === $status)
            {
               return $this->returnFailure(RequestManager::STATUS_ERROR, sprintf(esc_html__('Your AI quota for this month has been exceeded. We would love to hear your feedback — please share it with us %shere%s.', 'shortpixel-image-optimiser'), '<a href="https://shortpixel.com/contact" target="_blank">', '</a>'));
            }
            elseif(self::AI_STATUS_INVALID_URL === $status)
            {
                return $this->returnFailure(RequestManager::STATUS_FAIL, __('No URL or Invalid URL', 'shortpixel-image-optimiser'));
            }
            else
            {
               return $this->returnFailure(RequestManager::STATUS_ERROR, $error);
            }

        }

        if ($qItem->data()->action == 'retrieveAlt')
        {
              $aiData = array_filter([
                 'filename' => isset($apiData['file_name']) ? sanitize_text_field($apiData['file_name']) : null,
                 'alt' => isset($apiData['alt']) ? sanitize_text_field($apiData['alt']) : null, 
                 'caption' => isset($apiData['caption']) ? sanitize_text_field($apiData['caption']) : null, 
                 'relevance' => isset($apiData['relevance']) ? sanitize_text_field($apiData['relevance']) : null, 
                 'description' => isset($apiData['image_description']) ? sanitize_text_field($apiData['image_description']) : null,
              ]);

              
              
              // Switch known Statii 
              switch ($status)
              {
                  case '-1':  // Error of some kind 
                    $apiStatus = RequestManager::STATUS_FAIL; 
                    return $this->returnFailure($apiStatus, $error); 
                  break; 
                  case '0': // queued
                      if (false !== $is_error)
                      {
                         return $this->returnFailure(RequestManager::STATUS_FAIL, $error);
                      }
                  case '1':
                 
                     return $this->returnOk(RequestManager::STATUS_WAITING, __('Waiting for result', 'shortpixel-image-optimiser'));
                  break; 
                  case '2':  // Success of some kind. 
                  default: 
                    //$apiStatus = RequestManager::STATUS_SUCCESS; 
                    // @todo Possibly add a fail state here if all AI stuff came back negative / without data (?) 
                    /*      if (is_null($text) || strlen($text) == 0 || $error !== false)
                    {
                        $apiStatus = RequestManager::STATUS_FAIL; 
                        return $this->returnFailure(RequestManager::STATUS_FAIL, __('AI could not generate text for this image', 'shortpixel-image-optimiser'));
                    }
                    else
                    {
              */
                      $successData = $this->handleSuccess($aiData, $qItem);
                      return $successData;
               //     }

                  break;
            //  }
                             
               
            }
        }
      return $this->returnFailure(0, 'No remote ID?');
    }

    /**
     * Undocumented function
     *
     * @param array $aiData
     * @param object $qItem
     * @return array Result array via requestManager 
     */
    protected function handleSuccess($aiData, QueueItem $qItem)
    {
      if (false === is_null($qItem->data()->returndatalist))
      {
         $returndatalist = $qItem->data()->returndatalist; 
         if (is_object($returndatalist))
         {
           $returndatalist = (array) $returndatalist; 
         }

         foreach($returndatalist as $name => $data)
         {
            if (is_object($data)) // annoying conversion somehow by json decode from record
            {
               $data = (array) $data; 
            }
            if (! isset($aiData[$name]) && isset($data['status']))
            { 
                $aiData[$name]  = $data['status']; 
            }
         }
      }
      
      return $this->returnSuccess(['aiData' => $aiData], RequestManager::STATUS_SUCCESS, __('Retrieved AI image SEO data', 'shortpixel-image-optimiser')); ;
    }

    protected function doRequest(QueueItem $item, $requestParameters)
    {
        // For now
        if (false === property_exists($item->data, 'remote_id') || is_null($item->data()->remote_id))
        {
           $this->apiEndPoint = $this->main_url . 'add-url.php';
        }
        else {
          $this->apiEndPoint = $this->main_url . 'get-url.php';
        }

        return parent::doRequest($item, $requestParameters);

    }

    protected function returnFailure($code, $message)
    {
       if (401 == $code)
       {
          $token = get_transient($this->auth_token);
          if ($token !== false)
          {
             delete_transient($this->auth_token);
             return $this->returnRetry($code, __('Authentication token failure - Reset - Please wait', 'shortpixel-image-optimiser'));
          }
       }
       return parent::returnFailure($code, $message);
    }


}
