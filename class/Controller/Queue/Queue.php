<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\CacheController as CacheController;

use ShortPixel\ShortQ\ShortQ as ShortQ;

abstract class Queue
{
    protected $q;
//    protected static $instance;
    protected static $results;

    const PLUGIN_SLUG = 'SPIO';


    // Result status for Run function
    const RESULT_ITEMS = 1;
    const RESULT_PREPARING = 2;
    const RESULT_PREPARING_DONE = 3;
    const RESULT_EMPTY = 4;
    const RESULT_QUEUE_EMPTY = 10;
    const RESULT_RECOUNT = 11;
    const RESULT_ERROR = -1;
    const RESULT_UNKNOWN = -10;


    /* Result status (per item) to communicate back to frontend */
/*    const FILE_NOTEXISTS = -1;
    const FILE_ALREADYOPTIMIZED = -2;
    const FILE_OK = 1;
    const FILE_SUCCESS = 2;
    const FILE_WAIT = 3; */

    abstract protected function prepare();
    abstract public function getType();

    public function createNewBulk($args)
    {
        $this->resetQueue();
        $this->q->setStatus('preparing', true, false);
        $this->q->setStatus('bulk_running', true, true);

        $cache = new CacheController();
        $cache->deleteItem($this->cacheName);
    }

    public function startBulk()
    {
        $this->q->setStatus('preparing', false, false);
        $this->q->setStatus('running', true, true);
    }

    public function resetQueue()
    {
       $this->q->resetQueue();
    }

    public function cleanQueue()
    {
       $this->q->cleanQueue();
    }

    /** Enqueues a single items into the urgent queue list
    *   - Should not be used for bulk images
    * @param ImageModel $mediaItem An ImageModel (CustomImageModel or MediaLibraryModel) object
    * @return mixed
    */
    public function addSingleItem(ImageModel $imageModel)
    {

       $preparing = $this->getStatus('preparing');

       $qItem = $this->imageModelToQueue($imageModel);
       $counts = $qItem->counts;

       $item = array('id' => $imageModel->get('id'), 'value' => $qItem, 'item_count' => $counts->creditCount);
       $this->q->addItems(array($item));
       $numitems = $this->q->withRemoveDuplicates()->enqueue(); // enqueue returns numitems

       $this->q->setStatus('preparing', $preparing); // add single should not influence preparing status.
       $result = new \stdClass;
       $result = $this->getQStatus($result, $numitems);
       $result->numitems = $numitems;

       do_action('shortpixel_start_image_optimisation', $imageModel->get('id'), $imageModel);
       return $result;
    }

    public function run()
    {
       $result = new \stdClass();
       $result->qstatus = self::RESULT_UNKNOWN;
       $result->items = null;

       if ( $this->getStatus('preparing') === true) // When preparing a queue for bulk
       {
            $prepared = $this->prepare();
            $result->qstatus = self::RESULT_PREPARING;
            $result->items = $prepared['items']; // number of items.
            $result->images = $prepared['images'];
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
          $result = $this->getQStatus($result, count($items));
          $result->items = $items;

       }

       return $result;
    }


    protected function prepareItems($items)
    {
        $return = array('items' => 0, 'images' => 0, 'results' => 0);

          if (count($items) == 0)
          {
              $this->q->setStatus('preparing', false);
              Log::addDebug('Preparing, false', $items);
              return $return;
          }

          $fs = \wpSPIO()->filesystem();

          $queue = array();
          $imageCount = $webpCount = $avifCount = 0;

          $customData = $this->getStatus('custom_data');

          if (! is_null($customData->customOperation))
            $operation = $customdata->customOperation;
          else
            $operation = false;

          // maybe while on the whole function, until certain time has elapsed?
          foreach($items as $mediaItem)
          {
                if ($mediaItem->isProcessable() && ! $operation) // Checking will be done when processing queue.
                {
                    $qObject = $this->imageModelToQueue($mediaItem);

                    $counts = $qObject->counts;

                      $queue[] = array('id' => $mediaItem->get('id'), 'value' => $qObject, 'item_count' => $counts->creditCount);

                    $imageCount += $counts->creditCount;
                    $webpCount += $counts->webpCount;
                    $avifCount += $counts->avifCount;

                    do_action('shortpixel_start_image_optimisation', $mediaItem->get('id'), $mediaItem);

                }
                else
                {
                   if($operation !== false)
                   {
                      if ($operation == 'bulk-restore')
                      {
                          $qObject = $this->imageModelToQueue($mediaItem);
                          $qObject->action = 'restore';
                          $queue[] = array('id' => $mediaItem->get('id'), 'value' => $qObject, 'item_count' => $counts->creditCount);
                      }
                      elseif ($operation == 'migrate')
                      {
                          $qObject = $this->imageModelToQueue($mediaItem);
                          $qObject->action = 'migrate';

                          $queue[] = array('id' => $mediaItem->get('id'), 'value' => $qObject, 'item_count' => $counts->creditCount);
                      }
                   }
                   elseif($mediaItem->isOptimized())
                   {
                      Log::addTemp('Item is optimized -' . $mediaItem->get('id'));
                   }

                }
          }

          $this->q->additems($queue);
          $numitems = $this->q->enqueue();

          $customData = $this->getStatus('custom_data');


          $customData->webpCount += $webpCount;
          $customData->avifCount += $avifCount;

          $this->q->setStatus('custom_data', $customData, false);

          // mediaItem should be last_item_id, save this one.
          $this->q->setStatus('last_item_id', $mediaItem->get('id')); // enum status to prevent a hang when no items are enqueued, thus last_item_id is not raised. save to DB.

          $qCount = count($queue);

          $return['items'] = $qCount;
          $return['images'] = $imageCount;
          $return['results'] = count($items); // This is the return of the query. Preparing should not be 'done' before the query ends, but it can return 0 on the qcount if all results are already optimized.


          return $return; // only return real amount.
    }

