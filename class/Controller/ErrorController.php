<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Registers a PHP shutdown handler that catches fatal errors and outputs a
 * human-readable diagnostic when the plugin is running in debug mode.
 *
 * Activated only when `wpSPIO()->env()->is_debug` is true. On a fatal error
 * the handler scrubs any buffered output, prints the error details together
 * with the last processed queue item ID, then halts execution.
 *
 * @package ShortPixel\Controller
 */
class ErrorController
{

			/**
			 * Constructor — intentionally empty; use start() to activate the handler.
			 */
			public function __construct()
			{

			}

			/**
			 * Register the shutdown error handler when the plugin is in debug mode.
			 *
			 * Calls `register_shutdown_function` with `checkErrors` so that PHP
			 * fatal errors are intercepted and displayed with queue context.
			 * Has no effect when debug mode is off.
			 *
			 * @return void
			 */
			public static function start()
			{
					if (true === \wpSPIO()->env()->is_debug)
					{
				 		register_shutdown_function(array(self::class, 'checkErrors'));
					}
			}

			/**
			 * Shutdown callback: inspect the last PHP error and display it if fatal.
			 *
			 * Intended to be registered via `register_shutdown_function`. Exits early
			 * when there is no error (`null`) or when the error type is not `E_ERROR`
			 * (type !== 1). On a fatal error any buffered output is cleaned, a
			 * diagnostic block is echoed, and execution is halted.
			 *
			 * @return void
			 */
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
				 		echo '<PRE>' . $error['message'] .  ' in ' . $error['file']  . ' on line ' . $error['line'] . '<br> Last Item ID: ' . QueueController::getLastId() . '</PRE>';
						exit(' <small><br> -ShortPixel Error Handler- </small>');
				 }
			}
}
