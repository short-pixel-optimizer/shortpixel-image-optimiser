<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class FrontController extends \ShortPixel\Controller
{

		private static $instance;
		protected $controller;

		public function __construct()
		{
				// Class ::
				// Figure out with Front Class is active ( or not ) .
				// Init the Output buffer listener ( or partial one? )
				// Give task to relevant class.

				if (\wpSPIO()->env()->is_front) // if is front.
				{
					$settings = \wpSPIO()->settings();
					Log::addTemp('Deliver ' . $settings->deliverWebp);

					if (true === $settings->useCDN)
					{
						 $this->controller = new Front\CDNController();
					}
					elseif($settings->deliverWebp > 0)
					{
							$this->controller = new Front\PictureController();
					}

				}
		}


		public static function getInstance()
		{
					if (is_null(self::$instance))
						 self::$instance = new static();

					return self::$instance;
		}


}
