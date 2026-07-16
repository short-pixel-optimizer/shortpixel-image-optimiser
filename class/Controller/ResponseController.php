<?php
namespace ShortPixel\Controller;

use ShortPixel\Controller\Api\RequestManager;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\ResponseModel as ResponseModel;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Model\Queue\QueueItem;

/**
 * Centralised store and formatter for per-item optimization responses.
 *
 * All methods are static; the class acts as a request-scoped registry keyed by
 * queue type and item ID. Callers populate ResponseModel objects through
 * `addData()` / `formatQItem()` and retrieve human-readable text via
 * `formatItem()`.
 *
 * Must be primed with `setQ()` before use so that the correct queue context
 * (name, type, max tries) is known. Output verbosity is controlled by
 * `setOutput()` and the OUTPUT_* constants.
 *
 * @package ShortPixel\Controller
 */
class ResponseController
{

    /** @var array<string, array<int, ResponseModel>> Registry of response items keyed by queue type then item ID. */
    protected static $items = array();

    /** @var string|null Name of the currently active queue (e.g. 'MediaLibrary'). */
		protected static $queueName;

    /** @var string|null Type identifier of the currently active queue (e.g. 'media'). */
		protected static $queueType;

    /** @var int|null Maximum number of retries configured for the current queue. */
		protected static $queueMaxTries;

    /** @var int Current output verbosity level; one of the OUTPUT_* constants. */
		protected static $screenOutput  = 1; // see consts down

		// Issue-type constants used on ResponseModel::$issue_type
		const ISSUE_BACKUP_CREATE = 10;        // Issues with backups in ImageModel
		const ISSUE_BACKUP_EXISTS = 11;
		const ISSUE_OPTIMIZED_NOFILE = 12;     // Issues with missing files
		const ISSUE_QUEUE_FAILED = 13;         // Issues with enqueueing items (Queue)
		const ISSUE_FILE_NOTWRITABLE = 20;     // Issues with file writing
		const ISSUE_DIRECTORY_NOTWRITABLE = 30; // Issues with directory writing
		const ISSUE_API = 50;                  // Issues with API — general
		const ISSUE_QUOTA = 100;               // Issues with Quota

		// Output verbosity levels
		const OUTPUT_MEDIA = 1; // Has context of image; uses simple language
		const OUTPUT_BULK  = 2;
		const OUTPUT_CLI   = 3; // No visual context; includes filename and queue info


		/**
		 * Prime the controller with the active queue's context.
		 *
		 * Must be called before any per-item methods. Sets the queue name, type,
		 * and max-tries values used by subsequent formatting calls. Also ensures
		 * the items registry has a bucket for the queue type.
		 *
		 * Be aware that usage outside the queue system needs to manually set the
		 * type via `addData()` with an `item_type` key.
		 *
		 * @param object $q QueueObject being used (must implement getType(),
		 *                  getQueueName(), and getShortQ()).
		 * @return void
		 */
		public static function setQ($q)
		{
			 $queueType = $q->getType();

			 self::$queueName = $q->getQueueName();
			 self::$queueType = $queueType;
			 self::$queueMaxTries = $q->getShortQ()->getOption('retry_limit');

			 if (! isset(self::$items[$queueType]))
			 {
				  self::$items[self::$queueType]  = array();
			 }
		}

		/**
		 * Set the output verbosity level for message formatting.
		 *
		 * @param int $output One of the OUTPUT_* constants (OUTPUT_MEDIA, OUTPUT_BULK, OUTPUT_CLI).
		 * @return void
		 */
		public static function setOutput($output)
		{
				self::$screenOutput = $output;
		}

