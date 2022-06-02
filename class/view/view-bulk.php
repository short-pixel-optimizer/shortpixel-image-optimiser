<?php
namespace ShortPixel;
?>


<div class="shortpixel-bulk-wrapper">
  <h1><?php _e('ShortPixel Bulk Processing', 'shortpixel-image-optimiser'); ?></h1>

  <div id="processPaused" class="processor-paused" data-action="ResumeBulk"><span class='dashicons dashicons-controls-pause' data-action="ResumeBulk"></span>The Bulk Processing is paused, please click to resume</div>

  <div id="processorOverQuota" class="processor-overquota">
			<h3>There are no credits left. The Bulk Processing is paused.</h3>
			<p><a href="javascript:window.location.reload()">Click to reload page after adding credits</a></p>
	</div>


  <div class="screen-wrapper">

  <?php
  //$this->loadView('bulk/part-progressbar', false);
  $this->loadview('bulk/part-dashboard');
  $this->loadView('bulk/part-selection');
  $this->loadView('bulk/part-summary');
  $this->loadView('bulk/part-process');
  $this->loadView('bulk/part-finished');

  $this->loadView('bulk/part-bulk-special');

  //$this->loadView('bulk/part-results');

  if (\wpSPIO()->env()->is_debug)
  //   $this->loadView('bulk/part-debug');
  ?>

  </div>

</div> <!-- wrapper -->
