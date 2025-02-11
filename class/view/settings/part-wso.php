<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$bool = apply_filters('shortpixel/settings/no_banner', true);
if (! $bool )
  return;

if ( defined('SHORTPIXEL_NO_BANNER') && SHORTPIXEL_NO_BANNER == true)
  return;

?>

<section class='wso banner'>
    <span class="image">
      <a href="https://fastpixel.io/?utm_source=SPIO" target="_blank">
      <img src="<?php echo \wpSPIO()->plugin_url() ?>res/img/fastpixel-logo.svg" />
    </a>
    </span>
    <span class="line"><h3>
      <?php printf(__('FAST%sPIXEL%s - the new website accelerator plugin from ShortPixel', 'shortpixel-image-optimiser'), '<span class="red">','</span>'); ?>
      </h3>
    </span>
  <!--  <span class="line"><h3>
       <?php printf(__('ALLOW ShortPixel SPECIALISTS TO %s FIND THE  SOLUTION FOR YOU.', 'shortpixel-image-optimiser'), '<br>'); ?>
     </h3>
   </span> -->
  <span class="button-wrap">
      <a href="https://fastpixel.io/?utm_source=SPIO" target="_blank" class='button' ><?php _e('TRY NOW!', 'shortpixel-image-optimiser'); ?></a>
  </span>
</section>
