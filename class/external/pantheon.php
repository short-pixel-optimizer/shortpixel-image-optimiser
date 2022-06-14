<?php
namespace ShortPixel;

class Pantheon {

	public function __construct()
	{
		add_action( 'shortpixel/image/optimised', array( $this, 'flush_image_caches' ), 10 );
	}

	public function flush_image_caches( $imageItem )
	{

    $image_paths[] = $imageItem->getURL();

		if ($imageItem->hasOriginal())
		{
			 $image_paths[] = $imageItem->getOriginalFile()->getURL();
		}

    if (count($imageItem->get('thumbnails')) > 0)
    {
       foreach($imageItem->get('thumbnails') as $thumbObj)
       {
           $image_paths[] = $thumbObj->getURL();
       }
    }

    $domain      = get_site_url();
    $image_paths = array_map(function($path) use ($domain)
    {
       return str_replace( $domain, '', $path);
    },$image_paths);

		if ( ! empty( $image_paths ) ) {
			$image_paths = array_unique( $image_paths );
			if ( function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
				// Do the flush
				pantheon_wp_clear_edge_paths( $image_paths );
			}
		}
  }
} // class

if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
	$p = new Pantheon();  // monitor hook.
}
