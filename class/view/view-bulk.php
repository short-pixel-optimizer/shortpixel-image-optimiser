<?php
namespace ShortPixel;
?>


<div class="shortpixel-bulk-wrapper">
  <h1>Shortpixel Bulk Processing</h1>



  <!--
  <div class='error'><p id="shortpixel-bulk-error">Error region</p></div>
  -->
  <div id="processPaused" class="processor-paused" data-action="ResumeBulk"><span class='dashicons dashicons-controls-pause' data-action="ResumeBulk"></span> Processor is paused, click to resume</div>
  <div class="screen-wrapper">


  <?php
  //$this->loadView('bulk/part-progressbar');
  $this->loadview('bulk/part-dashboard');
  $this->loadView('bulk/part-selection');
  $this->loadView('bulk/part-summary');
  $this->loadView('bulk/part-process');
  $this->loadView('bulk/part-finished');
  //$this->loadView('bulk/part-results');

  if (\wpSPIO()->env()->is_debug)
  //   $this->loadView('bulk/part-debug');
  ?>

  </div>

</div> <!-- wrapper -->
