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

  <div class='pagination tablenav'>

			<?php if ($this->view->pagination !== false): ?>
	      <div class='tablenav-pages'>
	        <?php echo $this->view->pagination; ?>
	    	</div>
			<?php endif; ?>
  </div>

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
