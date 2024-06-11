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

            <p>
              <?php esc_html_e("If you are using Cloudflare on your site, we recommend that you to fill in the details below. This will allow ShortPixel to work seamlessly with Cloudflare, so that any image optimized/restored by ShortPixel is automatically updated on Cloudflare as well.",'shortpixel-image-optimiser');?>
              <i class="documentation dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/160-cloudlfare"></i>
            </p>

            <h4>Optimize</h4>
            <settinggrid>
               <setting>
                <switch>
                    <label>
                      <input type="checkbox" class="switch" name="processThumbnails" value="1" <?php checked($view->data->processThumbnails, '1');?>>
                      <div class="the_switch">&nbsp; </div>
                      <?php printf(esc_html__('Apply compression also to %s image thumbnails.%s ','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                    </label>
                </switch>
              </setting>
              <setting>
               <switch>
                   <label>
                     <input type="checkbox" class="switch" name="processThumbnails" value="1" <?php checked($view->data->processThumbnails, '1');?>>
                     <div class="the_switch">&nbsp; </div>
                     <?php printf(esc_html__('Apply compression also to %s image thumbnails.%s ','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                   </label>
               </switch>
             </setting>
             <setting>
              <switch>
                  <label>
                    <input type="checkbox" class="switch" name="processThumbnails" value="1" <?php checked($view->data->processThumbnails, '1');?>>
                    <div class="the_switch">&nbsp; </div>
                    <?php printf(esc_html__('Apply compression also to %s image thumbnails.%s ','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                  </label>
              </switch>
            </setting>
            <setting>
             <switch>
                 <label>
                   <input type="checkbox" class="switch" name="processThumbnails" value="1" <?php checked($view->data->processThumbnails, '1');?>>
                   <div class="the_switch">&nbsp; </div>
                   <?php printf(esc_html__('Apply compression also to %s image thumbnails.%s ','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                 </label>
             </switch>
           </setting>
            </settinggrid>

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


        </div>

    </section>
