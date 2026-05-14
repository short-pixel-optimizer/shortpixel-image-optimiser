<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortQ\ShortQ as ShortQ;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;

/**
 * Queue implementation for custom (non-media-library) image folders.
 *
 * Manages preparation and processing of images stored in custom folders
 * tracked via the shortpixel_meta database table.
 *
 * @package ShortPixel\Controller\Queue
 */
class CustomQueue extends Queue
{

   /** @var string Cache key used when preparing queue items. */
   protected $cacheName = 'CustomCache'; // When preparing, write needed data to cache.

   /** @var static Singleton instance. */
   protected static $instance;

   /**
    * Initialises the underlying ShortQ queue with custom-folder defaults.
    *
    * @param string $queueName Internal queue name passed to ShortQ.
    */
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

   /**
    * Returns the queue type identifier.
    *
    * @return string Always 'custom'.
    */
   public function getType()
   {
      return 'custom';
   }


   /**
    * Queries unoptimised custom items and enqueues them for bulk optimisation.
    *
    * @return array Preparation result with item counts and overlap flags.
    */
   protected function prepare()
   {
      $items = $this->queryItems();

      return $this->prepareItems($items);
   }

   /**
    * Queries successfully optimised custom items and enqueues them for bulk restore.
    *
    * @return array Preparation result with item counts and overlap flags.
    */
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

   /**
    * Initialises a new bulk run, applying any provided filters before delegating to the parent.
    *
    * @param array $args Optional arguments including a 'filters' key for date/ID range filters.
    * @return array The merged options passed to the parent bulk initialisation.
    */
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

   /**
    * Returns the table and field information required to build date-range filter queries.
    *
    * @return array Associative array with keys: date_field, base_query, base_prepare.
    */
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


   /**
    * Queries shortpixel_meta for custom items that have not yet been successfully optimised,
    * respecting active folders, optional ID range filters and the enqueue limit.
    *
    * @return array Array of integer item IDs ready for enqueuing.
    */
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
          $items[] = $item_id; 
     }

     return array_filter($items);
   }

   /**
    * Queries shortpixel_meta for items that have already been successfully optimised,
    * used when preparing a bulk restore operation.
    *
    * @return array Array of integer item IDs ready for enqueuing.
    */
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
