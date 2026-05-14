<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Admin notice warning that LiteSpeed Cache's WebP Image Replacement conflicts
 * with ShortPixel unless the double WebP extension constant is enabled.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class LitespeedCache extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_LITESPEED_WEBP';

	/** @var string Severity level for this notice. */
  protected $errorLevel = 'warning';


	/**
	 * Checks whether the notice should be triggered by delegating to checkTriggers().
	 *
	 * @return bool True to show the notice, false to suppress it.
	 */
	protected function checkTrigger()
	{
     return $this->checkTriggers();
  }

	/**
	 * Checks whether the notice should be reset.
	 * Resets the notice when the conflicting condition is no longer met.
	 *
	 * @return bool True to reset the notice, false to keep it.
	 */
  protected function checkReset()
  {
      // Check trigger and reverse, when condition not met, reset.
      $bool = $this->checkTriggers();
      if (false === $bool)
      {
         return true;
      }
      return false;
  }

	/**
	 * Evaluates all conditions that make the LiteSpeed/WebP conflict relevant:
	 * LiteSpeed Cache plugin is active, double WebP extension is not already enabled,
	 * WebP creation is enabled in ShortPixel settings, and LiteSpeed's WebP option is on.
	 *
	 * @return bool True when the conflict is present, false otherwise.
	 */
  private function checkTriggers()
  {
    if (false === defined( 'LSCWP_DIR' )) // no litespeed.
    {
       return false;
    }

    // We already have this.
    if (true === \wpSPIO()->env()->useDoubleWebpExtension())
    {
      return false;
    }

    $settings = \wpSPIO()->settings();

    if (!$settings->createWebp) // if not active, return.
    {
       return false;
    }

    // Seemingly the main setting.
    $option = get_option('litespeed.conf.img_optm-webp');
    if ($option)
    {
       return true;
    }

    return false;
  }

	/**
	 * Builds the HTML message explaining the LiteSpeed/WebP conflict and linking
	 * to the relevant knowledge-base article.
	 *
	 * @return string HTML message string.
	 */
  protected function getMessage()
  {

      $linkurl = 'https://shortpixel.com/knowledge-base/article/how-to-deliver-the-webps-generated-with-shortpixel-with-the-litespeed-cache-plugin/';

      $message = '<p>' . sprintf(__("ShortPixel has detected that you are using the Litespeed cache with WebP Image Replacement enabled. You must %s enable the double WebP extension constant %s for WebP delivery to work correctly in this case.", 'shortpixel-image-optimiser'), '<a href="' . $linkurl . '" target="_blank">', '</a>') . '</p>';


      return $message;
   }




} // class
