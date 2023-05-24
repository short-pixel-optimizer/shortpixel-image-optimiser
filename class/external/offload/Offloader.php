<?php
namespace ShortPixel\External\Offload;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Class to check what offloader to use and load it. To offload.
class Offloader
{
		private static $instance;
		private static $offload_instance;
		private $offLoadName;

		public static function getInstance()
		{
			 if (is_null(self::$instance))
			 {
				  self::$instance = new Offloader();
			 }

			 return self::$instance;
		}

		public function __construct()
		{
			  add_action('plugins_loaded', array($this, 'load'));
				add_action('as3cf_init', array($this, 'initS3Offload'));
		}

		public function load()
		{
				if (class_exists('\Stack\Config'))
				{
						$this->offLoadName = 'stack';
 						self::$offload_instance = new VirtualFileSystem();
				}

		}

		// If As3cfInit is called check WpOffload runtime. This is later in order than plugins_loaded!
		public function initS3Offload($as3cf)
		{
					if (is_null(self::$offload_instance))
					{
							$this->offLoadName = 'wp-offload';
						  self::$offload_instance = new wpOffload($as3cf);
					}
					else {
						  Log::addError('Instance is not null - other virtual component has loaded!');
					}

		}

		public function getOffloadName()
		{
			 return $this->offLoadName;
		}

}

Offloader::getInstance(); // init
