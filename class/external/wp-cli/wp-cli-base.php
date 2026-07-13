<?php

namespace ShortPixel;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Controller\OptimizeAiController as OptimizeAiController;

use ShortPixel\Controller\BulkController as BulkController;

use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\Helper\UiHelper as UiHelper;

use ShortPixel\Controller\Queue\QueueItems as QueueItems;



/**
 * WP-CLI bootstrap — registers the plugin's `spio` and `spio bulk`
 * command groups with WP-CLI and routes debug logging to a dedicated
 * CLI log file.
 *
 * Instantiated once from `shortpixel-plugin.php` inside the
 * `lowInit()` sequence when `defined('WP_CLI') && \WP_CLI` — so this
 * class only ever loads in a WP-CLI request.
 *
 * The heavy lifting (arg parsing, queue orchestration, output
 * formatting) lives on `SpioCommandBase` below; this class just wires
 * two subclasses of that base (`SpioSingle` for `wp spio`, `SpioBulk`
 * for `wp spio bulk`) into WP-CLI's dispatcher.
 *
 * @package ShortPixel
 */
class WpCliController
{
	/** @var WpCliController|null Singleton instance held by getInstance(). */
	public static $instance;

	/** @var int Reserved / unused — declared but never assigned or read. */
	protected static $ticks = 0;

	/** @var int Reserved / unused — declared but never assigned or read. */
	protected static $emptyq = 0;

	/**
	 * Configure the SPIO logger for CLI context and register the two
	 * WP-CLI command groups.
	 *
	 * When SPIO debug mode is active, redirects the logger to a
	 * dedicated file (`shortpixel_log_wpcli` in the backup folder) so
	 * CLI-side traces don't intermix with web-request traces.
	 */
	public function __construct()
	{
		$log = \ShortPixel\ShortPixelLogger\ShortPixelLogger::getInstance();
		if (\ShortPixel\ShortPixelLogger\ShortPixelLogger::debugIsActive())
			$log->setLogPath(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log_wpcli");

		$this->initCommands();
	}

	/**
	 * Return the singleton, constructing it (and registering the CLI
	 * commands) on first call.
	 *
	 * @return WpCliController
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new WpCliController();



		return self::$instance;
	}


	/**
	 * Register the two WP-CLI command groups:
	 *
	 *   - `wp spio ...`      → `SpioSingle`  (single-item commands: restore, requestAlt, plus everything inherited from `SpioCommandBase`)
	 *   - `wp spio bulk ...` → `SpioBulk`    (bulk / queue commands: start, auto, create, prepare, plus base commands)
	 *
	 * @return void
	 */
	protected function initCommands()
	{
		\WP_CLI::add_command('spio', '\ShortPixel\SpioSingle');
		\WP_CLI::add_command('spio bulk', '\ShortPixel\SpioBulk');
	}
} // class WpCliController

/**
 * Base class for both WP-CLI command groups (`SpioSingle` and
 * `SpioBulk`). Owns the commands shared by both surfaces plus every
 * helper the subclasses need for output formatting, queue arg
 * parsing, and result rendering.
 *
 * Commands defined here (available as both `wp spio <cmd>` and
 * `wp spio bulk <cmd>`):
 *
 *   - `add`          — add a single item to the queue then process
 *   - `run`          — drive the queue with optional --ticks / --wait
 *   - `status`       — dump current queue counts
 *   - `settings`     — dump the operator-visible settings snapshot
 *   - `clear`        — reset the queue(s)
 *   - `removebackups`— walk the backup folder via BackupController
 *
 * IMPORTANT: every public method on this class (and the subclasses)
 * uses **WP-CLI's own PHPDoc grammar** — the `## OPTIONS`,
 * `[--flag=<value>]`, `default:` / `options:` blocks, and `## EXAMPLES`
 * are parsed by WP-CLI at runtime to produce `wp help spio <cmd>`
 * output. When editing these docblocks, preserve that grammar
 * verbatim — the internal-purpose prose I've added around them is
 * safe to change independently.
 *
 * All command methods take `($args, $assoc)` — WP-CLI passes
 * positional args in `$args` and long-option args in `$assoc`.
 *
 * @package ShortPixel
 */
class SpioCommandBase
{

