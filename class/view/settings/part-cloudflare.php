<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

    <section id="tab-cloudflare" class="<?php echo esc_attr(($this->display_part == 'cloudflare') ? 'sel-tab ' :''); ?>">
        <h2><a class='tab-link' href='javascript:void(0);'
               data-id="tab-cloudflare"><?php esc_html_e('Cloudflare API', 'shortpixel-image-optimiser'); ?></a>
        </h2>

        <div class="wp-shortpixel-tab-content" style="visibility: hidden">
        </div>

    </section>
