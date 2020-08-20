<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;



class ApiController
{
  const STATUS_SUCCESS = 1;
  const STATUS_UNCHANGED = 0;
  const STATUS_ERROR = -1;
  const STATUS_FAIL = -2;
  const STATUS_QUOTA_EXCEEDED = -3;
  const STATUS_SKIP = -4;
  const STATUS_NOT_FOUND = -5;
  const STATUS_NO_KEY = -6;
  const STATUS_RETRY = -7;
  const STATUS_SEARCHING = -8; // when the Queue is looping over images, but in batch none were found.
  const STATUS_QUEUE_FULL = -404;
  const STATUS_MAINTENANCE = -500;

  const ERR_FILE_NOT_FOUND = -2;
  const ERR_TIMEOUT = -3;
  const ERR_SAVE = -4;
  const ERR_SAVE_BKP = -5;
  const ERR_INCORRECT_FILE_SIZE = -6;
  const ERR_DOWNLOAD = -7;
  const ERR_PNG2JPG_MEMORY = -8;
  const ERR_POSTMETA_CORRUPT = -9;
  const ERR_UNKNOWN = -999;

  private static $instance;

  private $apiEndPoint;
  private $apiDumpEndPoint;

  public function __construct()
  {
    $settings = \wpSPIO()->settings();
    $this->apiEndPoint = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/reducer.php';
    $this->apiDumpEndPoint = $settings->httpProto . '://' . SHORTPIXEL_API . '/v2/cleanup.php';
  }


  public static function getInstance()
  {
     if (is_null(self::$instance))
       self::$instance = new ApiController();

      return self::$instance;
  }


  public function processMediaItem($item)
  {
      if (count($items->urls) == 0)
      {
          $item->result = $this->returnFailure(STATUS_FAIL, __('No Urls given for this Item', 'shortpixel-image-optimiser'));
          return $item;
      }

      $requestArgs = array('urls' => $item->urls); // obligatory
      if (isset($item->compressionType))
        $requestArgs['compressionType'] = $item->compressionType;
      $requestArgs['blocking'] =  ($item->tries == 0) ? false : true;
      $requestArgs['item_id'] = $item->id;
      $requestArgs['refresh'] = (isset($item->refresh) && $item->refresh) ? true : false;


      $request = $this->getRequest($requestArgs);
      $item = $this->doRequest($item, $request);

      // @todo Might be interesting to put the result to Item for furter return processing.
      //$item->result = $this->getResult($result);

      return $item;
  }

  /** Former, prepare Request in API */
  private function getRequest($args = array())
  {
    $settings = \wpSPIO()->settings();
    $keyControl = ApiKeyController::getInstance();

    $defaults = array(
          'urls' => null,
          'compressionType' => $settings->compressionType,
          'blocking' => true,
          'item_id' => null,
          'refresh' => false,
    );

    $args = wp_parse_args($args, $defaults);
var_dump($args);
    $requestParameters = array(
        'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
        'key' => $keyControl->forceGetApiKey(),
        'lossy' => $args['compressionType'],
        'cmyk2rgb' => $settings->CMYKtoRGBconversion,
        'keep_exif' => ($settings->keepExif ? "1" : "0"),
        'convertto' => ($settings->createWebp ? urlencode("+webp") : ""),
        'resize' => $settings->resizeImages ? 1 + 2 * ($settings->resizeType == 'inner' ? 1 : 0) : 0,
        'resize_width' => $settings->resizeWidth,
        'resize_height' => $settings->resizeHeight,
        'urllist' => $args['urls'],
    );

    if(/*false &&*/ $settings->downloadArchive == 7 && class_exists('PharData')) {
        $requestParameters['group'] = $args['item_id'];
    }
    if($args['refresh']) { // @todo if previous status was ShortPixelAPI::ERR_INCORRECT_FILE_SIZE; then refresh.
        $requestParameters['refresh'] = 1;
    }

    $arguments = array(
        'method' => 'POST',
        'timeout' => 15,
        'redirection' => 3,
        'sslverify' => false,
        'httpversion' => '1.0',
        'blocking' => $args['blocking'],
        'headers' => array(),
        'body' => json_encode($requestParameters),
        'cookies' => array()
    );
    //add this explicitely only for https, otherwise (for http) it slows down the request
    if($settings->httpProto !== 'https') {
        unset($arguments['sslverify']);
    }

    return $arguments;
  }

  protected function doRequest($item, $requestParameters)
  {

    //WpShortPixel::log("ShortPixel API Request Settings: " . json_encode($requestParameters));
    $response = wp_remote_post($this->apiEndPoint, $requestParameters );
    Log::addDebug('ShortPixel API Request sent', $requestParameters);

    //WpShortPixel::log('RESPONSE: ' . json_encode($response));

    //only if $Blocking is true analyze the response
    if ( $requestParameters['blocking'] )
    {
        if ( is_object($response) && get_class($response) == 'WP_Error' )
        {
            $errorMessage = $response->errors['http_request_failed'][0];
            $errorCode = 503;
            $item->result = $this->returnFailure($errorCode, $errorMessage);
        }
        elseif ( isset($response['response']['code']) && $response['response']['code'] <> 200 )
        {
            $errorMessage = $response['response']['code'] . " - " . $response['response']['message'];
            $errorCode = $response['response']['code'];
            $item->result = $this->returnFailure($errorCode, $errorMessage);
        }
        else
        {
           $item = $this->handleResponse($response, $item);
        }
  /*      if ( isset($errorMessage) )
        {//set details inside file so user can know what happened
          //  $itemHandler->incrementRetries(1, $errorCode, $errorMessage);
          return self::STATUS_FAIL;
            //return array("response" => array("code" => $errorCode, "message" => $errorMessage ));
        } */

        //return $response;//this can be an error or a good response
    }
    else
    {
       $item->result = $item->returnoK(STATUS_OK);
    }
    //return $response;

    return $item;
  }

  private function handleResponse($response, $item)
  {
    $data = $response['body'];
    $data = json_decode($data);
    return (array)$data;

    
  }


  private function getSetting($name)
  {
     return \wpSPIO()->settings()->$name;

  }

  private function getResultObject($result)
  {
        $result = new \stdClass;
        $result->status = null;
        $result->message = '';
        $result->is_error = false;
        $result->is_done = false;

        return $result;
  }

  private function returnFailure($status, $message)
  {
        $result = $this->getResultObject();
        $result->status = $status;
        $result->message = $message;
        $result->is_error = true;
        $result->is_done = true;

        return $result;  // fatal.

        /*switch ($status)
        {
            default:
                $message = __()
            break;
        } */
  }

  private function returnOK()
  {
      $result = $this->getResultObj();
      $result->status = self::STATUS_UNCHANGED;

      return $result;
  }

  private function returnSuccess()
  {

  }



} // class
