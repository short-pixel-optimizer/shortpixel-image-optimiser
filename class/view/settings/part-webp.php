<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


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

  <h2><?php esc_html_e('Next Generation Images','shortpixel-image-optimiser');?></h2>

  <!-- next generation -->
  <setting class='switch'>

    <content>

      <?php $this->printSwitchButton(
            ['name' => 'createWebp',
             'checked' => $view->data->createWebp,
             'label' => esc_html__('Create Webp Images','shortpixel-image-optimiser'),
             'data' => ['data-dashboard="' . __('Recommend adding Webp', 'shortpixel-image-optimiser') . '"'],
            ]);
      ?>
        <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/286-how-to-serve-webp-files-using-spio"></i>

      </content>
      <info>           <?php printf(esc_html__('Create %s WebP versions %s of the images. Each image/thumbnail will use an additional credit unless you use the %s Unlimited plan. %s','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/blog/how-webp-images-can-speed-up-your-site/" target="_blank">', '</a>', '<a href="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work" target="_blank">', '</a>' );?></info>
  </setting>
  <!-- /next generation -->

  <!-- avif -->
  <setting class='switch'>

      <content>
        <?php
          $avifEnabled = $this->access()->isFeatureAvailable('avif');
          $createAvifChecked = ($view->data->createAvif == 1 && $avifEnabled === true) ? 1 : 0;
          $disabled = ($avifEnabled === false) ? true : false;
          $avifEnabledNotice = false;
          if ($avifEnabled == false)
          {
             $avifEnabledNotice = '<div class="sp-notice sp-notice-warning  avifNoticeDisabled">';
             $avifEnabledNotice .=  __('The creation of AVIF files is not possible with this license type.', 'shortpixel-image-optimiser') ;
             $avifEnabledNotice .=  '<div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/555-how-does-the-unlimited-plan-work"></span></div>';
             $avifEnabledNotice .= '</div>';
          }
        ?>
        <?php $this->printSwitchButton(
              ['name' => 'createAvif',
               'checked' => $createAvifChecked,
               'label' => esc_html__('Create Avif Images','shortpixel-image-optimiser'),
               'disabled' => $disabled,
              ]);
        ?>

       <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/467-how-to-create-and-serve-avif-files-using-shortpixel-image-optimizer"></i>


       <?php if(strlen($deliverAVIFLabel)){ ?>
                    <p class="sp-notice sp-notice-warning">
                   <?php echo ( $deliverAVIFLabel );?>
                    </p>
       <?php } ?>
       <?php if ($avifEnabledNotice !== false) {  echo $avifEnabledNotice;  } ?>

      </content>
      <info>
         <?php printf(esc_html__('Create %s AVIF versions %s of the images. Each image/thumbnail will use an additional credit. ','shortpixel-image-optimiser'), '<a href="https://shortpixel.com/blog/what-is-avif-and-why-is-it-good/" target="_blank">', '</a>');?>
      </info>
  </setting>
  <!-- // avif -->


<setting>

  <content>

<!--
    <switch>
      <label>
        <input type="checkbox" class="switch" name="deliverWebp" data-toggle="deliverTypes" value="1" <?php checked( ($view->data->deliverWebp > 0), true);?>>
        <div class="the_switch">&nbsp; </div>
        <?php esc_html_e('Deliver the next generation versions of the images in the front-end:','shortpixel-image-optimiser');?>
      </label>
   </switch>  -->

   <?php $this->printSwitchButton(
         ['name' => 'deliverWebp',
          'checked' =>  ($view->data->deliverWebp > 0) ? 1 : 0,
          'label' => esc_html__('Deliver the next generation versions of the images in the front-end:','shortpixel-image-optimiser'),
          'disabled' => $disabled,
          'data' => ['data-toggle="deliverTypes"', 'data-dashboard="' . __('Modern format not being deliverd', 'shortpixel-image-optimiser') . '"'],
         ]);
   ?>

   <i class='documentation dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/126-which-webp-files-delivery-method-is-the-best-for-me"></i>

      <ul id="deliverTypes" class="deliverWebpTypes ">
          <li>
              <input type="radio" name="deliverWebpType" id="deliverWebpAltered" <?php checked( ($view->data->deliverWebp >= 1 && $view->data->deliverWebp <= 2), true); ?> <?php echo esc_attr( $deliverWebpAlteredDisabled );?> value="deliverWebpAltered" data-toggle="deliverAlteringTypesPicture">
              <label for="deliverWebpAltered">
                  <?php esc_html_e('Using the &lt;PICTURE&gt; tag syntax','shortpixel-image-optimiser');?>
              </label>

              <ul id="deliverAlteringTypes" class="toggleTarget" >
                  <li>
                      <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredWP" <?php checked(($view->data->deliverWebp == 2), true);?> value="deliverWebpAlteredWP">
                      <label for="deliverWebpAlteredWP">
                          <?php esc_html_e('Only via Wordpress hooks (like the_content, the_excerpt, etc)');?>
                      </label>
                  </li>
                  <li>
                      <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredGlobal" <?php checked(($view->data->deliverWebp == 1),true)?>  value="deliverWebpAlteredGlobal" data-toggle="deliverAlteringTypesHtaccess">
                      <label for="deliverWebpAlteredGlobal">
                          <?php esc_html_e('Global (processes the whole output buffer before sending the HTML to the browser)','shortpixel-image-optimiser');?>
                      </label>
                  </li>
              </ul>

              <info>
                   <?php esc_html_e('Each &lt;img&gt; will be replaced with a &lt;picture&gt; tag that will also provide AVIF and WebP images for browsers that support it.  You don\'t need to activate this if you\'re using the Cache Enabler plugin because your AVIF\WebP images are already handled by this plugin. <strong>Please run some tests before using this option!</strong> If the styles that your theme is using rely on the position of your &lt;img&gt; tags, you may experience display problems.','shortpixel-image-optimiser'); ?>
                  <strong><?php esc_html_e('You can revert anytime to the previous state just by deactivating the option.','shortpixel-image-optimiser'); ?></strong>
              </info>

          </li>
          <li>
              <hr>
              <input type="radio" name="deliverWebpType" id="deliverWebpUnaltered" <?php checked(($view->data->deliverWebp == 3), true);?> <?php echo esc_attr( $deliverWebpUnalteredDisabled );?> value="deliverWebpUnaltered" data-toggle="deliverAlteringTypes">

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
    <warning id="deliverAlteringTypesPicture">
       <message>
<?php _e( "Warning: Using this method alters the structure of the rendered HTML code (IMG tags get included in PICTURE tags), which, in some rare \ncases, can lead to CSS/JS inconsistencies.\n\nPlease test this functionality thoroughly after activating!\n\nIf you notice any issue, just deactivate it and the HTML will will revert to the previous state.", 'shortpixel-image-optimiser' ); ?>
        </message>
    </warning>
    <warning id="deliverAlteringTypesHtaccess" >
      <message>
        <?php _e( 'This option will serve both WebP and the original image using the same URL, based on the web browser capabilities, please make sure you\'re serving the images from your server and not using a CDN which caches the images.', 'shortpixel-image-optimiser' ) ?>
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
