<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


?>



<section id="tab-settings" class="<?php echo ($this->display_part == 'delivery') ? 'active setting-tab' :'setting-tab'; ?>" data-part="delivery" >

<settinglist>

  <h2><?php esc_html_e('Delivery','shortpixel-image-optimiser');?></h2>



</settinglist>


  <?php $this->loadView('settings/part-savebuttons', false); ?>
</section>
