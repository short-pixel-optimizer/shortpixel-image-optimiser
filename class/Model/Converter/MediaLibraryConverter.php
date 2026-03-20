<?php

namespace ShortPixel\Model\Converter;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\Replacer\Replacer as Replacer;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/* Abstract base to use for image converters. Handles media library related functions ( replacing )  */

/**
 * Abstract converter base that handles WordPress Media Library integration.
 *
 * Manages URL replacement (via Replacer) and WordPress attachment metadata
 * updates when a file is converted to a different format.
 *
 * @package ShortPixel\Model\Converter
 */
abstract class MediaLibraryConverter extends Converter
{
	/** @var string|null Source URL of the original image, used by the replacer. */
	protected $source_url;

	/** @var Replacer|null Replacer instance used to update URLs across the database. */
	protected $replacer; // Replacer class Object.

	/** @var FileModel|null The replacement file object produced by the conversion. */
	protected $newFile; // The newFile Object.

	/**
	 * Retrieves the latest WordPress attachment metadata for the image being converted.
	 *
	 * @return array WordPress attachment metadata array.
	 */
	public function getUpdatedMeta()
	{
		$id = $this->imageModel->get('id');
		$meta = wp_get_attachment_metadata($id); // reset the metadata because we are on the hook.
		return $meta;
	}

	/**
	 * Initialises the Replacer instance with the source URL of the current image,
	 * accounting for scaled originals and attaching the existing WP metadata.
	 *
	 * @return void
	 */
	protected function setupReplacer()
	{
		$this->replacer = new Replacer();
		$fs = \wpSPIO()->filesystem();

		$url = $fs->pathToUrl($this->imageModel);

		if ($this->imageModel->isScaled()) // @todo Test these assumptions
		{
			$url = $fs->pathToUrl($this->imageModel->getOriginalFile());
		}

		$this->source_url = $url;
		$this->replacer->setSource($url);

		$this->replacer->setSourceMeta($this->imageModel->getWPMetaData());
	}

	/**
	 * Sets the target replacement file on the replacer, calculating the new URL
	 * by substituting the original filename with the new one.
	 *
	 * @param FileModel $newFile The destination file object after conversion.
	 * @return void
	 */
	protected function setTarget($newFile)
	{
		$fs = \wpSPIO()->filesystem();
		$this->newFile = $newFile; // set target newFile.

		$url = $fs->pathToUrl($this->imageModel);
		$newUrl = str_replace($this->imageModel->getFileName(), $newFile->getFileName(), $url);

		$this->replacer->setTarget($newUrl);
	}

	/**
	 * Updates all WordPress attachment records after a successful conversion or restore.
	 *
	 * Handles: updating the attached file path, post MIME type and GUID, regenerating
	 * (or patching) attachment metadata, and propagating changes to WPML duplicates.
	 *
	 * @param array $params {
	 *     Conversion parameters.
	 *     @type bool $success           True when the conversion succeeded.
	 *     @type bool $restore           True when restoring to the original format.
	 *     @type bool $generate_metadata Whether to regenerate WP attachment metadata.
	 * }
	 * @return bool|void False on failure, void on success.
	 */
	protected function updateMetaData($params)
	{
		$defaults = array(
			'success' => false,
			'restore' => false,
			'generate_metadata' => true,
		);

		$params = wp_parse_args($params, $defaults);

		$newFile = $this->newFile;
		//		$fullPath = $newFile->getFullPath();

		if (! is_object($newFile)) {
			Log::addError('Update metadata failed. NewFile not properly set', $newFile);
			return false;
		}

		$attach_id = $this->imageModel->get('id');

		$WPMLduplicates = $this->imageModel->getWPMLDuplicates();

		$attachment = get_post($attach_id);

		$guid = $attachment->guid;

		// This action prevents images from being regenerated on the thumbnail hook.
		do_action('shortpixel-thumbnails-before-regenerate', $attach_id);
		do_action('shortpixel/converter/prevent-offload', $attach_id);

		// Update attached_file
		$bool = update_attached_file($attach_id, $newFile->getFullPath());
		if (false === $bool)
			return false;


		// Update post mime on attachment
		if (isset($params['success']) && true === $params['success']) {
			$fromExt = $this->imageModel->getMeta()->convertMeta()->getFileFormat();
			$toExt = 'jpg';
			$newGuid = str_replace($fromExt, $toExt, $guid); // This probable doesn't work bcause doesn't update Guid with this function.
			$post_ar = array('ID' => $attach_id, 'post_mime_type' => 'image/jpeg', 'guid' => $newGuid);
		} elseif (isset($params['restore']) && true === $params['restore']) {
			$fromExt = 'jpg';
			$toExt = $this->imageModel->getMeta()->convertMeta()->getFileFormat();
			$newGuid = str_replace($fromExt, $toExt, $guid);
			$post_ar = array('ID' => $attach_id, 'post_mime_type' => 'image/' . $toExt, 'guid' => $newGuid);
		}

		$result = wp_update_post($post_ar);

		if ($result === 0 || is_wp_error($result)) {
			Log::addError('Issue updating WP Post converter - ' . $attach_id);
			return false;
		}

		$metadata = wp_get_attachment_metadata($attach_id);


		if (true === $params['generate_metadata']) {
			$attachment = get_post($attach_id);
			$new_metadata = wp_generate_attachment_metadata($attach_id, $newFile->getFullPath());
		} else { // when not regenarting the metadata, ie bmp
			$file = $metadata['file'];
			$replace = str_replace($fromExt, $toExt, $file);
			$new_metadata = array('file' => $replace);
		}

		// Metadata might not be array when add_attachment is calling this hook via AdminController ( PNG2JPG)
		if (is_array($metadata)) {
			// Original Image in the new situation can not be there. Don't preserve it.
			if (isset($metadata['original_image']) && ! isset($new_metadata['original_image'])) {
				unset($metadata['original_image']);
			}

			$new_metadata = array_merge($metadata, $new_metadata); // merge to preserve other custom metadata

		}

		if (isset($params['success']) && true === $params['success']) {
			do_action('shortpixel/converter/prevent-offload-off', $attach_id);
		}

		if (is_array($new_metadata) && count($new_metadata) > 0) {
			$bool = wp_update_attachment_metadata($attach_id, $new_metadata);
		}

		// Restore -sigh- fires off a later signal, because on the succesHandler in MediaLIbraryModel it may copy back backups.
		if (isset($params['restore']) && true === $params['restore']) {
			do_action('shortpixel/converter/prevent-offload-off', $attach_id);
		}

		if (is_array($WPMLduplicates) && count($WPMLduplicates) > 0) {
			foreach ($WPMLduplicates as $duplicate_id) {
				update_attached_file($duplicate_id, $newFile->getFullPath());
				wp_update_attachment_metadata($duplicate_id, $new_metadata);

				$post_ar["ID"]  = $duplicate_id;
				wp_update_post($post_ar);
			}
		}

		$this->replacer->setTargetMeta($new_metadata);
	}
} // class
