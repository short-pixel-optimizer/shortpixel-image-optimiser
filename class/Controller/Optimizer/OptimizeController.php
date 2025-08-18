<?php
namespace ShortPixel\Controller\Optimizer;

use ShortPixel\Controller\Api\RequestManager;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\Model\Queue\QueueItem as QueueItem;

use ShortPixel\Helper\DownloadHelper as DownloadHelper;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Helper\UiHelper as UiHelper;


use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;

use ShortPixel\Model\Converter\Converter as Converter;

use ShortPixel\Controller\AjaxController as AjaxController;
use ShortPixel\Controller\Queue\QueueItems;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\StatsController as StatsController;

class OptimizeController extends OptimizerBase
{

  public function __construct()
  {
    parent::__construct();
    $this->api = ApiController::getInstance();
    $this->apiName = 'optimize';

  }

  public function enQueueItem(QueueItem $qItem, $args = [])
  {
    $queue = $this->getCurrentQueue($qItem);
    
    $qItem->newOptimizeAction($args);


    $status = $queue->addQueueItem($qItem);
    return $status;
  }


  /** Item check before the enqueueing happens 
   * 
   * @param QueueItem  
   * @return boolean 
   */
  public function checkItem(QueueItem $qItem)
  {
    /*  $defaults = array(
          'forceExclusion' => false,
          'action' => 'optimize',
      );
      $args = wp_parse_args($args, $defaults); */

    //$fs = \wpSPIO()->filesystem();

   // $json = $this->getJsonResponse();
    $bool = $this->checkImageModel($qItem);

    if (false === $bool) {
      return false;
    }

    $imageModel = $qItem->imageModel;

    // Manual Optimization order should always go trough
    if ($imageModel->isOptimizePrevented() !== false) {
      $imageModel->resetPrevent();
    }

    $is_processable = $imageModel->isProcessable();
    // Allow processable to be overridden when using the manual optimize button
    if (false === $is_processable && true === $imageModel->isUserExcluded() && true === $qItem->data()->forceExclusion) {
      $imageModel->cancelUserExclusions();
      $is_processable = true;
    }

    // If is not processable and not user excluded (user via this way can force an optimize if needed) then don't do it!
    if (false === $is_processable) {
      $qItem->addResult([
        'message' => $imageModel->getProcessableReason(),
        'is_error' => true,
        'is_done' => true,
        'fileStatus' => ImageModel::FILE_STATUS_ERROR,
      ]);
    } else {
      return true;
    }

    return false;
  }

  public function sendToProcessing(QueueItem $qItem)
  {

    $is_processable = $qItem->imageModel->isProcessable();

    // Allow processable to be overridden when using the manual optimize button - ignore when this happens already to be in queue.

    if (false === $is_processable) {
      // @todo This should be checked.
      if (! is_null($qItem->data()->forceExclusion) && true == $qItem->data()->forceExclusion) {
        $qItem->imageModel->cancelUserExclusions();
      }

    }

    $this->api->processMediaItem($qItem, $qItem->imageModel);

  }

