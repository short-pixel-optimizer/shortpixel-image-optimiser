<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/**
 * Model for storing cached data via WordPress transients.
 *
 * Use this in conjunction with CacheController — do not instantiate stand-alone.
 * Wraps get_transient/set_transient/delete_transient with additional expiration
 * sanity checks to guard against persistent (non-expiring) transients.
 *
 * @package ShortPixel\Model
 */
class CacheModel
{

  /**
   * Transient key used for storage and retrieval.
   *
   * @var string
   */
  protected $name;

  /**
   * The cached value.
   *
   * @var mixed
   */
  protected $value;

  /**
   * Expiration time in seconds applied when save() is called.
   *
   * This is the default TTL for new items; it does NOT represent the remaining
   * TTL of an already-loaded transient.
   *
   * @var int
   */
  protected $expires = HOUR_IN_SECONDS;  // This is the expires, when saved without SetExpires! This value is not a representation of any expire time when loading something cache!

  /**
   * Whether a non-expired transient was found during load().
   *
   * @var bool
   */
  protected $exists = false;


  /**
   * Load the transient identified by $name.
   *
   * @param string $name Transient key to load.
   */
  public function __construct($name)
  {
     $this->name = $name;
     $this->load();
  }

  /** Set the expiration of this item. In seconds
  * @param int $time Expiration in seconds.
  * @return void
  */
  public function setExpires($time)
  {
    $this->expires = $time;
  }

  /**
   * Persist the current value as a WordPress transient.
   *
   * Skips saving if the configured expiration is zero or negative, since
   * transients without a positive TTL become persistent and can cause issues.
   *
   * @return void
   */
  public function save()
  {
		 if ($this->expires <= 0)
		 {
			 	return; // don't save transients without expiration
		 }
     $this->exists = set_transient($this->name, $this->value, $this->expires);

  }

  /**
   * Delete the transient from WordPress and mark this item as non-existent.
   *
   * @return void
   */
  public function delete()
  {
     delete_transient($this->name);
     $this->exists = false;
  }

  /**
   * Load the transient value from WordPress, if it exists and has a valid expiration.
   *
   * Calls checkExpiration() to detect and remove transients whose timeout option
   * has been lost (a known WordPress edge case that creates persistent transients).
   *
   * @return void
   */
  protected function load()
  {
    $item = get_transient($this->name);
    if ($item !== false)
    {
      $this->value = $item;
      $this->exists = true;
			$this->checkExpiration($this->name);
    }
  }

	/** It has been shown that sometimes the expire of the transient is lost, creating a persistent transient.  This can be harmful, especially in the case of bulk-secret which can create a situation were no client will optimize due to the hanging transient.
	 *
	 * Skips the check when an external object cache is in use, since transient
	 * timeout options are not stored in that case.
	 *
	 * @param string $name Transient key whose timeout option should be verified.
	 * @return bool True when the expiration is intact or an external cache is used.
	 */
	private function checkExpiration($name)
	{
			$option = get_option('_transient_timeout_' . $name);

			if (false !== $option && is_numeric($option))
			{
				 return true; // ok
			}
			else {

        // Via object cache the expire info can't be retrieved. Customer is on it's own with this.
         if (wp_using_ext_object_cache())
         {
            return true;
         }

				 $this->value = '';
				 $this->delete();
				 Log::addError('Found hanging transient with no expiration! ' . $name, $option);
			}
	}

}
