<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\ResponseModel as ResponseModel;

class ResponseController
{

    protected static $items = array();

		protected static $queueName; // the current queueName.
		protected static $queueType;  // the currrent queueType.
		protected static $queueMaxTries;

		protected static $screenOutput  = 1; // see consts down

		// Some form of issue keeping
		public const ISSUE_BACKUP_CREATE = 10; // Issues with backups in ImageModel
		public const ISSUE_BACKUP_EXISTS = 11;
		public const ISSUE_OPTIMIZED_NOFILE = 12; // Issues with missing files
		public const ISSUE_QUEUE_FAILED = 13;  // Issues with enqueueing items ( Queue )
		public const ISSUE_FILE_NOTWRITABLE = 20; // Issues with file writing

		public const ISSUE_API = 50; // Issues with API - general
		public const ISSUE_QUOTA = 100; // Issues with Quota.

		public const OUTPUT_MEDIA = 1; // Has context of image, needs simple language
		public const OUTPUT_BULK = 2;
		public const OUTPUT_CLI = 3;  // Has no context, needs more information


		/**
		* @param Object QueueObject being used.
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

		public static function setOutput($output)
		{
				self::$screenOutput = $output;
		}


		protected static function getResponseItem($item_id)
		{
				$itemType = self::$queueType;
				if (is_null($itemType)) // fail-safe
					$itemType = "Unknown";

				if (isset(self::$items[$itemType][$item_id]))
				{
					 $item = self::$items[$itemType][$item_id];

				}
				else {
						Log::addTemp('Creating new ResponseModel');
						$item = new ResponseModel($item_id, $itemType);
				}

				return $item;
		}

		protected static function updateResponseItem($item)
		{
				$itemType = self::$queueType;
			  self::$items[$itemType][$item->item_id] = $item;
		}

		// ?
		//
		public static function addData($item_id, $name, $value = null)
		{
			if (! is_array($name) && ! is_object($name) )
			{
				$data = array($name => $value);
			}
			else {
				$data = $name;
			}

			$resp = self::getResponseItem($item_id); // responseModel
			Log::addTemp('Adding Data for ' . $item_id, $data);

			foreach($data as $prop => $val)
			{
					if (property_exists($resp, $prop))
					{
						 $resp->$prop = $val;
					}
					else {
						Log::addTemp('ResponseModel Wrong Property:' . $prop);
					}

			}

			self::updateResponseItem($resp);

		}


		public static function formatItem($item_id)
		{
				 $item = self::getResponseItem($item_id); // ResponseMOdel
				 Log::addTemp('Format Response Item', $item);
				 $text = $item->message;

				 if ($item->is_error)
				 	  $text = self::formatErrorItem($item, $text);
				 else {
					 	$text = self::formatRegularItem($item, $text);
				 }

				 return $text;
		}

		private static function formatErrorItem($item, $text)
		{
			switch($item->issue_type)
			{
				 case self::ISSUE_BACKUP_CREATE:
				 		if (self::$screenOutput < self::OUTPUT_CLI) // all but cli .
				 			$text .= sprintf(__(' - file %s', 'shortpixel-image-optimiser'), $item->fileName);
				 break;
			}

			if (self::$screenOutput == self::OUTPUT_CLI)
			{
				 $text = '(' . $this->queueName . ' : ' . $item->fileName . ') ' . $text . ' ';
			}

			return $text;
		}

		private static function formatRegularItem($item, $text)
		{
			  if (! $item->is_done)
				{
					 $text = sprintf(__('Optimizing - waiting for results (%d/%d)','shortpixel-image-optimiser'), $item->images_done, $item->images_total);
				}

				switch($item->apiStatus)
				{
					 case ApiController::STATUS_SUCCESS:
					 	$text = __('Item successfully optimized', 'shortpixel-image-optimiser');
					 break;

					 case ApiController::STATUS_FAIL:
					 case ApiController::ERR_TIMEOUT:
						 if (self::$screenOutput < self::OUTPUT_CLI)
						 {
							 		$text .= ' ' . sprintf(__('in %s', 'shortpixel_image_optimiser'), $item->fileName);
						 }
					 break;
				}

				if (self::$screenOutput == self::OUTPUT_CLI)
				{
					 $text = '(' . self::$queueName . ' : ' . $item->fileName . ') ' . $text . ' ';
					 $text .= sprintf(__('(cycle %d)', 'shortpixel-image-optimiser'), intval($item->tries) );
				}

				return $text;
		}


		private function responseStrings()
		{

		}


} // Class
