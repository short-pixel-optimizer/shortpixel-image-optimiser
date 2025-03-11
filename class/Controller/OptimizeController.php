<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;

use ShortPixel\Controller\AjaxController as AjaxController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\StatsController as StatsController;

use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\Api\AiController as AiController;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\QueueItem as QueueItem;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;


use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\DownloadHelper as DownloadHelper;

use ShortPixel\Model\Converter\Converter as Converter;

class OptimizeController
{
    protected $isBulk = false; // if queueSystem should run on BulkQueues;

//		protected static $lastId; // Last item_id received / send. For catching errors.

    // If OptimizeController should use the bulkQueues.
    // @todo Should be in constructor.
    /*public function setBulk($bool)
    {
       $this->isBulk = $bool;
    } */

		/** Gets the correct queue type */
    // @todo Check how much this is called during run. Perhaps cachine q's instead of new object everytime is more efficient.
    /*
    public function getQueue($type)
    {
        $queue = null;

        if ($type == 'media')
        {
            $queueName = ($this->isBulk == true) ? 'media' : 'mediaSingle';
            $queue = new MediaLibraryQueue($queueName);
        }
        elseif ($type == 'custom')
        {
          $queueName = ($this->isBulk == true) ? 'custom' : 'customSingle';
          $queue = new CustomQueue($queueName);
        }
				else
				{
					Log::addInfo("Get Queue $type seems not a queue");
					return false;
				}

        $options = $queue->getCustomDataItem('queueOptions');
        if ($options !== false)
        {
            $queue->setOptions($options);
        }
        return $queue;
    } */

    // Queuing Part
    /* Add Item to Queue should be used for starting manual Optimization
    * Enqueue a single item, put it to front, remove duplicates.
		* @param Object $mediaItem
    @return int Number of Items added

    // Delegate to Optimizers via this func.
    */
   /*
    public function addItemToQueue(ImageModel $mediaItem, $args = [])
    {
        $defaults = array(
            'forceExclusion' => false,
            'action' => 'optimize',
        );
        $args = wp_parse_args($args, $defaults);

        $fs = \wpSPIO()->filesystem();

				$json = $this->getJsonResponse();
        $json->status = 0;
        $json->result = new \stdClass;

				if (! is_object($mediaItem))  // something wrong
				{
					$json->result = new \stdClass;
					$json->result->message = __("File Error. File could not be loaded with this ID ", 'shortpixel-image-optimiser');
					$json->result->apiStatus = ApiController::STATUS_NOT_API;
					$json->fileStatus = ImageModel::FILE_STATUS_ERROR;
					$json->result->is_done = true;
					$json->result->is_error = true;

					ResponseController::addData($item->item_id, 'message', $item->result->message);

					Log::addWarn('Item with id ' . $json->result->item_id . ' is not restorable,');
					 return $json;
				}

        $id = $mediaItem->get('id');
        $type = $mediaItem->get('type');

        $json->result->item_id = $id;

        // Manual Optimization order should always go trough
        if ($mediaItem->isOptimizePrevented() !== false)
        {
            $mediaItem->resetPrevent();
        }

        $queue = $this->getQueue($mediaItem->get('type'));


        $is_processable = $mediaItem->isProcessable();
        // Allow processable to be overridden when using the manual optimize button
        if (false === $is_processable && true === $mediaItem->isUserExcluded() && true === $args['forceExclusion'] )
        {
          $mediaItem->cancelUserExclusions();
          $is_processable = true;
        }

        // If is not processable and not user excluded (user via this way can force an optimize if needed) then don't do it!
        if (false === $is_processable)
        {
          $json->result->message = $mediaItem->getProcessableReason();
          $json->result->is_error = false;
          $json->result->is_done = true;
          $json->result->fileStatus = ImageModel::FILE_STATUS_ERROR;
        }
				elseif($queue->isDuplicateActive($mediaItem))
				{
					$json->result->fileStatus = ImageModel::FILE_STATUS_UNPROCESSED;
					$json->result->is_error = false;
					$json->result->is_done = true;
					$json->result->message = __('A duplicate of this item is already active in queue. ', 'shortpixel-image-optimiser');
				}
        else
        {
          $result = $queue->addSingleItem($mediaItem, $args); // 1 if ok, 0 if not found, false is not processable

          // @todo This is flawed, the last block will overwrite message /   that's done here?
          if ($result->numitems > 0)
          {
            $json->result->message = sprintf(__('Item %s added to Queue. %d items in Queue', 'shortpixel-image-optimiser'), $mediaItem->getFileName(), $result->numitems);
            $json->status = 1;

            // Check if background process is active / this needs activating.
            $cronController = CronController::getInstance();
            $cronController->checkNewJobs();
          }
          else
          {
            $json->message = __('No items added to queue', 'shortpixel-image-optimiser');
            $json->status = 0;
          }

            $json->qstatus = $result->qstatus;
            $json->result->fileStatus = ImageModel::FILE_STATUS_PENDING;
            $json->result->is_error = false;
            $json->result->message = __('Item has been added to the queue and will be optimized on the next run', 'shortpixel-image-optimiser');
        }

        return $json;
    }
*/

