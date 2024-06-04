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
            <?php
            if(! $this->is_curl_installed) {
                echo('<p style="font-weight:bold;color:red">' . esc_html__("Please enable PHP cURL extension for the Cloudflare integration to work.", 'shortpixel-image-optimiser') . '</p>' );
            }
            ?>

			  <div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/160-cloudlfare"></span></div>

            <p>
              <?php esc_html_e("If you are using Cloudflare on your site, we recommend that you to fill in the details below. This will allow ShortPixel to work seamlessly with Cloudflare, so that any image optimized/restored by ShortPixel is automatically updated on Cloudflare as well.",'shortpixel-image-optimiser');?>
            </p>

            <optionlist>

               <header>Hello</header>

               <setting>
                  <title><?php esc_html_e('Zone ID', 'shortpixel-image-optimiser'); ?></title>
                  <input name="cloudflareZoneID" type="text" id="cloudflare-zone-id" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>
                         value="<?php echo( esc_attr(wp_unslash($view->data->cloudflareZoneID))); ?>" class="regular-text">
                  <p class="settings-info">
                      <?php esc_html_e('You can find this in your Cloudflare account in the "Overview" section for your domain.','shortpixel-image-optimiser');?>
                  </p>
               </setting>



            </optionlist>

            <table class="form-table">
                <tbody>
                  <tr>
                      <th scope="row"><label
                                  for="cloudflare-zone-id"><?php esc_html_e('Zone ID', 'shortpixel-image-optimiser'); ?></label>
                      </th>
                      <td>
                          <input name="cloudflareZoneID" type="text" id="cloudflare-zone-id" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>
                                 value="<?php echo( esc_attr(wp_unslash($view->data->cloudflareZoneID))); ?>" class="regular-text">
                          <p class="settings-info">
                              <?php esc_html_e('You can find this in your Cloudflare account in the "Overview" section for your domain.','shortpixel-image-optimiser');?>
                          </p>
                      </td>
                  </tr>

                <tr>
                    <th scope="row" class='cf_switch'>

                          <span><?php esc_html_e('Cloudflare Token', 'shortpixel-image-optimiser'); ?></span>

                    </th>
                    <td class='token-cell'>
                      <input name="cloudflareToken" type="text"  id="cloudflare-token" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>  value="<?php echo esc_attr($view->data->cloudflareToken) ?>" class='regular-text' autocomplete="off">

	                      <p class='settings-info'><?php printf(esc_html__('Enter your %s site token %s for authentication. This token needs %s Cache Purge permission %s! ', 'shortpixel-image-optimiser'), '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank">', '</a>'); ?></p>
                        <a href="https://shortpixel.com/knowledge-base/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank" class="shortpixel-help-link">
                            <span class="dashicons dashicons-editor-help"></span><?php esc_html_e('How to set it up','shortpixel-image-optimiser');?>
                        </a>

                    </td>

                </tr>


                </tbody>
            </table>

        </div>

    </section>
