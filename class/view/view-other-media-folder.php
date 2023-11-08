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


$this->loadView('custom/part-othermedia-top');

?>

<!--- add Custom Folder -->
<div class='addCustomFolder'>

  <p class='add-folder-text'><strong><?php esc_html_e('Add a custom folder', 'shortpixel-image-optimiser'); ?></strong></p>
  <input type="text" name="addCustomFolderView" id="addCustomFolderView" class="regular-text" value="" disabled style="">&nbsp;

  <a class="button open-selectfolder-modal" title="<?php esc_html_e('Select the images folder on your server.','shortpixel-image-optimiser');?>" href="javascript:void(0);">
      <?php esc_html_e('Select','shortpixel-image-optimiser');?>
  </a>
<input type="submit" name="save" id="saveAdvAddFolder" class="button button-primary hidden" title="<?php esc_html_e('Add this Folder','shortpixel-image-optimiser');?>" value="<?php esc_html_e('Add this Folder','shortpixel-image-optimiser');?>">
<p class="settings-info">
    <?php printf(esc_html__('Use the Select... button to select site folders. ShortPixel will optimize images and PDFs from the specified folders and their subfolders. In the %s Custom Media list %s, under the Media menu, you can see the optimization status for each image or PDF in these folders.','shortpixel-image-optimiser'), '<a href="upload.php?page=wp-short-pixel-custom">', '</a>');?>
</p>

<div class="sp-modal-shade sp-folder-picker-shade" ></div>
    <div class="shortpixel-modal modal-folder-picker shortpixel-hide">
        <div class="sp-modal-title"><?php esc_html_e('Select the images folder','shortpixel-image-optimiser');?></div>
        <div class="sp-folder-picker">

        </div>
        <input type="button" class="button button-info select-folder-cancel" value="<?php esc_html_e('Cancel','shortpixel-image-optimiser');?>" style="margin-right: 30px;">
        <input type="button" class="button button-primary select-folder" value="<?php esc_html_e('Add','shortpixel-image-optimiser');?>" disabled>

        <span class='sp-folder-picker-selected'>&nbsp;</span>
    </div>


</div> <!-- end of AddCustomFolder -->


<div class='list-overview'>
	<div class='heading'>
		<?php foreach($this->view->headings as $hname => $heading):

          $title_context = (isset($heading['title_context'])) ? ' title="'. esc_attr($heading['title_context']) . '"' : '';
		?>
			<span class='heading <?php echo esc_attr($hname);?>' <?php echo $title_context ?> >
					<?php echo $this->getDisplayHeading($heading); ?>
			</span>

		<?php endforeach; ?>
	</div>

		<?php if (count($this->view->items) == 0) : ?>
			<div class='no-items'> <p>
				<?php
				if ($this->search === false):
					printf(esc_html__('No folders available. ','shortpixel-image-optimiser'), '<a href="options-general.php?page=wp-shortpixel-settings&part=adv-settings">', '</a>');
				 else:
					 echo esc_html__('Your search query didn\'t result in any images. ', 'shortpixel-image-optimiser');
				endif; ?>
			</p>
			</div>

		<?php endif; ?>

		<?php
		foreach($view->items as $index => $item) {
        $this->view->current_item = $item; // not the best pass
        $this->loadView('custom/part-single-folder', false);

		 }?>
				</div> <!-- shortpixel-folders-list -->

	<?php // view -> customerFolders

  $this->loadView('custom/part-othermedia-bottom');
