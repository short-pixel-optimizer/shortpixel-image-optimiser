<?php
namespace ShortPixel\External\Offload;

use Shortpixel\Model\File\FileModel as FileModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class VirtualFileSystem
{

		protected $offloadName;

		public function __construct($name)
		{
				$this->offloadName = $name;
				$this->listen();
		}

		public function listen()
		{
				//  $fs = \wpSPIO()->fileSystem()->startTrustedMode(); // @todo check if this works trusted mode forever.
					add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,2);
					add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));
		}

		public function checkIfOffloaded($bool, $url)
		{
				// Slow as it is, check nothing.
			 if ($offloadName = 's3-uploads-human')
			 {
				 return FileModel::$VIRTUAL_STATELESS;
//				  return true;
			 }

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