    // Used by Optimizecontroller on handlesuccess.
    public function getQueueName()
    {
          return $this->queueName;
    }


    public function getQStatus($result, $numitems)
    {
      if ($numitems == 0)
      {
        if ($this->getStatus('items') == 0 && $this->getStatus('errors') == 0 && $this->getStatus('in_process') == 0) // no items, nothing waiting in retry. Signal finished.
        {
          $result->qstatus = self::RESULT_QUEUE_EMPTY;
        }
        else
        {
          $result->qstatus = self::RESULT_EMPTY;
        }
      }
      else
      {
        $result->qstatus = self::RESULT_ITEMS;
      }

      return $result;
    }


    public function getStats()
    {
      $stats = new \stdClass; // For frontend reporting back.
      $stats->is_preparing = $this->getStatus('preparing');
      $stats->is_running = $this->getStatus('running');
      $stats->is_finished = $this->getStatus('finished');
      $stats->in_queue = $this->getStatus('items');
      $stats->in_process = $this->getStatus('in_process');
      $stats->errors = $this->getStatus('errors');
      $stats->fatal_errors = $this->getStatus('fatal_errors');
      $stats->done = $this->getStatus('done');
      $stats->bulk_running = $this->getStatus('bulk_running');

      $stats->total = $stats->in_queue + $stats->fatal_errors + $stats->errors + $stats->done + $stats->in_process;
      if ($stats->total > 0)
        $stats->percentage_done = round((100 / $stats->total) * ($stats->done + $stats->fatal_errors));
      else
        $stats->percentage_done = 0;

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
          $count->images_webp = $customData->webpCount;
          $count->images_avif = $customData->avifCount;
        }


