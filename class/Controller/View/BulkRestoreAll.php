<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use \ShortPixel\Controller\OtherMediaController as OtherMediaController;

class BulkRestoreAll extends \ShortPixel\Controller
{
    protected static $slug = 'bulk-restore-all';
    protected $template = 'view-restore-all';
    protected $form_action = 'sp-bulk';

    protected $selected_folders = array();

    public function __construct()
    {
        parent::__construct();

    }

    public function load()
    {
       $this->loadView();
    }

    public function randomCheck()
    {

      $output = '';
      for ($i=1; $i<= 10; $i++)
      {
          $output .= "<span><input type='radio' name='random_check[]' value='$i'  onchange='ShortPixel.checkRandomAnswer(event)' /> $i </span>";
      }

      return $output;
    }

    public function randomAnswer()
    {
      $correct = rand(1,10);
      $output = "<input type='hidden' name='random_answer' value='$correct'  data-target='#bulkRestore'  /> <span class='answer'>$correct</span> ";

      return $output;
    }

    public function getCustomFolders()
    {
      //wpshortPixel::refreshCustomFolders();
      //$spMetaDao = $this->shortPixel->getSpMetaDao();
      //$customFolders = $spMetaDao->getFolders();
      $otherMedia = new OtherMediaController();

      return $otherMedia->getAllFolders();

    }

    protected function processPostData($post)
    {
        if (isset($post['selected_folders']))
        {
            $folders = array_filter($post['selected_folders'], 'intval');
            if (count($folders) > 0)
            {
              $this->selected_folders = $folders;
            }
            unset($post['selected_folders']);
        }

        parent::processPostData($post);

    }

    public function setupBulk()
    {
        // Not doing this, since it's deliverd from bulk_view_controller. Yes, this is hacky. Prob. controller should merge.
      //  $this->checkPost(); // check if any POST vars are there ( which should be if custom restore is on )
      $selected_folders = isset($_POST['selected_folders']) ? $_POST['selected_folders'] : array();

      // handle the custom folders if there are any.
      if (count($selected_folders) > 0)
      {
          $spMetaDao = \wpSPIO()->getShortPixel()->getSpMetaDao();

          foreach($selected_folders as $folder_id)
          {
            $spMetaDao->setBulkRestore($folder_id);
          }
      }
    }


}