		/** Check if item is in queue. || Only checks the single queue!
		* @param Object $mediaItem
		*/
  /*
    public function isItemInQueue(ImageModel $mediaItem)
		{
				if (! is_null($mediaItem->is_in_queue))
					return $mediaItem->is_in_queue;

				$type = $mediaItem->get('type');

			  $q = $this->getQueue($type);

				$bool = $q->isItemInQueue($mediaItem->get('id'));

			  // Preventing double queries here
				$mediaItem->is_in_queue = $bool;
				return $bool;
    }*/

		/** Restores an item
		*
		* @param Object $mediaItem
		*/
  /*
    public function restoreItem(ImageModel $mediaItem)
    {
        $fs = \wpSPIO()->filesystem();

        $json = $this->getJsonResponse();
        $json->status = 0;
        $json->result = new \stdClass;

				$item_id = $mediaItem->get('id');

        if (! is_object($mediaItem))  // something wrong
        {

					$json->result = new \stdClass;
					$json->result->message = __("File Error. File could not be loaded with this ID ", 'shortpixel-image-optimiser');
					$json->result->apiStatus = ApiController::STATUS_NOT_API;
					$json->fileStatus = ImageModel::FILE_STATUS_ERROR;
					$json->result->is_done = true;
					$json->result->is_error = true;

					ResponseController::addData($item_id, 'is_error', true);
					ResponseController::addData($item_id, 'is_done', true);
					ResponseController::addData($item_id, 'message', $item->result->message);

          Log::addWarn('Item with id ' . $item_id . ' is not restorable,');

          return $json;
        }

				$data = array(
					'item_type' => $mediaItem->get('type'),
					'fileName' => $mediaItem->getFileName(),
				);
				ResponseController::addData($item_id, $data);

        $item_id = $mediaItem->get('id');
				$json->result->item_id = $item_id;

				$optimized = $mediaItem->getMeta('tsOptimized');

				if ($mediaItem->isRestorable())
				{
        	$result = $mediaItem->restore();
				}
				else
				{
					 $result = false;
					 $json->result->message = ResponseController::formatItem($mediaItem->get('id')); // $mediaItem->getReason('restorable');
				}

				// Compat for ancient WP
				$now = function_exists('wp_date') ? wp_date( 'U', time() ) : time();

				// Reset the whole thing after that.
				$mediaItem = $fs->getImage($item_id, $mediaItem->get('type'), false);

				// Dump this item from server if optimized in the last hour, since it can still be server-side cached.
				if ( ( $now   - $optimized) < HOUR_IN_SECONDS )
				{
           $api = $this->getAPI('restore');
           //$item = new \stdClass;
           //$item->urls = $mediaItem->getOptimizeUrls();
           $qItem = QueueItems::getImageItem($mediaItem);
           $qItem->newDumpAction();
           $api->dumpMediaItem($qItem);
				}

        if ($result)
        {
           $json->status = 1;
           $json->result->message = __('Item restored', 'shortpixel-image-optimiser');
           $json->fileStatus = ImageModel::FILE_STATUS_RESTORED;
           $json->result->is_done = true;
        }
        else
        {
					 $json->result->message = ResponseController::formatItem($mediaItem->get('id'));
           $json->result->is_done = true;
           $json->fileStatus = ImageModel::FILE_STATUS_ERROR;
           $json->result->is_error = true;
        }

        return $json;
    }
*/
		/** Reoptimize an item
		*
		* @param Object $mediaItem
		*/
  /*
    public function reOptimizeItem(ImageModel $mediaItem, $compressionType, $args = array())
    {
      $json = $this->restoreItem($mediaItem);

      if ($json->status == 1) // successfull restore.
      {
					$fs = \wpSPIO()->filesystem();
					$fs->flushImageCache();

          // Hard reload since metadata probably removed / changed but still loaded, which might enqueue wrong files.
            $mediaItem = $fs->getImage($mediaItem->get('id'), $mediaItem->get('type'), false);
            $mediaItem->setMeta('compressionType', $compressionType);

						if (isset($args['smartcrop']))
						{
							 $mediaItem->doSetting('smartcrop', $args['smartcrop']);
						}

            // This is a user triggered thing. If the whole thing is user excluxed, but one ones this, then ok.
            $args = array();
            if (false === $mediaItem->isProcessable() && true === $mediaItem->isUserExcluded())
            {
               $args['forceExclusion'] = true;
            }


            $json = $this->addItemToQueue($mediaItem, $args);
            return $json;
      }

     return $json;

    } */

    /** Returns the state of the queue so the startup JS can decide if something is going on and what.  **/
    /*
    public function getStartupData()
    {
        $mediaQ = $this->getQueue('media');
        $customQ = $this->getQueue('custom');

        $data = new \stdClass;
        $data->media = new \stdClass;
        $data->custom = new \stdClass;
        $data->total = new \stdClass;

        $data->media->stats = $mediaQ->getStats();
        $data->custom->stats = $customQ->getStats();

        $data->total = $this->calculateStatsTotals($data);
				$data = $this->numberFormatStats($data);

        return $data;
    }
*/
    // Processing Part

