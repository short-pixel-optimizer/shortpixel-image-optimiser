<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\AdminController as AdminController;

class Formidable
{
    public function __construct()
    {
        if (true === \wpSPIO()->env()->is_autoprocess )
        {
          $this->addHooks();
        }

    }

    protected function addHooks()
    {

        add_filter('shortpixel/media/uploadhook', array($this, 'checkFormUpload'), 10, 4);
        add_action('frm_after_update_entry', array($this, 'formUpload'), 10, 2);
        add_action('frm_after_create_entry', array($this, 'formUpload'), 30, 2);
    }

    // Check if this is a formadible form upload and then not add this file in the initial stage to the queue.
    public function checkFormUpload($bool, $mediaItem, $meta, $id)
    {
        $value = get_post_meta($id, '_frm_temporary', true);

        // Seems form submit temporary.
        if (is_numeric($value))
        {
          Log::addTemp('Numeric post meta on formidable, preventing');
           return false;
        }

        return $bool;
    }

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
          Log::addTemp('Not Post item meta here');
          return;
       }

       $fields = $this->getFileUploadFields($form_id);
       if (false === $fields)
       {
          Log::addTemp('Form fields didnt contain uploads');
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

    private function checkMediaLibrary($item_id)
    {
      Log::addTemp('Checking Media Lib: ' . $item_id);
      $fs = \wpSPIO()->filesystem();

      $mediaItem = $fs->getMediaImage($item_id);
      if (is_object($mediaItem) && $mediaItem->isProcessable())
      {
         Log::addTemp('Adding item - ' . $item_id);
         $adminController = AdminController::getInstance();
         $adminController->handleImageUploadHook(null, $item_id);
      }
    }



}

$f = new Formidable();
