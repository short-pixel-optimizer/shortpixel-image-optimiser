<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;



class ApiController
{
//  const STATUS_
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

  const DOWNLOAD_ARCHIVE = 7; // @todo Other settings for this Setting?

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
      if (isset($item->compressionType))
        $requestArgs['compressionType'] = $item->compressionType;
      $requestArgs['blocking'] =  ($item->tries == 0) ? false : true;
      $requestArgs['item_id'] = $item->item_id;
      $requestArgs['refresh'] = (property_exists($item, 'refresh') && $item->refresh) ? true : false;

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

    $convertTo = array();
    if ($this->_settings->createWebp)
       $convertTo[]= urlencode("+webp");
    if ($this->_settings->createAvif)
       $convertTo[] = urlencode('+avif');

     if (count($convertTo) > 0)
       $convertTo = implode('|', $convertTo);
     else
       $convertTo = '';

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

    //WpShortPixel::log("ShortPixel API Request Settings: " . json_encode($requestParameters));
    $response = wp_remote_post($this->apiEndPoint, $requestParameters );

    Log::addDebug('ShortPixel API Request sent', $requestParameters);
    Log::addtemp('Remote Response ', $response);

//echo "<PRE>"; var_dump($response); echo "</PRE>";  exit();
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
    //return $response;

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

    // This is only set if something is up, otherwise, ApiResponise returns array
    if ( isset($APIresponse['Status']))
    {
        // Check for known errors. : https://shortpixel.com/api-docs
        switch($APIresponse['Status']->Code)
        {
              case -102: // Invalid URL
              case -105: // URL missing
              case -106: // Url is inaccessible
              case -113: // Too many inaccessible URLs
              case -201: // Invalid image format
              case -202: // Invalid image or unsupported format
              case -203: // Could not download file
                 return $this->returnFailure( self::STATUS_ERROR, $APIresponse['Status']->Message);
              break;
              case -403:
              case -301:
                  @delete_option('bulkProcessingStatus'); // legacy

                  $settings->quotaExceeded = 1; // @todo This should be a function in quotaController.

                  return $this->returnRetry( self::STATUS_QUOTA_EXCEEDED, __('Quota exceeded.','shortpixel-image-optimiser'));
                  break;
              case -401:
                  return $this->returnFailure( self::STATUS_NO_KEY, $APIresponse['Status']->Message);
              break;
              case -404:
                  //return array("Status" => self::STATUS_QUEUE_FULL, "Message" => $APIresponse['Status']->Message);
                  return $this->returnRetry( self::STATUS_QUEUE_FULL, $APIresponse['Status']->Message);
              case -500:
                  //return array("Status" => self::STATUS_MAINTENANCE, "Message" => $APIresponse['Status']->Message);
                  return $this->returnRetry( self::STATUS_MAINTENANCE, $APIresponse['Status']->Message);
          }
    }


