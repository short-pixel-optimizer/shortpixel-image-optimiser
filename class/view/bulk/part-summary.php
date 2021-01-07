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
      <div class="section-wrapper">
      <h4><span class='dashicons dashicons-images-alt2'>&nbsp;</span> Media Library</h4>
        <div class="list-table">
          <div><span>Total Images</span><span >0</span></div>
          <div><span>Already Opt Items</span><span data-stats-media="bulk-optimizedCount">0</span></div>
          <div><span>Already Opt Thumbs</span><span data-stats-media="bulk-optimizedThumbnailCount">0</span></div>

          <div><span><strong>Total to be optimized in Media Library</strong></span><span>&nbsp;</span></div>
          <div><span>Items to Optimize</span><span data-stats-media="bulk-items">0</span></div>
          <div><span>Total images to Optimize </span><span data-stats-media="bulk-images">0</span></div>
        </div>
      </div>

      <div class="totals">
        Total number to be optimized  <span class="number" data-stats-total="bulk-images">0</span>
        (All)
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

    <div class="over-quota">
      <span><img src="<?php echo wpSPIO()->plugin_url('res/img/bulk/over-quota.svg') ?>" /></span>  <p>On your ShortPixel account you <span class='red'>only have <?php echo $quotaData->total->total ?> credits available </span>, but you have selected <b data-stats-total="bulk-images">X</b> images to be optimized in this process. You can either go back and select less images to optimize, or you can upgrade to a higher plan or buy one time credits.

       <button class="button">Show me the best options</button>

       </p>



    </div>

    <nav>
      <button class="button">Back</button>
      <button class="button-primary" data-action="open-panel" data-panel="process">Start Bulk Optimization</button>
    </nav>
  </div>
</section>