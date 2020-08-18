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

  public function __construct()
  {

  }


  public static function getInstance()
  {
     if (is_null(self::$instance))
       self::$instance = new ApiController();

      return self::$instance;
  }


  public function processMediaItem($item)
  {
      var_dump($item);
      $requestArgs = array('urls' => $item->urls); // obligatory
      if (isset($item->compressionType))
        $requestArgs['compressionsType'] = $item->compressionType;

      $request = $this->getRequest($requestArgs);



  }

  /** Former, prepare Request in API */
  private function prepareRequest($args = array())
  {
    $settings = \wpSPIO()->settings();
    $keyControl = ApiKeyController::getInstance();

    $requestParameters = array(
        'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
        'key' => $keyControl->forceGetApiKey(),
        'lossy' => $compressionType === false ? $settings->compressionType : $compressionType,
        'cmyk2rgb' => $this->_settings->CMYKtoRGBconversion,
        'keep_exif' => ($settings->keepExif ? "1" : "0"),
        'convertto' => ($settings->createWebp ? urlencode("+webp") : ""),
        'resize' => $settings->resizeImages ? 1 + 2 * ($settings->resizeType == 'inner' ? 1 : 0) : 0,
        'resize_width' => $settings->resizeWidth,
        'resize_height' => $settings->resizeHeight,
        'urllist' => $URLs,
    );

    if(/*false &&*/ $this->_settings->downloadArchive == 7 && class_exists('PharData')) {
        $requestParameters['group'] = $itemHandler->getId();
    }
    if($refresh) {
        $requestParameters['refresh'] = 1;
    }

    $arguments = array(
        'method' => 'POST',
        'timeout' => 15,
        'redirection' => 3,
        'sslverify' => false,
        'httpversion' => '1.0',
        'blocking' => $Blocking,
        'headers' => array(),
        'body' => json_encode($requestParameters),
        'cookies' => array()
    );
    //add this explicitely only for https, otherwise (for http) it slows down the request
    if($settings->httpProto !== 'https') {
        unset($arguments['sslverify']);
    }
  }

  protected function doRequest()
  {


    //WpShortPixel::log("ShortPixel API Request Settings: " . json_encode($requestParameters));
    $response = wp_remote_post($this->_apiEndPoint, $this->prepareRequest($requestParameters, $Blocking) );
    Log::addDebug('ShortPixel API Request sent', $requestParameters);

    //WpShortPixel::log('RESPONSE: ' . json_encode($response));

    //only if $Blocking is true analyze the response
    if ( $Blocking )
    {
        if ( is_object($response) && get_class($response) == 'WP_Error' )
        {
            $errorMessage = $response->errors['http_request_failed'][0];
            $errorCode = 503;
        }
        elseif ( isset($response['response']['code']) && $response['response']['code'] <> 200 )
        {
            $errorMessage = $response['response']['code'] . " - " . $response['response']['message'];
            $errorCode = $response['response']['code'];
        }

        if ( isset($errorMessage) )
        {//set details inside file so user can know what happened
            $itemHandler->incrementRetries(1, $errorCode, $errorMessage);
            return array("response" => array("code" => $errorCode, "message" => $errorMessage ));
        }

        return $response;//this can be an error or a good response
    }

    return $response;
  }


  private function getSetting($name)
  {
     return \wpSPIO()->settings()->$name;

  }




} // class
