<?php

namespace ShortPixel\Controller;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

class ApiController
{
    const STATUS_ENQUEUED = 10;
    const STATUS_SUCCESS = 2;
    const STATUS_UNCHANGED = 0;
    const STATUS_ERROR = -1;
    const STATUS_FAIL = -2;
    const STATUS_QUOTA_EXCEEDED = -3;
    const STATUS_SKIP = -4;
    const STATUS_NOT_FOUND = -5;
    const STATUS_NO_KEY = -6;
    const STATUS_RETRY = -7;
    const STATUS_SEARCHING = -8; // when the Queue is looping over images, but in batch none were   found.
    const STATUS_QUEUE_FULL = -404;
    const STATUS_MAINTENANCE = -500;
    const STATUS_NOT_API = -1000; // Not an API process, i.e restore / migrate. Don't handle as optimized

    const ERR_FILE_NOT_FOUND = -2;
    const ERR_TIMEOUT = -3;
    const ERR_SAVE = -4;
    const ERR_SAVE_BKP = -5;
    const ERR_INCORRECT_FILE_SIZE = -6;
    const ERR_DOWNLOAD = -7;
    const ERR_PNG2JPG_MEMORY = -8;
    const ERR_POSTMETA_CORRUPT = -9;
    const ERR_UNKNOWN = -999;

    const DOWNLOAD_ARCHIVE = 7;

    private static $instance;

    private $apiEndPoint;
    private $apiDumpEndPoint;

    protected static $temporaryFiles = array();
    protected static $temporaryDirs = array();

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


  /*
  * @param Object $item Item of stdClass
  * @return Returns same Item with Result of request
  */
  public function processMediaItem($item)
  {
      if (! is_array($item->urls) || count($item->urls) == 0)
      {
          $item->result = $this->returnFailure(self::STATUS_FAIL, __('No Urls given for this Item', 'shortpixel-image-optimiser'));
          return $item;
      }

      $requestArgs = array('urls' => $item->urls); // obligatory
      if (property_exists($item, 'compressionType'))
        $requestArgs['compressionType'] = $item->compressionType;
      $requestArgs['blocking'] =  ($item->tries == 0) ? false : true;
      $requestArgs['item_id'] = $item->item_id;
      $requestArgs['refresh'] = (property_exists($item, 'refresh') && $item->refresh) ? true : false;
      $requestArgs['flags'] = (property_exists($item, 'flags')) ? $item->flags : array();

      $request = $this->getRequest($requestArgs);
      $item = $this->doRequest($item, $request);

			if ($item->result->is_error === true && $item->result->is_done === true)
			{
				 $this->dumpMediaItem($item); // item failed, directly dump anything from server.
			}

      return $item;
  }

	/* Ask to remove the items from the remote cache.
	  @param $item Must be object, with URLS set as array of urllist.
	*/
	public function dumpMediaItem($item)
	{
     $settings = \wpSPIO()->settings();
     $keyControl = ApiKeyController::getInstance();

		 if (property_exists($item, 'urls') === false || ! is_array($item->urls) || count($item->urls) == 0)
		 {
			  Log::addError('Media Item without URLS cannnot be dumped', $item);
				return false;
		 }

		 $request = $this->getRequest();

		 $request['body'] = json_encode(
			 			array(
                'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
                'key' => $keyControl->forceGetApiKey(),
                'urllist' => $item->urls	)
					);

		 Log::addDebug('Dumping Media Item', $item->urls);

		 $ret = wp_remote_post($this->apiDumpEndPoint, $request);

     return $ret;

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
          'flags' => array(),
    );

    $args = wp_parse_args($args, $defaults);
    $convertTo = implode("|", $args['flags']);

    $requestParameters = array(
        'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
        'key' => $keyControl->forceGetApiKey(),
        'lossy' => $args['compressionType'],
        'cmyk2rgb' => $settings->CMYKtoRGBconversion,
        'keep_exif' => ($settings->keepExif ? "1" : "0"),
        'convertto' => $convertTo,
        'resize' => $settings->resizeImages ? 1 + 2 * ($settings->resizeType == 'inner' ? 1 : 0) : 0,
        'resize_width' => $settings->resizeWidth,
        'resize_height' => $settings->resizeHeight,
        'urllist' => $args['urls'],
    );