  public function handleAPIResult(QueueItem $qItem)
  {
    $imageModel = $qItem->imageModel;
    $q = $this->getCurrentQueue($qItem); 
    $fs = \wpSPIO()->filesystem();

    $bool = $this->checkImageModel($qItem);
    if (false === $bool) {
      return false;
    }

    $item_id = $qItem->item_id;

    $qItem->addResult(['apiName' => $this->apiName]);

    // @todo this result message won't be.
    /*
    if (property_exists($qItem->result(), 'message') && strlen($qItem->result()->message) > 0) {
      ResponseController::addData($qItem->item_id, 'message', $qItem->result()->message);
    } */


    if (true === $qItem->block()) {

      $qItem->addResult([
        'apiStatus' => RequestManager::STATUS_UNCHANGED,
        'message' => __('Item is waiting (blocked)', 'shortpixel-image-optimiser'),
      ]);
      Log::addWarn('Encountered blocked item, processing success? ', $item_id);
    } else {
      // This used in bulk preview for formatting filename.
      $qItem->addResult(
        ['filename' => $imageModel->getFileName()]
      );

      // Used in WP-CLI
      ResponseController::addData($item_id, 'fileName', $imageModel->getFileName());
    }

    $quotaController = QuotaController::getInstance();
    $statsController = StatsController::getInstance();

    if (true === $qItem->result()->is_error) {
      Log::addWarn('OptimizeControl - Item has Error', $qItem->result());
      // Check ApiStatus, and see what is what for error
      // https://shortpixel.com/api-docs
      $apistatus = $qItem->result()->apiStatus;

      if ($apistatus == RequestManager::STATUS_ERROR) // File Error - between -100 and -300
      {
        $qItem->addResult(['fileStatus' => ImageModel::FILE_STATUS_ERROR]);
      }
      // Out of Quota (partial / full)
      elseif ($apistatus == RequestManager::STATUS_QUOTA_EXCEEDED) {
        $error_code = AjaxController::NOQUOTA;
        $quotaController->setQuotaExceeded();
      } elseif ($apistatus == RequestManager::STATUS_NO_KEY) {
        $error_code = AjaxController::APIKEY_FAILED;
      } elseif ($apistatus == RequestManager::STATUS_QUEUE_FULL || $apistatus == RequestManager::STATUS_MAINTENANCE) // Full Queue / Maintenance mode
      {
        $error_code = AjaxController::SERVER_ERROR;
      }

      if (isset($error_code)) {
        $qItem->addResult(['error' => $error_code, 'is_error' => true]);
      }

      $response = array(
        'is_error' => true,
        'message' => $qItem->result()->message, // These mostly come from API
      );
      ResponseController::addData($item_id, $response);

      if ($qItem->result()->is_done) {
        $q->itemFailed($qItem, true);
        $this->HandleItemError($qItem);

        ResponseController::addData($qItem->item_id, 'is_done', true);
      }

    } elseif (true === $qItem->result()->is_done) {
      if ($qItem->result()->apiStatus == RequestManager::STATUS_SUCCESS) // Is done and with success
      {

        $tempFiles = [];

        // Set the metadata decided on APItime.
        if (false === is_null($qItem->data()->compressionType)) {
          $imageModel->setMeta('compressionType', $qItem->data()->compressionType);
        }
        else
        {
          Log::addWarn('Compression Type not set on handleSuccess!');
        }

        if (is_array($qItem->result()->files) && count($qItem->result()->files) > 0) {
          $status = $this->handleOptimizedItem($qItem, $imageModel);
          $qItem->addResult(['improvements' => $imageModel->getImprovements()]);

          if (RequestManager::STATUS_SUCCESS == $status) {
            $qItem->addResult([
              'apiStatus' => RequestManager::STATUS_SUCCESS,
              'fileStatus' => ImageModel::FILE_STATUS_SUCCESS
            ]);

            do_action('shortpixel_image_optimised', $item_id);
            do_action('shortpixel/image/optimised', $imageModel);
          } elseif (RequestManager::STATUS_CONVERTED == $status) {
            $qItem->addResult([
              'apiStatus' => RequestManager::STATUS_CONVERTED,
              'fileStatus' => ImageModel::FILE_STATUS_SUCCESS
            ]);

            $imageModel = $fs->getMediaImage($qItem->item_id);

            if (! is_null($qItem->data()->compressionTypeRequested)) {
              $qItem->setData('compressionType', $qItem->data()->compressionTypeRequested);
            }
            // Keep compressiontype from object, set in queue, imageModelToQueue
            //$imageModel->setMeta('compressionType', $qItem->data()->compressionType);
          } else {

            $qItem->addResult([
              'apiStatus' => RequestManager::STATUS_ERROR,
              'fileStatus' => ImageModel::FILE_STATUS_ERROR,
              'is_error' => true,
            ]);

          }

          /*$showItem = UiHelper::findBestPreview($imageModel); // find smaller / better preview
          $original = $optimized = false;

          if ($showItem->getExtension() == 'pdf') // non-showable formats here
          {
            //								 $item->result->original = false;
//								 $item->result->optimized = false;
          } elseif ($showItem->hasBackup()) {
            $backupFile = $showItem->getBackupFile(); // attach backup for compare in bulk
            $backup_url = $fs->pathToUrl($backupFile);
            $original = $backup_url;
            $optimized = $fs->pathToUrl($showItem);
          } else {
            $original = false;
            $optimized = $fs->pathToUrl($showItem);
          }

          $qItem->addResult([
            'original' => $original,
            'optimized' => $optimized,
          ]);*/
          $this->addPreview($qItem);

          // Dump Stats, Dump Quota. Refresh
          $statsController->reset();

          $this->deleteTempFiles($qItem);

        }
        // This was not a request process, just handle it and mark it as done.
        elseif ($qItem->result()->apiStatus == RequestManager::STATUS_NOT_API) {
          // Nothing here.
        } else {
          Log::addWarn('Api returns Success, but result has no files', $qItem->result());
          $message = sprintf(__('Image API returned succes, but without images', 'shortpixel-image-optimiser'), $item_id);
          ResponseController::addData($item_id, 'message', $message);
          $qItem->addResult(['is_error' => true, 'apiStatus' => RequestManager::STATUS_FAIL]);
        }


      }  // Is Done / Handle Success

      // This is_error can happen not from api, but from handleOptimized
      if ($qItem->result()->is_error) {
        Log::addDebug('Item failed, has error on done ', $qItem->result());
        $q->itemFailed($qItem, true);
        $this->HandleItemError($qItem);
      } else {

        // *** RESEND TO PROCESS MORE *** 
        // If this keeps giving issues, probably some trigger is needed and move to QueueController instead.
        // @todo In future check if we can more relaible do this via finishItem process. 
        if ($imageModel->isProcessable() && $qItem->result()->apiStatus !== RequestManager::STATUS_NOT_API) {
          Log::addDebug('Item with ID' . $item_id . ' still has processables (with dump)', $imageModel->getOptimizeUrls());

          $api = $this->api;

          $optimize_args = []; 
          if (! is_null($qItem->data()->compressionType))
          {
            $optimize_args['compressionType'] = $qItem->data()->compressionType; 
          }
          if (! is_null($qItem->data()->smartcrop))
          {
            $optimize_args['smartcrop'] = $qItem->data()->smartcrop; 
          }

          // It can happen that only webp /avifs are left for this image. This can't influence the API cache, so dump is not needed. Just don't send empty URLs for processing here.
          $api->dumpMediaItem($qItem);

          // Fetch a new qItem, because of all the left-over-data . Left the old one alone for reporting
          $new_qItem = QueueItems::getImageItem($imageModel);
                    
          $this->enQueueItem($new_qItem, $optimize_args); // requeue for further processing.

        } elseif (RequestManager::STATUS_CONVERTED !== $qItem->result()->apiStatus) {
              $this->finishItemProcess($qItem);
//          $q->itemDone($qItem); // Unbelievable but done.
        }
      }
    } else {
      if ($qItem->result()->apiStatus == ApiController::STATUS_UNCHANGED || $qItem->result()->apiStatus === Apicontroller::STATUS_PARTIAL_SUCCESS) {
        $qItem->addResult(['fileStatus' => ImageModel::FILE_STATUS_PENDING]);
        $retry_limit = $q->getShortQ()->getOption('retry_limit');

        if ($qItem->result()->apiStatus === ApiController::STATUS_PARTIAL_SUCCESS) {
          if (property_exists($qItem->result(), 'files') && count($qItem->result()->files) > 0) {
            $this->handleOptimizedItem($qItem, $imageModel);
          } else {
            Log::addWarn('Status is partial success, but no files followed. ', $qItem->result());
          }

          // Let frontend follow unchanged / waiting procedure.
          $qItem->addResult(['apiStatus' => ApiController::STATUS_UNCHANGED]);
        }

        if ($retry_limit == $qItem->data()->tries || $retry_limit == ($qItem->data()->tries - 1)) {
          $message = __('Retry Limit reached. Image might be too large, limit too low or network issues.  ', 'shortpixel-image-optimiser');

          ResponseController::addData($item_id, 'message', $message);
          ResponseController::addData($item_id, 'is_error', true);
          ResponseController::addData($item_id, 'is_done', true);

          $qItem->addResult([
            'apiStatus' => ApiController::ERR_TIMEOUT,
            'message' => $message,
            'is_error' => true,
            'is_done' => true,
          ]);

          $this->HandleItemError($qItem);

          // @todo Remove temp files here
        } else {
          //ResponseController::addData($item_id, 'message', $qItem->result()->message); // item is waiting base line here.
        }
        /* Item is not failing here:  Failed items come on the bottom of the queue, after all others so might cause multiple credit eating if the time is long. checkQueue is only done at the end of the queue.
         * Secondly, failing it, would prevent it going to TIMEOUT on the PROCESS in WPQ - which would mess with correct timings on that.
         */
        //  $q->itemFailed($item, false); // register as failed, retry in x time, q checks timeouts
      }
    }

    // Cleaning up the debugger.
    $debugItem = clone $qItem; 

    Log::addDebug('Optimizecontrol - QueueItem has a result ', $debugItem->result());


    ResponseController::addData($item_id, [
      'is_error' => $qItem->result()->is_error,
      'is_done' => $qItem->result()->is_done,
      'apiStatus' => $qItem->result()->apiStatus,
      'tries' => $qItem->data()->tries,
    ]);

    if (property_exists($qItem->result(), 'fileStatus')) {
      ResponseController::addData($item_id, 'fileStatus', $qItem->result()->fileStatus);
    }

    // For now here, see how that goes
    $responseMessage = ResponseController::formatItem($item_id);
    if ($responseMessage !== false && strlen($responseMessage) > 0)
    {
      $qItem->addResult([
        'message' => $responseMessage,
      ]);
    }

    if ($qItem->result()->is_error) {
      $qItem->addResult([
        'kblink' => UiHelper::getKBSearchLink($qItem->result()->message)
      ]);
    }
  }