		/**
		 * Retrieve or create the ResponseModel for a given item ID.
		 *
		 * Uses the current queue type as the item type. Falls back to "Unknown"
		 * when no queue context has been set. If an existing ResponseModel for
		 * this item is already in the registry it is returned directly.
		 *
		 * @param int $item_id The queue item ID.
		 * @return ResponseModel The response model for the item.
		 */
		public static function getResponseItem($item_id)
		{
				if (is_null(self::$queueType)) // fail-safe
				{
					$itemType = "Unknown";
				}
				else {
					$itemType = self::$queueType;
				}

				if (isset(self::$items[$itemType][$item_id]))
				{
					 $item = self::$items[$itemType][$item_id];
				}
				else {
					$item = new ResponseModel($item_id, $itemType);
				}

				return $item;
		}

		/**
		 * Write a ResponseModel back into the registry.
		 *
		 * @param ResponseModel $item The updated response model.
		 * @return void
		 */
		protected static function updateResponseItem($item)
		{
				$itemType = $item->item_type;
			  self::$items[$itemType][$item->item_id] = $item;
		}

		/**
		 * Attach data to the ResponseModel for a given item.
		 *
		 * Accepts either a key/value pair or an associative array/object as
		 * `$name`. Only properties that already exist on ResponseModel are set;
		 * unknown keys are silently ignored. If an `item_type` key is present
		 * and no queue context has been set yet, this method also seeds
		 * `$queueType` so that subsequent calls work outside the queue system.
		 *
		 * Logs a warning when `$item_id` is not numeric.
		 *
		 * @param int          $item_id The queue item ID.
		 * @param string|array $name    Property name, or associative array of property => value pairs.
		 * @param mixed        $value   Value to set when $name is a string; ignored when $name is an array.
		 * @return void
		 */
		public static function addData($item_id, $name, $value = null)
		{
			if (false === is_numeric($item_id))
			{
			    Log::addWarn('ResponseController issue - first parameter should be item_id' . $item_id, $name);			 
			}
			if (! is_array($name) && ! is_object($name) )
			{
				$data = array($name => $value);
			}
			else {
				$data = $name;
			}

			$item_type = (array_key_exists('item_type', $data)) ? $data['item_type'] : false;
			// If no queue / queue type is set, set it if item type is passed to ResponseController.  For items outside the queue system.
			if ($item_type && is_null(self::$queueType))
			{
				 self::$queueType = $item_type;
			}

			$resp = self::getResponseItem($item_id); // responseModel

			foreach($data as $prop => $val)
			{
					if (property_exists($resp, $prop))
					{

						 $resp->$prop = $val;
					}
					else {
					}

			}
			self::updateResponseItem($resp);
		}


		/**
		 * Return a formatted human-readable message for an item.
		 *
		 * @deprecated Use formatQItem() for queue-based items.
		 *
		 * @param int $item_id The queue item ID.
		 * @return string Formatted message string.
		 */
		public static function formatItem($item_id)
		{
				 $item = self::getResponseItem($item_id); // ResponseMOdel
				 $text = $item->message;

				 if ($item->is_error)
				 	  $text = self::formatErrorItem($item, $text);
				 else {
					 	$text = self::formatRegularItem($item, $text);
				 }

				 return $text;
		}

		/**
		 * Merge a QueueItem's result into its ResponseModel and return a formatted message.
		 *
		 * Copies non-null result properties from the QueueItem onto the ResponseModel,
		 * sets the item type from the attached ImageModel, persists via
		 * `updateResponseItem()`, then delegates to `formatItem()` for the final string.
		 *
		 * @param QueueItem $queueItem The completed queue item.
		 * @return string Formatted human-readable message.
		 */
		public static function formatQItem(QueueItem $queueItem)
		{
			$result = $queueItem->result();
			$data = $queueItem->data(); 
			$imageModel = $queueItem->imageModel; 

			$item_id = $queueItem->item_id; 

			$responseModel = self::getResponseItem($item_id); 

			//if (is_null($responseModel->item_type))
			//{
				 $responseModel->item_type = $imageModel->get('type'); 
			//}

			foreach($result as $resultName => $resultValue)
			{
				if (property_exists($responseModel, $resultName) && false === is_null($responseModel->$resultName))
				{
					 $responseModel->$resultName = $resultValue; 
				}
			}

			self::updateResponseItem($responseModel);

			return self::formatItem($item_id); 

		}

