<?php

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

/**
 * Visual Composer (WPBakery) compatibility shim.
 *
 * When VC is in its "inline editing" mode, it re-renders posts through
 * its own AJAX pipeline. SPIO's auto-media-library scanner interferes
 * with that rendering (duplicate optimisation queue entries + spurious
 * work during editing), so we short-circuit it whenever `vc_action()`
 * reports `vc_inline`. Any other VC action (or no VC at all) → the
 * default `$bool` is passed through unchanged.
 *
 * NOTE: this file lives OUTSIDE the `ShortPixel` namespace (unlike
 * every other file in `class/external/`). The class is declared at
 * global scope as `visualComp`. Not changed here to avoid breaking any
 * external code that references the global name.
 *
 * Self-boots at file-load time (`new visualComp()` at the bottom) —
 * same rationale as `cacheRemover` / `CloudFlareAPI`. No singleton
 * wrapper, so a double-require would double-register the filter; the
 * autoloader manifest guarantees single-load.
 */
class visualComp
{

  /**
   * Register the auto-library veto filter. VC doesn't need to be
   * detected here — the filter callback checks at call time.
   */
  public function __construct()
  {
     add_filter('shortpixel/init/automedialibrary', array($this, 'check_vcinline'));
  }

  /**
   * Veto SPIO's auto-media-library scanning when Visual Composer is
   * in inline-edit mode.
   *
   * @param bool $bool Incoming filter default (usually true — meaning "allow the scan").
   * @return bool `false` when VC is in `vc_inline` mode, otherwise `$bool` unchanged.
   */
  // autolibrary should not do things when VC is being inline somewhere.
  public function check_vcinline($bool)
  {
      if ( function_exists( 'vc_action' ) && vc_action() == 'vc_inline' )
        return false;
      else
        return $bool;
  }

} // Class

$vc = new visualComp();
