<?php
namespace ShortPixel;
use ShortPixel\Notices\NoticeController as Notice;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

// Notices for fringe cases
if (! $view->key->is_verifiedkey && $view->key->hide_api_key && ! $view->key->is_constant_key)
{
  Notice::addError(__('wp-config.php is hiding the API key, but no API key was found. Remove the constant, or define the SHORTPIXEL_API_KEY constant as well', 'shortpixel-image-optimiser'));
}
elseif ($view->key->is_constant_key && ! $view->key->is_verifiedkey)
{
  $dkey = ($view->key->hide_api_key) ? '' : '(' . SHORTPIXEL_API_KEY.  ')';
  Notice::addError(sprintf(__('Constant API Key is not verified. Please check if this is a valid API key %s'),$dkey));
}

$adminEmail = get_bloginfo('admin_email');

?>
<section id="tab-settings" class="sel-tab" >
    <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-settings">
      <?php esc_html_e('Join ShortPixel','shortpixel-image-optimiser');?></a>
    </h2>
<div class="wp-shortpixel-options wp-shortpixel-tab-content">
	 <!-- // @todo Inline CSS on whole page-->
  <h3><?php esc_html_e('Request an API Key:','shortpixel-image-optimiser');?></h3>
<p><?php esc_html_e('If you don\'t have an API Key, you can request one for free. Just press the "Request Key" button after checking that the e-mail is correct.','shortpixel-image-optimiser');?></p>

<settinglist>

  <form method="POST" action="<?php echo esc_url(add_query_arg(array('noheader' => 'true', 'sp-action' => 'action_request_new_key'))) ?>"
      id="shortpixel-form-request-key">
  <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

  <setting>
      <name for="pluginemail"><?php esc_html_e('E-mail address:','shortpixel-image-optimiser');?></name>
      <content>
              <input name="pluginemail" type="text" id="pluginemail" value="<?php echo esc_attr( sanitize_email($adminEmail) );?>" class="regular-text">

              <span class="spinner" id="pluginemail_spinner" style="float:none;"></span>

              <button type="submit" id="request_key" class="button button-primary" title="<?php esc_html_e('Request a new API key','shortpixel-image-optimiser');?>"
                 href="https://shortpixel.com/free-sign-up?pluginemail=<?php echo esc_attr( esc_url($adminEmail) );?>">
                 <?php esc_html_e('Request Key','shortpixel-image-optimiser');?>
              </button>

              <info>
                <p class="settings-info shortpixel-settings-error" style='display:none;' id='pluginemail-error'>
                    <b><?php esc_html_e('Please provide a valid e-mail address.', 'shortpixel-image-optimiser');?></b>
                </p>
                <p class="settings-info" id='pluginemail-info'>
                    <?php if($adminEmail) {
                        printf(esc_html__('%s %s %s is the e-mail address in your WordPress Settings. You can use it, or change it to any valid e-mail address that you own.','shortpixel-image-optimiser'), '<b>', esc_html(sanitize_email($adminEmail)),  '</b>');
                    } else {
                        esc_html_e('Please input your e-mail address and press the Request Key button.','shortpixel-image-optimiser');
                    }
                    ?><p><span style="position:relative;">
                        <input name="tos" type="checkbox" id="tos">
                        <img id="tos-robo" alt="<?php esc_html_e('ShortPixel logo', 'shortpixel-image-optimiser'); ?>"
                             src="<?php echo esc_url(wpSPIO()->plugin_url('res/img/slider.png' ));?>" style="position: absolute;left: -95px;bottom: -26px;display:none;">
                        <img id="tos-hand" alt="<?php esc_html_e('Hand pointing', 'shortpixel-image-optimiser'); ?>"
                             src="<?php echo esc_url(wpSPIO()->plugin_url('res/img/point.png' ));?>" style="position: absolute;left: -39px;bottom: -9px;display:none;">
                    </span>
                    <label for="tos"><?php printf(esc_html__('I have read and I agree to the %s Terms of Service %s and the %s Privacy Policy %s (%s GDPR compliant %s).','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/tos" target="_blank">', '</a>', '<a href="https://shortpixel.com/privacy" target="_blank">', '</a>', '<a href="https://shortpixel.com/privacy#gdpr" target="_blank">', '</a>');
                    ?> </label></p>
              </info>
      </content>
  </setting>

</settinglist>

<h3>
    <?php esc_html_e('Already have an API Key:','shortpixel-image-optimiser');?>
</h3>
<p>
    <?php esc_html_e('If you already have an API Key please input it below and press Validate.','shortpixel-image-optimiser');?>
</p>

<settinglist>
  <form method="POST" action="<?php echo esc_url(add_query_arg(array('noheader' => 'true', 'sp-action' => 'action_addkey'))) ?>" id="shortpixel-form-nokey">
  <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

  <setting>
      <name>
          <?php esc_html_e('API Key:','shortpixel-image-optimiser');?>
      </name>
      <content>
        <input name="apiKey" type="text" id="key" value="<?php echo esc_attr( $view->key->apiKey );?>"
           class="regular-text">

              <input type="hidden" name="validate" id="valid" value="validate"/>
              <span class="spinner" id="pluginemail_spinner" style="float:none;"></span>
              <button type="submit" id="validate" class="button button-primary" title="<?php esc_html_e('Validate the provided API key','shortpixel-image-optimiser');?>"
                  >
                  <?php esc_html_e('Validate','shortpixel-image-optimiser');?>
              </button>
      </content>
  </setting>

  </form>
</settinglist>


</div> <!-- tab content -->
</section>