	/** @var int Reserved / unused — declared but never assigned or read. */
	protected static $runs = 0;

	/** @var int|null Cached queue "combined status" (min of media/custom qstatus) written at the end of runClick() but never read anywhere. Dead assignment. */
	protected $last_combinedStatus;

	/**
	 * Adds a single item to the queue(s), then processes the queue(s).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Media Library ID or Custom Media ID
	 *
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
	 * [--halt]
	 * : Stops (does not process the queues) after the item is added.
	 *
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio [bulk] add 123
	 *   wp spio [bulk] add 21 --type=custom --halt
	 *
	 * @when after_wp_load
	 *
	 * @param array $args   Positional args from WP-CLI. Index 0: item id.
	 * @param array $assoc  Long options from WP-CLI (type, halt).
	 * @return void
	 */
	public function add($args, $assoc)
	{
		$controller = $this->getQueueController();

		$type = isset($assoc['type']) ? sanitize_text_field($assoc['type']) : 'media';

		if (! isset($args[0])) {
			\WP_CLI::Error(__('Specify an Media Library Item ID', 'shortpixel-image-optimiser'));
			return;
		}
		$id = intval($args[0]);

		$fs = \wpSPIO()->filesystem();
		$imageObj = $fs->getImage($id, $type);

		if ($imageObj === false) {
			\WP_CLI::Error(__('Image object not found / non-existing in database by this ID', 'shortpixel-image-optimiser'));
		}

		$result = $controller->addItemtoQueue($imageObj);

		//	$complete = isset($assoc['complete']) ? true : false;

		$message = '';
		if (property_exists($result, 'message')) {
			$message = $result->message;
		}
		if (property_exists($result, 'is_error') && $result->is_error) {
			\WP_CLI::Error(sprintf(__("while adding item: %s", 'shortpixel-image-optimiser'), $message));
		} else {
			\WP_CLI::Success($message);

			if (! isset($assoc['halt'])) {
				$this->run($args, $assoc);
			} else {
				\WP_CLI::Line(__('You can optimize images via the run command', 'shortpixel-image-optimiser'));
			}
		}

		$this->status($args, $assoc);
	}