    // next tick of items to do.
    /* Processes one tick of the queue
    *
    * @return Object JSON object detailing results of run
    */
   // @todo This is the main function that starts the processing
   /*
    public function processQueue($queueTypes = array())
    {
        $keyControl = ApiKeyController::getInstance();
        if ($keyControl->keyIsVerified() === false)
        {
           $json = $this->getJsonResponse();
           $json->status = false;
           $json->error = AjaxController::APIKEY_FAILED;
           $json->message =  __('Invalid API Key', 'shortpixel-image-optimiser');
           $json->status = false;
           return $json;
        }

        $quotaControl = QuotaController::getInstance();
        if ($quotaControl->hasQuota() === false)
        {
					// If we are doing something special (restore, migrate etc), it should runs without credits, so we shouldn't be using any.
					$isCustomOperation = false;
					foreach($queueTypes as $qType)
					{
						$queue = $this->getQueue($qType);
						if ($queue && true === $queue->isCustomOperation())
						{
								$isCustomOperation = true;
								break;
						}
					}

					// Break out of quota if we are on normal operations.
					if (false === $isCustomOperation )
					{
						$quotaControl->forceCheckRemoteQuota(); // on next load check if something happenend when out and asking.
	          $json = $this->getJsonResponse();
	          $json->error = AjaxController::NOQUOTA;
	          $json->status = false;
	          $json->message =   __('Quota Exceeded','shortpixel-image-optimiser');
	          return $json;
					}
        }

        // @todo Here prevent bulk from running when running flag is off
        // @todo Here prevent a runTick is the queue is empty and done already ( reliably )
        // @todo If once queue exited because of mediaItem, don't run the other one but abort
        $results = new \stdClass;
        if ( in_array('media', $queueTypes))
        {
          $mediaQ = $this->getQueue('media');
          $results->media = $this->runTick($mediaQ); // run once on mediaQ

          $overlimit = (Queue::RESULT_PREPARING_OVERLIMIT === $results->media->qstatus) ? true : false;

        }
        if (false === $overlimit && in_array('custom', $queueTypes))
        {
          $customQ = $this->getQueue('custom');
          $results->custom = $this->runTick($customQ);
        }

        $results->total = $this->calculateStatsTotals($results);
				$results = $this->numberFormatStats($results);

        return $results;
    } */

    /** Run the Queue once with X amount of items, send to processor or handle.  */
    // @todo Call by processQueue
    /*
    protected function runTick($Q)
    {
      $result = $Q->run();
      $results = array();

			ResponseController::setQ($Q);

      // Items is array in case of a dequeue items.
      $items = (isset($result->items) && is_array($result->items)) ? $result->items : array();


      foreach($items as $mainIndex => $item)
      {
             //continue; // conversion done one way or another, item will be need requeuing, because new urls / flag.
						// Note, all these functions change content of QueueItem
						$this->sendToProcessing($item, $Q);

            $this->handleAPIResult($item, $Q);
            $result->items[$mainIndex] = $item->returnObject(); // replace processed item, should have result now.
      }

      $result->stats = $Q->getStats();
      $json = $this->queueToJson($result);
      $this->checkQueueClean($result, $Q);

      return $json;
    }  */


    /** Checks and sends the item to processing
    * @param Object $item Item is a stdClass object from Queue. This is not a model, nor a ShortQ Item.
    * @param Object $q  Queue Object
		*/
  /*
    public function sendToProcessing(QueueItem $item, $q)
    {
			$this->setLastID($item->item_id);

			$fs = \wpSPIO()->filesystem();
			$qtype = $q->getType();
			$qtype = strtolower($qtype);

      // @todo @important The options probable don't work in new setup.
      // Options contained in the queue item for extra uh options  // forceExclusion
			$options = (false === is_null($item->options)) ? $item->options : [];
      $action = (false === is_null($item->data()->action)) ? $item->data()->action : false; // This one is obligatory
      $api = $this->getAPI($action);

Log::addTemp('Action', $action);
      // @todo See if we can do without this after first try.
      $imageObj = (! is_null($item->imageModel)) ? $item->imageModel : $fs->getImage($item->item_id, $qtype);
      if (is_null($item->imageModel))
      {
        Log::addWarn('ImageObject was empty when send to processing - ' . $item->item_id);
      }

			if (is_object($imageObj))
			{
				ResponseController::addData($item->item_id, 'fileName', $imageObj->getFileName());
			}
      else {
         // If image doesn't produce object, bail out.
         return $this->handleAPIResult($item, $q);
      }

      // @todo SendToProcessing - Update the blocked checks
			// If item is blocked (handling success), skip over. This can happen if internet is slow or process too fast.
			if (true === $item->block() )
			{
					$item = $this->handleAPIResult($item, $q);
			}
      elseif (false === $api) // ResultMessages in ResponseController
      {
//            $item->result = new \stdClass;
						$item->setResult([
								'apiStatus' => ApiController::STATUS_NOT_API,
								'is_done' => true,
								'is_error' => false,
						]);
//            $item->result->is_done = true; // always done
//            $item->result->is_error = false; // for now
//            $item->result->apiStatus = ApiController::STATUS_NOT_API;
            ResponseController::addData($item->item_id, 'action', $item->action);

					 if ($imageObj === false) // not exist error.
					 {
					  	$this->handleAPIResult($item, $q);
					 }
           switch($item->action)
           {
              case 'restore';
								 $imageObj->restore(['keep_in_queue' => true]);
              break;
              case 'migrate':
									$imageObj->migrate(); // hard migrate in bulk, to check if all is there / resync on problems.
              break;
							case 'png2jpg':
								$item = $this->convertPNG($item, $q);
								$item->setResult([
										'is_done' => false,
								]);
//								$item->result->is_done = false;  // if not, finished to newly enqueued
							break;
							case 'removeLegacy':
									 $imageObj->removeLegacyShortPixel();
							break;
           }
      }
      else // as normal
      {
        $is_processable = $imageObj->isProcessable(); // @todo Probably check this against api / AiController

        // Allow processable to be overridden when using the manual optimize button - ignore when this happens already to be in queue.

        if (false === $is_processable )
        {
          if (is_object($options) && property_exists($options,'forceExclusion') && true == $options->forceExclusion)
          {
            $imageObj->cancelUserExclusions();
          }

        }

				$api->processMediaItem($item, $imageObj);

      }

    } */

