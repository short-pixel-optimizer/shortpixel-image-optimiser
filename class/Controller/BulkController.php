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
   protected static $logName = 'shortpixel-bulk-logs';

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
       //if ($q->getStatus('items') > 0)
       $q->startBulk();

       return $optimizeControl->processQueue();
   }

   public function finishBulk($type = 'media')
   {
     $optimizeControl = new OptimizeController();
     $optimizeControl->setBulk(true);

     $q = $optimizeControl->getQueue($type);

     $stats = $q->getStats(); // for the log

     $this->addLog($stats, $type);

     $q->resetQueue();
   }

   public function getLogs()
   {
        $logs = get_option(self::$logName, array());
        return $logs;
   }

   protected function addLog($stats, $type)
   {
        //$data = (array) $stats;
        $data['items'] = $stats->done;
        $data['errors'] = $stats->errors;
        $data['fatal_errors'] = $stats->fatal_erors;
        $data['date'] = time();

        $logs = $this->getLogs();
        if (count($logs) == 10)
          array_shift($logs);

        $logs[] = $data;

        $this->saveLogs($logs);
   }

   protected function saveLogs($logs)
   {
        if (is_array($logs) && count($logs) > 0)
          update_option(self::$logName, $logs, false);
        else
          delete_option(self::$logName);
   }

}
