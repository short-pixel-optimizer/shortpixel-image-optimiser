<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

class SpaiCDN extends \ShortPixel\Model\AdminNoticeModel
{

	protected $key = 'MSG_SPAICDN';
  protected $errorLevel = 'error';

	protected function checkTrigger()
	{
		  if (\wpSPIO()->env()->plugin_active('spai') && \wpSPIO()->settings()->useCDN == true)
      {
          return true;
      }
      return false;
	}

  protected function checkReset()
  {
    if (\wpSPIO()->env()->plugin_active('spai') && \wpSPIO()->settings()->useCDN == true)
    {
        return false;
    }

     return true;
  }

// @todo This message is not properly stringF'ed.
	protected function getMessage()
	{
		$settings = \wpSPIO()->settings();

		//$unlisted = isset($settings->currentStats['foundUnlistedThumbs']) ? $settings->currentStats['foundUnlistedThumbs'] : null;
		$unlisted_id = $this->getData('id');
		$unlisted_name = $this->getData('name');
		$unlistedFiles = (is_array($this->getData('filelist'))) ? $this->getData('filelist') : array();

		$admin_url = esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=adv-settings'));


		$message = __("Please deactivate the ShortPixel Adaptive Images plugin when the CDN delivery is enabled in ShortPixel Image Optimization active. If both are enabled, over-optimization and errors could appear on your website", 'shortpixel-image-optimiser');


    $action = 'Deactivate';
    $path = 'shortpixel-adaptive-images/short-pixel-ai.php';
    $link = wp_nonce_url( admin_url( 'admin-post.php?action=shortpixel_deactivate_conflict_plugin&plugin=' . urlencode( $path ) ), 'sp_deactivate_plugin_nonce' );

    $message .= sprintf('<p><a class="button button-primary" href="%s">%s</a></p>', $link, __('Deactivate ShortPixel Adaptive Images'));

		return $message;

	}
}
