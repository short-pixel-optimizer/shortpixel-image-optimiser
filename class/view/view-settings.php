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
      <?php //esc_html_e('ShortPixel Plugin Settings','shortpixel-image-optimiser');?>
  </h1>

  <div class='top-buttons'>
    <button><i class='shortpixel-icon notifications'></i><?php _e('Notifications','shortpixel-image-optimiser'); ?></button>
    <button id="viewmode-toggle"><i class='shortpixel-icon switch'></i><?php _e('Advanced', 'shortpixel-image-optimiser'); ?></button>
  </div>
</header>

<?php $this->loadView('settings/part-header'); ?>


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
          <?php echo $this->settingLink('optimisation', __("Image optimisation", "shortpixel-image-optimiser"), 'shortpixel-icon image_optimization'); ?>
        </li>
        <li>
          <?php echo $this->settingLink('webp', __("Webp/Avif", "shortpixel-image-optimiser"), 'shortpixel-icon webp_avif'); ?>
        </li>
				<li>
          <?php echo $this->settingLink('delivery', __("Delivery", "shortpixel-image-optimiser"), 'shortpixel-icon delivery'); ?>
        </li>
				<li>
          <?php echo $this->settingLink('cdn', __("CDN", "shortpixel-image-optimiser"), 'shortpixel-icon cdn'); ?>
        </li>

				<li class='is-advanced'>
          <?php echo $this->settingLink('tools', __("Tools", "shortpixel-image-optimiser")); ?>
        </li>

        <li>
          <?php echo $this->settingLink('knowledge', __("Knowledgebase / Help", "shortpixel-image-optimiser"), 'shortpixel-icon help'); ?>
        </li>

        <li>
          <?php echo $this->settingLink('feedback', __("Feedback", "shortpixel-image-optimiser"), 'shortpixel-icon feedback'); ?>
        </li>

        <?php
          if (Log::debugIsActive())
          { ?>
  			<li>
          <?php echo $this->settingLink('debug', __("Debug", "shortpixel-image-optimiser")); ?>
        </li>
        <?php } ?>

			</ul>
		</menu>
		<section class="wrapper">
          <?php $this->loadView('settings/part-overview'); ?>
          <?php $this->loadView('settings/part-general'); ?>
          <?php $this->loadView('settings/part-optimisation'); ?>

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

<div class='debug'><PRE>
  <?php print_r($this->view->data); ?>
</PRE></div>

<article id="shortpixel-settings-tabs" class="sp-tabs">
    <?php if (! $view->key->is_verifiedkey)
    {
      $this->loadView('settings/part-nokey');
    } ?>

  <?php
    if ($view->key->is_verifiedkey):
      ?>
      <div class='section-wrapper'>
				<form name='wp_shortpixel_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>'  method='post' id='wp_shortpixel_options'>
	        <input type='hidden' name='display_part' value="<?php echo esc_attr($this->display_part) ?>" />
	        <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>

        <?php
        $this->loadView('settings/part-general');
        $this->loadView('settings/part-advanced');
        if (! $this->view->cloudflare_constant)
        {
          $this->loadView('settings/part-cloudflare');
        }
        if ($view->averageCompression !== null)
        {
    //     $this->loadView('settings/part-statistics');
        }

        ?>
			</form>
			<?php
				if (Log::debugIsActive())
        {
          $this->loadView('settings/part-debug');
        }
				?>
			</div> <!-- wrappur -->
      <?php
    endif;
    ?>

</article>
<?php $this->loadView('settings/part-wso'); ?>

<?php //$this->loadView('snippets/part-inline-help'); ?>
<?php $this->loadView('snippets/part-inline-modal'); ?>
