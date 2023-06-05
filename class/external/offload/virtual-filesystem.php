<?php
namespace ShortPixel\External\Offload;

use Shortpixel\Model\File\FileModel as FileModel;

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

		public function checkIfOffloaded($bool, $url)
		{
			 if (file_exists($url))
			 {
				 return FileModel::$VIRTUAL_STATELESS;
			 }
			 return false;

		}

		public function getLocalPathByURL($path)
		{
			 return $path;
		}



} // class
