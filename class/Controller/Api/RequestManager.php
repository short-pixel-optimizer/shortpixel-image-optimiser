<?php
namespace ShortPixel\Controller\Api;

use ShortPixel\Helper\UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Model\Image\ImageModel as ImageModel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Abstract base class for all ShortPixel API communication controllers.
 *
 * Provides shared infrastructure for building, sending, and interpreting HTTP
 * requests to ShortPixel API endpoints. Concrete subclasses implement the
 * handleResponse() and processMediaItem() methods for their specific API flavour.
 *
 * @package ShortPixel\Controller\Api
 */
abstract class RequestManager
{

  /** @var static[] Singleton instances indexed by called class name. */
  protected static $instances;

  /** @var string Full URL of the API endpoint to post requests to. */
  protected $apiEndPoint;

  /**
   * Processes and interprets a raw API response for the given queue item.
   *
   * Must be implemented by each concrete subclass. The return value must be
   * one of the returnFailure / returnSuccess / returnOk result arrays.
   *
   * @param QueueItem $qItem    The queue item being processed.
   * @param mixed     $response The raw HTTP response from wp_remote_post().
   * @return array Result array produced by one of the return* helper methods.
   */
  protected abstract function handleResponse(QueueItem $qItem, $response);

  /**
   * Builds the API request body and dispatches it for the given queue item.
   *
   * @param QueueItem $qItem The queue item to send to the API.
   * @return void
   */
  public abstract function processMediaItem(QueueItem $qItem);

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

  /**
   * Returns the singleton instance of the called (sub)class.
   *
   * Uses late static binding so each concrete subclass gets its own instance.
   *
   * @return static
   */
	public static function getInstance()
	{
    $calledClass = get_called_class();
          if (! isset(static::$instances[$calledClass]))
          {
             static::$instances[$calledClass] = new $calledClass();
          }

     return self::$instances[$calledClass];
	}


  /**
   * Builds the wp_remote_post() argument array for a ShortPixel API request.
   *
   * Merges the provided request body and parameter overrides with sensible
   * defaults (timeout, SSL verify, JSON encoding, etc.). The SSL-verify argument
   * is omitted entirely for plain HTTP to avoid unnecessary overhead.
   *
   * @param array $requestBody       Associative array of API payload fields (e.g. key, urllist).
   * @param array $requestParameters Optional overrides: 'blocking' (bool) and 'headers' (array).
   * @return array Argument array ready to pass to wp_remote_post().
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


  /**
   * Performs a wp_remote_post() to the configured API endpoint and stores the result on the queue item.
   *
   * When the request is blocking, WP_Error objects and non-200 HTTP codes are mapped to
   * failure/retry results; successful responses are passed to handleResponse(). Non-blocking
   * (first-send) requests are immediately marked as enqueued without waiting for a response.
   *
   * @param QueueItem $qItem             The queue item that will receive the result via addResult().
   * @param array     $requestParameters HTTP argument array produced by getRequest().
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
        $urls = 1;

       $flags = $qItem->data()->flags;
			 $flags = implode("|", $flags);
       $text = sprintf(__('New item #%d sent for processing ( %d URLS %s)  ', 'shortpixel-image-optimiser'), $qItem->item_id, $urls, $flags );

       $qItem->addResult($this->returnOK(self::STATUS_ENQUEUED, $text ));
		}

	}


  /**
   * Returns a terminal-failure result array (is_error=true, is_done=true).
   *
   * Use this when the error is permanent and the item should not be retried.
   *
   * @param int|string $status  API or HTTP status code describing the error.
   * @param string     $message Human-readable error message.
   * @return array Result array with is_error and is_done both set to true.
   */
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

  /**
   * Returns a temporary-error result array (is_error=true, is_done=false).
   *
   * Use this when the error is transient and the item should be retried later.
   *
   * @param int|string $status  API or HTTP status code describing the transient error.
   * @param string     $message Human-readable error message.
   * @return array Result array with is_error=true and is_done=false.
   */
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
      'is_done' => false,
  ];

    return $result;
  }

  /**
   * Returns a non-error, non-done status result (e.g. still processing or enqueued).
   *
   * Use this for intermediate states such as STATUS_UNCHANGED or STATUS_ENQUEUED
   * where no error has occurred but the item is not yet finished.
   *
   * @param int    $status  One of the STATUS_* constants (defaults to STATUS_UNCHANGED).
   * @param string|false $message Optional human-readable status message.
   * @return array Result array with is_error=false and is_done=false.
   */
  protected function returnOK($status = self::STATUS_UNCHANGED, $message = false)
  {
      /* $result = $this->getResultObject();
      $result->apiStatus = $status;
      $result->is_error = false;
      $result->message = $message;
      */
      $result = [
         'apiStatus' => $status,
         'message' => $message,
         'is_error' => false,
         'is_done' => false,
      ];
      return $result;
  }

  /**
   * Returns a success result array, optionally marking the item as done.
   *
   * Merges the provided data array into the result. The is_done flag is only added
   * when the status equals STATUS_SUCCESS. A false message is omitted from the result.
   *
   * @param array      $data    Additional data to merge into the result (e.g. files, aiData).
   * @param int        $status  Status code; defaults to STATUS_SUCCESS.
   * @param string|false $message Optional human-readable success message.
   * @return array The merged result array.
   */
  protected function returnSuccess($data, $status = self::STATUS_SUCCESS, $message = false)
  {

      $result = [
          'apiStatus' => $status,
          'message' => $message,
          'is_error' => false,
      ];

      if (self::STATUS_SUCCESS === $status)
        $result['is_done'] = true;

      if ($message == false)
      {
         unset($result['message']);
      }


      $result = array_merge($result, $data);
      return $result;
  }

  /**
   * Decodes the JSON body from a wp_remote_post() response.
   *
   * Falls back to extracting the first valid JSON object from the raw body string
   * when json_decode() returns null (e.g. when the response contains extra data
   * outside the JSON structure).
   *
   * @param array $response Raw wp_remote_post() response array with a 'body' key.
   * @return array Decoded response data as an associative array.
   */
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

  /**
   * Extracts all valid JSON object strings from a raw text payload.
   *
   * Uses a recursive regex to find top-level curly-brace blocks and validates each
   * one before returning the list of well-formed JSON strings.
   *
   * @param string $text Raw response body that may contain one or more JSON objects.
   * @return string[] Array of valid JSON object strings found in the text.
   */
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
