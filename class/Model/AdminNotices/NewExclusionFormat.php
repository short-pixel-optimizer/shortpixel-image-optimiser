<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class NewExclusionFormat extends \ShortPixel\Model\AdminNoticeModel
{

  protected $key = 'MSG_EXCLUSION_WARNING';


	protected function checkTrigger()
	{
      $patterns = \wpSPIO()->settings()->excludePatterns;

      foreach($patterns as $index => $pattern)
      {
        if (! isset($pattern['apply']))
        {
           return true;
        }
      }
      return false;
	}

	protected function getMessage()
	{
		$message = "<p>" . __('The  new version of ShortPixel Image Optimiser also checks thumbnails for exclusions. This might change which images are optimized. Check the '
						. '<a href="options-general.php?page=wp-shortpixel-settings">ShortPixel Settings</a> page in your WordPress admin.','shortpixel-image-optimiser') . "
		</p>";

		return $message;
	}
}
