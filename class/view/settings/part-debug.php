<?php
namespace ShortPixel;
use ShortPixel\Notices\NoticeController as NoticeController;
use Shortpixel\Controller\StatsController as StatsController;
use Shortpixel\Controller\OptimizeController as OptimizeController;


$opt = new OptimizeController();

$q = $opt->getQueue('media');

$env = \wpSPIO()->env();


?>

<section id="tab-debug" class="<?php echo esc_attr(($this->display_part == 'debug') ? ' sel-tab ' :''); ?>">
  <h2><a class='tab-link' href='javascript:void(0);' data-id="tab-debug">
    <?php esc_html_e('Debug','shortpixel-image-optimiser');?></a>
  </h2>

<div class="wp-shortpixel-options wp-shortpixel-tab-content" style="visibility: hidden">
  <div class='env'>
    <h3><?php esc_html_e('Environment', 'shortpixel'); ?></h3>
    <div class='flex'>
      <span>NGINX</span><span><?php var_export($this->is_nginx); ?></span>
      <span>KeyVerified</span><span><?php var_export($this->is_verifiedkey); ?></span>
      <span>HtAccess writable</span><span><?php var_export($this->is_htaccess_writable); ?></span>
      <span>Multisite</span><span><?php var_export($this->is_multisite); ?></span>
      <span>Main site</span><span><?php var_export($this->is_mainsite); ?></span>
      <span>Constant key</span><span><?php var_export($this->is_constant_key); ?></span>
      <span>Hide Key</span><span><?php var_export($this->hide_api_key); ?></span>
      <span>Has Nextgen</span><span><?php var_export($this->has_nextgen); ?></span>

    </div>
		<div class='flex'>
			<span>GD Installed</span><span><?php var_export($env->is_gd_installed); ?></span>
			<span>Curl Installed</span><span><?php var_export($env->is_curl_installed); ?></span>
		</div>

		<div class='flex'>
				<span>Uploads Base</span><span><?php echo esc_html((defined('SHORTPIXEL_UPLOADS_BASE')) ? SHORTPIXEL_UPLOADS_BASE : 'not defined'); ?></span>
				<span>Uploads Name</span><span><?php echo esc_html((defined('SHORTPIXEL_UPLOADS_NAME')) ? SHORTPIXEL_UPLOADS_NAME : 'not defined'); ?></span>
				<span>Backup Folder</span><span><?php echo esc_html((defined('SHORTPIXEL_BACKUP_FOLDER')) ? SHORTPIXEL_BACKUP_FOLDER : 'not defined'); ?></span>
				<span>Backup URL</span><span><?php echo esc_html((defined('SHORTPIXEL_BACKUP_URL')) ? SHORTPIXEL_BACKUP_URL : 'not defined'); ?></span>



		</div>
  </div>

  <div class='settings'>
    <h3><?php esc_html_e('Settings', 'shortpixel'); ?></h3>
    <?php $local = $this->view->data;
      $local->apiKey = strlen($local->apiKey) . ' chars'; ?>
    <pre><?php var_export($local); ?></pre>
  </div>

  <div class='quotadata'>
    <h3><?php esc_html_e('Quota Data', 'shortpixel'); ?></h3>
    <pre><?php var_export($this->quotaData); ?></pre>
  </div>


  <div class='debug-quota'>
    <form method="POST" action="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_resetquota'))) ?>">

      <button class='button' type='submit'>Clear Quota Data</button>
      </form>
  </div>
  <div class="stats env">
      <h3><?php esc_html_e('Stats', 'shortpixel-image-optimiser'); ?></h3>
      <h4>Media</h4>
      <div class='flex'>
        <?php $statsControl = StatsController::getInstance();
        ?>
        <span>Items</span><span><?php echo esc_html($statsControl->find('media', 'items')); ?></span>
        <span>Thumbs</span><span><?php echo esc_html($statsControl->find('media', 'thumbs')); ?></span>
        <span>Images</span><span><?php echo esc_html($statsControl->find('media', 'images')); ?></span>
        <span>ItemsTotal</span><span><?php echo esc_html($statsControl->find('media', 'itemsTotal')); ?></span>
        <span>ThumbsTotal</span><span><?php echo esc_html($statsControl->find('media', 'thumbsTotal')); ?></span>

     </div>
     <h4>Custom</h4>
     <div class='flex'>
       <span>Custom Optimized</span><span><?php echo esc_html($statsControl->find('custom', 'items')); ?></span>
       <span>Custom itemsTotal</span><span><?php echo esc_html($statsControl->find('custom', 'itemsTotal')); ?>
       </span>
     </div>
     <h4>Total</h4>
     <div class='flex'>
        <span>Items</span><span><?php echo esc_html($statsControl->find('total', 'items')); ?></span>
        <span>Images</span><span><?php echo esc_html($statsControl->find('total', 'images')); ?></span>
        <span>Thumbs</span><span><?php echo esc_html($statsControl->find('total', 'thumbs')); ?></span>
     </div>
     <h4>Period</h4>
     <div class='flex'>
        <span>Month #1 </span><span><?php echo esc_html($statsControl->find('period', 'months', '1')); ?></span>
        <span>Month #2 </span><span><?php echo esc_html($statsControl->find('period', 'months', '2')); ?></span>
        <span>Month #3 </span><span><?php echo esc_html($statsControl->find('period', 'months', '3')); ?></span>
        <span>Month #4 </span><span><?php echo esc_html($statsControl->find('period', 'months', '4')); ?></span>
  	</div>
	</div> <!-- stats -->

  <div class='debug-stats'>
    <form method="POST" action="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_resetStats'))) ?>"
      id="shortpixel-form-debug-stats">
      <button class='button' type='submit'>Clear statistics cache</button>
      </form>
  </div>

  <?php $noticeController =  NoticeController::getInstance();
    $notices = $noticeController->getNotices();
  ?>

  <h3>Notices (<?php echo esc_html(count($notices)); ?>)</h3>
  <div class='table notices'>

    <div class='head'>
      <span>ID</span><span>Done</span><span>Dismissed</span><span>Persistent</span>
    </div>

  <?php foreach ($notices as $noticeObj): ?>

  <div>
      <span><?php echo esc_html($noticeObj->getID()); ?></span>
      <span><?php echo ($noticeObj->isDone()) ? 'Y' : 'N'; ?> </span>
      <span><?php echo ($noticeObj->isDismissed()) ? 'Y' : 'N'; ?> </span>
      <span><?php echo ($noticeObj->isPersistent()) ? 'Y' : 'N'; ?> </span>

  </div>


  <?php endforeach ?>
  </div>

  <div class='debug-notices'>
    <form method="POST" action="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_resetNotices'))) ?>"
      id="shortpixel-form-debug-stats">
      <button class='button' type='submit'>Reset Notices</button>
      </form>
  </div>

  <p>&nbsp;</p>

	<div class='table queue-stats'>
		<?php
			$opt = new OptimizeController();

		 	$statsMedia = $opt->getQueue('media');
			$statsCustom = $opt->getQueue('custom');

			$opt->setBulk(true);

		 	$bulkMedia = $opt->getQueue('media');
			$bulkCustom = $opt->getQueue('custom');

			$queues = array('media' => $statsMedia, 'custom' => $statsCustom, 'mediaBulk' => $bulkMedia, 'customBulk' => $bulkCustom);

			?>
			  <div class='head'>
					<span>Name</span>
					<span>In Queue</span>
					<span>In process</span>
					<span>Errors</span>
					<span>Fatal</span>
					<span>Done</span>
					<span>Total</span>
				</div>
			<?php

			foreach($queues as $name => $queue):
					$stats = $queue->getStats();
					echo "<div>";
						echo "<span>" .  esc_html($name) . '</span>';
						echo "<span>" .  esc_html($stats->in_queue) . '</span>';
						echo "<span>" .  esc_html($stats->in_process) . '</span>';
						echo "<span>" .  esc_html($stats->errors) . '</span>';
						echo "<span>" .  esc_html($stats->fatal_errors) . '</span>';
						echo "<span>" .  esc_html($stats->done) . '</span>';
						echo "<span>" .  esc_html($stats->total) . '</span>';

					echo "</div>";
				?>

			<?php endforeach; ?>

  <div class='debug-queue'>
    <form method="POST" action="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_resetQueue'))) ?>"
      id="shortpixel-form-reset-queue">
      <button class='button' type='submit'>Reset ShortQ</button>
			<select name="queue">
					<option>All</option>
					<?php foreach($queues as $name => $q)
					{
						 echo "<option>" . esc_attr($name) . "</option>";
					}
					?>
			</select>
      </form>
  </div>
</div> <!--- stats -->

<p></p>
<div class='debug-key'>
	<form method="POST" action="<?php echo esc_url(add_query_arg(array('sp-action' => 'action_debug_removeProcessorKey'))) ?>"
		id="shortpixel-form-debug-stats">
		<button class='button' type='submit'>Reset Processor Key</button>
		</form>
</div>

</div> <!-- tab-content -->
</section>
