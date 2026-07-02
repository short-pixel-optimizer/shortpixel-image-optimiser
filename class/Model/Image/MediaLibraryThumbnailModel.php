<?php

namespace ShortPixel\Model\Image;

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Helper\DownloadHelper as DownloadHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;


if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use \ShortPixel\Model\File\FileModel as FileModel;

/**
 * Represents a single thumbnail (or retina variant) of a Media Library attachment.
 *
 * A thumbnail belongs to a parent attachment (identified by $id) and to a named
 * WordPress size (`thumbnail`, `medium`, `large`, custom sizes, or the special
 * `original` slot). Its metadata lives on the parent attachment; loadMeta() /
 * saveMeta() are no-ops here because MediaLibraryModel owns the persistence
 * for the whole family.
 *
 * @package ShortPixel\Model\Image
 */
class MediaLibraryThumbnailModel extends \ShortPixel\Model\Image\ImageModel
{

	/** @var string|null WordPress size name for this thumbnail (e.g. 'thumbnail', 'medium'). */
	public $name;

	/** @var string|false Reason set by preventNextTry(), or false when not prevented. */
	protected $prevent_next_try = false;

	/** @var bool True when this instance represents the main attachment file rather than a real thumbnail. */
	protected $is_main_file = false;

	/** @var bool True when this instance is a retina (@2x) variant. */
	protected $is_retina = false;

	/** @var int The parent attachment ID. */
	protected $id;

	/** @var string Size name of the thumbnail in WordPress terms; may be 'original' for the unscaled companion. */
	protected $size;

	/** @var array|null WordPress size definition (width / height / crop) for this thumbnail. */
	protected $sizeDefinition;

	/** @var \ShortPixel\Model\Backup\BackupModel|null Cached backup model for this thumbnail. */
	protected $backupModel;


	/** @var string Model type marker used by BackupController / QueueController routing. */
	protected $type = 'media';


	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path to the thumbnail file on disk.
	 * @param int    $id   Parent attachment ID.
	 * @param string $size WordPress size name for this thumbnail.
	 */
	public function __construct($path, $id, $size)
	{

		parent::__construct($path);
		$this->image_meta = new ImageThumbnailMeta();
		$this->id = $id;
		$this->imageType = self::IMAGE_TYPE_THUMB;
		$this->size = $size;
	}


	/**
	 * No-op. Thumbnail meta is loaded by the owning MediaLibraryModel and
	 * assigned via setMetaObj(); there is no per-thumbnail persistence.
	 *
	 * @return void
	 */
	protected function loadMeta() {}

	/**
	 * No-op. Persistence is handled by MediaLibraryModel::saveMeta() for the
	 * whole attachment family in a single write.
	 *
	 * @return void
	 */
	protected function saveMeta() {}

	/**
	 * Provide a compact representation for var_dump()/debug output that omits
	 * the noisy parent FileModel internals.
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo()
	{
		return array(
			'image_meta' => $this->image_meta,
			'name' => $this->name,
			'path' => $this->getFullPath(),
			'size' => $this->size,
			'width' => $this->get('width'),
			'height' => $this->get('height'),
			'exists' => ($this->exists()) ? 'yes' : 'no',
			'is_virtual' => ($this->is_virtual()) ? 'yes' : 'no',
			'wordpress_size' => $this->sizeDefinition,

		);
	}

	/**
	 * Assign the WordPress size name for this thumbnail (e.g. 'thumbnail', 'medium').
	 *
	 * @param string $name Size slug.
	 * @return void
	 */
	public function setName($name)
	{
		$this->name = $name;
	}

	/**
	 * Assign the WordPress size definition (width / height / crop flag).
	 *
	 * @param array $sizedef WordPress-shape size definition.
	 * @return void
	 */
	public function setSizeDefinition($sizedef)
	{
		$this->sizeDefinition = $sizedef;
	}

