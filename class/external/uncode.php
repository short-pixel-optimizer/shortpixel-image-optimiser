<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Uncode theme compatibility shim.
 *
 * Uncode does its own crop/regenerate lifecycle for image variants,
 * bypassing WordPress's `image_downsize` path. When Uncode:
 *
 *   1. **Deletes a crop** (`uncode_delete_crop_image`) — SPIO's
 *      optimised WebP/AVIF variants for that crop, plus its backup,
 *      would otherwise be orphaned on disk. `removedMetaData()`
 *      cleans them up by direct filesystem operations (bypassing the
 *      ImageModel path because Uncode fires the hook AFTER the
 *      metadata is already gone — the model can't reconstruct the
 *      variant list at that point).
 *
 *   2. **Creates a new crop** (`uncode_after_new_crop`) — the new
 *      variant needs to be optimised. `after_new_crop()` adds the
 *      parent MediaLibrary image back to the queue if it's still
 *      processable.
 *
 * NOTE: `removedMetaData()`'s inline comment ("Just rough n dirty
 * here") acknowledges the bypass. It's the correct approach given
 * Uncode's hook ordering, but leaves cleanup responsibility on this
 * class rather than the normal ImageModel cascade.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class UncodeController
{
	 /**
	  * Wire both Uncode hooks immediately — no plugin-active gate
	  * because these hooks only fire if Uncode itself is present.
	  */
	 function __construct()
	 {
		  $this->addHooks();
	 }

	 /**
	  * Register the two Uncode integration points.
	  *
	  * @return void
	  */
	 protected function addHooks()
	 {
	    add_action('uncode_delete_crop_image', array($this, 'removedMetaData'), 10, 2);
      	add_action( 'uncode_after_new_crop', array($this, 'after_new_crop'), 10, 5 );
	 }

	 /**
	  * Delete SPIO's WebP/AVIF variants + backup for a crop that
	  * Uncode is about to remove.
	  *
	  * Bypasses the ImageModel/ThumbnailModel path because Uncode
	  * fires this action AFTER it has already deleted its own
	  * metadata — by the time we're called, ImageModel can't
	  * reconstruct which variants belonged to the crop. So we
	  * derive the variant paths from the file's own name (same
	  * directory, same base, `.webp` / `.avif` suffixes) and the
	  * backup path from `getBackupDirectory()`.
	  *
	  * @param int    $attach_id Parent attachment ID (unused — see bypass note above).
	  * @param string $filePath  Absolute path of the crop file Uncode is deleting.
	  * @return void
	  */
	 public function removedMetaData($attach_id, $filePath)
	 {
		  	$fs = \wpSPIO()->filesystem();
				//$imageObj = $fs->getImage($attach_id, 'media', false);

//				$imageObj->saveMeta();


				// We can't do this via the usual methods, because the filter is deleted before the filter hits, thus not loading in the Models anymore
				// Just rough n dirty here.

				$fileObj = $fs->getFile($filePath);

				$avifFile = $fs->getFile($fileObj->getFileDir() . $fileObj->getFileBase() . '.avif');
				$webpFile = $fs->getFile($fileObj->getFileDir() . $fileObj->getFileBase() . '.webp');
				$backupFile = $fs->getFile($fs->getBackupDirectory($fileObj, true) . $fileObj->getFileName());

				if ($avifFile->exists())
				{
					$avifFile->delete();
				}
				if ($webpFile->exists())
				{
				    $webpFile->delete();
				}
				if ($backupFile->exists())
				{
					$backupFile->delete();
				}

	 }

   /**
    * Re-queue the parent image after Uncode creates a new crop, so
    * SPIO optimises the new variant on the next queue tick.
    *
    * `isProcessable()` guards against re-queueing already-excluded or
    * previously-errored items.
    *
    * @param int    $media_id       ID of the main full image.
    * @param string $url            URL of the new crop (unused).
    * @param int    $width          Crop width (unused).
    * @param int    $height         Crop height (unused).
    * @param string $attachment_key Key of the crop in attachment_meta (unused).
    * @return void
    */
   public function after_new_crop( $media_id, $url, $width, $height,  $attachment_key ) {
      // $media_id       - ID of the main full image
    	// $url            - URL of the crop
    	// $width          - Width of the crop
    	// $height         - Height of the crop
    	// $attachment_key - Key of the crop in attachment_meta

      $fs = \wpSPIO()->filesystem();
      $mediaItem = $fs->getImage($media_id, 'media');
      if (is_object($mediaItem) && $mediaItem->isProcessable())
      {
          $control = new \Shortpixel\Controller\QueueController();
          $control->addItemToQueue($mediaItem);
      }

    }
} // class

$u = new UncodeController();
