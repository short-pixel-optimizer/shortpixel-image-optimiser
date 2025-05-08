<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\OtherMediaController as OtherMediaController;

// Gravity Forms integrations.
class gravityForms
{

  public function __construct()
  {
		// @todo All this off, because it can only fatal error.
   // add_filter( 'gform_save_field_value', array($this,'shortPixelGravityForms'), 10, 5 );
  }

  function shortPixelGravityForms( $value, $lead, $field, $form ) {
      if($field->type == 'post_image') {
          $this->handleGravityFormsImageField($value);
      }
      return $value;
  }

  public function handleGravityFormsImageField($value) {


			$fs = \wpSPIO()->filesystem();
			$otherMediaController = OtherMediaController::getInstance();
			$uploadBase = $fs->getWPUploadBase();


			$gravFolder = $otherMediaController->getFolderByPath($uploadBase->getPath() . 'gravity_forms');

			if (! $gravFolder->exists())
			 	return false;

/* no clue what this legacy is suppposed to be.
      if(strpos($value , '|:|')) {
          $cleanup = explode('|:|', $value);
          $value = $cleanup[0];
      }
*/
			if (! $gravFolder->get('in_db'))
			{
				 $otherMediaController->addDirectory($gravFolder->getPath());
			}

  }

} // class

$g = new gravityForms();
