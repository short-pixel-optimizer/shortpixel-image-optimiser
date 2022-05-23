<?php

namespace ShortPixel;

?>
<section class="panel process" data-panel="process" >
  <div class="panel-container">

    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      ShortPixel Bulk is in progress
      <div class='average-optimization'>
          <p>Average Optimization </p>
          <svg class="opt-circle-average" viewBox="-10 0 150 140">
                        <path class="trail" d="
                            M 50,50
                            m 0,-46
                            a 46,46 0 1 1 0,92
                            a 46,46 0 1 1 0,-92
                            " stroke-width="16" fill-opacity="0">
                        </path>
                        <path class="path" d="
                            M 50,50
                            m 0,-46
                            a 46,46 0 1 1 0,92
                            a 46,46 0 1 1 0,-92
                            " stroke-width="16" fill-opacity="0" style="stroke-dasharray: 289.027px, 289.027px; stroke-dashoffset: 180px;">
                        </path>
                        <text class="text" x="50" y="50"><?php _e('N/A', 'shortpixel-image-optimiser'); ?></text>
                    </svg>

      </div>
    </h3>

    <p class='description'><?php _e('ShortPixel is optimizing your images. Please leave this window open for the process to finish.', 'shortpixel-image-optimiser'); ?> </p>

    <?php $this->loadView('bulk/part-progressbar', false); ?>

		<!--- ###### MEDIA ###### -->
    <div class='bulk-summary' data-check-visibility data-control="data-check-media-total">
      <div class='heading'>
        <span><i class='dashicons dashicons-images-alt2'>&nbsp;</i> <?php _e('Media Library' ,'shortpixel-image-optimiser'); ?></span>
        <span>
              <span class='line-progressbar'>
                <span class='done-text'><i data-stats-media="percentage_done"></i> %</span>
                <span class='done' data-stats-media="percentage_done" data-presentation="css.width.percentage"></span>

              </span>
							<span class='dashicons spin dashicons-update line-progressbar-spinner' data-check-visibility data-control="data-check-media-in_process">&nbsp;</span>

        </span>
        <span>Processing: <i data-stats-media="in_process" data-check-media-in_process >-</i></span>
        <span>&nbsp;</span>
				<span>&nbsp;</span>
      </div>
      <div>
        <span>Processed: <i data-stats-media="done">-</i></span>

        <span><?php _e('Waiting','shortpixel-image-optimiser'); ?> <i data-stats-media="in_queue">-</i></span>
        <span>Errors: <i data-check-media-fatalerrors data-stats-media="fatal_errors" class='error'>- </i>
            </span>
				<span data-check-visibility data-control="data-check-media-fatalerrors" ><label title="<?php _e('Show Errors', 'shortpixel-image-optimiser'); ?>">
					<input type="checkbox" name="show-errors" value="show" data-action='ToggleErrorBox' data-errorbox='media' data-event='change'>Show Errors</label>
			 </span>
        <span class='hidden' data-check-media-total data-stats-media="total">0</span>

      </div>

    </div>

		<div data-error-media="message" data-presentation="append" class='errorbox media'></div>

		<!-- ****** CUSTOM ********  --->
    <div class='bulk-summary' data-check-visibility data-control="data-check-custom-total">
      <div class='heading'>
        <span><i class='dashicons dashicons-open-folder'>&nbsp;</i> <?php _e('Custom Media', 'shortpixel-image-optimiser'); ?> </span>
        <span>
              <span class='line-progressbar'>
                <span class='done-text'><i data-stats-custom="percentage_done"></i> %</span>
                <span class='done' data-stats-custom="percentage_done" data-presentation="css.width.percentage"></span>
              </span>
							<span class='dashicons spin dashicons-update line-progressbar-spinner' data-check-visibility data-control="data-check-custom-in_process">&nbsp;</span>

        </span>
  			<span>Processing: <i data-stats-custom="in_process" data-check-custom-in_process>-</i></span>
			  <span>&nbsp;</span>
        <span>&nbsp;</span>
      </div>
      <div>
        <span>Processed: <i data-stats-custom="done">-</i></span>

        <span><?php _e('Waiting','shortpixel-image-optimiser'); ?>: <i data-stats-custom="in_queue">-</i></span>
        <span>Errors: <i data-check-custom-fatalerrors  data-stats-custom="fatal_errors" class='error'>-</i></span>

			<span data-check-visibility data-control="data-check-custom-fatalerrors" ><label title="<?php _e('Show Errors', 'shortpixel-image-optimiser'); ?>">
				<input type="checkbox" name="show-errors" value="show" data-action='ToggleErrorBox' data-errorbox='custom' data-event='change'>Show Errors</label>
		 </span>

        <span class='hidden' data-check-custom-total data-stats-custom="total">0</span>
      </div>

    </div>

    <div data-error-custom="message" data-presentation="append" class='errorbox custom'></div>

		<nav>
			<button class='button stop' data-action="StopBulk" >Stop Bulk Processing</button>
			<button class='button pause' data-action="PauseBulk" id="PauseBulkButton">Pause Bulk Processing</button>
			<button class='button button-primary resume' data-action='ResumeBulk' id="ResumeBulkButton">Resume Bulk Processing</button>

		</nav>

    <div class='image-preview-section hidden'> <!-- /hidden -->
       <div class="image-preview-line">
        <!-- <strong data-result="queuetype"></strong>  -->
				<span>&nbsp;</span> <!-- Spacer for flex -->
				<span data-result="filename">&nbsp;</span>

        <svg class="opt-circle-image" viewBox="0 0 100 100">
                      <path class="trail" d="
                          M 50,50
                          m 0,-46
                          a 46,46 0 1 1 0,92
                          a 46,46 0 1 1 0,-92
                          " stroke-width="8" fill-opacity="0">
                      </path>
                      <path class="path" d="
                          M 50,50
                          m 0,-46
                          a 46,46 0 1 1 0,92
                          a 46,46 0 1 1 0,-92
                          " stroke-width="8" fill-opacity="0" style="stroke-dasharray: 289.027px, 289.027px; stroke-dashoffset: 180px;">
                      </path>
                      <text class="text" x="50" y="50">-- %</text>
                  </svg>
      </div>

      <div class="preview-wrapper">
			 <div class="slide-mask" id="preview-structure" data-placeholder="<?php echo \wpSPIO()->plugin_url('res/img/bulk/placeholder.svg'); ?>">

					<div class='current preview-image'>
		        <div class="image source">
		          <img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/placeholder.svg'); ?>" >
		          <p>Original Image</p>
							<?php $this->loadView('snippets/part-svgloader', false); ?>
		        </div>

		        <div class="image result">
		          <img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/placeholder.svg'); ?>" >
						<p>Optimized Image</p>
						<?php $this->loadView('snippets/part-svgloader', false); ?>
		        </div>
					</div>

					<div class='new preview-image'>

							<div class="image source">
								<img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/placeholder.svg'); ?>" >
								<?php $this->loadView('snippets/part-svgloader', false); ?>
								<p>Original Image</p>
							</div>

							<div class="image result">
								<img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/placeholder.svg'); ?>" >
								<?php $this->loadView('snippets/part-svgloader', false); ?>
							<p>Optimized Image</p>
							</div>
					</div>
	      </div> <!-- slidemask -->
			</div>  <!-- preview wrapper -->
    </div>

		<div id="preloader" class="hidden">


  </div>


</section>
