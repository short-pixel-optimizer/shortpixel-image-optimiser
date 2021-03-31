<?php
namespace ShortPixel;
?>

<section class="panel summary" data-panel="summary">
  <div class="panel-container">

    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Shortpixel Bulk Optimization - Select Images
    </h3>

    <p class='description'>Welcome to the bulk optimization wizard, where you will be able to select the images that ShortPixel will optimize in the background for you.</p>

    <?php $this->loadView('bulk/part-progressbar'); ?>

    <div class='summary-list'>
      <h3>Review and start Bulk
        <span><img src="<?php echo wpSPIO()->plugin_url('res/img/bulk/robot-book-summary.svg') ?>" /></span>
      </h3>
      <div class="section-wrapper" data-check-visibility data-control="data-check-media-total">
      <h4><span class='dashicons dashicons-images-alt2'>&nbsp;</span> Media Library</h4>
        <div class="list-table">
          <div><span>Items to Optimize</span><span data-stats-media="in_queue">0</span></div>
          <div><span>Total images to Optimize </span><span data-stats-media="images-images">0</span></div>
        </div>
      </div>

    <div class="section-wrapper" data-check-visibility data-control="data-check-custom-total">
    <h4><span class='dashicons dashicons-open-folder'>&nbsp;</span> Other Media</h4>
      <div class="list-table">
        <div><span>Items to Optimize</span><span data-stats-custom="in_queue">0</span></div>
        <div><span>Total images to Optimize </span><span data-stats-custom="images-images">0</span></div>
      </div>
    </div>

    <div class="totals">
      Total number to be optimized  <span class="number" data-stats-total="images-images" data-check-total-total >0</span>
    </div>

  </div>

  <?php
    $quotaData = $this->view->quotaData;
  ?>

    <div class="credits">
      <p class='heading'><span>Your ShortPixel Credits</span>
        <span><?php echo  number_format($quotaData->total->total) ?></span>
      </p>

      <p><span>Your monthly plan</span>
         <span><?php echo $quotaData->monthly->text ?> <br>
              <?php _e('Consumed', 'shortpixel-image-optimiser'); ?> <?php echo number_format($quotaData->monthly->consumed) ?>
              <?php _e('Remaining', 'shortpixel-image-optimiser'); ?> <?php echo number_format($quotaData->monthly->remaining) ?>
          </span>
      </p>

      <p>
          <span>Your One Time Credits</span>
          <span><?php echo $quotaData->onetime->text ?> <br>
             <?php _e('Consumed', 'shortpixel-image-optimiser'); ?> <?php echo number_format($quotaData->onetime->consumed) ?>
             <?php _e('Remaining', 'shortpixel-image-optimiser'); ?> <?php echo number_format($quotaData->onetime->remaining) ?>
         </span>
      </p>

    </div>

    <div class="over-quota" data-check-visibility="false" data-control="data-quota-remaining" data-control-check="data-check-total-total">
      <span><img src="<?php echo wpSPIO()->plugin_url('res/img/bulk/over-quota.svg') ?>" /></span>  <p>On your ShortPixel account you <span class='red'>only have <?php echo number_format($quotaData->total->remaining) ?> credits available </span>, but you have selected <b data-stats-total="images-images">0</b> images to be optimized in this process. You can either go back and select less images to optimize, or you can upgrade to a higher plan or buy one time credits.

       <button class="button">Show me the best options</button>

       </p>


       <span class='hidden' data-quota-remaining><?php echo $quotaData->total->remaining ?></span>
    </div>

    <div class='no-images' data-check-visibility="false" data-control="data-check-total-total">
        <?php _e('The current selection contains no images. The bulk cannot start.', 'shortpixel-image-optimiser'); ?>
    </div>

    <nav>
      <button class="button" data-action="open-panel" data-panel="selection">Back</button>
      <button class="button-primary" data-action="StartBulk" data-control="data-check-total-total" data-check-presentation="disable" >Start Bulk Optimization</button>
    </nav>
  </div>
</section>
