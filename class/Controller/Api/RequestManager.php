<?php
namespace ShortPixel\Controller\Api;

use ShortPixel\Helper\UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

abstract class RequestManager
{

  protected static $instances;
  protected $apiEndPoint;

  /**
   * 
   * @param QueueItem $item 
   * @param mixed $response 
   * @return object Return must be one of the returnFail / returnSuccess / returnOk functions!
   */
  protected abstract function handleResponse(QueueItem $item, $response);
  public abstract function processMediaItem(QueueItem $item, ImageModel $mediaItem);

  const STATUS_ENQUEUED = 10;
  const STATUS_PARTIAL_SUCCESS = 3;
  const STATUS_SUCCESS = 2;
  const STATUS_WAITING = 1;
  const STATUS_UNCHANGED = 0;
  const STATUS_ERROR = -1;
  const STATUS_FAIL = -2;
  const STATUS_QUOTA_EXCEEDED = -3;
  const STATUS_SKIP = -4;
  const STATUS_NOT_FOUND = -5;
  const STATUS_NO_KEY = -6;
 // const STATUS_RETRY = -7;
 // const STATUS_SEARCHING = -8; // when the Queue is looping over images, but in batch none were   found.
 const STATUS_OPTIMIZED_BIGGER = -9;
 const STATUS_CONVERTED = -10;
 const STATUS_NOT_COMPATIBLE = -11;

  const STATUS_QUEUE_FULL = -404;
  const STATUS_MAINTENANCE = -500;
	const STATUS_CONNECTION_ERROR = -503; // Not official, error connection in WP.
  const STATUS_NOT_API = -1000; // Not an API process, i.e restore / migrate. Don't handle as optimized

	public static function getInstance()
	{
    $calledClass = get_called_class(); 
          if (! isset(static::$instances[$calledClass]))
          {
             static::$instances[$calledClass] = new $calledClass(); 
          }
    
     return self::$instances[$calledClass];
	}


  /** Builds RequestData for wp_remote_get.
    @param Array RequestBody What to send to remote API, the arguments.
    @param Array RequestParams
   */
  protected function getRequest($requestBody = [], $requestParameters = [])
  {
    $settings = \wpSPIO()->settings();

    $requestBody = apply_filters('shortpixel/api/request', $requestBody, $requestBody['item_id']);

    $arguments = array(
        'method' => 'POST',
        'timeout' => 15, // timeout in seconds
        'redirection' => 3, // amount of redirects allowed.
        'sslverify' => apply_filters('shortpixel/system/sslverify', true),
        'httpversion' => '1.0',
        'blocking' => isset($requestParameters['blocking']) ? $requestParameters['blocking'] : true,
        'headers' => isset($requestParameters['headers']) ? $requestParameters['headers'] : [],
        'body' => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
        'cookies' => [], 
    );

    //add this explicitely only for https, otherwise (for http) it slows down the request
    if($settings->httpProto !== 'https') {
        unset($arguments['sslverify']);
    }

    return $arguments;
  }



