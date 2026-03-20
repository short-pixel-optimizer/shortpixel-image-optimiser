<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\QuotaController as QuotaController;

/**
 * Central place for user / access checking, roles and capabilities.
 *
 * Manages SPIO-specific capabilities and maps them to WordPress user roles,
 * providing a single point of truth for permission checks throughout the plugin.
 *
 * @package ShortPixel\Model
 */
class AccessModel
{

  /**
   * Singleton instance of this class.
   *
   * @var AccessModel|null
   */
	private static $instance;

  /**
   * Array of known SPIO capabilities mapped to WordPress role/cap equivalents.
   *
   * @var array<string, string|array>
   */
	private $caps;

  /**
   * The current WordPress user ID.
   *
   * @var int
   */
	private $current_user_id;


	public function __construct()
	{
		 $this->setDefaultPermissions();
	}

	/**
	 * Defines the default mapping between SPIO capabilities and WordPress capabilities.
	 *
	 * Applies the 'shortpixel/init/permissions' filter so third parties can
	 * extend or override the capability map.
	 *
	 * @return void
	 */
	protected function setDefaultPermissions()
	{

			$spioCaps = array(
					'notices' =>  'activate_plugins',				// used in AdminNoticesController
					'quota-warning' => 'manage_options',    // used in AdminController
					'image_all' =>  'edit_others_posts',
					'image_user' => 'edit_post',
					'custom_all' => 'edit_others_posts',
					'is_admin_user' => 'manage_options',
					'is_editor' => 'edit_others_posts',  // used in AjaxController
					'is_author' => 'edit_posts', // used in AjaxController
					'actions' => array(),
			);

		 $spioCaps = apply_filters('shortpixel/init/permissions', $spioCaps);
		 // $this->cap_actions = bla.
		 $this->caps = $spioCaps;

	}

	/**
	 * Returns the singleton instance, creating it if necessary.
	 *
	 * @return AccessModel
	 */
	public static function getInstance()
	{
			 if (is_null(self::$instance))
       {
			 	 self::$instance = new AccessModel();
       }

			return self::$instance;
	}

	/** Check for allowing a notice
	*  @param object $notice Notice object to evaluate.
	*  @return bool True if the current user has the capability to see notices.
	*/
	public function noticeIsAllowed($notice)
	{
			$cap = $this->getCap('notices');
			return $this->user()->has_cap($cap);
	}

	/**
	 * Check whether the current user holds a given SPIO capability.
	 *
	 * @param string $cap SPIO capability slug to check against WordPress permissions.
	 * @return bool True if the current user has the mapped WordPress capability.
	 */
	public function userIsAllowed($cap)
	{
			$cap = $this->getCap($cap);
			return $this->user()->has_cap($cap);
	}

	/**
	 * Determine whether the current user may edit a given media/custom image item.
	 *
	 * Checks either the 'custom_all' or 'image_all'/'image_user' capability
	 * depending on the item type.
	 *
	 * @param object $mediaItem An image model object exposing get('type') and get('id').
	 * @return bool True if the current user may edit the item, false otherwise.
	 */
	public function imageIsEditable($mediaItem)
	{
			$type = $mediaItem->get('type');
			if ($type == 'custom' )
			{
				 return $this->user()->has_cap($this->getCap('custom_all'), $mediaItem->get('id'));
			}
		  elseif ($type == 'media') // media
			{
				if ($this->user()->has_cap($this->getCap('image_all'), $mediaItem->get('id')) || $this->user()->has_cap($this->getCap('image_user'), $mediaItem->get('id'))  )
				{
						return true;
				}
			}
			return false;
	}

	/**
	 * Check whether a named plugin feature is available on the current installation.
	 *
	 * Supports feature flags such as 'avif' and 'webp'.
	 *
	 * @param string $name Feature name to check (e.g. 'avif', 'webp').
	 * @return bool True if the feature is available, false otherwise.
	 */
	public function isFeatureAvailable($name)
	{
		 $available = true;

		 switch($name)
		 {
			  case 'avif':
					/* no longer!
          $quotaControl = QuotaController::getInstance();

					$quota = $quotaControl->getQuota();

					if (property_exists($quota, 'unlimited') && $quota->unlimited === true)
					{
						$available = false;
					} */

				break;
				case 'webp':
				default:

				break;
		 }
		 return $available;
	}


	/**
	 * Returns the current WordPress user object.
	 *
	 * @return \WP_User
	 */
	protected function user()
	{
				return wp_get_current_user();
	}

	/** Find the needed capability
	*
	* This translates a SPIO capability into the associated cap that is registered within WordPress.
	*
	* @param string $cap    The required SPIO capability slug.
	* @param string $default The default WordPress capability to return when the slug is not found.
	*                        Defaults to 'manage_options' to prevent unintended access leaking.
	* @return string The WordPress capability string.
	*/
	protected function getCap($cap, $default = 'manage_options')
	{
		  if (isset($this->caps[$cap]))
				return $this->caps[$cap];
			else
				return $default;
	}


} // CLASS
