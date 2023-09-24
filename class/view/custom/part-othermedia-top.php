<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
?>
<div class="wrap shortpixel-other-media">
    <h2>
        <?php esc_html_e($view->title);?>
    </h2>

    <div class='toolbar'>
        <div>
          <?php
          $nonce = wp_create_nonce('refresh_folders');
          ?>
            <a href="<?php echo esc_url(admin_url('upload.php?page=wp-short-pixel-custom&sp-action=action_refreshfolders&_wpnonce=' . $nonce)); ?>" id="refresh" class="button button-primary" title="<?php esc_attr_e('Refresh custom folders content','shortpixel-image-optimiser');?>">
                <?php esc_attr_e('Refresh folders','shortpixel-image-optimiser');?>
            </a>
        </div>
			<hr class='wp-header-end' />

<?php if (property_exists($view, 'show_search') && true === $view->show_search):  ?>
      <div class="searchbox">
            <form method="get">
                <input type="hidden" name="page" value="wp-short-pixel-custom" />
                <input type='hidden' name='order' value="<?php echo esc_attr($this->order) ?>" />
                <input type="hidden" name="orderby" value="<?php echo esc_attr($this->orderby) ?>" />

                <p class="search-form">
                  <label><?php esc_html_e('Search', 'shortpixel-image-optimiser'); ?></label>
                  <input type="text" name="s" value="<?php echo esc_attr($this->search) ?>" />

                </p>

            </form>
      </div>
  </div>
<?php endif;  ?>

<?php if ($this->view->pagination !== false): ?>
  <div class='pagination tablenav'>
			<div class="view_switch">
				<?php if ($this->has_hidden_items):

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
<?php endif; ?>

<?php
$file_url =  esc_url(add_query_arg('part', 'files', $this->url));
$folder_url = esc_url(add_query_arg('part', 'folders', $this->url));
$scan_url = esc_url(add_query_arg('part', 'scan', $this->url));

$current_part = isset($_GET['part']) ? sanitize_text_field($_GET['part']) : 'files';

$tabs = array(
	'files' => array('link' => $file_url,
									 'text' => __('Files', 'shortpixel-image-optimiser'),
								 ),
	 'folders' => array('link' => $folder_url,
	 										'text' => __('Folders', 'shortpixel-image-optimiser'),
 								),
		'scan' => array('link' => $scan_url,
										'text' => __('Scan', 'shortpixel-image-optimiser'),

	),
);

?>

<div class="custom-media-tabs">
		<?php foreach($tabs as $tabName => $tab)
		{
				$class = ($current_part == $tabName) ? ' class="selected" ' : '';

				echo '<a href="' . $tab['link'] . '" ' . $class . '>' . $tab['text'] . '</a>';
		} ?>
</div>
