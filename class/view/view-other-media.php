<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Helper\UiHelper as UiHelper;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$fs = \wpSPIO()->filesystem();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
if ( isset($_GET['noheader']) ) {
    require_once(ABSPATH . 'wp-admin/admin-header.php');
}

$this->loadView('custom/part-othermedia-top');

?>


<div class='extra-heading top'>
  <span>&nbsp;</span>
  <span>
    <select name='bulk-actions'>
     <option><?php _e('Bulk Actions', 'shortpixel-image-optimiser'); ?></option>
     <option value='optimize'><?php _e('Optimize','shortpixel-image-optimiser'); ?></option>
     <option value='restore'><?php _e('Restore', 'shortpixel-image-optimiser'); ?></option>
     <option value="mark-completed"><?php _e('Mark completed', 'shortpixel-image-optimiser'); ?></option>
   </select> <button class='button' type='button' name='doBulkAction'><?php _e('Apply', 'shortpixel-image-optimiser'); ?></button>
  </span>

  <span class='custom-filter'>
    <form method="get" action="<?php echo $this->url ?>" >
      <input type='hidden' name='page' value='wp-short-pixel-custom'>
    <?php $this->printFilter(); ?>
     <button class='button' type='submit'><?php _e('Filter', 'shortpixel-image-optimiser'); ?></button>
   </form>
  </span>

</div>
    <div class='list-overview'>


      <div class='heading'>
        <?php foreach($this->view->headings as $hname => $heading):
        ?>
          <span class='heading <?php echo esc_attr($hname) ?>'>
              <?php echo $this->getDisplayHeading($heading); ?>
          </span>

        <?php endforeach; ?>
      </div>

        <?php if (count($this->view->items) == 0) : ?>
          <div class='no-items'> <p>
            <?php

            if (true === $view->hasSearch)
            {
              echo esc_html__('Your search query didn\'t result in any images. ', 'shortpixel-image-optimiser');
             }
             elseif (true === $view->hasFilter )
             {
               printf(esc_html__('Filter didn\'t yield any results.  %s Show all Items %s ', 'shortpixel-image-optimiser'), "<a href='$this->url'>",'</a>');
             }
             else
             {
               $folder_url = esc_url(add_query_arg('part', 'folders', $this->url));

               printf(esc_html__('No images available. Go to %s Folders %s to configure additional folders to be optimized.','shortpixel-image-optimiser'), '<a href="'. esc_url($folder_url) . '">', '</a>');

             } ?>
          </p>
          </div>

        <?php endif; ?>

        <?php
        $folders = $this->view->folders;

        foreach($this->view->items as $item):


        ?>

        <div class='item item-<?php echo esc_attr($item->get('id')) ?>'>
            <?php

              $allActions = array_merge(UiHelper::getActions($item), UiHelper::getListActions($item));

              $checkBoxActions = array();
              if (array_key_exists('optimize', $allActions))
              {
                  $checkBoxActions[] = 'is-optimizable';
              }
              if (array_key_exists('restore', $allActions))
              {
                  $checkBoxActions[] = 'is-restorable';
              }

              $filesize = $item->getFileSize();
              $display_date = $this->getDisplayDate($item);
              $folder_id = $item->get('folder_id');

              $rowActions = $this->getRowActions($item);


              $folder = isset($folders[$folder_id]) ? $folders[$folder_id] : false;
              $media_type = ($folder && $folder->get('is_nextgen')) ? __('Nextgen', 'shortpixel-image-optimiser') : __('Custom', 'shortpixel_image_optimiser');
              $img_url = $fs->pathToUrl($item);
              $is_heavy = ($filesize >= 500000 && $filesize > 0);

              $item_class = '';
              if (count($checkBoxActions) > 0)
              $item_class = ' class="' . implode(' ', $checkBoxActions) . '" ';

            ?>
            <span><input type='checkbox' name='select[]' value="<?php echo $item->get('id'); ?>" <?php echo $item_class ?>/></span>
            <span><a href="<?php echo esc_attr($img_url); ?>" target="_blank">
                <div class='thumb' <?php if($is_heavy)
								{
								 	echo('title="' . esc_attr__('This image is heavy and it would slow this page down if displayed here. Click to open it in a new browser tab.', 'shortpixel-image-optimiser') . '"');
								}
                ?> style="background-image:url('<?php echo($is_heavy ? esc_url(wpSPIO()->plugin_url('res/img/heavy-image@2x.png')) : esc_url($img_url)) ?>')">
							</div>
                </a></span>
            <span class='filename'><?php echo esc_html($item->getFileName()) ?>

                <div class="row-actions">
                  <span class='item-id'>#<?php echo esc_attr($item->get('id')); ?></span>

                  <?php
								if (isset($rowActions)):
									$i = 0;
								  foreach($rowActions as $actionName => $action):


								    $classes = ''; // ($action['display'] == 'button') ? " button-smaller button-primary $actionName " : "$actionName";
								    $link = ($action['type'] == 'js') ? 'javascript:' . $action['function'] : $action['function'];
										$newtab  = ($actionName == 'extendquota' || $actionName == 'view') ? 'target="_blank"' : '';

										if ($i > 0)
											echo "|";
								    ?>
								   	<a href="<?php echo $link ?>" <?php echo esc_attr($newtab); ?> class="<?php echo $classes ?>"><?php echo $action['text'] ?></a>
								    <?php
										$i++;
								  endforeach;UiHelper::getActions($item);

								endif;
                ?>
							</div>
            </span>
            <span class='folderpath'><?php echo  esc_html( (string) $item->getFileDir()); ?></span>
            <span class='mediatype'><?php echo esc_html($media_type) ?></span>
            <span class="date"><?php echo esc_html($display_date) ?></span>

            <span >
								<?php $this->doActionColumn($item); ?>
	          </span>

        </div>
        <?php endforeach; ?>
      </div>


      <div class='pagination tablenav bottom'>
				<div class="view_switch">
					<?php if ($this->has_hidden_items || $this->show_hidden):

						if ($this->show_hidden)
						{
							 printf('<a href="%s">%s</a>', esc_url(add_query_arg('show_hidden',false)), esc_html__('Back to normal items', 'shortpixel-image-optimiser'));
						}
						else
						{
							 printf('<a href="%s">%s</a>', esc_url(add_query_arg('show_hidden',true)), esc_html__('Show hidden items', 'shortpixel-image-optimiser'));
						}

					 endif; ?>
				</div>
        <div class='tablenav-pages'>
            <?php echo $this->view->pagination; ?>
        </div>
      </div>


</div> <!-- wrap -->

<?php $this->loadView('snippets/part-comparer'); ?>