    if(/*false &&*/ $settings->downloadArchive == self::DOWNLOAD_ARCHIVE && class_exists('PharData')) {
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
    $response = wp_remote_post($this->apiEndPoint, $requestParameters );
    Log::addDebug('ShortPixel API Request sent', $requestParameters['body']);

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
           $item->result = $this->handleResponse($item, $response);
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
       $text = ($item->tries > 0) ? sprintf(__('Item is waiting for results ( pass %d )', 'shortpixel-image-optimiser'), $item->tries) : __('Item is waiting for results', 'shortpixel-image-optimiser');
       $item->result = $this->returnOK(self::STATUS_ENQUEUED, $text );
    }

    return $item;
  }

  private function parseResponse($response)
  {
    $data = $response['body'];
    $data = json_decode($data);
    return (array)$data;
  }

  private function handleResponse($item, $response)
  {

    $APIresponse = $this->parseResponse($response);//get the actual response from API, its an array
    $settings = \wpSPIO()->settings();


		// Don't know if it's this or that.
		$status = false;
		if (isset($APIresponse['Status']))
		{
			$status = $APIresponse['Status'];
		}
		elseif( property_exists($APIresponse[0], 'Status'))
		{
			$status = $APIresponse[0]->Status;
		}

    // This is only set if something is up, otherwise, ApiResponse returns array
    if (is_object($status))
    {
        // Check for known errors. : https://shortpixel.com/api-docs
				Log::addDebug('Api Response Status :' . $status->Code  );
        switch($status->Code)
        {
              case -102: // Invalid URL
              case -105: // URL missing
              case -106: // Url is inaccessible
              case -113: // Too many inaccessible URLs
              case -201: // Invalid image format
              case -202: // Invalid image or unsupported format
              case -203: // Could not download file
                 return $this->returnFailure( self::STATUS_ERROR, $status->Message);
              break;
              case -403: // Quota Exceeded
              case -301: // The file is larger than remaining quota
									// legacy
                  @delete_option('bulkProcessingStatus');
									QuotaController::getInstance()->setQuotaExceeded();

                  return $this->returnRetry( self::STATUS_QUOTA_EXCEEDED, __('Quota exceeded.','shortpixel-image-optimiser'));
                  break;
              case -401: // Invalid Api Key
                  return $this->returnFailure( self::STATUS_NO_KEY, $status->Message);
              break;
              case -404: // Maximum number in optimization queue (remote)
                  //return array("Status" => self::STATUS_QUEUE_FULL, "Message" => $APIresponse['Status']->Message);
                  return $this->returnRetry( self::STATUS_QUEUE_FULL, $status->Message);
              case -500: // API in maintenance.
                  //return array("Status" => self::STATUS_MAINTENANCE, "Message" => $APIresponse['Status']->Message);
                  return $this->returnRetry( self::STATUS_MAINTENANCE, $status->Message);
          }
    }

    $neededURLS = $item->urls;

    if ( isset($APIresponse[0]) ) //API returned image details
    {
        foreach ( $APIresponse as $imageObject ) {//this part makes sure that all the sizes were processed and ready to be downloaded.
          // If status is still waiting. Check if the return URL is one we sent.
            if ( isset($imageObject->Status) && ( $imageObject->Status->Code == 0 || $imageObject->Status->Code == 1 ) && in_array($imageObject->OriginalURL, $neededURLS)) {
                return $this->returnOK(self::STATUS_UNCHANGED, __('Item is waiting for optimisation', 'shortpixel-image-optimiser'));
            }
        }

        $firstImage = $APIresponse[0];//extract as object first image
        switch($firstImage->Status->Code)
        {
        case self::STATUS_SUCCESS:
            //handle image has been processed
            return $this->handleSuccess($item, $APIresponse);
        default:

						// Theoretically this should not be needed.
						Log::addWarn('ApiController Response not handled before default case');
            if ( isset($APIresponse[0]->Status->Message) ) {

                $err = array("Status" => self::STATUS_FAIL, "Code" => (isset($APIresponse[0]->Status->Code) ? $APIresponse[0]->Status->Code : self::ERR_UNKNOWN),
                             "Message" => __('There was an error and your request was not processed.','shortpixel-image-optimiser')
                                          . " (" . wp_basename($APIresponse[0]->OriginalURL) . ": " . $APIresponse[0]->Status->Message . ")");
                return $this->returnRetry($err['Code'], $err['Message']);
            } else {
                $err = array("Status" => self::STATUS_FAIL, "Message" => __('There was an error and your request was not processed.','shortpixel-image-optimiser'),
                             "Code" => (isset($APIresponse[0]->Status->Code) ? $APIresponse[0]->Status->Code : self::ERR_UNKNOWN));
                return $this->returnRetry($err['Code'], $err['Message']);
            }

        }
    }

    // If this code reaches here, something is wrong.
    if(!isset($APIresponse['Status'])) {

        Log::addError('API returned Unknown Status/Response ', $response);
        return $this->returnFailure(self::STATUS_FAIL,  __('Unrecognized API response. Please contact support.','shortpixel-image-optimiser'));

    } else {

      //sometimes the response array can be different
      if (is_numeric($APIresponse['Status']->Code)) {
          //return array("Status" => self::STATUS_FAIL, "Message" => $APIresponse['Status']->Message);
          $message = $APIresponse['Status']->Message;
      } else {
          //return array("Status" => self::STATUS_FAIL, "Message" => $APIresponse[0]->Status->Message);
          $message = $APIresponse[0]->Status->Message;
      }
      return $this->returnRetry(self::STATUS_FAIL, $message);
    } // else
  }
  // handleResponse function



  /*  HandleSucces
  *
  * @param Object $item MediaItem that has been optimized
  * @param Object $response The API Response with opt. info.
  * @return ObjectArray $results The Result of the optimization
  */
  private function handleSuccess($item, $response)
  {
      Log::addDebug('Shortpixel API : Handling Success!', $response);
      $settings = \wpSPIO()->settings();
      $fs = \wpSPIO()->fileSystem();

      $counter = $savedSpace =  $originalSpace =  $optimizedSpace = $fileCount /* = $averageCompression */ = 0;
      $compressionType = property_exists($item, 'compressionType') ? $item->compressionType : $settings->compressionType;

      if($compressionType > 0) {
          $fileType = "LossyURL";
          $fileSize = "LossySize";
      } else {
          $fileType = "LosslessURL";
          $fileSize = "LosslessSize";
      }
      $webpType = "WebP" . $fileType;
      $avifType = "AVIF" . $fileType;


      $tempFiles = $responseFiles = $results = array();

      //download each file from array and process it
      foreach ($response as $fileData )
      {
          if(!isset($fileData->Status)) continue; //if optimized images archive is activated, last entry of APIResponse if the Archive data.

          //file was processed OK
          if ($fileData->Status->Code == self::STATUS_SUCCESS )
          {
                  $downloadResult = $this->handleDownload($fileData->$fileType, $fileData->$fileSize, $fileData->OriginalSize
                );
                $archive = false;

              /* Status_Unchanged will be caught by ImageModel and not copied ( should be ).
              * @todo Write Unit Test for Status_unchanged
              * But it should still be regarded as File Done. This can happen on very small file ( 6pxX6px ) which will not optimize.
              */
              if ($downloadResult->apiStatus == self::STATUS_SUCCESS || $downloadResult->apiStatus == self::STATUS_UNCHANGED )
              {
                  // Removes any query ?strings and returns just filename of originalURL
                  $originalURL = $fileData->OriginalURL;

                  if (strpos($fileData->OriginalURL, '?') !== false)
                  {
                    $originalURL = substr($fileData->OriginalURL, 0, (strpos($fileData->OriginalURL, '?'))  ); // Strip Query String from URL. If it's there!
                  }
                  $originalFile = $fs->getFile($originalURL); //basename(parse_url($fileData->OriginalURL, PHP_URL_PATH));

                  // Put it in Results.
                  $originalName = $originalFile->getFileName();
                  $results[$originalName] = $downloadResult;

                  // Handle Stats
                  $savedSpace += $fileData->OriginalSize - $fileData->$fileSize;
                  $originalSpace += $fileData->OriginalSize;
                  $optimizedSpace += $fileData->$fileSize;
                  $fileCount++;

                  // ** Download Webp files if they are returned **/
                  if (isset($fileData->$webpType) && $fileData->$webpType != 'NA')
                  {
                    $webpName = $originalFile->getFileBase() . '.webp'; //basename(parse_url($fileData->$webpType, PHP_URL_PATH));

                    if($archive) { // swallow pride here, or fix this.
                        $webpDownloadResult = $this->fromArchive($archive['Path'], $fileData->$webpType, false,false);
                    } else {
                        $webpDownloadResult = $this->handleDownload($fileData->$webpType, false, false);
                    }

                    if ( $webpDownloadResult->apiStatus == self::STATUS_SUCCESS)
                    {
                       Log::addDebug('Downloaded Webp : ' . $fileData->$webpType);
                       $results[$webpName] = $webpDownloadResult;
                    }
                  }

                  // ** Download Webp files if they are returned **/
                  if (isset($fileData->$avifType) && $fileData->$avifType !== 'NA')
                  {
                    $avifName = $originalFile->getFileBase() . '.avif'; ;

                    if($archive) { // swallow pride here, or fix this.
                        $avifDownloadResult = $this->fromArchive($archive['Path'], $fileData->$avifType, false,false);
                    } else {
                        $avifDownloadResult = $this->handleDownload($fileData->$avifType, false, false);
                    }

                    if ( $avifDownloadResult->apiStatus == self::STATUS_SUCCESS)
                    {
                       Log::addDebug('Downloaded Avif : ' . $fileData->$avifType);
                       $results[$avifName] = $avifDownloadResult;
                    }
                  }

              }
              /*elseif ($downloadResult->status == self::STATUS_UNCHANGED)
              {
                  // Do nothing.
              } */
              //when the status is STATUS_UNCHANGED we just skip the array line for that one
              /*elseif( $downloadResult->status == self::STATUS_UNCHANGED ) {
                  //this image is unchanged so won't be copied below, only the optimization stats need to be computed
                  $originalSpace += $fileData->OriginalSize;
                  $optimizedSpace += $fileData->$fileSize;

              } */
              else {
                  self::cleanupTemporaryFiles($archive, $tempFiles);
                //  return $downloadResult;
              }

          }
          else { //there was an error while trying to download a file
              $tempFiles[$counter] = "";
          }
          $counter++;
      }

      // Update File Stats

      $settings->savedSpace += $savedSpace;
      $settings->fileCount += $fileCount;
      //new average counting
      $settings->totalOriginal += $originalSpace;
      $settings->totalOptimized += $optimizedSpace;

      Log::addDebug("Adding $fileCount files to stats, $originalSpace went to $optimizedSpace ($savedSpace)");

      // *******************************

      return $this->returnSuccess($results, self::STATUS_SUCCESS, false);
  }

  /**
   * handles the download of an optimized image from ShortPixel API
   * @param string $optimizedUrl
   * @param int $optimizedSize  Check optimize and original size for file consistency
   * @param int $originalSize
   * @return array status /message array
   */
  private function handleDownload($optimizedUrl, $optimizedSize = false, $originalSize = false){

      $downloadTimeout = max(ini_get('max_execution_time') - 10, 15);
      $fs = \wpSPIO()->filesystem();


      //if there is no improvement in size then we do not download this file
      if (($optimizedSize !== false && $originalSize !== false) && $originalSize == $optimizedSize )
      {
          return $this->returnRetry(self::STATUS_UNCHANGED, __("File wasn't optimized so we do not download it.", 'shortpixel-image-optimiser'));
      }
      $correctFileSize = $optimizedSize;
      $fileURL = $this->setPreferredProtocol(urldecode($optimizedUrl));

      $tempFile = download_url($fileURL, $downloadTimeout);
      Log::addInfo('Downloading ' . $fileURL . ' to : '.json_encode($tempFile));
      if(is_wp_error( $tempFile ))
      { //try to switch the default protocol
          $fileURL = $this->setPreferredProtocol(urldecode($optimizedUrl), true); //force recheck of the protocol
          $tempFile = download_url($fileURL, $downloadTimeout);
      }

      //on success we return this
    //  $returnMessage = array("Status" => self::STATUS_SUCCESS, "Message" => $tempFile);

      if ( is_wp_error( $tempFile ) ) {
          return $this->returnFailure(self::STATUS_ERROR, __('Error downloading file','shortpixel-image-optimiser') . " ({$optimizedUrl}) " . $tempFile->get_error_message());
      }

      $tempFile = $fs->getFile($tempFile); // switch to FS after download

      //check response so that download is OK
      if (! $tempFile->exists()) {
          return $this->returnFailure(self::ERR_FILE_NOT_FOUND, __('Unable to locate downloaded file','shortpixel-image-optimiser') . " " . $tempFile );
          /*$returnMessage = array("Status" => self::STATUS_ERROR,
              "Code" => self::ERR_FILE_NOT_FOUND,
              "Message" => __('Unable to locate downloaded file','shortpixel-image-optimiser') . " " . $tempFile); */
      }
      elseif($correctFileSize !== false &&  $tempFile->getFileSize() != $correctFileSize) {

          $tempFile->delete();
          Log::addWarn('Incorrect file size: ' . $tempFile->getFullPath() . '(' . $correctFileSize . ')');
          return $this->returnFailure(self::ERR_INCORRECT_FILE_SIZE, sprintf(__('Error downloading file - incorrect file size (downloaded: %s, correct: %s )','shortpixel-image-optimiser'),$tempFile->getFileSize(), $correctFileSize));
          /*$returnMessage = array(
              "Status" => self::STATUS_ERROR,
              "Code" => self::ERR_INCORRECT_FILE_SIZE,
              "Message" => sprintf(__('Error downloading file - incorrect file size (downloaded: %s, correct: %s )','shortpixel-image-optimiser'),$size, $correctFileSize)); */
      }
      //return $returnMessage;
      return $this->returnSuccess($tempFile);
  }

  private function fromArchive($path, $optimizedUrl, $optimizedSize, $originalSize) {

      $fs = \wpSPIO()->filesystem();

      //if there is no improvement in size then we do not download this file
      if ( $originalSize == $optimizedSize )
      {
          return $this->returnRetry(self::STATUS_UNCHANGED, __("File wasn't optimized so we do not download it. Retry", 'shortpixel-image-optimiser'));
      }

      $correctFileSize = $optimizedSize;
      $tempFile = $path . '/' . wp_basename($optimizedUrl);

      $tempFile = $fs->getFile($tempFile);

      if($tempFile->exists()) {
          //on success we return this
         if( $tempFile->getFileSize() != $correctFileSize) {

             $tempFile->delete();

                 return $this->returnFailure(self::ERR_INCORRECT_FILE_SIZE, sprintf(__('Error downloading file - incorrect file size (downloaded: %s, correct: %s )','shortpixel-image-optimiser'),$tempFile->getFileSize(), $correctFileSize));

          } else {
              $this->returnSuccess($tempFile);
         }
      } else {
          $returnMessage = array("Status" => self::STATUS_ERROR,
              "Code" => self::ERR_FILE_NOT_FOUND,
              "Message" => __('Unable to locate downloaded file','shortpixel-image-optimiser') . " " . $tempFile);
      }

      $this->returnSuccess($tempFile);
//      return $returnMessage;
  }


  /**
   * sets the preferred protocol of URL using the globally set preferred protocol.
   * If  global protocol not set, sets it by testing the download of a http test image from ShortPixel site.
   * If http works then it's http, otherwise sets https
   * @param string $url
   * @param bool $reset - forces recheck even if preferred protocol is already set
   * @return string url with the preferred protocol
   */
  private function setPreferredProtocol($url, $reset = false) {
      //switch protocol based on the formerly detected working protocol
      $settings = \wpSPIO()->settings();

      if($settings->downloadProto == '' || $reset) {
          //make a test to see if the http is working
          $testURL = 'http://' . SHORTPIXEL_API . '/img/connection-test-image.png';
          $result = download_url($testURL, 10);
          $settings->downloadProto = is_wp_error( $result ) ? 'https' : 'http';
      }
      return $settings->downloadProto == 'http' ?
              str_replace('https://', 'http://', $url) :
              str_replace('http://', 'https://', $url);
  }

  private function getSetting($name)
  {
     return \wpSPIO()->settings()->$name;

  }

  private function getResultObject()
  {
        $result = new \stdClass;
        $result->apiStatus = null;
        $result->message = '';
        $result->is_error = false;
        $result->is_done = false;
        //$result->errors = array();

        return $result;
  }

  private function returnFailure($status, $message)
  {
        $result = $this->getResultObject();
        $result->apiStatus = $status;
        $result->message = $message;
        $result->is_error = true;
        $result->is_done = true;

        return $result;  // fatal.
  }

  // Temporary Error, retry.
  private function returnRetry($status, $message)
  {

    $result = $this->getResultObject();
    $result->apiStatus = $status;
    $result->message = $message;

    //$result->errors[] = array('status' => $status, 'message' => $message);
    $result->is_error = true;

    return $result;
  }

  private function returnOK($status = self::STATUS_UNCHANGED, $message = false)
  {
      $result = $this->getResultObject();
      $result->apiStatus = $status;
      $result->is_error = false;
      $result->message = $message;

      return $result;
  }

  /** Returns a success status. This is succeseption, each file gives it's own status, bundled. */
  private function returnSuccess($file, $status = self::STATUS_SUCCESS, $message = false)
  {
      $result = $this->getResultObject();
      $result->apiStatus = $status;
      $result->message = $message;
      $result->is_done = true;
      if (is_array($file))
        $result->files = $file;
      else
        $result->file = $file;

      return $result;
  }



} // class
