<?php 
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<?php if (property_exists($this->view, 'icon')): ?> 
<i class="shortpixel-icon <?php echo $this->view->icon ?>" title="<?php echo $this->view->title ?>"></i>
<?php endif ?>
