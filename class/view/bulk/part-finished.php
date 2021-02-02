<?php

namespace ShortPixel;

?>
<section class="panel finished" data-panel="finished">
  <div class="panel-container">

    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      Shortpixel Bulk is finished
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

    <nav>
      <button class='button finish' data-action="FinishBulk" id="FinishBulkButton">Finish Bulk</button>
    </nav>




  </div>
</section>
