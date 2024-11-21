<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

?>
<hr class='wp-header-end'>

<div class="wrap is-shortpixel-settings-page <?php echo esc_attr($this->view_mode); ?> ">

<header>
  <h1>
      <?php echo UIHelper::getIcon('res/images/illustration/logo_settings.svg'); ?>
  </h1>
  <div class='top-buttons'>

    <a class='header-button' href="https://shortpixel.com/<?php
            echo esc_attr(($view->key->apiKey ? "login/". $view->key->apiKey . "/dashboard" : "login"));
        ?>" target="_blank">
          <i class='shortpixel-icon user'></i><name><?php _e('ShortPixel Account','shortpixel-image-optimiser'); ?></name>
        </a>
    <!--<button><i class='shortpixel-icon notifications'></i><?php _e('Notifications','shortpixel-image-optimiser'); ?></button>
    <button id="viewmode-toggle"><i class='shortpixel-icon switch'></i>
      <span class='advanced'><?php _e('Advanced', 'shortpixel-image-optimiser'); ?></span>
      <span class='simple'><?php _e('Simple', 'shortpixel-image-optimiser'); ?></span>
    </button>-->
  </div>
</header>


<?php //$this->loadView('settings/part-header'); ?>

  <input type='checkbox' name='heavy_features' value='1' <?php echo ($this->disable_heavy_features) ? 'checked' : '' ?> class='shortpixel-hide' />

<article class='shortpixel-settings'>
  <?php if ($this->view->data->redirectedSettings < 3 && $view->key->is_verifiedkey)
  {
    $this->loadView('settings/part-quicktour');
  }

  ?>

  <label class='mobile-menu closed'>
    <span class='open'><?php echo UIHelper::getIcon('res/images/icon/accordion.svg'); ?></span>
    <span class='close'><?php echo UIHelper::getIcon('res/images/icon/close.svg'); ?></span>
    <input type='checkbox'></label>
  <menu>
			<ul>
				<li>
          <?php echo $this->settingLink([
              'part' => 'overview',
              'title' =>  __("Overview", "shortpixel-image-optimiser"),
              'icon' => 'shortpixel-icon dashboard',
            ]); ?>
        </li>
				<li>
          <?php echo $this->settingLink([
            'part' => 'optimisation',
            'title' => __("Image Optimization", "shortpixel-image-optimiser"),
            'icon' => 'shortpixel-icon optimization']); ?>
        </li>
        <li class='is-advanced'>
          <?php echo $this->settingLink([
              'part' => 'exclusions',
              'title' => __("Exclusions", "shortpixel-image-optimiser"),
              'icon' => 'shortpixel-icon exclusions']); ?>
        </li>

        <li class='is-advanced'>
          <?php echo $this->settingLink([
            'part' => 'processing',
            'title' => __("Processing", "shortpixel-image-optimiser"),
            'icon' => 'shortpixel-icon processing']); ?>
        </li>
        <li>
					<?php echo $this->settingLink([
            'part' => 'webp',
            'title' => __("WebP/AVIF & CDN", "shortpixel-image-optimiser"),
            'icon' => 'shortpixel-icon webp_avif']); ?>
        </li>

				<li class='is-advanced'>
					<?php echo $this->settingLink([
            'part' => 'integrations',
            'title' => __("Integrations", "shortpixel-image-optimiser"),
            'icon' => 'shortpixel-icon integrations']); ?>
        </li>

				<li class='is-advanced'>
          <?php echo $this->settingLink([
            'part' => 'tools',
            'title' => __("Tools", "shortpixel-image-optimiser"),
            'icon' => 'shortpixel-icon tools']); ?>
        </li>

        <li>
          <?php echo $this->settingLink([
            'part' => 'help',
            'title' => __("Help Center", "shortpixel-image-optimiser"),
            'icon' => 'shortpixel-icon help-circle']); ?>
        </li>

        <?php
          if (Log::debugIsActive())
          { ?>
  			<li>
          <?php echo $this->settingLink([
              'part' => 'debug',
              'title' => __("Debug", "shortpixel-image-optimiser"),
              'icon' => 'shortpixel-icon debug']); ?>
        </li>
        <?php } ?>

			</ul>
			<div class="adv_switcher">
				<?php esc_html_e('Advanced Mode','shortpixel-image-optimiser');?>
                		<label class="adv_switch" id="viewmode-toggles">
                        		<input type="checkbox" <?php echo ('advanced' == $this->view_mode) ? 'checked' : '' ?> >
					<span class="adv_slider"></span>
            		    	</label>
			</div>

