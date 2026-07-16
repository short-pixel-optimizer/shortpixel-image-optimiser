<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Manages bulk optimisation runs and bulk operation log persistence.
 *
 * Responsible for creating, starting, and finishing bulk queues, and for
 * maintaining the rotating list of bulk-run log files stored in the backup folder.
 *
 * @package ShortPixel\Controller
 */
class BulkController
{
   protected static $instance;
   protected static $logName = 'shortpixel-bulk-logs';

   protected $logs;

   /** Intentionally empty; use {@see getInstance()} to obtain the singleton. */
   public function __construct()
   {

   }

   /**
    * Return the singleton instance, creating it on first call.
    *
    * @return BulkController The singleton instance.
    */
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
   public function createNewBulk($type = 'media', $args = [])
   {
      $defaults = [
          'customOp' => null, 
          'filters' => [], 

      ];

      $args = wp_parse_args($args, $defaults); 

      $queueController = new QueueController(['is_bulk' => true]);

			$fs = \wpSPIO()->filesystem();
			$backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
			$current_log = $fs->getFile($backupDir->getPath() . 'current_bulk_' . $type . '.log');

			// When starting new bulk remove any open 'current logs';
			if ($current_log->exists() && $current_log->is_writable())
			{
				 $current_log->delete();
			}

      $Q = $queueController->getQueue($type);

      if (! is_null($args['customOp']))
      {
        $customOp = $args['customOp'];
        
        if ($customOp == 'bulk-restore' ||  $customOp == 'bulk-undoAI')
        {
          $args['numitems'] = 5;
          $args['retry_limit'] = 5;
          $args['process_timeout'] = 3000;
          
        }
        if ($customOp == 'migrate' || $customOp == 'removeLegacy')
        {
           $args['numitems'] = 200;
        }

				$args = apply_filters('shortpixel/bulk/custom_options', $args);

      }

      
      $options = $Q->createNewBulk($args);


      return $Q->getStats();
   }

	 /**
	  * Check whether a bulk run is currently in progress for a given queue.
	  *
	  * @param string $type Queue name: 'media' or 'custom'. Default 'media'.
	  * @return bool True if the queue has unfinished items, false otherwise.
	  */
	 public function isBulkRunning($type = 'media')
	 {
       $queueControl = new QueueController(['is_bulk' => true]);
       $queue = $queueControl->getQueue($type);

			 $stats = $queue->getStats();

			 if ( $stats->is_finished === false && $stats->total > 0)
			 {
			 	return true;
		 	 }
			 else
			 {
			 	return false;
			}
	 }

	 /**
	  * Return true if a bulk run is active on either the media or custom queue.
	  *
	  * @return bool True when at least one queue has an active bulk run.
	  */
	 public function isAnyBulkRunning()
	 {
		   $bool = $this->isBulkRunning('media');
			 if ($bool === false)
			 {
				   $bool = $this->isBulkRunning('custom');
			 }

			 return $bool;
	 }

   /**
    * Return the current custom operation name for any running queue, or false.
    *
    * Checks the media queue first, then the custom queue.  Both queues always run
    * in tandem so the first non-false result is authoritative.
    *
    * @return string|false The custom operation name (e.g. 'bulk-restore', 'migrate'),
    *                      or false when no custom operation is active.
    */
   public function getAnyCustomOperation()
   {

    $op = $this->getCustomOperation('media');

    if ($op !== false)
    {
       return $op;
    }

    $op = $this->getCustomOperation('custom');

    if ($op !== false)
    {
       return $op;
    }

    return false;

   }

   /**
    * Return the custom-operation name stored in a specific queue's custom data.
    *
    * @param string $qname Queue name: 'media' or 'custom'.
    * @return string|false The operation name, or false when none is set.
    */
   public function getCustomOperation($qname)
   {
     $queueControl = new QueueController(['is_bulk' => true]);
     $q = $queueControl->getQueue($qname);

     $op = $q->getCustomDataItem('customOperation');
     return $op;
   }

   /*** Start the bulk run. Must deliver all queues at once due to processQueue bundling */
   public function startBulk($types = 'media')
   {
       $queueControl = new QueueController(['is_bulk' => true]);

			 if (! is_array($types))
			 	 $types = array($types);

			 foreach($types as $type)
			 {
         $q = $queueControl->getQueue($type);
	       $q->startBulk();
			 }

       return  $q->getStats(); 
   }

   /**
    * Finalise a bulk run for the given queue type.
    *
    * Writes a log entry for the completed run, resets the `migrate` legacy notice
    * if the run was a migration, then resets the queue via `Queue::resetQueue()`.
    *
    * @param string $type Queue name: 'media' or 'custom'. Default 'media'.
    * @return void
    */
   public function finishBulk($type = 'media')
   {
     $queueControl = new QueueController(['is_bulk' => true]);

     $q = $queueControl->getQueue($type);

		 $this->addLog($q);

		 $op = $q->getCustomDataItem('customOperation');

		 // When finishing, remove the Legacy Notice
		 if ($op == 'migrate')
		 {
			 	AdminNoticesController::resetLegacyNotice();
		 }

     $q->resetQueue();
   }



