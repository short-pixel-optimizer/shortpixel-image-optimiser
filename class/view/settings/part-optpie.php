<?php
namespace ShortPixel;


$total_circle = 289.027;
$total =round($view->averageCompression);

if( $total  >0 ) {
		$total_circle = round($total_circle-($total_circle * $total /100));
}

?>

<script>
		jQuery(function() {
		//	jQuery("#sp-total-optimization-dial").val("<?php echo("" . round($view->averageCompression))?>");
		//		ShortPixel.percentDial("#sp-total-optimization-dial", 100);
		});
</script>


<?php if ( round($view->averageCompression) > 20): ?>
	<div class="sp-bulk-summary">
			<span><?php _e('Average optimization', 'shortpixel-image-optimiser'); ?></span>
<!--			<input type="text" value="<?php echo("" . round($view->averageCompression))?>" id="sp-total-optimization-dial" class="dial"> -->
			<svg class="opt-circle-average" viewBox="-10 0 150 140">
										<path class="trail" d="
												M 50,50
												m 0,-46
												a 46,46 0 1 1 0,92
												a 46,46 0 1 1 0,-92
												" stroke-width="16" fill-opacity="0">
										</path>
										<path class="path" d="
												M 50,50
												m 0,-46
												a 46,46 0 1 1 0,92
												a 46,46 0 1 1 0,-92
												" stroke-width="16" fill-opacity="0" style="stroke-dasharray: 289.027px, 289.027px; stroke-dashoffset: <?php echo $total_circle ?>">
										</path>
										<text class="text" x="50" y="50"><?php echo $total ?>%</text>
								</svg>

	</div>
<?php endif; ?>
