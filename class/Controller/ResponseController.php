<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\ResponseModel as ResponseModel;

class ResponseController
{

    protected static $items = array();

		protected static $queueName; // the current queueName.
		protected static $queueType;  // the urrrent queueType.
		protected static $queueMaxTries;


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


		protected static function getItem($item_id)
		{
				$itemType = self::$queueType;
				if (isset(self::$items[$itemType][$item_id]))
				{
					 $item = self::$items[$itemType][$item_id];

				}
				else {
						$item = new ResponseModel($item_id, $itemType);
				}

				return $item;
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

			$resp = self::getItem($item_id); // resonseModel

			foreach($data as $prop => $val)
			{
					if (property_exists($resp, $prop))
					{
						 $resp->prop = $val;
					}

			}

		}


		public static function outputItem($item, $format)
		{


		}

		private function responseStrings()
		{

		}


} // Class
