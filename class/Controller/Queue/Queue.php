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
       //if (! $mediaItem->isProcessable())
      //  return false;
       $preparing = $this->getStatus('preparing');

       $qItem = $this->imageModelToQueue($imageModel);

       $item = array('id' => $imageModel->get('id'), 'value' => $qItem, 'item_count' => count($qItem->urls));
       $this->q->addItems(array($item));
       $numitems = $this->q->withRemoveDuplicates()->enqueue(); // enqueue returns numitems

       $this->q->setStatus('preparing', $preparing); // add single should not influence preparing status.
       $result = new \stdClass;
       $result = $this->getQStatus($result, $numitems);
       $result->numitems = $numitems;

       return $result;
       //return $numitems;
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
/*               $cache = new CacheController();
               $countCache = $cache->getItem($this->cacheName);
               $count = $countCache->getValue(); */

            /*   if ($count->items !== $this->getStatus('items'))
               {
                 $result->qstatus = self::RESULT_RECOUNT;
                 Log::addDebug("Difference in Items!" .  $count->items . ' ' . $this->getStatus('items'));
                 $this->recountQueue();
               } */
            }
       }
       elseif ($this->getStatus('bulk_running') == true) // this is a bulk queue, don't start automatically.
       {
          if ($this->getStatus('running') == true)
              $items = $this->deQueue();
          elseif ($this->getStatus('preparing') == false)
              $result->qstatus = self::RESULT_PREPARING_DONE;
       }
       else // regular queue can run whenever.
       {
            $items = $this->deQueue();
       }

       if (isset($items)) // did a dequeue.
       {
          $result = $this->getQStatus($result, count($items));
          Log::addTemp('Fetched Q items', $items);
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
          $imageCount = 0;
          $optimizedCount = 0;
          $optimizedThumbnailCount = 0;
        //  $thumbnailCount = 0;

          // maybe while on the whole function, until certain time has elapsed?
          foreach($items as $mediaItem)
          {
              //  $mediaItem= $fs->getMediaImage($item);

                if ($mediaItem->isProcessable()) // Checking will be done when processing queue.
                {
                   Log::addTemp('Preparing as Processable' . $mediaItem->get('id'));
                    $qObject = $this->imageModelToQueue($mediaItem);
                  //  $thumbnailCount += count($mediaItem->get('thumbnails'));
                    $imageCount += count($qObject->urls);

                    $queue[] = array('id' => $mediaItem->get('id'), 'value' => $qObject, 'item_count' => count($qObject->urls)); // array('id' => $mediaItem->get('id'), 'value' => $mediaItem->getOptimizeURLS() );
                }
                else
                {
                   if($mediaItem->isOptimized())
                   {
                      Log::addTemp('Item is optimized -' . $mediaItem->get('id'));
                  //  $optimizedCount++;
                  //  $optimizedThumbnailCount = count($mediaItem->get('thumbnails'));
                   }

                }
          }

          $this->q->additems($queue);
          $numitems = $this->q->enqueue();

          // mediaItem should be last_item_id, save this one.
          $this->q->setStatus('last_item_id', $mediaItem->get('id')); // enum status to prevent a hang when no items are enqueued, thus last_item_id is not raised. Don't save to DB though.
          Log::addTemp('Last Item Id stored' . $this->q->getStatus('last_item_id'));
          Log::addTemp('Items enqueued ' . $numitems);

        /*  $countObj= $this->getCountCache(); */
          $qCount = count($queue);
/*
          $countObj->images += $imageCount;
          $countObj->items += $qCount;
          $countObj->optimizedCount += $optimizedCount;
          $countObj->optimizedThumbnailCount += $optimizedThumbnailCount;
*/
          $return['items'] = $qCount;
          $return['images'] = $imageCount;
          $return['results'] = count($items); // This is the return of the query. Preparing should not be 'done' before the query ends, but it can return 0 on the qcount if all results are already optimized.
/*
    Log::addDebug('This run prepared: ' . $qCount . ' ' . $imageCount, $return);
    Log::addDebug('Count Cache ', $countObj);

          $this->saveCountCache($countObj); */

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
        $stats->percentage_done = round((100 / $stats->total) * $stats->done);
      else
        $stats->percentage_done = 0;

      if ($stats->is_preparing)
      {
        $stats->images = $this->countQueue();
      }

      return $stats;
    }

