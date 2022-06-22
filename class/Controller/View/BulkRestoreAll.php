<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use \ShortPixel\Controller\OtherMediaController as OtherMediaController;

class BulkRestoreAll extends \ShortPixel\ViewController
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

      $otherMedia = OtherMediaController::getInstance();

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



}
