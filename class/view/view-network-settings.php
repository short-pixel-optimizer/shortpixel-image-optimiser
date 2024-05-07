<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


$delivery_settings = $this->view->settings['delivery'];


?>

<div class="wrap is-shortpixel-settings-page multi-site-settings">
<h1>
    <img src="<?php echo esc_url(\wpSPIO()->plugin_url('res/img/svg/sp-logo-regular.svg')) ?>" width="50" />
    <?php esc_html_e('ShortPixel Network Settings','shortpixel-image-optimiser');?>
</h1>

<hr class='wp-header-end'>

<article id="shortpixel-settings-tabs" class="sp-tabs">
  <div class='section-wrapper'>
    <form name='wp_shortpixel_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>'  method='post' id='wp_shortpixel_options'>
      <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>
      <section id="multi-site" class="clearfix sel-tab">
        <h2>&nbsp;</h2>

        <h4>Deliver the next generation versions of the images in the front-end: </h4>
        <hr>


        <div class='option'>
          <?php $this->printInlineHelp("https://shortpixel.com/knowledge-base/article/126-which-webp-files-delivery-method-is-the-best-for-me");
          ?>

          <?php $this->printSwitchButton(
                ['name' => 'delivery_enable',
                 'checked' => $delivery_settings['delivery_enable'],
                 'label' => esc_html__('Enable site-wide settings','shortpixel-image-optimiser')
                ]);
          ?>

        </div>


        <div class='delivery-options-wrapper flex option'>

        </div>


            <ul class="deliverWebpTypes toggleTarget" id="deliverTypes">
                <li>
                    <input type="radio" name="deliverWebpType" id="deliverWebpAltered" <?php checked( ($delivery_settings['deliver_picture'] == true), true); ?> <?php echo esc_attr( $deliverWebpAlteredDisabled );?> value="deliverWebpAltered" data-toggle="deliverAlteringTypes">
                    <label for="deliverWebpAltered">
                        <?php esc_html_e('Using the &lt;PICTURE&gt; tag syntax','shortpixel-image-optimiser');?>
                    </label>

                    <?php if($deliverWebpAlteredDisabledNotice){ ?>
                        <p class="sp-notice">
                            <?php esc_html_e('After the option to work on .htaccess was selected, the .htaccess file has become unaccessible / read-only. Please make the .htaccess file writeable again to be able to further set this option up.','shortpixel-image-optimiser')?>
                        </p>
                    <?php } ?>

                    <p class="settings-info">
                         <?php esc_html_e('Each &lt;img&gt; will be replaced with a &lt;picture&gt; tag that will also provide AVIF and WebP images for browsers that support it.  You don\'t need to activate this if you\'re using the Cache Enabler plugin because your AVIF\WebP images are already handled by this plugin. <strong>Please run some tests before using this option!</strong> If the styles that your theme is using rely on the position of your &lt;img&gt; tags, you may experience display problems.','shortpixel-image-optimiser'); ?>
                        <strong><?php esc_html_e('You can revert anytime to the previous state just by deactivating the option.','shortpixel-image-optimiser'); ?></strong>
                    </p>

                    <ul class="deliverWebpAlteringTypes toggleTarget" id="deliverAlteringTypes">
                        <li>

                            <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredWP" <?php checked( ($delivery_settings['deliver_picture'] == true && $delivery_settings['delivery_method'] == 'hooks'), true);?> value="deliverWebpAlteredWP">
                            <label for="deliverWebpAlteredWP">
                                <?php esc_html_e('Only via Wordpress hooks (like the_content, the_excerpt, etc)');?>
                            </label>
                        </li>
                        <li>
                            <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredGlobal" <?php checked(($delivery_settings['deliver_picture'] == true && $delivery_settings['delivery_method'] == 'global'),true)?>  value="deliverWebpAlteredGlobal">
                            <label for="deliverWebpAlteredGlobal">
                                <?php esc_html_e('Global (processes the whole output buffer before sending the HTML to the browser)','shortpixel-image-optimiser');?>
                            </label>
                        </li>
                    </ul>
                </li>
                <li>
                    <input type="radio" name="deliverWebpType" id="deliverWebpUnaltered" <?php checked($delivery_settings['deliver_htaccess'], true);?> <?php echo esc_attr( $deliverWebpUnalteredDisabled );?> value="deliverWebpUnaltered" data-toggle="deliverAlteringTypes" data-toggle-reverse>

                    <label for="deliverWebpUnaltered">
                        <?php esc_html_e('Without altering the page code (via .htaccess)','shortpixel-image-optimiser')?>
                    </label>
                    <?php if(strlen($deliverWebpUnalteredLabel)){ ?>
                        <p class="sp-notice sp-notice-warning"><strong>
                            <?php echo( $deliverWebpUnalteredLabel );?>
                          </strong>
                        </p>
                    <?php } ?>
                </li>
            </ul>

      </section> <!-- //tab -->

    </form>
  </div> <!-- /section-wrapper -->


</article>


</div> <!--- // settings -->
