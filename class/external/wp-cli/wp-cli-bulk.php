<?php

namespace ShortPixel;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\BulkController as BulkController;

use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\ResponseController as ResponseController;

/**
 * Actions for running bulk operations from WP-CLI
 */
class SpioBulk extends SpioCommandBase
{
	/**
	 * Starts the prepared queue(s). The bulk needs an express command to start processing.
	 * Once started, the queue(s) can be processed and finished with the run command.
	 *
	 * ## OPTIONS

	 * [--queue=<name>]
	 * : Either 'media' or 'custom'. Omit the parameter to start both queues.
	 * ---
	 * default: media,custom
	 * options:
	 *   - media
	 *   - custom
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 * wp spio bulk start
	 *
	 *
	 * @when after_wp_load
	 */
	public function start($args, $assoc)
	{
		$bulkControl = BulkController::getInstance();

		$queue = $this->getQueueArgument($assoc);

		\WP_CLI::Line('Start signal for Bulk Processing given.');

		foreach ($queue as $qname) {
			$result = $bulkControl->startBulk($qname);
		}
	}


	/**
	 * Automatically Bulk Processes everything that needs to be done.
	 *
	 * [--queue=<name>]
	 * : Either 'media' or 'custom'. Omit the parameter to process both queues.
	 * ---
	 * default: media,custom
	 * options:
	 *   - media
	 *   - custom
	 * ---
	 *
	 * [--limit=<num>]
	 * : Limit the amount of items being prepared.
	 *
	 * [--special=<migrate>]
	 * : Run the migration

	 *
	 * ## EXAMPLES
	 *
	 * wp spio bulk auto
	 *
	 *
	 */
	public function auto($args, $assoc)
	{
		$queue = $this->getQueueArgument($assoc);
		$queueController = $this->getQueueController(true);

		$bulkControl = BulkController::getInstance();

		$running = true;
		$created = false;

		$this->settings();
		sleep(2); // user can digest settings

		while ($running) {
			$data = $queueController->getStartupData();
		//	print_r($data);

			// Both are present. @todo If any queues appear this will be issue. 
			if (count($queue) == 2)
			{
				$combined = $data->total->stats;
			}
			elseif('custom' == $queue[0])
			{
				 $combined = $data->custom->stats; 
			}
			else
			{
				$combined = $data->media->stats; 
			}


			// Is_finished is no queue running.
			if ($combined->is_preparing) {
				\WP_CLI::line('[Auto Bulk] Preparing .. ');
				$this->prepare($args, $assoc);
				$this->start($args, $assoc);
				\WP_CLI::line('Preparing Run done');
			} elseif ($combined->is_running) {
				\WP_CLI::line('Bulk Running ...');
				$this->run($args, $assoc); // Run All
			} elseif ($combined->total > 0 && $combined->done == 0 && $combined->is_running == false && $combined->is_preparing == false && $combined->is_finished == false) {
				\WP_CLI::line('[Auto Bulk] Starting to process');
				$this->status($args, $assoc);
				$this->start($args, $assoc);
			//} elseif ($combined->is_finished) {
			} else { 
				if ($combined->done > 0 || $created == true) // means we already ran the whole thing once.
				{
					\WP_CLI::Line('[Auto Bulk] Seems finished and done running');
					$running = false;
					$this->finishBulk($args, $assoc);

					break;
				}
				elseif (false === $created)
				{
					\WP_CLI::Line('[Auto Bulk] Creating New Queue');
					$this->create($args, $assoc);
					$created = true;
				}
				else{
					\WP_CLI::error("[Auto Bulk] : Encountered nothing to do", true);
					$running = false; // extra fallback
				}
				
			} 
		}

		\WP_CLI::log('Automatic Bulk ended');
	}

	/**
	 * Creates the queue(s) for bulk optimization of media library and/or custom media items.
	 *
	 * ## OPTIONS
	 *
	 * [--queue=<name>]
	 * : Either 'media' or 'custom'. Omit the parameter to create both queues.
	 * ---
	 * default: media,custom
	 * options:
	 *   - media
	 *   - custom
	 * [--special=<migrate>]
	 * : Run the migration
	 * 
	 * [--start-date=<start_date>]
	 * : Filter, start from this date 
	 * 
	 * [--end-date=<end_date>]
	 * : Filter, don't enqueue items old than this date. 
	 * 
	 * ## EXAMPLES
	 *
	 *  wp spio bulk create
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

		$operation = null;
		$args = $filters = []; 
		if (isset($assoc['special'])) {
			switch ($assoc['special']) {
				case 'migrate':
					$operation = 'migrate';
					$args['customOp'] = $operation; 
					$queues = array('media'); // can only have one bulk, this.
					break;
			}
		}

		if (isset($assoc['start-date']))
		{
			 $filters['start_date'] = sanitize_text_field($assoc['start-date']); 
		}
		if (isset($assoc['end-date']))
		{
			 $filters['end_date'] = sanitize_text_field($assoc['end-date']); 
		}

		if (count($filters) > 0)
		{
			 $args['filters'] = $filters; 
		}

		foreach ($queues as $qname) {
			$stats = $bulkControl->createNewBulk($qname, $args);
			$json->$qname->stats = $stats;

			\WP_CLI::Line("Bulk $qname created. Ready to prepare");
		}

		$this->showResponses();
		return $stats;
	}

	/**
	 * ## OPTIONS
	 *
	 * <start-id>
	 * : ID to start restore
	 *
	 * <end-id>
	 * : ID to stop restore
	 *
	 * [--type=<type>]
	 * : media or custom
	 * ---
	 * default: media
	 * options:
	 *   - media
	 *   - custom
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio bulk restore 0 100
	 *
	 *
	 * @when after_wp_load
	 */
	/*public function restore($args, $assoc)
		{
				\WP_CLI::Line('Not yet implemented');
		} */


	protected function finishBulk($args, $assoc)
	{
		$bulkControl = BulkController::getInstance();
		$queues = $this->getQueueArgument($assoc);

		foreach ($queues as $queue_name) {
			$bulkControl->finishBulk($queue_name);
		}
	}


	/**
	 * Prepares the items by adding them to the queue(s). It runs only when the queue is in the preparing phase and finishes when everything is prepared.
	 *
	 *
	 * [--queue=<name>]
	 * : Either 'media' or 'custom'. Omit the parameter to run both queues.
	 * ---
	 * default: media,custom
	 * options:
	 *   - media
	 *   - custom
	 * ---
	 *
	 * [--limit=<num>]
	 * : Limit the amount of items being prepared.
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio bulk prepare
	 *
	 */
	public function prepare($args, $assoc)
	{
		// $queues = $this->getQueueArgument($assoc);
		$queueController = $this->getQueueController(true);

		$data = $queueController->getStartupData();

		if (! $data->total->stats->is_preparing) {
			\WP_CLI::Error("No queues have status preparing, aborting");
		} else {
			$assoc['wait'] = 0.5;
			$bool = $this->run($args, $assoc);
		}
	}

	// To ensure the bulk switch is ok. Overriding parameter in any case.
	protected function getQueueController($bulk = false)
	{
		$queueController = new QueueController(['is_bulk' => true]);
		return $queueController;
	}
} // CLASS
