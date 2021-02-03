<?php
namespace ShortPixel;

?>
<section class='panel selection' data-panel="selection" data-loadpanel="PrepareBulk" data-status="loading">
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
               <span class="number" data-stats-total="total">x</span> items found</p>
           </span>

         </div>
       </div>

       <div class="interface wrapper">

         <div class="media-library optiongroup hidden" data-check-visibility data-control="data-check-media-total" >

            <div class='switch_button'>
              <label>
                <input type="checkbox" class="switch" id="media_checkbox" checked>
                <div class="the_switch">&nbsp; </div>
              </label>
            </div>

            <h4><label for="media_checkbox">Your Media Library</label></h4>
            <div class='option'>
              <label>Items in Library</label>
              <span class="number" data-stats-media="total" data-check-media-total><?php _e('n/a', 'shortpixel-image-optimizer') ?></span>
            </div>
            <div class='option'>
              <labeL>Images</label> <span class="number" data-stats-media="images-images"><?php _e('n/a', 'shortpixel-image-optimizer')  ?></span>
            </div>
         </div>

         <div class="custom-images optiongroup hidden" data-check-visibility data-control="data-check-custom-total" >
           <div class='switch_button'>
             <label>
               <input type="checkbox" class="switch" id="custom_checkbox" checked>
               <div class="the_switch">&nbsp; </div>
             </label>
           </div>
           <h4><label for="custom_checkbox">Custom Images</label></h4>
            <div class='option'>
              <label>Images</label>
               <span class="number" data-stats-custom="total" data-check-custom-total ><?php _e('n/a', 'shortpixel-image-optimizer')  ?></span>
            </div>

         </div>


         <div class='optiongroup hidden' data-check-visibility data-control="data-check-media-total" >
           <div class='switch_button'>

             <label>
               <input type="checkbox" class="switch">
               <div class="the_switch">&nbsp; </div>
             </label>

           </div>
           <h4>Also Webp.</h4>


       </div>

       <div class='optiongroup' data-check-visibility="false" data-control="data-check-total-total">
          <h3>No images found</h3>
          <p> Shortpixel Bulk couldn't find any optimizable images. </p>
       </div>

      <nav>
        <button class="button" type="button" data-action="FinishBulk">Stop Bulk</button>
        <button class="button-primary" type="button" data-action="open-panel" data-panel="summary" data-check-disable data-control="data-check-total-total">Next</button>
      </nav>

    </div> <!-- interface wrapper -->
  </div><!-- container -->
</section>
