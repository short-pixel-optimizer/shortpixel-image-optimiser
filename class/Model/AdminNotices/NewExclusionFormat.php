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

      if (! is_array($patterns))
      {
         return false; 
      }

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
		$message = "<p>" . __('Since version 5.5.0, ShortPixel Image Optimiser also checks thumbnails for exclusions. This can change which images are optimized and which are excluded. Please check your exclusion rules in the '
						. '<a href="options-general.php?page=wp-shortpixel-settings&part=exclusions">ShortPixel Settings</a> page.','shortpixel-image-optimiser') . "
		</p>";

		return $message;
	}
}
