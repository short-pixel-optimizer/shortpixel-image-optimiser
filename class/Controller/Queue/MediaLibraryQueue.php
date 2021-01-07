<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;
use ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class MediaLibraryQueue extends Queue
{
   const QUEUE_NAME = 'Media';
   const CACHE_NAME = 'MediaCache'; // When preparing, write needed data to cache.

   /* MediaLibraryQueue Instance */
   public function __construct()
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue(self::QUEUE_NAME);

     $options = array(
        'numitems' => 1,
        'mode' => 'wait',
        'process_timeout' => 7000,
        'retry_limit' => 20,
        'enqueue_limit' => 20,
     );

     $options = apply_filters('shortpixel/medialibraryqueue/options', $options);
     $this->q->setOptions($options);

     /*
     $this->q->setOption('numitems', 1);
     $this->q->setOption('mode', 'wait');
     $this->q->setOption('process_timeout', 7000);
     $this->q->setOption('retry_limit', 20);
     $this->q->setOption('enqueue_limit', 20); */
   }

   public function createNewBulk($args)
   {
       $this->q->resetQueue();
       $this->q->setStatus('preparing', true, false);
       $this->q->setStatus('bulk_running', false, true);

       $cache = new CacheController();

       $cache->deleteItem(self::CACHE_NAME);

   }

   public function startBulk()
   {
       $this->q->setStatus('preparing', false, false);
       $this->q->setStatus('bulk_running', true, true);
   }

   public function getQueueName()
   {
      return self::QUEUE_NAME;
   }

   protected function prepare()
   {

      $items = $this->queryPostMeta();
      $return = array('items' => 0, 'images' => 0);

      if (count($items) == 0)
      {
          $this->q->setStatus('preparing', false);
          Log::addDebug('Preparing, false');
          return $return;
      }

      $fs = \wpSPIO()->filesystem();

      $queue = array();
      $imageCount = 0;
      $optimizedCount = 0;
      $optimizedThumbnailCount = 0;

      // maybe while on the whole function, until certain time has elapsed?
      foreach($items as $item)
      {
            $mediaItem= $fs->getMediaImage($item);
            if ($mediaItem->isProcessable()) // Checking will be done when processing queue.
            {
                $qObject = $this->imageModelToQueue($mediaItem);
                $thumbnailCount += count($mediaItem->get('thumbnails'));
                $imageCount += count($qObject->urls);

                $queue[] = array('id' => $mediaItem->get('id'), 'value' => $qObject ); // array('id' => $mediaItem->get('id'), 'value' => $mediaItem->getOptimizeURLS() );
            }
            elseif ($mediaItem->isOptimized())
            {
                $optimizedCount++;
                $optimizedThumbnailCount = count($mediaItem->get('thumbnails'));
            }
      }

      $this->q->additems($queue);
      $numitems = $this->q->enqueue();

      $cache = new CacheController();
      $countCache = $cache->getItem(self::CACHE_NAME);

      if (! $countCache->exists() )
      {
        $count = (object) [
            'images' => 0,
            'items' => 0,
            'thumbnailCount' => 0,
            'optimizedCount' => 0,
            'optimizedThumbnailCount' => 0,
        ];
        Log::addDebug('Recreated CountCache');
      }
      else
        $count = $countCache->getValue();

      $qCount = count($queue);

      $count->images += $imageCount;
      $count->items += $qCount;
      $count->optimizedCount += $optimizedCount;
      $count->optimizedThumbnailCount += $optimizedThumbnailCount;

      $return['items'] = $qCount;
      $return['images'] = $imageCount;

Log::addDebug('This run prepared: ' . $qCount . ' ' . $imageCount, $return);
Log::addDebug('Count Cache ', $count);

      $countCache->setValue($count);
      $countCache->setExpires(2 * HOUR_IN_SECONDS);
      $cache->storeItemObject ($countCache);
      return $return; // only return real amount.
   }

   private function queryPostMeta()
   {
     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');
     $prepare = array();
     global $wpdb;

     $sqlmeta = "SELECT DISTINCT post_id FROM " . $wpdb->prefix . "postmeta where (meta_key = %s or meta_key = %s)";

     $prepare[] = '_wp_attached_file';
     $prepare[] = '_wp_attachment_metadata';

     if ($last_id > 0)
     {
        $sqlmeta .= " and post_id < %d ";
        $prepare [] = intval($last_id);
     }
     $sqlmeta .= ' order by post_id DESC LIMIT %d ';
     $prepare[] = $limit;

     $sqlmeta = $wpdb->prepare($sqlmeta, $prepare);
     Log::addDebug($sqlmeta);
     $result = $wpdb->get_col($sqlmeta);

     return $result;
   }

  /* public function queueToMediaItem($queueItem)
   {
      $id = $queueItem->id;
      return $fs->getMediaImage($id);
   } */

}
