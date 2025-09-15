<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Model\Converter\Converter as Converter;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use ShortPixel\Model\Queue\QueueItem as QueueItem;


use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Model\AiDataModel;
use ShortPixel\ShortQ\ShortQ as ShortQ;

abstract class Queue
{
    protected $q;
//    protected static $instance;
    protected static $results;
    protected static $isInQueue = [];

    const PLUGIN_SLUG = 'SPIO';

    // Result status for Run function
    const RESULT_ITEMS = 1;
    const RESULT_PREPARING = 2;
    const RESULT_PREPARING_OVERLIMIT = 5;
    const RESULT_PREPARING_DONE = 3;
    const RESULT_EMPTY = 4;
    const RESULT_QUEUE_EMPTY = 10;
    const RESULT_RECOUNT = 11;
    const RESULT_ERROR = -1;
    const RESULT_UNKNOWN = -10;


    abstract protected function prepare();
    abstract protected function prepareBulkRestore();
    abstract public function getType();

    protected $queueName = '';
    protected $cacheName; 

    
    public function createNewBulk($args = [])
    {
				$this->resetQueue();

        $this->q->setStatus('preparing', true, false);
				$this->q->setStatus('finished', false, false);
        $this->q->setStatus('bulk_running', true, true);

        $cache = new CacheController();
        $cache->deleteItem($this->cacheName);

        $this->setCustomBulk($args);
    }

    public function startBulk()
    {
        $this->q->setStatus('preparing', false, false);
        $this->q->setStatus('running', true, true);
    }

    public function cleanQueue()
    {
       $this->q->cleanQueue();
    }

		public function resetQueue()
		{
			$this->q->resetQueue();
		}

    // gateway to set custom options for queue.
    public function setOptions($options)
    {
        return $this->q->setOptions($options);
    }

    /** Enqueues a single items into the urgent queue list
    *   - Should not be used for bulk images
    *   @todo This function is deprecated.
    * @param ImageModel $mediaItem An ImageModel (CustomImageModel or MediaLibraryModel) object
    * @return mixed
    */
    public function addSingleItem(ImageModel $imageModel, $args = array())
    {

       $defaults = array(
          'forceExclusion' => false,
          'action' => 'optimize',
          'remote_id' => null, // for retrieveAltAction
       );
       $args = wp_parse_args($args, $defaults);

       $qItem = QueueItems::getImageItem($imageModel);

			 $result = new \stdClass;

       $this->q->addItems([$qItem->returnEnqueue()], false);
       $numitems = $this->q->withRemoveDuplicates()->enqueue(); // enqueue returns numitems

       $this->checkQueueCache($imageModel->get('id'));

       $result->qstatus = $this->getQStatus($numitems);
       $result->numitems = $numitems;

       do_action('shortpixel_start_image_optimisation', $imageModel->get('id'), $imageModel);
       return $result;
    }

    public function addQueueItem(QueueItem $qItem)
    {
      $this->q->addItems([$qItem->returnEnqueue()], false);
      $item_id = $qItem->item_id; 
      $numitems = $this->q->withRemoveDuplicates()->enqueue(); // enqueue returns numitems

      $result = new \stdClass;
      $result->qstatus = $this->getQStatus($numitems);
      $result->numitems = $numitems;

      $this->checkQueueCache($item_id);
      

      do_action('shortpixel_start_image_optimisation', $item_id, $qItem->imageModel);
      return $result;
    }

		/** Drop Item if it needs dropping. This can be needed in case of image alteration and it's in the queue */
		public function dropItem($item_id)
		{
				$this->q->removeItems(array(
						'item_id' => $item_id,
				));

		}


