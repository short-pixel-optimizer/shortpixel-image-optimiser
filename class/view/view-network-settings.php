<?php
namespace ShortPixel;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<hr class='wp-header-end'>

<div class="wrap is-shortpixel-settings-page">
  <header>
    <h1>
      <?php echo UIHelper::getIcon('res/images/illustration/logo_settings.svg'); ?>
    </h1>
  </header>

  <article class='shortpixel-settings'>
    <label class='mobile-menu closed'>
      <span class='open'><?php echo UIHelper::getIcon('res/images/icon/accordion.svg'); ?></span>
      <span class='close'><?php echo UIHelper::getIcon('res/images/icon/close.svg'); ?></span>
      <input type='checkbox'>
    </label>

    <menu>
      <ul>
        <li>
          <?php echo $this->settingLink([
            'part' => 'network',
            'title' => __('Network Control', 'shortpixel-image-optimiser'),
            'icon' => 'shortpixel-icon dashboard',
          ]); ?>
        </li>
        <li>
          <?php echo $this->settingLink([
            'part' => 'optimisation',
            'title' => __('Image Optimization', 'shortpixel-image-optimiser'),
            'icon' => 'shortpixel-icon optimization',
          ]); ?>
        </li>
        <li>
          <?php echo $this->settingLink([
            'part' => 'processing',
            'title' => __('Processing', 'shortpixel-image-optimiser'),
            'icon' => 'shortpixel-icon processing',
          ]); ?>
        </li>
        <li>
          <?php echo $this->settingLink([
            'part' => 'webp',
            'title' => __('WebP/AVIF & CDN', 'shortpixel-image-optimiser'),
            'icon' => 'shortpixel-icon webp_avif',
          ]); ?>
        </li>
        <li>
          <?php echo $this->settingLink([
            'part' => 'ai',
            'title' => __('AI Image SEO', 'shortpixel-image-optimiser'),
            'icon' => 'shortpixel-icon ai',
          ]); ?>
        </li>
      </ul>
    </menu>

    <section class="wrapper">
      <form name='wp_shortpixel_network_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>' method='post' id='wp_shortpixel_network_options'>
        <input type='hidden' name='display_part' value="<?php echo esc_attr($this->display_part); ?>" />
        <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

        <?php $this->loadView('settings/part-network-override'); ?>
        <?php $this->loadView('settings/part-optimisation'); ?>
        <?php $this->loadView('settings/part-processing'); ?>
        <?php $this->loadView('settings/part-webp'); ?>
        <?php $this->loadView('settings/part-ai'); ?>
      </form>
    </section>
  </article>
</div>

