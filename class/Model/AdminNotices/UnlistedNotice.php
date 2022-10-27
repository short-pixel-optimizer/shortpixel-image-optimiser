<?php
namespace ShortPixel\Model\AdminNotices;

class UnlistedNotice extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_UNLISTED_FOUND';

	protected function checkTrigger()
	{
		$settings = \wpSPIO()->settings();
		if ($settings->optimizeUnlisted)
			return false;

		if(isset($settings->currentStats['foundUnlistedThumbs']) && is_array($settings->currentStats['foundUnlistedThumbs'])) {
				return true;
		}

		return false;
	}

// @todo This message is not properly stringF'ed.
	protected function getMessage()
	{
		$settings = \wpSPIO()->settings();

		$unlisted = isset($settings->currentStats['foundUnlistedThumbs']) ? $settings->currentStats['foundUnlistedThumbs'] : null;
		$unlisted_id = (is_object($unlisted) && property_exists($unlisted, 'id')) ? $unlisted->id : null;
		$unlisted_name = (is_object($unlisted) && property_exists($unlisted, 'name')) ? $unlisted->name : null;
		$unlistedFiles = (is_object($unlisted) && property_exists($unlisted->unlisted) && is_array($unlisted->unlisted)) ? $unlisted->unlisted : array();

		$message = __("<p>ShortPixel found thumbnails which are not registered in the metadata but present alongside the other thumbnails. These thumbnails could be created and needed by some plugin or by the theme. Let ShortPixel optimize them as well?</p>", 'shortpixel-image-optimiser');
		$message .= '<p>' . __("For example, the image", 'shortpixel-image-optimiser') . '
				<a href="post.php?post=' . $unlisted_id . '&action=edit" target="_blank">
						' . $unlisted_name . '
				</a> has also these thumbs not listed in metadata: '  . (implode(', ', $unlistedFiles)) . '
				</p>';

		return $message;

	}
}
