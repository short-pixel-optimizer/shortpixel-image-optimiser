<?php
namespace ShortPixel;

/**  Proto parent class for all controllers.
*
* So far none of the controller need or implement similar enough functions for a parent to make sense. * Perhaps this will change of time, so most are extending this parent.
**/

class Controller
{

	protected $userIsAllowed = false;

	public function __construct()
	{
    $this->userIsAllowed = $this->checkUserPrivileges();
	}

	  protected function checkUserPrivileges()
	  {
	    if ((current_user_can( 'manage_options' ) || current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' )))
	      return true;

	    return false;
	  }

} // class
