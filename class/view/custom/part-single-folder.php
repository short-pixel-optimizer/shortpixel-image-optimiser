<?php

namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Helper\UiHelper as UiHelper;


if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

$item = $this->view->current_item;

$folder_id = $item->get('id');

$type_display =   ($item->get('is_nextgen') ) ? __('Nextgen', 'shortpixel-image-optimiser') : __('Custom Media', 'shortpixel-image-optimiser');
$stat = $item->getStats();


$fullstatus = esc_html__("Optimized",'shortpixel-image-optimiser') . ": " . $stat['optimized'] . "\n"
      . "" . esc_html__("Unoptimized",'shortpixel-image-optimiser') . ": " . $stat['waiting'] . "\n"
      ;
//$fullstatus .= ($item->get('is_nextgen') ) ? __('Nextgen', 'shortpixel-image-optimiser') : "";


/*
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
*/


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
        <?php echo $type_display; ?>
    </span>
    <span>

        <span title="<?php echo esc_attr($fullstatus); ?>" class='info-icon'>
            <img alt='<?php esc_html_e('Info Icon', 'shortpixel-image-optimiser') ?>' src='<?php echo esc_url( wpSPIO()->plugin_url('res/img/info-icon.png' ));?>' style="margin-bottom: -2px;"/>
        </span>&nbsp;<?php
        //echo esc_html($type_display. ' ' );
        ?>

        <span class='files-number'><?php
          echo esc_html($stat['optimized']);
          echo '/';
          echo esc_html($stat['total']); ?>
        </span> <?php _e('Files', 'shortpixel-image-optimiser'); ?>
    </span>
    <span>
        <?php echo esc_html(UiHelper::formatTS($item->get('updated'))) ?>
    </span>
    <span class='status'>

    </span>

</div>
