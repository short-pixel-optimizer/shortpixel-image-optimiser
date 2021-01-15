<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;

class CustomQueue extends Queue
{

   const QUEUE_NAME = 'Custom';
   const CACHE_NAME = 'CustomCache'; // When preparing, write needed data to cache.

   protected static $instance;


   public static function getInstance()
   {
      if (is_null(self::$instance))
      {
         $class = get_called_class();
         static::$instance = new $class();
      }

      return static::$instance;
   }


   public function __construct()
   {
     $shortQ = new ShortQ(static::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue(static::QUEUE_NAME);

     $this->q->setOption('numitems', 5);
     $this->q->setOption('mode', 'wait');
     $this->q->setOption('process_timeout', 7000);
     $this->q->setOption('retry_limit', 20);
   }

   public function createNewBulk($args)
   {

   }

   public function startBulk()
   {
   }

   public function prepare()
   {

   }

   public function getQueueName()
   {
      return static::QUEUE_NAME;
   }



}
