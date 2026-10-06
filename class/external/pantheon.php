<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Pantheon host compatibility shim.
 *
 * Pantheon's edge cache holds image responses at their global CDN
 * PoPs. When SPIO finishes optimising an image, those PoPs still
 * serve the pre-optimised bytes until the cache expires or is
 * explicitly cleared. This class:
 *
 *   1. Hooks `shortpixel/image/optimised` and fires
 *      `pantheon_wp_clear_edge_paths()` with the list of URLs
 *      touched by the optimisation (main + original + every
 *      thumbnail, converted to relative paths).
 *   2. Forces `SHORTPIXEL_TRUSTED_MODE` on because Pantheon's
 *      filesystem is read-only outside `wp-content/uploads`; SPIO's
 *      normal "verify filesystem" checks would fail on this host.
 *   3. Exposes `Pantheon::IsActive()` as a static getter that other
 *      SPIO code checks to route around read-only filesystem
 *      assumptions.
 *
 * Class instantiation is gated at file-load time on the
 * `$_ENV['PANTHEON_ENVIRONMENT']` — set by Pantheon on every request
 * running on their infrastructure. On non-Pantheon installs the class
 * is defined but never instantiated, so no hooks are registered and
 * `IsActive()` stays false.
 */
class Pantheon {

	/** @var bool True once the Pantheon self-boot instantiates. Read via IsActive(). */
	public static $is_pantheon = false;

	/**
	 * Register the edge-flush hook, force trusted mode, and mark
	 * Pantheon as active for other SPIO subsystems.
	 */
	public function __construct()
	{
		add_action( 'shortpixel/image/optimised', array( $this, 'flush_image_caches' ), 10 );
		if (! defined('SHORTPIXEL_TRUSTED_MODE'))
		{
			 define('SHORTPIXEL_TRUSTED_MODE', true);
		}

		self::$is_pantheon = true;
	}

	/**
	 * Report whether the Pantheon shim has instantiated (i.e., whether
	 * we're running on Pantheon's infrastructure).
	 *
	 * @return bool True on Pantheon, false everywhere else.
	 */
	public static function IsActive()
	{
		 return self::$is_pantheon;
	}

	/**
	 * Build a de-duplicated list of relative paths for the optimised
	 * image (main + original + thumbnails), then fire
	 * `pantheon_wp_clear_edge_paths()` to invalidate them at the edge.
	 *
	 * URL → path conversion strips `get_site_url()` from every entry
	 * so Pantheon's edge sees relative paths (which is what its
	 * `pantheon_wp_clear_edge_paths` API expects).
	 *
	 * The `pantheon_wp_clear_edge_paths` function-exists guard is
	 * defensive — the class only instantiates on Pantheon, but the
	 * mu-plugin that provides the function has occasionally been
	 * disabled during maintenance.
	 *
	 * @param object $imageItem Optimised item — must respond to `getURL()`, `hasOriginal()`, `getOriginalFile()`, `get('thumbnails')`.
	 * @return void
	 */
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

// Only self-boot on Pantheon. `PANTHEON_ENVIRONMENT` is set by
// Pantheon's platform on every request — its presence is a reliable
// "we're running on Pantheon" signal.
if ( ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) ) {
	$p = new Pantheon();  // monitor hook.
}