        return $count;
    }


    protected function getStatus($name = false)
    {
        if ($name == 'items')
          return $this->q->itemCount(); // This one also recounts once queue returns 0
        elseif ($name == 'custom_data')
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

    protected function deQueue()
    {
       $items = $this->q->deQueue(); // Items, can be multiple different according to throttle.

       $items = array_map(array($this, 'queueToMediaItem'), $items);
       return $items;
    }

    protected function queueToMediaItem($qItem)
    {

        //$items = array(); // convert to a item array to be send off.

        $item = new \stdClass;
        $item = $qItem->value;
        $item->_queueItem = $qItem;

        $item->item_id = $qItem->item_id;
        $item->tries = $qItem->tries;

        return $item;
        /*foreach($qItem->value as $values) // Always an array of items.
        {
          $newItem = clone $item;
          foreach($values as $key => $val)
          {
              $newItem->$key = $val;
          }
          $newItem->_queueItem = $qItem;
          $items[] = $newItem;
        } */

        //return $items;
    }

    protected function mediaItemToQueue($item)
    {
        $mediaItem = clone $item;  // clone here, not to loose referenced data.
        unset($mediaItem->item_id);
        unset($mediaItem->tries);

        $qItem = $mediaItem->_queueItem;

        unset($mediaItem->_queueItem);

        $qItem->value = $mediaItem;
        return $qItem;
    }

    // This is a general implementation - This should be done only once!
    // The 'avif / webp left imp. is commented out since both API / and OptimizeController don't play well with this.
    protected function imageModelToQueue(ImageModel $imageModel)
    {
      //  $settings = \wpSPIO()->settings();
        $item = new \stdClass;
        $item->compressionType = \wpSPIO()->settings()->compressionType;

        $urls = $imageModel->getOptimizeUrls();

        $counts = new \stdClass;
        $counts->creditCount = 0;  // count the used credits for this item.
        $counts->avifCount = 0;
        $counts->webpCount = 0;
        //$creditCount = 0;

        $webps = ($imageModel->isProcessableFileType('webp')) ? $imageModel->getOptimizeFileType('webp') : null;
        $avifs = ($imageModel->isProcessableFileType('avif')) ? $imageModel->getOptimizeFileType('avif') : null;

        $hasUrls = (count($urls) > 0) ? true : false;
        $hasWebps = (! is_null($webps) && count($webps) > 0) ? true : false;
        $hasAvifs = (! is_null($avifs) && count($avifs) > 0) ? true : false;
        $flags = array();
        $items = array();

        $webpLeft = $avifLeft = false;

        if (is_null($webps) && is_null($avifs))
        {
           // nothing.
          // $items[] = $item;
            $counts->creditCount += count($urls);
        }
        else
        {
            if ($hasUrls) // if original urls needs optimizing.
            {
                $counts->creditCount += count($urls);

                if ($hasWebps && count($urls) == count($webps))
                {
                   $flags[] = '+webp'; // original + format
                   $counts->creditCount += count($urls);
                   $counts->webpCount += count($urls);
                }
                elseif($hasWebps)
                  $webpLeft = true; // or indicate this should go separate ( not full )

                if ($hasAvifs && count($urls) == count($avifs))
                {
                   $flags[] = '+avif';
                   $counts->creditCount += count($urls);
                   $counts->avifCount += count($urls);
                }
                elseif($hasAvifs)
                  $avifLeft = true;

            }
            elseif(! $hasUrls && $hasWebps || $hasAvifs) // if only webp / avif needs doing.
            {
                if ($hasWebps && $hasAvifs)
                {
                    if (count($webps) == count($avifs))
                    {
                        $flags[] = 'avif';
                        $flags[] = 'webp';
                        $counts->creditCount += count($webps) * 2;
                        $counts->webpCount += count($webps);
                        $counts->avifCount += count($avifs);
                        $urls = $webps; // Main URLS not available, but needs queuing.
                    }
                    else
                    {
                      $flags[] = 'webp';
                      $avifLeft = true;
                      $counts->creditCount += count($webps);
                      $counts->webpCount += count($webps);
                      $urls = $webps;
                    }
                }
                elseif($hasWebps && ! $hasAvifs)
                {
                    $flags[] = 'webp';
                    $counts->creditCount += count($webps);
                    $counts->webpCount += count($webps);
                    $urls = $webps;
                }
                elseif($hasAvifs && ! $hasWebps)
                {
                    $flags[] = 'avif';
                    $counts->creditCount += count($avifs);
                    $counts->avifCount += count($avifs);
                    $urls = $avifs;
                }
            }

        }

      //  $paths = $imageModel->getOptimizePaths();
        //Log::addDebug('AvifL on ' . $imageModel->get('id') . ' ', array($avifLeft, $urls));
        if ($imageModel->get('do_png2jpg') && $hasUrls)  // Flag is set in Is_Processable in mediaLibraryModel, when settings are on, image is png.
        {
          $item->png2jpg = $imageModel->get('do_png2jpg');
        }

        if (! is_null($imageModel->getMeta('compressionType')))
          $item->compressionType = $imageModel->getMeta('compressionType');

        $item->flags = $flags;

        // Former securi function, add timestamp to all URLS, for cache busting.
        $urls = $this->timestampURLS($urls, $imageModel->get('id'));
        $item->urls = apply_filters('shortpixel_image_urls', $urls, $imageModel->get('id'));
        $item->counts = $counts;


        return $item;
    }

    protected function timestampURLS($urls, $id)
    {
      // https://developer.wordpress.org/reference/functions/get_post_modified_time/
      $time = get_post_modified_time('U', false, $id );
      foreach($urls as $index => $url)
      {
        $urls[$index] = add_query_arg('ver', $time, $url);
      }

      return $urls;
    }

    private function countQueueItem()
    {

    }

    public function itemFailed($item, $fatal = false)
    {
        $qItem = $this->mediaItemToQueue($item); // convert again
        $this->q->itemFailed($qItem, $fatal);
        $this->q->updateItemValue($qItem);
    }

    public function itemDone ($item)
    {
      $qItem = $this->mediaItemToQueue($item); // convert again
      $this->q->itemDone($qItem);
    }

    //@todo
    public function uninstall()
    {
        $this->q->uninstall();
    }

    // @todo
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
        $data->customOperation = null;

        return $data;
    }



} // class
