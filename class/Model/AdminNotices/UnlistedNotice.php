<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Admin notice informing the user that unregistered thumbnails were found
 * alongside the regular media library images.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class UnlistedNotice extends \ShortPixel\Model\AdminNoticeModel
{

	/** @var string Unique notice key. */
	protected $key = 'MSG_UNLISTED_FOUND';

	/**
	 * Checks whether this notice should be automatically triggered.
	 * Must be triggered manually via addManual().
	 *
	 * @return bool Always false.
	 */
	protected function checkTrigger()
	{
		return false;
	}

// @todo This message is not properly stringF'ed.
	/**
	 * Builds the HTML message describing the unlisted thumbnails that were found
	 * and linking to the setting to enable optimization of unlisted thumbnails.
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

		$admin_url = esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=optimisation'));


		$message = __("<p>ShortPixel has found thumbnails that are not registered in the metadata, but are present alongside the other thumbnails. These thumbnails could be created and needed by a plugin or the theme. Should ShortPixel optimize them as well?</p>", 'shortpixel-image-optimiser');
		$message .= '<p>' . __("For example, the image", 'shortpixel-image-optimiser') . '
				<a href="post.php?post=' . $unlisted_id . '&action=edit" target="_blank">
						' . $unlisted_name . '
				</a> also has these thumbnails that are not listed in the metadata: '  . (implode(', ', $unlistedFiles)) . '
				</p>';

		$message .= '<p>' . sprintf(__('You can activate the option %s Optimize unlisted thumbnails %s in the %sImage Optimization%s area of the settings.', 'shortpixel-image-optimiser'), '<b>', '</b>', '<a href="'. $admin_url . '">','</a>') . '</p>';

		return $message;

	}
}
