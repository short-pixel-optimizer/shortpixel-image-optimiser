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
        ShortPixel Bulk Optimization - Select Images
      </h3>

      <p class='description'>Welcome to the bulk optimization wizard, where you can select the images that ShortPixel will optimize in the background for you.</p>

       <?php $this->loadView('bulk/part-progressbar'); ?>

      <div class='load wrapper' >
         <div class='loading'>
             <span><img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/loading-hourglass.svg'); ?>" /></span>
             <span>
             <p>Please wait, ShortPixel is checking the images to be optimized... <br>
               <span class="number" data-stats-total="total">x</span> items found</p>
           </span>

         </div>
       </div>

       <div class="interface wrapper">

				 <div class="option-block">

					 <h2>Optimize: </h2>
					 <p>Shortpixel has <b>estimated</b> the number of images that can still be optimized. <br />After choosing the options, the plugin will calculate exactly how many images will be optimized.</p>

	         <div class="media-library optiongroup">

	            <div class='switch_button'>
	              <label>
	                <input type="checkbox" class="switch" id="media_checkbox" checked>
	                <div class="the_switch">&nbsp; </div>
	              </label>
	            </div>


	            <h4><label for="media_checkbox"><?php _e('Media Library','shortpixel-image-optimiser'); ?></label></h4>
	            <div class='option'>
	              <label>Items</label>
	              <span class="number" ><?php echo $approx->media->items ?></span>
	            </div>
	            <div class='option'>
	              <label>Images</label> <span class="number" ><?php echo $approx->media->total ?> </span>
	            </div>
	         </div>

	         <div class="custom-images optiongroup"  data-check-visibility data-control="data-check-custom-hascustom" >
	           <div class='switch_button'>
	             <label>
	               <input type="checkbox" class="switch" id="custom_checkbox" checked>
	               <div class="the_switch">&nbsp; </div>
	             </label>
	           </div>
	           <h4><label for="custom_checkbox">Custom Media images</label></h4>
	            <div class='option'>
	              <label>Images</label>
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
		           <h4>Also create <b>WebP</b> versions of the images</h4>
							 <div class='option'><label>Media Libary</label>
								 	+ <span class="number"><?php echo $approx->media->total ?></span> credits
							</div>

		           <div class='option' data-check-visibility data-control="data-check-custom-hascustom"><label>Custom Media</label>
								 + <span class="number"><?php echo $approx->custom->images ?></span> credits
							 </div>
		       </div>

		       <div class='optiongroup '  >
		         <div class='switch_button'>

		           <label>
		             <input type="checkbox" class="switch" id="avif_checkbox" name="avif_checkbox"
		              <?php checked(\wpSPIO()->settings()->createAvif); ?>  />
		             <div class="the_switch">&nbsp; </div>
		           </label>

		         </div>
		         <h4>Also create <b>AVIF</b> versions of the images</h4>
							 <div class="option"><label>Media Library</label>
								 	+ <span class="number"><?php echo $approx->media->total ?></span> credits
								</div>
		           <div class="option" data-check-visibility data-control="data-check-custom-hascustom"><label>Custom Media</label> + <span class="number"><?php echo $approx->custom->images ?></span> credits
							 </div>
		     </div>
		 </div>

 	 	 <div class="option-block">
       <div class='optiongroup' data-check-visibility="false" data-control="data-check-approx-total">
          <h3><?php _e('No images found', 'shortpixel-image-optimiser'); ?></h3>
          <p><?php _e('Shortpixel Bulk couldn\'t find any optimizable images.','shortpixel-image-optimiser'); ?></p>
       </div>


       <h4 class='approx'><?php _e('An estimate of unoptimized images in this installation', 'shortpixel-image-optimiser'); ?> : <span data-check-approx-total><?php echo $approx->total->images ?></span> </h4>

       <div><p>In the next step the plugin calculates the total number of images to be optimized, and your bulk process will be prepared. It will <b>not yet</b> start the bulk process.</p></div>
		 </div>

      <nav>
        <button class="button" type="button" data-action="FinishBulk">
					<span class='dashicons dashicons-arrow-left'></span>
					<?php _e('Back', 'shortpixel-image-optimiser'); ?>
				</button>

        <button class="button-primary button" type="button" data-action="CreateBulk" data-panel="summary" data-check-disable data-control="data-check-total-total">
					<span class='dashicons dashicons-arrow-right'></span>
					<?php _e('Calculate and summary', 'shortpixel-image-optimiser'); ?>
				</button>
      </nav>

    </div> <!-- interface wrapper -->
  </div><!-- container -->
</section>
