<?php
namespace ShortPixel;

$approx = $this->view->approx;
?>
<section class='panel selection' data-panel="selection" data-status="loaded" >
  <div class="panel-container">
			<span class='hidden' data-check-custom-hascustom >
				<?php echo  ($this->view->approx->custom->has_custom === true) ? 1 : 0;  ?>
			</span>

      <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
        <?php _e('ShortPixel Bulk Optimization - Select Images', 'shortpixel-image-optimiser'); ?>
      </h3>

      <p class='description'><?php _e('Select the type of images that ShortPixel should optimize for you.','shortpixel-image-optimiser'); ?></p>

       <?php $this->loadView('bulk/part-progressbar', false); ?>

      <div class='load wrapper' >
         <div class='loading'>
             <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/loading-hourglass.svg'); ?>" /></span>
             <span>
             <p><php _e('Please wait, ShortPixel is checking the images to be processed...','shortpixel-image-optimiser'); ?><br>
               <span class="number" data-stats-total="total">x</span> items found</p>
           </span>

         </div>
       </div>

       <div class="interface wrapper">

				 <div class="option-block">

					 <h2><?php _e('Optimize:','shortpixel-image-optimiser'); ?> </h2>
					 <p><?php printf(__('ShortPixel has %sestimated%s the number of images that can still be optimized. <br />After choosing the options, the plugin will calculate exactly how many images will be optimized.','shortpixel-image-optimiser'), '<b>','</b>'); ?></p>

	         <div class="media-library optiongroup">

	            <div class='switch_button'>
	              <label>
	                <input type="checkbox" class="switch" id="media_checkbox" checked>
	                <div class="the_switch">&nbsp; </div>
	              </label>
	            </div>


	            <h4><label for="media_checkbox"><?php _e('Media Library','shortpixel-image-optimiser'); ?></label></h4>
	            <div class='option'>
	              <label><?php _e('Images (estimate)', 'shortpixel-image-optimiser'); ?></label>
	              <span class="number" ><?php echo $approx->media->items ?></span>
	            </div>
							<?php if (\wpSPIO()->settings()->processThumbnails == 1): ?>
		            <div class='option'>
		              <label><?php _e('Thumbnails (estimate)','shortpixel-image-optimiser'); ?></label> <span class="number" ><?php echo $approx->media->thumbs ?> </span>
		            </div>
							<?php endif; ?>
	         </div>


					<?php if (! \wpSPIO()->settings()->processThumbnails): ?>
					<div class='thumbnails optiongroup'>
						<div class='switch_button'>
							<label>
								<input type="checkbox" class="switch" id="thumbnails_checkbox" <?php checked(\wpSPIO()->settings()->processThumbnails); ?>>
								<div class="the_switch">&nbsp; </div>
							</label>
						</div>
						<h4><label for="thumbnails_checkbox"><?php _e('Process Image Thumbnails','shortpixel-image-optimiser'); ?></label></h4>
						<div class='option'>
							<label><?php _e('Thumbnails (estimate)','shortpixel-image-optimiser'); ?></label> <span class="number" ><?php echo $approx->media->total ?> </span>
						</div>

						<p><?php _e('It\'s recommend to process the WordPress thumbnails. There are the small images that are most often used on posts and pages. These options change the settings of your installation.','shortpixel-image-optimiser'); ?></p>



					</div>
				<?php endif; ?>

	         <div class="custom-images optiongroup"  data-check-visibility data-control="data-check-custom-hascustom" >
	           <div class='switch_button'>
	             <label>
	               <input type="checkbox" class="switch" id="custom_checkbox" checked>
	               <div class="the_switch">&nbsp; </div>
	             </label>
	           </div>
	           <h4><label for="custom_checkbox">Custom Media images</label></h4>
	            <div class='option'>
	              <label>Images (estimate)</label>
	               <span class="number" ><?php echo $approx->custom->images ?></span>
	            </div>
	         </div>
				</div> <!-- block -->

				 <div class="option-block selection-settings">
					 <h2>Options: </h2>
						 <p>Check these if you want to also create WebP / AVIF files. These options change the settings of your installation.</p>
		         <div class='optiongroup '  >
		           <div class='switch_button'>

		             <label>
		               <input type="checkbox" class="switch" id="webp_checkbox" name="webp_checkbox"
		                <?php checked(\wpSPIO()->settings()->createWebp); ?>  />
		               <div class="the_switch">&nbsp; </div>
		             </label>

		           </div>
			   <h4><label for="webp_checkbox">Also create <b>WebP</b> versions of the images</label></h4>
				<div class="option">The total number of WebP images will be calculated in the next step.</div>
		       </div>

		       <div class='optiongroup'>
		         <div class='switch_button'>

		           <label>
		             <input type="checkbox" class="switch" id="avif_checkbox" name="avif_checkbox"
		              <?php checked(\wpSPIO()->settings()->createAvif); ?>  />
		             <div class="the_switch">&nbsp; </div>
		           </label>

		         </div>
		         <h4><label for="avif_checkbox">Also create <b>AVIF</b> versions of the images</label></h4>
				<div class="option">The total number of AVIF images will be calculated in the next step.</div>
		     </div>
		 </div>

 	 	 <div class="option-block">
       <div class='optiongroup' data-check-visibility="false" data-control="data-check-approx-total">
          <h3><?php _e('No images found', 'shortpixel-image-optimiser'); ?></h3>
          <p><?php _e('ShortPixel Bulk couldn\'t find any optimizable images.','shortpixel-image-optimiser'); ?></p>
       </div>


       <h4 class='approx'><?php _e('An estimate of unoptimized images in this installation', 'shortpixel-image-optimiser'); ?> : <span data-check-approx-total><?php echo $approx->total->images ?></span> </h4>

       <div><p>In the next step the plugin calculates the total number of images to be optimized, and your bulk process will be prepared. It will <b>not yet</b> start the processing, but will display a summary of what will be optimized.</p></div>
		 </div>

      <nav>
        <button class="button" type="button" data-action="FinishBulk">
					<span class='dashicons dashicons-arrow-left'></span>
					<p><?php _e('Back', 'shortpixel-image-optimiser'); ?></p>
				</button>

        <button class="button-primary button" type="button" data-action="CreateBulk" data-panel="summary" data-check-disable data-control="data-check-total-total">
					<span class='dashicons dashicons-arrow-right'></span>
					<p><?php _e('Calculate', 'shortpixel-image-optimiser'); ?></p>
				</button>
      </nav>

    </div> <!-- interface wrapper -->
  </div><!-- container -->
</section>
