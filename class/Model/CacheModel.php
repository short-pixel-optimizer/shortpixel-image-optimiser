<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;


/* Model for storing cached data
*
* Use this in conjunction with cache controller, don't call it stand-alone.
*/
class CacheModel
{

  protected $name;
  protected $value;
  protected $expires = HOUR_IN_SECONDS;  // This is the expires, when saved without SetExpires! This value is not a representation of any expire time when loading something cache!
  protected $exists = false;


  public function __construct($name)
  {
     $this->name = $name;
     $this->load();
  }

  /** Set the expiration of this item. In seconds
  * @param $time Expiration in Seconds
  */
  public function setExpires($time)
  {
    $this->expires = $time;
  }

  public function setValue($value)
  {
    $this->value = $value;
  }

  public function exists()
  {
    return $this->exists;
  }

  public function getValue()
  {
      return $this->value;
  }

  public function getName()
  {
      return $this->name;
  }

  public function save()
  {
		 if ($this->expires <= 0)
		 {
			 	return; // don't save transients without expiration
		 }
     $this->exists = set_transient($this->name, $this->value, $this->expires);

  }

  public function delete()
  {
     delete_transient($this->name);
     $this->exists = false;
  }

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

	/** It has been shown that sometimes the expire of the transient is lost, creating a persistent transient.  This can be harmful, especially in the case of bulk-secret which can create a situation were no client will optimize due to the hanging transient. */
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
