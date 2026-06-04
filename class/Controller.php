<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Helper\UiHelper as UiHelper;

/**
 * Proto parent class for all controllers.
 *
 * So far none of the controllers need or implement similar enough functions for a parent
 * to make sense. Perhaps this will change over time, so most controllers extend this parent.
 *
 * @package ShortPixel
 */

class Controller
{

	/** @var mixed|null Connected model instance. */
	protected $model;

	/** @var bool Whether the current user has sufficient privileges to use the plugin. */
	protected $userIsAllowed = false;

	public function __construct()
	{
    $this->userIsAllowed = $this->checkUserPrivileges();
	}


	/**
	 * Determines whether the current WordPress user has the required capabilities
	 * to interact with the plugin (manage_options, upload_files, or edit_posts).
	 *
	 * @return bool True if the user is allowed, false otherwise.
	 */
	  protected function checkUserPrivileges()
	  {
	    if ((current_user_can( 'manage_options' ) || current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' )))
	      return true;

	    return false;
	  }

	/**
	 * Formats a number for display, delegating to UiHelper::formatNumber.
	 *
	 * @param int|float $number    The number to format.
	 * @param int       $precision Number of decimal places. Default 2.
	 * @return string Formatted number string.
	 */
		// helper for a helper.
		protected function formatNumber($number, $precision = 2)
		{
			 return UIHelper::formatNumber($number, $precision);
		}

} // class