	/** DoRequest : Does a remote_post to the API
	*
	* @param Object $item  The QueueItemObject
	* @param Array $requestParameters  The HTTP parameters for the remote post (arguments in getRequest)
  * @return void
	*/
  protected function doRequest(QueueItem $qItem, $requestParameters )
	{
		$response = wp_remote_post($this->apiEndPoint, $requestParameters );
    Log::addDebug('ShortPixel API Request sent to ' . $this->apiEndPoint , $requestParameters['body']);
   // Log::addTemp('ShortPixel API Request sent to ' . $this->apiEndPoint , $requestParameters);

		//only if $Blocking is true analyze the response
		if ( $requestParameters['blocking'] )
		{
				if ( is_object($response) && get_class($response) == 'WP_Error' )
				{
						$errorMessage = $response->errors['http_request_failed'][0];
						$errorCode = self::STATUS_CONNECTION_ERROR;
            $is_fatal = false; 

            if (strpos($errorMessage, 'cURL error 28') !== false)
            {
               $errorMessage = __('Timeout fetching data from ShortPixel servers. If persistent, check server connection / whitelist', 'shortpixel-image-optimiser');
            }
            if (strpos($errorMessage, 'cURL error 60') !== false)
            {
               $errorMessage = __('Server error, please contact support ( ' . $errorMessage. ')');
               $is_fatal = true; 

            }
            if (strpos($errorMessage, 'cURL error 6') !== false)
            {
              $errorMessage = __('Host error, please check configuration or contact support ( ' . $errorMessage. ')');
              $is_fatal = true; 
            }

            if (true === $is_fatal)
            {
              $qItem->addResult($this->returnFailure($errorCode, $errorMessage));
            }
            else
            {
              Log::addTemp('ReturnRetry?');
              $qItem->addResult($this->returnRetry($errorCode, $errorMessage));
            }
            
				}
				elseif ( isset($response['response']['code']) && $response['response']['code'] <> 200 )
				{
						$errorMessage = $response['response']['code'] . " - " . $response['response']['message'];
						$errorCode = $response['response']['code'];

            $qItem->addResult($this->returnFailure($errorCode, $errorMessage));
				}
				else
				{
           $resultData = $this->handleResponse($qItem, $response);
           $qItem->addResult($resultData);
				}

		}
		else // This should be only non-blocking the FIRST time it's send off.
		{
       if ($qItem->tries > 0)
			 {
          Log::addWarn('DOREQUEST sent item non-blocking with multiple tries!', $qItem);
			 }

       
       $urls = (! is_null($qItem->data()->urls)) ? count($qItem->data()->urls) : 0;

       if ($urls == 0 && (! is_null($qItem->data()->url)))
        $urls = count($qItem->data()->url);

       $flags = $qItem->data()->flags;
			 $flags = implode("|", $flags);
       $text = sprintf(__('New item #%d sent for processing ( %d URLS %s)  ', 'shortpixel-image-optimiser'), $qItem->item_id, $urls, $flags );

       $qItem->addResult($this->returnOK(self::STATUS_ENQUEUED, $text ));
		}

	}


  protected function returnFailure($status, $message)
  {
  /*      $result = $this->getResultObject();
        $result->apiStatus = $status;
        $result->message = $message;
        $result->is_error = true;
        $result->is_done = true; */

        $result = [
            'apiStatus' => $status,
            'message' => $message,
            'is_error' => true,
            'is_done' => true,
        ];

        return $result;  // fatal.
  }

  // Temporary Error, retry.
  protected function returnRetry($status, $message)
  {

  /*  $result = $this->getResultObject();
    $result->apiStatus = $status;
    $result->message = $message; */

  //  $result->is_error = true;

    $result = [
      'apiStatus' => $status,
      'message' => $message,
      'is_error' => true,
  ];

    return $result;
  }

  protected function returnOK($status = self::STATUS_UNCHANGED, $message = false)
  {
      /* $result = $this->getResultObject();
      $result->apiStatus = $status;
      $result->is_error = false;
      $result->message = $message;
      */
      $result = [
         'apiStatus' => $status,
         'message' => $message
      ];
      return $result;
  }

  /** Returns a success status. This is succeseption, each file gives it's own status, bundled. 

  @param array $data sends the data to be included in the success result.
  @param int $status Status code of the return ( success by default ) 
  @param string $message Message to add to the result. 
  @return Array The result array 
  */
  protected function returnSuccess($data, $status = self::STATUS_SUCCESS, $message = false)
  {

      $result = [
          'apiStatus' => $status,
          'message' => $message,
      ];

      if (self::STATUS_SUCCESS === $status)
        $result['is_done'] = true;

      if ($message == false)
      {
         unset($result['message']);
      }

    /*  if (is_array($file))
        $result['files'] = $file;
      else
        $result['file'] = $file; // this file is being used in imageModel
*/
      $result = array_merge($result, $data);
      return $result;
  }

  protected function parseResponse($response)
  {
    $data = $response['body'];

    $raw_data = $data; 

    $data = json_decode($data);
    if (is_null($data)) // null means failure on return
    {
      /* $data = [
         'status' => self::STATUS_ERROR,
         'error' => json_last_error_msg(),
       ]; */
       $data = $this->getJsonStrings($raw_data);
       $data = (array) json_decode($data[0]);
       return $data;
    }
    return (array)$data;
  }

  // Temporary!  (not sure what temporary means here)
  private function getJsonStrings(string $text): array
  {
      preg_match_all('#\{(?:[^{}]|(?R))*\}#s', $text, $matches);
      $finalValidJson = [];
      foreach ($matches[0] as $match) {
          if (UtilHelper::validateJSON($match)) {
              $finalValidJson[] = $match;
          }
      }

      return $finalValidJson;
  }


} // class RequestManager
