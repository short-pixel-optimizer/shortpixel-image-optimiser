<?php
namespace ShortPixel\Model\AdminNotices;

class LegacyNotice extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_CONVERT_LEGACY';

	protected function checkTrigger()
	{
		 return false;
	}

	protected function getMessage()
	{
		$message = '<p><strong>' .  __('ShortPixel found items in media library with a legacy optimization format!', 'shortpixel-image-optimiser') . '</strong></p>';

		$message .= '<p>' . __('Prior to version 5.0, a different format was used to store ShortPixel optimization information. ShortPixel automatically migrates the media library items to the new format when they are opened. %s Please check if your images contain the optimization information after the migration. %s Read more %s', 'shortpixel-image-optimiser') . '</p>';

		$message .=  '<p>' . __('It is recommended to migrate all items to the modern format by clicking on the button below.', 'shortpixel-image-optimser') . '</p>';
		$message .= '<p><a href="%s" class="button button-primary">%s</a></p>';

		$read_link = esc_url('https://shortpixel.com/knowledge-base/article/539-spio-5-tells-me-to-convert-legacy-data-what-is-this');
		$action_link = esc_url(admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-migrate'));
		$action_name = __('Migrate legacy data', 'shortpixel-image-optimiser');

		$message = sprintf($message, '<br>', '<a href="' . $read_link . '" target="_blank">', '</a>', $action_link, $action_name);

		return $message; 
	}
}
