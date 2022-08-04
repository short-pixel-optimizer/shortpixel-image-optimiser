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
		const STATUS_CONNECTION_ERROR = -503; // Not official, error connection in WP.
    const STATUS_NOT_API = -1000; // Not an API process, i.e restore / migrate. Don't handle as optimized

		// Moved these numbers higher to prevent conflict with STATUS
    const ERR_FILE_NOT_FOUND = -902;
    const ERR_TIMEOUT = -903;
    const ERR_SAVE = -904;
    const ERR_SAVE_BKP = -905;
    const ERR_INCORRECT_FILE_SIZE = -906;
    const ERR_DOWNLOAD = -907;
    const ERR_PNG2JPG_MEMORY = -908;
    const ERR_POSTMETA_CORRUPT = -909;
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
  public function processMediaItem($item, $imageObj)
  {
		 	if (! $imageObj->isProcessable() || $imageObj->isOptimizePrevented() == true)
			{
					if ($imageObj->isOptimized())
					{
						 $item->result = $this->returnFailure(self::STATUS_FAIL, __('Item is already optimized', 'shortpixel-image-optimiser'));
						 return $item;
					}
					else {
						 $item->result = $this->returnFailure(self::STATUS_FAIL, __('Item is not processable and not optimized', 'shortpixel-image-optimiser'));
						 return $item;
					}
			}

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

			ResponseController::addData($item->item_id, 'images_total', count($item->urls));

			// If error has occured, but it's not related to connection.
			if ($item->result->is_error === true && $item->result->is_done === true)
			{
				 $this->dumpMediaItem($item); // item failed, directly dump anything from server.
			}

      return $item;
  }

	/* Ask to remove the items from the remote cache.
	  @param $item Must be object, with URLS set as array of urllist. - Secretly not a mediaItem - shame
	*/
	public function dumpMediaItem($item)
	{
     $settings = \wpSPIO()->settings();
     $keyControl = ApiKeyController::getInstance();

		 if (property_exists($item, 'urls') === false || ! is_array($item->urls) || count($item->urls) == 0)
		 {
			  Log::addWarn('Media Item without URLS cannnot be dumped ', $item);
				return false;
		 }

		 $request = $this->getRequest();

		 $request['body'] = json_encode(
			 			array(
                'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
                'key' => $keyControl->forceGetApiKey(),
                'urllist' => $item->urls	)
					);

		 Log::addDebug('Dumping Media Item ', $item->urls);

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

		$requestParameters = apply_filters('shortpixel/api/request', $requestParameters, $args['item_id']);

    $arguments = array(
        'method' => 'POST',
        'timeout' => 15,
        'redirection' => 3,
        'sslverify' => apply_filters('shortpixel/system/sslverify', true),
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


	/** DoRequest : Does a remote_post to the API
	*
	* @param Object $item  The QueueItemObject
	* @param Array $requestParameters  The HTTP parameters for the remote post (arguments in getRequest)
	*/
  protected function doRequest($item, $requestParameters )
  {
    $response = wp_remote_post($this->apiEndPoint, $requestParameters );
    Log::addDebug('ShortPixel API Request sent', $requestParameters['body']);


    //only if $Blocking is true analyze the response
    if ( $requestParameters['blocking'] )
    {
        if ( is_object($response) && get_class($response) == 'WP_Error' )
        {
            $errorMessage = $response->errors['http_request_failed'][0];
            $errorCode = self::STATUS_CONNECTION_ERROR;
            $item->result = $this->returnRetry($errorCode, $errorMessage);
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

    }
    else // This should be only non-blocking the FIRST time it's send off.
    {
			 if ($item->tries > 0)
			 {
			 		Log::addWarn('DOREQUEST sent item non-blocking with multiple tries!', $item);
			 }

			 // Non-blocking shouldn't have tries.
       /*$text = ($item->tries > 0) ? sprintf(__('(Api DoRequest) Item is waiting for results ( pass %d )', 'shortpixel-image-optimiser'), $item->tries) : __('(Api DoRequest) Item is waiting for results', 'shortpixel-image-optimiser');
			 */
			 $urls = count($item->urls);
			 $flags = property_exists($item, 'flags') ? $item->flags : array();
			 $flags = implode("|", $flags);
			 $text = sprintf(__('New item #%d sent for processing ( %d URLS %s)  ', 'shortpixel-image-optimiser'), $item->item_id, $urls, $flags );

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

	/**
	*
	**/
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

    $neededURLS = $item->urls; // URLS we are waiting for.


    if ( isset($APIresponse[0]) ) //API returned image details
    {
				$analyze = array('total' => count($item->urls), 'ready' => 0, 'waiting' => 0);
				$waitingDebug = array();
				foreach($APIresponse as $imageObject) // loop for analyzing
				{
					if (property_exists($imageObject, 'Status'))
					{
					 	if ($imageObject->Status->Code == self::STATUS_SUCCESS)
					 	{
					 	  	$analyze['ready']++;
					 	}
						elseif ($imageObject->Status->Code == 0 || $imageObject->Status->Code == 1) // unchanged /waiting
						{
							 $analyze['waiting']++;
						//	 $waitingDebug[] = $imageObj->
						}
					}
				}

				$imageData = array(
						'images_done' => $analyze['ready'],
						'images_waiting' => $analyze['waiting'],
						'images_total' => $analyze['total']
				);


				ResponseController::addData($item->item_id, $imageData);

				// This part makes sure that all the sizes were processed and ready to be downloaded. If ones is missing, we wait more.
        foreach ( $APIresponse as $imageObject ) {

          // If status is still waiting. Check if the return URL is one we sent.
            if ( isset($imageObject->Status) && ( $imageObject->Status->Code == 0 || $imageObject->Status->Code == 1 ) && in_array($imageObject->OriginalURL, $neededURLS)) {

							//	ResponseController:: @todo See what needs this doing.
                return $this->returnOK(self::STATUS_UNCHANGED, sprintf(__('Item is waiting', 'shortpixel-image-optimiser')));
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
    } // ApiResponse[0]

    // If this code reaches here, something is wrong.
    if(!isset($APIresponse['Status'])) {

        Log::addError('API returned Unknown Status/Response ', $response);
        return $this->returnFailure(self::STATUS_FAIL,  __('Unrecognized API response. Please contact support.','shortpixel-image-optimiser'));

    } else {

      //sometimes the response array can be different
      if (is_numeric($APIresponse['Status']->Code)) {
          $message = $APIresponse['Status']->Message;
      } else {
          $message = $APIresponse[0]->Status->Message;
      }

			if (! isset($message) || is_null($message) || $message == '')
			{
				 $message = __('Unrecognized API message. Please contact support.','shortpixel-image-optimiser');
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
      Log::addDebug('ShortPixel API : Handling Success!', $response);
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

									// This is the main translation back from URL back to local path.
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
              else {  // Some error
                //  self::cleanupTemporaryFiles($archive, $tempFiles);
								if (property_exists($downloadResult, 'file')) // delete Temp File if there.
								{
									 if ($downloadResult->file->exists())
									 	$downloadResult->file->delete();
								}
                return $downloadResult;

              }

          }
          else { //there was an error while trying to download a file
              $tempFiles[$counter] = "";
          }
          $counter++;
      }

      // Update File Stats
			if ($savedSpace > 0)
			{
      	$settings->savedSpace += $savedSpace;
      	$settings->fileCount += $fileCount;
      	//new average counting
      	$settings->totalOriginal += $originalSpace;
      	$settings->totalOptimized += $optimizedSpace;
			}
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
  private function handleDownload($optimizedUrl, $optimizedSize = false, $originalSize = false)
	{
      $downloadTimeout = max(ini_get('max_execution_time') - 10, 15);
      $fs = \wpSPIO()->filesystem();

      //if there is no improvement in size then we do not download this file, except (sigh) when the fileType is heic since it converts.
      if (($optimizedSize !== false && $originalSize !== false) && $originalSize == $optimizedSize && strpos($optimizedUrl, 'heic') === false )
      {

				  Log::addDebug('Optimize and Original size seems the same');
          return $this->returnRetry(self::STATUS_UNCHANGED, __("File wasn't optimized so we do not download it.", 'shortpixel-image-optimiser'));
      }
      $correctFileSize = $optimizedSize;
      $fileURL = $this->setPreferredProtocol(urldecode($optimizedUrl));

      $tempFile = \download_url($fileURL, $downloadTimeout);
      Log::addInfo('Downloading ' . $fileURL . ' to : '.json_encode($tempFile));
      if(is_wp_error( $tempFile ))
      { //try to switch the default protocol
          $fileURL = $this->setPreferredProtocol(urldecode($optimizedUrl), true); //force recheck of the protocol
          $tempFile = \download_url($fileURL, $downloadTimeout);
      }

      if ( is_wp_error( $tempFile ) ) {

				  $fail = true;
					$error = self::STATUS_ERROR;
					$error_message = $tempFile->get_error_message();

					if (strpos($error_message, 'timed out') !== false)
					{
							$error = self::ERR_TIMEOUT;
							$fail = false;
					}

					if ($fail)
					{

							Log::addError('[Fatal] Failed downloading file ', $error_message);
          		return $this->returnFailure($error, __('Error downloading file','shortpixel-image-optimiser') . " ({$optimizedUrl}) " . $error_message);
					}
					else
					{
							Log::addWarn('Failed downloading file ', $error_message);
					    return $this->returnRetry($error, __('Error downloading file','shortpixel-image-optimiser') . " ({$optimizedUrl}) " . $error_message);
					}
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
        $result->file = $file; // this file is being used in imageModel

      return $result;
  }



} // class
