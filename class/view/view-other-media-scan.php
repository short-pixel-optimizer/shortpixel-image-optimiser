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
  <div><span> Count XX amount of folder  - last update xxxd </span></div>
  <div class='button action'><button type="button" name="scan"><?php _e('Scan and update all folder', 'shortpixel-image-optimiser'); ?></button></div>

  <div class='output result'>
      -- Result can be here --
  </div>

</div>