    public function run()
    {
       $result = new \stdClass();
       $result->qstatus = self::RESULT_UNKNOWN;
       $result->items = null;

       $custom_operation = $this->getCustomDataItem('customOperation');

       if ( $this->getStatus('preparing') === true) // When preparing a queue for bulk
       {
            if (false !== $custom_operation && 'bulk-restore' === $custom_operation)
            {
              $prepared = $this->prepareBulkRestore();
            }
            else {

              $prepared = $this->prepare();
            }


            $result->qstatus = self::RESULT_PREPARING;
            $result->items = $prepared['items']; // number of items.
            $result->images = $prepared['images'];

            if (true === $prepared['overlimit'])
            {
               $result->qstatus = self::RESULT_PREPARING_OVERLIMIT;
            }

            if ($prepared['items'] == 0)
            {
               Log::addDebug( $this->queueName . ' Queue, prepared came back as zero ', array($prepared, $result->items));
               if ($prepared['results'] == 0) /// This means no results, empty query.
               {
                $result->qstatus = self::RESULT_PREPARING_DONE;
               }
            }
       }
       elseif ($this->getStatus('bulk_running') == true) // this is a bulk queue, don't start automatically.
       {
          if ($this->getStatus('running') == true)
          {
              $items = $this->deQueue();
          }
          elseif ($this->getStatus('preparing') == false && $this->getStatus('finished') == false)
          {
              $result->qstatus = self::RESULT_PREPARING_DONE;
          }
          elseif ($this->getStatus('finished') == true)
          {
              $result->qstatus = self::RESULT_QUEUE_EMPTY;
          }
       }
       else // regular queue can run whenever.
       {
            $items = $this->deQueue();
       }

       if (isset($items)) // did a dequeue.
       {
          $result->qstatus = $this->getQStatus(count($items));
          $result->items = $items;

       }

       return $result;
    }


