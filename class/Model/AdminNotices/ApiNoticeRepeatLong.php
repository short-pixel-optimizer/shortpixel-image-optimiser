<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;

/**
 * Third-stage repeat notice urging the user to obtain an API key.
 * Shown after both the initial notice and the first repeat have been dismissed,
 * and at least three days have passed since activation.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class ApiNoticeRepeatLong extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_NO_APIKEY_REPEAT_LONG';

	/** @var string Severity level for this notice. */
	protected $errorLevel = 'warning';

	/**
	 * Checks whether this long-repeat notice should be triggered.
	 *
	 * Conditions: no API key verified, activation date recorded, both the original
	 * notice and the first repeat have been dismissed, and 3 days have elapsed.
	 *
	 * @return bool True to show the notice, false to suppress it.
	 */
	protected function checkTrigger()
	{
			$keyControl = ApiKeyController::getInstance();

			if (true === $keyControl->keyIsVerified())
			{
				return false;
			}

			// Is set by general ApiNotice. If not set, don't bother with the repeat.
			$activationDate = \wpSPIO()->settings()->activationDate;
			if (! $activationDate)
			{
				 return false;
			}

			$controller = AdminNoticesController::getInstance();

			// Check the original
			$firstNotice = $controller->getNoticeByKey('MSG_NO_APIKEY');
			if ($firstNotice->isDismissed() === false)
			{
				 return false;
			}

			// Check the Repeat.
			$secondNotice = $controller->getNoticeByKey('MSG_NO_APIKEY_REPEAT');
			if ($secondNotice->isDismissed() === false)
			{
				 return false;
			}

			// After 3 days.
			if (time() < $activationDate + (3 * DAY_IN_SECONDS))
			{
				 return false;
			}

			// If not key is verified and first one is dismissed, and not this one.
			return true;
	}

	/**
	 * Builds the HTML message prompting the user to get an API key.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$message = __("Your image gallery is not optimized. It takes 2 minutes to <a href='https://shortpixel.com/wp-apikey' target='_blank'>get your API key</a> and activate your ShortPixel plugin.",'shortpixel-image-optimiser') . "<BR><BR>";

		return $message;
	}
}
