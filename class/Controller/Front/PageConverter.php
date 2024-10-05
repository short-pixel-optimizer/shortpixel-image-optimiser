<?php
namespace ShortPixel\Controller\Front;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


class PageConverter extends \ShortPixel\Controller
{

	protected $site_url;

	public function __construct()
	{
			$this->site_url =  get_site_url();
	}

	protected function shouldConvert()
	{
		$env = wpSPIO()->env();
		if ($env->is_admin || $env->is_ajaxcall || $env->is_jsoncall || $env->is_croncall)
		{
			Log::addTemp('DENIED PAGE' . $_SERVER['REQUEST_URI']);
			return false;
		}

		// Doesn't seem to work like this .  @todo The front processor is also triggered when an image / other document is not found and instead the 404 page is returned by WP .  Need to detect this somehow, since it's extra load.
		/* if (is_404())
		{
		} */

		return true;
	}

	protected function startOutputBuffer($callback) {
			$call = array($this, $callback);
			ob_start( $call );

	}

	// Parse the URL src of the image to see components.
	protected function parseImageSource($src)
	{


	}

}
