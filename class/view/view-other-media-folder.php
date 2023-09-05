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


		<div class="spio-inline-help"><span class="dashicons dashicons-editor-help" title="Click for more info" data-link="https://shortpixel.com/knowledge-base/article/46-how-to-optimize-images-in-wordpress-themes-and-plugins"></span></div>
									<div class="shortpixel-folders-list">


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

											$action =  __("Stop monitoring",'shortpixel-image-optimiser');

											$err = ''; // unused since failed is gone.
											if (! $item->exists() && ! $err)
												$err = __('Directory does not exist', 'shortpixel-image-optimiser');


											if ($item->get('is_nextgen') && $view->data->includeNextGen == 1)
												$action = false;


												$refreshUrl = add_query_arg(array('sp-action' => 'action_refreshfolder', 'folder_id' => $folder_id, 'part' => 'adv-settings'), $this->url); // has url
											?>
											<div class='item item-<?php echo esc_attr($item->get('id')) ?>'>
												<span><input type="checkbox" /></span>
													<span class='folder folder-<?php echo esc_attr($item->get('id')) ?>'><?php echo esc_html($item->getPath()); ?></span>
													<span>
															<?php if(!($stat['total'] == 0)) { ?>
															<span title="<?php echo esc_attr($fullstatus); ?>" class='info-icon'>
																	<img alt='<?php esc_html_e('Info Icon', 'shortpixel-image-optimiser') ?>' src='<?php echo esc_url( wpSPIO()->plugin_url('res/img/info-icon.png' ));?>' style="margin-bottom: -2px;"/>
															</span>&nbsp;<?php  }
															echo esc_html($type_display. ' ' . $optimize_status . $err);
															?>
													</span>
													<span>
															<?php echo esc_html($stat['total']); ?> files
													</span>
													<span>
															<?php echo esc_html(UiHelper::formatTS($item->get('updated'))) ?>
													</span>
													<span>
														<a href='<?php echo esc_url($refreshUrl) ?>' title="<?php esc_html_e('Recheck for new images', 'shortpixel-image-optimiser'); ?>" class='refresh-folder'><i class='dashicons dashicons-update'>&nbsp;</i></a>
													</span>
													<span class='action'>
														<?php if ($action): ?>
															<input type="button" class="button remove-folder-button" data-value="<?php echo esc_attr($item->get('id')); ?>" data-name="<?php echo esc_attr($item->getPath()) ?>" title="<?php echo esc_attr($action . " " . $item->getPath()); ?>"   value="<?php echo esc_attr($action); ?>">
													 <?php endif; ?>
													</span>
											</div>
									<?php }?>
								</div> <!-- shortpixel-folders-list -->
								<div class='folder-stats'> XX folders ( xx files )  </div>

	<?php endif; // view -> customerFolders