    /**
     * Try to convert a PNGfile to JPG. This is done on the local server.  The file should be converted and then re-added to the queue to be processed as a JPG ( if success ) or continue as PNG ( if not success )
     * @param  Object $item                 Queued item
     * @param  Object $mediaQ               Queue object
     * @return Object         Returns queue item
     */
    // @todo Via actions to Optimizers
    /*
		protected function convertPNG(QueueItem $item, $mediaQ)
    {
//			$item->blocked = true;
			$item->block(true);
			$mediaQ->updateItem($item);

      $settings = \wpSPIO()->settings();
      $fs = \wpSPIO()->filesystem();

      $imageObj = $fs->getMediaImage($item->item_id);

			 if ($imageObj === false) // not exist error.
			 {
				 $item->block(false);
				 $q->updateItem($item);

			 	 return $item;
			 }

				$converter = Converter::getConverter($imageObj, true);
				$bool = false; // init
				if (false === $converter)
				{
					 Log::addError('Converter on Convert function returned false ' . $imageObj->get('id'));
					 $bool = false;
				}
				elseif ($converter->isConvertable())
				{
					$bool = $converter->convert();
				}

			if ($bool)
			{
				 ResponseController::addData($item->item_id, 'message', __('PNG2JPG converted', 'shortpixel-image-optimiser'));
			}
			else {
				 ResponseController::addData($item->item_id, 'message', __('PNG2JPG not converted', 'shortpixel-image-optimiser'));
			}

			// Regardless if it worked or not, requeue the item otherwise it will keep trying to convert due to the flag.
      $imageObj = $fs->getMediaImage($item->item_id);

			// Keep compressiontype from object, set in queue, imageModelToQueue
			$imageObj->setMeta('compressionType', $item->compressionType);

			$item->block(false);
			$mediaQ->updateItem($item);

      // Add converted items to the queue for the process
      $this->addItemToQueue($imageObj);

      return $item;
    }
*/

