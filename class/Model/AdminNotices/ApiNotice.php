<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;


class ApiNotice extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_NO_APIKEY';

  protected $exclude_screens = ['settings_page_wp-shortpixel-settings'];

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

  protected function checkReset()
  {

		$keyControl = ApiKeyController::getInstance();
		if ($keyControl->keyIsVerified())
		{
      return true;
    }
    return false;
  }

	protected function getMessage()
	{
		$message = "<p>" . __('To start the optimization process, you need to validate your API key on the '
						. '<a href="options-general.php?page=wp-shortpixel-settings">ShortPixel Settings</a> page in your WordPress admin.','shortpixel-image-optimiser') . "
		</p>
		<p>" .  __('If you do not have an API key yet, just fill out the form and a key will be created.','shortpixel-image-optimiser') . "</p>";

		return $message;
	}
}
