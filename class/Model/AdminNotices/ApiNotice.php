<?php
namespace ShortPixel\Model\AdminNotices;

class ApiNotice extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_NO_APIKEY';

	public function __construct()
	{
		$activationDate = \wpSPIO()->settings()->activationDate;
		if (! $activationDate)
		{
			 $activationDate = time();
			 \wpSPIO()->settings()->activationDate = $activationDate;
		}

		parent::__construct();
	}

	protected function checkTrigger()
	{
			if (\wpSPIO()->settings()->verifiedKey)
			{
				return false;
			}

			// If not key is verified.
			return true;
	}

	protected function getMessage()
	{
		$message = "<p>" . __('In order to start the optimization process, you need to validate your API Key in the '
						. '<a href="options-general.php?page=wp-shortpixel-settings">ShortPixel Settings</a> page in your WordPress Admin.','shortpixel-image-optimiser') . "
		</p>
		<p>" .  __('If you don’t have an API Key, just fill out the form and a key will be created.','shortpixel-image-optimiser') . "</p>";

		return $message;
	}
}
