<?php

namespace ShortPixel\Controller;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class FrontController extends \ShortPixel\Controller
{

	private static $instance;
	protected $controller;

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

	public static function getInstance()
	{
		if (is_null(self::$instance))
			self::$instance = new static();

		return self::$instance;
	}
}
