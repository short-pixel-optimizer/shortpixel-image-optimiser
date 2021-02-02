<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

// Class for controlling bulk and reporting.
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
      $optimizeController = new OptimizeController();
      $optimizeController->setBulk(true);

        $Q = $optimizeController->getQueue($type);
        $Q->createNewBulk(array());

        return $Q->getStats();
   }

   /*** Start the bulk run */
   public function startBulk($type = 'media')
   {

       $optimizeControl = new OptimizeController();
       $optimizeControl->setBulk(true);

       $q = $optimizeControl->getQueue($type);
       $q->startBulk();

       return $optimizeControl->processQueue();

   }

   public function finishBulk($type = 'media')
   {
     $optimizeControl = new OptimizeController();
     $optimizeControl->setBulk(true);
     
     $q = $optimizeControl->getQueue($type);

     $q->resetQueue();


   }

}