	/**
	 * Starts processing what has been added to the processing queue(s), optionally stopping after a specified number of "ticks".
	 *
	 * A tick (or cycle) means a request sent to the API, either to send an image to be processed or to check if the API has completed processing. Use the ticks (cycles) if you want to run the script regularly (every few minutes) want to run the script.
	 *
	 * If you do not define ticks, the queue will run until everything has been processed.
	 *
	 * ## OPTIONS
	 *
	 * [--ticks=<number>]
	 * : How often the queue runs (how many ticks/cycles)
	 * ---
	 *
	 * [--wait=<seconds>]
	 * : How many seconds the system waits for next tick (cycle).
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--queue=<name>]
	 * : Either 'media' or 'custom'. Omit the parameter to run both queues.
	 * ---
	 * default: media,custom
	 * ---
	 * options:
	 *   - media
	 *   - custom
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio [bulk] run													  | Complete all processes
	 *   wp spio [bulk] run --ticks=20 --wait=3				| Ticks and wait time.
	 *   wp spio [bulk] run --queue=media							| Only run a specific queue.
	 *
	 *
	 * @when after_wp_load
	 *
	 * Implementation notes:
	 *   - Delegates to `runClick()` per tick. A `false` return from
	 *     runClick means all queues signalled done → break out of the
	 *     loop.
	 *   - `--limit` is applied *after* runClick — the loop keeps
	 *     preparing until `total >= limit` AND `is_preparing`.
	 *   - `sleep($wait)` gives the API breathing room between ticks;
	 *     defaults to 3 seconds when --wait is omitted.
	 *
	 * @param array $args   Positional args (unused by run itself, passed through to status()).
	 * @param array $assoc  Long options (ticks, wait, queue, limit).
	 * @return void
	 */
	public function run($args, $assoc)
	{
		if (isset($assoc['ticks']))
			$ticks = intval($assoc['ticks']);

		if (isset($assoc['wait']))
			$wait = intval($assoc['wait']);
		else
			$wait = 3;

		\WP_CLI::line('Process Started. Please wait for results. This can take a while');

		// Prepare limit
		if (isset($assoc['limit'])) {
			$limit = intval($assoc['limit']);
		} else {
			$limit = false;
		}

		$complete = false;
		if (! isset($assoc['ticks'])) {
			$ticks = -1;
			$complete = true; // run until all is done.
		}

		$queue = $this->getQueueArgument($assoc);

		while ($ticks > 0 || $complete == true) {
			$bool = $this->runClick($queue);
			if ($bool === false) {
				$this->status($args, $assoc);
				break;
			}

			if (false !== $limit) {
				$status = $this->getStatus();
				$total = $this->unFormatNumber($status->total->stats->total);
				$is_preparing = $status->total->stats->is_preparing;
				if ($total >= $limit && $is_preparing) {
					\WP_CLI::log(sprintf('Bulk Preparing is done. Limit reached of %s items (%s items). Use start command to signal ready. Use run to process after starting.', $limit, $status->total->stats->total));
					$this->status($args, $assoc);

					$bool = false;
					break;
				}
			}

			$ticks--;

			if (ob_get_length() !== false) {
				ob_flush();
			}

			sleep($wait);
		}

		// Done.
		$this->showResponses();
	}

