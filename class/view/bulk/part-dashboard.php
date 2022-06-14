<?php
namespace ShortPixel;
?>

<section class='dashboard panel active' data-panel="dashboard" style='display: block'  >

  <?php //$this->loadView('bulk/part-progressbar', false); ?>

  <div class="panel-container">


    <h3 class="heading"><span><img src="<?php echo \wpSPIO()->plugin_url('res/img/robo-slider.png'); ?>"></span>
      <?php _e('Welcome to the Bulk Processing page!', 'shortpixel-image-optimiser'); ?>
    </h3>

    <div class='interface wrapper'>

      <div class='bulk-wrapper'>
        <button type="button" class="button-primary button" id="start-optimize" data-action="open-panel" data-panel="selection" <?php echo ($this->view->error) ? "disabled" : ''; ?>  >
            <span class='dashicons dashicons-controls-play'>&nbsp;</span>
						<p><?php _e('Start optimizing','shortpixel-image-optimiser'); ?></p>
        </button>
      </div>

      <p class='description'><?php _e('Here you can (re)optimize your Media Library or Custom Media folders from your website.', 'shortpixel-image-optimiser'); ?></p>

   </div>

   <?php if ($this->view->error): ?>
     <div class='bulk error'>
        <h3><?php echo $this->view->errorTitle; ?></h3>
        <p><?php echo $this->view->errorContent; ?></p>
        <?php if (property_exists($this->view, 'errorText')): ?>
            <p class='text'><?php echo $this->view->errorText ?></p>
        <?php endif; ?>

     </div>

   <?php endif; ?>

   <?php if (! $this->view->error): /*
     <div class='advanced-actions'>
       <h4><?php _e('Advanced Options','shortpixel-image-optimiser'); ?></h4>
       <button type="button" class="button" id="bulk-restore" data-action='open-panel' data-panel="bulk-restore"><?php _e('Bulk Restore', 'shortpixel-image-optimiser'); ?></button>
       <button type="button" class="button" id="bulk-restore" data-action='open-panel' data-panel="bulk-migrate"><?php _e('Migrate from 4x', 'shortpixel-image-optimiser'); ?></button>

     </div>

   */ endif; ?>

   <?php if (count($this->view->logs) > 0): ?>

	 <div id="LogModal" class="shortpixel-modal shortpixel-hide bulk-modal">
		 <span class="close" data-action="CloseModal" data-id="LogModal">X</span>
	 	  <div class='title'>

			</div>
			<div class="content sptw-modal-spinner">
				 <div class='table-wrapper'>

				 </div>

			</div>
	 </div>
	 <div id="LogModal-Shade" class='sp-modal-shade'></div>
   <div class='dashboard-log'>

      <h3><?php _e('Previous Bulks', 'shortpixel_image_optimizer'); ?></h3>
      <?php
        echo "<div class='head'>";
        foreach($this->view->logHeaders as $header)
        {
           echo "<span>$header</span>";
        }
        echo "</div>";
        foreach ($this->view->logs as $logItem):
        {
          	echo "<div class='data " . $logItem['type'] . "'>";

					  echo "<span>" . $logItem['images']  . '</span>';
						echo "<span>" . $logItem['errors'] . '</span>';

              echo '<span class="checkmark_green date">' . sprintf(__('%sCompleted%s on %s','shortpixel-image-optimiser'), '<b>','</b>', $logItem['date']) . '</span>';

						echo "<span>" . $logItem['bulkName'] . '</span>';

          echo "</div>";
         }
        ?>


      <?php endforeach; ?>

   </div>
  <?php endif; ?>


  <?php if (! $this->view->error): ?>
     <div class='shortpixel-bulk-loader' id="bulk-loading" data-status='loading'>
       <div class='loader'>
				 	 <span class="svg-spinner"><?php $this->loadView('snippets/part-svgloader', false); ?></span>

           <span>
           <h2>Please wait, ShortPixel is loading</h2>

         </span>

       </div>
     </div>
  <?php endif; ?>
 </div> <!-- panel-container -->
</section> <!-- section -->
