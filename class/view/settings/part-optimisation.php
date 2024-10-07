<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>


<section id="tab-optimisation" class="<?php echo ($this->display_part == 'optimisation_off') ? 'active setting-tab' :'setting-tab'; ?>" data-part="optimisation_off" >


  <settinglist>
    <h2>
      <?php esc_html_e('Image Optimisation','shortpixel-image-optimiser');?>
    </h2>
  </settinglist>



</section>