    // This is everything sub-efficient.
    /* Handles the Queue Item API result .
    ** @todo This needs splitting in due time between various tasks / optimize / ai etc.
    */
		protected function handleAPIResult(QueueItem $item, $q)
    {
      $fs = \wpSPIO()->filesystem();

      $qtype = $q->getType();
      $qtype = strtolower($qtype);

      $imageItem = $fs->getImage($item->item_id, $qtype);

      // If something is in the queue for long, but somebody decides to trash the file in the meanwhile.
      if ($imageItem === false)
      {
//				$item->result = new \stdClass;
				$item->setResult([
						'apiStatus' => ApiController::STATUS_NOT_API,
						'message' => __("File Error. File could not be loaded with this ID ", 'shortpixel-image-optimiser'),
						'fileStatus' => ImageModel::FILE_STATUS_ERROR,
						'is_done' => true,
						'is_error' => true,
				]);

				ResponseController::addData($item->item_id, 'message', $item->result->message);
      }
			elseif(true === $item->block())
			{
//				$item->result = new \stdClass;
				$item->setResult([
						'apiStatus' => ApiController::STATUS_UNCHANGED,
						'message' => __('Item is waiting (blocked)', 'shortpixel-image-optimiser'),
				]);
/*				$item->result->apiStatus = ApiController::STATUS_UNCHANGED;
				$item->result->message = __('Item is waiting (blocked)', 'shortpixel-image-optimiser');
				$item->result->is_done = false;
				$item->result->is_error = false; */
				Log::addWarn('Encountered blocked item, processing success? ', $item->item_id);
			}
      else
			{
				// This used in bulk preview for formatting filename.
				$item->setResult(
						['filename' => $imageItem->getFileName()]
				);

				// Used in WP-CLI
				ResponseController::addData($item->item_id, 'fileName', $imageItem->getFileName());
			}

//      $result = $item->result;

			$quotaController = QuotaController::getInstance();
			$statsController = StatsController::getInstance();

			if (true === $item->result()->is_error)
      {
          Log::addWarn('OptimizeControl - Item has Error', $item->result());
          // Check ApiStatus, and see what is what for error
          // https://shortpixel.com/api-docs
					$apistatus = $item->result()->apiStatus;

          if ($apistatus == ApiController::STATUS_ERROR ) // File Error - between -100 and -300
          {
							$item->setResult(['fileStatus' => ImageModel::FILE_STATUS_ERROR]);
          }
          // Out of Quota (partial / full)
          elseif ($apistatus == ApiController::STATUS_QUOTA_EXCEEDED)
          {
							$error_code = AjaxController::NOQUOTA;
							$quotaController->setQuotaExceeded();
          }
          elseif ($apistatus == ApiController::STATUS_NO_KEY)
          {
							$error_code = AjaxController::APIKEY_FAILED;
          }
          elseif($apistatus == ApiController::STATUS_QUEUE_FULL || $apistatus == ApiController::STATUS_MAINTENANCE ) // Full Queue / Maintenance mode
          {
							$error_code = AjaxController::SERVER_ERROR;
          }

					if (isset($error_code))
					{
						 $item->setResult(['error' => $error_code, 'is_error' => true]);
					}


					$response = array(
						 'is_error' => true,
						 'message' => $item->result()->message, // These mostly come from API
					);
					ResponseController::addData($item->item_id, $response);

					if ($item->result()->is_done )
          {
             $q->itemFailed($item, true);
             $this->HandleItemError($item, $qtype);

						 ResponseController::addData($item->item_id, 'is_done', true);
          }

      }
			elseif (true === $item->result()->is_done)
      {
				 if ($item->result()->apiStatus == ApiController::STATUS_SUCCESS ) // Is done and with success
         {

					 $tempFiles = [];

           // Set the metadata decided on APItime.
					 if (isset($item->data()->compressionType))
           {
						 $imageItem->setMeta('compressionType', $item->data()->compressionType);
           }

					 if (is_array($item->result()->files) && count($item->result()->files) > 0 )
           {
							$status = $this->handleOptimizedItem($q, $item, $imageItem, $item->result()->files);
							$item->setResult(['improvements' => $imageItem->getImprovements()]);

              if (ApiController::STATUS_SUCCESS == $status)
              {
								 $item->setResult(['apiStatus' => ApiController::STATUS_SUCCESS, 'fileStatus' => ImageModel::FILE_STATUS_SUCCESS]);

                 do_action('shortpixel_image_optimised', $imageItem->get('id'));
								 do_action('shortpixel/image/optimised', $imageItem);
               }
							 elseif(ApiController::STATUS_CONVERTED == $status)
							 {
								 $item->setResult(['apiStatus' => ApiController::STATUS_CONVERTED, 'fileStatus' => ImageModel::FILE_STATUS_SUCCESS]);

								 $fs = \wpSPIO()->filesystem();
		 						 $imageItem = $fs->getMediaImage($item->item_id);

								 if (property_exists($item->data(), 'compressionTypeRequested'))
								 {
										$item->setData('compressionType',$item->data()->compressionTypeRequested);
								 }
								 // Keep compressiontype from object, set in queue, imageModelToQueue
								 $imageItem->setMeta('compressionType', $item->data()->compressionType);
							 }
               else
               {
								 /*
                 $item->result->apiStatus = ApiController::STATUS_ERROR;
                 $item->fileStatus = ImageModel::FILE_STATUS_ERROR;
              //   $item->result->message = sprintf(__('Image not optimized with errors', 'shortpixel-image-optimiser'), $item->item_id);
              //   $item->result->message = $imageItem->getLastErrorMessage();
                 $item->result->is_error = true;
								 */
								 $item->setResult([
										'apiStatus' => ApiController::STATUS_ERROR,
										'fileStatus' => ImageModel::FILE_STATUS_ERROR,
										'is_error' => true,
								 ]);

               }

              // @todo SHoudl this be in results, if unset like this? This can't be unset like this!
              unset($item->result()->files);

              $item->setResult(['queuetype' => $qtype]);

							$showItem = UiHelper::findBestPreview($imageItem); // find smaller / better preview
							$original = $optimized = false;

							if ($showItem->getExtension() == 'pdf') // non-showable formats here
							{
//								 $item->result->original = false;
//								 $item->result->optimized = false;
							}
							elseif ($showItem->hasBackup())
              {
                $backupFile = $showItem->getBackupFile(); // attach backup for compare in bulk
                $backup_url = $fs->pathToUrl($backupFile);
								$original = $backup_url;
								$optimized = $fs->pathToUrl($showItem);
              }
              else
							{
                $original = false;
								$optimized = $fs->pathToUrl($showItem);
							}

							$item->setResult([
									'original' => $original,
									'optimized' => $optimized,
							]);

							// Dump Stats, Dump Quota. Refresh
							$statsController->reset();

							$this->deleteTempFiles($item);

           }
           // This was not a request process, just handle it and mark it as done.
           elseif ($item->result()->apiStatus == ApiController::STATUS_NOT_API)
           {
              // Nothing here.
           }
           else
           {
              Log::addWarn('Api returns Success, but result has no files', $item->result());
              $message = sprintf(__('Image API returned succes, but without images', 'shortpixel-image-optimiser'), $item->item_id);
							ResponseController::addData($item->item_id, 'message', $message );
              $item->setResult(['is_error' => true, 'apiStatus' => ApiController::STATUS_FAIL]);
           }


         }  // Is Done / Handle Success

				 // This is_error can happen not from api, but from handleOptimized
         if ($item->result()->is_error)
         {
					 Log::addDebug('Item failed, has error on done ', $item);
          $q->itemFailed($item, true);
          $this->HandleItemError($item, $qtype);
         }
         else
         {
           if ($imageItem->isProcessable() && $item->result()->apiStatus !== ApiController::STATUS_NOT_API)
           {
              Log::addDebug('Item with ID' . $imageItem->item_id . ' still has processables (with dump)', $imageItem->getOptimizeUrls());

              $api = $this->getAPI('optimize');
              // Create a copy of this for dumping, so doesn't influence the adding to queue.
            //  $newItem = clone $imageItem;
            //	$newItem->urls = $imageItem->getOptimizeUrls();


							// Add to URLs also the possiblity of images with only webp / avif needs. Otherwise URLs would end up emtpy.

							// It can happen that only webp /avifs are left for this image. This can't influence the API cache, so dump is not needed. Just don't send empty URLs for processing here.
              $api->dumpMediaItem($item);

              $this->addItemToQueue($imageItem); // requeue for further processing.
           }
           elseif (ApiController::STATUS_CONVERTED !== $item->result()->apiStatus)
					 {
            $q->itemDone($item); // Unbelievable but done.
					 }
         }
      }
      else
      {
          if ($item->result()->apiStatus == ApiController::STATUS_UNCHANGED || $item->result()->apiStatus === Apicontroller::STATUS_PARTIAL_SUCCESS)
          {
              $item->setResult(['fileStatus' => ImageModel::FILE_STATUS_PENDING]);
							$retry_limit = $q->getShortQ()->getOption('retry_limit');

              if ($item->result()->apiStatus === ApiController::STATUS_PARTIAL_SUCCESS)
							{
                  if (property_exists($item->result(), 'files') && count($item->result()->files) > 0 )
									{
                     $this->handleOptimizedItem($q, $item, $imageItem, $item->result()->files);
									}
									else {
                    Log::addWarn('Status is partial success, but no files followed. ', $item->result());
									}

									// Let frontend follow unchanged / waiting procedure.
                  $item->setResult(['apiStatus' => ApiController::STATUS_UNCHANGED]);
							}

              if ($retry_limit == $item->data()->tries || $retry_limit == ($item->data()->tries -1))
							{
									$message = __('Retry Limit reached. Image might be too large, limit too low or network issues.  ', 'shortpixel-image-optimiser');

									ResponseController::addData($item->item_id, 'message', $message);
									ResponseController::addData($item->item_id, 'is_error', true);
									ResponseController::addData($item->item_id, 'is_done', true);

                  $item->setResult(['apiStatus' => ApiController::ERR_TIMEOUT,
                            'message' => $message,
                            'is_error' => true,
                            'is_done' => true,
                ]);

									$this->HandleItemError($item, $qtype);

									// @todo Remove temp files here
							}
							else {
                  ResponseController::addData($item->item_id, 'message', $item->result()->message); // item is waiting base line here.
							}
						/* Item is not failing here:  Failed items come on the bottom of the queue, after all others so might cause multiple credit eating if the time is long. checkQueue is only done at the end of the queue.
						* Secondly, failing it, would prevent it going to TIMEOUT on the PROCESS in WPQ - which would mess with correct timings on that.
						*/
            //  $q->itemFailed($item, false); // register as failed, retry in x time, q checks timeouts
          }
      }

			// Not relevant for further returning.
			if (property_exists($item, 'paramlist'))
				 unset($item->paramlist);

			if (property_exists($item, 'returndatalist'))
				 unset($item->returndatalist);

			// Cleaning up the debugger.
			$debugItem = clone $item;
			unset($debugItem->_queueItem);
			unset($debugItem->counts);

      if (property_exists($debugItem, 'result'))
      {
        Log::addDebug('Optimizecontrol - Item has a result ', $debugItem->result);
      }
      else {
          Log::addDebug('Optimizecontrol - Item has a result ', $debugItem);
      }

			ResponseController::addData($item->item_id, array(
        'is_error' => $item->result()->is_error,
        'is_done' => $item->result()->is_done,
        'apiStatus' => $item->result()->apiStatus,
        'tries' => $item->data()->tries,

			));

			if (property_exists($item, 'fileStatus'))
			{
				 ResponseController::addData($item->item_id, 'fileStatus', $item->fileStatus);
			}

			// For now here, see how that goes
			$item->result->message = ResponseController::formatItem($item->item_id);

			if ($item->result->is_error)
				$item->result->kblink = UiHelper::getKBSearchLink($item->result->message);

      return $item;

    }


