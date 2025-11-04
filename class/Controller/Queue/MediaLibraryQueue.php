<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortQ\ShortQ as ShortQ;
use ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\Helper\UtilHelper;
use ShortPixel\Model\AiDataModel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;


class MediaLibraryQueue extends Queue
{
   protected $cacheName = 'MediaCache'; // When preparing, write needed data to cache.

   protected static $instance;

   protected $options = array(
      'numitems' => 5,  // amount of items to pull per tick when optimizing
      'mode' => 'wait',
      'process_timeout' => 10000, // time between request for the image. (in milisecs)
      'retry_limit' => 30, // amount of times it will retry without errors before giving up
      'enqueue_limit' => 200, // amount of items added to the queue when preparing.
      'filters' => [], 
   );   

   /* MediaLibraryQueue Instance */
   public function __construct($queueName = 'Media')
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue($queueName);
     $this->queueName = $queueName;

     $options = $this->getOptions();
     // If no DB options are set, get the defaults. 
     if (false === $options)
     {
       $options = $this->options; 
     }

     // @todo  Here probably options thing should be replaced by querying custom_data from Q first and then set options
     $this->options = apply_filters('shortpixel/medialibraryqueue/options', $options);


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

   protected function prepareUndoAI()
   {
      $items = $this->queryAiItems(); 
      return $this->prepareItems($items);
   }

   public function createNewBulk($args = [])
   {
      if (isset($args['filters']))
      {
         $this->addFilters($args['filters']); 
         unset($args['filters']); // added to options 
      } 
       
      $options = array_merge($this->options, $args);

      // Parent should save options as well. 
       return parent::createNewBulk($options); 
   }


   protected function getFilterQueryData()
   {
      global $wpdb; 
      $table = $wpdb->posts; 

      return [
          'date_field' => 'POST_DATE', 
          'base_query' => 'SELECT ID FROM ' . $table . ' WHERE post_type = %s AND ',
          'base_prepare' => ['attachment'], 
          
      ];
   }
   

   private function queryPostMeta()
   {
     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');

     $options = $this->getOptions(); 

      // Filters. 
     $start_id = $end_id = null; 
     if (isset($options['filters']))
     {
        if (isset($options['filters']['start_id']))
        {
          $start_id = $options['filters']['start_id'];
        }
        if (isset($options['filters']['end_id']))
        {
          $end_id = $options['filters']['end_id'];
        }
     }

     if (-1 === $start_id || -1 === $end_id)
     {
       return []; 
     }


     $prepare = [];
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
     elseif (false === is_null($start_id))
     {
       $sqlmeta .= ' and post_id <= %d ';
       $prepare[] = intval($start_id);
     }

     if (false === is_null($end_id))
     {
       $sqlmeta .= ' and post_id >= %d '; 
       $prepare[] = intval($end_id); 
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


   private function queryAiItems()
   {
       $last_id = $this->getStatus('last_item_id'); 

       $limit = $this->q->getOption('enqueue_limit');
       $prepare = [];
       global $wpdb;

       $table = $wpdb->prefix . 'shortpixel_aipostmeta';

       $sql = ' SELECT attach_id from ' . $table . ' WHERE status = %d '; 
       $prepare[] = AiDataModel::AI_STATUS_GENERATED; 

       if ($last_id > 0)
       {
          $sql .= " and attach_id < %d ";
          $prepare [] = intval($last_id);
       }
  
       $sql .= ' order by attach_id DESC LIMIT %d ';
       $prepare[] = $limit;
  
       $sql = $wpdb->prepare($sql, $prepare);
  
       $results = $wpdb->get_col($sql);
  
       $items = [];
  
       foreach($results as $item_id)
       {
          $items[] = $item_id;
       }
  
       return array_filter($items);
  

   }

}
