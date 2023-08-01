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
		private $offloadName;

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
				$bool = $this->checkVirtualLoaders();
				if (true === $bool)
				{
 						self::$offload_instance = new VirtualFileSystem($this->offloadName);
				}

		}

		protected function checkVirtualLoaders()
		{
			 	if ( class_exists('\Stack\Config') ) // Bitpoke Stack MU
				{
						$this->offloadName = 'stack';
						return true;
				}
				elseif (defined('STACK_MEDIA_BUCKET'))
				{
						$this->offloadName = 'stack';
						return true;
				}
				elseif (class_exists('\S3_Uploads\Plugin'))
				{
					 $this->offloadName = 's3-uploads-human';
					 return true;
				}
/* (Doesn't work)
				elseif (function_exists('ud_check_stateless_media'))
				{
					 $this->offloadName = 'wp-stateless';
					 return true;
				} */
				return false;
		}

		// If As3cfInit is called check WpOffload runtime. This is later in order than plugins_loaded!
		public function initS3Offload($as3cf)
		{
					if (is_null(self::$offload_instance))
					{
							$this->offloadName = 'wp-offload';
						  self::$offload_instance = new wpOffload($as3cf);
					}
					else {
						  Log::addError('Instance is not null - other virtual component has loaded! (' . $this->offloadName . ')');
					}
		}

		public function getOffloadName()
		{
			 return $this->offloadName;
		}

}

Offloader::getInstance(); // init
