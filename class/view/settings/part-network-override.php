<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<section id="tab-network" class="<?php echo ($this->display_part == 'network') ? 'active setting-tab' : 'setting-tab'; ?>" data-part="network">
  <settinglist>
    <h2><?php esc_html_e('Network-wide control', 'shortpixel-image-optimiser'); ?></h2>

    <setting class='switch'>
      <content>
        <?php $this->printSwitchButton([
          'name' => 'network_settings_override_enabled',
          'checked' => (bool) $view->network_settings_enabled,
          'label' => esc_html__('Use network-wide settings for this site', 'shortpixel-image-optimiser'),
        ]); ?>
      </content>
      <info>
        <?php esc_html_e('When enabled, the network admin settings override the per-site values for the requested tabs and the site-level settings page is hidden.', 'shortpixel-image-optimiser'); ?>
      </info>
    </setting>

    <!--
    <setting class='switch'>
      <content>
        <?php $this->printSwitchButton([
          'name' => 'disable_site_settings_page',
          'checked' => (bool) $view->data->disable_site_settings_page,
          'label' => esc_html__('Hide the ShortPixel site settings page', 'shortpixel-image-optimiser'),
        ]); ?>
      </content>
      <info>
        <?php esc_html_e('Hide ShortPixel admin settings from regular site dashboards and manage those options only from the network admin screen.', 'shortpixel-image-optimiser'); ?>
      </info>
    </setting> 
  </settinglist>
-->

    <?php $this->loadView('settings/part-savebuttons', false); ?>
</section>
