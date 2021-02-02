<?php
namespace ShortPixel;
?>


<div class="shortpixel-bulk-wrapper">
  <h1>Shortpixel Bulk Processing</h1>



  <!--
  <div class='error'><p id="shortpixel-bulk-error">Error region</p></div>
  -->
  <div id="processPaused" class="processor-paused"><span class='dashicons dashicons-controls-pause'></span> Processor is paused, click to resume in the tooltip </div>
  <div class="screen-wrapper">


    <div class='shortpixel-bulk-loader' id="bulk-loading" data-status='loading'>
      <div class='loader'>
          <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/spinner2.gif'); ?>" /></span>
          <span>
          <h2>Please wait, ShortPixel is loading</h2>

        </span>

      </div>
    </div>


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
