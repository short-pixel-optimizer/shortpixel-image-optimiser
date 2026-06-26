<?php 
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>
<div class='save-buttons'>
    <button type="submit" class='save'>
        <i class='shortpixel-icon save'></i>
        <?php _e('Save', 'shortpixel-image-optimiser'); ?>
    </button>
    <button type="submit" class='save-bulk' name='save-bulk' value='check'>
        <i class='shortpixel-icon bulk'></i>
        <?php esc_attr_e('Save and Go to Bulk Process','shortpixel-image-optimiser');?>
    </button>


</div>
