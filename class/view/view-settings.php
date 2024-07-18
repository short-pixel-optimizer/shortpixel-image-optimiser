<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>

<div class="wrap is-shortpixel-settings-page">
<h1>
    <?php esc_html_e('ShortPixel Plugin Settings','shortpixel-image-optimiser');?>
</h1>

<?php $this->loadView('settings/part-header'); ?>


<hr class='wp-header-end'>

<article class='shortpixel-settings'>
		<menu>
			<ul>
				<li><a href="?overview"><?php _e("Overview", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?optimisation"><?php _e("Image optimisation", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?processing"><?php _e("Processing", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?webp"><?php _e("Webp/Avif", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?delivery"><?php _e("Delivery", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?cdn"><?php _e("CDN", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?overview"><?php _e("Exclusions", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?overview"><?php _e("Tools", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?overview"><?php _e("Notifications", "shortpixel-image-optimiser"); ?></a></li>
				<li><a href="?overview"><?php _e("Debug", "shortpixel-image-optimiser"); ?></a></li>

			</ul>
		</menu>
		<section class="wrapper">
					<?php $this->loadView('settings/part-general'); ?>
		</section>
</article>


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
				$this->loadView('settings/part-tools');

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