	/**
	 * Single-tick worker for `run()` — drive the queue controller
	 * one cycle, emit per-queue log lines for anything worth showing,
	 * and return whether another tick is worth trying.
	 *
	 * Output filtering rules:
	 *   - Queue-empty results are silently skipped (nothing to say to
	 *     the user).
	 *   - Single-response results (prepared, enqueued) print one
	 *     `[queue] : message` line.
	 *   - Result arrays are iterated and each entry rendered via
	 *     `displayResult()` (plus a trailing `displayStatsLine('Total')`
	 *     to give the user progress after each successful op).
	 *   - A `result` (singular) property triggers a `deprecated single
	 *     result` error — no queue type is supposed to hit this shape.
	 *
	 * Return value drives the outer loop:
	 *   - `false` → all queues done or bulk-preparing complete;
	 *     `run()` should break out.
	 *   - `true`  → more work exists; another tick should run.
	 *
	 * The min-of-both `combinedStatus` reflects the JS processor's
	 * behaviour (see comment in code) — the loop only halts when the
	 * *slower* of the two queues is done.
	 *
	 * @param string[] $queueTypes Queue names to drive this tick (subset of `media` / `custom`).
	 * @return bool `true` when another tick is worth running, `false` when queues report done.
	 */
	protected function runClick($queueTypes)
	{
		ResponseController::setOutput(ResponseController::OUTPUT_CLI);

		$controller = $this->getQueueController();
		
		$results = $controller->processQueue($queueTypes);

		$totalStats = (property_exists($results, 'total') && property_exists($results->total, 'stats')) ? $results->total->stats : null;

		// Trouble
		if (is_object($results) && property_exists($results, 'status') && $results->status === false) {
			\WP_CLI::error($results->message);
		}

		foreach ($queueTypes as $qname) {


			$qresult = property_exists($results, $qname) ? $results->$qname : null; // qname really is type.
			if (is_null($qresult)) {
				continue;
			}

			if (property_exists($qresult, 'message') && ! is_null($qresult->message)) {

				// Queue Empty not interesting for CLI.
				if ($qresult->qstatus == Queue::RESULT_QUEUE_EMPTY || $qresult->qstatus == Queue::RESULT_EMPTY) {
				}
				// Result / Results have more interesting information than how much was fetched here probably.
				elseif (! property_exists($qresult, 'result') && ! property_exists($qresult, 'results')) {
					\WP_CLI::log(ucfirst($qname) . ' : ' . $qresult->message); // Single Response ( ie prepared, enqueued etc )
				}
			}

			// Result after optimizing items and such.
			if (property_exists($qresult, 'results') && is_array($qresult->results)) {
				foreach ($qresult->results as $result) {
					// Non-result results can happen ( ie. with PNG conversion ). Probably just ignore.
					/*if (false === property_exists($item, 'result') || ! is_object($item->result)) {
						continue;
					} */

					//$result = (true === property_exists($item, 'result')) ? $item->result : null;
					$counts = (true === property_exists($result, 'counts')) ? $result->counts : null;

					$apiStatus = property_exists($result, 'apiStatus') ? $result->apiStatus : null;

					$this->displayResult($result, $qname, $counts);

					// prevent spamming.
					if (! is_null($totalStats) && $apiStatus == ApiController::STATUS_SUCCESS) {
						$this->displayStatsLine('Total', $totalStats);
					}
				}
			}
			if (property_exists($qresult, 'result') && is_object($qresult->result)) {
				Log::addWarn('WP - Single Result', $qresult);
				\WP_CLI::error('Came back as deprecated single result');
				
				//$this->displayResult($qresult->result, $qname);
			}
		}

		// Combined Status. Implemented from shortpixel-processor.js
		$mediaStatus = $customStatus = 100;

		if (property_exists($results, 'media') && property_exists($results->media, 'qstatus')) {
			$mediaStatus = $results->media->qstatus;
		}
		if (property_exists($results, 'custom') && property_exists($results->custom, 'qstatus')) {
			$customStatus = $results->custom->qstatus;
		}

		// The lowest queue status (for now) equals earlier in process. Don't halt until both are done.
		if ($mediaStatus <= $customStatus)
			$combinedStatus = $mediaStatus;
		else
			$combinedStatus = $customStatus;

		if ($combinedStatus == Queue::RESULT_QUEUE_EMPTY) {
			\WP_CLI::log('All Queues report processing has finished');

			return false;
		} elseif ($combinedStatus == Queue::RESULT_PREPARING_DONE) {
			\WP_CLI::log(sprintf('Bulk Preparing is done. %s items. Use start command to signal ready. Use run to process after starting.', $results->total->stats->total));
			return false;
		}

		$this->last_combinedStatus = $combinedStatus;

		return true;
	}