	/**
	 * Override the imageType constant assigned by the constructor
	 * (used to promote a thumbnail into an IMAGE_TYPE_ORIGINAL or
	 * IMAGE_TYPE_RETINA variant).
	 *
	 * @param int $type One of the ImageModel::IMAGE_TYPE_* constants.
	 * @return void
	 */
	public function setImageType($type)
	{
		$this->imageType = $type;
	}

	/**
	 * Locate the retina (@2x) companion of this thumbnail on disk and return
	 * it as a MediaLibraryThumbnailModel, or false when none exists.
	 *
	 * Handles virtual (remote / stateless) sources: for those, the retina
	 * lookup only runs when the environment allows heavy virtual functions,
	 * because it requires translating the virtual path.
	 *
	 * @return MediaLibraryThumbnailModel|false
	 */
	public function getRetina()
	{
		if ($this->is_virtual()) {
			// This function needs an hard check on file exists, which might not be wanted. 
			// Moved - Why invoke the translate, if it's going to be false anyhow?
			if (false === \wpSPIO()->env()->useVirtualHeavyFunctions()) {
				return false;
			}
			$fs = \wpSPIO()->filesystem();
			$filepath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);
			$virtualFile = $fs->getFile($filepath);

			$filebase = $virtualFile->getFileBase();
			$filepath = (string) $virtualFile->getFileDir();
			$extension = $virtualFile->getExtension();


		} else {
			$filebase = $this->getFileBase();
			$filepath = (string) $this->getFileDir();
			$extension = $this->getExtension();
		}

		$retina = new MediaLibraryThumbnailModel($filepath . $filebase . '@2x.' . $extension, $this->id, $this->size); // mind the dot in after 2x
		$retina->setName($this->size);
		$retina->setImageType(self::IMAGE_TYPE_RETINA);

		$retina->is_retina = true;

		$forceCheck = true;
		if ($retina->exists($forceCheck))
			return $retina;

