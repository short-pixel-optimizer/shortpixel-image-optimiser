<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class LitespeedCache extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_LITESPEED_WEBP';
  protected $errorLevel = 'warning';


	protected function checkTrigger()
	{
     return $this->checkTriggers();
  }

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

  private function checkTriggers()
  {
    if (false === defined( 'LSCWP_DIR' )) // no litespeed.
    {
       return false;
    }

    // We already have this.
    if (defined('SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION') &&  SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION)
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

  protected function getMessage()
  {

      $linkurl = 'https://shortpixel.com/knowledge-base/article/264-how-to-deliver-the-webps-generated-with-shortpixel-with-the-litespeed-cache-plugin';

      $message = '<p>' . sprintf(__("Shortpixel has detected you are you using Litespeed cache with Webp Image Replacement. You will need to %s enable double webp extension constant %s ", 'shortpixel-image-optimiser'), '<a href="' . $linkurl . '" target="_blank">', '</a>') . '</p>';


      return $message;
   }






} // class
