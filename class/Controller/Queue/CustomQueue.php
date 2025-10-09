<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortQ\ShortQ as ShortQ;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;

class CustomQueue extends Queue
{

   protected $cacheName = 'CustomCache'; // When preparing, write needed data to cache.

   protected static $instance;

   public function __construct($queueName = 'Custom')
   {
     $shortQ = new ShortQ(static::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue($queueName);
     $this->queueName = $queueName;

      $options = array(
         'numitems' => 5,
         'mode' => 'wait',
         'process_timeout' => 7000,
         'retry_limit' => 20,
         'enqueue_limit' => 120,
      );

     $options = apply_filters('shortpixel/customqueue/options', $options);
     $this->q->setOptions($options);
   }

   public function getType()
   {
      return 'custom';
   }


   protected function prepare()
   {
      $items = $this->queryItems();

      return $this->prepareItems($items);
   }

   protected function prepareBulkRestore()
   {
      $items = $this->queryOptimizedItems();

      return $this->prepareItems($items);
   }

   // Not implemented, for abstract.
   protected function prepareUndoAI()
   {
       return []; 
   }

   public function createNewBulk($args = [])
   {
      if (isset($args['filters']))
      {
         $this->addFilters($args['filters']); 
         unset($args['filters']); 
      } 
       
      $options = array_merge($this->options, $args);
      
      // Parent should save options as well. 
       return parent::createNewBulk($options); 
   }

   /*
   protected function addFilters($filters)
   {

      global $wpdb; 
      $table = $wpdb->prefix . 'shortpixel_meta'; 
      list($start_date, $end_date) = parent::addFilters($filters);

      

      if (isset($start_date) && false !== $start_date)
      {
         $startDateSQL = 'ts_added <= %s '; 
         $prepare[] = $start_date->format("Y-m-d H:i:s");
      }
      if (isset($end_date) && false !== $end_date)
      {
         $endDateSQL = 'ts_added >= %s'; 
         $prepare[] = $end_date->format("Y-m-d H:i:s");
      }

      $get_start_id = $get_end_id = false; 
      if (isset($startDateSQL) && isset($endDateSQL))
      {
          $dateSQL = $startDateSQL . ' and ' . $endDateSQL; 
          $get_start_id = true; 
          $get_end_id = true; 
      }
      elseif (isset($startDateSQL) && false === isset($endDateSQL))
      {
          $dateSQL = $startDateSQL;
          $get_start_id = true; 
      }
      elseif (false === isset($startDateSQL) && isset($endDateSQL))
      {
          $dateSQL = $endDateSQL; 
          $get_end_id = true; 
      }


      $sql = 'SELECT id from '  . $table . ' WHERE ' . $dateSQL; 


      if (true === $get_start_id)
      {
          $startSQL = $sql . '  ORDER BY ts_added DESC LIMIT 1'; 
          $startSQL = $wpdb->prepare($startSQL, $prepare); 
          $start_id = $wpdb->get_var($startSQL); 
          if (is_null($start_id))
          {
              $start_id = -1; 
          }
          $this->options['filters']['start_id'] = $start_id; 
      }

      if (true === $get_end_id)
      {
         $endSQL = $sql . '  ORDER BY ts_added ASC LIMIT 1'; 
         $endSQL = $wpdb->prepare($endSQL, $prepare); 
         $end_id = $wpdb->get_var($endSQL); 
         if (is_null($end_id))
         {
             $end_id = -1; 
         }
         $this->options['filters']['end_id'] = $end_id; 
      }
      

   } */

   protected function getFilterQueryData()
   {
      global $wpdb; 
      $table = $wpdb->prefix . 'shortpixel_meta'; 

      return [
          'date_field' => 'ts_added', 
          'base_query' => 'SELECT ID FROM ' . $table . ' WHERE ',
          'base_prepare' => [], 
          
      ];
   }



   private function queryItems()
   {
     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');
     $prepare = array();
     $items = array();
     $fastmode = apply_filters('shortpixel/queue/fastmode', false);

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

     global $wpdb;

     $folderSQL = ' SELECT id FROM ' . $wpdb->prefix . 'shortpixel_folders where status >= 0 ';
     $folderRow = $wpdb->get_col($folderSQL);

     // No Active Folders, No Items.
     if (count($folderRow) == 0)
       return $items;

     // List of prepared (%d) for the folders.
     $query_arr = join( ',', array_fill( 0, count( $folderRow ), '%d' ) );

     $sql = 'SELECT id FROM ' . $wpdb->prefix . 'shortpixel_meta WHERE folder_id in ( ';

     $sql .= $query_arr . ') ';
     // Query anything else than success, since that is done.
     $prepare = array_merge($prepare, $folderRow);

      if (true === $fastmode)
      {
         $sql .= ' AND status <> %d';
         $prepare[] = ImageModel::FILE_STATUS_SUCCESS;
      }

     if ($last_id > 0)
     {
        $sql .= " AND id < %d ";
        $prepare [] = intval($last_id);
     }
     elseif (false === is_null($start_id))
     {
       $sql .= ' and id <= %d ';
       $prepare[] = intval($start_id);
     }

     if (false === is_null($end_id))
     {
       $sql .= ' and id >= %d '; 
       $prepare[] = intval($end_id); 
     }


     $sql .= ' order by id DESC LIMIT %d ';
     $prepare[] = $limit;

     $sql = $wpdb->prepare($sql, $prepare);

     $results = $wpdb->get_col($sql);

     foreach($results as $item_id)
     {
          $items[] = $item_id; //$fs->getImage($item_id, 'custom');
     }

     return array_filter($items);
   }

   private function queryOptimizedItems()
   {
     global $wpdb;

     $last_id = $this->getStatus('last_item_id');
     $limit = $this->q->getOption('enqueue_limit');
     $prepare = [];
     $items = [];

     $sql = 'SELECT id FROM ' . $wpdb->prefix . 'shortpixel_meta WHERE status = %d  ';
     $prepare[] = ImageModel::FILE_STATUS_SUCCESS;

     if ($last_id > 0)
     {
        $sql .= " AND id < %d ";
        $prepare [] = intval($last_id);
     }

     $sql .= ' order by id DESC limit %d';
     $prepare[] = $limit;

     $sql = $wpdb->prepare($sql, $prepare);

     $results = $wpdb->get_col($sql);

     foreach($results as $item_id)
     {
        $items[] = $item_id;
     }

     return array_filter($items);
   }


} // class
