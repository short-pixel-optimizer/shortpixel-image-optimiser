<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Admin notice warning that ShortPixel Adaptive Images and CDN delivery
 * should not be active at the same time.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class SpaiCDN extends \ShortPixel\Model\AdminNoticeModel
{

	/** @var string Unique notice key. */
	protected $key = 'MSG_SPAICDN';

	/** @var string Severity level for this notice. */
  protected $errorLevel = 'error';

	/**
	 * Checks whether the notice should be triggered.
	 * Returns true when both the SPAI plugin is active and CDN delivery is enabled.
	 *
	 * @return bool True to show the notice, false to suppress it.
	 */
	protected function checkTrigger()
	{
		  if (\wpSPIO()->env()->plugin_active('spai') && \wpSPIO()->settings()->useCDN == true)
      {
          return true;
      }
      return false;
	}

	/**
	 * Checks whether the notice should be reset.
	 * Returns true once either SPAI is deactivated or CDN delivery is disabled.
	 *
	 * @return bool True to reset the notice, false to keep it.
	 */
  protected function checkReset()
  {
    if (\wpSPIO()->env()->plugin_active('spai') && \wpSPIO()->settings()->useCDN == true)
    {
        return false;
    }

     return true;
  }

// @todo This message is not properly stringF'ed.
	/**
	 * Builds the HTML message explaining the SPAI/CDN conflict and providing
	 * a one-click button to deactivate ShortPixel Adaptive Images.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$settings = \wpSPIO()->settings();

		//$unlisted = isset($settings->currentStats['foundUnlistedThumbs']) ? $settings->currentStats['foundUnlistedThumbs'] : null;
		$unlisted_id = $this->getData('id');
		$unlisted_name = $this->getData('name');
		$unlistedFiles = (is_array($this->getData('filelist'))) ? $this->getData('filelist') : array();

		$admin_url = esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=webp'));


		$message = __("Please deactivate the ShortPixel Adaptive Images plugin if CDN delivery is enabled in ShortPixel Image Optimization. If both are activated, this can lead to over-optimization and errors on your website.", 'shortpixel-image-optimiser');


    $action = 'Deactivate';
    $path = 'shortpixel-adaptive-images/short-pixel-ai.php';
    $link = wp_nonce_url( admin_url( 'admin-post.php?action=shortpixel_deactivate_conflict_plugin&plugin=' . urlencode( $path ) ), 'sp_deactivate_plugin_nonce' );

    $message .= sprintf('<p><a class="button button-primary" href="%s">%s</a></p>', $link, __('Deactivate ShortPixel Adaptive Images'));

		return $message;

	}
}