<?php if (false == $view->is_unlimited): ?>
          <div class='upgrade-banner'>
              <div class="robo-container">
                  <div class="robo-from-banner"> <?php echo UIHelper::getIcon('res/img/robo-slider.png'); ?></div>
                  <h2><?php _e('Upgrade to ShortPixel Unlimited', 'shortpixel-image-optimiser'); ?> </h2>
              </div>
              <div class="banner-line-container">
                  <span class="shortpixel-icon ok"></span>
                  <p><?php _e('Unlimited credits ', 'shortpixel-image-optimiser'); ?></p>
              </div>
              <div class="banner-line-container">
                  <span class="shortpixel-icon ok"></span>
                  <p><?php _e('Unlimited websites ', 'shortpixel-image-optimiser'); ?></p>
              </div>
              <div class="banner-line-container">
                  <span class="shortpixel-icon ok"></span>
                  <p><?php _e('Unlimited WebP/AVIF ', 'shortpixel-image-optimiser'); ?></p>
              </div>
              <div class="banner-line-container">
                  <span class="shortpixel-icon ok"></span>
                  <p><?php _e('SmartCompress ', 'shortpixel-image-optimiser'); ?></p>
              </div>
              <div class='banner-upgrade-button'>
                  <button type="button" class="button button-primary" id="upgrade" onclick="window.open('https://shortpixel.com/ms/af/KZYK08Q28044', '_blank');">
                      <i class="shortpixel-icon cart"></i>
                      <?php _e('Upgrade Now', 'shortpixel-image-optimizer'); ?>
                  </button>
              </div>

          </div>
<?php endif; ?>



		</menu>
		<section class="wrapper">
      <form name='wp_shortpixel_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>'  method='post' id='wp_shortpixel_options'>

        <input type='hidden' name='display_part' value="<?php echo esc_attr($this->display_part) ?>" />
        <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

          <?php $this->loadView('settings/part-overview'); ?>
          <?php $this->loadView('settings/part-optimisation'); ?>
          <?php $this->loadView('settings/part-processing'); ?>
          <?php $this->loadView('settings/part-webp'); ?>
          <?php $this->loadView('settings/part-integrations'); ?>
          <?php $this->loadView('settings/part-exclusions'); ?>
          <?php $this->loadView('settings/part-help'); ?>

					<?php $this->loadView('settings/part-nokey'); ?>
      </form>
          <?php $this->loadView('settings/part-tools'); ?>
          <?php
            if (Log::debugIsActive())
            {
              $this->loadView('settings/part-debug');
            }
            ?>
		</section>
</article>


    <section class='ajax-save-done'>
        <div class="icon-container">
            <span class="shortpixel-icon ok" aria-hidden="true"></span>
        </div>
        <div class="text-container">
            <h2><?php _e('Settings successfully saved! ', 'shortpixel-image-optimiser'); ?></h2>
            <h3 class='after-save-notices'><span class='notice_count'>X</span> <?php _e('new notices', 'shortpixel-image-optimiser'); ?></h3>
        </div>
    </section>

<article id="shortpixel-settings-tabs" class="sp-tabs">
    <?php if (! $view->key->is_verifiedkey)
    {
    } ?>

  <?php
    if ($view->key->is_verifiedkey):
      ?>
      <div class='section-wrapper'>
				<form name='wp_shortpixel_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>'  method='post' id='wp_shortpixel_options'>
	        <input type='hidden' name='display_part' value="<?php echo esc_attr($this->display_part) ?>" />
	        <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

        <?php
        if (! $this->view->cloudflare_constant) // @todo
        {
          //$this->loadView('settings/part-cloudflare');
        }


        ?>
			</form>

			</div> <!-- wrappur -->
      <?php
    endif;
    ?>

</article>
<?php $this->loadView('settings/part-wso'); ?>

<?php //$this->loadView('snippets/part-inline-help'); ?>
<?php $this->loadView('snippets/part-inline-modal'); ?>
