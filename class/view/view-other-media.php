<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Helper\UiHelper as UiHelper;


$fs = \wpSPIO()->filesystem();

if ( isset($_GET['noheader']) ) {
    require_once(ABSPATH . 'wp-admin/admin-header.php');
}


?>
<div class="wrap shortpixel-other-media">
    <h2>
        <?php _e('Custom Media optimized by ShortPixel','shortpixel-image-optimiser');?>
    </h2>

    <div class='toolbar'>
        <div>
          <?php
          $nonce = wp_create_nonce( 'refresh_folders' );
          ?>
            <a href="<?php echo admin_url('upload.php?page=wp-short-pixel-custom&sp-action=action_refreshfolders&_wpnonce=' . $nonce); ?>" id="refresh" class="button button-primary" title="<?php _e('Refresh custom folders content','shortpixel-image-optimiser');?>">
                <?php _e('Refresh folders','shortpixel-image-optimiser');?>
            </a>
        </div>
			<hr class='wp-header-end' />

      <div class="searchbox">
            <form method="get">
                <input type="hidden" name="page" value="wp-short-pixel-custom" />
                <input type='hidden' name='order' value="<?php echo $this->order ?>" />
                <input type="hidden" name="orderby" value="<?php echo $this->orderby ?>" />

                <p class="search-form">
                  <label><?php _e('Search', 'shortpixel-image-optimiser'); ?></label>
                  <input type="text" name="s" value="<?php echo $this->search ?>" />

                </p>
                <?php //$customMediaListTable->search_box("Search", "sp_search_file");
                ?>
            </form>
      </div>
  </div>

<?php if ($this->view->pagination !== false): ?>
  <div class='pagination tablenav'>
			<div class="view_switch">
				<?php if ($this->has_hidden_items):

					if ($this->show_hidden)
					{
						 printf('<a href="%s">%s</a>', esc_url(add_query_arg('show_hidden',false)), __('Back to normal items', 'shortpixel-image-optimiser'));
					}
					else
					{
						 printf('<a href="%s">%s</a>', esc_url(add_query_arg('show_hidden',true)), __('Show hidden items', 'shortpixel-image-optimiser'));
					}

		     endif; ?>
			</div>
      <div class='tablenav-pages'>
        <?php echo $this->view->pagination; ?>
    </div>
  </div>
<?php endif; ?>

    <div class='list-overview'>
      <div class='heading'>
        <?php foreach($this->view->headings as $hname => $heading):
            $isSortable = $heading['sortable'];
        ?>
          <span class='heading <?php echo $hname ?>'>
              <?php echo $this->getDisplayHeading($heading); ?>
          </span>

        <?php endforeach; ?>
      </div>

        <?php if (count($this->view->items) == 0) : ?>
          <div class='no-items'> <p>
            <?php
            if ($this->search === false):
              echo(__('No images available. Go to <a href="options-general.php?page=wp-shortpixel-settings&part=adv-settings">Advanced Settings</a> to configure additional folders to be optimized.','shortpixel-image-optimiser'));
             else:
               echo __('Your search query didn\'t result in any images. ', 'shortpixel-image-optimiser');
            endif; ?>
          </p>
          </div>

        <?php endif; ?>

        <?php
        $folders = $this->view->folders;

        foreach($this->view->items as $item):


          ?>

        <div class='item item-<?php echo $item->get('id') ?>'>
            <?php
            //  $itemFile = $fs->getFile($item->path);
              $filesize = $item->getFileSize();
              $display_date = $this->getDisplayDate($item);
              $folder_id = $item->get('folder_id');

              $rowActions = $this->getRowActions($item);


              $folder = isset($folders[$folder_id]) ? $folders[$folder_id] : false;
              $media_type = ($folder && $folder->get('is_nextgen')) ? __('Nextgen', 'shortpixel-image-optimiser') : __('Custom', 'shortpixel_image_optimiser');
              $img_url = $fs->pathToUrl($item);
              $is_heavy = ($filesize >= 500000 && $filesize > 0);

            ?>
            <span><a href="<?php echo($img_url);?>" target="_blank">
                <div class='thumb' <?php if($is_heavy)
								{
								 	echo('title="' . __('This image is heavy and it would slow this page down if displayed here. Click to open it in a new browser tab.', 'shortpixel-image-optimiser') . '"');
								}
                ?> style="background-image:url('<?php echo($is_heavy ? wpSPIO()->plugin_url('res/img/heavy-image@2x.png') : $img_url) ?>')"></div>
                </a></span>
            <span class='filename'><?php echo $item->getFileName() ?>
                <div class="row-actions"><?php
                $numberActions = count($rowActions);
                for ($i = 0; $i < $numberActions; $i++)
                {
                    echo $rowActions[$i];
                    if ($i < ($numberActions-1) )
                      echo '|';
                }
                ?>
								<span class='item-id'>#<?php echo $item->get('id'); ?></span>
							</div>
            </span>
            <span class='folderpath'><?php echo (string) $item->getFileDir(); ?></span>
            <span class='mediatype'><?php echo $media_type ?></span>
            <span class="date"><?php echo $display_date ?></span>

            <span >
								<?php $this->doActionColumn($item); ?>
	          </span>




        </div>
        <?php endforeach; ?>
      </div>


      <div class='pagination tablenav bottom'>
        <div class='tablenav-pages'>
            <?php echo $this->view->pagination; ?>
        </div>
      </div>


</div> <!-- wrap -->

<?php $this->loadView('snippets/part-comparer'); ?>
