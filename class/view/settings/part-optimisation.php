<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>


<section id="tab-settings" class="<?php echo ($this->display_part == 'optimisation') ? 'active setting-tab' :'setting-tab'; ?>" data-part="optimisation" >


  <settinglist>
    <h2>
      <?php esc_html_e('Image Optimisation','shortpixel-image-optimiser');?>
    </h2>
  </settinglist>



</section>
