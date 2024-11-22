<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


?>

<section id="tab-integrations" class="<?php echo ($this->display_part == 'integrations') ? 'active setting-tab' :'setting-tab'; ?>" data-part="integrations" >

<settinglist>
  <h2>
    <?php esc_html_e('Integrations','shortpixel-image-optimiser');?>
  </h2>
</settinglist>

  <h3><?php esc_html_e('HTTP AUTH credentials', 'shortpixel-image-optimiser') ?></h3>
<settinglist>
  <setting>
      <content>
        <?php if (! defined('SHORTPIXEL_HTTP_AUTH_USER')): ?>
        <inputlabel>User</inputlabel> <input name="siteAuthUser" type="text" id="siteAuthUser" value="<?php echo( esc_html(wp_unslash($view->data->siteAuthUser )));?>" class="regular-text" placeholder="<?php esc_html_e('User','shortpixel-image-optimiser');?>" style="margin-bottom: 8px"><br>
        <inputlabel>Password</inputlabel> <input name="siteAuthPass" type="password" id="siteAuthPass" value="<?php echo( esc_html(wp_unslash($view->data->siteAuthPass )));?>" class="regular-text" placeholder="<?php esc_html_e('Password','shortpixel-image-optimiser');?>" style="margin-bottom: 8px">
        <info>
            <?php printf(esc_html__('Only fill in these fields if your website\'s front end is not publicly accessible and requires a username and password for visitors to connect.
                      If you\'re unsure, simply %sleave these fields empty%s. Please note that the CDN delivery method will not work if your site is protected by HTTP AUTH.','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
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

<h3><?php esc_html_e('Cloudflare', 'shortpixel-image-optimiser') ?></h3>

<p>
  <?php esc_html_e("If you are using Cloudflare on your site, we recommend filling in the details below. This allows ShortPixel to work seamlessly with Cloudflare, ensuring that any images optimized or restored by ShortPixel are automatically updated on Cloudflare as well.",'shortpixel-image-optimiser');?>
  <i class="documentation up dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/160-cloudlfare?target=iframe"></i>
</p>

<settinglist>
   <setting>
      <content>
      <inputlabel>Zone ID  </inputlabel> <input name="cloudflareZoneID" type="text" id="cloudflare-zone-id" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>
               value="<?php echo( esc_attr(wp_unslash($view->data->cloudflareZoneID))); ?>"
               class="regular-text">
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/160-cloudlfare?target=iframe"></i>

        <info>
            <?php esc_html_e('You can find this in your Cloudflare account in the "Overview" section for your domain.','shortpixel-image-optimiser');?>
        </info>

        <inputlabel>Token</inputlabel> <input name="cloudflareToken" type="text"  id="cloudflare-token" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>  value="<?php echo esc_attr($view->data->cloudflareToken) ?>" class='regular-text' autocomplete="off">
        <info>
            <?php printf(esc_html__('Enter your %s site token %s for authentication. This token must have %s Cache Purge permission %s! ', 'shortpixel-image-optimiser'), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank">', '</a>'); ?>
        <a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank" class="shortpixel-help-link">
              <?php esc_html_e('How to set it up','shortpixel-image-optimiser');?>
          </a>
        </info>
     </content>
   </setting>


<!--
   <setting>
      <name><?php esc_html_e('Cloudflare Token', 'shortpixel-image-optimiser'); ?>
      </name>
      <content>
        <input name="cloudflareToken" type="text"  id="cloudflare-token" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>  value="<?php echo esc_attr($view->data->cloudflareToken) ?>" class='regular-text' autocomplete="off">
        <info>
            <?php printf(esc_html__('Enter your %s site token %s for authentication. This token needs %s Cache Purge permission %s! ', 'shortpixel-image-optimiser'), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank">', '</a>'); ?>
        <a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank" class="shortpixel-help-link">
              <?php esc_html_e('How to set it up','shortpixel-image-optimiser');?>
          </a>
        </info>
     </content>
  </setting>
-->
</settinglist>



<?php $this->loadView('settings/part-savebuttons', false); ?>
</section>