/*
    protected function getCountCache()
    {
      $cache = new CacheController();
      $countCache = $cache->getItem($this->cacheName);
      $countObj = $countCache->getValue();

      if (is_null($countObj) )
      {
      //  Log::addDebug('GetCountCache failure ' . $this->cacheName . ', not matching counts ' . $countObj->items . ' <ci gs>  ' . $this->getStatus('items'));
        //$countObj = $this->recountQueue();
        //$this->saveCountCache($countObj);
        $count = (object) [
            'images' => 0,
            'items' => 0,
            'optimizedCount' => 0, // already optimized items
            'optimizedThumbnailCount' => 0,
        ];
      }

      return $countObj;
    }

    protected function saveCountCache($countObj)
    {
      $cache = new CacheController();
      $countCache = $cache->getItem($this->cacheName);

      $countCache->setValue($countObj);
      $countCache->setExpires(14 * DAY_IN_SECONDS);
      $cache->storeItemObject ($countCache);
    }
*/

    /** Recounts the ItemSum for the Queue
    *
    * Note that this is not the same number as preparing adds to the cache, which counts across the installation how much images were already optimized. However, we don't want to stop and reset cache just for a few lost numbers so we should accept a flawed outcome here perhaps.
    */
    protected function countQueue()
    {
        $recount = $this->q->itemSum('countbystatus');
        Log::addDebug('Recounts, countbystatus', $recount);
        $count = (object) [
            'images' => $recount[ShortQ::QSTATUS_WAITING],
            'images_done' => $recount[ShortQ::QSTATUS_DONE],
            'images_inprocess' => $recount[ShortQ::QSTATUS_INPROCESS],
          //  'items' => $this->getStatus('items'),
          //  'optimizedCount' => $this->getStatus('done'), // already optimized items
          //  'optimizedThumbnailCount' => $recount[ShortQ::QSTATUS_DONE],
        ];

    //    Log::addDebug('Recreated ' . $this->cacheName . ' CountCache', $recount);
        return $count;
    }


    protected function getStatus($name = false)
    {
        if ($name == 'items')
          return $this->q->itemCount(); // This one also recounts once queue returns 0
        else
          return $this->q->getStatus($name);
    }

    protected function deQueue()
    {
       $items = $this->q->deQueue();
       $items = array_map(array($this, 'queueToMediaItem'), $items);
       return $items;
    }

    /*protected function deQueuePriority()
    {
      $items = $this->q->deQueue(array('onlypriority' => true));
      $items = array_map(array($this, 'queueToMediaItem'), $items);

      return $items;
    } */


    protected function queueToMediaItem($qItem)
    {
        $item = new \stdClass;

        $item = $qItem->value;
        $item->_queueItem = $qItem;

        $item->item_id = $qItem->item_id;
        $item->tries = $qItem->tries;

        return $item;
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

    // This might be a general implementation - This should be done only once!
    protected function imageModelToQueue(ImageModel $imageModel)
    {

        $item = new \stdClass;
        $item->compressionType = \wpSPIO()->settings()->compressionType;

        $urls = $imageModel->getOptimizeUrls();
      //  $paths = $imageModel->getOptimizePaths();

        if ($imageModel->get('do_png2jpg'))  // Flag is set in Is_Processable in mediaLibraryModel, when settings are on, image is png.
          $item->png2jpg = $imageModel->get('do_png2jpg');

        if ($imageModel->getMeta('compressionType'))
          $item->compressionType = $imageModel->getMeta('compressionType');

        //$item->paths = apply_filters('shortpixel/queue/paths', $paths, $imageModel->get('id'));
        $item->urls = apply_filters('shortpixel_image_urls', $urls, $imageModel->get('id'));


        return $item;
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




} // class
