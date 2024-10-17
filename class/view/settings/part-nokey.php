<?php
namespace ShortPixel;
use ShortPixel\Notices\NoticeController as Notice;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

// Notices for fringe cases
if (! $view->key->is_verifiedkey && $view->key->hide_api_key && ! $view->key->is_constant_key)
{

	$error_message = __('wp-config.php is hiding the API key, but no API key was found. Remove the constant, or define the SHORTPIXEL_API_KEY constant as well', 'shortpixel-image-optimiser');
	Notice::addError($error_message);
}
elseif ($view->key->is_constant_key && ! $view->key->is_verifiedkey)
{
  $dkey = ($view->key->hide_api_key) ? '' : '(' . SHORTPIXEL_API_KEY.  ')';
	$error_message = sprintf(__('Constant API Key is not verified. Please check if this is a valid API key %s'),$dkey);
  Notice::addError($error_message);
}

$adminEmail = get_bloginfo('admin_email');


// When key is not editable, basically all fields should be off.
$disabled = ($view->key->is_editable) ? '' : 'disabled';

?>
<section id="tab-nokey" class="<?php echo ($this->display_part == 'nokey') ? 'active setting-tab' :'setting-tab'; ?>" data-part="nokey" >

  <h1><?php _e('Welcome Onboard!', 'shortpixel-image-optimiser'); ?></h1>
  <div class='onboarding-logo'>
        <?php echo UIHelper::getIcon('res/images/illustration/onboarding.svg'); ?>
  </div>

    <progressbar>

    </progressbar>

    <!--  <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-settings">
      <?php esc_html_e('Join ShortPixel','shortpixel-image-optimiser');?></a>
    </h2> -->


		<div class='onboarding-join-wrapper'>

	 <!-- // @todo Remove Inline CSS on whole page-->


<settinglist class='new-customer now-active'>

	<h2><?php esc_html_e('New Customer','shortpixel-image-optimiser');?></h2>
<p><?php esc_html_e('If you don\'t have an API Key, you can request one for free. Just press the "Request Key" button after checking that the e-mail is correct.','shortpixel-image-optimiser');?></p>

  <form method="POST" action="<?php echo esc_url(add_query_arg(array('noheader' => 'true', 'sp-action' => 'action_request_new_key'))) ?>"
      id="shortpixel-form-request-key">

  <setting>
      <name for="pluginemail"><?php esc_html_e('E-mail address:','shortpixel-image-optimiser');?></name>
      <content>
              <input name="pluginemail" type="text" id="pluginemail" value="<?php echo esc_attr( sanitize_email($adminEmail) );?>" class="regular-text" <?php echo $disabled ?> />

              <span class="spinner" id="pluginemail_spinner" style="float:none;"></span>
<!--
              <button type="submit" id="request_key" class="button button-primary" title="<?php esc_html_e('Request a new API key','shortpixel-image-optimiser');?>"
                 href="https://shortpixel.com/free-sign-up?pluginemail=<?php echo esc_attr( esc_url($adminEmail) );?>"
								 <?php echo $disabled ?>  >
                 <?php esc_html_e('Request Key','shortpixel-image-optimiser');?>
              </button>
-->
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
                    <label for='tos'>
                        <input name="tos" type="checkbox" id="tos">
                        <img class="tos-robo" alt="<?php esc_html_e('ShortPixel logo', 'shortpixel-image-optimiser'); ?>"
                             src="<?php echo esc_url(wpSPIO()->plugin_url('res/img/slider.png' ));?>" style="position: absolute;left: -95px;bottom: -26px;display:none;">
                        <img class="tos-hand" alt="<?php esc_html_e('Hand pointing', 'shortpixel-image-optimiser'); ?>"
                             src="<?php echo esc_url(wpSPIO()->plugin_url('res/img/point.png' ));?>" style="position: absolute;left: -39px;bottom: -9px;display:none;">
                    </span>
                    <?php printf(esc_html__('I have read and I agree to the %s Terms of Service %s and the %s Privacy Policy %s (%s GDPR compliant %s).','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/tos" target="_blank">', '</a>', '<a href="https://shortpixel.com/privacy" target="_blank">', '</a>', '<a href="https://shortpixel.com/privacy#gdpr" target="_blank">', '</a>');
                    ?> </label></p>
              </info>
      </content>
  </setting>

</settinglist>

<settinglist class='existing-customer'>
	<h2>
			<?php esc_html_e('Existing Customer','shortpixel-image-optimiser');?>
	</h2>
	<p>
	    <?php esc_html_e('Welcome back! If you already have an API Key please input it below and press Validate.','shortpixel-image-optimiser');?>
	</p>


  <setting>
      <name>
          <?php esc_html_e('API Key:','shortpixel-image-optimiser');?>
      </name>
      <content>
        <input name="login_apiKey" type="text" id="key" value="<?php echo esc_attr( $view->key->apiKey );?>"
           class="regular-text" <?php echo $disabled ?>>

              <input type="hidden" name="validate" id="valid" value="validate"/>
              <span class="spinner" id="pluginemail_spinner" style="float:none;"></span>
            <!--  <button type="submit" id="validate" class="button button-primary" title="<?php esc_html_e('Validate the provided API key','shortpixel-image-optimiser');?>" <?php echo $disabled ?>
                  >
                  <?php esc_html_e('Validate','shortpixel-image-optimiser');?>
              </button> -->
      </content>

  </setting>

</settinglist>
</label>



</div> <!-- // Join Wrapper -->



  <div class='submit-errors'>

  </div>
<settinglist class='onboard-submit'>

  <button type="button" name="add-key"><?php esc_html_e('Continue', 'shortpixel-image-optimiser'); ?></button>

</settinglist>



</section>
