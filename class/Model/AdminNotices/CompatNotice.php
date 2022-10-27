<?php
namespace ShortPixel\Model\AdminNotices;

class CompatNotice extends \ShortPixel\Model\AdminNoticeModel
{
	protected $key = 'MSG_COMPAT';
	protected $errorLevel = 'warning';

	protected function checkTrigger()
	{
			$conflictPlugins = \ShortPixelTools::getConflictingPlugins();
			if (count($conflictPlugins) > 0)
			{
				$this->addData('conflicts', $conflictPlugins);
				return true;
			}
			else {
				return false;
			}
	}

	protected function getMessage()
	{
//		$conflicts = \ShortPixelTools::getConflictingPlugins();
		$conflicts = $this->getData('conflicts');
		if (! is_array($conflicts))
			$conflicts = array();

		$message = __("The following plugins are not compatible with ShortPixel and may lead to unexpected results: ",'shortpixel-image-optimiser');
		$message .= '<ul class="sp-conflict-plugins">';
		foreach($conflicts as $plugin) {
				//ShortPixelVDD($plugin);
				$action = $plugin['action'];
				$link = ( $action == 'Deactivate' )
						? wp_nonce_url( admin_url( 'admin-post.php?action=shortpixel_deactivate_conflict_plugin&plugin=' . urlencode( $plugin['path'] ) ), 'sp_deactivate_plugin_nonce' )
						: $plugin['href'];
				$message .= '<li class="sp-conflict-plugins-list"><strong>' . $plugin['name'] . '</strong>';
				$message .= '<a href="' . $link . '" class="button button-primary">' . $action . '</a>';

				if($plugin['details']) $message .= '<br>';
				if($plugin['details']) $message .= '<span>' . $plugin['details'] . '</span>';
		}
		$message .= "</ul>";

		return $message;
	}
}
