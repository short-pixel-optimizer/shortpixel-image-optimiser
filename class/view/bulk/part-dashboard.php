<?php
namespace ShortPixel;
?>

<h1>Shortpixel Bulk Processing</h1>

<section class='dashboard panel active' data-panel="dashboard">

  <?php //$this->loadView('bulk/part-progressbar'); ?>

  <div class="panel-container">

    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Welcome to the Bulk Processing page. You can add a bulk job by selecting one of the options below
    </h3>

    <div class='bulk-wrapper'>
      <button type="button" class="button-primary" id="start-bulk" data-action="open-panel" data-panel="selection"><span class='dashicons dashicons-controls-play'>&nbsp;</span> Optimize</button>
    </div>

    <p class='description'>Here you can (re)optimize your Media Library, image files from your theme or other media folders that you are using on your site.


    <button class="button-secondary">Bulk Restore</button> (i) <button class="button-secondary">Remove Metadata</button> (I)

  </div>
</section>
