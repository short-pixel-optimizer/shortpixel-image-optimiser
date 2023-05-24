<?php
namespace ShortPixel\External\Offload;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VirtualFileSystem
{

		public function __construct()
		{
				$this->listen();
		}

		public function listen()
		{
					add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,2);
					add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));
		}

		public function checkIfOffloaded($url)
		{
			 if (file_exists($url))
			 {
				 return true;
			 }
			 return false;

		}

		public function getLocalPathByURL($path)
		{
			 return $path;
		}



} // class
