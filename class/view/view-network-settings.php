<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$createAvifEnabled = $this->access()->isFeatureAvailable('avif');
$deliverWebpType = ($view->data->deliverWebp >= 1 && $view->data->deliverWebp <= 2) ? 'deliverWebpAltered' : 'deliverWebpUnaltered';
$deliverWebpAlteringType = ($view->data->deliverWebp == 2) ? 'deliverWebpAlteredWP' : 'deliverWebpAlteredGlobal';
?>

<div class="wrap is-shortpixel-settings-page multi-site-settings">
  <h1>
      <img src="<?php echo esc_url(\wpSPIO()->plugin_url('res/img/svg/sp-logo-regular.svg')) ?>" width="50" />
      <?php esc_html_e('ShortPixel Network Settings','shortpixel-image-optimiser'); ?>
  </h1>

  <hr class='wp-header-end'>

  <article class="shortpixel-settings">
    <div class='section-wrapper'>
      <form name='wp_shortpixel_network_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>' method='post' id='wp_shortpixel_network_options'>
        <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

        <section id="network-settings" class="setting-tab active" data-part="network">
          <h2><?php esc_html_e('Network-wide settings', 'shortpixel-image-optimiser'); ?></h2>

          <settinglist>
            <setting class='switch'>
              <content>
                <?php $this->printSwitchButton([
                      'name' => 'disable_site_settings_page',
                      'checked' => $view->data->disable_site_settings_page,
                      'label' => esc_html__('Disable the ShortPixel settings page for individual sites', 'shortpixel-image-optimiser'),
                    ]);
                ?>
              </content>
              <info>
                <?php esc_html_e('Hide ShortPixel admin settings from regular site dashboards and manage those options only from the network admin screen.', 'shortpixel-image-optimiser'); ?>
              </info>
            </setting>
          </settinglist>

          <h2><?php esc_html_e('Image delivery options', 'shortpixel-image-optimiser'); ?></h2>

          <settinglist>
            <setting class='switch'>
              <content>
                <?php $this->printSwitchButton([
                      'name' => 'createWebp',
                      'checked' => $view->data->createWebp,
                      'label' => esc_html__('Generate WebP images across the network', 'shortpixel-image-optimiser'),
                    ]);
                ?>
              </content>
              <info><?php esc_html_e('Enable WebP generation for all sites managed by this network.', 'shortpixel-image-optimiser'); ?></info>
            </setting>

            <setting class='switch'>
              <content>
                <?php $this->printSwitchButton([
                      'name' => 'createAvif',
                      'checked' => ($view->data->createAvif == 1 && $createAvifEnabled),
                      'label' => esc_html__('Generate AVIF images across the network', 'shortpixel-image-optimiser'),
                      'disabled' => ! $createAvifEnabled,
                    ]);
                ?>
              </content>
              <info>
                <?php if ($createAvifEnabled): ?>
                  <?php esc_html_e('Enable AVIF generation for all network sites.', 'shortpixel-image-optimiser'); ?>
                <?php else: ?>
                  <?php esc_html_e('AVIF is not available for your current license.', 'shortpixel-image-optimiser'); ?>
                <?php endif; ?>
              </info>
            </setting>

            <setting class='switch'>
              <content>
                <?php $this->printSwitchButton([
                      'name' => 'useCDN',
                      'checked' => ($view->data->useCDN > 0),
                      'label' => esc_html__('Deliver images using the ShortPixel CDN', 'shortpixel-image-optimiser'),
                    ]);
                ?>
              </content>
              <info><?php esc_html_e('Serve next-generation images via ShortPixel CDN instead of local delivery.', 'shortpixel-image-optimiser'); ?></info>
            </setting>

            <setting class='switch'>
              <content>
                <?php $this->printSwitchButton([
                      'name' => 'deliverWebp',
                      'checked' => ($view->data->deliverWebp > 0),
                      'label' => esc_html__('Serve WebP/AVIF images locally', 'shortpixel-image-optimiser'),
                    ]);
                ?>
              </content>
              <info><?php esc_html_e('Use local WebP/AVIF delivery on each site without the CDN.', 'shortpixel-image-optimiser'); ?></info>
            </setting>

            <ul class="deliverWebpTypes toggleTarget" id="deliverTypes">
              <li>
                <input type="radio" name="deliverWebpType" id="deliverWebpAltered" <?php checked($deliverWebpType, 'deliverWebpAltered'); ?> value="deliverWebpAltered" data-toggle="deliverAlteringTypes">
                <label for="deliverWebpAltered"><?php esc_html_e('Deliver using the <picture> tag', 'shortpixel-image-optimiser'); ?></label>

                <ul class="deliverWebpAlteringTypes toggleTarget" id="deliverAlteringTypes">
                  <li>
                    <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredWP" <?php checked($deliverWebpAlteringType, 'deliverWebpAlteredWP'); ?> value="deliverWebpAlteredWP">
                    <label for="deliverWebpAlteredWP"><?php esc_html_e('WordPress hooks only', 'shortpixel-image-optimiser'); ?></label>
                  </li>
                  <li>
                    <input type="radio" name="deliverWebpAlteringType" id="deliverWebpAlteredGlobal" <?php checked($deliverWebpAlteringType, 'deliverWebpAlteredGlobal'); ?> value="deliverWebpAlteredGlobal">
                    <label for="deliverWebpAlteredGlobal"><?php esc_html_e('Global output buffering', 'shortpixel-image-optimiser'); ?></label>
                  </li>
                </ul>
              </li>
              <li>
                <input type="radio" name="deliverWebpType" id="deliverWebpUnaltered" <?php checked($deliverWebpType, 'deliverWebpUnaltered'); ?> value="deliverWebpUnaltered">
                <label for="deliverWebpUnaltered"><?php esc_html_e('Use server rules (.htaccess / nginx) where possible', 'shortpixel-image-optimiser'); ?></label>
              </li>
            </ul>
          </settinglist>

          <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save Network Settings', 'shortpixel-image-optimiser'); ?></button>
          </p>
        </section>
      </form>
    </div>
  </article>
</div>

