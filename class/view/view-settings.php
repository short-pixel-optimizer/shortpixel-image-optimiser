<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

?>
<div class="wrap is-shortpixel-settings-page">
<h1><?php _e('ShortPixel Plugin Settings','shortpixel-image-optimiser');?></h1>
<div class='top-menu'>

	  <div class='links'>
    <a href="https://shortpixel.com/<?php
    echo(($view->data->apiKey ? "login/". $view->data->apiKey : "pricing"));
    ?>" target="_blank">
        <?php _e('Add credits','shortpixel-image-optimiser');?>
    </a> | <a href="https://shortpixel.com/knowledge-base/" target="_blank"><?php _e('Knowledge Base','shortpixel-image-optimiser');?> </a> |
    <a href="https://shortpixel.com/contact" target="_blank"><?php _e('Contact Support','shortpixel-image-optimiser');?> </a> |
    <a href="https://shortpixel.com/<?php
    echo(($view->data->apiKey ? "login/". $view->data->apiKey : "pricing"));
    ?>" target="_blank">
        <?php _e('ShortPixel account','shortpixel-image-optimiser');?>
    </a>
		</div>

		<div class='pie-wrapper'>
			<?php	$this->loadView('settings/part-optpie'); ?>
		</div>
		
		<?php if (! is_null($this->quotaData)): ?>
		<div class='quota-remaining'>
			<?php printf(__('%s Credits remaining', 'shortpixel-image-optimiser'),  $this->formatNumber($this->quotaData->total->remaining, 0)); ?>
		</div>
		<?php endif; ?>
</div>

<hr class='wp-header-end'>


<article id="shortpixel-settings-tabs" class="sp-tabs">
    <?php if (! $this->is_verifiedkey)
    {
      $this->loadView('settings/part-nokey');
    } ?>

  <?php
    if ($this->is_verifiedkey):



      ?>
      <form name='wp_shortpixel_options' action='<?php echo esc_url(add_query_arg('noheader', 'true')) ?>'  method='post' id='wp_shortpixel_options'>
        <input type='hidden' name='display_part' value="<?php echo $this->display_part ?>" />
        <?php wp_nonce_field($this->form_action, 'sp-nonce'); ?>
      <div class='section-wrapper'>
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
        if (Log::debugIsActive())
        {
          $this->loadView('settings/part-debug');
        }
        ?>
      </div>
      </form>
      <?php
    endif;
    ?>

</article>

<?php $this->loadView('snippets/part-inline-help'); ?>
<?php $this->loadView('snippets/part-inline-modal'); ?>
<?php $this->loadView('settings/part-wso'); ?>
