<?php

namespace ShortPixel\Helper;

// Our newest Tools class
class UtilHelper
{

		public static function getPostMetaTable()
		{
			 global $wpdb;

			 return $wpdb->prefix . 'shortpixel_postmeta';
		}

}
