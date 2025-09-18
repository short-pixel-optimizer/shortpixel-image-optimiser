<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortQ\ShortQ as ShortQ;
use ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\Helper\UtilHelper;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;


class MediaLibraryQueue extends Queue
{
   protected $cacheName = 'MediaCache'; // When preparing, write needed data to cache.

   protected static $instance;


   /* MediaLibraryQueue Instance */
   public function __construct($queueName = 'Media')
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue($queueName);
     $this->queueName = $queueName;

     $options = array(
        'numitems' => 5,  // amount of items to pull per tick when optimizing
        'mode' => 'wait',
        'process_timeout' => 10000, // time between request for the image. (in milisecs)
        'retry_limit' => 30, // amount of times it will retry without errors before giving up
        'enqueue_limit' => 200, // amount of items added to the queue when preparing.
        'filters' => [], 
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

   protected function prepareBulkRestore()
   {
      $items = $this->queryOptimizedItems();
      return $this->prepareItems($items);
   }

   public function createNewBulk($args = [])
   {
     /* if (isset($args['filters']))
      {
         $this->addFilters($args['filters']); 
      } */
       
      
      // Parent should save options as well. 
       return parent::createNewBulk($args); 
   }


   protected function addFilters($filters)
   {

      //$start_id = $end_id = null; 

      
      global $wpdb; 
      

      $start_date = isset($filters['start_date'])  ? new \DateTime($filters['start_date']) : false; 
      $end_date = isset($filters['end_date'])  ? new \DateTime($filters['end_date']) : false; 

      if (isset($filters['start_date']))
      {
         //$date = UtilHelper::timestampToDB($filters['start_time']); 
         $date = $start_date->format("Y-m-d H:i:s");
         $startSQL = 'select max(ID) from wp_posts where post_date <= %s group by post_date order by post_date DESC limit 1';
         $sql = $wpdb->prepare($startSQL, $date); 
         $start_id =  $wpdb->get_var($sql); 
      }
      if (isset($filters['end_date']))
      {
        // $date = UtilHelper::timestampToDB($filters['end_time']); 
        $date = $end_date->format("Y-m-d H:i:s");
         $endSQL = 'select MIN(ID) from wp_posts where post_date <= %s group by post_date order by post_date DESC limit 1';
         $sql = $wpdb->prepare($endSQL, $date); 
         $end_id =  $wpdb->get_var($sql); 
      }
      


       //echo "Start $start_id END $end_id";
       //exit();
      // IF POST DATE NEEDS 09-20 ( or 23:59:59? )
      // select post_date, max(ID) from wp_posts where post_date <= '2024-09-21 00:00:00' group by post_date order by post_date DESC limit 100
   }
   

   private function queryPostMeta()
   {
     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');
     $prepare = array();

     $fastmode = apply_filters('shortpixel/queue/fastmode', false);

     global $wpdb;

     $sqlmeta = "SELECT DISTINCT post_id FROM " . $wpdb->prefix . "postmeta where (meta_key = %s or meta_key = %s)";

     if ( true === $fastmode)
     {
       // This will by definition not optimize everything is things like only-thumbnails are not done.
       $sqlmeta .= ' and post_id not in (SELECT DISTINCT attach_id from ' . $wpdb->prefix. 'shortpixel_postmeta where parent = %d and status = %d) ';
     }

     $prepare[] = '_wp_attached_file';
     $prepare[] = '_wp_attachment_metadata';

     if (true === $fastmode)
     {
        $prepare[] = ImageModel::IMAGE_TYPE_MAIN;
        $prepare[] = ImageModel::FILE_STATUS_SUCCESS;
     }

     if ($last_id > 0)
     {
        $sqlmeta .= " and post_id < %d ";
        $prepare[] = intval($last_id);
     }

     $sqlmeta .= ' order by post_id DESC LIMIT %d ';
     $prepare[] = $limit;

     $sqlmeta = $wpdb->prepare($sqlmeta, $prepare);

     $results = $wpdb->get_col($sqlmeta);

     $items = [];
     foreach($results as $item_id)
     {
          $items[] = $item_id;
     }

     // Remove failed object, ie if getImage returned false.
     return array_filter($items);
   }

   private function queryOptimizedItems()
   {
     $last_id = $this->getStatus('last_item_id');

     $limit = $this->q->getOption('enqueue_limit');
     $prepare = array();
     global $wpdb;

     $sql = 'SELECT distinct attach_id from ' . $wpdb->prefix . 'shortpixel_postmeta where status = %d ';
     $prepare[] = ImageModel::FILE_STATUS_SUCCESS;

     if ($last_id > 0)
     {
        $sql .= " and attach_id < %d ";
        $prepare [] = intval($last_id);
     }

     $sql .= ' order by attach_id DESC LIMIT %d ';
     $prepare[] = $limit;

     $sql = $wpdb->prepare($sql, $prepare);

     $results = $wpdb->get_col($sql);

     $items = array();

     foreach($results as $item_id)
     {
        $items[] = $item_id;
     }

     return array_filter($items);

   }

}
