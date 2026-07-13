<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Envira Gallery + Soliloquy compatibility shim.
 *
 * Both plugins generate thumbnail variants with non-standard filename
 * suffixes (`_c`, `_tl`, `_tr`, `_br`, `_bl`, plus WP-style
 * dimensions `-{w}x{h}`). SPIO's "unlisted images" scanner defaults
 * to WordPress-native suffixes only, so those extra thumbnails get
 * ignored — the plugin's Envira / Soliloquy gallery admin screens
 * also don't trigger SPIO's per-screen optimisation panel.
 *
 * Two-way integration:
 *   1. `add_screen_loads()` — adds Envira and Soliloquy admin screens
 *      to SPIO's `shortpixel/init/optimize_on_screens` filter so the
 *      per-screen optimisation UI appears there. Unconditional (fires
 *      even without either plugin installed) — the screen slugs are
 *      harmless on other admin pages.
 *   2. `envira_suffixes()` — extends `shortpixel/image/unlisted_suffixes`
 *      with the six extra suffixes above. Only wired when Envira or
 *      Soliloquy is active (via `addConstants()` on `admin_init`).
 *
 * The commented-out `SHORTPIXEL_CUSTOM_THUMB_SUFFIXES` block in
 * `addConstants()` is legacy scaffolding from the pre-filter days
 * when suffixes were configured via a constant. Kept in place as a
 * migration marker; safe to delete when Bas confirms.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
// Image gallery plugins that require a few small extra's
class ImageGalleries
{
  /**
   * Register both integrations. Screen additions happen unconditionally
   * (via `shortpixel/init/optimize_on_screens`); the suffix filter
   * wiring is deferred to `admin_init` because `plugin_active()` needs
   * WordPress to have loaded the plugin list.
   */
  public function __construct()
  {
      add_action('admin_init', array($this, 'addConstants'));
      add_filter('shortpixel/init/optimize_on_screens', array($this, 'add_screen_loads'), 10, 2);
  }

  /**
   * Wire `envira_suffixes` into `shortpixel/image/unlisted_suffixes`
   * when Envira OR Soliloquy is active.
   *
   * @return void
   */
  // This adds constants for mentioned plugins checking for specific suffixes on addUnlistedImages.
	// @integration Envira Gallery
	// @integration Soliloquy
  public function addConstants()
  {
    //if( !defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES')) {


    if (\wpSPIO()->env()->plugin_active('envira') || \wpSPIO()->env()->plugin_active('soliloquy') )
		{

						add_filter('shortpixel/image/unlisted_suffixes', array($this, 'envira_suffixes'));
            //define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', '_c,_tl,_tr,_br,_bl');
    //    }

		// not in use?
    //    elseif(defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIX')) {
    //        define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', SHORTPIXEL_CUSTOM_THUMB_SUFFIX);
    //    }
    }

  }

  /**
   * Add Envira and Soliloquy admin-screen slugs to SPIO's
   * "screens that show the optimisation panel" list. Runs on every
   * admin page load — the extra entries are ignored by SPIO on
   * unrelated screens.
   *
   * @param string[] $screens Current screen-slug list.
   * @param mixed    $screen  Current screen object (unused).
   * @return string[] Original list plus edit-envira, envira, edit-soliloquy, soliloquy.
   */
  public function add_screen_loads($screens, $screen)
  {

     // Envira Gallery Lite
     $screens[] = 'edit-envira';
     $screens[] = 'envira';

     // Soliloquy
     $screens[] = 'edit-soliloquy';
     $screens[] = 'soliloquy';
     return $screens;
  }

  /**
   * Extend the "unlisted image suffixes" list with Envira/Soliloquy's
   * six extra variants:
   *   `_c` (centre), `_tl` / `_tr` / `_br` / `_bl` (corner crops),
   *   plus a `-{w}x{h}` regex for arbitrary dimensioned thumbnails.
   *
   * @param string[] $suffixes Current suffix list.
   * @return string[] Merged list.
   */
	public function envira_suffixes($suffixes)
	{

		 $envira_suffixes = array('_c','_tl','_tr','_br','_bl', '-\d+x\d+');
		 $suffixes = array_merge($suffixes, $envira_suffixes);

		 return $suffixes;
	}



} // class
$c = new ImageGalleries();
