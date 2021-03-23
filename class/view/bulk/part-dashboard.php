<?php
namespace ShortPixel;
?>

<section class='dashboard panel active' data-panel="dashboard" style='display: block'  >

  <?php //$this->loadView('bulk/part-progressbar'); ?>

  <div class="panel-container">


    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Welcome to the Bulk Processing page. You can add a bulk job by selecting one of the options below
    </h3>

    <div class='interface wrapper'>

      <div class='bulk-wrapper'>
        <button type="button" class="button-primary" id="start-optimize" data-action="StartPrepare" disabled><span class='dashicons dashicons-controls-play'>&nbsp;</span> Optimize</button>
      </div>

      <p class='description'>Here you can (re)optimize your Media Library, image files from your theme or other media folders that you are using on your site.

   </div>

   <div class='dashboard-log'>
      [Logs] 

   </div>

   <div class='shortpixel-bulk-loader' id="bulk-loading" data-status='loading'>
     <div class='loader'>
         <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/spinner2.gif'); ?>" /></span>
         <span>
         <h2>Please wait, ShortPixel is loading</h2>

       </span>

     </div>
   </div>

  </div>
</section>