    /**
     * [Handles one optimized image and extra filetypes]
     * @param  [object] $q                         [queue object]
		 * @param  [object] $item                      [item QueueItem object. The data item]
     * @param  [object] $mediaObj                  [imageModel of the optimized collection]
     * @param  [array] $successData               [all successdata received so far]
     * @return [int]              [status integer, one of apicontroller status constants]
     */
		protected function handleOptimizedItem($q, $item, $mediaObj, $successData)
		{
				$imageArray = $successData['files'];

				$downloadHelper = DownloadHelper::getInstance();
				$converter = Converter::getConverter($mediaObj, true);

				$item->block(true);
				$q->updateItem($item);

        // @todo Here check if these item_files are persistent ( probablY ) and if so add them to data(), not result();
        // @todo This should be a temporary cast. Perhaps best to include this in QueueItem object with extra functions / checks implementable?
        if (property_exists($item->data(), 'files'))
				{
          $item_files = (array) $item->data()->files;
				}
        else {
          $item_files = [];
        }


/*				if (! property_exists($item, 'files'))
				{
					$item->files = array();
				}
*/
				foreach($imageArray as $imageName => $image)
				{
					 if (! isset($item_files[$imageName]))
					 {
						 $item_files[$imageName]  = [];
					 }

					 if (isset($item_files[$imageName]['image']) && file_exists($item_files[$imageName]['image']))
					 {
						  // All good.
					 }
					 // If status is success.  When converting (API) allow files that are bigger
					 elseif ($image['image']['status'] == ApiController::STATUS_SUCCESS ||
					 				($image['image']['status'] == ApiController::STATUS_OPTIMIZED_BIGGER && is_object($converter))
									)
					 {
						  $tempFile = $downloadHelper->downloadFile($image['image']['url']);
							if (is_object($tempFile))
							{
								$item_files[$imageName]['image'] = $tempFile->getFullPath();
								$imageArray[$imageName]['image']['file'] = $tempFile->getFullPath();
							}
					 }


					 if (! isset($item_files[$imageName]['webp']) &&  $image['webp']['status'] == ApiController::STATUS_SUCCESS)
					 {
						 $tempFile = $downloadHelper->downloadFile($image['webp']['url']);
						 if (is_object($tempFile))
						 {
								$item_files[$imageName]['webp'] = $tempFile->getFullPath();
						 		$imageArray[$imageName]['webp']['file'] = $tempFile->getFullPath();
					 		}
				 	 }
					 elseif ($image['webp']['status'] == ApiController::STATUS_OPTIMIZED_BIGGER) {
							$item_files[$imageName]['webp'] = ApiController::STATUS_OPTIMIZED_BIGGER;
					 }

					 if (! isset($item_files[$imageName]['avif']) && $image['avif']['status'] == ApiController::STATUS_SUCCESS)
					 {
						 $tempFile = $downloadHelper->downloadFile($image['avif']['url']);
						 if (is_object($tempFile))
						 {
								$item_files[$imageName]['avif'] = $tempFile->getFullPath();
						 		$imageArray[$imageName]['avif']['file'] = $tempFile->getFullPath();
						 }
					 }
					 elseif ($image['avif']['status'] == ApiController::STATUS_OPTIMIZED_BIGGER) {
							$item_files[$imageName]['avif'] = ApiController::STATUS_OPTIMIZED_BIGGER;

					 }
				}

				$successData['files']  = $imageArray;
				$item->setData('files', $item_files);

				$converter = Converter::getConverter($mediaObj, true);
				$optimizedArgs = array();
				if (is_object($converter) && $converter->isConverterFor('api') )
				{
					$optimizedResult = $converter->handleConverted($successData);
					if (true === $optimizedResult)
					{
						ResponseController::addData($item->item_id, 'message', __('File Converted', 'shortpixel-image-optimiser'));
						$status = ApiController::STATUS_CONVERTED;

					}
					else {
						ResponseController::addData($item->item_id, 'message', __('File conversion failed.', 'shortpixel-image-optimiser'));
						$q->itemFailed($item, true);
            Log::addError('File conversion failed with data ', $successData);
						$status = ApiController::STATUS_FAIL;
					}

				}
				else
				{
          if (is_object($converter))
          {
              $successData = $converter->handleConvertedFilter($successData);
          }

					$optimizedResult = $mediaObj->handleOptimized($successData);
					if (true === $optimizedResult)
					  $status = ApiController::STATUS_SUCCESS;
					else {
						$status = ApiController::STATUS_FAIL;
					}
				}

				$item->block(false);
				$q->updateItem($item);

				return $status;
		}





