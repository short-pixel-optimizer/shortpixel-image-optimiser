<?php
namespace ShortPixel;
?>

<section class="panel summary" data-panel="summary">
  <div class="panel-container">

    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      ShortPixel Bulk Optimization - Select Images
    </h3>

    <p class='description'><?php _e('Welcome to the bulk optimization wizard, where you can select the images that ShortPixel will optimize in the background for you.','shortpixel-image-optimiser'); ?></p>

    <?php $this->loadView('bulk/part-progressbar', false); ?>

    <div class='summary-list'>
      <h3>Review and start the Bulk
        <span><img src="<?php echo wpSPIO()->plugin_url('res/img/bulk/robot-book-summary.svg') ?>" /></span>
      </h3>
      <div class="section-wrapper" data-check-visibility data-control="data-check-media-total">
      <h4><span class='dashicons dashicons-images-alt2'>&nbsp;</span> Media Library (<span data-stats-media="in_queue">0</span> images)</h4>
        <div class="list-table">


						<div><span>Images & thumbnails</span><span data-stats-media="images-images_basecount">n/a</span></div>

            <div class='filetypes' data-check-visibility data-control="data-check-has-webp"><span>+ WebP images & thumbnails</span><span data-stats-media="images-images_webp" data-check-has-webp>&nbsp;</span></div>
            <div class='filetypes' data-check-visibility data-control="data-check-has-avif"><span>+ AVIF images & thumbnails</span><span data-stats-media="images-images_avif" data-check-has-avif>&nbsp;</span></div>


          <div><span><?php _e('Total images & thumbnails to optimize from the Media Library','shortpixel-image-optimiser'); ?></span><span data-stats-media="images-images">0</span></div>

        </div>
      </div>

    <div class="section-wrapper" data-check-visibility data-control="data-check-custom-total">
    <h4><span class='dashicons dashicons-open-folder'>&nbsp;</span><?php _e('Custom Media', 'shortpixel-image-optimiser') ?> (<span data-stats-custom="in_queue">0</span> images)</h4>
      <div class="list-table">
				<div><span>Images</span><span data-stats-custom="images-images_basecount">n/a</span></div>


					<div class='filetypes' data-check-visibility data-control="data-check-has-custom-webp" ><span>+ WebP images</span>
						<span data-stats-custom="images-images_webp" data-check-has-custom-webp>&nbsp;</span>
					</div>
					<div class='filetypes' data-check-visibility data-control="data-check-has-custom-avif">
						<span>+ AVIF images</span><span data-stats-custom="images-images_avif" data-check-has-custom-avif>&nbsp;</span>
					</div>

        <div><span>Total images to optimize from Custom Media folders</span><span  data-stats-custom="images-images">0</span></div>
      </div>
    </div>


    <div class="totals">
      Total images to optimize: <span class="number" data-stats-total="images-images" data-check-total-total >0</span>

      Total Credits Will Be Used <span class='number'></span>
    </div>

  </div>

  <?php
    $quotaData = $this->view->quotaData;
  ?>

    <div class="credits">
      <p class='heading'><span><?php _e('Your ShortPixel Credits Available', 'shortpixel-image-optimiser'); ?></span>
        <span><?php echo $this->formatNumber($quotaData->total->remaining, 0) ?></span>
      </p>

      <p><span>Your monthly plan</span>
         <span><?php echo $quotaData->monthly->text ?> <br>
              <?php _e('Consumed', 'shortpixel-image-optimiser'); ?> <?php echo $this->formatNumber($quotaData->monthly->consumed, 0) ?>
              <?php _e('; Remaining', 'shortpixel-image-optimiser'); ?> <?php echo $this->formatNumber($quotaData->monthly->remaining, 0) ?>
          </span>
      </p>

      <p>
          <span>Your One Time Credits</span>
          <span><?php echo $quotaData->onetime->text ?> <br>
             <?php _e('Consumed', 'shortpixel-image-optimiser'); ?> <?php echo $this->formatNumber($quotaData->onetime->consumed, 0) ?>
             <?php _e('; Remaining', 'shortpixel-image-optimiser'); ?> <?php echo $this->formatNumber($quotaData->onetime->remaining, 0) ?>
         </span>
      </p>

    </div>

    <div class="over-quota" data-check-visibility="false" data-control="data-quota-remaining" data-control-check="data-check-total-total">
      <span><img src="<?php echo wpSPIO()->plugin_url('res/img/bulk/over-quota.svg') ?>" /></span>  <p>In your ShortPixel account you <span class='red'>have only <?php echo $this->formatNumber($quotaData->total->remaining, 0) ?> credits available </span>, but you have chosen <b data-stats-total="images-images">0</b> images to be optimized in this bulk process. You can either go back and select less images, or you can upgrade to a higher plan or buy one-time credits.

       <button class="button" onClick="ShortPixel.proposeUpgrade();">Show me the best options</button>
     </p>

       <span class='hidden' data-quota-remaining><?php
			 // This is hidden check, no number format.
			 	echo $quotaData->total->remaining
			 ?></span>
    </div>
		<?php $this->loadView('snippets/part-upgrade-options'); ?>

    <div class='no-images' data-check-visibility="false" data-control="data-check-total-total">
        <?php _e('The current selection contains no images. The bulk process cannot start.', 'shortpixel-image-optimiser'); ?>
    </div>

    <nav>
      <button class="button" data-action="open-panel" data-panel="selection">
				<span class='dashicons dashicons-arrow-left' ></span>
				<?php _e('Back','shortpixel-image-optimiser'); ?>
			</button>
      <button class="button-primary button" data-action="StartBulk" data-control="data-check-total-total" data-check-presentation="disable">
				<span class='dashicons dashicons-arrow-right'></span>
				<?php _e('Start Bulk Optimization', 'shortpixel-image-optimiser'); ?>
			</button>
    </nav>
  </div>
</section>
