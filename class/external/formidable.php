<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\AdminController as AdminController;

/**
 * Formidable Forms compatibility shim (auto-optimise mode only).
 *
 * Formidable's file-upload fields work in two stages:
 *
 *   1. The user uploads a file → Formidable creates a *temporary*
 *      attachment row tagged with the `_frm_temporary` post-meta.
 *   2. The form is submitted → Formidable promotes the temporary
 *      attachment to a permanent one and fires
 *      `frm_after_create_entry` / `frm_after_update_entry`.
 *
 * If SPIO optimises the file at stage 1, we waste an optimisation
 * credit on something the user may never actually submit (they close
 * the tab, hit the browser back button, etc.). So this class:
 *
 *   - Vetos SPIO's automatic queue-on-upload path (`shortpixel/media/uploadhook`)
 *     when the target attachment is marked `_frm_temporary`, so
 *     stage-1 uploads never enter the queue.
 *   - On stage 2 (`frm_after_create_entry` / `frm_after_update_entry`),
 *     iterates every file-upload field in the form's fields table
 *     (`{prefix}frm_fields`) and enqueues each uploaded attachment
 *     through `AdminController::handleImageUploadHook()`.
 *
 * Gated on `env()->is_autoprocess` — if the operator has turned auto
 * optimisation off, no hooks are wired (they'll bulk-optimise
 * manually later, which handles form uploads correctly through the
 * normal media-library scan).
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class Formidable
{
    /**
     * Only wire hooks when auto-processing is on. See class docblock.
     */
    public function __construct()
    {
        if (true === \wpSPIO()->env()->is_autoprocess )
        {
          $this->addHooks();
        }

    }

    /**
     * Register the veto filter + the two form-entry actions.
     *
     * @return void
     */
    protected function addHooks()
    {

        add_filter('shortpixel/media/uploadhook', array($this, 'checkFormUpload'), 10, 4);
        add_action('frm_after_update_entry', array($this, 'formUpload'), 10, 2);
        add_action('frm_after_create_entry', array($this, 'formUpload'), 30, 2);
    }

    /**
     * Vetos the automatic queue-on-upload path for attachments still
     * in Formidable's stage-1 (marked `_frm_temporary`).
     *
     * @param bool   $bool      Filter default (usually true — allow queue).
     * @param object $mediaItem The uploaded media item (unused — decision is based on post-meta).
     * @param array  $meta      Upload metadata (unused).
     * @param int    $id        Attachment ID.
     * @return bool `false` when the attachment is a Formidable temporary; otherwise `$bool` unchanged.
     */
    // Check if this is a formadible form upload and then not add this file in the initial stage to the queue.
    public function checkFormUpload($bool, $mediaItem, $meta, $id)
    {
        $value = get_post_meta($id, '_frm_temporary', true);

        // Seems form submit temporary.
        if (is_numeric($value))
        {
           return false;
        }

        return $bool;
    }

    /**
     * Fired on Formidable's stage-2 form submit. Walks the form's
     * file-upload fields, extracts attachment IDs from
     * `$_POST['item_meta']`, and enqueues each through
     * `AdminController::handleImageUploadHook`.
     *
     * Reads `$_POST` directly because Formidable's action payload
     * doesn't include the file-field values — they only live in the
     * request body. Nonce verification happens on Formidable's side
     * before this action fires.
     *
     * Bails silently when:
     *   - `form_id` is missing from POST (invalid submit).
     *   - `item_meta` isn't an array (invalid submit).
     *   - No file-upload fields exist for this form.
     *
     * @param int   $id         Entry ID (unused — we key off $_POST).
     * @param array $new_values Entry values (unused — same reason).
     * @return void
     */
    public function formUpload($id, $new_values)
    {
       $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : null;

       if (is_null($form_id))
       {
          Log::addError('Form ID not set, aborting', $_POST);
          return;
       }

       if (false === isset($_POST['item_meta']) || false === is_array($_POST['item_meta']))
       {
          return;
       }

       $fields = $this->getFileUploadFields($form_id);
       if (false === $fields)
       {
          return;
       }

        //$item_meta = array_filter($_POST['item_meta']);

       foreach($fields as $index => $field_id)
       {
         $meta = isset($_POST['item_meta'][$field_id]) ? $_POST['item_meta'][$field_id] : '';

         // array can contain non numeric or empty values.
         if (! is_numeric($meta) && ! is_array($meta))
         {
          continue;
         }
         elseif (is_array($meta)) // can be nested.
         {
            $meta = array_filter($meta);
            foreach($meta as $index => $meta_id)
            {
               $this->checkMediaLibrary(intval($meta_id));
            }
         }
         else {
            $this->checkMediaLibrary(intval($meta));
         }


       }

    }

    /**
     * Look up all file-upload field IDs for a given Formidable form.
     *
     * Queries `{prefix}frm_fields` directly rather than going through
     * Formidable's model layer because that model isn't guaranteed to
     * be loaded at action-time.
     *
     * @param int $form_id Formidable form ID.
     * @return int[]|false Field IDs, or `false` when no file fields exist.
     */
    private function getFileUploadFields($form_id)
    {
        global $wpdb;

        $sql = 'SELECT id FROM ' . $wpdb->prefix . 'frm_fields where form_id = %d and type = %s ';
        $sql = $wpdb->prepare($sql, $form_id, 'file');

        $row = $wpdb->get_col($sql);

        if (count($row) === 0)
        {
           return false;
        }

        return $row;

    }

    /**
     * Enqueue one media-library attachment for optimisation by
     * forwarding to `AdminController::handleImageUploadHook`.
     *
     * Silently skips items that aren't in the media library or
     * aren't processable (already optimised, excluded, or errored).
     *
     * @param int $item_id Attachment ID.
     * @return void
     */
    private function checkMediaLibrary($item_id)
    {
      $fs = \wpSPIO()->filesystem();

      $mediaItem = $fs->getMediaImage($item_id);
      if (is_object($mediaItem) && $mediaItem->isProcessable())
      {
         $adminController = AdminController::getInstance();
         $adminController->handleImageUploadHook(null, $item_id);
      }
    }



}

$f = new Formidable();
