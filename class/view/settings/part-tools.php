<?php
namespace ShortPixel;
use \ShortPixel\Helper\UiHelper as UiHelper;

?>

<section id="tab-tools" class="clearfix <?php echo ($this->display_part == 'tools') ? ' sel-tab ' :''; ?> ">
    <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-tools"><?php _e('Tools','shortpixel-image-optimiser');?></a></h2>

<BR><BR>
		<div class='option action'>
			<a href="<?php echo add_query_arg(array('sp-action' => 'action_debug_resetQueue')) ?>" class="button">Clear the Queue</a>
			<p class='description'>It is highly recommended that you optimize the thumbnails as they are usually the images most viewed by end users and can generate most traffic.
Please note that thumbnails count up to your total quota. </p>
		</div>


		<div class='option action'>
			<a href="<?php echo admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-migrate'); ?>" class="button">Search and Migrate All</a>
			<p class='description'>It is highly recommended that you optimize the thumbnails as they are usually the images most viewed by end users and can generate most traffic.
			Please note that thumbnails count up to your total quota. </p>
		</div>


		<hr>
		<h3>danger zone</h3>
		<hr>

		<div class='danger-zone'>

			<div class='option'>These actions cannot be undone. It is important to have a fresh backup ready before attemping any of them. Can cause data-loss.</div>

			<div class='option action'><a href="<?php echo admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-restore'); ?>" class="button">Bulk Restore</a></div>

		</div>

</section>