	/**
	 * Render a single optimizer result to the CLI. Called per item
	 * from `runClick()` (and directly from `SpioSingle::requestAlt` /
	 * `restore`).
	 *
	 * Three output shapes, keyed off `$result->apiStatus`:
	 *
	 *   1. **STATUS_SUCCESS** — banner + message + a formatted
	 *      improvements table (main + per-thumbnail % savings) + a
	 *      one-liner summary of the credits used (`processed / images
	 *      / webps / avifs`).
	 *   2. **STATUS_NOT_API** — just print the message (no
	 *      formatting, no banner).
	 *   3. **Anything else** — either `\WP_CLI::error()` (when
	 *      `is_error` is true) or a plain `line` with the message.
	 *
	 * @param object      $result Result object from the queue tick. Expected: apiStatus, message, improvements, is_error.
	 * @param string      $type   Queue type this result came from (`media` / `custom`) — used for context only.
	 * @param object|null $counts Optional counts object (baseCount, webpCount, avifCount, creditCount).
	 * @return void
	 */
	// Function for Showing JSON output of Optimizer regarding the process.
	protected function displayResult($result, $type, $counts = null)
	{
		$apiStatus = property_exists($result, 'apiStatus') ? $result->apiStatus : null;


		if ($apiStatus === ApiController::STATUS_SUCCESS) {
			\WP_CLI::line(' ');
			\WP_CLI::line('---------------------------------------');
			\WP_CLI::line(' ');
			\WP_CLI::line(' ' . $result->message); // testing

			if (property_exists($result, 'improvements')) {
				$outputTable = array();
				$improvements = $result->improvements;

				if (isset($improvements['main'])) {
					$outputTable[] = array('name' => 'main', 'improvement' => $improvements['main'][0] . '%');
				}

				if (isset($improvements['thumbnails'])) {
					foreach ($improvements['thumbnails'] as $thumbName => $optData) {
						$outputTable[] = array('name' => $thumbName, 'improvement' => $optData[0] . '%');
					}
				}

				$outputTable[] = array('name' => ' ', 'improvement' => ' ');
				$outputTable[] = array('name' => __('Total', 'shortpixel-image-optimiser'), 'improvement' => $improvements['totalpercentage'] . '%');

				\WP_CLI\Utils\format_items('table', $outputTable, array('name', 'improvement'));

				if (! is_null($counts)) {
					$baseMsg = sprintf(
						' This job, %d files were processed: %d images',
						$counts->creditCount,
						$counts->baseCount
					);

					if ($counts->webpCount > 0)
						$baseMsg .= sprintf(', %d WebPs ', $counts->webpCount);
					if ($counts->avifCount > 0)
						$baseMsg .= sprintf(', %d AVIFs ', $counts->avifCount);

					\WP_CLI::line($baseMsg);
				}
				\WP_CLI::line(' ');
				\WP_CLI::line('---------------------------------------');
				\WP_CLI::line(' ');
			}
		} // success
		elseif ($apiStatus === ApiController::STATUS_NOT_API) {
			$message = property_exists($result, 'message') ? $result->message : '';

			\WP_CLI::line($message);
		} else {
			if (property_exists($result, 'is_error') && $result->is_error) {
				\WP_CLI::error($result->message, false);
			} else {
				\WP_CLI::line($result->message);
			}
		}
	}

	/**
	 * Render a one-line progress summary for a queue's stats bucket.
	 *
	 * Called from `runClick()` after each successful op, from
	 * `status()` at the bottom of the queue table, and from
	 * `SpioBulk::auto()` between phases.
	 *
	 * @param string $name  Human-readable queue label (e.g. "media", "Total").
	 * @param object $stats Stats snapshot: done, fatal_errors, total, percentage_done, awaiting.
	 * @return void
	 */
	protected function displayStatsLine($name, $stats)
	{
		$line = sprintf('Current Status for %s : (%s/%s) Done (%s%%), %s awaiting %s errors --', $name, ($stats->done + $stats->fatal_errors), $stats->total, $stats->percentage_done, ($stats->awaiting), $stats->fatal_errors);

		\WP_CLI::line($line);
	}

	/**
	 * Displays the current status of the processing queue(s)
	 *
	 * [--show-debug]
	 * :  Dumps more information for debugging purposes
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio [bulk] status [--show-debug]
	 *
	 * @param array $args   Positional args (unused).
	 * @param array $assoc  Long options (queue, show-debug).
	 * @return void
	 */
	public function status($args, $assoc)
	{
		$queue = $this->getQueueArgument($assoc);
		$startupData = $this->getStatus();

		$items = array();
		$fields = array('queue name', 'in queue', 'in process', 'fatal errors', 'done', 'total', 'preparing', 'running', 'finished');

		foreach ($queue as $queue_name) {
			$stats = $startupData->$queue_name->stats;

			$item = array(
				'queue name' => $queue_name,
				'in queue' => $stats->in_queue,
				'in process' => $stats->in_process,
				'fatal errors' => $stats->fatal_errors,
				'done' => $stats->done,
				'total' => $stats->total,
				'preparing' => ($stats->is_preparing) ? __('Yes', 'shortpixel-image-optimiser') : __('No', 'shortpixel-image-optimiser'),
				'running' => ($stats->is_running) ? __('Yes', 'shortpixel-image-optimiser') : __('No', 'shortpixel-image-optimiser'),
				'finished' => ($stats->is_finished) ? __('Yes', 'shortpixel-image-optimiser') : __('No', 'shortpixel-image-optimiser'),
			);

			$items[] = $item;

			if (isset($assoc['show-debug'])) {
				print_r($stats);
			}
		}

		\WP_CLI::Line("--- Current Status ---");
		\WP_CLI\Utils\format_items('table', $items, $fields);
		\WP_CLI::Line($this->displayStatsLine('Total', $startupData->total->stats));
	}

