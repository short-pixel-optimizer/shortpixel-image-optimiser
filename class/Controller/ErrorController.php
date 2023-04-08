<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


class ErrorController
{

			public function __construct()
			{

			}

			public static function start()
			{
				 	register_shutdown_function(array(self::class, 'checkErrors'));
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
				 		echo '<PRE>' . $error['message'] .  ' in ' . $error['file']  . ' on line ' . $error['line'] . '<br> Last Item ID: ' . OptimizeController::getLastId() . '</PRE>';
						exit(' <small><br> -Shortpixel Error Handler- </small>');
				 }
			}
}
