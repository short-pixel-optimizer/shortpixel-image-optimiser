<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;

use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\ApiController as ApiController;
use ShortPixel\Controller\ResponseController as ResponseController;


class SpioBulk extends SpioCommandBase
{
	   /**
	   * Starts prepared queue. The bulk needs an express command to start running.
	   *
	   * ## OPTIONS
	   *
	   * [--ticks=<20>]
	   * : How much times the queue runs
	   *
	   * [--wait=<3000>]
	   * : How much miliseconds to wait for next tick.
	   *
	   * [--complete]
	   * : Run until either preparation is done or bulk has run fully.
	   *
	   * [--queue=<name>]
	   * : Either 'media' or 'custom' . Omit to run both.
	   * ---
	   * default: media,custom
	   * options:
	   *   - media
	   *   - custom
		 *
	   * ---
	   *
	   * ## EXAMPLES
	   *
	   *   wp spio run <ticks=20> <wait=3000>
	   *
	   *
	   * @when after_wp_load
	   */
	  public function start($args, $assoc)
	  {
			 $bulkControl = BulkController::getInstance();

			 $queue = $this->getQueueArgument($assoc);

			 foreach($queue as $qname)
			 {
			 	$result = $bulkControl->startBulk($qname);
			 }

			 $this->run($args, $assoc);
	     //$controller = new OptimizeController();
	     //$result = $controller->startBulk();
	  }

	 /**
	 * Enqueues the batch for bulk optimizing the media library
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<name>]
	 * : Either 'media' or 'custom' . Omit to run both.
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio bulk create
	 *

	 *
	 * @when after_wp_load
	 */
	  public function create($args, $assoc)
	  {
	    $bulkControl = BulkController::getInstance();
	    $json = new \stdClass;
	    $json->media = new \stdClass;
	    $json->custom = new \stdClass;

			$queues = $this->getQueueArgument($assoc);

			foreach($queues as $qname)
			{
	    	$stats = $bulkControl->createNewBulk($qname);
	    	$json->$qname->stats = $stats;

				\WP_CLI::Line("Bulk $qname created. Waiting to prepare");

			}

	  }

		// To ensure the bulk switch is ok.
		protected function getOptimizeController()
		{

				$optimizeController = new OptimizeController();
				$optimizeController->setBulk(true);
				return $optimizeController;
		}

			/**
			*	 Prepares items, similar to the run command. If will only run when a queue is in preparing stage and will run until everything is prepared.
			*

			 * [--queue=<name>]
		   * : Either 'media' or 'custom' . Omit to run both.
		   * ---
		   * default: media,custom
		   * options:
		   *   - media
		   *   - custom
			 *
			*/
			public function prepare($args, $assoc)
			{
					 $queues = $this->getQueueArgument($assoc);
					 $optimizeController = $this->getOptimizeController();

						$data = $optimizeController->getStartupData();

						if (! $data->total->stats->is_preparing)
						{
							 \WP_CLI::Error("Queue is not in status preparing, aborting");
					//		 break;
						}
						else
						{
							 $assoc['complete']  = true;
							 //$assoc['queue'] = $qname;
							 $assoc['wait'] = 500;
							 $this->run($args, $assoc);

						}

			}


} // CLASS
