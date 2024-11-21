<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;

class ApiNoticeRepeatLong extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_NO_APIKEY_REPEAT_LONG';
	protected $errorLevel = 'warning';

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

	protected function getMessage()
	{
		$message = __("Your image gallery is not optimized. It takes 2 minutes to <a href='https://shortpixel.com/wp-apikey' target='_blank'>get your API key</a> and activate your ShortPixel plugin.",'shortpixel-image-optimiser') . "<BR><BR>";

		return $message;
	}
}
