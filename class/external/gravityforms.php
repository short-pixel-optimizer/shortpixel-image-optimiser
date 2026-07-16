<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\OtherMediaController as OtherMediaController;

/**
 * Gravity Forms integration — currently DORMANT.
 *
 * The intent was: when a Gravity Forms `post_image` field is saved,
 * auto-register the `gravity_forms/` upload subdirectory with SPIO's
 * OtherMedia (custom media) system so those uploads get optimised.
 *
 * Status: the whole feature is switched off — the constructor's
 * `add_filter( 'gform_save_field_value', ... )` line is commented out
 * with the note *"@todo All this off, because it can only fatal
 * error."*. Nothing this class does is currently reachable via the
 * normal Gravity Forms lifecycle.
 *
 * The two methods below (`shortPixelGravityForms`, `handleGravityFormsImageField`)
 * are still defined but only invokable by direct method call — no
 * hook path reaches them. Kept in place because re-enabling requires
 * fixing whatever "can only fatal error" state the plugin was in when
 * this was disabled.
 *
 * Self-boots at file-load time (no singleton wrapper) — but the
 * self-boot only runs the empty constructor.
 */
// Gravity Forms integrations.
class gravityForms
{

  /**
   * No hooks registered — the intended filter registration is
   * commented out. See class docblock.
   */
  public function __construct()
  {
		// @todo All this off, because it can only fatal error.
   // add_filter( 'gform_save_field_value', array($this,'shortPixelGravityForms'), 10, 5 );
  }

  /**
   * Would forward Gravity Forms `post_image` field saves to
   * `handleGravityFormsImageField`, but is unreachable because the
   * filter registration is disabled.
   *
   * @param mixed  $value Field value being saved.
   * @param object $lead  GF entry object (unused).
   * @param object $field GF field object — type is checked for `post_image`.
   * @param object $form  GF form object (unused).
   * @return mixed The unchanged `$value` (this method never mutates it).
   */
  function shortPixelGravityForms( $value, $lead, $field, $form ) {
      if($field->type == 'post_image') {
          $this->handleGravityFormsImageField($value);
      }
      return $value;
  }

  /**
   * Would register `wp-content/uploads/gravity_forms` as a SPIO
   * OtherMedia (custom-media) folder if it exists and isn't already
   * tracked. Currently unreachable — see class docblock.
   *
   * @param mixed $value Field value from the GF save hook (unused).
   * @return false|void `false` if the gravity_forms folder doesn't exist yet; otherwise void.
   */
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
