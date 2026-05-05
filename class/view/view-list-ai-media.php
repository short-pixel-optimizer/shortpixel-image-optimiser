<?php 
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<?php if (property_exists($this->view, 'ai_icon')): ?> 
<i class="shortpixel-icon <?php echo esc_attr($this->view->ai_icon); ?>" title="<?php echo esc_attr($this->view->ai_title); ?>"></i>
<!-- <div class='ai-messagebox' id="shortpixel-ai-messagebox-<?php echo esc_attr($this->view->item_id); ?>">&nbsp;</div>-->
<?php endif ?>
