<?php
namespace ShortPixel\External\Offload;

use ShortPixel\Model\File\FileModel as FileModel;

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
					add_filter('shortpixel/file/virtual/heavy_features', array($this, 'extraFeatures'), 10);
		}

		public function checkIfOffloaded($bool, $url)
		{
				// Slow as it is, check nothing.
			 if ($offloadName = 's3-uploads-human')
			 {
				 return FileModel::$VIRTUAL_STATELESS;
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

		// Features like addUNlisted and retina's ( check outside the WP metadata realm ) add a lot of extra time to stateless / remote filesystems.  Disable by default to prevent pages from not loading.
		public function extraFeatures()
		{
			 return false;
		}



} // class
