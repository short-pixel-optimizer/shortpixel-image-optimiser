<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<hr class="wp-header-end">

<div class="wrap is-shortpixel-bulk-page">
<header>
  <h1>
      <?php echo UIHelper::getIcon('res/images/illustration/logo_settings.svg'); ?>
      <?php //esc_html_e('ShortPixel Plugin Settings','shortpixel-image-optimiser');?>
  </h1>

<!--
  <div class='top-buttons'>
    <button><i class='shortpixel-icon notifications'></i><?php _e('Notifications','shortpixel-image-optimiser'); ?></button>

  </div>
-->
</header>
<div class="shortpixel-bulk-wrapper">

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
