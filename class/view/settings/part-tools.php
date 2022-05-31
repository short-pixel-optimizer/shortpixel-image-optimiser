<?php
namespace ShortPixel;
use \ShortPixel\Controller\BulkController as BulkController;
use \ShortPixel\Helper\UiHelper as UiHelper;

$url = esc_url_raw(remove_query_arg('part'));

$bulk = BulkController::getInstance();
$queueRunning = $bulk->isAnyBulkRunning();

?>

<section id="tab-tools" class="clearfix <?php echo ($this->display_part == 'tools') ? ' sel-tab ' :''; ?> ">
    <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-tools"><?php _e('Tools','shortpixel-image-optimiser');?></a></h2>


	<p class='description'><?php printf(__('The tools provided are designed to make bulk changes to your data. Therefore, it is %s very important %s that you back up your entire website. ', 'shortpixel-image-optimiser'), '<b>', '</b>'); ?></p>

	<div class='wp-shortpixel-options wp-shortpixel-tab-content'>

		<div class='option'>
			<div class='name'><?php _e('Migrate data', 'shortpixel-image-optimiser'); ?></div>
			<div class='field'><a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'migrate', 'noheader' => true), $url)); ?>" class="button">
						<?php _e('Search and Migrate All', 'shortpixel-image-optimiser'); ?>
				</a>
				<p class='description'><?php printf(__('If you upgraded from a ShortPixel Image Optimizer version prior than version 5.0, you may want to convert all your image data to the new format. %s This will speed up the plugin and ensure all data is preserved. %s Check your image data after running the conversion! %s', 'shortpixel-image-optimiser'), '<br>', '<br><b>', '</b>') ?> </p>
			</div>
		</div>



 		<div class='option'>
			<div class='name'><?php _e('Clear Queue','shortpixel-image-optimiser'); ?></div>
			<div class='field'>

				<a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_resetQueue', 'queue' => 'all', 'part' => 'tools'), $url)); ?>" class="button"><?php _e('Clear the Queue','shortpixel-image-optimiser'); ?></a>
				<p class='description'><?php _e('Removes all current items waiting or in process from all the queues. This stops any optimization process across the installation.', 'shortpixel-image-optimiser'); ?> </p>

			</div>
		</div>


		<?php if ($queueRunning === true): ?>
		<div class='option'>
			<div class='name'>&nbsp;</div>

			<div class='field danger-zone action'>
				 	<?php _e('It looks like a bulk process is still active. Please note that bulk actions will reset running bulk processes. ', 'shortpixel-image-optimiser'); ?>
			 </div>
		</div>
		<?php endif; ?>



		<hr />

		<div class='danger-zone'>
			<h3><?php _e('Danger Zone', 'shortpixel-image-optimiser'); ?></h3>
			<p><?php _e('Actions below are regarding cleanup and deinstallation. They cannot be undone. It is important to have a fresh backup ready before attemping it. Will cause data loss.', 'shortpixel-image-optimiser') ?></p>
			<hr />


			<div class='option'>
					<div class='name'>Remove data</div>
					<div class='field'>
						<a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'restore', 'noheader' => true), $url)) ?>" class="button danger">Bulk Restore</a>

					<p class='description'><?php printf(__('Will %sUndo%s all optimizations and restore all your backed up images to their original state. Used credits will not be refunded and you will have to re-optimize your images.', 'shortpixel-image-optimiser'), '<b>','</b>'); ?></p>
				</div>
			</div>

			<div class='option'>
					<div class='name'>&nbsp;</div>
					<div class='field'>
						<a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'removeLegacy', 'noheader' => true), $url)); ?>" class="button danger">Remove Legacy Data</a>

					<p class='description'><?php printf(__('Will %sRemove Legacy data%s . This may result in data loss. Not recommended to do this manually.', 'shortpixel-image-optimiser'), '<b>','</b>'); ?></p>
				</div>
			</div>

			<div class='option'>
					<div class='name'>&nbsp;</div>
					<div class='field'>
						<button type="button" class='button danger' data-action="open-modal" data-target="ToolsRemoveAll">
														<?php _e('Remove all Shortpixel Data', 'shortpixel-image-optimiser'); ?></button>


						<div class='remove-all modalTarget' id="ToolsRemoveAll">

							<input type="hidden" name="screen_action" value="toolsRemoveAll" />
							<?php  wp_nonce_field('remove-all', 'tools-nonce'); ?>

							<p>&nbsp;</p>
							<p><?php _e('This will remove all Shortpixel Data including data about optimization.', 'shortpixel-image-optimiser'); ?></p>
							<?php _e('Type confirm to delete all Shortpixel data', 'shortpixel-image-optimiser'); ?>
							<input type="text" name="confirm" value=""  data-required='confirm' />

							<p><b><?php _e('I understand that all Shortpixel data will be removed.','shortpixel-image-optimiser'); ?></b></p>

							<button type="button" class='button modal-send' name="uninstall" data-action='ajaxrequest'><?php _e('Remove all data', 'shortpixel-image-optimiser'); ?></button>

						</div> <!-- modal -->
						<p class='description'><?php printf(__('This will %s remove all ShortPixel data (including backups) %s  and deactivate the plugin. Your images will not changed, but next time ShortPixel is activated will not recognize any previous optimizations.', 'shortpixel-image-optimiser'), '<b>','</b>'); ?></p>
				 </div>
			</div>


			 <div class="option">
				 		<div class='name'>&nbsp;</div>
						<div class='field'>
										<div class="backup-modal">
									<?php wp_nonce_field('empty-backup', 'tools-nonce'); ?>

									<button type="button" class='button danger' data-action="open-modal" data-target="ToolsRemoveBackup">
																	<?php _e('Remove backups', 'shortpixel-image-optimiser'); ?></button>


									<div class='remove-backup modalTarget' id="ToolsRemoveBackup">

										<input type="hidden" name="screen_action" value="toolsRemoveBackup" />
										<?php  wp_nonce_field('empty-backup', 'tools-nonce'); ?>

										<p>&nbsp;</p>
										<p><?php _e('This will delete all the backup images. You won\'t be able to restore from backup or to reoptimize with different settings if you delete the backups.', 'shortpixel-image-optimiser'); ?></p>
										<?php _e('Type confirm to delete all Shortpixel data', 'shortpixel-image-optimiser'); ?>
										<input type="text" name="confirm" value="" data-required='confirm' />

										<p><b><?php _e('I understand that all Backups will be removed.','shortpixel-image-optimiser'); ?>  </b></p>

										<p class='center'>
											<button type="button" class='button modal-send' name="removebackups" data-action='ajaxrequest'><?php _e('Remove backups', 'shortpixel-image-optimiser'); ?></button>
										</p>
									</div>
							</div> <!-- backup modal -->

							<p class='description'><?php _e('When enabled, original images are stored in a backup folder. Removing the backup folder will means that you can\'t restore images or reoptimize images','shortpixel-image-optimiser');?>
						</div>

				</div>


			</div> <!-- danger zone -->
	</div> <!-- options tab content -->
</section>
