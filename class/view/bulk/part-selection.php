<?php
namespace ShortPixel;

?>
<section class='panel selection' data-panel="selection" data-loadpanel="StartPrepare" data-status="loading">
  <div class="panel-container">

      <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
        Shortpixel Bulk Optimization - Select Images
      </h3>

      <p class='description'>Welcome to the bulk optimization wizard, where you will be able to select the images that ShortPixel will optimize in the background for you.</p>

       <?php $this->loadView('bulk/part-progressbar'); ?>

       <div class='load wrapper' >
         <div class='loading'>
             <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/loading-hourglass.svg'); ?>" /></span>
             <span>
             <p>Please wait, ShortPixel is checking for you the images to be optimized... <br>
               <span class="number" data-stats-total="bulk-items">x</span> images found</p>
           </span>

         </div>
       </div>


       <div class="interface wrapper">
         <div class="media-library optiongroup">
            <div class='switch_button'>
              <label>
                <input type="checkbox" class="switch">
                <div class="the_switch">&nbsp; </div>
              </label>
            </div>

              Your Media Library
            <div class='option'>
              Original Images  <span class="number" data-stats-media="bulk-items"><?php _e('n/a', 'shortpixel-image-optimizer') ?></span>
            </div>
            <div class='option'>
              Thumbnails <span class="number" data-stats-media="bulk-images"><?php _e('n/a', 'shortpixel-image-optimizer')  ?></span>
            </div>
         </div>

         <div class="theme-images optiongroup not-implemented">
           <div class='switch_button'>
             <label>
               <input type="checkbox" class="switch">
               <div class="the_switch">&nbsp; </div>
             </label>
           </div> Custom Images
            <div class='option'>
              Images <span class="number" data-stats-custom="bulk-items"><?php _e('n/a', 'shortpixel-image-optimizer')  ?></span>
            </div>

         </div>

         <div class='optiongroup'>
           <div class='switch_button'>
             <label>
               <input type="checkbox" class="switch">
               <div class="the_switch">&nbsp; </div>
             </label>
           </div> Also Webp.
         <nav><button class="button-primary" type="button" data-action="open-panel" data-panel="summary" >Next</button></nav>
       </div>
    </div> <!-- interface wrapper -->
  </div><!-- container -->
</section>
