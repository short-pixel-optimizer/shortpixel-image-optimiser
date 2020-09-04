<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;

class MediaLibraryQueue extends Queue
{
   const QUEUE_NAME = 'Media';

   public function __construct()
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue(self::QUEUE_NAME);

     $this->q->setOption('numitems', 1);
     $this->q->setOption('mode', 'wait');
     $this->q->setOption('process_timeout', 7000);
     $this->q->setOption('retry_limit', 20);
   }

   protected function createNewBulk($args)
   {
       $this->q->resetQueue();
   }

   public function getQueueName()
   {
      return self::QUEUE_NAME;
   }
   
   protected function prepare()
   {
      $this->q->setStatus('preparing', true);
      $items = $this->queryPostMeta();

      if (count($items) == 0)
      {
          $this->setStatus('preparing', false);
          return 0;
      }

      $fs = \wpSPIO()->filesystem();

      $queue = array();
      // maybe while on the whole function, until certain time has elapsed?
      foreach($items as $item)
      {
            $mediaItem= $fs->getMediaImage($item);
            if ($mediaItem->is_processable()) // Checking will be done when processing queue.
            {
                $queue[] = $this->mediaItemToQueue($item); // array('id' => $mediaItem->get('id'), 'value' => $mediaItem->getOptimizeURLS() );
            }
      }

      $this->q->additems($queue)->enqueue();
      $numitems = $this->q->enqueue();

      return $numitems;
   }

   private function queryPostMeta()
   {
     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');
     global $wpdb;

     $sqlmeta = "SELECT DISTINCT post_id FROM " . $wpdb->prefix . "postmeta where (meta_key = %s or meta_key = %s) and post_id > %d order by post_id DESC LIMIT %d";
     $sqlmeta = $wpdb->prepare($sqlmeta, '_wp_attached_file', '_wp_attachment_metadata', $last_id, $limit);

     $result = $wpdb->get_col($sqlmeta);

     return $result;

   }

  /* public function queueToMediaItem($queueItem)
   {
      $id = $queueItem->id;
      return $fs->getMediaImage($id);
   } */

}