  protected function HandleItemError(QueueItem $qItem)
  {
    return;
  }

  /**
   * [Handles one optimized image and extra filetypes]
   * @param  [object] $q                         [queue object]
   * @param  [object] $item                      [item QueueItem object. The data item]
   * @param  [object] $mediaObj                  [imageModel of the optimized collection]
   * @param  [array] $successData               [all successdata received so far]
   * @return int           status integer, one of apicontroller status constants
   */
  protected function handleOptimizedItem($qItem, $imageModel)
  {
    $imageArray = $qItem->result()->files;

    $downloadHelper = DownloadHelper::getInstance();
    $converter = Converter::getConverter($imageModel, true);

    $qItem->block(true);

    $q = $this->currentQueue;
    $q->updateItem($qItem);

    $item_id = $qItem->item_id;

    // @todo Here check if these item_files are persistent ( probablY ) and if so add them to data(), not result();
    // @todo This should be a temporary cast. Perhaps best to include this in QueueItem object with extra functions / checks implementable?
    if (! is_null($qItem->data()->files)) {
      $item_files = (array) $qItem->data()->files;
    } else {
      $item_files = [];
    }


    foreach ($imageArray as $imageName => $image) {
      if (!isset($item_files[$imageName])) {
        $item_files[$imageName] = [];
      }

      if (isset($item_files[$imageName]['image']) && file_exists($item_files[$imageName]['image'])) {
        // All good.
      }
      // If status is success.  When converting (API) allow files that are bigger
      elseif (
        $image['image']['status'] == ApiController::STATUS_SUCCESS ||
        ($image['image']['status'] == ApiController::STATUS_OPTIMIZED_BIGGER && is_object($converter))
      ) {
        $tempFile = $downloadHelper->downloadFile($image['image']['url']);
        if (is_object($tempFile)) {
          $item_files[$imageName]['image'] = $tempFile->getFullPath();
          $imageArray[$imageName]['image']['file'] = $tempFile->getFullPath();
        }
      }


      if (!isset($item_files[$imageName]['webp']) && $image['webp']['status'] == ApiController::STATUS_SUCCESS) {
        $tempFile = $downloadHelper->downloadFile($image['webp']['url']);
        if (is_object($tempFile)) {
          $item_files[$imageName]['webp'] = $tempFile->getFullPath();
          $imageArray[$imageName]['webp']['file'] = $tempFile->getFullPath();
        }
      } elseif ($image['webp']['status'] == ApiController::STATUS_OPTIMIZED_BIGGER) {
        $item_files[$imageName]['webp'] = ApiController::STATUS_OPTIMIZED_BIGGER;
      }
      elseif ($image['webp']['status'] == ApiController::STATUS_NOT_COMPATIBLE) {
       $item_files[$imageName]['webp'] = ApiController::STATUS_NOT_COMPATIBLE;
      }
      //STATUS_NOT_COMPATIBLE

      if (!isset($item_files[$imageName]['avif']) && $image['avif']['status'] == ApiController::STATUS_SUCCESS) {
        $tempFile = $downloadHelper->downloadFile($image['avif']['url']);
        if (is_object($tempFile)) {
          $item_files[$imageName]['avif'] = $tempFile->getFullPath();
          $imageArray[$imageName]['avif']['file'] = $tempFile->getFullPath();
        }
      } elseif ($image['avif']['status'] == ApiController::STATUS_OPTIMIZED_BIGGER) {
        $item_files[$imageName]['avif'] = ApiController::STATUS_OPTIMIZED_BIGGER;
      }
      elseif ($image['avif']['status'] == ApiController::STATUS_NOT_COMPATIBLE) {
        $item_files[$imageName]['avif'] = ApiController::STATUS_NOT_COMPATIBLE;
      }
    }

    $successData['files'] = $imageArray;
    // Bit strange but imageModel currently expects this.
    $successData['data'] = $qItem->result()->data;
    $qItem->setData('files', $item_files);

    $converter = Converter::getConverter($imageModel, true);

    if (is_object($converter) && $converter->isConverterFor('api')) {
      $optimizedResult = $converter->handleConverted($successData);
      if (true === $optimizedResult) {
        ResponseController::addData($item_id, 'message', __('File Converted', 'shortpixel-image-optimiser'));
        $status = ApiController::STATUS_CONVERTED;

      } else {
        ResponseController::addData($item_id, 'message', __('File conversion failed.', 'shortpixel-image-optimiser'));
        $q->itemFailed($qItem, true);
        Log::addError('File conversion failed with data ', $successData);
        $status = ApiController::STATUS_FAIL;
      }

    } else {
      if (is_object($converter)) {
        $successData = $converter->handleConvertedFilter($successData);
      }

      $optimizedResult = $imageModel->handleOptimized($successData);
      if (true === $optimizedResult)
        $status = ApiController::STATUS_SUCCESS;
      else {
        $status = ApiController::STATUS_FAIL;
      }
    }

    $qItem->block(false);
    $q->updateItem($qItem);

    return $status;
  }

  protected function deleteTempFiles(QueueItem $qItem)
  {
    if (! is_null($qItem->data()->files)) {
      return false;
    }

    $files = $qItem->files;
    $fs = \wpSPIO()->filesystem();

    foreach ($files as $name => $data) {
      foreach ($data as $tmpPath) {
        if (is_numeric($tmpPath)) // Happens when result is bigger status is set.
          continue;

        $tmpFile = $fs->getFile($tmpPath);
        if ($tmpFile->exists())
          $tmpFile->delete();
      }
    }

  }



} // class