    if ( isset($APIresponse[0]) ) //API returned image details
    {
        foreach ( $APIresponse as $imageObject ) {//this part makes sure that all the sizes were processed and ready to be downloaded
            if ( isset($imageObject->Status) && ( $imageObject->Status->Code == 0 || $imageObject->Status->Code == 1 ) ) {
              //  sleep(1); // @todo ??
        //        return $this->processImageRecursive($URLs, $PATHs, $itemHandler, $startTime);
                return $this->returnOK(self::STATUS_UNCHANGED, __('Item is waiting for optimisation', 'shortpixel-image-optimiser'));
            }
        }

        $firstImage = $APIresponse[0];//extract as object first image
        switch($firstImage->Status->Code)
        {
        case self::STATUS_SUCCESS: //self::STATUS_SUCCESS: <- @todo Success in this constant is 1 ,but appears to be 2? // success
            //handle image has been processed
            return $this->handleSuccess($item, $APIresponse);
        default:
            //handle error
          //  $incR = 1;
          /*  This should not be possible / optimizeURLS should checks path.
           if ( !file_exists($PATHs[0]) ) {
                $err = array("Status" => self::STATUS_NOT_FOUND, "Message" => "File not found on disk. "
                             . ($itemHandler->getType() == ShortPixelMetaFacade::CUSTOM_TYPE ? "Image" : "Media")
                             . " ID: " . $itemHandler->getId(), "Code" => self::ERR_FILE_NOT_FOUND);
                $incR = 3;
            } */
            if ( isset($APIresponse[0]->Status->Message) ) {
                //return array("Status" => self::STATUS_FAIL, "Message" => "There was an error and your request was not processed (" . $APIresponse[0]->Status->Message . "). REQ: " . json_encode($URLs));
                $err = array("Status" => self::STATUS_FAIL, "Code" => (isset($APIresponse[0]->Status->Code) ? $APIresponse[0]->Status->Code : self::ERR_UNKNOWN),
                             "Message" => __('There was an error and your request was not processed.','shortpixel-image-optimiser')
                                          . " (" . wp_basename($APIresponse[0]->OriginalURL) . ": " . $APIresponse[0]->Status->Message . ")");
              ///  $this->resultFa
                return $this->returnRetry($err['Code'], $err['Message']);
            } else {
                $err = array("Status" => self::STATUS_FAIL, "Message" => __('There was an error and your request was not processed.','shortpixel-image-optimiser'),
                             "Code" => (isset($APIresponse[0]->Status->Code) ? $APIresponse[0]->Status->Code : self::ERR_UNKNOWN));
                return $this->returnRetry($err['Code'], $err['Message']);
            }

        //    $itemHandler->incrementRetries($incR, $err["Code"], $err["Message"]);
        //    $meta = $itemHandler->getMeta();
          /* Stuff for the queue */

            /*if($meta->getRetries() >= SHORTPIXEL_MAX_FAIL_RETRIES) {
                $meta->setStatus($APIresponse[0]->Status->Code);
                $meta->setMessage($APIresponse[0]->Status->Message);
                $itemHandler->updateMeta($meta);
            } */
          //  return $err;
        }
    }

    // If this code reaches here, something is wrong.
    if(!isset($APIresponse['Status'])) {
        //WpShortPixel::log("API Response Status unfound : " . json_encode($APIresponse));
        /*return array("Status" => self::STATUS_FAIL, "Message" => __('Unrecognized API response. Please contact support.','shortpixel-image-optimiser'),
                     "Code" => self::ERR_UNKNOWN, "Debug" => ' (SERVER RESPONSE: ' . json_encode($response) . ')');*/
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
  } // handleResponse



