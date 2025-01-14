<?php
namespace ShortPixel\Controller\Api;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

abstract class RequestManager
{

  protected static $instance;
  protected $apiEndPoint;

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

  const STATUS_QUEUE_FULL = -404;
  const STATUS_MAINTENANCE = -500;
	const STATUS_CONNECTION_ERROR = -503; // Not official, error connection in WP.
  const STATUS_NOT_API = -1000; // Not an API process, i.e restore / migrate. Don't handle as optimized

	public static function getInstance()
	{
		 if (is_null(self::$instance))
			 self::$instance = new static();

			return self::$instance;
	}

  public function processItem(QueueItem $item)
  {

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
        'headers' => array(),
        'body' => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
        'cookies' => array()
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
	*/
  protected function doRequest(QueueItem $item, $requestParameters )
	{
		$response = wp_remote_post($this->apiEndPoint, $requestParameters );
    Log::addDebug('ShortPixel API Request sent to ' . $this->apiEndPoint , $requestParameters['body']);

		//only if $Blocking is true analyze the response
		if ( $requestParameters['blocking'] )
		{
				if ( is_object($response) && get_class($response) == 'WP_Error' )
				{
						$errorMessage = $response->errors['http_request_failed'][0];
						$errorCode = self::STATUS_CONNECTION_ERROR;
            $item->setResult($this->returnRetry($errorCode, $errorMessage));
				}
				elseif ( isset($response['response']['code']) && $response['response']['code'] <> 200 )
				{
						$errorMessage = $response['response']['code'] . " - " . $response['response']['message'];
						$errorCode = $response['response']['code'];
            $item->setResult($this->returnFailure($errorCode, $errorMessage));
				}
				else
				{
           $item->setResult($this->handleResponse($item, $response));
				}

		}
		else // This should be only non-blocking the FIRST time it's send off.
		{
			 if ($item->tries > 0)
			 {
					Log::addWarn('DOREQUEST sent item non-blocking with multiple tries!', $item);
			 }

			 $urls = (property_exists($item->data(), 'urls')) ? count($item->data()->urls) : 0;

			 if ($urls == 0 && property_exists($item->data(), 'url'))
				$urls = count($item->data()->url);

			 $flags = property_exists($item->data(), 'flags') ? $item->data()->flags : [];
			 $flags = implode("|", $flags);
			 $text = sprintf(__('New item #%d sent for processing ( %d URLS %s)  ', 'shortpixel-image-optimiser'), $item->item_id, $urls, $flags );

       $item->setResult($this->returnOK(self::STATUS_ENQUEUED, $text ));
		}

		return $item;
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

  /** Returns a success status. This is succeseption, each file gives it's own status, bundled. */
  protected function returnSuccess($file, $status = self::STATUS_SUCCESS, $message = false)
  {
  /*    $result = $this->getResultObject();
      $result->apiStatus = $status;
      $result->message = $message;
  */
      $result = [
          'apiStatus' => $status,
          'message' => $message,
      ];



      if (self::STATUS_SUCCESS === $status)
        $result['is_done'] = true;

      if (is_array($file))
        $result['files'] = $file;
      else
        $result['file'] = $file; // this file is being used in imageModel

      return $result;
  }


} // class RequestManager
