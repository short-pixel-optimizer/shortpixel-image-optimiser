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

   /** Create a new bulk, enqueue items for bulking
   * @param $type String media or custom is supported.
   * @param $customOp String   Not a usual optimize queue, but something else. options:
   * 'bulk-restore', or 'migrate'.
   */
   public function createNewBulk($type = 'media', $customOp = null)
   {
   //  $this->q->createNewBulk();
      $optimizeController = new OptimizeController();
      $optimizeController->setBulk(true);

      $Q = $optimizeController->getQueue($type);

      $Q->createNewBulk(array());

      if (! is_null($customOp))
      {
        $options = array();
        if ($customOp == 'bulk-restore')
        {
          $options['numitems'] = 5;
          $options['retry_limit'] = 5;
          $options['process_timeout'] = 3000;
        }
        if ($customOp == 'migrate')
        {
           $options['numitems'] = 200;
        }
        $Q->setCustomBulk($customOp, $options);
      }

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

       return $optimizeControl->processQueue(array($type));
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

	 public function getLog($logName)
	 {
  		 $fs = \wpSPIO()->filesystem();
			 $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

			 $log = $fs->getFile($backupDir->getPath() . $logName);
			 if ($log->exists())
			 	 return $log;
			 else
			 	return false;
	 }

	 public function getLogData($fileName)
	 {
		 		$logs = $this->getLogs();

				foreach($logs as $log)
				{
					 if (isset($log['logfile']) && $log['logfile'] == $fileName)
					 	 return $log;
				}

				return false;
	 }

   protected function addLog($stats, $type)
   {
        //$data = (array) $stats;
        if ($stats->done == 0 && $stats->fatal_errors == 0)
          return; // nothing done, don't log

        $data['processed'] = $stats->done;
        $data['not_processed'] = $stats->in_queue;
        $data['errors'] = $stats->errors;
        $data['fatal_errors'] = $stats->fatal_errors;
        $data['type'] = $type;
        $data['date'] = time();

        $logs = $this->getLogs();
        $fs = \wpSPIO()->filesystem();
        $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

        if (count($logs) == 10) // remove logs if more than 10.
        {
          $log = array_shift($logs);
          $log_date = $log['date'];

          $fileLog = $fs->getFile($backupDir->getPath() . 'bulk_' . $log_date . '.log');
          if ($fileLog->exists())
            $fileLog->delete();
        }

        $fileLog = $fs->getFile($backupDir->getPath() . 'current_bulk.log');
        $moveLog = $fs->getFile($backupDir->getPath() . 'bulk_' . $data['date'] . '.log');

        if ($fileLog->exists())
          $fileLog->move($moveLog);

				$data['logfile'] = 'bulk_' . $data['date'] . '.log';
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

   public static function uninstallPlugin()
   {
      delete_option(self::$logName);
   }

}  // class