    protected function prepareItems($items)
    {
        do_action('shortpixel/queue/prepare_items', $items);

        $return = array('items' => 0, 'images' => 0, 'results' => 0,
      'overlimit' => false);

				$settings = \wpSPIO()->settings();
        $env = \wpSPIO()->env();

          if (count($items) == 0)
          {
              $this->q->setStatus('preparing', false);
              Log::addDebug('PrepareItems: Items can back as empty array. Nothing to prepare');
              return $return;
          }

          $fs = \wpSPIO()->filesystem();

          $queue = array();
          $imageCount = $webpCount = $avifCount = $baseCount = 0;

          $operation = $this->getCustomDataItem('customOperation'); // false or value (or null)

					if (is_null($operation))
						$operation = false;

          $i = 0;

          // maybe while on the whole function, until certain time has elapsed?
          foreach($items as $item_id)
          {

							// Migrate shouldn't load image object at all since that would trigger the conversion.
							  if ($operation == 'migrate' || $operation == 'removeLegacy')
								{
                    //$qObject = new \stdClass;
                    //$qObject->action = $operation;
                    $item = QueueItems::getEmptyItem($item_id, $this->getType());
                    if ('migrate' == $operation)
                    {
                        $item->newMigrateAction();
                    }
                    if ('removeLegacy' == $operation)
                    {
                       $item->newRemoveLegacyAction();
                    }
                    $queue[] = $item->returnEnqueue(); //array('id' => $item_id, 'value' => $qObject, 'item_count' => 1);

										continue;
								}


                $mediaItem = $fs->getImage($item_id, $this->getType() );


            //checking if the $mediaItem actually exists
            if ( is_object($mediaItem) ) {

              if ('pdf' === $mediaItem->getExtension() && false === $settings->optimizePdfs)
              {
                  continue;
              }
              
                $optimizeAiController = OptimizeAiController::getInstance(); 

                // If autoAi is on the bulk, add operation to the item
                $enqueueAi = false; 
                if ('media' === $mediaItem->get('type') && true === $optimizeAiController->isAiEnabled() && true === $settings->autoAIBulk)
                {
                  $aiDataModel = AiDataModel::getModelByAttachment($mediaItem->get('id'));  
                  $enqueueAi = $aiDataModel->isProcessable();
                }

                if ($mediaItem->isProcessable() && $mediaItem->isOptimizePrevented() === false && ! $operation) // Checking will be done when processing queue.
                {

										if ($this->isDuplicateActive($mediaItem, $queue))
										{
											 continue;
										}

                    $qItem = QueueItems::getImageItem($mediaItem);
                    $qItem->newOptimizeAction();

									 if ($mediaItem->getParent() !== false)
						 			 {
						 				  $media_id = $mediaItem->getParent();
						 			 }

                   if (true === $enqueueAi)
                   {
                      $qItem->data->addNextAction('requestAlt'); 
                   }

                    $queue[] = $qItem->returnEnqueue(); //array('id' => $media_id, 'value' => $qObject, 'item_count' => $counts->creditCount);

                    $counts = $qItem->data()->counts; 

                    $imageCount += $counts->creditCount;
                    $webpCount += $counts->webpCount;
                    $avifCount += $counts->avifCount;
										$baseCount += $counts->baseCount; // base images (all minus webp/avif) 

                    
                    $this->checkQueueCache($item_id);
                    do_action('shortpixel_start_image_optimisation', $mediaItem);

                }
                else
                { // @todo Incorporate these actions here.  . Perhaps operations should all be on top?
                   if($operation !== false)
                   {
                      if ($operation == 'bulk-restore')
                      {
                          if ($mediaItem->isRestorable())
                          {
                            $qObject = new \stdClass;
                            $qObject->action = 'restore';
                            $queue[] = array('id' => $mediaItem->get('id'), 'value' => $qObject);
                          }
                      }
                   }
                   elseif(true === $enqueueAi)
                   {
                          $qItem = QueueItems::getImageItem($mediaItem);
                          $qItem->requestAltAction();
                          $queue[] = $qItem->returnEnqueue();
                   }
									 else
									 {
												$response = array(
							 					 	'is_error' => true,
							 						'item_type' => ResponseController::ISSUE_QUEUE_FAILED,
							 						'message ' => ' Item failed: ' . $mediaItem->getProcessableReason(),
							 				 );
							 				  ResponseController::addData($item_id, $response);
									 }

                }
    			  }
    			  else
    			  {
        				 $response = array(
        					 	'is_error' => true,
        						'item_type' => ResponseController::ISSUE_QUEUE_FAILED,
        						'message ' => ' Enqueing of item failed : invalid post content or post type',
        				 );
        				 	ResponseController::addData($item_id, $response);
        				  Log::addWarn('The item with id ' . $item_id . ' cannot be processed because it is either corrupted or an invalid post type');
        			}

              if (true === $env->IsOverMemoryLimit($i) || true === $env->IsOverTimeLimit())
              {
                 Log::addMemory('PrepareItems: OverLimit! Breaking on index ' . $i);
                 $this->q->setStatus('last_item_id', $item_id);
                 $return['overlimit'] = true; // lockout return
                 break;
              }

              $i++;
        } // Loop Items

          $this->q->additems($queue);
          $numitems = $this->q->enqueue();

          $customData = $this->getStatus('custom_data');

          $customData->webpCount += $webpCount;
          $customData->avifCount += $avifCount;
					$customData->baseCount += $baseCount;

          $this->q->setStatus('custom_data', $customData, false);

          // mediaItem should be last_item_id, save this one.
          $this->q->setStatus('last_item_id', $item_id); // enum status to prevent a hang when no items are enqueued, thus last_item_id is not raised. save to DB.

          $qCount = count($queue);

          $return['items'] = $qCount;
          $return['images'] = $imageCount;
					/** NOTE! The count items is the amount of items queried and checked. It might be they never enqueued, just that the check process is running.
					*/
          $return['results'] = count($items); // This is the return of the query. Preparing should not be 'done' before the query ends, but it can return 0 on the qcount if all results are already optimized.

          return $return; // only return real amount.
    }

    // Used by Optimizecontroller on handlesuccess.
    public function getQueueName()
    {
          return $this->queueName;
    }


    protected function getQStatus($numitems)
    {

      if ($numitems == 0)
      {
        if ($this->getStatus('items') == 0 && $this->getStatus('errors') == 0 && $this->getStatus('in_process') == 0) // no items, nothing waiting in retry. Signal finished.
        {
          $qstatus = self::RESULT_QUEUE_EMPTY;
        }
        else
        {
          $qstatus = self::RESULT_EMPTY;
        }
      }
      else
      {
        $qstatus = self::RESULT_ITEMS;
      }

      return $qstatus;
    }


