<?php

namespace ShortPixel\Controller;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Front-end controller that bootstraps the active front-end delivery sub-controller.
 *
 * Selects the appropriate sub-controller (CDN or picture-tag WebP delivery) based on
 * the plugin settings during construction. Follows the singleton pattern.
 *
 * @package ShortPixel\Controller
 */
class FrontController extends \ShortPixel\Controller
{

	/** @var FrontController|null Singleton instance */
	private static $instance;

	/** @var Front\CDNController|Front\PictureController|null The active front-end sub-controller */
	protected $controller;

	/**
	 * Initialise the front-end controller by selecting and instantiating the correct
	 * delivery sub-controller based on current plugin settings.
	 */
	public function __construct()
	{

			$settings = \wpSPIO()->settings();

			if (true === $settings->useCDN) {
				$this->controller = new Front\CDNController();
			} elseif (1 == $settings->deliverWebp || 2 == $settings->deliverWebp) {
				$this->controller = new Front\PictureController();
			}
//		}
	}

	/**
	 * Return the singleton instance, creating it on first call.
	 *
	 * @return static The singleton FrontController instance.
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new static();

		return self::$instance;
	}
}
