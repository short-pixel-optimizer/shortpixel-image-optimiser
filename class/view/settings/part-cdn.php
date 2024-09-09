<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


?>

<section id="tab-cdn" class="<?php echo ($this->display_part == 'cdn') ? 'active setting-tab' :'setting-tab'; ?>" data-part="cdn" >


<settinglist>

  <h2>
    <?php esc_html_e('CDN','shortpixel-image-optimiser');?>
  </h2>

  <setting>
      <name><?php esc_html_e('HTTP AUTH credentials','shortpixel-image-optimiser');?></name>

      <content>
        <?php if (! defined('SHORTPIXEL_HTTP_AUTH_USER')): ?>
        <input name="siteAuthUser" type="text" id="siteAuthUser" value="<?php echo( esc_html(wp_unslash($view->data->siteAuthUser )));?>" class="regular-text" placeholder="<?php esc_html_e('User','shortpixel-image-optimiser');?>" style="margin-bottom: 8px"><br>
        <input name="siteAuthPass" type="text" id="siteAuthPass" value="<?php echo( esc_html(wp_unslash($view->data->siteAuthPass )));?>" class="regular-text" placeholder="<?php esc_html_e('Password','shortpixel-image-optimiser');?>" style="margin-bottom: 8px">
        <info>
            <?php printf(esc_html__('Only fill in these fields if your site (front-end) is not publicly accessible and visitors need a user/pass to connect to it.
                      If you don\'t know what is this then just %sleave the fields empty%s.','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
        </info>
        <?php else:  ?>
            <p><?php esc_html_e('The HTTP AUTH credentials have been defined in the wp-config file.', 'shortpixel-image-optimiser'); ?></p>
        <?php endif; ?>
      </content>

  </setting>
</settinglist>



<?php
if(! $this->is_curl_installed) {
    echo('<p style="font-weight:bold;color:red">' . esc_html__("Please enable PHP cURL extension for the Cloudflare integration to work.", 'shortpixel-image-optimiser') . '</p>' );
}
?>

<p>
  <?php esc_html_e("If you are using Cloudflare on your site, we recommend that you to fill in the details below. This will allow ShortPixel to work seamlessly with Cloudflare, so that any image optimized/restored by ShortPixel is automatically updated on Cloudflare as well.",'shortpixel-image-optimiser');?>
  <i class="documentation dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/160-cloudlfare"></i>
</p>

<h3><?php esc_html_e('Cloudflare', 'shortpixel-image-optimiser') ?></h3>

<settinglist>
   <setting>
      <name><?php esc_html_e('Zone ID', 'shortpixel-image-optimiser'); ?></name>
      <content>
        <input name="cloudflareZoneID" type="text" id="cloudflare-zone-id" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>
               value="<?php echo( esc_attr(wp_unslash($view->data->cloudflareZoneID))); ?>"
               class="regular-text">
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/160-cloudlfare"></i>

        <info>
            <?php esc_html_e('You can find this in your Cloudflare account in the "Overview" section for your domain.','shortpixel-image-optimiser');?>
        </info>
      </content>
   </setting>


   <setting>
      <name><?php esc_html_e('Cloudflare Token', 'shortpixel-image-optimiser'); ?>
      </name>
      <content>
        <input name="cloudflareToken" type="text"  id="cloudflare-token" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>  value="<?php echo esc_attr($view->data->cloudflareToken) ?>" class='regular-text' autocomplete="off">
        <info>
            <?php printf(esc_html__('Enter your %s site token %s for authentication. This token needs %s Cache Purge permission %s! ', 'shortpixel-image-optimiser'), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank">', '</a>'); ?>
        </info>
        <p><a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank" class="shortpixel-help-link">
              <?php esc_html_e('How to set it up','shortpixel-image-optimiser');?>
          </a></p>
     </content>
  </setting>

</settinglist>



<?php $this->loadView('settings/part-savebuttons', false); ?>
</section>
