<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<div class="wrap is-shortpixel-settings-page <?php echo esc_attr($this->view_mode); ?> ">
<header>
  <h1>
      <?php echo UIHelper::getIcon('res/images/illustration/logo_settings.svg'); ?>
  </h1>

  <div class='top-buttons'>
    <button><i class='shortpixel-icon notifications'></i><?php _e('Notifications','shortpixel-image-optimiser'); ?></button>
    <button id="viewmode-toggle"><i class='shortpixel-icon switch'></i>
      <span class='advanced'><?php _e('Advanced', 'shortpixel-image-optimiser'); ?></span>
      <span class='simple'><?php _e('Simple', 'shortpixel-image-optimiser'); ?></span>
    </button>
  </div>
</header>

<?php $this->loadView('settings/part-header'); ?>

  <input type='checkbox' name='heavy_features' value='1' <?php echo ($this->disable_heavy_features) ? 'checked' : '' ?> class='shortpixel-hide' />


<hr class='wp-header-end'>

<form name='wp_shortpixel_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>'  method='post' id='wp_shortpixel_options'>

  <input type='hidden' name='display_part' value="<?php echo esc_attr($this->display_part) ?>" />
  <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>


<article class='shortpixel-settings'>
		<menu>
			<ul>
				<li>
          <?php echo $this->settingLink('overview', __("Overview", "shortpixel-image-optimiser"), 'shortpixel-icon dashboard'); ?>
        </li>
				<li>
          <?php echo $this->settingLink('optimisation', __("Image optimisation", "shortpixel-image-optimiser"), 'shortpixel-icon optimization'); ?>
        </li>
        <li class='is-advanced'>
          <?php echo $this->settingLink('exclusions', __("Exclusions", "shortpixel-image-optimiser"), 'shortpixel-icon exclude'); ?>
        </li>

        <li class='is-advanced'>
          <?php echo $this->settingLink('processing', __("Processing", "shortpixel-image-optimiser"), 'shortpixel-icon processing'); ?>
        </li>
        <li>
					<?php echo $this->settingLink('webp', __("Webp/Avif & CDN", "shortpixel-image-optimiser"), 'shortpixel-icon webp_avif'); ?>
        </li>


				<li class='is-advanced'>
					<?php echo $this->settingLink('cdn', __("Integrations", "shortpixel-image-optimiser"), 'shortpixel-icon cdn'); ?>
        </li>

				<li class='is-advanced'>
          <?php echo $this->settingLink('tools', __("Tools", "shortpixel-image-optimiser") , 'shortpixel-icon tools'); ?>
        </li>

        <li>
          <?php echo $this->settingLink('help', __("Help Center", "shortpixel-image-optimiser"), 'shortpixel-icon help-circle'); ?>
        </li>

        <?php
          if (Log::debugIsActive())
          { ?>
  			<li>
          <?php echo $this->settingLink('debug', __("Debug", "shortpixel-image-optimiser"), 'shortpixel-icon debug'); ?>
        </li>
        <?php } ?>

			</ul>
		</menu>
		<section class="wrapper">

          <?php $this->loadView('settings/part-overview'); ?>
          <?php $this->loadView('settings/part-optimisation'); ?>
          <?php $this->loadView('settings/part-processing'); ?>
          <?php $this->loadView('settings/part-webp'); ?>
          <?php $this->loadView('settings/part-cdn'); ?>
          <?php $this->loadView('settings/part-exclusions'); ?>
          <?php $this->loadView('settings/part-help'); ?>

					<?php $this->loadView('settings/part-nokey'); ?>
          <?php $this->loadView('settings/part-tools'); ?>
          <?php
            if (Log::debugIsActive())
            {
              $this->loadView('settings/part-debug');
            }
            ?>

		</section>
</article>
</form>

<section class='ajax-save-done'>
	<h1>
		<?php echo UIHelper::getIcon('res/img/robo-cool.png'); ?>
		<?php _e('Settings Saved', 'shortpixel-image-optimiser'); ?> </h1>
	<h3> <span class='notice_count'>X</span> new notices </h3>
</section>

<div class='debug'><PRE>
  <?php print_r($this->view->data); ?>
</PRE></div>

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
