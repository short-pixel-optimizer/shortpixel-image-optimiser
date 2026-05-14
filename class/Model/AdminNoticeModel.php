<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;
use ShortPixel\Notices\NoticeController as Notices;


/**
 * Abstract base class for all SPIO admin notices.
 *
 * Concrete subclasses implement checkTrigger() and getMessage() to define
 * when a notice should appear and what text it should show.  The base class
 * handles persistence, dismissal, screen limiting, and the add/reset lifecycle.
 *
 * @package ShortPixel\Model
 */
abstract class AdminNoticeModel
{
	 /**
	  * Unique string key identifying this notice.
	  *
	  * @var string
	  */
	 protected $key; // abstract

	 /**
	  * The underlying notice object returned by NoticeController, or null if not yet added.
	  *
	  * @var object|null
	  */
	 protected $notice;

	 /**
	  * Severity level of the notice: 'normal', 'warning', or 'error'.
	  *
	  * @var string
	  */
	 protected $errorLevel = 'normal';  // normal, warning, error

	 /**
	  * How long (in seconds) a dismissed notice should be suppressed.
	  *
	  * @var int
	  */
	 protected $suppress_delay = YEAR_IN_SECONDS;

	 /**
	  * Optional callback passed to NoticeController::makePersistent().
	  *
	  * @var callable|null
	  */
	 protected $callback;

	 /**
	  * Admin screen IDs on which this notice should be shown (empty means all).
	  *
	  * @var string[]
	  */
	 protected $include_screens = array();

	 /**
	  * Admin screen IDs from which this notice should be hidden.
	  *
	  * @var string[]
	  */
	 protected $exclude_screens = array();

	 /**
	  * Arbitrary key/value data consumed by getMessage() in subclasses.
	  *
	  * @var array<string, mixed>
	  */
	 protected $data;


   /**
    * Evaluate whether the conditions for showing this notice are met.
    *
    * @return bool True if the notice should be added, false otherwise.
    */
   abstract protected function checkTrigger();

   /**
    * Build and return the localised message string for this notice.
    *
    * @return string The HTML/text message to display.
    */
   abstract protected function getMessage();

	 // No stuff loading here, low init
	 public function __construct()
	 {

	 }

	 /**
	  * Main initialisation routine.
	  *
	  * Loads any existing persistent notice from the NoticeController.  If no
	  * notice exists yet and the trigger condition is met the notice is added.
	  * If a notice already exists but the reset condition is met it is removed.
	  *
	  * @return bool False when the notice was dismissed or was reset; true otherwise.
	  */
	 public function load()
	 {
		 $noticeController = Notices::getInstance();
		 $notice = $noticeController->getNoticeByID($this->key);


		 if (is_object($notice))
		 {
		 	$this->notice = $notice;
		 }

		 if (is_object($notice) && $notice->isDismissed())
		 {
			 return false;
		 }

		 if (is_null($this->notice) && $this->checkTrigger() === true)
		 {
			  $this->add();
		 }
		 elseif ( is_object($this->notice) && $this->checkReset() === true)
		 {
			  $this->reset();
        return false;
		 }
     return true;
	 }

	 /**
	  * Remove this notice by its key, optionally overriding which key to remove.
	  *
	  * @param string|null $key Notice key to remove; defaults to $this->key.
	  * @return void
	  */
	 public function reset($key = null)
	 {
		  $key = (is_null($key)) ? $this->key : $key;
		 	Notices::removeNoticeByID($key);
      $this->notice = null;
	 }

	 /**
	  * Determine whether the notice should be reset/removed.
	  *
	  * Override in subclasses to implement reset logic.
	  *
	  * @return bool True if the notice should be removed, false to keep it.
	  */
	 protected function checkReset()
	 {
		  return false;
	 }

	 /**
	  * Manually trigger the notice, bypassing the normal checkTrigger() flow.
	  *
	  * Useful when the notice must be shown in response to an explicit action
	  * rather than a passively evaluated condition.
	  *
	  * @param array<string, mixed> $args Optional key/value pairs stored as notice data.
	  * @return void
	  */
	 public function addManual($args = array())
	 {
		  foreach($args as $key => $val)
			{
				 $this->addData($key, $val);
			}
		 	$this->add();
	 }

	 /**
	  * Returns the raw notice object (may be null if not yet added).
	  *
	  * @return object|null
	  */
	 public function getNoticeObj()
	 {
		  return $this->notice;  // can be null!
	 }

	 /**
	  * Check whether this notice has been dismissed by the user.
	  *
	  * Acts as a proxy to the underlying notice object's isDismissed() method.
	  *
	  * @return bool True if dismissed, false if not dismissed or not yet created.
	  */
	 public function isDismissed()
	 {
		 	$notice = $this->getNoticeObj();
			if (is_null($notice) || $notice->isDismissed() === false)
				return false;

			return true;
	 }


	 /**
	  * Create and register the notice with the NoticeController.
	  *
	  * Uses $errorLevel to choose the appropriate factory method, applies any
	  * screen restrictions, and makes the notice persistent with the configured
	  * suppress delay and optional callback.
	  *
	  * @return void
	  */
	 protected function add()
	 {

		 switch ($this->errorLevel)
		 {
			 case 'warning':
			 	$notice = Notices::addWarning($this->getMessage());
			 break;
       case 'error':
        $notice = Notices::addError($this->getMessage());
       break;
			 case 'normal':
			 default:
			 	$notice = Notices::addNormal($this->getMessage());

			 break;
		 }

		 /// Todo implement include / exclude screens here.
		 if (count($this->exclude_screens) > 0)
		 {
			 $notice->limitScreens('exclude', $this->exclude_screens);
		 }

		 if (count($this->include_screens) > 0)
		 {
			 $notice->limitScreens('include', $this->include_screens);
		 }


		 if (! is_null($this->callback))
		 	Notices::makePersistent($notice, $this->key, $this->suppress_delay, $this->callback);
		 else
		 	Notices::makePersistent($notice, $this->key, $this->suppress_delay);

		 $this->notice = $notice;
	 }

	 /**
	  * Store a named value in the internal data bag for later use by getMessage().
	  *
	  * @param string $name  Data key.
	  * @param mixed  $value Data value.
	  * @return void
	  */
	 protected function addData($name, $value)
	 {
		  $this->data[$name] = $value;
	 }

	 /**
	  * Retrieve a previously stored data value by name.
	  *
	  * @param string $name Data key to retrieve.
	  * @return mixed The stored value, or false if not set.
	  */
	 protected function getData($name)
	 {
		  	if (isset($this->data[$name]))
				{
					 return $this->data[$name];
				}
				return false;
	 }



}