		return false;
	}

	/**
	 * Whether a WebP or AVIF companion still needs to be generated for this
	 * thumbnail.
	 *
	 * PDFs are always excluded. Otherwise the answer is yes when the thumbnail
	 * is processable (or already optimized) and no companion file exists yet.
	 *
	 * @param string $type Either 'webp' or 'avif'.
	 * @return bool
	 */
	public function isFileTypeNeeded($type = 'webp')
	{
		// pdf extension can be optimized, but don't come with these filetypes
		if ($this->getExtension() == 'pdf') {
			return false;
		}

		if ($type == 'webp')
			$file = $this->getWebp();
		elseif ($type == 'avif')
			$file = $this->getAvif();

		if (($this->isThumbnailProcessable() || $this->isOptimized()) && $file === false)  // if no file, it can be optimized.
			return true;
		else
			return false;
	}

	/**
	 * Handle deletion of this thumbnail.
	 *
	 * Optionally skips the physical file delete (multilingual duplicate flows
	 * only want to reset the metadata but keep the file), then resets
	 * image_meta to a fresh baseline appropriate for main-file vs. thumbnail.
	 *
	 * @param bool $fileDelete When false, skip parent::onDelete() and keep the file on disk.
	 * @return bool Result of parent::onDelete() (or true when skipped).
	 */
	public function onDelete($fileDelete = true)
	{
		if ($fileDelete == true) {
			$bool = parent::onDelete();
		} else {
			$bool = true;
		}

		// minimally reset all the metadata.
		if ($this->is_main_file) {
			$this->image_meta = new ImageMeta();
		} else {
			$this->image_meta = new ImageThumbnailMeta();
		}

		return $bool;
	}

	/**
	 * Assign the image_meta object from the parent MediaLibraryModel.
	 *
	 * Cloned so mutations on the thumbnail do not leak back into the owning
	 * attachment's meta before saveMeta() runs.
	 *
	 * @param ImageThumbnailMeta $metaObj Meta object to adopt.
	 * @return void
	 */
	protected function setMetaObj($metaObj)
	{
		$this->image_meta = clone $metaObj;
	}

	/**
	 * Return this thumbnail's image_meta object (for MediaLibraryModel to
	 * merge back into the attachment's meta).
	 *
	 * @return ImageThumbnailMeta
	 */
	protected function getMetaObj()
	{
		return $this->image_meta;
	}

	/**
	 * Return this thumbnail's URL for the ShortPixel API.
	 *
	 * NOTE: Currently unused; the parent MediaLibraryModel builds a single
	 * combined optimize-URL payload for the whole attachment family.
	 *
	 * @return string|false URL, or false when not processable.
	 */
	public function getOptimizeUrls()
	{
		if (! $this->isProcessable())
			return false;

		$url = $this->getURL();

		if (! $url) {
			return false; //nothing
		}

		return $url;
	}

	/**
	 * Resolve the public URL of this thumbnail.
	 *
	 * The 'original' size uses wp_get_original_image_url(). Unlisted (extra)
	 * thumbnails resolve via pathToUrl(). Standard thumbnails try
	 * image_get_intermediate_size() first, then fall back to reconstructing
	 * the URL from the main attachment URL when third-party filters (e.g.
	 * WooCommerce) rewrite it. The final URL is passed through checkURL()
	 * for normalization.
	 *
	 * @return string|false Public URL, or false when it cannot be resolved.
	 */
	public function getURL()
	{
		$fs = \wpSPIO()->filesystem();

		if ($this->size == 'original' && ! $this->get('is_retina')) {
			$url = wp_get_original_image_url($this->id);
		} elseif ($this->isUnlisted()) {
			$url = $fs->pathToUrl($this);
		} else {
			// We can't trust higher lever function, or any WP functions.  I.e. Woocommerce messes with the URL's if they like so.
			// So get it from intermediate and if that doesn't work, default to pathToUrl - better than nothing.
			// https://app.asana.com/0/1200110778640816/1202589533659780
			$size_array = image_get_intermediate_size($this->id, $this->size);

			if ($size_array === false || ! isset($size_array['url'])) {
				$url = $fs->pathToUrl($this);
			} elseif (isset($size_array['url'])) {
				$url = $size_array['url'];
				// Even this can go wrong :/
				if (strpos($url, $this->getFileName()) === false) {
					// Taken from image_get_intermediate_size if somebody still messes with the filters.
					$mainurl = wp_get_attachment_url($this->id);
					$url = path_join(dirname($mainurl), $this->getFileName());
				}
			} else {
				return false;
			}
		}

		return $this->fs()->checkURL($url);
	}

	/**
	 * Placeholder implementation of the abstract getImprovements().
	 *
	 * Thumbnails do not carry their own per-thumbnail improvement array;
	 * this simply delegates to the parent's (equally-unused) implementation.
	 *
	 * @return mixed
	 */
	public function getImprovements()
	{
		return parent::getImprovements();
	}

	/*
	public function getBackupFileName()
	{
		$mainFile = ($this->is_main_file) ? $this : $this->getMainFile();
		if (false == $mainFile) {
			return parent::getBackupFileName();
		}

		if ($mainFile->getMeta()->convertMeta()->getReplacementImageBase() !== false) {
			if ($this->is_main_file)
				return $mainFile->getMeta()->convertMeta()->getReplacementImageBase() . '.' . $this->getExtension();
			else {
				//					 $fileBaseNoSize =
				$name = str_replace($mainFile->getFileBase(), $mainFile->getMeta()->convertMeta()->getReplacementImageBase(), $this->getFileName());

				return $name;
			}
		}

		return parent::getBackupFileName();
	} */


	/**
	 * Record a reason to skip the next auto-retry of this thumbnail.
	 *
	 * @param string $reason Human-readable reason surfaced by isOptimizePrevented() on the main file.
	 * @return void
	 */
	protected function preventNextTry($reason = '')
	{
		$this->prevent_next_try = $reason;
	}

	/**
	 * Thumbnails do not carry a prevent flag of their own — the answer is
	 * always false. The main-file MediaLibraryModel owns this state.
	 *
	 * @return false
	 */
	public function isOptimizePrevented()
	{
		return false;
	}

	/**
	 * Thumbnails do not carry a prevent flag of their own — nothing to reset.
	 *
	 * @return null
	 */
	public function resetPrevent()
	{
		return null;
	}

	/**
	 * isProcessable() variant that respects the "process thumbnails" global
	 * setting.
	 *
	 * When thumbnail processing is disabled, all thumbnails become
	 * unprocessable (P_EXCLUDE_SIZE) *except* the main file and the original
	 * unscaled companion, which are always allowed. Otherwise defers to the
	 * standard isProcessable() logic.
	 *
	 * @return bool
	 */
	protected function isThumbnailProcessable()
	{
		// if thumbnail processing is off, thumbs are never processable.
		// This is also used by main file, so check for that!

		if ($this->excludeThumbnails() && $this->is_main_file === false && $this->get('imageType') !== self::IMAGE_TYPE_ORIGINAL) {
			$this->processable_status = self::P_EXCLUDE_SIZE;
			return false;
		} else {
			$bool = parent::isProcessable();

			return $bool;
		}
	}

	/**
	 * Whether this thumbnail is one SPIO discovered on disk (unlisted) rather
	 * than one WordPress registered natively.
	 *
	 * Unlisted thumbnails carry a `file` value on their meta object; native
	 * ones do not.
	 *
	 * @return bool
	 */
	protected function isUnlisted()
	{
		if (! is_null($this->getMeta('file')))
			return true;
		else
			return false;
	}

	/**
	 * isSizeExcluded() with the additional "excluded image sizes" list check.
	 *
	 * When the current thumbnail's size name is in $settings->excludeSizes,
	 * mark it as P_EXCLUDE_SIZE and return true. Otherwise defer to the
	 * parent implementation, which handles size-range exclusion rules.
	 *
	 * @return bool
	 */
	protected function isSizeExcluded()
	{

		$excludeSizes = \wpSPIO()->settings()->excludeSizes;

		if (is_array($excludeSizes) && in_array($this->name, $excludeSizes)) {
			$this->processable_status = self::P_EXCLUDE_SIZE;
			return true;
		}

		$bool = parent::isSizeExcluded();

		return $bool;
	}

	/**
	 * isProcessableFileType() with the "process thumbnails" gate applied.
	 *
	 * When processThumbnails is disabled and this instance is not the main
	 * file, WebP / AVIF variants are also disabled for it.
	 *
	 * @param string $type Either 'webp' or 'avif'.
	 * @return bool
	 */
	public function isProcessableFileType($type = 'webp')
	{
		// Prevent webp / avif processing for thumbnails if this is off. Exclude main file
		if ($this->excludeThumbnails() === true && $this->is_main_file === false)
			return false;

		return parent::isProcessableFileType($type);
	}

	/**
	 * Return the exclusion patterns filtered for this thumbnail's context.
	 *
	 * Passes the thumbnail's size name and whether it is a thumbnail (as
	 * opposed to the main file) into UtilHelper::getExclusions() so it can
	 * evaluate thumb-specific rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function getExcludePatterns()
	{
		$args = array(
			'filter' => true,
			'thumbname' => $this->name,
			'is_thumbnail' => (true === $this->is_main_file) ? false : true,
		);

		// @todo Find a way to cache IsProcessable perhaps due to amount of checks being done.  Should be release in flushOptimizeCache or elsewhere (?)
		$patterns = UtilHelper::getExclusions($args);
		return $patterns;
	}

	/**
	 * Whether the "process thumbnails" setting is disabled globally.
	 *
	 * @return bool True when thumbnails should be skipped.
	 */
	protected function excludeThumbnails()
	{
		return (! \wpSPIO()->settings()->processThumbnails);
	}


	/**
	 * Check whether a shortpixel_postmeta row exists for this thumbnail.
	 *
	 * Used to distinguish thumbnails SPIO has already tracked from ones it
	 * has never seen.
	 *
	 * @return bool
	 */
	public function hasDBRecord()
	{
		global $wpdb;

		$sql = 'SELECT id FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d AND size = %s';
		$sql = $wpdb->prepare($sql, $this->id, $this->size);

		$id = $wpdb->get_var($sql);

		if (is_null($id)) {
			return false;
		} elseif (is_numeric($id)) {
			return true;
		}
	}

	/**
	 * Restore this thumbnail from its backup.
	 *
	 * For virtual files, first translates the virtual path to a real one so
	 * the restore can write locally. On success, resets image_meta — but
	 * preserves convertMeta for main files whose converted variant is being
	 * kept (isConverted + not omitBackup).
	 *
	 * @return bool True on successful restore, false otherwise.
	 */
	public function restore()
	{
		if ($this->is_virtual()) {
			$fs = \wpSPIO()->filesystem();
			$filepath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);

			$this->setVirtualToReal($filepath);
		}

		$bool = parent::restore();

		if ($bool === true) {
			if ($this->is_main_file) {
				// If item is converted and will not be moved back to original format ( but converted ) , keep the convert metadata
				if (true === $this->getMeta()->convertMeta()->isConverted() && false === $this->getMeta()->convertMeta()->omitBackup()) {
					$convertMeta = clone $this->getMeta()->convertMeta();
					$imageMeta = new ImageMeta();
					$imageMeta->convertMeta()->fromClass($convertMeta);
					// This removed, because interfering with messaging / other functions
					//			$bool = false; // Prevent cleanRestore from deleting the metadata.
				} else {
					$imageMeta = new ImageMeta();
				}

				$this->image_meta = $imageMeta;
			} else {
				$this->image_meta = new ImageThumbNailMeta();
			}
		}

		return $bool;
	}


	/**
	 * Ensure a virtual (remote / stateless) file is available locally so a
	 * backup can be created from it.
	 *
	 * For real files this is a no-op. For virtual ones the method translates
	 * the virtual path and, depending on the virtual_status, either accepts
	 * an existing local copy, downloads the remote file via DownloadHelper,
	 * or treats a stateless location as ready. On failure it calls
	 * preventNextTry() to avoid retry storms and records an error message.
	 *
	 * @return bool True when a local file is available (or the source was
	 *              already real); false when the download failed.
	 */
	public function checkVirtualForBackup()
	{
		if ($this->is_virtual()) // download remote file to backup.
		{
			$fs = \wpSPIO()->filesystem();
			$filepath = apply_filters('shortpixel/file/virtual/translate', $this->getFullPath(), $this);

			$result = false;
			if ($this->virtual_status == self::$VIRTUAL_REMOTE) {
				// filepath is translated. Check if this exists as a local copy, if not remotely download.
				if ($filepath !== $this->getFullPath()) {
					$fileObj = $fs->getFile($filepath);
					// Result is same as fileExists, check if file is already here.
					$result = $fileExists = $fileObj->exists();
					
				} else {
					$fileExists = false;
				}

			if (false === $fileExists) {
					$downloadHelper = DownloadHelper::getInstance();
					$url = $this->getURL();
					$result = $downloadHelper->downloadFile($url, array('destinationPath' => $filepath));
			}
			} elseif ($this->virtual_status == self::$VIRTUAL_STATELESS) {
				$result = $filepath;
			} else {
				Log::addWarning('Virtual Status not set. Trying to blindly download vv DownloadHelper');
				$downloadHelper = DownloadHelper::getInstance();
				$url = $this->getURL();
				$result = $downloadHelper->downloadFile($url, array('destinationPath' => $filepath));
			}

			if ($result == false) {
				$this->preventNextTry(__('Fatal Issue: Remote virtual file could not be downloaded for backup', 'shortpixel-image-optimiser'));
				if (! isset($url))
				{
					 $url = 'Unknown'; 
				}
				Log::addError('Remote file download failed from : ' . $url . ' to: ' . $filepath, $this->getURL());
				$this->error_message = __('Remote file could not be downloaded' . $this->getFullPath(), 'shortpixel-image-optimiser');

				return false;
			}

			$this->setVirtualToReal($filepath);
		}

		return true; 
		//return parent::createBackup();
	}

} // class
