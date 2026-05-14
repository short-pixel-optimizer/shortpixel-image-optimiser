<?php
namespace ShortPixel\Model\AdminNotices;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Admin notice suggesting the user switch the Media Library to list view
 * for a better ShortPixel experience.
 *
 * @package ShortPixel\Model\AdminNotices
 */
class ListviewNotice extends \ShortPixel\Model\AdminNoticeModel
{
	/** @var string Unique notice key. */
	protected $key = 'MSG_LISTVIEW_ACTIVE';

	/**
	 * Registers the notice to appear only on the media upload screen.
	 */
	public function __construct()
	{
		 $this->include_screens[] = 'upload';
		 parent::__construct();
	}

	/*public function load()
	{
		 parent::load();
		 // Reset this notice even when dismissed when condition changed.
		  if ($this->isDismissed() && $this->checkReset() === true)
			{
				$this->reset();
			}
	} */

	/**
	 * Checks whether the notice should be triggered.
	 *
	 * Only runs on the media upload screen. Defaults the user to list view if no
	 * preference has been set yet. Returns true when the current view mode is not list.
	 *
	 * @return bool True to show the notice, false to suppress it.
	 */
	protected function checkTrigger()
	{
		// Don't check for this, when not on this screen.
		$screen_id = \wpSPIO()->env()->screen_id;
		if ($screen_id !== 'upload')
		{
			return false;
		}

		if (! function_exists('wp_get_current_user') )
		{
			return false;

		}

			$viewMode = get_user_option('media_library_mode', get_current_user_id() );

			if ($viewMode === "" || strlen($viewMode) == 0)
			{
					// If nothing is set, set it for them.
						update_user_option( get_current_user_id(), 'media_library_mode', 'list' );
					return false;
			}
			elseif ($viewMode !== "list")
			{
					return true;
			}
			else
			{
				if (is_object($this->getNoticeObj()))
					$this->reset();
			}

			return false;
	}

	/**
	 * Checks whether the notice should be reset.
	 * Returns true once the current user has switched to list view.
	 *
	 * @return bool True to reset the notice, false to keep it.
	 */
	protected function checkReset()
	{
		if (! function_exists('wp_get_current_user') )
		{
			return false;

		}

			$current_user = wp_get_current_user();
			$currentUserID = $current_user->ID;
			$viewMode = get_user_meta($currentUserID, "wp_media_library_mode", true);

			if ($viewMode == 'list')
			{
				 return true;
			}

			return false;
	}


	/**
	 * Builds the HTML message suggesting the user switch to list view.
	 *
	 * @return string HTML message string.
	 */
	protected function getMessage()
	{
		$message = '<p><h3>' .  __('ShortPixel actions in grid view', 'shortpixel-image-optimiser') . '</h3></p>';
		$message .= __('Now you can see ShortPixel Image Optimizer\'s actions and optimization data in Grid view too! Click on any image below and you can see the ShortPixel actions and menus in the popup that opens. However, the list view provides a better experience. Click now to %sswitch to the list view%s. ', 'shortpixel-image-optimiser');
		$message = sprintf($message, '<a href="' . admin_url('upload.php?mode=list') . '">','</a>');

		return $message;

	}
}
