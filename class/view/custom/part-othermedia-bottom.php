<?php
namespace ShortPixel;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<div class='pagination tablenav bottom'>
  <div class='tablenav-pages'>
      <?php echo $this->view->pagination; ?>
  </div>
</div>
