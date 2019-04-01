<?php
namespace Shortpixel;


class BulkRestoreAll extends ShortPixelController
{
    protected static $slug = 'bulk-restore-all';
    protected $template = 'view-restore-all';

    public function __construct()
    {
        parent::__construct();

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



}
