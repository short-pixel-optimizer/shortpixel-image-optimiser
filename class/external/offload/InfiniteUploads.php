<?php

namespace ShortPixel\External\Offload;

use ShortPixel\Model\File\FileModel as FileModel;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

class InfiniteUploads
{
	
		public function __construct()
		{
		//	add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10, 3);
		//	add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));
		}


		/** Checks if image is offloaded. True / False  */
		public function checkIfOffloaded($boolean, $url, $fullpath)
		{
			 
		}

		public function getLocalPathByURL()
		{

		}
	



}