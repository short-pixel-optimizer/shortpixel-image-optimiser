<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$approx = $this->view->approx;
?>
<section class='panel selection' data-panel="selection" data-status="loaded" >
  <div class="panel-container">
			<span class='hidden' data-check-custom-hascustom >
				<?php echo  ($this->view->approx->custom->has_custom === true) ? 1 : 0;  ?>
			</span>

      <h3 class="heading"><span><img src="<?php echo esc_url(\wpSPIO()->plugin_url('res/img/robo-slider.png')); ?>"></span>
        <?php esc_html_e('ShortPixel Bulk Optimization - Select Images', 'shortpixel-image-optimiser'); ?>
      </h3>

      <p class='description'><?php esc_html_e('Select the type of images that ShortPixel should optimize for you.','shortpixel-image-optimiser'); ?></p>

       <?php $this->loadView('bulk/part-progressbar', false); ?>

      <div class='load wrapper' >
         <div class='loading'>
             <span><img src="<?php echo esc_url(\wpSPIO()->plugin_url('res/img/bulk/loading-hourglass.svg')); ?>" /></span>
             <span>
             <p><?php esc_html_e('Please wait, ShortPixel is checking the images to be processed...','shortpixel-image-optimiser'); ?><br>
               <span class="number" data-stats-total="total">x</span> <?php esc_html_e('items found', 'shortpixel-image-optimiser'); ?></p>
           </span>
         </div>
				 <div class='loading skip'>
					 <span><p><button class='button' data-action="SkipPreparing"><?php _e('Start now', 'shortpixel-image-optimiser'); ?></button></p>

					 </span>
					 <span>
	 						 <p><?php _e("Clicking this button will start optimization of the items added to the queue. The remaining items can be processed in a new bulk. After completion, you can start bulk and the system will continue with the unprocessed images.",'shortpixel-image-optimiser'); ?></p>
						</span>
				</div>

        <div class='loading overlimit'>
              <p><?php _e('ShortPixel has detected that there are no more resources available during preparation. The plugin will try to complete the process, but may be slower. Increase memory, disable heavy plugins or reduce the number of prepared items per load.', 'shortpixel-image-optimiser'); ?></p>

        </div>


       </div>

       <div class="interface wrapper">

				 <div class="option-block">

					 <h2><?php esc_html_e('Optimize:','shortpixel-image-optimiser'); ?> </h2>
					 <p><?php printf(esc_html__('ShortPixel has %sestimated%s the number of images that can still be optimized. %sAfter you select the options, the plugin will calculate exactly how many images to optimize.','shortpixel-image-optimiser'), '<b>','</b>', '<br />'); ?></p>

					 <?php if ($approx->media->isLimited): ?>
						 <h4 class='count_limited'><?php esc_html_e('ShortPixel has detected a high number of images. This estimates are limited for performance reasons. On the next step an accurate count will be produced', 'shortpixel-image-optimiser'); ?></h4>
					 <?php endif; ?>


	         <div class="media-library optiongroup">


	            <div class='switch_button'>
	              <label>
	                <input type="checkbox" class="switch" id="media_checkbox" checked>
	                <div class="the_switch">&nbsp; </div>
	              </label>
	            </div>


	            <h4><label for="media_checkbox"><?php esc_html_e('Media Library','shortpixel-image-optimiser'); ?></label></h4>
	            <div class='option'>
	              <label><?php esc_html_e('Images (estimate)', 'shortpixel-image-optimiser'); ?></label>
	              <span class="number" ><?php echo esc_html($approx->media->items) ?></span>
	            </div>

							<?php if (\wpSPIO()->settings()->processThumbnails == 1): ?>
		            <div class='option'>
		              <label><?php esc_html_e('Thumbnails (estimate)','shortpixel-image-optimiser'); ?></label> <span class="number" ><?php echo esc_html($approx->media->thumbs) ?> </span>
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
						<h4><label for="thumbnails_checkbox"><?php esc_html_e('Process Image Thumbnails','shortpixel-image-optimiser'); ?></label></h4>
						<div class='option'>
							<label><?php esc_html_e('Thumbnails (estimate)','shortpixel-image-optimiser'); ?></label>
							 <span class="number" ><?php echo esc_html($approx->media->total) ?> </span>
						</div>

						<p><?php esc_html_e('It is recommended to process the WordPress thumbnails. These are the small images that are most often used in posts and pages.This option changes the global ShortPixel settings of your site.','shortpixel-image-optimiser'); ?></p>

					</div>
				<?php endif; ?>

	         <div class="custom-images optiongroup"  data-check-visibility data-control="data-check-custom-hascustom" >
	           <div class='switch_button'>
	             <label>
	               <input type="checkbox" class="switch" id="custom_checkbox" checked>
	               <div class="the_switch">&nbsp; </div>
	             </label>
	           </div>
	           <h4><label for="custom_checkbox"><?php esc_html_e('Custom Media images','shortpixel-image-optimiser') ?></label></h4>
	            <div class='option'>
	              <label><?php esc_html_e('Images (estimate)','shortpixel-image-optimiser'); ?></label>
	               <span class="number" ><?php echo esc_html($approx->custom->images) ?></span>
	            </div>
	         </div>
				</div> <!-- block -->

				 <div class="option-block selection-settings">
					 <h2><?php esc_html_e('Options','shortpixel-image-optimiser') ?>: </h2>
						 <p><?php esc_html_e('Enable these options if you also want to create WebP/AVIF files. These options change the global ShortPixel settings of your site.','shortpixel-image-optimiser'); ?></p>
		         <div class='optiongroup'  >
		           <div class='switch_button'>

		             <label>
		               <input type="checkbox" class="switch" id="webp_checkbox" name="webp_checkbox"
		                <?php checked(\wpSPIO()->settings()->createWebp); ?>  />
		               <div class="the_switch">&nbsp; </div>
		             </label>

		           </div>
			   <h4><label for="webp_checkbox">
					 <?php printf(esc_html__('Also create WebP versions of the images' ,'shortpixel-image-optimiser') ); ?>
				 </label></h4>
				<div class="option"><?php esc_html_e('The total number of WebP images will be calculated in the next step.','shortpixel-image-optimiser'); ?></div>
		       </div>


					 <?php
					 $avifEnabled = $this->access()->isFeatureAvailable('avif');
					 $createAvifChecked = (\wpSPIO()->settings()->createAvif == 1 && $avifEnabled === true) ? true : false;
					 $disabled = ($avifEnabled === false) ? 'disabled' : '';
					 ?>


		       <div class='optiongroup'>
		         <div class='switch_button'>

		           <label>
		             <input type="checkbox" class="switch" id="avif_checkbox" name="avif_checkbox" <?php echo $disabled ?>
		              <?php checked($createAvifChecked); ?>  />
		             <div class="the_switch">&nbsp; </div>
		           </label>

		         </div>
		         <h4><label for="avif_checkbox"><?php esc_html_e('Also create AVIF versions of the images','shortpixel-image-optimiser'); ?></label></h4>
				<?php if ($avifEnabled == true): ?>
				<div class="option"><?php esc_html_e('The total number of AVIF images will be calculated in the next step.','shortpixel-image-optimiser'); ?></div>
		     </div>
			<?php else : ?>
				<div class="option warning"><?php printf(esc_html__('The creation of AVIF files is not possible with this license type. %s Read more %s ','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work" target="_blank">', '</a>'); ?>
				</div>
			<?php endif;  ?>

        <div class='optiongroup'>
          <div class='switch_button'>

            <label>
              <input type="checkbox" class="switch" id="background_checkbox" name="background_checkbox"
               <?php checked(\wpSPIO()->settings()->doBackgroundProcess); ?>  data-action="ChangeBackgroundProcessSettingEvent" data-event="change"/>
              <div class="the_switch">&nbsp; </div>
            </label>

          </div>
          <h4><label for="background_checkbox">

            <?php printf(esc_html__('Background Mode' ,'shortpixel-image-optimiser') ); ?>
              <span class='new'><?php _e('New!', 'shortpixel-image-optimiser'); ?></span>
          </label></h4>
            <?php $link = 'https://shortpixel.com/knowledge-base/article/584-background-processing-using-cron-jobs-in-shortpixel-image-optimizer'; ?>
         <div class="option"><?php printf(esc_html__('Utilize this feature to optimize images without the need to keep a browser window open. Please be aware that on websites with low traffic or shared hosting, this method of optimization might be considerably slower. If you observe a significant increase in server resource usage or processing time, consider switching to browser-based optimization. %sRead more%s.','shortpixel-image-optimiser'), '<strong><a href="' . esc_attr($link) . '" target="_blank">', '</a></strong>'); ?>
         </div>
         <div class='option warning
         <?php echo (\wpSPIO()->settings()->doBackgroundProcess) ? '' : 'hidden' ?>'>
         <p><?php _e('I understand that background optimization may pause if there are no visitors on the website.', 'shortpixel-image-optimiser'); ?></p></div>

       </div>
		 </div> <!-- option block -->

 	 	 <div class="option-block">
       <div class='optiongroup' data-check-visibility="false" data-control="data-check-approx-total">
          <h3><?php esc_html_e('No images found', 'shortpixel-image-optimiser'); ?></h3>
          <p><?php esc_html_e('ShortPixel Bulk couldn\'t find any optimizable images.','shortpixel-image-optimiser'); ?></p>
       </div>

       <h4 class='approx'><?php esc_html_e('An estimate of unoptimized images in this installation', 'shortpixel-image-optimiser'); ?> :
			<span data-check-approx-total><?php echo esc_html($approx->total->images) ?></span> </h4>

       <div><p><?php printf(__('In the next step, the plugin will calculate the total number of images to be optimized, and your bulk process will be prepared. The processing %s will not start yet %s, but a summary of the images to be optimized will be displayed.', 'shortpixel-image-optimiser'),'<b>','</b>'); ?></p></div>
		 </div>

      <nav>
        <button class="button" type="button" data-action="FinishBulk">
					<span class='dashicons dashicons-arrow-left'></span>
					<p><?php esc_html_e('Back', 'shortpixel-image-optimiser'); ?></p>
				</button>

        <button class="button-primary button" type="button" data-action="CreateBulk" data-panel="summary" data-check-disable data-control="data-check-total-total">
					<span class='dashicons dashicons-arrow-right'></span>
					<p><?php esc_html_e('Calculate', 'shortpixel-image-optimiser'); ?></p>
				</button>
      </nav>

    </div> <!-- interface wrapper -->
  </div><!-- container -->
</section>