		private function HandleItemError($item, $type)
    {
			 // Perhaps in future this might be taken directly from ResponseController
        if ($this->isBulk)
				{
					$responseItem = ResponseController::getResponseItem($item->item_id);
          $fs = \wpSPIO()->filesystem();
          $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
          $fileLog = $fs->getFile($backupDir->getPath() . 'current_bulk_' . $type . '.log');

          $time = UiHelper::formatTs(time());

          $fileName = $responseItem->fileName;
          $message = ResponseController::formatItem($item->item_id);
          $item_id = $item->item_id;

          $fileLog->append($time . '|' . $fileName . '| ' . $item_id . '|' . $message . ';' .PHP_EOL);
        }
    }
/*
    protected function checkQueueClean($result, $q)
    {
        if ($result->qstatus == Queue::RESULT_QUEUE_EMPTY && ! $this->isBulk)
        {
            $stats = $q->getStats();

            if ($stats->done > 0 || $stats->fatal_errors > 0)
            {
               $q->cleanQueue(); // clean the queue
            }
        }
    }
*/
    /* This will be move to the respective classes */
    /*public function getAPI($action)
    {
       $api = false;
       switch($action)
       {
          case 'optimize':
          case 'restore':
            $api = ApiController::getInstance();
          break;
          case 'requestAlt': // @todo Check if this is correct action name,
          case 'retrieveAlt':
            $api = AiController::getInstance();
          break;
       }

       return $api;
    } */