    public function getStats()
    {
      $stats = new \stdClass; // For frontend reporting back.
      $stats->is_preparing = (bool) $this->getStatus('preparing');
      $stats->is_running = (bool) $this->getStatus('running');
      $stats->is_finished = (bool) $this->getStatus('finished');
      $stats->in_queue = (int) $this->getStatus('items');
      $stats->in_process = (int) $this->getStatus('in_process');
			$stats->awaiting = $stats->in_queue + $stats->in_process; // calculation used for WP-CLI.

      $stats->errors = (int) $this->getStatus('errors');
      $stats->fatal_errors = (int) $this->getStatus('fatal_errors');
      $stats->done = (int) $this->getStatus('done');
      $stats->bulk_running = (bool) $this->getStatus('bulk_running');

			$customData = $this->getStatus('custom_data');

			if ($this->isCustomOperation())
			{
					  $stats->customOperation = $this->getCustomDataItem('customOperation');
            $stats->isCustomOperation = '10'; // numeric value for the bulk JS
			}

      $stats->total = $stats->in_queue + $stats->fatal_errors + $stats->errors + $stats->done + $stats->in_process;
      if ($stats->total > 0)
			{
        $stats->percentage_done = round((100 / $stats->total) * ($stats->done + $stats->fatal_errors), 0, PHP_ROUND_HALF_DOWN);
			}
			else
        $stats->percentage_done = 100; // no items means all done.


      if (! $stats->is_running)
      {
        $stats->images = $this->countQueue();
      }

      return $stats;
    }


    /** Recounts the ItemSum for the Queue
    *
    * Note that this is not the same number as preparing adds to the cache, which counts across the installation how much images were already optimized. However, we don't want to stop and reset cache just for a few lost numbers so we should accept a flawed outcome here perhaps.
    */
    protected function countQueue()
    {
        $recount = $this->q->itemSum('countbystatus');
        $customData = $this->getStatus('custom_data');
        $count = (object) [
            'images' => $recount[ShortQ::QSTATUS_WAITING],
            'images_done' => $recount[ShortQ::QSTATUS_DONE],
            'images_inprocess' => $recount[ShortQ::QSTATUS_INPROCESS],
        ];

        $count->images_webp = 0;
        $count->images_avif = 0;
        if (is_object($customData))
        {
          $count->images_webp = (int) $customData->webpCount;
          $count->images_avif = (int) $customData->avifCount;
					$count->images_basecount = (int) $customData->baseCount;
        }

        return $count;
    }


    protected function getStatus($name = false)
    {
        if ($name == 'custom_data')
        {
            $customData = $this->q->getStatus('custom_data');
            if (! is_object($customData))
            {
               $customData = $this->createCustomData();
            }
            return $customData;
        }
        return $this->q->getStatus($name);
    }

    public function setCustomBulk($options = [] )
    {
        if (0 === count($options))
          return false;

        $customData = $this->getStatus('custom_data');


        if (isset($options['customOp']))
        {
           $customOp = $options['customOp'];   
           $customData->customOperation = $customOp;
           unset($options['customOp']);
        }

        if (is_array($options) && count($options) > 0)
          $customData->queueOptions = $options;

        $this->getShortQ()->setStatus('custom_data', $customData);
    }

		// Return if this queue has any special operation outside of normal optimizing.
		// Use to give the go processing when out of credits (ie)
		public function isCustomOperation()
		{
			if ($this->getCustomDataItem('customOperation'))
			{
				return true;
			}
			return false;
		}

    public function getCustomDataItem($name)
    {
        $customData = $this->getStatus('custom_data');
        if (is_object($customData) && property_exists($customData, $name))
        {
           return $customData->$name;
        }
        return false;
    }

    protected function deQueue()
    {
       $items = $this->q->deQueue(); // Items, can be multiple different according to throttle.

       $items = array_map(array($this, 'queueToMediaItem'), $items);
       return $items;
    }

