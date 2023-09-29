<?php

namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
if ( isset($_GET['noheader']) ) {
    require_once(ABSPATH . 'wp-admin/admin-header.php');
}

	 $this->loadView('custom/part-othermedia-top');


?>



<div class='scan-area'>
  <div><span> <?php printf(__(' Folders: %s  ', 'shortpixel-image-optimiser'), $view->totalFolders) ?>  </span></div>
  <div class='button action'>
		<h2><?php _e('Actions', 'shortpixel-image-optimiser') ?></h2>

		<button type="button" name="scan" class='scan-button'>
			<?php _e('Update all folders', 'shortpixel-image-optimiser'); ?>
		</button>

		<button type="button" name="fullscan" class='scan-button full' data-mode="force">
			 <?php _e('Full scan of all folders', 'shortpixel-image-optimiser'); ?>
		</button>
	</div>

  <div class='output result'>
			<h2><?php _e('Results', 'shortpixel-image-optimiser'); ?></h2>
			<div class='result-table'>

			</div>
  </div>

</div>
