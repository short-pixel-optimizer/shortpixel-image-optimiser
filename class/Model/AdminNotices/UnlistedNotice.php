<?php
namespace ShortPixel\Model\AdminNotices;

class UnlistedNotice extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_UNLISTED_FOUND';

	protected function checkTrigger()
	{
		/*$settings = \wpSPIO()->settings();
		if ($settings->optimizeUnlisted)
			return false;

		if(isset($settings->currentStats['foundUnlistedThumbs']) && is_array($settings->currentStats['foundUnlistedThumbs'])) {
				return true;
		} */

		return false;
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


		$message = __("<p>ShortPixel found thumbnails which are not registered in the metadata but present alongside the other thumbnails. These thumbnails could be created and needed by some plugin or by the theme. Let ShortPixel optimize them as well?</p>", 'shortpixel-image-optimiser');
		$message .= '<p>' . __("For example, the image", 'shortpixel-image-optimiser') . '
				<a href="post.php?post=' . $unlisted_id . '&action=edit" target="_blank">
						' . $unlisted_name . '
				</a> has also these thumbs not listed in metadata: '  . (implode(', ', $unlistedFiles)) . '
				</p>';

		$message .= '<p>' . sprintf(__('You can enable optimizing %s Unlisted Images %s in the %s settings %s', 'shortpixel-image-optimiser'), '<b>', '</b>', '<a href="'. $admin_url . '">','</a>') . '</p>';		

		return $message;

	}
}
