<?php

namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Helper\UiHelper as UiHelper;

use ShortPixel\Controller\OtherMediaController as OtherMediaController;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
if ( isset($_GET['noheader']) ) {
    require_once(ABSPATH . 'wp-admin/admin-header.php');
}

	 $this->loadView('custom/part-othermedia-top');

	 $folder_url = esc_url(add_query_arg('part', 'folders', $this->url));

	 $otherMediaController = OtherMediaController::getInstance();
?>

<div class='scan-area'>

  <div class='scan-actions'>
		<h2><?php _e('Actions', 'shortpixel-image-optimiser') ?></h2>
		<p><?php printf(__('Scan folders for images that are not yet included in custom media. If you only want to check specific folders, you can do this in the %sFolders tab%s.', 'shortpixel-image-optimiser'), '<a href="' . $folder_url . '">', '</a>'); ?>
		</p>

		<div class='action-scan'>
			<button type="button" name="scan" class='scan-button button button-primary'>
				<?php _e('Refresh all folders', 'shortpixel-image-optimiser'); ?>
			</button>
			<label><?php _e('Refresh all folders since the last refresh time. This is faster.', 'shortpixel-image-optimiser'); ?>
			</label>
		</div>

		<div class='action-scan'>
			<button type="button" name="fullscan" class='scan-button full button button-primary' data-mode="force">
				 <?php _e('Full scan of all folders', 'shortpixel-image-optimiser'); ?>
			</button>
			<label>
				<?php _e('Fully scan all folders and check all files again.', 'shortpixel-image-optimiser'); ?>
			</label>

		</div>

		<div class='action-stop not-visible' >
			<button type="button" name="stop" class="stop-button button">
					<?php _e('Stop Scanning', 'shortpixel-image-optimiser'); ?>
			</button>
			<label>
				<?php _e('Stop current scan process', 'shortpixel-image-optimiser'); ?>
			</label>
		</div>
	</div>

  <div class='output result not-visible'>
			<h2><?php _e('Results', 'shortpixel-image-optimiser'); ?></h2>
			<div class='result-table'>

			</div>
  </div>

  <div class='scan-help'>
    <p><?php printf(__('If new images are regularly added to your Custom Media folders from outside WordPress (e.g. via (S)FTP or SSH), you must manually click on "Refresh all folders" so that the new images are recognized and optimized. Alternatively, you can also set up a regular cron job as described in our %s Knowledge Base %s.', 'shortpixel-image-optimiser'),
    '<a href="https://shortpixel.com/knowledge-base/article/543-how-to-schedule-a-cron-event-to-run-shortpixel-image-optimizer" target="_blank">', '</a>'
    ); ?></p>
  </div>


</div> <!-- / scan-area -->

</div> <!--- wrap from othermedia-top -->
