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


/**
 * Queue implementation for WordPress Media Library images.
 *
 * Manages preparation and bulk processing of attachment post items,
 * including support for AI-generated alt data operations.
 *
 * @package ShortPixel\Controller\Queue
 */
class MediaLibraryQueue extends Queue
{
   /** @var string Cache key used when preparing queue items. */
   protected $cacheName = 'MediaCache'; // When preparing, write needed data to cache.

   /** @var static Singleton instance. */
   protected static $instance;

   /** @var array Default queue options; loaded from the database on construction if available. */
   protected $options = array(
      'numitems' => 5,  // amount of items to pull per tick when optimizing
      'mode' => 'wait',
      'process_timeout' => 10000, // time between request for the image. (in milisecs)
      'retry_limit' => 30, // amount of times it will retry without errors before giving up
      'enqueue_limit' => 200, // amount of items added to the queue when preparing.
      'filters' => [],
   );

   /**
    * Initialises the underlying ShortQ queue and applies stored or default options.
    *
    * @param string $queueName Internal queue name passed to ShortQ.
    */
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

   /**
    * Returns the queue type identifier.
    *
    * @return string Always 'media'.
    */
   public function getType()
   {
      return 'media';
   }

   /**
    * Queries unoptimised media library posts and enqueues them for bulk optimisation.
    *
    * @return array Preparation result with item counts and overlap flags.
    */
   protected function prepare()
   {
      $items = $this->queryPostMeta();
      return $this->prepareItems($items);
   }

   /**
    * Queries successfully optimised media items and enqueues them for bulk restore.
    *
    * @return array Preparation result with item counts and overlap flags.
    */
   protected function prepareBulkRestore()
   {
      $items = $this->queryOptimizedItems();
      return $this->prepareItems($items);
   }

   /**
    * Queries media items with AI-generated data and enqueues them for bulk AI undo.
    *
    * @return array Preparation result with item counts and overlap flags.
    */
   protected function prepareUndoAI()
   {
      $items = $this->queryAiItems();
      return $this->prepareItems($items);
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
         unset($args['filters']); // added to options
      }

      $options = array_merge($this->options, $args);

      // Parent should save options as well.
       return parent::createNewBulk($options);
   }


   /**
    * Returns the table and field information required to build date-range filter queries
    * against the WordPress posts table.
    *
    * @return array Associative array with keys: date_field, base_query, base_prepare.
    */
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


   /**
    * Queries WordPress postmeta for attachment IDs that are candidates for optimisation,
    * respecting optional date/ID range filters, fast-mode exclusions, and the enqueue limit.
    *
    * @return array Array of integer attachment IDs ready for enqueuing.
    */
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

   /**
    * Queries shortpixel_postmeta for attachment IDs that have already been successfully
    * optimised, used when preparing a bulk restore operation.
    *
    * @return array Array of integer attachment IDs ready for enqueuing.
    */
   private function queryOptimizedItems()
   {
     $last_id = $this->getStatus('last_item_id');

     $limit = $this->q->getOption('enqueue_limit');
     $prepare = [];
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

     $items = [];

     foreach($results as $item_id)
     {
        $items[] = $item_id;
     }

     return array_filter($items);
   }


   /**
    * Queries the AI post-meta table for attachment IDs that have AI-generated data,
    * used when preparing a bulk undo-AI operation.
    *
    * @return array Array of integer attachment IDs ready for enqueuing.
    */
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
