<?php
namespace ShortPixel;
?>

<section class='dashboard panel active' data-panel="dashboard" data-status="loading" >

  <?php //$this->loadView('bulk/part-progressbar'); ?>

  <div class="panel-container">


    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Welcome to the Bulk Processing page. You can add a bulk job by selecting one of the options below
    </h3>

    <div class='load wrapper' >
      <div class='loading'>
          <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/spinner2.gif'); ?>" /></span>
          <span>
          <h2>Please wait, ShortPixel is checking the Bulk Status</h2>

        </span>

      </div>
    </div>

    <div class='interface wrapper'>

      <div class='bulk-wrapper'>
        <button type="button" class="button-primary" id="start-optimize" data-action="StartPrepare"><span class='dashicons dashicons-controls-play'>&nbsp;</span> Optimize</button>
      </div>

      <p class='description'>Here you can (re)optimize your Media Library, image files from your theme or other media folders that you are using on your site.


      <button class="button-secondary">Bulk Restore</button> (i) <button class="button-secondary">Remove Metadata</button> (I)
   </div>

  </div>
</section>