		/**
		 * Append error-specific context to a message string.
		 *
		 * Checks `$item->issue_type` and `$item->fileStatus`/`apiStatus` to
		 * append file names and item IDs. In CLI output mode the queue name and
		 * file name are prepended.
		 *
		 * @param ResponseModel $item The response model for the errored item.
		 * @param string        $text The base message text to augment.
		 * @return string The augmented message text.
		 */
		private static function formatErrorItem($item, $text)
		{
			switch($item->issue_type)
			{
				 case self::ISSUE_BACKUP_CREATE:
				 		if (self::$screenOutput < self::OUTPUT_CLI) // all but cli .
				 			$text .= sprintf(__(' - file %s', 'shortpixel-image-optimiser'), $item->fileName);
				 break;
			}

			switch($item->fileStatus)
			{
				  case ImageModel::FILE_STATUS_ERROR:
							$text .= sprintf(__('( %s %d ) ', 'shortpixel-image-optimiser'), (strtolower($item->item_type) == 'media') ?  __('Attachment ID ') : __('Custom # '), $item->item_id);
					break;
			}

			switch($item->apiStatus)
			{
				  case RequestManager::STATUS_FAIL:
							$text .= sprintf(__('( %s %d ) ', 'shortpixel-image-optimiser'), (strtolower($item->item_type) == 'media') ?  __('Attachment ID ') : __('Custom # '), $item->item_id);
					break;
			}


			if (self::$screenOutput == self::OUTPUT_CLI)
			{
				 $text = '(' . self::$queueName . ' : ' . $item->fileName . ') ' . $text . ' ';
			}

			return $text;
		}

		/**
		 * Build a success/in-progress message string for a non-error item.
		 *
		 * Inspects `$item->apiStatus` to produce status-appropriate text:
		 * waiting, enqueued, successfully optimized, timed out, or a generic
		 * non-API action completion. In CLI output mode appends the queue name,
		 * file name, and retry count.
		 *
		 * @param ResponseModel $item The response model for the item.
		 * @param string        $text The base message text to augment.
		 * @return string The formatted message text.
		 */
		private static function formatRegularItem($item, $text)
		{

			  if (! $item->is_done && $item->apiStatus == ApiController::STATUS_UNCHANGED)
				{
					 	$text = sprintf(__('Optimizing - waiting for results (%d/%d)','shortpixel-image-optimiser'), $item->images_done, $item->images_total);
				}
				if (! $item->is_done && $item->apiStatus == ApiController::STATUS_ENQUEUED)
				{
				  	$text = sprintf(__('Optimizing - Item has been sent to ShortPixel (%d/%d)','shortpixel-image-optimiser'), $item->images_done, $item->images_total);
				}

				switch($item->apiStatus)
				{
					 case RequestManager::STATUS_SUCCESS:
					 	$text = __('Item successfully optimized', 'shortpixel-image-optimiser');
					 break;

					 case RequestManager::STATUS_FAIL:
					 case ApiController::ERR_TIMEOUT:
						 if (self::$screenOutput < self::OUTPUT_CLI)
						 {
						 }
					 break;
           case RequestManager::STATUS_NOT_API:
              $action = (property_exists($item, 'action')) ? ucfirst($item->action) : __('Action', 'shortpixel-image-optimiser');
              $filename = (property_exists($item, 'fileName')) ? $item->fileName : '';
              $text = sprintf(__('%s completed for %s', 'shortpixel-image-optimiser'), $action, $item->fileName);
           break;
				}

				if (self::$screenOutput == self::OUTPUT_CLI)
				{
					 $text = '(' . self::$queueName . ' : ' . $item->fileName . ') ' . $text . ' ';
           if ($item->tries > 0)
					      $text .= sprintf(__('(cycle %d)', 'shortpixel-image-optimiser'), intval($item->tries) );
				}
				return $text;
		}

} // Class