	/**
	 * Displays the key settings that are applied when executing commands with WP-CLI.
	 *
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio [bulk] settings
	 *
	 * @return void
	 */
	public function settings()
	{
		$settings = \WPspio()->settings();

		$items = array();
		$fields = array('setting', 'value');

		$items[] = array('setting' => 'Compression', 'value' => UiHelper::compressionTypeToText($settings->compressionType));
		$items[] = array('setting' => 'Image Backup', 'value' => $this->textBoolean($settings->backupImages, true));
		$items[] = array('setting' => 'Processed Thumbnails', 'value' => $this->textBoolean($settings->processThumbnails, true));
		$items[] = array('setting' => ' ', 'value' => ' ');
		$items[] = array('setting' => 'Creates Webp', 'value' => $this->textBoolean($settings->createWebp));
		$items[] = array('setting' => 'Creates Avif', 'value' =>  $this->textBoolean($settings->createAvif));

		\WP_CLI\Utils\format_items('table', $items, $fields);
	}

	 /**
	 * Auto-removes backups according to settings. Use with care. 
	 *
	 *
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio removebackups
	 *
	 * @return void
	 */
	public function removebackups()
	{
		$backupController = BackupController::getBackupController();
		$backupController->cliRemoveBackups();

	}

	/**
	 * Clears the Queue(s)
	 *
	 *
	 * [--queue=<name>]
	 * : Either 'media' or 'custom'. Omit the parameter to clear both queues.
	 * ---
	 * default: media,custom
	 * options:
	 *   - media
	 *   - custom
	 *
	 * ## EXAMPLES
	 *
	 *   wp spio [bulk] clear
	 *
	 * @param array $args   Positional args (unused).
	 * @param array $assoc  Long options (queue).
	 * @return void
	 */
	public function clear($args, $assoc)
	{
		$queues = $this->getQueueArgument($assoc);
		$queueController = $this->getQueueController();

		foreach ($queues as $type) {
			$queue = $queueController->getQueue($type);
			$queue->resetQueue();
		}

		\WP_CLI::Success(__('Queue(s) cleared', 'shortpixel-image-optimiser'));
	}


	/**
	 * Render a truthy/falsy value as a localised "Yes" / "No" string
	 * for the `settings()` table.
	 *
	 * The optional `$colored` flag would wrap the output in
	 * `\WP_CLI::colorize()` codes (green Yes / red No), but that path
	 * is disabled at the top of the method because of a known WP-CLI
	 * php-cli-tools bug (linked in the inline comment). Left as
	 * scaffolding for when the upstream bug ships a fix.
	 *
	 * @param mixed $bool    Value to interpret truthy/falsy.
	 * @param bool  $colored Passed by callers hopefully; currently forced to false.
	 * @return string Localised "Yes" or "No".
	 */
	//  Colored is buggy, so off for now -> https://github.com/wp-cli/php-cli-tools/issues/134
	private function textBoolean($bool, $colored = false)
	{
		$colored = false;
		$values = array('', '');

		if ($bool) {
			if ($colored) {
				$values = array('%g', '%n');
			}
			$res =  vsprintf(__('%sYes%s', 'shortpixel-image-optimiser'), $values);
			if ($colored)
				$res = \WP_CLI::colorize($res);
		} else {
			if ($colored) {
				$values = array('%r', '');
			}
			$res = vsprintf(__('%sNo%s', 'shortpixel-image-optimiser'), $values);
			if ($colored)
				$res = \WP_CLI::colorize($res);
		}

		return $res;
	}

