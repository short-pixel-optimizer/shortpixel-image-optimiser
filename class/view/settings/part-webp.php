<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use \ShortPixel\Helper\UiHelper as UiHelper;

?>

<?php
// @todo This mess should be unmessed
$deliverWebpAlteredDisabled = '';
$deliverWebpUnalteredDisabled = '';
$deliverWebpAlteredDisabledNotice = false;
$deliverWebpUnalteredLabel ='';
$deliverAVIFLabel ='';

if( $this->is_nginx ){
    $deliverWebpUnaltered = '';                         // Uncheck
    $deliverWebpUnalteredDisabled = 'disabled';         // Disable
    $deliverWebpUnalteredLabel = __('It looks like you\'re running your site on an NGINX server. This means that you can only achieve this functionality by directly configuring the server config files. Please follow this link for instructions:','shortpixel-image-optimiser')." <a class=\"shortpixel-help-link\" href=\"https://shortpixel.com/knowledge-base/article/111-configure-nginx-to-transparently-serve-webp-files-when-supported\" target=\"_blank\" data-beacon-article=\"5bfeb9de2c7d3a31944e78ee\"><span class=\"dashicons dashicons-editor-help\"></span></a>";
    $deliverAVIFLabel = __('<strong>It looks like you\'re running your site on an NGINX server. You may need additional configuration for the AVIF delivery to work as expected</strong>','shortpixel-image-optimiser')." <a class=\"shortpixel-help-link\" href=\"https://shortpixel.com/knowledge-base/article/499-how-do-i-configure-my-web-server-to-deliver-avif-images\" target=\"_blank\"><span class=\"dashicons dashicons-editor-help\"></span></a>";
} else {
    if( !$this->is_htaccess_writable ){
        $deliverWebpUnalteredDisabled = 'disabled';     // Disable
        if( $view->data->deliverWebp == 3 ){
            $deliverWebpAlteredDisabled = 'disabled';   // Disable
            $deliverWebpUnalteredLabel = __('It looks like you recently moved from an Apache server to an NGINX server, while the option to use .htacces was in use. Please follow this tutorial to see how you could implement by yourself this functionality, outside of the WP plugin: ','shortpixel-image-optimiser') . '<a href="https://shortpixel.com/knowledge-base/article/111-configure-nginx-to-transparently-serve-webp-files-when-supported" target="_blank" data-beacon-article="5bfeb9de2c7d3a31944e78ee"></a>';
        } else {
            $deliverWebpUnalteredLabel = __('It looks like your .htaccess file cannot be written. Please fix this and then return to refresh this page to enable this option.','shortpixel-image-optimiser');
        }
    } elseif (isset($_SERVER['HTTP_USER_AGENT']) && strpos( wp_unslash($_SERVER['HTTP_USER_AGENT']), 'Chrome') !== false) {
        // Show a message about the risks and caveats of serving WEBP images via .htaccess
        $deliverWebpUnalteredLabel = '<span style="color: initial;">'. esc_html__('Based on testing your particular hosting configuration, we determined that your server','shortpixel-image-optimiser').
            '&nbsp;<img alt="can or can not" src="'. esc_url(plugins_url( 'res/img/test.jpg' , SHORTPIXEL_PLUGIN_FILE)) .'">&nbsp;'.
            esc_html__('serve the WebP or AVIF versions of the JPEG files seamlessly, via .htaccess.','shortpixel-image-optimiser').' <a href="https://shortpixel.com/knowledge-base/article/127-delivering-webp-images-via-htaccess" target="_blank" data-beacon-article="5c1d050e04286304a71d9ce4">Open article to read more about this.</a></span>';
    }
}

?>

<section id="tab-webp" class="<?php echo ($this->display_part == 'webp') ? 'active setting-tab' :'setting-tab'; ?>" data-part="webp" >

