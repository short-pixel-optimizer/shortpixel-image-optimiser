<?php
namespace ShortPixel;

?>

    <section id="tab-cloudflare" <?php echo ($this->display_part == 'cloudflare') ? ' class="sel-tab" ' :''; ?>>
        <h2><a class='tab-link' href='javascript:void(0);'
               data-id="tab-cloudflare"><?php _e('Cloudflare API', 'shortpixel-image-optimiser'); ?></a>
        </h2>

        <div class="wp-shortpixel-tab-content" style="visibility: hidden">
            <?php

            if(! $this->is_curl_installed) {
                echo('<p style="font-weight:bold;color:red">' . __("Please enable PHP cURL extension for the Cloudflare integration to work.", 'shortpixel-image-optimiser') . '</p>' );
            }
            ?>
            <p><?php _e("If you're using Cloudflare on your site then we advise you to fill in the details below. This will allow ShortPixel to work seamlessly with Cloudflare so that any image optimized/restored by ShortPixel will be automatically updated on Cloudflare as well.",'shortpixel-image-optimiser');?></p>

            <table class="form-table">
                <tbody>
                  <tr>
                      <th scope="row"><label
                                  for="cloudflare-zone-id"><?php _e('Zone ID:', 'shortpixel-image-optimiser'); ?></label>
                      </th>
                      <td>
                          <input name="cloudflareZoneID" type="text" id="cloudflare-zone-id" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>
                                 value="<?php echo(stripslashes(esc_html($view->data->cloudflareZoneID))); ?>" class="regular-text">
                          <p class="settings-info">
                              <?php _e('This can be found in your Cloudflare account in the "Overview" section for your domain.','shortpixel-image-optimiser');?>
                          </p>
                      </td>
                  </tr>

                <tr>
                    <th scope="row">&nbsp;</th>
                    <td><h3><?php _e('Cloudflare Authentication', 'shortpixel-image-optimiser'); ?></h3></td>
                </tr>

                <tr>
                    <th scope="row" class='cf_switch'>
                      <?php
                        $token_checked =   (strlen($view->data->cloudflareToken) > 0) ? 'checked' : '';
                        $global_checked =  (strlen($view->data->cloudflareAuthKey) > 0) ? 'checked' : '';

                        if ($token_checked == '' && $global_checked == '')
                           $token_checked = 'checked'; // default.


                      ?>
                          <label><input type='radio' name='cf_auth_switch' value='token' <?php echo $token_checked ?> ><span><?php _e('Cloudflare Token', 'shortpixel-image-optimiser'); ?></span></label>
                          <label><input type='radio' name='cf_auth_switch' value='global' <?php echo $global_checked ?> ><span><?php _e('Global API Key', 'shortpixel-image-optimiser') ?></span></label>
                    </th>
                    <td class='token-cell'>
                      <input name="cloudflareToken" type="text"  id="cloudflare-token" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>  value="<?php echo $view->data->cloudflareToken ?>" class='regular-text' autocomplete="off">
                      <p class='settings-info'><?php printf(__('%s Preferred Method %s. Enter your %s site token %s for authentication. This token needs %s Cache Purge permission %s! ', 'shortpixel-image-optimiser'), '<b>', '</b>', '<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank">', '</a>', '<a href="https://help.shortpixel.com/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token" target="_blank">', '</a>'); ?></p>
                      <p class='settings-info'><?php _e('When using a token, leave the email and global API key fields empty.', 'shortpixel-image-optimiser'); ?>
                        <a href="https://help.shortpixel.com/article/325-using-shortpixel-image-optimizer-with-cloudflare-api-token/" target="_blank" class="shortpixel-help-link">
                            <span class="dashicons dashicons-editor-help"></span><?php _e('How to set it up','shortpixel-image-optimiser');?>
                        </a></p>
                    </td>
                    <td class='authkey-cell'>
                        <input name="cloudflareAuthKey" type="text" id="cloudflare-auth-key" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?> value="<?php echo(stripslashes(esc_html($view->data->cloudflareAuthKey))); ?>" class="regular-text" autocomplete="off">
                        <p class="settings-info">
                            <?php _e("This can be found when you're logged into your account, on the My Profile page:",'shortpixel-image-optimiser');?> <a href='https://www.cloudflare.com/a/profile' target='_blank'>https://www.cloudflare.com/a/profile</a>
                        </p>
                    </td>
                </tr>
                <tr class='email-cell'>
                    <th scope="row">
                        <label for="cloudflare-email"><?php _e('Cloudflare E-mail:', 'shortpixel-image-optimiser'); ?></label>
                    </th>
                    <td>
                        <input name="cloudflareEmail" type="text" id="cloudflare-email" <?php echo(! $this->is_curl_installed ? 'disabled' : '');?>
                               value="<?php echo( stripslashes(esc_html($view->data->cloudflareEmail))); ?>" class="regular-text">
                        <p class="settings-info">
                            <?php _e('The e-mail address you use to login to CloudFlare.','shortpixel-image-optimiser');?>
                        </p>
                    </td>
                </tr>

                </tbody>
            </table>
            <p class="submit">
                <input type="submit" name="saveCloudflare" id="saveCloudflare" class="button button-primary"
                       title="<?php _e('Save Changes', 'shortpixel-image-optimiser'); ?>"
                       value="<?php _e('Save Changes', 'shortpixel-image-optimiser'); ?>"> &nbsp;
            </p>
        </div>
        <script language="javascript">

            function switchCF()
            {
                if ( jQuery('input[name="cf_auth_switch"]:checked').val() == 'token')
                {
                    jQuery('.authkey-cell, .email-cell').hide();
                    jQuery('.token-cell').show();
                }
                else
                {
                    jQuery('.token-cell').hide();
                    jQuery('.authkey-cell, .email-cell').show();
                }
            }
            switchCF();
            jQuery('input[name="cf_auth_switch"]').on('change', switchCF);
        </script>
    </section>
