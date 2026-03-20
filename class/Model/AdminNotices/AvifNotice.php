<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use \ShortPixel\Controller\CacheController as CacheController;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Admin notice shown when the server is not configured to serve AVIF files correctly.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class AvifNotice extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_AVIF_ERROR';

	/** @var string Severity level for this notice. */
	protected $errorLevel = 'error';

	/** @var string|null Human-readable summary of the AVIF error. */
	protected $error_message;

	/** @var string|null Detailed explanation of the AVIF error. */
	protected $error_detail;


	/**
	 * Checks whether this notice should be automatically triggered.
	 * Currently disabled; use check() directly to test AVIF support.
	 *
	 * @return bool Always false.
	 */
	protected function checkTrigger()
	{
		// No Automatic Trigger.
		// Disabled the notification and this check mechanism
		//$this->check(); // @todo Hacky solution to have this retry functionality available. @todo Fix into better structure with auto-check.
		return false;
	}

	/**
	 * Performs an HTTP request to the test AVIF file and verifies the server returns
	 * the correct Content-Type header. Shows or resets the notice based on the result.
	 * Result is cached for one month on success to avoid repeated checks.
	 *
	 * @return void
	 */
	public function check()
	{
		$cache = new CacheController();
		if (apply_filters('shortpixel/avifcheck/override', false) === true)
		{ return; }


		if ($cache->getItem('avif_server_check')->exists() === false)
		{
			 $url = \WPSPIO()->plugin_url('res/img/test.avif');
			 $headers = get_headers($url);
			 $is_error = true;

			 $this->addData('headers', $headers);
			 // Defaults.
			 $this->error_message = __('AVIF server test failed. Your server may not be configured to display AVIF files correctly. Serving AVIF might cause your images not to load. Check your images, disable the AVIF option, or update your web server configuration.', 'shortpixel-image-optimiser');
			 $this->error_detail = __('The request did not return valid HTTP headers. Check if the plugin is allowed to access ' . $url, 'shortpixel-image-optimiser');

			 $response = $headers[0];

			 if (is_array($headers) )
			 {
					foreach($headers as $index => $header)
					{
							if ( strpos(strtolower($header), 'content-type') !== false )
							{
								// This is another header that can interrupt.
								if (strpos(strtolower($header), 'x-content-type-options') === false)
								{
									$contentType = $header;
								}
							}
					}

 					 // http not ok, redirect etc. Shouldn't happen.
					 if (is_null($response) || strpos($response, '200') === false)
					 {
						 $this->error_detail = sprintf(__('AVIF check could not be completed because the plugin could not retrieve %s %s %s. %s Please check the security/firewall settings and try again', 'shortpixel-image-optimiser'), '<a href="' . $url . '">', $url, '</a>', '<br>');
					 }
					 elseif(is_null($contentType) || strpos($contentType, 'avif') === false)
					 {
						 $this->error_detail = sprintf(__('The required Content-type header for AVIF files was not found. Please check this with your hosting and/or CDN provider. For more details on how to fix this issue, %s see this article %s', 'shortpixel_image_optimiser'), '<a href="https://shortpixel.com/blog/avif-mime-type-delivery-apache-nginx/" target="_blank"> ', '</a>');
					 }
					 else
					 {
							$is_error = false;
					 }
			 }

			 if ($is_error)
			 {
				   if (is_null($this->notice) || $this->notice->isDismissed() === false)
					 {
						  $this->addManual();
					 }

			 }
			 else
			 {
				 		$this->reset();

						 $item = $cache->getItem('avif_server_check');
						 $item->setValue(time());
						 $item->setExpires(MONTH_IN_SECONDS);
						 $cache->storeItemObject($item );
			 }
		}

	}

	/**
	 * Builds the HTML message describing the AVIF server configuration error,
	 * including the raw response headers and a dismiss/retry button.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
			$headers = $this->getData('headers');


			$message = '<h4>' . $this->error_message . '</h4><p>' . $this->error_detail . '</p><p class="small">' . __('Returned headers for:<br>', 'shortpixel-image-optimiser') . print_r($headers, true) .  '</p>';

      $message .= '<div>
        <button class="button button-primary notice-dismiss-action" data-dismisstype="remove" type="button" id="shortpixel-upgrade-advice" style="margin-right:10px;"><strong>' .  __('Dismiss and try again on next page load', 'shortpixel-image-optimiser') . '</strong></button>
        </div>';

			return $message;
	}
}