	/**
	 * Fetch a fresh queue-startup snapshot — the same payload the
	 * front-end processor JS gets, containing per-queue stats.
	 *
	 * @return object Startup data object with `->media` / `->custom` / `->total` sub-objects.
	 */
	protected function getStatus()
	{
		$optimizeController = $this->getQueueController();
		$startupData = $optimizeController->getStartupData();
		return $startupData;
	}

	/**
	 * Placeholder for streaming ResponseController buffered messages
	 * to CLI output — **currently a no-op stub**.
	 *
	 * Called from three places (`run` at the end, `SpioBulk::create`
	 * at the end, `SpioSingle::restore` mid-way). Every one of those
	 * calls silently does nothing. The `@todo` in-line acknowledges
	 * it's deferred. Flagged in the deferred-root-bugs memo — either
	 * wire the ResponseController path (uncomment the body) or remove
	 * the calls and this method together.
	 *
	 * @return false Always false.
	 */
	protected function showResponses()
	{
		return false; // @todo Pending responseControl, offf.

		/*$responses = ResponseController::getAll();

         foreach ($responses as $response)
         {
             if ($response->is('error'))
                \WP_CLI::Error($response->message, false);
             elseif ($response->is('success'))
                \WP_CLI::Success($response->message);
             else
               \WP_CLI::line($response->message);
         } */
	}

	/**
	 * Parse the `--queue` associative arg into a list of queue names.
	 *
	 * Accepted shapes:
	 *   - `--queue=media`            → `['media']`
	 *   - `--queue=media,custom`     → `['media', 'custom']`
	 *   - flag omitted               → `['media', 'custom']` (both)
	 *
	 * Each entry is `sanitize_text_field`'d — WP-CLI callers can only
	 * pass strings but the sanitisation is belt-and-braces.
	 *
	 * @param array $assoc Long-option arg map from WP-CLI.
	 * @return string[] Queue names to operate on.
	 */
	protected function getQueueArgument($assoc)
	{

		if (isset($assoc['queue'])) {
			if (strpos($assoc['queue'], ',') !== false) {
				$queue = explode(',', $assoc['queue']);
				$queue = array_map('sanitize_text_field', $queue);
			} else
				$queue = array(sanitize_text_field($assoc['queue']));
		} else
			$queue = array('media', 'custom');

		return $queue;
	}

	/**
	 * Factory for the QueueController used across the CLI commands.
	 *
	 * The `$bulk` flag controls whether the controller enters bulk
	 * mode (larger batches, different queue semantics). `SpioBulk`
	 * overrides this method to force `is_bulk => true` regardless of
	 * the passed argument.
	 *
	 * @param bool $bulk Whether to construct in bulk mode.
	 * @return QueueController
	 */
	// To ensure the bulk switch is ok.
	protected function getQueueController($bulk = false)
	{
		$queueController = new QueueController(['is_bulk' => $bulk]);
		return $queueController;
	}

	/*
    protected function getOptimizeAiController()
    {
      $optimizeController = new OptimizeAiController();
      return $optimizeController;

    }
*/
	/**
	 * Strip thousands-separator commas and decimal points from a
	 * number string so it can be compared as an integer (used by
	 * `run()` when applying `--limit`).
	 *
	 * @param string $string Number string possibly containing `,` and `.` separators.
	 * @return string Digits-only string.
	 */
	private function unFormatNumber($string)
	{
		$string = str_replace(',', '', $string);
		$string = str_replace('.', '', $string);

		return $string;
	}
} // Class SpioCommandBase