    /** Convert a result Queue Stdclass to a JSON send Object */
    // Q
    /*
    protected function queueToJson($result, $json = false)
    {
        if (! $json)
          $json = $this->getJsonResponse();

        switch($result->qstatus)
        {
          case Queue::RESULT_PREPARING:
            $json->message = sprintf(__('Prepared %s items', 'shortpixel-image-optimiser'), $result->items );
          break;
          case Queue::RESULT_PREPARING_OVERLIMIT:
            $json->message = sprintf(__('Prepared %s items - but went over limit! ', 'shortpixel-image-optimiser'), $result->items );
          break;
          case Queue::RESULT_PREPARING_DONE:
            $json->message = sprintf(__('Preparing is done, queue has %s items ', 'shortpixel-image-optimiser'), $result->stats->total );
          break;
          case Queue::RESULT_EMPTY:
              $json->message  = __('Queue returned no active items', 'shortpixel-image-optimiser');
          break;
          case Queue::RESULT_QUEUE_EMPTY:
              $json->message = __('Queue empty and done', 'shortpixel-image-optimiser');
          break;
          case Queue::RESULT_ITEMS:
            $json->message = sprintf(__("Fetched %d items",  'shortpixel-image-optimiser'), count($result->items));
            $json->results = $result->items;
          break;
          case Queue::RESULT_RECOUNT: // This one should probably not happen.
             $json->has_error = true;
             $json->message = sprintf(__('Bulk preparation seems to be interrupted. Restart the queue or continue without accurate count', 'shortpixel-image-optimiser'));
          break;
          default:
             $json->message = sprintf(__('Unknown Status %s ', 'shortpixel-image-optimiser'), $result->qstatus);
          break;
        }
        $json->qstatus = $result->qstatus;
        //$json->

        if (property_exists($result, 'stats'))
          $json->stats = $result->stats;

        return $json;
    } */

    // Communication Part
    /*
    protected function getJsonResponse()
    {

      $json = new \stdClass;
      $json->status = null;
      $json->result = null;
      $json->results = null;
//      $json->actions = null;
    //  $json->has_error = false;// probably unused
      $json->message = null;

      return $json;
    } */



    // @todo For optimiser.
		protected function deleteTempFiles($item)
		{
				if (! property_exists($item, 'files'))
				{
					return false;
				}

				$files = $item->files;
				$fs = \wpSPIO()->filesystem();

				foreach($files as $name => $data)
				{
						foreach($data as $tmpPath)
						{
							 if (is_numeric($tmpPath)) // Happens when result is bigger status is set.
							 	continue;

								$tmpFile = $fs->getFile($tmpPath);
								if ($tmpFile->exists())
									$tmpFile->delete();
						}
				}

		}

/*

		protected function setLastID($item_id)
		{
			 self::$lastId = $item_id;
		}

		public static function getLastId()
		{
			 return self::$lastId;
		}

*/

    // Q
    //
    /*
    public static function resetQueues()
    {
	      $queues = array('media', 'mediaSingle', 'custom', 'customSingle');
	      foreach($queues as $qName)
	      {
	          $q = new MediaLibraryQueue($qName);
	          $q->activatePlugin();
	      }
    }

    // Q
    public static function uninstallPlugin()
    {

      $queues = array('media', 'mediaSingle', 'custom', 'customSingle');
      foreach($queues as $qName)
      {
          $q = new MediaLibraryQueue($qName);
          $q->uninstall();
      }

    }
    */


} // class
