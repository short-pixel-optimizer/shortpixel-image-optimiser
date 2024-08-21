<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<div class="wrap is-shortpixel-settings-page">
<header>
  <h1>
      <?php echo UIHelper::getIcon('res/img/robo-slider.png'); ?>
      <?php esc_html_e('ShortPixel Plugin Settings','shortpixel-image-optimiser');?>
  </h1>

  <div class='top-buttons'>
    <button>Notifications</button>
    <button>Others</button>
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
          <?php echo $this->settingLink('dashboard', __("Dashboard", "shortpixel-image-optimiser")); ?>
        </li>
				<li>
          <?php echo $this->settingLink('optimisation', __("Image optimisation", "shortpixel-image-optimiser")); ?>
        </li>
				<li>
          <?php echo $this->settingLink('processing', __("Processing", "shortpixel-image-optimiser")); ?>
        </li>
				<li>
          <?php echo $this->settingLink('webp', __("Webp/Avif", "shortpixel-image-optimiser")); ?>
        </li>
				<li>
          <?php echo $this->settingLink('delivery', __("Delivery", "shortpixel-image-optimiser")); ?>
        </li>
				<li>
          <?php echo $this->settingLink('cdn', __("CDN", "shortpixel-image-optimiser")); ?>
        </li>
				<li><a href="?overview"><?php _e("Exclusions", "shortpixel-image-optimiser"); ?></a></li>
				<li>
          <?php echo $this->settingLink('tools', __("Tools", "shortpixel-image-optimiser")); ?>
        </li>
				<li><a href="?overview"><?php _e("Notifications", "shortpixel-image-optimiser"); ?></a></li>
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
