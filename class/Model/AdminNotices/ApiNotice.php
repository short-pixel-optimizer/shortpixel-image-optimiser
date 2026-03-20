<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;

/**
 * Admin notice displayed when no valid API key has been configured.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class ApiNotice extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_NO_APIKEY';

	/** @var array Screens on which this notice should not appear. */
  protected $exclude_screens = ['settings_page_wp-shortpixel-settings'];

	/**
	 * Loads the notice and records the plugin activation date if not already set.
	 *
	 * @return void
	 */
	public function load()
	{
		$activationDate = \wpSPIO()->settings()->activationDate;
		if (! $activationDate)
		{
			 $activationDate = time();
			 \wpSPIO()->settings()->activationDate = $activationDate;
		}

		parent::load();
	}

	/**
	 * Checks whether the notice should be triggered.
	 * Returns true when no API key has been verified yet.
	 *
	 * @return bool True to show the notice, false to suppress it.
	 */
	protected function checkTrigger()
	{
			$keyControl = ApiKeyController::getInstance();
			if ($keyControl->keyIsVerified())
			{
				return false;
			}

			// If not key is verified.
			return true;
	}

	/**
	 * Checks whether the notice should be reset/dismissed.
	 * Returns true once a valid API key is verified.
	 *
	 * @return bool True to reset the notice, false to keep it.
	 */
  protected function checkReset()
  {

		$keyControl = ApiKeyController::getInstance();
		if ($keyControl->keyIsVerified())
		{
      return true;
    }
    return false;
  }

	/**
	 * Builds the HTML message asking the user to configure their API key.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$message = "<p>" . __('To start the optimization process, you need to validate your API key on the '
						. '<a href="options-general.php?page=wp-shortpixel-settings">ShortPixel Settings</a> page in your WordPress admin.','shortpixel-image-optimiser') . "
		</p>
		<p>" .  __('If you do not have an API key yet, just fill out the form and a key will be created.','shortpixel-image-optimiser') . "</p>";

		return $message;
	}
}
