<?php
namespace ShortPixel;
use \ShortPixel\Controller\BulkController as BulkController;
use \ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$url = esc_url_raw(remove_query_arg('part'));

$bulk = BulkController::getInstance();
$queueRunning = $bulk->isAnyBulkRunning();

?>

<section id="tab-tools" class="<?php echo ($this->display_part == 'tools') ? 'active setting-tab' :'setting-tab'; ?>" data-part="tools">


    <settinglist>
      <h2><?php _e('Tools', 'shortpixel-image-optimiser'); ?></h2>

	<p><?php printf(esc_html__('The tools below are designed for making bulk changes to your image and optimization data. It is %s highly recommended %s to back up your entire website before using them. ', 'shortpixel-image-optimiser'), '<b>', '</b>'); ?></p>

		<?php if ($queueRunning === true): ?>
		<div class='option'>

			<div class='field action queue-warning'>
				 	<?php esc_html_e('It looks like a bulk process is still active. Please note that bulk actions will reset running bulk processes. ', 'shortpixel-image-optimiser'); ?>
			 </div>
		</div>
		<?php endif; ?>

        <setting>

            <content>
              <a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'migrate', 'noheader' => true), $url)); ?>" class="button">
                  <?php esc_html_e('Search and Migrate All', 'shortpixel-image-optimiser'); ?>
              </a>

                <i class='documentation down dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/539-spio-5-tells-me-to-convert-legacy-data-what-is-this?target=iframe"></i>

                <info>
                  <?php printf(esc_html__('ShortPixel Image Optimizer version 5.0 brings a new format for saving the image optimization information. If you have upgraded from a version prior to version 5.0, you may want to convert all your image data to the new format. This conversion will speed up the plugin and ensure that all data is preserved. %sThis process is also useful for resolving errors that may occur during optimization due to leftover metadata.%s', 'shortpixel-image-optimiser'), '<br><b>', '</b>') ?>
                </info>
            </content>
    <!--        <name>
              <?php esc_html_e('Migrate data', 'shortpixel-image-optimiser'); ?>
            </name> -->
        </setting>

        <setting>
          <!--  <name>
              <?php esc_html_e('Clear Queue','shortpixel-image-optimiser'); ?>
            </name> -->
            <content>
        				<a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_resetQueue', 'queue' => 'all', 'part' => 'tools', 'noheader' => true), $url)); ?>" class="button"><?php esc_html_e('Clear the Queue','shortpixel-image-optimiser'); ?></a>

                <info>
                  <?php esc_html_e('Removes all items currently waiting or being processed from all queues. This stops all optimization processes in the entire installation.', 'shortpixel-image-optimiser'); ?>
                </info>
            </content>
        </setting>

        <setting>
        <!--    <name>
              <?php esc_html_e('Clear Optimization Errors','shortpixel-image-optimiser'); ?>
            </name> -->
            <content>
        				<a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_removePrevented', 'queue' => 'all', 'part' => 'tools', 'noheader' => true), $url)); ?>" class="button"><?php esc_html_e('Clear Optimization Errors','shortpixel-image-optimiser'); ?></a>

                <info>
                  <?php printf(esc_html__('Removes the blocks from the items where the optimization failed for some reason. This usually happens when the plugin is not able to save the backups. %s %sImportant!%s The cause of the error should be fixed, otherwise data corruption may occur.','shortpixel-image-optimiser') , '<br>', '<b>','</b>'); ?>
                </info>
            </content>
        </setting>

    </settinglist>


		<hr />

		<div class='danger-zone'>
			<h3><?php esc_html_e('Danger Zone - please read carefully!', 'shortpixel-image-optimiser'); ?></h3>
			<p><?php printf(esc_html__('The following actions are related to cleaning up and uninstalling the plugin. %s They cannot be undone %s. It is important that you create a new backup copy before performing any of these actions, as this may result in data loss.', 'shortpixel-image-optimiser'), '<strong>', '</strong>');  ?></p>
			<hr />

      <settinglist>

       <!-- Bulk Restore -->
       <setting>
         <name>
              <?php esc_html_e('Undo optimization: Restore all images to original state','shortpixel-image-optimiser'); ?>
         </name>
         <content>
           <a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'restore', 'noheader' => true), $url)) ?>" class="button danger"><?php _e('Bulk Restore', 'shortpixel-image-optimiser'); ?></a>

             <i class='documentation down dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/14-can-i-restore-my-images-what-happens-with-the-originals?target=iframe"></i>
           <info>
             <?php printf(esc_html__('%sUndoes%s all optimizations and restores all your backed-up images to their original state. Credits used will not be refunded and you will have to optimize your images again.', 'shortpixel-image-optimiser'), '<b>','</b>'); ?>
           </info>
         </content>
      </setting>

      <!-- Remove Legacy Data -->
      <setting>
      <!--  <name>
            &nbsp;
        </name> -->
        <content>
						<a href="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_redirectBulk', 'bulk' => 'removeLegacy', 'noheader' => true), $url)); ?>" class="button danger"><?php esc_html_e('Remove Legacy Data'); ?></a>

          <info>
            <?php printf(esc_html__('%sRemoves Legacy Data%s (the old format for storing image optimization information in the database, which was used before version 5). This may result in data loss. It is not recommended to do this manually.', 'shortpixel-image-optimiser'), '<b>','</b>'); ?>
          </info>
        </content>
     </setting>

     <!-- Remove All Data -->
     <setting>
       <!-- <name>
          &nbsp;
       </name> -->
       <content>
         <button type="button" class='button danger' data-action="open-modal" data-target="ToolsRemoveAll">
                       <?php esc_html_e('Remove all ShortPixel Data', 'shortpixel-image-optimiser'); ?></button>

           <i class='documentation down dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/81-remove-all-the-shortpixel-related-data-on-a-wp-website?target=iframe"></i>
         <info>
            <?php printf(esc_html__('%sRemoves all ShortPixel data (including backups) %s and deactivates the plugin. Your images will not be changed (the optimized images will remain), but the next time ShortPixel is activated, it will no longer recognize previous optimizations.', 'shortpixel-image-optimiser'), '<b>','</b>'); ?>
         </info>
         <div class='remove-all modalTarget' id="ToolsRemoveAll">

           <input type="hidden" name="screen_action" value="toolsRemoveAll" />
           <?php  wp_nonce_field('remove-all', 'tools-nonce'); ?>

           <p>&nbsp;</p>
           <p><?php esc_html_e('This will remove all ShortPixel Data including data about optimization and image backups.', 'shortpixel-image-optimiser'); ?></p>
           <?php esc_html_e('Type confirm to delete all ShortPixel data', 'shortpixel-image-optimiser'); ?>
           <input type="text" name="confirm" value=""  data-required='confirm' />

           <p><b><?php esc_html_e('I understand that all ShortPixel data will be removed.','shortpixel-image-optimiser'); ?></b></p>

           <button type="button" class='button modal-send' name="uninstall" data-action='ajaxrequest'><?php esc_html_e('Remove all data', 'shortpixel-image-optimiser'); ?></button>

         </div> <!-- modal -->
       </content>
    </setting>


    <!-- Remove Backups -->
    <setting>
      <!-- <name>
        &nbsp;
      </name> -->
      <content>
        <button type="button" class='button danger' data-action="open-modal" data-target="ToolsRemoveBackup">
                      <?php esc_html_e('Remove backups', 'shortpixel-image-optimiser'); ?></button>

          <i class='documentation down dashicons dashicons-editor-help' data-link="https://shortpixel.com/knowledge-base/article/83-how-to-remove-the-backed-up-images-in-wordpress?target=iframe"></i>
        <info>
            <?php esc_html_e('When backups are enabled, original images are stored in a backup folder. If you remove the backup folder, you will not be able to restore or reoptimize the images. We strongly recommend that you keep a copy of the backup folder (/wp-content/uploads/ShortpixelBackups/) somewhere safe.','shortpixel-image-optimiser');?>
        </info>
              <?php wp_nonce_field('empty-backup', 'tools-nonce'); ?>

              <div class='remove-backup modalTarget' id="ToolsRemoveBackup">

                <input type="hidden" name="screen_action" value="toolsRemoveBackup" />
                <?php  wp_nonce_field('empty-backup', 'tools-nonce'); ?>

                <p>&nbsp;</p>
                <p><?php esc_html_e('This will delete all the backup images. You won\'t be able to restore from backup or to reoptimize with different settings if you delete the backups.', 'shortpixel-image-optimiser'); ?></p>
                <?php esc_html_e('Type confirm to delete all ShortPixel backups', 'shortpixel-image-optimiser'); ?>
                <input type="text" name="confirm" value="" data-required='confirm' />

                <p><b><?php esc_html_e('I understand that all Backups will be removed.','shortpixel-image-optimiser'); ?>  </b></p>

                <p class='center'>
                  <button type="button" class='button modal-send' name="removebackups" data-action='ajaxrequest'><?php esc_html_e('Remove backups', 'shortpixel-image-optimiser'); ?></button>
                </p>
              </div>
           <!-- backup modal -->
      </content>
   </setting>

      </settinglist>




			</div> <!-- danger zone -->
</section>
