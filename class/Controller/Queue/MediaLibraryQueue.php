<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;
use ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class MediaLibraryQueue extends Queue
{
   protected $queueName = '';
   protected $cacheName = 'MediaCache'; // When preparing, write needed data to cache.

   protected static $instance;


   /* MediaLibraryQueue Instance */
   public function __construct($queueName = 'Media')
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue($queueName);
     $this->queueName = $queueName;

     $options = array(
        'numitems' => 1,
        'mode' => 'wait',
        'process_timeout' => 7000,
        'retry_limit' => 20,
        'enqueue_limit' => 200,
     );

     $options = apply_filters('shortpixel/medialibraryqueue/options', $options);
     $this->q->setOptions($options);
   }

   public function getType()
   {
      return 'media';
   }

   protected function prepare()
   {

      $items = $this->queryPostMeta();
      return $this->prepareItems($items);

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
     Log::addDebug('Media Library, Queue meta SQL'   . $sqlmeta);
     $results = $wpdb->get_col($sqlmeta);

     $fs = \wpSPIO()->filesystem();
     $items = array();

     foreach($results as $item_id)
     {
          $items[] = $fs->getImage($item_id, 'media');
     }

     return $items;


   }

  /* public function queueToMediaItem($queueItem)
   {
      $id = $queueItem->id;
      return $fs->getMediaImage($id);
   } */

}
