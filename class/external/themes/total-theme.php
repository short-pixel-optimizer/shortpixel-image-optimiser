<?php
namespace ShortPixel\External\Themes;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Total theme compatibility shim.
 *
 * The Total WordPress theme (WPExplorer) resizes / re-generates
 * thumbnail variants at runtime and fires
 * `totaltheme/resize-image/after_save_image` when it does. When that
 * happens, SPIO's previously-optimised data for that specific
 * thumbnail size is stale — the file on disk has been overwritten by
 * Total, so we need to drop the optimised state and let the next
 * queue tick re-optimise it.
 *
 * Namespaced under `ShortPixel\External\Themes` (unlike every other
 * external file which lives directly under `ShortPixel\`). Not
 * changed here — matches the folder path `class/external/themes/`.
 *
 * Self-boots at file-load time (no singleton wrapper).
 *
 * @package ShortPixel\External\Themes
 */
class TotalTheme
{

  /**
   * Register the Total resize-completed hook. No plugin/theme gate —
   * the action only fires when Total is the active theme AND its
   * runtime resize path executes.
   */
  public function __construct()
  {
//    do_action( 'totaltheme/resize-image/after_save_image', $attachment, $intermediate_size );
    add_action( 'totaltheme/resize-image/after_save_image', array($this, 'resizeImage'), 10, 2);
  }

  /**
   * Drop the optimised state for one specific thumbnail size after
   * Total has re-generated the file on disk.
   *
   * `onDelete(true)` removes the thumbnail's optimised record;
   * `saveMeta()` persists the change so the next queue tick sees the
   * variant as un-optimised again.
   *
   * @param int    $attachment_id Parent attachment ID.
   * @param string $size          Thumbnail size slug that Total just re-saved.
   * @return void
   */
  public function resizeImage($attachment_id, $size)
  {
    $image = \wpSPIO()->filesystem()->getMediaImage($attachment_id);

    if (! is_object($image))
    {
      return;
    }

    $changes = false;
    $thumbObj = $image->getThumbnail($size);
    if (is_object($thumbObj))
    {
      $thumbObj->onDelete(true);
      $changes = true;
    }
    else {
    }

    if ( true === $changes)
    {
      $image->saveMeta();
    }

}

} // class

$t = new TotalTheme();
