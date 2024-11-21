<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>
<div class='top-menu'>

  <div class='links'>

		<?php if (false === $view->is_unlimited): ?>
    <a href="https://shortpixel.com/<?php
        echo esc_attr(($view->key->apiKey ? "login/". $view->key->apiKey . '/spio-unlimited': "pricing"));
    ?>" target="_blank"><?php esc_html_e( 'Buy credits', 'shortpixel-image-optimiser' );?></a> |
	  <?php endif; ?>

    <a href="https://shortpixel.com/knowledge-base/" target="_blank"><?php esc_html_e('Knowledge Base','shortpixel-image-optimiser');?></a> |
    <a href="https://shortpixel.com/contact" target="_blank"><?php esc_html_e('Contact Support','shortpixel-image-optimiser');?></a> |
    <a href="https://shortpixel.com/<?php
        echo esc_attr(($view->key->apiKey ? "login/". $view->key->apiKey . "/dashboard" : "login"));
    ?>" target="_blank">
        <?php esc_html_e('ShortPixel account','shortpixel-image-optimiser');?>
    </a>
    | <a href="mailto:help@shortpixel.com?subject=SPIO Feature Request"><?php _e('Feature Request', 'shortpixel-image-optimiser'); ?>
    </a>
    | <a href="https://wordpress.org/support/plugin/shortpixel-image-optimiser/reviews/#new-post" target="_blank">   <?php _e('Rate Us', 'shortpixel-image-optimiser'); ?><img src="<?php echo esc_attr(\wpSPIO()->plugin_url('res/img/stars.png')); ?>" width="80" /></a>
  </div>


</div>
