<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<div class="shortpixel-bulk-wrapper">
  <h1><?php esc_html_e('ShortPixel Bulk Processing', 'shortpixel-image-optimiser'); ?></h1>

  <div id="processPaused" class="processor-paused" data-action="ResumeBulk"><span class='dashicons dashicons-controls-pause' data-action="ResumeBulk"></span>
		<?php esc_html_e('The Bulk Processing is paused, please click to resume','shortpixel-image-optimiser'); ?>
    <p class='small'><?php _e('If you have activated background mode, please note that this process will continue', 'shortpixel-image-optimiser'); ?></p>
  </div>

  <div id="processorOverQuota" class="processor-overquota">
			<h3><?php esc_html_e('There are no credits left. The Bulk Processing is paused.','shortpixel-image-optimiser'); ?></h3>
			<p><a href="javascript:window.location.reload()"><?php esc_html_e('Click to reload page after adding credits','shortpixel-image-optimiser'); ?></a></p>
	</div>

  <div class="screen-wrapper">

  <?php
  $this->loadview('bulk/part-dashboard');
  $this->loadView('bulk/part-selection');
  $this->loadView('bulk/part-summary');
  $this->loadView('bulk/part-process');
  $this->loadView('bulk/part-finished');

  $this->loadView('bulk/part-bulk-special');

  ?>

  </div>

</div> <!-- wrapper -->
