<?php
namespace ShortPixel;
use ShortPixel\Notices\NoticeController as NoticeController;
use Shortpixel\Controller\StatsController as StatsController;

?>

<section id="tab-debug" <?php echo ($this->display_part == 'debug') ? ' class="sel-tab" ' :''; ?>>
  <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-debug">
    <?php _e('Debug','shortpixel-image-optimiser');?></a>
  </h2>

<div class="wp-shortpixel-options wp-shortpixel-tab-content" style="visibility: hidden">
  <div class='env'>
    <h3><?php _e('Environment', 'shortpixel'); ?></h3>
    <div class='flex'>
      <span>Nginx</span><span><?php var_export($this->is_nginx); ?></span>
      <span>KeyVerified</span><span><?php var_export($this->is_verifiedkey); ?></span>
      <span>HtAccess writable</span><span><?php var_export($this->is_htaccess_writable); ?></span>
      <span>Multisite</span><span><?php var_export($this->is_multisite); ?></span>
      <span>Main site</span><span><?php var_export($this->is_mainsite); ?></span>
      <span>Constant key</span><span><?php var_export($this->is_constant_key); ?></span>
      <span>Hide Key</span><span><?php var_export($this->hide_api_key); ?></span>
      <span>Has Nextgen</span><span><?php var_export($this->has_nextgen); ?></span>

    </div>
  </div>

  <div class='settings'>
    <h3><?php _e('Settings', 'shortpixel'); ?></h3>
    <?php $local = $this->view->data;
      $local->apiKey = strlen($local->apiKey) . ' chars'; ?>
    <pre><?php var_export($local); ?></pre>
  </div>

  <div class='quotadata'>
    <h3><?php _e('Quota Data', 'shortpixel'); ?></h3>
    <pre><?php var_export($this->quotaData); ?></pre>
  </div>
  <div class='debug-quota'>
    <form method="POST" action="<?php echo add_query_arg(array('sp-action' => 'action_debug_resetquota')) ?>"
      id="shortpixel-form-debug-medialib">
      <button class='button' type='submit'>Clear Quota Data</button>
      </form>
  </div>
  <div  class="stats env">
      <h3><?php _e('Stats', 'shortpixel-image-optimiser'); ?></h3>
      <h4>Media</h4>
      <div class='flex'>
        <?php $statsControl = StatsController::getInstance();
        ?>
        <span>Items</span><span><?php echo $statsControl->find('media', 'items'); ?></span>
        <span>Thumbs</span><span><?php echo $statsControl->find('media', 'thumbs'); ?></span>
        <span>Images</span><span><?php echo $statsControl->find('media', 'images'); ?></span>
        <span>ItemsTotal</span><span><?php echo $statsControl->find('media', 'itemsTotal'); ?></span>
        <span>ThumbsTotal</span><span><?php echo $statsControl->find('media', 'thumbsTotal'); ?></span>
     </div>
     <h4>Custom</h4>
     <div class='flex'>
       <span>Custom Optimized</span><span><?php echo $statsControl->find('custom', 'items'); ?></span>
       <span>Custom itemsTotal</span><span><?php echo $statsControl->find('custom', 'itemsTotal'); ?>
       </span>
     </div>
     <h4>Total</h4>
     <div class='flex'>
        <span>Items</span><span><?php echo $statsControl->find('total', 'items'); ?></span>
        <span>Images</span><span><?php echo $statsControl->find('total', 'images'); ?></span>
        <span>Thumbs</span><span><?php echo $statsControl->find('total', 'thumbs'); ?></span>
     </div>
     <h4>Period</h4>
     <div class='flex'>
        <span>Month #1 </span><span><?php echo $statsControl->find('period', 'months', '1'); ?></span>
        <span>Month #2 </span><span><?php echo $statsControl->find('period', 'months', '2'); ?></span>
        <span>Month #3 </span><span><?php echo $statsControl->find('period', 'months', '3'); ?></span>
        <span>Month #4 </span><span><?php echo $statsControl->find('period', 'months', '4'); ?></span>
  </div>


  <div class='debug-stats'>
    <form method="POST" action="<?php echo add_query_arg(array('sp-action' => 'action_debug_resetStats')) ?>"
      id="shortpixel-form-debug-stats">
      <button class='button' type='submit'>Clear statistics cache</button>
      </form>
  </div>

  <?php $noticeController =  NoticeController::getInstance();
    $notices = $noticeController->getNotices();
  ?>

  <h3>Notices (<?php echo count($notices); ?>)</h3>
  <div class='table notices'>

    <div class='head'>
      <span>ID</span><span>Done</span><span>Dismissed</span><span>Persistent</span>
    </div>

  <?php foreach ($notices as $noticeObj): ?>

  <div>
      <span><?php echo $noticeObj->getID(); ?></span>
      <span><?php echo ($noticeObj->isDone()) ? 'Y' : 'N'; ?> </span>
      <span><?php echo ($noticeObj->isDismissed()) ? 'Y' : 'N'; ?> </span>
      <span><?php echo ($noticeObj->isPersistent()) ? 'Y' : 'N'; ?> </span>

  </div>


  <?php endforeach ?>
  </div>

  <div class='debug-notices'>
    <form method="POST" action="<?php echo add_query_arg(array('sp-action' => 'action_debug_resetNotices')) ?>"
      id="shortpixel-form-debug-stats">
      <button class='button' type='submit'>Reset Notices</button>
      </form>
  </div>

  <p>&nbsp;</p>

</div> <!-- tab-content -->
</section>
