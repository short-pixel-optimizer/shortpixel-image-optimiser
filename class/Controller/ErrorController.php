<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

class ErrorController
{

			public function __construct()
			{

			}

			public static function start()
			{
					if (true === \wpSPIO()->env()->is_debug)
					{
				 		register_shutdown_function(array(self::class, 'checkErrors'));
					}
			}

			public static function checkErrors()
			{
				 $error = error_get_last();

				 // Nothing, happy us.
				 if (is_null($error))
				 {
					  return;
				 }
				 elseif (1 !== $error['type']) // Nothing fatal.
				 {
					  return;
				 }
				 else {
					 if (ob_get_length() > 0)
					 {
					  ob_clean(); // try to scrub other stuff
					 }
				 		echo '<PRE>' . $error['message'] .  ' in ' . $error['file']  . ' on line ' . $error['line'] . '<br> Last Item ID: ' . OptimizeController::getLastId() . '</PRE>';
						exit(' <small><br> -ShortPixel Error Handler- </small>');
				 }
			}
}
