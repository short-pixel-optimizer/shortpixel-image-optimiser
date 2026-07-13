<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * ShortPixel Adaptive Images (SPAI) compatibility shim.
 *
 * SPAI intercepts image URLs on the fly to serve optimised variants
 * from its CDN. That interception also runs against SPIO's own AJAX
 * responses (which include image URLs as part of the "here's what I
 * just optimised" payload) — rewriting those URLs breaks SPIO's
 * bookkeeping.
 *
 * Solution: when the incoming AJAX request is one of SPIO's own
 * (`shortpixel_image_processing` or `shortpixel_ajaxRequest`), define
 * the `DONOTCDN` constant that SPAI honours to opt out of URL
 * rewriting for the whole request.
 *
 * Wiring is deferred to `plugins_loaded` because we need to check
 * whether SPAI is active — which requires the plugin list to be
 * populated first.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class Spai
{
		/**
		 * Defer hook wiring to `plugins_loaded` so SPAI activation can
		 * be checked reliably.
		 */
		public function __construct()
		{
			 add_action('plugins_loaded', array($this, 'addHooks'));

		}

		/**
		 * When SPAI is active AND the current request is one of SPIO's
		 * own AJAX endpoints, disable SPAI URL rewriting for the rest of
		 * the request via `preventCache()`.
		 *
		 * The nonce-verification `phpcs:ignore` markers are correct
		 * here: `$_REQUEST['action']` is only used to route to the right
		 * handler, not to authorise anything. Nonce checks happen inside
		 * the actual SPIO AJAX handlers.
		 *
		 * @return void
		 */
		public function addHooks()
		{
			  if (\wpSPIO()->env()->plugin_active('spai'))
				{
					 // Prevent SPAI doing its stuff to our JSON returns.
					 $hook_upon = array('shortpixel_image_processing', 'shortpixel_ajaxRequest');
					 if (wp_doing_ajax() &&
					 			// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
					 		 isset($_REQUEST['action']) &&
							 // phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
							 in_array($_REQUEST['action'], $hook_upon)			 )
					 {
						 	$this->preventCache();
					 }
				}
		}

		/**
		 * Define `DONOTCDN` so SPAI skips URL rewriting for the rest of
		 * this request. Idempotent — checks `defined()` first because
		 * WordPress's AJAX handler may have entered this path already.
		 *
		 * @return void
		 */
		public function preventCache()
		{
			  if (! defined('DONOTCDN'))
				{
					 define('DONOTCDN', true);
				}
		}
}

$s = new Spai();