<settinglist>

  <h2><?php esc_html_e('Deliver Next Generation Images & CDN','shortpixel-image-optimiser');?></h2>

  <!-- next generation -->
  <setting class='switch step-highlight-3'>

    <content>

      <?php $this->printSwitchButton(
            ['name' => 'createWebp',
             'checked' => $view->data->createWebp,
             'label' => esc_html__('Create WebP Images','shortpixel-image-optimiser'),
             'data' => ['data-dashboard="' . __('WebP or AVIF files are not generated', 'shortpixel-image-optimiser') . '"'],
            ]);
      ?>
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/286-how-to-serve-webp-files-using-spio?target=iframe"></i>

      </content>
      <info>           <?php printf(esc_html__('Generate %sWebP versions%s of images. Each image or thumbnail will use an additional credit unless you are on the %sUnlimited plan.%s','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/blog/how-webp-images-can-speed-up-your-site/" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work" target="_blank">', '</a>' );?></info>
  </setting>
  <!-- /next generation -->

  <!-- avif -->
  <setting class='switch step-highlight-3'>

      <content>
        <?php
          $avifEnabled = $this->access()->isFeatureAvailable('avif');
          $createAvifChecked = ($view->data->createAvif == 1 && $avifEnabled === true) ? 1 : 0;
          $disabled = ($avifEnabled === false) ? true : false;
          $avifEnabledNotice = false;
          if ($avifEnabled == false)
          {
             $avifEnabledNotice = '<div class="sp-notice sp-notice-warning avifNoticeDisabled">';
             $avifEnabledNotice .=  __('The creation of AVIF files is not possible with this license type.', 'shortpixel-image-optimiser') ;
             $avifEnabledNotice .=  '<div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work"></span></div>';
             $avifEnabledNotice .= '</div>';
          }
        ?>
        <?php $this->printSwitchButton(
              ['name' => 'createAvif',
               'checked' => $createAvifChecked,
               'label' => esc_html__('Create AVIF Images','shortpixel-image-optimiser'),
               'disabled' => $disabled,
               'data' => ['data-dashboard="' . __('WebP or AVIF files are not generated', 'shortpixel-image-optimiser') . '"'],
              ]);
        ?>

       <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/467-how-to-create-and-serve-avif-files-using-shortpixel-image-optimizer?target=iframe"></i>


       <?php if(strlen($deliverAVIFLabel)){ ?>
                    <p class="sp-notice sp-notice-warning">
                   <?php echo ( $deliverAVIFLabel );?>
                    </p>
       <?php } ?>
       <?php if ($avifEnabledNotice !== false) {  echo $avifEnabledNotice;  } ?>

      </content>
      <info>
         <?php printf(esc_html__('Generate %sAVIF versions%s of images. Each image or thumbnail will use an additional credit unless you are on the %sUnlimited plan.%s','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/blog/what-is-avif-and-why-is-it-good/" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work" target="_blank">', '</a>' );?>
      </info>
  </setting>
  <!-- // avif -->


<?php

  if (true === apply_filters('shortpixel/settings/allow_cdn', true)): ?>
    <setting class='switch step-highlight-3'>
      <content>
    <?php $this->printSwitchButton(
          ['name' => 'useCDN',
           'checked' =>  ($view->data->useCDN > 0) ? 1 : 0,
           'label' => esc_html__('Deliver the next generation images using the ShortPixel CDN:','shortpixel-image-optimiser'),

           'data' => ['data-toggle="useCDN"', 'data-exclude="deliverWebp"', 'data-dashboard="' . __('Next generation images are not delivered', 'shortpixel-image-optimiser') . '"', ],
          ]);
    ?>

    <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/deliver-webp-avif-images-using-the-shortpixel-cdn-in-spio/?target=iframe"></i>

    </content>
    <?php echo UiHelper::getIcon('res/images/icon/new.svg'); ?>
    <info>
           <?php printf(esc_html__('When enabled, the plugin replaces images with CDN URLs and delivers next-generation formats (e.g. WebP, AVIF, if enabled above). Otherwise, images are served locally, as usual. You must %sassociate your domain%s to your ShortPixel account for this delivery method to work. %sRead more%s.','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/associated-domains" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/deliver-webp-avif-images-using-the-shortpixel-cdn-in-spio/" target="_blank">', '</a>' );?>
    </info>

    <?php
    $cdnDomain = $view->data->CDNDomain;
    // in 6.0 original release, the other domain was used. This was changed. At some point this can be removed.
    if ('https://cdn.shortpixel.ai/spio' == $cdnDomain)
    {
       $cdnDomain = 'https://spcdn.shortpixel.ai/spio';
    }
    ?>

    <name class='useCDN toggleTarget'><?php esc_html_e('CDN Domain', 'shortpixel-image-optimiser'); ?></name>
    <content class='useCDN toggleTarget'>
        <input type="text" name="CDNDomain" class='regular-text' value="<?php echo esc_attr($cdnDomain) ?>" >
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/how-to-serve-the-images-from-a-custom-domain/?target=iframe"></i>
    </content>
    <info class='useCDN toggleTarget'>
           <?php printf(esc_html__('Change this only if you want to set up your %scustom domain%s.  ShortPixel CDN: %s','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/knowledge-base/article/how-to-serve-the-images-from-a-custom-domain/" target="_blank">', '</a>', 'https://spcdn.shortpixel.ai/spio');?>
    </info>
  </setting>

<?php
// sadly this field need to be present, because of field checks
else:

 $this->printSwitchButton(
        ['name' => 'useCDN',
         'checked' =>  ($view->data->useCDN > 0) ? 1 : 0,
         'label' => esc_html__('Deliver the next generation images using the ShortPixel CDN:','shortpixel-image-optimiser'),

         'data' => ['data-toggle="useCDN"', 'data-exclude="deliverWebp"', 'data-dashboard="' . __('Next generation images are not delivered', 'shortpixel-image-optimiser') . '"', ],
         'disabled' => true,
         'switch_class' => 'hidden',
        ]);

   ?>

<?php endif; ?>



<setting class='switch step-highlight-3'>
  <content>

   <?php $this->printSwitchButton(
         ['name' => 'deliverWebp',
          'checked' =>  ($view->data->deliverWebp > 0) ? 1 : 0,
          'label' => esc_html__('Serve WebP/AVIF images from locally hosted files (without using a CDN):','shortpixel-image-optimiser'),
          'disabled' => $disabled,
          'data' => ['data-toggle="deliverTypes"', 'data-dashboard="' . __('Next generation images are not delivered', 'shortpixel-image-optimiser') . '"', 'data-exclude="useCDN" data-hidewarnings'],
         ]);
   ?>

   <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/126-which-webp-files-delivery-method-is-the-best-for-me?target=iframe"></i>

   <info>
         <?php printf(esc_html__('Local delivery skips the CDN and serves next-generation files directly from your website using either the PICTURE tag method or .htaccess/nginx rules. %sRead more%s.','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/knowledge-base/article/which-webp-files-delivery-method-is-the-best-for-me/" target="_blank">', '</a>' );?>
   </info>
      <ul  class="deliverTypes deliverWebpTypes toggleTarget">
          <li>
              <input type="radio" name="deliverWebpType" id="deliverWebpAltered" <?php checked( ($view->data->deliverWebp >= 1 && $view->data->deliverWebp <= 2), true); ?> <?php echo esc_attr( $deliverWebpAlteredDisabled );?> value="deliverWebpAltered" data-toggle="deliverAlteringTypesPicture">

            <label for="deliverWebpAltered">
                  <?php esc_html_e('Using the &lt;PICTURE&gt; tag syntax','shortpixel-image-optimiser');?>
              </label>

              <ul class="toggleTarget deliverAlteringTypesPicture picture-option-list" >
                  <li>
                      <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredWP" <?php checked(($view->data->deliverWebp == 2), true);?> value="deliverWebpAlteredWP">
                      <label for="deliverWebpAlteredWP" >
                          <?php esc_html_e('Only via Wordpress hooks (like the_content, the_excerpt, etc)');?>
                      </label>
                  </li>
                  <li>
                      <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredGlobal" <?php checked(($view->data->deliverWebp == 1),true)?>  value="deliverWebpAlteredGlobal" >
                      <label for="deliverWebpAlteredGlobal">
                          <?php esc_html_e('Global (processes the whole output buffer before sending the HTML to the browser)','shortpixel-image-optimiser');?>
                      </label>
                  </li>
              </ul>

              <info>
                   <?php printf(esc_html__('Each &lt;img&gt; tag will be replaced with a &lt;picture&gt; tag, providing AVIF and WebP versions for browsers that support them. You don\'t need to enable this option if you\'re using the Cache Enabler plugin, as it already handles AVIF and WebP images. %sPlease test thoroughly before enabling this option!%s If your theme\'s styles depend on the position of your &lt;img&gt; tags, display issues may occur.','shortpixel-image-optimiser'), '<strong>', '</strong>'); ?>
                  <strong><?php esc_html_e('You can revert to the original state at any time by simply deactivating the option and flushing your WordPress cache.','shortpixel-image-optimiser'); ?></strong>
              </info>

          </li>
          <li>
              <hr>
              <input type="radio" name="deliverWebpType" id="deliverWebpUnaltered" <?php checked(($view->data->deliverWebp == 3), true);?> <?php echo esc_attr( $deliverWebpUnalteredDisabled );?> value="deliverWebpUnaltered" data-toggle="deliverAlteringTypesHtaccess">

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
    </content>
    <warning id="deliverAlteringTypesPicture" class="deliverAlteringTypesPicture">
       <message>
<?php _e( "Warning: Enabling this method changes the structure of the rendered HTML by wrapping &lt;img&gt; tags inside &lt;picture&gt; tags. In rare cases, this may lead to CSS or JavaScript inconsistencies.\n\nPlease test thoroughly after activating!\n\nIf you notice any issues, simply deactivat the option, flush any cache that may be active and the HTML will will revert to its original state.", 'shortpixel-image-optimiser' ); ?>
        </message>
    </warning>
    <warning  class="deliverAlteringTypesHtaccess" >
      <message>
        <?php _e( 'This option will serve both WebP/AVIF and the original image from the same URL, depending on the web browser\'s capabilities. Make sure the images are served directly from your server, not through a CDN that may cache them. If you make any changes, remember to flush your cache to ensure the updates are properly applied.', 'shortpixel-image-optimiser' ) ?>
      </message>
    </warning>

    <?php if($deliverWebpAlteredDisabledNotice): ?>
    <warning class='is-visible'>
        <message>
            <?php esc_html_e('After the option to work on .htaccess was selected, the .htaccess file has become unaccessible / read-only. Please make the .htaccess file writeable again to be able to further set this option up.','shortpixel-image-optimiser')?>
        </message>
    </warning>
  <?php endif; ?>
  </setting>

</settinglist>

  <?php $this->loadView('settings/part-savebuttons', false); ?>
</section>
