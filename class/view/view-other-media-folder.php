<?php

namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
if ( isset($_GET['noheader']) ) {
    require_once(ABSPATH . 'wp-admin/admin-header.php');
}
 if($view->items):

	 $this->loadView('custom/part-othermedia-top');


?>

<!--- add Custom Folder -->
<div class='addCustomFolder'>

  <input type="hidden" name="removeFolder" id="removeFolder"/>
  <p class='add-folder-text'><strong><?php esc_html_e('Add a custom folder', 'shortpixel-image-optimiser'); ?></strong></p>
  <input type="text" name="addCustomFolderView" id="addCustomFolderView" class="regular-text" value="" disabled style="">&nbsp;
  <input type="hidden" name="addCustomFolder" id="addCustomFolder" value=""/>
  <input type="hidden" id="customFolderBase" value="<?php echo esc_attr($this->customFolderBase); ?>">

  <a class="button open-selectfolder-modal" title="<?php esc_html_e('Select the images folder on your server.','shortpixel-image-optimiser');?>" href="javascript:void(0);">
      <?php esc_html_e('Select','shortpixel-image-optimiser');?>
  </a>
<input type="submit" name="save" id="saveAdvAddFolder" class="button button-primary hidden" title="<?php esc_html_e('Add this Folder','shortpixel-image-optimiser');?>" value="<?php esc_html_e('Add this Folder','shortpixel-image-optimiser');?>">
<p class="settings-info">
    <?php printf(esc_html__('Use the Select... button to select site folders. ShortPixel will optimize images and PDFs from the specified folders and their subfolders. In the %s Custom Media list %s, under the Media menu, you can see the optimization status for each image or PDF in these folders.','shortpixel-image-optimiser'), '<a href="upload.php?page=wp-short-pixel-custom">', '</a>');?>
</p>

<div class="sp-modal-shade sp-folder-picker-shade"></div>
    <div class="shortpixel-modal modal-folder-picker shortpixel-hide">
        <div class="sp-modal-title"><?php esc_html_e('Select the images folder','shortpixel-image-optimiser');?></div>
        <div class="sp-folder-picker"></div>
        <input type="button" class="button button-info select-folder-cancel" value="<?php esc_html_e('Cancel','shortpixel-image-optimiser');?>" style="margin-right: 30px;">
        <input type="button" class="button button-primary select-folder" value="<?php esc_html_e('Select','shortpixel-image-optimiser');?>">
    </div>

<script>
    jQuery(document).ready(function () {
        //ShortPixel.initFolderSelector();
    });
</script>
</div> <!-- end of AddCustomFolder -->


<div class='list-overview'>
	<div class='heading'>
		<?php foreach($this->view->headings as $hname => $heading):
				$isSortable = $heading['sortable'];
		?>
			<span class='heading <?php echo esc_attr($hname) ?>'>
					<?php echo $this->getDisplayHeading($heading); ?>
			</span>

		<?php endforeach; ?>
	</div>

		<?php if (count($this->view->items) == 0) : ?>
			<div class='no-items'> <p>
				<?php
				if ($this->search === false):
					printf(esc_html__('No images available. Go to %s Advanced Settings %s to configure additional folders to be optimized.','shortpixel-image-optimiser'), '<a href="options-general.php?page=wp-shortpixel-settings&part=adv-settings">', '</a>');
				 else:
					 echo esc_html__('Your search query didn\'t result in any images. ', 'shortpixel-image-optimiser');
				endif; ?>
			</p>
			</div>

		<?php endif; ?>

<!--
		<div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/46-how-to-optimize-images-in-wordpress-themes-and-plugins"></span></div>
  -->
									<?php
									foreach($view->items as $index => $item) {

											$folder_id = $item->get('id');

											$type_display = ($item->get('is_nextgen') ) ? __('Nextgen', 'shortpixel-image-optimiser') . ":" : "";
											$stat = $item->getStats();

											$fullstatus = esc_html__("Optimized",'shortpixel-image-optimiser') . ": " . $stat['optimized'] . ", "
														. " " . esc_html__("Waiting",'shortpixel-image-optimiser') . ": " . $stat['waiting'] . ""
														;

											if ($stat['total'] == 0)
											{
												$optimize_status = __("Empty",'shortpixel-image-optimiser');
												$fullstatus = '';
											}
											elseif ($stat['total'] == $stat['optimized'])
											{
												$optimize_status = __("Optimized",'shortpixel-image-optimiser');
											}
											elseif ($stat['optimized'] > 0)
											{
												 $optimize_status = __("Pending",'shortpixel-image-optimiser');
											}
											else
											{
												$optimize_status = __("Waiting",'shortpixel-image-optimiser');
											}

										//	$action =  __("Stop monitoring",'shortpixel-image-optimiser');

											$err = ''; // unused since failed is gone.
											if (! $item->exists() && ! $err)
												$err = __('Directory does not exist', 'shortpixel-image-optimiser');


											if ($item->get('is_nextgen') && $view->settings->includeNextGen == 1)
												$action = false;

												$refreshUrl = add_query_arg(array('sp-action' => 'action_refreshfolder', 'folder_id' => $folder_id, 'part' => 'adv-settings'), $this->url); // has url

                        $rowActions = $this->getRowActions($item);
											?>
											<div class='item item-<?php echo esc_attr($item->get('id')) ?>'>
												<span><input type="checkbox" /></span>

													<span class='folder folder-<?php echo esc_attr($item->get('id')) ?>'>
                              <?php echo esc_html($item->getPath()); ?>

                            <div class="row-actions">
                            <span class='item-id'>#<?php echo esc_attr($item->get('id')); ?></span>
                            <?php
            								if (isset($rowActions)):
            									$i = 0;
            								  foreach($rowActions as $actionName => $action):
            								    $classes = '';
            								    $link = ($action['type'] == 'js') ? 'javascript:' . $action['function'] : $action['function'];

            										if ($i > 0)
            											echo "|";
            								    ?>
            								   	<a href="<?php echo $link ?>" class="<?php echo $classes ?>"><?php echo $action['text'] ?></a>
            								    <?php
            										$i++;
            								  endforeach;

            								endif;


                            ?>
            							</div>


                          </span>
													<span>
															<?php if(!($stat['total'] == 0)) { ?>
															<span title="<?php echo esc_attr($fullstatus); ?>" class='info-icon'>
																	<img alt='<?php esc_html_e('Info Icon', 'shortpixel-image-optimiser') ?>' src='<?php echo esc_url( wpSPIO()->plugin_url('res/img/info-icon.png' ));?>' style="margin-bottom: -2px;"/>
															</span>&nbsp;<?php  }
															echo esc_html($type_display. ' ' . $optimize_status . $err);
															?>
													</span>
													<span>
															<span class='files-number'><?php echo esc_html($stat['total']); ?></span> <?php _e('Files', 'shortpixel-image-optimiser'); ?>
													</span>
													<span>
															<?php echo esc_html(UiHelper::formatTS($item->get('updated'))) ?>
													</span>
                          <span class='status'>

                          </span>

											</div>
									<?php }?>
								</div> <!-- shortpixel-folders-list -->

	<?php endif; // view -> customerFolders

  $this->loadView('custom/part-othermedia-bottom');
