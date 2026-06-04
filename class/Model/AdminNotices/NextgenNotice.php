<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\ApiKeyController as ApiKeyController;

/**
 * Admin notice informing the user that NextGen Gallery integration is available
 * but not yet enabled.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class NextgenNotice extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_INTEGRATION_NGGALLERY';

	/**
	 * Checks whether the notice should be triggered.
	 *
	 * Returns true when a valid API key is set, NextGen Gallery is detected,
	 * and the NextGen integration option is not yet enabled.
	 *
	 * @return bool True to show the notice, false to suppress it.
	 */
	protected function checkTrigger()
	{

		$settings = \wpSPIO()->settings();
		$keyControl = ApiKeyController::getInstance();

		if (false === $keyControl->keyIsVerified())
		{
			return false; // no key, no integrations.
		}

		if (\wpSPIO()->env()->has_nextgen && ! $settings->includeNextGen)
		{
			 return true;
		}

		return false;
	}

	/**
	 * Builds the HTML message prompting the user to enable NextGen Gallery optimization.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$url = esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=optimisation'));
		$message = sprintf(__('You seem to be using NextGen Gallery. You can optimize your galleries with ShortPixel, but this is not currently enabled. To enable it, %sgo to settings and enable%s it!', 'shortpixel_image_optimiser'), '<a href="' . $url . '">', '</a>');

		return $message;

	}
}
