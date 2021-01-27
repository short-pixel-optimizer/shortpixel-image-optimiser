<?php

namespace ShortPixel;

?>
<section class="panel process" data-panel="process" data-loadpanel="StartBulk">
  <div class="panel-container">

    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Shortpixel Bulk is in progress
      <div class='average-optimization'>
          <p>Overal optimization</p>
          <svg class="opt-circle" viewBox="-10 0 150 140">
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
                        <text class="text" x="50" y="50">--</text>
                    </svg>

      </div>
    </h3>

    <p class='description'>Welcome to the bulk optimization wizard, where you will be able to select the images that ShortPixel will optimize in the background for you.</p>

    <?php $this->loadView('bulk/part-progressbar'); ?>

    <div class='bulk-summary'>
      <div class='heading'>
        <span><i class='dashicons dashicons-images-alt2'>&nbsp;</i> Media Library</span>
        <span>
              <span class='progressbar'>
                <span class='done-text'><i data-stats-media="percentage_done"></i> %</span>
                <span class='done' data-stats-media="percentage_done" data-presentation="css.width.percentage"></span>
              </span>
        </span>
        <span>&nbsp;</span>
        <span>Check Details</span>
      </div>
      <div>
        <span>Items processed: <i data-stats-media="done">-</i></span>
        <span>Processing : <i data-stats-media="in_process">-</i></span>
        <span>Items Left <i data-stats-media="in_queue">-</i></span>
        <span>Errors : <i data-stats-media="errors">-</i> Check Errors</span>
      </div>

    </div>

    <div class='bulk-summary'>
      <div class='heading'>
        <span><i class='dashicons dashicons-open-folder'>&nbsp;</i> Other Media</span>
        <span>
              <span class='progressbar'>
                <span class='done-text'><i data-stats-custom="percentage_done"></i> %</span>
                <span class='done' data-stats-custom="percentage_done" data-presentation="css.width.percentage"></span>
              </span>
        </span>
        <span>&nbsp;</span>
        <span>Check Details</span>
      </div>
      <div>
        <span>Items processed: <i data-stats-custom="done">-</i></span>
        <span>Processing : <i data-stats-custom="in_process">-</i></span>
        <span>Items Left <i data-stats-custom="in_queue">-</i></span>
        <span>Errors : <i data-stats-custom="errors">-</i> Check Errors</span>
      </div>

    </div>

    <div class='image-preview'>
      <div class="image-preview-line">
        <strong data-result="queuetype"></strong> <span data-result="filename">Pending</span>

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
        <div class="image-source">
          <img src="">
          <p>Original Image</p>
        </div>
        <img src="<?php echo \wpSPIO()->plugin_url('res/img/bulk/optimize-arrow-right.svg') ?>" />
        <div class="image-result">
          <img src="">
          <p>Optimized Image</p>
        </div>
      </div>
    </div>

    <nav>
      <button class='button pause' data-action="PauseBulk" id="PauseBulkButton">Pause Bulk Processing</button>
      <button class='button resume' data-action='ResumeBulk' id="ResumeBulkButton">Resume Bulk Processing</button>
      <button class='button-primary stop' data-action="StopBulk" >Stop Bulk Processing</button>
    </nav>
  </div>
</section>
