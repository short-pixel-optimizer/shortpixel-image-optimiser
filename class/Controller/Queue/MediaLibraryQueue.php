<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;

class MediaLibraryQueue extends Queue
{
   const QUEUE_NAME = 'Media';

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
       $this->q->setStatus('preparing', true);
       $this->q->setStatus('bulk_running', false);

   }

   public function startBulk()
   {
       $this->q->setStatus('bulk_running', true);
       
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
          $this->q->setStatus('preparing', false);
          return 0;
      }

      $fs = \wpSPIO()->filesystem();

      $queue = array();
      // maybe while on the whole function, until certain time has elapsed?
      foreach($items as $item)
      {
            $mediaItem= $fs->getMediaImage($item);
            if ($mediaItem->isProcessable()) // Checking will be done when processing queue.
            {
                $queue[] = array('id' => $mediaItem->get('id'), 'value' => $this->imageModelToQueue($mediaItem)); // array('id' => $mediaItem->get('id'), 'value' => $mediaItem->getOptimizeURLS() );
            }
      }

      $this->q->additems($queue);
      $numitems = $this->q->enqueue();

      return $numitems;
   }

   private function queryPostMeta()
   {
     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');
     $prepare = array();
     global $wpdb;

echo "QRYP - LAST ID" . $last_id .  ' WITH LIMIT ' . $limit . '\n\n';

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

     $result = $wpdb->get_col($sqlmeta);

     return $result;
   }

  /* public function queueToMediaItem($queueItem)
   {
      $id = $queueItem->id;
      return $fs->getMediaImage($id);
   } */

}