    protected function queueToMediaItem($qItem)
    {
        /* $item = new \stdClass;
        $item = $qItem->value;
        $item->_queueItem = $qItem; */

//        $item->item_id = $qItem->item_id;
//        $item->tries = $qItem->tries;

        $item = QueueItems::getEmptyItem($qItem->item_id, $this->getType());
        $item->setFromData($qItem->value);
        $item->setData('tries', $qItem->tries);
        $item->setData('queue_list_order', $qItem->list_order);
        $item->data()->addKeepDataArgs('queue_list_order'); 
        $item->set('queueItem', $qItem);

				/* Dunno about this, the decode should handle arrays properly
				if (property_exists($item->data(), 'files'))
				{ // This must be array.
					$item->files = json_decode(json_encode($item->files), true);
				} */
        return $item;
    }

    protected function mediaItemToQueue(QueueItem $item)
    {
        // @todo Test this assumption
        return $item->getQueueItem();
    }

    public function getItem($item_id)
    {
        $itemObj = $this->q->getItem($item_id); 
        if (false === is_object($itemObj))
        {
           return $itemObj; // probably boolean / not found. 
        }

        return $this->queueToMediaItem(($itemObj));
    }


		// Check if item is in queue. Considered not in queue if status is done.
		public function isItemInQueue($item_id)
		{
        if (isset(self::$isInQueue[$item_id]))
        {
           return self::$isInQueue[$item_id];
        }

				$itemObj = $this->q->getItem($item_id);
        self::$isInQueue[$item_id] = $itemObj; // cache this, since interface requests this X amount of times.

				$notQ = array(ShortQ::QSTATUS_DONE, ShortQ::QSTATUS_FATAL);
				if (is_object($itemObj) && in_array(floor($itemObj->status), $notQ) === false )
				{
					return true;
				}
				return false;
		}

    protected function checkQueueCache($item_id)
    {

      
      if (isset(self::$isInQueue[$item_id]) && false === self::$isInQueue[$item_id])
      {
         unset(self::$isInQueue[$item_id]);
      }




    }

    public function itemFailed(QueueItem $qItem, $fatal = false)
    {
			  if ($fatal)
			  {
					 Log::addError('Item failed while optimizing', $qItem->result());
				}

          // It can happen that the item is not in the queue yet ( directAction doing ) 
        $item = $this->mediaItemToQueue($qItem);
        if (false === $item)
        {
           return;
        }

        $this->q->itemFailed($item, $fatal);
        $this->q->updateItemValue($item);
    }

		public function updateItem($item)
		{
			$qItem = $this->mediaItemToQueue($item); // convert again
			$this->q->updateItemValue($qItem);
		}

		public function isDuplicateActive($mediaItem, $queue = array() )
		{
			if ($mediaItem->get('type') === 'custom')
				return false;

			$WPMLduplicates = $mediaItem->getWPMLDuplicates();
			$qitems = array();
			if (count($queue) > 0)
			{
				 foreach($queue as $qitem)
				 {
					  $qitems[] = $qitem['id'];
				 }
			}

			if (is_array($WPMLduplicates) && count($WPMLduplicates) > 0)
			{
				 $duplicateActive = false;
				 foreach($WPMLduplicates as $duplicate_id)
				 {
					  if (in_array($duplicate_id, $qitems))
						{
							Log::addDebug('Duplicate Item is in queue already, skipping (ar). Duplicate:' . $duplicate_id);
							$duplicateActive = true;
							break;
						}
						elseif ($this->isItemInQueue($duplicate_id))
						{
							 Log::addDebug('Duplicate Item is in queue already, skipping (db). Duplicate:' . $duplicate_id);
							 $duplicateActive = true;
							 break;
						}
				 }
				 if (true === $duplicateActive)
				 {
						return $duplicateActive;
				 }
			}
			return false;
		}

    public function itemDone ($item)
    {
      $qItem = $this->mediaItemToQueue($item); // convert again
      $this->q->itemDone($qItem);
    }

    public function uninstall()
    {
        $this->q->uninstall();
    }

    public function activatePlugin()
    {
        $this->q->resetQueue();
    }

    public function getShortQ()
    {
        return $this->q;
    }

    // All custom Data in the App should be created here.
    private function createCustomData()
    {
        $data = new \stdClass;
        $data->webpCount = 0;
        $data->avifCount = 0;
				$data->baseCount = 0;
        $data->customOperation = false;

        return $data;
    }



} // class
