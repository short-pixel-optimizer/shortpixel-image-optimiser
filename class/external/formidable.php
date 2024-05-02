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
        $this->addHooks();
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
           return false;
        }

        return $bool;
    }

    public function formUpload($id, $new_values)
    {
       $fs = \wpSPIO()->filesystem();

       if (isset($_POST['item_meta']) && is_array($_POST['item_meta']))
       {
           foreach($_POST['item_meta'] as $field_id => $meta_id)
           {
             // array can contain non numeric or empty values.
             if (! is_numeric($meta_id))
             {
              continue;
             }

              $mediaItem = $fs->getMediaImage($meta_id);
              if (is_object($mediaItem) && $mediaItem->isProcessable())
              {
                 $adminController = AdminController::getInstance();
                 $adminController->handleImageUploadHook(null, $meta_id);
              }
           }

       }

    }


}

$f = new Formidable();
