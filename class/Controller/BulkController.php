<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class BulkController
{
   protected static $instance;

   public function __construct()
   {

   }

   public static function getInstance()
   {
      if ( is_null(self::$instance))
         self::$instance = new BulkController();

     return self::$instance;
   }

   /** Create a new bulk, enqueue items for bulking */
   public function createNewBulk($type = 'media')
   {
   //  $this->q->createNewBulk();
      $cache = new CacheController();

      if ($type == 'media')
      {
        $Q = MediaLibraryQueue::getInstance();
        $Q->createNewBulk(array());
      }
      elseif( $type == 'custom')
      {
        $Q = CustomQueue::getInstance();
        $Q->createNewBulk(array());
      }
      return $Q->getStats();
   }

   /*** Start the bulk run */
   public function startBulk($type = 'media')
   {
       if ($type == 'media')
       {
          $Q = MediaLibraryQueue::getInstance();
          $Q->startBulk();
       }
       elseif($type == 'custom')
       {
         $Q = CustomQueue::getInstance();
         $Q->startBulk();
       }

       $optimizeControl = OptimizeController::getInstance();
       return $optimizeControl->processQueue();

   }




}