   /**
    * Return the list of stored bulk-run log metadata from the database.
    *
    * Results are cached in memory after the first call.  Each entry is an associative
    * array with keys: processed, not_processed, errors, fatal_errors, type, date,
    * logfile, and optionally operation and total_images.
    *
    * @return array Array of log metadata entries (up to 10).
    */
   public function getLogs()
   {
        if (is_null($this->logs))
        {
          $logs = get_option(self::$logName, array());
          $this->logs = $logs;
        }
        return $this->logs;
   }

	 /**
	  * Return a FileModel for a specific bulk log file, or false if it does not exist.
	  *
	  * Validates that the resolved path is within the backup directory to prevent
	  * path-traversal attacks before returning the file object.
	  *
	  * @param string $logName Filename including extension (e.g. 'bulk_media_1234567890.log').
	  * @return \ShortPixel\Model\File\FileModel|false The log file model, or false on failure.
	  */
	 public function getLog($logName)
	 {
  		 $fs = \wpSPIO()->filesystem();
			 $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

       $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
       $backupPath = realpath($backupDir->getPath());
   
       // Construct the full path
       $fullPath = $backupDir->getPath() . $logName; // .log not passed  anymore
       $resolvedPath = realpath($fullPath);
   
       // Ensure the resolved path is within the backup directory
       if ($resolvedPath === false || strpos($resolvedPath, $backupPath) !== 0) {
           return false;  // Path traversal attempt detected
       }
   

			 $log = $fs->getFile($backupDir->getPath() . $logName);
			 if ($log->exists())
			 	 return $log;
			 else
			 	return false;
	 }



	 /**
	  * Return the metadata entry for a specific log file by filename.
	  *
	  * @param string $fileName The log filename to look up (as stored in the log metadata).
	  * @return array|false The log metadata array, or false when not found.
	  */
	 public function getLogData($fileName)
	 {
		 		$logs = $this->getLogs();

				foreach($logs as $log)
				{
					 if (isset($log['logfile']) && $log['logfile'] == $fileName)
           {
					 	 return $log;
           }
				}

				return false;
	 }

   /**
    * Persist a log entry for a completed bulk run and rotate old log files.
    *
    * Skips writing when nothing was processed and no fatal errors occurred.  Keeps a
    * maximum of 10 log entries, removing the oldest file and metadata entry when the
    * limit is reached.  Renames the current log file (`current_bulk_$type.log`) to a
    * timestamped name before saving the metadata.
    *
    * @param \ShortPixel\Controller\Queue\Queue $q The queue object from which stats are read.
    * @return void
    */
   protected function addLog($q)
   {
				$stats = $q->getStats(); // for the log
				$type = $q->getType();

        if ($stats->done == 0 && $stats->fatal_errors == 0)
				{
          return; // nothing done, don't log
				}

        $data['processed'] = $stats->done;
        $data['not_processed'] = $stats->in_queue;
        $data['errors'] = $stats->errors;
        $data['fatal_errors'] = $stats->fatal_errors;

				$webpcount = $q->getCustomDataItem('webpcount');
				$avifcount = $q->getCustomDataItem('avifcount');
				$basecount = $q->getCustomDataItem('basecount');

				if (property_exists($stats, 'images'))
					$data['total_images'] = $stats->images->images_done;

        $data['type'] = $type;
				if ($q->getCustomDataItem('customOperation'))
				{
					$data['operation'] = $q->getCustomDataItem('customOperation');
				}
        $data['date'] = time();

        $logs = $this->getLogs();
        $fs = \wpSPIO()->filesystem();
        $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

        if (count($logs) == 10) // remove logs if more than 10.
        {
          $log = array_shift($logs);
					if (isset($data['logfile']))
					{
						$logfile = $data['logfile'];

	          $fileLog = $fs->getFile($backupDir->getPath() . $logfile);
	          if ($fileLog->exists())
	            $fileLog->delete();
					}
        }

        $fileLog = $fs->getFile($backupDir->getPath() . 'current_bulk_' . $type . '.log');
        $moveLog = $fs->getFile($backupDir->getPath() . 'bulk_' . $type. '_' . $data['date'] . '.log');

        if ($fileLog->exists())
          $fileLog->move($moveLog);

				$data['logfile'] = 'bulk_' . $type . '_' . $data['date'] . '.log';
        $logs[] = $data;

        $this->saveLogs($logs);
   }

   /**
    * Persist the bulk-run log array to the database, or delete the option when empty.
    *
    * @param array $logs Array of log metadata entries to save.
    * @return void
    */
   protected function saveLogs($logs)
   {
        if (is_array($logs) && count($logs) > 0)
          update_option(self::$logName, $logs, false);
        else
          delete_option(self::$logName);
   }

   /**
    * Remove the bulk-log option from the database during plugin uninstall.
    *
    * @return void
    */
   public static function uninstallPlugin()
   {
      delete_option(self::$logName);
   }

}  // class
