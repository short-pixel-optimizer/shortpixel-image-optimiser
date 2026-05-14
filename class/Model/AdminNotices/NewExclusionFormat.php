<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Admin notice warning that exclusion rules may need review after the 5.5.0 format change.
 *
 * Since version 5.5.0, exclusion rules also apply to thumbnails. This notice is
 * shown when existing exclusion patterns lack the new 'apply' field, indicating
 * they were saved in the old format.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class NewExclusionFormat extends \ShortPixel\Model\AdminNoticeModel
{

	/** @var string Unique notice key. */
  protected $key = 'MSG_EXCLUSION_WARNING';


	/**
	 * Checks whether any stored exclusion patterns are missing the 'apply' field
	 * introduced in version 5.5.0.
	 *
	 * @return bool True when at least one outdated pattern is found, false otherwise.
	 */
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

	/**
	 * Builds the HTML message directing the user to review their exclusion rules.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$message = "<p>" . __('Since version 5.5.0, ShortPixel Image Optimiser also checks thumbnails for exclusions. This can change which images are optimized and which are excluded. Please check your exclusion rules in the '
						. '<a href="options-general.php?page=wp-shortpixel-settings&part=exclusions">ShortPixel Settings</a> page.','shortpixel-image-optimiser') . "
		</p>";

		return $message;
	}
}
