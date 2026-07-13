<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
//use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;

use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\ResponseController as ResponseController;

use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;

/**
 * WP-CLI command group `wp spio ...` — single-item commands. Extends
 * `SpioCommandBase` (in `wp-cli-base.php`), so every base-class
 * command (add, run, status, settings, clear, removebackups) is also
 * available here as `wp spio <cmd>`.
 *
 * Single-item-specific commands added below:
 *
 *   - `restore`    — restore one optimized item from its backup
 *   - `requestAlt` — kick off an AI alt-text request for one item
 *
 * Unlike `SpioBulk`, this group does NOT override
 * `getQueueController()` — it uses the base's default (non-bulk mode).
 *
 * @package ShortPixel
 */
class SpioSingle extends SpioCommandBase
{

    /**
   * Restores the optimized item to its original state (if backups are active).
   *
   * ## OPTIONS
   *
   * <id>
   * : Media Library ID or Custom Media ID
	 *
   * [--type=<type>]
   * : media | custom
   * ---
   * default: media
   * options:
   *   - media
   *   - custom
   * ---
   *
   * ## EXAMPLES
   *
   *   wp spio restore 123
   *   wp spio restore 21 --type=custom
   *
   * @when after_wp_load
   *
   * Implementation notes:
   *   - Builds a `QueueItems::getImageItem($imageModel)` and calls
   *     `newRestoreAction()` on it before enqueuing so the queue
   *     controller knows this item is a restore, not an optimize.
   *   - Result-shape handling below is defensive: property_exists
   *     guards on `message` and `result`, then decides success /
   *     error / undetermined by looking at `->success` / `->is_error`.
   *     There's an inconsistency here (see the memo item on
   *     `$result->is_error` access without a guard).
   *
   * @param array $args        Positional args from WP-CLI. Index 0: item id.
   * @param array $assoc_args  Long options from WP-CLI (type).
   * @return void
   */
  public function restore($args, $assoc_args)
  {
      $fs = \wpSPIO()->filesystem();

      if (! isset($args[0]))
      {
        \WP_CLI::Error(__('Specify an (Media Library) Item ID', 'shortpixel-image-optimiser'));
        return;
      }
			if (! is_numeric($args[0]))
			{
				 \WP_CLI::Error(__('Item ID needs to be a number', 'shortpixel-image-optimiser'));
				 return;
			}

      $id = intval($args[0]);
			$type = $assoc_args['type'];

      $imageModel = $fs->getImage($id, $type);

      if ($imageModel === false)
			{
				 \WP_CLI::Error(__('No Image returned. Please check if the number and type are correct and the image exists', 'shortpixel-image-optimiser'));
				 return;
			}

      $qItem = QueueItems::getImageItem($imageModel);
      $qItem->newRestoreAction();

      $queueController = $this->getQueueController();

      $result  = $queueController->addItemToQueue($imageModel, ['action' => 'restore']);

			$this->showResponses();

	 		if (property_exists($result,'message') && ! is_null($result->message) && strlen($result->message) > 0)
				 $message = $result->message;
			elseif (property_exists($result, 'result') )
      {
        \WP_CLI::Error(sprintf(__("Result result exists, should not be", 'shortpixel-image-optimiser'), $result) );
      }
      else {
         $message = __('Operation didn\'t yield any messages');
      }


      if (property_exists($result, 'success') && true === $result->success)
			{
        \WP_CLI::Success($message);
			}
      elseif (true === $result->is_error)
			{
        \WP_CLI::Error(sprintf(__("Restoring Item: %s", 'shortpixel-image-optimiser'), $message) );
			}
      else {
        \WP_CLI::Error('Undetermined' . $message);
      }
  }

  	/**
	 * Add an Alt Tag to Item
	 *
	 *  <id>
	 *   : Media Library ID
	 *
	 *
	 * Implementation notes:
	 *   - Uses `getMediaImage()` (not `getImage()`) — AI features
	 *     only make sense for media-library attachments, so custom
	 *     media isn't a valid target here.
	 *   - Two `@todo` comments in-line acknowledge the method is
	 *     minimal — the real integration with the AI queue is still
	 *     coming and the current implementation just adds to the
	 *     queue with `action=requestAlt` and hopes for the best.
	 *
	 * @param array $args   Positional args from WP-CLI. Index 0: attachment id.
	 * @param array $assoc  Long options (unused — no flags on this command).
	 * @return void
	 */
	public function requestAlt($args, $assoc)
	{
		$queueController = $this->getQueueController();
		$fs = \wpSPIO()->filesystem();

		if (! isset($args[0])) {
			\WP_CLI::Error(__('Specify an Media Library Item ID', 'shortpixel-image-optimiser'));
			return;
		}

		$id = intval($args[0]);

		$imageObj = $fs->getMediaImage($id);

		if ($imageObj === false) {
			\WP_CLI::Error(__('Image object not found / non-existing in database by this ID', 'shortpixel-image-optimiser'));
		}

		// @todo When completing this script probably as for AddSingleItem with requestAlt as action, then run queue, then remove/update item for getter.

		// @todo Check OptimizeController - sendToProcessing for options / other data.

		$args = [
			'action' => 'requestAlt',

		];
		$result = $queueController->addItemToQueue($imageObj, $args);

		$this->displayResult($result, 'alttext');
	}




} // CLASS
