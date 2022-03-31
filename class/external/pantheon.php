namespace ShortPixel;


class Pantheon {

	public function __construct() 
	{
		add_action( 'shortpixel_image_optimised', array( $this, 'flush_image_caches' ) );
	}

	public function flush_image_caches( $id ) 
	{
		$image_sizes = get_intermediate_image_sizes();
		$image_paths = [];
		$domain      = get_site_url();
		foreach ( $image_sizes as $size ) {
			$image_array = wp_get_attachment_image_src( $id, $size );
			if ( $image_array ) {
				/**
				 *  $0 Image source URL.
				 *  We need absolute URI without domain
				 *
				 * @see wp_get_attachment_image_src
				 */
				$image_paths[] = str_replace( $domain, '', $image_array[0] );
			}
		}

		if ( ! empty( $image_paths ) ) {
			$image_paths = array_unique( $image_paths );
			if ( function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
				// Do the flush
				pantheon_wp_clear_edge_paths( $image_paths );
			}
		}
	}
}

if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
	$p = new Pantheon();  // monitor hook.
}
