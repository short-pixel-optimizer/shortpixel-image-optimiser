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
 * WP-CLI command group `wp spio bulk ...` — bulk / queue-orchestration
 * commands. Extends `SpioCommandBase` (in `wp-cli-base.php`), so every
 * command defined on the base (add, run, status, settings, clear,
 * removebackups) is also available here as `wp spio bulk <cmd>`.
 *
 * Bulk-specific commands added below:
 *
 *   - `start`   — signal bulk-processing to begin (moves preparing → running)
 *   - `auto`    — the one-shot "do everything" driver (create → prepare → start → run → finish)
 *   - `create`  — build the queue, optionally with date/migration filters
 *   - `prepare` — drive the preparing phase until it reports done
 *
 * The `getQueueController()` override at the bottom forces
 * `is_bulk = true` so every base-class command inherited here runs
 * against the bulk-mode queue rather than the "single item" queue.
 *
 * @package ShortPixel
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
	 *
	 * @param array $args   Positional args (unused).
	 * @param array $assoc  Long options (queue).
	 * @return void
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
	 * Implementation flow (loops until `$running = false`):
	 *
	 *   1. Show current settings, sleep 2s so the operator can read them.
	 *   2. Fetch startup data. Pick the "combined" stats — total when
	 *      both queues are in play, or the specific queue's stats when
	 *      `--queue=` scoped to one.
	 *   3. Branch on the combined status:
	 *      - `is_preparing`  → run `prepare` then `start`.
	 *      - `is_running`    → run `run` (drives every remaining tick).
	 *      - Nothing running but items exist → print status + `start`.
	 *      - Otherwise (done or nothing to do):
	 *        · If `done > 0` or a queue was already created this loop
	 *          → finish + break.
	 *        · Else if `!$created` → `create` (build a fresh queue).
	 *        · Else → error "nothing to do" and break.
	 *
	 * @param array $args   Positional args (unused).
	 * @param array $assoc  Long options (queue, limit, special=migrate).
	 * @return void
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
	 *
	 * Implementation notes:
	 *   - `--special=migrate` forces `queues = ['media']` regardless
	 *     of the `--queue=` arg (migration is media-only).
	 *   - Date filters (`--start-date` / `--end-date`) get bundled
	 *     into `$args['filters']` and passed to `BulkController::createNewBulk()`.
	 *   - The media queue picks up an extra `doAi` flag from
	 *     `settings->autoAIBulk` so the AI pass runs alongside
	 *     optimization if the operator opted in.
	 *
	 * @param array $args   Positional args (unused; reassigned mid-method).
	 * @param array $assoc  Long options (queue, special, start-date, end-date).
	 * @return object|null Stats object from the last-created queue (return value not typically consumed).
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

		$mediaArgs = array_merge($args, ['doMedia' => true, 'doAi' => \wpSPIO()->settings()->autoAIBulk]);

		foreach ($queues as $qname) {

			if ('media' == $qname)
			{
				$stats = $bulkControl->createNewBulk($qname, $mediaArgs);
			}			
			else
			{
				$stats = $bulkControl->createNewBulk($qname, $args);
			}

			$json->$qname->stats = $stats;

			\WP_CLI::Line("Bulk $qname created. Ready to prepare");
		}

		$this->showResponses();
		return $stats;
	}

	/**
	 * Documented-but-commented-out `restore` command.
	 *
	 * The docblock above shows what the interface *would* be
	 * (`wp spio bulk restore <start-id> <end-id> [--type=<type>]`)
	 * but the method body is a stub. Kept commented rather than
	 * deleted so re-implementation has the option surface handy.
	 * Flagged in the deferred-root-bugs memo.
	 */
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


	/**
	 * Signal each queue's `BulkController::finishBulk($queue_name)`
	 * — the completion side of the bulk lifecycle.
	 *
	 * Called from `auto()` when the loop detects a "done" state.
	 * Protected because it's not a CLI command in its own right —
	 * only the auto driver invokes it.
	 *
	 * @param array $args   Positional args (unused).
	 * @param array $assoc  Long options (queue).
	 * @return void
	 */
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
	 * Implementation notes:
	 *   - Aborts with `\WP_CLI::Error` when no queue is in a
	 *     preparing state — you can't prepare something that's
	 *     already prepared or already running.
	 *   - Sets `--wait=0.5` on the internal `run()` call so the
	 *     preparing phase ticks fast (no API round-trip involved,
	 *     just enqueue work).
	 *
	 * @param array $args   Positional args (unused).
	 * @param array $assoc  Long options (queue, limit) — mutated to inject wait=0.5 before the internal run() call.
	 * @return void
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

	/**
	 * Override of `SpioCommandBase::getQueueController()` — this
	 * command group ALWAYS runs against a bulk-mode queue, regardless
	 * of what the caller passed for `$bulk`.
	 *
	 * The parent method takes a `$bulk` flag; the argument is retained
	 * here for interface compatibility but ignored. Any base-class
	 * command inherited into `SpioBulk` (e.g. `wp spio bulk clear`,
	 * `wp spio bulk status`) picks this override up and gets the bulk
	 * queue automatically.
	 *
	 * @param bool $bulk Ignored — always constructs with `is_bulk => true`.
	 * @return QueueController
	 */
	// To ensure the bulk switch is ok. Overriding parameter in any case.
	protected function getQueueController($bulk = false)
	{
		$queueController = new QueueController(['is_bulk' => true]);
		return $queueController;
	}
} // CLASS