  /*  HandleSucces
  *
  * @param Object $item MediaItem that has been optimized
  * @param Object $response The API Response with opt. info.
  * @return ObjectArray $results The Result of the optimization
  */
  private function handleSuccess($item, $response) {
      //Log::addDebug('Shortpixel API : Handling Success!', $response);
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

      /** @todo - What does this mean?  */
      $archive = /*false &&*/
          ($settings->downloadArchive == self::DOWNLOAD_ARCHIVE && class_exists('PharData') && isset($APIresponse[count($APIresponse) - 1]->ArchiveStatus))
          ? $this->downloadArchive($APIresponse[count($APIresponse) - 1], $compressionType) : false;
      if($archive !== false && $archive['Status'] !== self::STATUS_SUCCESS) {
          return $archive;
      }

      $tempFiles = $responseFiles = $results = array();

      //download each file from array and process it
      foreach ( $response as $fileData )
      {

          if(!isset($fileData->Status)) continue; //if optimized images archive is activated, last entry of APIResponse if the Archive data.

          if ( $fileData->Status->Code == self::STATUS_SUCCESS ) //file was processed OK
          {
              // ** @todo Fix fromArchive, figure out how it works */
              if($archive) {
                  $downloadResult = $this->fromArchive($archive['Path'], $fileData->$fileType, $fileData->$fileSize, $fileData->OriginalSize
                );

              } else {
                  $downloadResult = $this->handleDownload($fileData->$fileType, $fileData->$fileSize, $fileData->OriginalSize
                );
              }

              /* Status_Unchanged will be caught by ImageModel and not copied ( should be ).
              * @todo Write Unit Test for Status_unchanged
              * But it should still be regarded as File Done. This can happen on very small file ( 6pxX6px ) which will not optimize.
              */
              if ( $downloadResult->apiStatus == self::STATUS_SUCCESS || $downloadResult->apiStatus == self::STATUS_UNCHANGED ) {
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
                  if (isset($fileData->$webpType))
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
                  if (isset($fileData->$avifType))
                  {
                    $avifName = $originalFile->getFileBase() . '.webp'; ;

                    if($archive) { // swallow pride here, or fix this.
                        $avifDownloadResult = $this->fromArchive($archive['Path'], $fileData->$avifType, false,false);
                    } else {
                        $avifDownloadResult = $this->handleDownload($fileData->$avifType, false, false);
                    }

                    if ( $avifDownloadResult->apiStatus == self::STATUS_SUCCESS)
                    {
                       Log::addDebug('Downloaded Webp : ' . $fileData->$avifType);
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

      Log::addTemp("Adding $fileCount files to stats, $originalSpace went to $optimizedSpace ($savedSpace)");

      // *******************************

      return $this->returnSuccess($results, self::STATUS_SUCCESS, false);

      //figure out in what SubDir files should land
  //    $mainPath = $itemHandler->getMeta()->getPath();

      //if backup is enabled - we try to save the images
    /* Done by Handler in Imagemodels
      if( $settings->backupImages )
      {
        // @todo Rewrite this to FileSystemController
          $backupStatus = self::backupImage($mainPath, $PATHs);
          Log::addDebug('Status', $backupStatus);
          if($backupStatus == self::STATUS_FAIL) {
              $itemHandler->incrementRetries(1, self::ERR_SAVE_BKP, $backupStatus["Message"]);
              self::cleanupTemporaryFiles($archive, empty($tempFiles) ? array() : $tempFiles);
              Log::addError('Failed to create image backup!', array('status' => $backupStatus));
              return array("Status" => self::STATUS_FAIL, "Code" =>"backup-fail", "Message" => "Failed to back the image up.");
          }
          $NoBackup = false;
      }//end backup section */

      $writeFailed = 0;
      $width = $height = null;
      $do_resize = $this->_settings->resizeImages;
      $retinas = 0;
      $thumbsOpt = 0;
      $thumbsOptList = array();
      // The settings model.
//      $settings = \wpSPIO()->settings();


      //Log::addDebug($tempFiles);
      // Check and Run all tempfiles. Move it to appropiate places.
      if ( !empty($tempFiles) )
      {
          //overwrite the original files with the optimized ones
          foreach ( $tempFiles as $tempFileID => $tempFile )
          {
              if(!is_array($tempFile)) continue;

              $targetFile = $fs->getFile($PATHs[$tempFileID]);
              $isRetina = ShortPixelMetaFacade::isRetina($targetFile->getFullPath()); // @todo See what this does

              // @todo What is status unchanged here?
              if(   ($tempFile['Status'] == self::STATUS_UNCHANGED || $tempFile['Status'] == self::STATUS_SUCCESS) && !$isRetina
                 && $targetFile->getFullPath() !== $mainPath) {
                  $thumbsOpt++;
                  $thumbsOptList[] = self::MB_basename($targetFile->getFullPath());
              }

              if($tempFile['Status'] == self::STATUS_SUCCESS) { //if it's unchanged it will still be in the array but only for WebP (handled below)
                  $tempFilePATH = $fs->getFile($tempFile["Message"]);

                  //@todo Move file logic to use FS controller / fileModel.
                  if ( $tempFilePATH->exists() && (! $targetFile->exists() || $targetFile->is_writable()) ) {
                    //  copy($tempFilePATH, $targetFile);
                      $tempFilePATH->move($targetFile);

                      if(ShortPixelMetaFacade::isRetina($targetFile->getFullPath())) {
                          $retinas ++;
                      }
                      if($do_resize && $itemHandler->getMeta()->getPath() == $targetFile->getFullPath() ) { //this is the main image
                          $size = getimagesize($PATHs[$tempFileID]);
                          $width = $size[0];
                          $height = $size[1];
                      }
                      //Calculate the saved space
                      $fileData = $APIresponse[$tempFileID];
                      $savedSpace += $fileData->OriginalSize - $fileData->$fileSize;
                      $originalSpace += $fileData->OriginalSize;
                      $optimizedSpace += $fileData->$fileSize;
                      //$averageCompression += $fileData->PercentImprovement;
                      Log::addInfo("HANDLE SUCCESS: Image " . $PATHs[$tempFileID] . " original size: ".$fileData->OriginalSize . " optimized: " . $fileData->$fileSize);

                      //add the number of files with < 5% optimization
                      if ( ( ( 1 - $APIresponse[$tempFileID]->$fileSize/$APIresponse[$tempFileID]->OriginalSize ) * 100 ) < 5 ) {
                          $this->_settings->under5Percent++;
                      }
                  }
                  else {
                      if($archive &&  SHORTPIXEL_DEBUG === true) {
                          if(! $tempFilePATH->exists()) {
                              Log::addWarn("MISSING FROM ARCHIVE. tempFilePath: " . $tempFilePATH->getFullPath() . " with ID: $tempFileID");
                          } elseif(! $targetFile->is_writable() ){
                              Log::addWarn("TARGET NOT WRITABLE: " . $targetFile->getFullPath() );
                          }
                      }
                      $writeFailed++;
                  }
                  //@unlink($tempFilePATH); // @todo Unlink is risky due to lack of checks.
                //  $tempFilePath->delete();
              }

              $tempWebpFilePATH = $fs->getFile($tempFile["WebP"]);
              if( $tempWebpFilePATH->exists() ) {
                  $targetWebPFileCompat = $fs->getFile($targetFile->getFileDir() . $targetFile->getFileName() . '.webp');
                  /*$targetWebPFileCompat = dirname($targetFile) . '/'. self::MB_basename($targetFile, '.' . pathinfo($targetFile, PATHINFO_EXTENSION)) . ".webp"; */

                  $targetWebPFile = $fs->getFile($targetFile->getFileDir() . $targetFile->getFileBase() . '.webp');
                  //if the Targetfile already exists, it means that there is another file with the same basename but different extension which has its .webP counterpart save it with double extension
                  if(SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION || $targetWebPFile->exists()) {
                      $tempWebpFilePATH->move($targetWebPFileCompat);
                  } else {
                      $tempWebpFilePATH->move($targetWebPFile);
                  }
              }
          } // / For each tempFile
          self::cleanupTemporaryFiles($archive, $tempFiles);

          if ( $writeFailed > 0 )//there was an error
          {

            /*  Log::addDebug("ARCHIVE HAS MISSING FILES. EXPECTED: " . json_encode($PATHs)
                              . " AND: " . json_encode($APIresponse)
                              . " GOT ARCHIVE: " . $APIresponse[count($APIresponse) - 1]->ArchiveURL . " LOSSLESS: " . $APIresponse[count($APIresponse) - 1]->ArchiveLosslessURL
                              . " CONTAINING: " . json_encode(scandir($archive['Path']))); */
              Log::addDebug('Archive files missing (expected paths, response)', array($PATHs, $APIresponse));

              $msg = sprintf(__('Optimized version of %s file(s) couldn\'t be updated.','shortpixel-image-optimiser'),$writeFailed);
              $itemHandler->incrementRetries(1, self::ERR_SAVE, $msg);
              $this->_settings->bulkProcessingStatus = "error";
              return array("Status" => self::STATUS_FAIL, "Code" =>"write-fail", "Message" => $msg);
          }
      } elseif( 0 + $fileData->PercentImprovement < 5) {
          $this->_settings->under5Percent++;
      }
      //old average counting
      $this->_settings->savedSpace += $savedSpace;
      //$averageCompression = $this->_settings->averageCompression * $this->_settings->fileCount /  ($this->_settings->fileCount + count($APIresponse));
      //$this->_settings->averageCompression = $averageCompression;
      $this->_settings->fileCount += count($APIresponse);
      //new average counting
      $this->_settings->totalOriginal += $originalSpace;
      $this->_settings->totalOptimized += $optimizedSpace;

      //update metadata for this file
      $meta = $itemHandler->getMeta();

      if($meta->getThumbsTodo()) {
          $percentImprovement = $meta->getImprovementPercent();
      }
      $png2jpg = $meta->getPng2Jpg();
      $png2jpg = is_array($png2jpg) ? $png2jpg['optimizationPercent'] : 0;
      $meta->setMessage($originalSpace
              ? number_format(100.0 * (1.0 - $optimizedSpace / $originalSpace), 2)
              : "Couldn't compute thumbs optimization percent. Main image: " . $percentImprovement);
      WPShortPixel::log("HANDLE SUCCESS: Image optimization: ".$meta->getMessage());
      $meta->setCompressionType($compressionType);
      $meta->setCompressedSize(@filesize($meta->getPath()));
      $meta->setKeepExif($this->_settings->keepExif);
      $meta->setCmyk2rgb($this->_settings->CMYKtoRGBconversion);
      $meta->setTsOptimized(date("Y-m-d H:i:s"));
      $meta->setThumbsOptList(is_array($meta->getThumbsOptList()) ? array_unique(array_merge($meta->getThumbsOptList(), $thumbsOptList)) : $thumbsOptList);
      $meta->setThumbsOpt(($meta->getThumbsTodo() ||  $this->_settings->processThumbnails) ? count($meta->getThumbsOptList()) : 0);
      $meta->setRetinasOpt($retinas);
      if(null !== $this->_settings->excludeSizes) {
          $meta->setExcludeSizes($this->_settings->excludeSizes);
      }
      $meta->setThumbsTodo(false);
      //* Not yet as it doesn't seem to work... */$meta->addThumbs($webpSizes);
      if($width && $height) {
          $meta->setActualWidth($width);
          $meta->setActualHeight($height);
      }

      $meta->setRetries($meta->getRetries() + 1);
      $meta->setBackup(!$NoBackup);
      $meta->setStatus(2);

      if ($do_resize)
      {

        $resizeWidth = $settings->resizeWidth;
        $resizeHeight = $settings->resizeHeight;

        if ($resizeWidth == $width || $resizeHeight == $height)  // resized.
        {
            $meta->setResizeWidth($width);
            $meta->setResizeHeight($height);
            $meta->setResize(true);
        }
        else
          $meta->setResize(false);
      }

      $itemHandler->updateMeta($meta);
      $itemHandler->optimizationSucceeded();
      Log::addDebug("HANDLE SUCCESS: Metadata saved.");

      if(!$originalSpace) { //das kann nicht sein, alles klar?!
          throw new Exception("OriginalSpace = 0. APIResponse" . json_encode($APIresponse));
      }

      //we reset the retry counter in case of success
      $this->_settings->apiRetries = 0;

      return array("Status" => self::STATUS_SUCCESS, "Message" => 'Success: No pixels remained unsqueezed :-)',
          "PercentImprovement" => $originalSpace
          ? number_format(100.0 * (1.0 - (1.0 - $png2jpg / 100.0) * $optimizedSpace / $originalSpace), 2)
          : "Couldn't compute thumbs optimization percent. Main image: " . $percentImprovement);
  }//end handleSuccess

  /** If SPIO API returns archive and host supports it, uncompress it here */
  private function downloadArchive($archive, $compressionType, $first = true) {
      if($archive->ArchiveStatus->Code == 1 || $archive->ArchiveStatus->Code == 0) { // @todo Put constants on these
        //  return array("Status" => self::STATUS_RETRY, "Code" => 1, "Message" => "Pending");
        return $this->returnRetry(self::STATUS_RETRY, __('Pending', 'shortpixel-image-optimiser'));
      }

      if($archive->ArchiveStatus->Code != self::STATUS_SUCCESS)
        return false;

      $archiveTempDir = get_temp_dir() . '/' . $archiveBasename;
      $fs = \wpSPIO()->filesystem();
      $tempDir = $fs->getDirectory($archiveTempDir);
      $tempDir->check();// try to create temporary folder

      if( $tempDir->exists() && (time() - $tempDir->getModified() < max(30, SHORTPIXEL_MAX_EXECUTION_TIME) + 10)) {
          Log::addWarn("CONFLICT. Folder already exists and is modified in the last minute. Current IP:" . $_SERVER['REMOTE_ADDR']);
          //return array("Status" => self::STATUS_RETRY, "Code" => 1, "Message" => "Pending");
          return $this->returnRetry(self::STATUS_RETRY, __('Pending. Temp directory already in use', 'shortpixel-image-optimiser'));
      }
      elseif( ! $tempDir->exists() ) {
          //return array("Status" => self::STATUS_ERROR, "Code" => self::ERR_SAVE, "Message" => "Could not create temporary folder.");
          $this->returnFailure(self::STATUS_ERROR, __("Could not create temporary folder", 'shortpixel-image-optimiser'));
      }
      //return array("Status" => self::STATUS_SUCCESS, "Dir" => $tempDir);
///      }

    //  } else {

          $suffix = ($compressionType == 0 ? "-lossless" : "");
          $archiveURL = "Archive" . ($compressionType == 0 ? "Lossless" : "") . "URL";
          $archiveSize = "Archive" . ($compressionType == 0 ? "Lossless" : "") . "Size";

        //  $archiveTemp = $this->createArchiveTempFolder(wp_basename($archive->$archiveURL, '.tar'));
          //if($archiveTemp["Status"] == self::STATUS_SUCCESS) { $archiveTempDir = $archiveTemp["Dir"]; }
        //  else { return $archiveTemp; }

          $downloadResult = $this->handleDownload($archive->$archiveURL, $archive->$archiveSize, 0, 'NA');

          if ( $downloadResult->status == self::STATUS_SUCCESS ) {
              $archiveFile = $downloadResult['Message'];
              if(filesize($archiveFile) !== $archive->$archiveSize) {
                  @unlink($archiveFile);
                  ShortpixelFolder::deleteFolder($archiveTempDir);
                  Log::addWarn('Download Failed, archive not same size as remote');
                  return array("Status" => self::STATUS_RETRY, "Code" => 1, "Message" => "Pending");
              }
              $pharData = new PharData($archiveFile);
              try {

                  $info = "Current IP:" . $_SERVER['REMOTE_ADDR'] . "ARCHIVE CONTENTS: COUNT " . $pharData->count() . ", ";
                  Log::addDebug($info);

                  $pharData->extractTo($archiveTempDir, null, true);
                  WPShortPixel::log("ARCHIVE EXTRACTED " . json_encode(scandir($archiveTempDir)));
                  @unlink($archiveFile);
              } catch (Exception $ex) {
                  @unlink($archiveFile);
                  ShortpixelFolder::deleteFolder($archiveTempDir);
                  return array("Status" => self::STATUS_ERROR, "Code" => $ex->getCode(), "Message" => $ex->getMessage());
              }
              return array("Status" => self::STATUS_SUCCESS, "Code" => 2, "Message" => "Success", "Path" => $archiveTempDir);

          } else {
              WPShortPixel::log("ARCHIVE ERROR (" . $archive->$archiveURL . "): " . json_encode($downloadResult));
              if($first && $downloadResult['Code'] == self::ERR_INCORRECT_FILE_SIZE) {
                  WPShortPixel::log("RETRYING AFTER ARCHIVE ERROR");
                  return $this->downloadArchive($archive, $compressionType, false); // try again, maybe the archive was flushing...
              }
              @rmdir($archiveTempDir); //in the case it was just created and it's empty...
              return array("Status" => $downloadResult['Status'], "Code" => $downloadResult['Code'], "Message" => $downloadResult['Message']);
          }
  //    }
      return false;
  }

  /**
   * handles the download of an optimized image from ShortPixel API
   * @param string $optimizedUrl
   * @param int $optimizedSize  Check optimize and original size for file consistency
   * @param int $originalSize
   * @return array status /message array
   */
  private function handleDownload($optimizedUrl, $optimizedSize = false, $originalSize = false){

    Log::addTemp('Handle Download: ' . $optimizedUrl . ' ( ' . $optimizedSize . ' '  . $originalSize);
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
