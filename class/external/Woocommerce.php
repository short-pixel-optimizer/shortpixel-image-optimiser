<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * WooCommerce compatibility shim.
 *
 * Two integration concerns:
 *
 *   1. **Product-image regeneration** — WooCommerce lets store owners
 *      regenerate all product thumbnails from Tools → Regenerate.
 *      When it does, WP's `intermediate_image_sizes_advanced` fires
 *      to produce fresh thumbnail files. Those fresh files overwrite
 *      the previously-optimised thumbnails on disk, so SPIO's
 *      optimised-state records for them become stale (a JPEG's
 *      already-optimised flag is now pointing at a re-generated
 *      un-optimised file).
 *
 *      Solution — a signal state machine:
 *        · `signalWC()` flips `$SIGNAL = true` when WC's
 *          `woocommerce_regenerate_images_intermediate_image_sizes`
 *          filter fires (just before regeneration).
 *        · `handleCreateImages()` reads `$SIGNAL` on
 *          `intermediate_image_sizes_advanced` (priority 99, late so
 *          third-party filters get first crack). If the signal is on,
 *          it walks the new size list and calls `onDelete()` on every
 *          thumbnail that's currently marked optimised — dropping the
 *          stale state so the next queue tick re-optimises the
 *          regenerated file. Then flips `$SIGNAL` back to `false`.
 *
 *      Without the signal, `handleCreateImages` would fire on every
 *      normal upload too and drop optimised state indiscriminately.
 *
 *   2. **WC → SPIO advice** — extends WooCommerce's Tools panel with
 *      a note recommending SPIO's auto-optimise setting be turned off
 *      during regeneration (`woocommerce_debug_tools` filter, handled
 *      by `addWarning`).
 *
 * All three hooks are wired only when WC is active — gated behind
 * `plugin_active('woocommerce')` in `hooks()`, which itself is
 * deferred to `plugins_loaded` (in the constructor) so the plugin
 * list is populated when the check runs.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class Woocommerce
{
	//	public function $new_sizes = array();

		/** @var bool Regeneration-in-progress flag. Flipped true by signalWC() on the WC pre-regenerate filter, read by handleCreateImages() on the WP thumbnail-creation filter, reset to false when the work completes. */
		protected static $SIGNAL = false;

		/**
		 * Defer hook wiring to `plugins_loaded` so the WC-active check
		 * has an accurate plugin list to consult.
		 */
		public function __construct()
		{
			 add_action('plugins_loaded', array($this, 'hooks'));
		}

		/**
		 * Wire the three WC integration hooks — only when WC is active.
		 *
		 *   - `woocommerce_regenerate_images_intermediate_image_sizes` → signalWC
		 *   - `woocommerce_debug_tools`                                → addWarning
		 *   - `intermediate_image_sizes_advanced` (priority 99)        → handleCreateImages
		 *
		 * Priority 99 on the third filter is deliberate — we run after
		 * any third-party filter that adjusts the size list, so our
		 * cleanup covers whatever ends up being generated.
		 *
		 * @return void
		 */
		public function hooks()
		{
			if (\wpSPIO()->env()->plugin_active('woocommerce'))
			{
				 add_filter('woocommerce_regenerate_images_intermediate_image_sizes', array($this, 'signalWC'));

				 add_filter('woocommerce_debug_tools', array($this, 'addWarning'));

				 // If new images are created, drop the optimize data of them . Late as possible, this is a hook often used by plugins to refine.
				 add_filter('intermediate_image_sizes_advanced', array($this, 'handleCreateImages'), 99, 3);

			}
		}

		/**
		 * Arm the regeneration signal. Called from WC's pre-regenerate
		 * filter — telling `handleCreateImages()` that the next
		 * `intermediate_image_sizes_advanced` fire is a regeneration
		 * (not a fresh upload) and should drop optimised state.
		 *
		 * @return void
		 */
		// This hook is ran just before create new images / regenerating them. Only then signal to check for optimized thumbs et al.
		public function signalWC()
		{
				self::$SIGNAL = true;
		}

		/**
		 * When the signal is armed, walk the new-size list and drop
		 * SPIO's optimised-state record for every thumbnail that's
		 * about to be re-generated. Then disarm the signal.
		 *
		 * Signal-off (normal upload) → passthrough with no state change.
		 * Empty new-size list        → disarm signal, passthrough.
		 * Item not in DB             → nothing to clean, disarm signal.
		 *
		 * `saveMeta()` is only called when at least one thumbnail's
		 * state was actually mutated (`$changes = true`), so we don't
		 * write an unchanged blob back to the DB on every hook fire.
		 *
		 * @param array $new_sizes  Size-slug → dimensions map that WP is about to generate.
		 * @param array $image_meta Existing attachment metadata (unused).
		 * @param int   $attach_id  Attachment ID.
		 * @return array `$new_sizes` unchanged — this is a filter passthrough with a side effect, not a mutator.
		 */
		/** Hook to run when Wordpress is about to generate new thumbnails.  Remove backup and optimize data if that happens */
		public function handleCreateImages($new_sizes, $image_meta, $attach_id)
		{
				// No signal, no run.
				if (false === self::$SIGNAL)
				{
					 return $new_sizes;
				}

				if (count($new_sizes) === 0)
				{
					 self::$SIGNAL = false;
					 return $new_sizes;
				}
				$fs = \wpSPIO()->filesystem();

				$mediaImage = $fs->getMediaImage($attach_id);
				$changes = false;
				if (is_object($mediaImage))
				{
						// Performance; This item is not in database, hence not optimized in any way.
						if (! is_null($mediaImage->getMeta('databaseID')))
						{

								foreach($new_sizes as $new_size => $data)
								{
										$thumbnailObj = $mediaImage->getThumbNail($new_size);
										if (is_object($thumbnailObj) && $thumbnailObj->isOptimized())
										{
												$thumbnailObj->onDelete();
												$changes = true;
										}
								}
						}
						else {
						}
				}

				if (true === $changes)
				{
					$mediaImage->saveMeta();
				}

				self::$SIGNAL = false;
				return $new_sizes;
		}

		/**
		 * Extend WooCommerce's Tools → Regenerate Thumbnails panel with
		 * a note recommending SPIO's auto-optimise setting be turned
		 * off during regeneration. The note only appears when auto-
		 * process is currently on — nothing to warn about otherwise.
		 *
		 * @param array $tools WC debug-tools map (tool slug → tool config).
		 * @return array Same map, with `regenerate_thumbnails.desc` optionally extended.
		 */
		public function addWarning($tools)
		{
			 if (isset($tools['regenerate_thumbnails']) && \wpSPIO()->env()->is_autoprocess)
			 {
				  $text = $tools['regenerate_thumbnails']['desc'];
					$text .= sprintf(
					'<br><br><strong class="red">%1$s</strong> %2$s',
					__( 'ShortPixel Image Optimizer Note:', 'shortpixel-image-optimiser' ),
					__( 'The ShortPixel Image Optimizer plugin is set to automatically optimize images on upload. When running the thumbnails tools, each image that is not optimized will be added to the queue. It is recommend to disable this option while running these tools', 'shortpixel-image-optimiser')
				);
				$tools['regenerate_thumbnails']['desc'] = $text;
			 }

			 return $tools;
		}


} // class

$w = new Woocommerce();
