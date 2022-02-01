<?php
namespace ShortPixel;
use \ShortPixel\Controller\BulkController as BulkController;
use \ShortPixel\Helper\UiHelper as UiHelper;

$url = remove_query_arg('part');

$bulk = BulkController::getInstance();
$queueRunning = $bulk->isAnyBulkRunning();

?>

<section id="tab-tools" class="clearfix <?php echo ($this->display_part == 'tools') ? ' sel-tab ' :''; ?> ">
    <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-tools"><?php _e('Tools','shortpixel-image-optimiser');?></a></h2>

	<p class='description'><?php printf(__('The tools provided are designed to make bulk changes to your data. Therefore, it is %s very important %s that you back up your entire website. ', 'shortpixel-image-optimiser'), '<b>', '</b>'); ?></p>

	<div class='wp-shortpixel-options wp-shortpixel-tab-content'>
		<div class='option action'>
			<a href="<?php echo add_query_arg(array('sp-action' => 'action_debug_resetQueue', 'queue' => 'all', 'part' => 'tools'), $url) ?>" class="button">Clear the Queue</a>
			<p class='description'><?php _e('Removes all current items waiting or in process from all the queues. This stops any optimization process across the installation.', 'shortpixel-image-optimiser'); ?> </p>
		</div>

		<?php if ($queueRunning === true): ?>
			 <div class='option danger-zone action'>
				 	<?php _e('It looks like a bulk process is still active. Please note that bulk actions will reset running bulk processes. ', 'shortpixel-image-optimiser'); ?>
			 </div>
		<?php endif; ?>

		<div class='option action'>
			<a href="<?php echo add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'migrate', 'noheader' => true), $url) ?>" class="button">
					<?php _e('Search and Migrate All', 'shortpixel-image-optimiser'); ?>
			</a>
			<p class='description'><?php printf(__('If you upgraded from a ShortPixel Image Optimiser version prior than version 5.0, you may want to convert all your image data to the new format. This will speed up the plugin and ensure all data is preserved. %s Check your image data after running the conversion! %s', 'shortpixel-image-optimiser'), '<b>', '</b>') ?> </p>
		</div>


		<h3><?php _e('Danger Zone', 'shortpixel-image-optimiser'); ?></h3>
		<div class='danger-zone'>
			<div class='option'><?php _e('This action cannot be undone. It is important to have a fresh backup ready before attemping it. Will cause data loss.', 'shortpixel-image-optimiser') ?></div>

			<div class='option action'>
					<a href="<?php echo add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'restore', 'noheader' => true), $url) ?>" class="button">Bulk Restore</a>

					<p class='description'><?php printf(__('Will %sUndo%s all optimizations and restore all your backed up images to their original state', 'shortpixel-image-optimiser'), '<b>','</b>'); ?></p>
			</div>

		</div>
	</div> <!-- options tab content -->
</section>
