<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$part = (isset($view->template_args['part'])) ? $view->template_args['part'] : ''; 

$do_active = true; // color all steps active until part is reached.


?>
<section class='spio-progressbar'>

  <div class="flex">
    <div class="select <?php echo (true == $do_active) ? ' active ' : '' ?>" >
        <span class='line'></span> 
        <span class="step">1</span>
        <span class="text"><?php esc_html_e('Select Images','shortpixel-image-optimiser'); ?></span>
    </div>

    <?php if ('selection' == $part) {
       $do_active = false; } ?> 

    <div class="summary <?php echo (true == $do_active) ? ' active ' : '' ?>"  >
        <span class='line'></span>
        <span class="step">2</span>
        <span class="text"><?php esc_html_e('Summary','shortpixel-image-optimiser'); ?></span>
    </div>

    <?php if ('summary' == $part) {
       $do_active = false; } ?> 

    <div class="process <?php echo (true == $do_active) ? ' active ' : '' ?>" >
        <span class='line'></span>
        <span class="step">3</span>
        <span class="text"><?php esc_html_e('Bulk Process','shortpixel-image-optimiser'); ?></span>
    </div>

    <?php if ('process' == $part) {
       $do_active = false; } ?> 

    <div class="result <?php echo (true == $do_active) ? ' active ' : '' ?>" >
        <span class='line'></span>
        <span class="step">4</span>
        <span class="text"><?php esc_html_e('Results','shortpixel-image-optimiser'); ?></span>
    </div>
  </div>

</section>
