<?php

namespace ShortPixel\Model\Image;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\Backup\BackupController as BackupController;
use ShortPixel\Controller\QuotaController as QuotaController;

use ShortPixel\Controller\QueueController as QueueController;

use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Converter\Converter as Converter;

/**
 * Represents a full WordPress Media Library attachment (the main file plus
 * all its thumbnails, retinas, WebP / AVIF companions, and — where present —
 * the WP 5.3+ unscaled original).
 *
 * Extends MediaLibraryThumbnailModel because the main file uses the same
 * per-file logic as a thumbnail; the extra responsibility on this class is
 * to own the *family* of variants, orchestrate the API request / result
 * pipeline for all of them at once, and persist the aggregate state to the
 * shortpixel_postmeta table.
 *
 * Also handles legacy metadata migration (from the pre-shortpixel_postmeta
 * schema), WPML duplicate propagation, and detection of "unlisted" extra
 * files that WordPress does not know about but that SPIO should still track.
 *
 * @package ShortPixel\Model\Image
 */
class MediaLibraryModel extends \ShortPixel\Model\Image\MediaLibraryThumbnailModel
{

	/** @var MediaLibraryThumbnailModel[] Registered thumbnails of this attachment, keyed by WP size name. */
	protected $thumbnails = [];

	/** @var MediaLibraryThumbnailModel[]|null Retina (@2x) companions, keyed by size name; null until getRetinas() runs. */
	protected $retinas;

	/** @var MediaLibraryThumbnailModel|false Unscaled original (WP 5.3+) when the main file is a `-scaled` copy, false otherwise. */
	protected $original_file = false;

	/** @var bool True when this attachment carries a WP 5.3+ `-scaled` main file. */
	protected $is_scaled = false;

	/** @var array|null Cached wp_get_attachment_metadata() payload. */
	protected $wp_metadata;

	/** @var int|null Parent attachment ID when this instance is a WPML duplicate. */
	private $parent;

	/** @var string Model type marker used by BackupController / QueueController routing. */
	protected $type = 'media';

	/** @var bool Always true on this class — marks the instance as the main attachment file. */
	protected $is_main_file = true;

	/** @var array<int, bool> Per-request cache of attachment IDs whose unlisted check has already run. */
	private static $unlistedChecked = array();

	/** @var bool Per-request flag: the "unlisted files found" notice check runs at most once per request. */
	private static $unlistedNoticeChecked = false;

	/** @var bool|null Cached result of isOptimizePrevented() for the current request. */
	protected $optimizePrevented;


	/** @var bool True after a legacy-format conversion ran this request, to prevent a second attempt. */
	private $justConverted = false;

	/** @var array|null Per-request cache of getOptimizeData(); cleared by flushOptimizeData(). */
	private $optimizeData;

	/** @var string Reserved size name used to route the main file through the same size-keyed data structures as the thumbnails. */
	protected $mainImageKey = 'shortpixel_main_donotuse';

	/** @var string Reserved size name used for the WP 5.3+ unscaled original companion. */
	protected $originalImageKey = 'shortpixel_original_donotuse';

	/** @var array<string, mixed> Per-request setting overrides pushed from the UI (e.g. "force lossy for this optimization"). */
	protected $forceSettings = array();

	/**
	 * File extensions the Media Library pipeline will accept.
	 *
	 * Wider than the base ImageModel::PROCESSABLE_EXTENSIONS list because
	 * Media Library uploads legitimately include BMP / TIFF that can be
	 * routed through the Converter before hitting the API.
	 */
	const PROCESSABLE_EXTENSIONS = array('jpg', 'jpeg', 'gif', 'png', 'pdf', 'bmp', 'tiff', 'tif', 'webp');

	/**
	 * Constructor.
	 *
	 * Sets the attachment id, delegates to the thumbnail-model parent (which
	 * seeds path + image_meta), then upgrades the instance to the main-file
	 * flavour: swaps in an ImageMeta, marks the imageType as IMAGE_TYPE_MAIN
	 * and assigns the reserved main-image key for the size-keyed data
	 * structures. If WP 5.3+ is present, checks for an unscaled original.
	 * Meta is loaded eagerly when $post_id > 0, and unlisted-file detection
	 * runs unless the extension is excluded.
	 *
	 * @param int    $post_id WordPress attachment ID.
	 * @param string $path    Absolute filesystem path to the main file.
	 */
	public function __construct($post_id, $path)
	{
		$this->id = $post_id;

		parent::__construct($path, $post_id, null);

		// Set AFTER PARENT, because it's overwritten.
		$this->imageType = self::IMAGE_TYPE_MAIN;
		$this->image_meta = new ImageMeta();
		$this->setName($this->mainImageKey); // by definition this is the case, used for isSizeExcluded

		// WP 5.3 and higher. Check for original file.
		if (function_exists('wp_get_original_image_path')) {
			$this->setOriginalFile();
		}

		if ($this->id > 0) {
			$this->loadMeta();
		}

		if (false === $this->isExtensionExcluded()) {
			$this->checkUnlistedForNotice();
		}

	}

	/**
	 * Return just the flat URL list for the ShortPixel API request.
	 *
	 * Thin wrapper around getOptimizeData() that drops the size keys so the
	 * queue can hand a plain positional array to the API client.
	 *
	 * @return string[]
	 */
	public function getOptimizeUrls()
	{
		$data = $this->getOptimizeData();
		return array_values($data['urls']);
	}

	/**
	 * Clear the "excluded by user setting" status on this image and every
	 * thumbnail, then flush the optimize-data cache so the next call
	 * recomputes URLs against the relaxed state.
	 *
	 * Used by the "process anyway" flow — call this *before* getOptimizeUrls
	 * / getOptimizeData when a user is manually optimizing an item that would
	 * otherwise be filtered out.
	 *
	 * @return void
	 */
	public function cancelUserExclusions()
	{
		parent::cancelUserExclusions();

		$thumbs = $this->getThumbObjects();
		foreach ($thumbs as $thumbnailObj) {
			$thumbnailObj->cancelUserExclusions();
		}

		// reset optimizedata if any
		$this->optimizeData = null;
	}

	/**
	 * Build the full API-request payload for this attachment family in one pass.
	 *
	 * Walks the main file plus every thumbnail (including the WP 5.3+
	 * unscaled original when present) and produces:
	 *   - `urls`   → per-size public URL to send to the API
	 *   - `paths`  → per-size filesystem path (destination for the response)
	 *   - `params` → per-size parameter list from createParamList()
	 *   - `returnParams.sizes`      → per-size filename (for the response mapping)
	 *   - `returnParams.doubles`    → sizes that share a paramList + URL hash with another size but map to a *different* file
	 *   - `returnParams.duplicates` → sizes that map to the *same* file as another size (only the meta gets updated on completion)
	 *   - `returnParams.fileSizes`  → per-size filesize, populated when createParamList selected the main URL instead of the size URL
	 *
	 * Thumbnails are skipped when the main extension isn't in
	 * ImageModel::PROCESSABLE_EXTENSIONS (converter-only formats: bmp, tiff)
	 * because those don't come with WP thumbnails yet — and in that case the
	 * result is *not* cached because the next call may see a converted main.
	 *
	 * Unprocessable thumbnails still get a second pass at the end so they can
	 * be tagged as duplicates of a size that *is* being processed.
	 *
	 * @return array{urls: array<string,string>, paths?: array<string,string>, params?: array<string,array>, returnParams: array{sizes: array<string,string>, doubles: array<string,string>, duplicates: array<string,string>, fileSizes?: array<string,int>}}
	 */
	public function getOptimizeData()
	{
		// The thumbnails included in the parent ImageModels are the ones that are not converted and thus also optimize all thumbnails.  Prevent adding thumbnails in the optimizeData if not so.
		$include_thumbs = true;
		if (false === in_array($this->getExtension(), ImageModel::PROCESSABLE_EXTENSIONS)) {
			$include_thumbs = false;
		}

		if (! is_null($this->optimizeData) && true === $include_thumbs) {
			return $this->optimizeData;
		}

		$parameters = array(
			'urls' => array(),
			'params' => array(),
			// doubles are items that will have same result, but is diffent file.  duplicates are same files, same result - only meta update.
			'returnParams' => array('sizes' => array(), 'doubles' => array(), 'duplicates'  => array()),
		);

		$settings = \wpSPIO()->settings();
		$url = $this->getURL();

		if ($this->hasOriginal()) {
			$main_url = $this->getOriginalFile()->getUrl();
		} else {
			$main_url = $url;
		}

		if (! $url) // If the whole image URL can't be found
		{
			return $parameters;
		}

		// Is smartcrop setting on?
		$isSmartCrop = ($settings->useSmartcrop == true && $this->getExtension() !== 'pdf') ? true : false;
		$paramListArgs = array(); // args for the params, yes.

		if (isset($this->forceSettings['smartcrop']) && $this->getExtension() !== 'pdf') {
			$isSmartCrop = ($this->forceSettings['smartcrop'] == ImageModel::ACTION_SMARTCROP) ? true : false;
		}
		$paramListArgs['smartcrop'] = $isSmartCrop;
		$paramListArgs['url'] = $url;
		$paramListArgs['main_url'] = $main_url;
		// Add main Image Sizes here for checking ratio / smartcrop.
		$paramListArgs['main_width'] = $this->get('width');
		$paramListArgs['main_height'] = $this->get('height');

		$doubles = array(); // check via hash if same command / result is there.

		if ($this->isProcessable(true) || ($this->isProcessableAnyFileType() && $this->isOptimized())) {
			$paramList = $this->createParamList($paramListArgs);
			$parameters['urls'][$this->mainImageKey] = $paramList['url'];
			$parameters['paths'][$this->mainImageKey] = $this->getFullPath();
			$parameters['params'][$this->mainImageKey] = $paramList;
			$parameters['returnParams']['sizes'][$this->mainImageKey] = $this->getFileName();

			// If top URL is used, include filesize information

			if ($paramList['url'] !== $paramListArgs['url']) {
				$parameters['returnParams']['fileSizes'][$this->mainImageKey] = $this->getFileSize();
			}

			$hash = md5(serialize($paramList) . $url);
			$doubles[$hash] = $this->mainImageKey;
		}

		if (false === $include_thumbs) {
			return $parameters;
		}

		$thumbObjs = $this->getThumbObjects();
		$unProcessable = [];

		foreach ($thumbObjs as $name => $thumbObj) {
			if ($thumbObj->isThumbnailProcessable() || ($thumbObj->isProcessableAnyFileType() && $thumbObj->isOptimized())) {

				$url = $thumbObj->getURL();
				$paramListArgs['url'] = $url;
				$paramListArgs['main_url'] = $main_url;

				$paramList = $thumbObj->createParamList($paramListArgs);
				$url = $paramList['url']; // createParamList also decides on URL.
				$hash = md5(serialize($paramList) . $url); // Hash the paramlist + url to find identical results.

				// Return last  duplicate/double name => name if hash found
				if (isset($doubles[$hash])) {
					$doubleName = $doubles[$hash];

					if ($doubleName === $this->mainImageKey) {
						$compareObj = $this;
					} else {
						$compareObj = $thumbObjs[$doubleName];
					}

					if ($thumbObj->getFileName() == $compareObj->getFileName()) {
						$parameters['returnParams']['duplicates'][$name] = $doubleName;
					} else {
						// Check if in a duplicate item is in doubles, so we don't double-double it.
						$aDuplicate = false;
						foreach ($parameters['returnParams']['doubles'] as $doubleNameInDoubles => $unneeded) {
							if ($doubleNameInDoubles !== $this->mainImageKey && $doubleNameInDoubles !== $this->originalImageKey && $thumbObjs[$doubleNameInDoubles]->getFileName() == $thumbObj->getFileName()) {
								$aDuplicate = true;
								$parameters['returnParams']['duplicates'][$name] = $doubleNameInDoubles;
							}
						}

						if (false === $aDuplicate) {
							$parameters['returnParams']['doubles'][$name] = $doubleName;
						}
					}
				} else {
					$parameters['urls'][$name] = $url;
					$parameters['paths'][$name] =  $thumbObj->getFullPath();
					$parameters['params'][$name] = $paramList;
					$parameters['returnParams']['sizes'][$name] = $thumbObj->getFileName();
					if ($paramList['url'] !== $paramListArgs['url']) {
						$parameters['returnParams']['fileSizes'][$name] = $thumbObj->getFileSize();
					}

					$doubles[$hash]  = $name;
				}
			} else {
				// Save rejected thumbs, because they might be a duplicate of something that goes on the processing.
				$unProcessable[] = $thumbObj;
			}
		}

		// If one or more thumbnails were not processable, still check them against the process list in case identical sizes are being processed and it should be marked as a duplicate.
		if (isset($parameters['paths']) && count($unProcessable) > 0) {
			$pathVal = array_values($parameters['paths']);
			$pathLookup = array_flip($parameters['paths']); // lookup fullpath -> size.

			foreach ($unProcessable as $thumbObj) {
				if (in_array($thumbObj->getFullPath(), $pathVal) === true) {
					$parameters['returnParams']['duplicates'][$thumbObj->get('name')] = $pathLookup[$thumbObj->getFullPath()];
				}
			}
		}

		$this->optimizeData = $parameters;
		return $parameters;
	}

	/**
	 * Clear the cached optimize-data payload so the next call recomputes.
	 *
	 * @return void
	 */
	public function flushOptimizeData()
	{
		$this->optimizeData = null;
	}

	/**
	 * Push a per-request setting override (used by UI actions that want to
	 * force a specific policy — e.g. "smartcrop for this run only"). Flushes
	 * the optimize-data cache so the override is picked up.
	 *
	 * @param string $setting Setting key expected by createParamList (e.g. 'smartcrop').
	 * @param mixed  $value   Override value.
	 * @return void
	 */
	public function doSetting($setting, $value)
	{
		$this->forceSettings[$setting] = $value;
		$this->flushOptimizeData();
	}

	/**
	 * Resolve the public URL of the main attachment file.
	 *
	 * NOTE: This is a genuinely heavy call. Third-party plugins (S3
	 * offloaders, CDN rewriters) can add significant latency to
	 * wp_get_attachment_url(); avoid calling repeatedly.
	 *
	 * When a legacy-format conversion left a placeholder on disk, the
	 * extension of the returned URL is rewritten back to the original format
	 * so the API sees the file it expects.
	 *
	 * @return string Public URL of the main file.
	 */
	public function getURL()
	{
		$url = $this->fs()->checkURL(wp_get_attachment_url($this->id));
		if (true === $this->getMeta()->convertMeta()->hasPlaceHolder()) {
			$extension = pathinfo($url, PATHINFO_EXTENSION);
			$url = str_replace($extension, $this->getMeta()->convertMeta()->getFileFormat(), $url);
		}

		return $url;
	}

	/**
	 * Return one of the reserved size-name keys used to identify the main
	 * file / unscaled original in size-keyed data structures.
	 *
	 * @param string $key Either 'main' or 'original'.
	 * @return string|null Reserved key, or null when $key is unknown.
	 */
	public function getImageKey($key = 'main')
	{
		 if ('main' == $key)
		 {
			 return $this->mainImageKey;
		 }
		 if ('original' == $key)
		 {
			 return $this->originalImageKey;
		 }
	}

	/**
	 * Return every URL owned by this attachment (main + original + all
	 * thumbnails + all WebP + all AVIF companions).
	 *
	 * Intended for cache-warmup / cache-invalidation flows. Do *not* use
	 * this for the optimizer — call getOptimizeUrls() instead.
	 *
	 * @return array{urls: array<string,string>, avif: array<string,string>, webp: array<string,string>}
	 */
	public function getAllUrls()
	{
		$urls = array();
		$urls[$this->mainImageKey] = $this->getUrl();

		if ($this->isScaled())
		{
			 $urls[$this->originalImageKey]  = $this->getOriginalFile()->getUrl();
		}

		$thumbs = $this->getThumbObjects();
		foreach ($thumbs as $thumbName => $thumbObj) {
			$urls[$thumbName] = $thumbObj->getUrl();

		}

	$results = [
		'urls' => $urls, 
		'avif' => [],
		'webp' => [] 
	];
	

		$webps = $this->getWebps();
		$avifs = $this->getAvifs(); 
		
		$base_url = trailingslashit(str_replace($this->getFileName(), '', $this->getURL()));

		foreach($webps as $webpName => $webpObj)
		{
			$results['webp'][$webpName]  =  $base_url . $webpObj->getFileName(); 
		}
		foreach($avifs as $avifName => $avifObj)
		{
			$results['avif'][$avifName]  = $base_url .  $avifObj->getFileName(); 	 
		}


		return $results;
	}

	/**
	 * Return every FileModel owned by this attachment — the object counterpart
	 * of getAllUrls().
	 *
	 * Used by backup / restore / cleanup flows that need to walk the family
	 * as objects rather than URLs.
	 *
	 * @return array{files: array<string, \ShortPixel\Model\File\FileModel>, avif: array<string, \ShortPixel\Model\File\FileModel>, webp: array<string, \ShortPixel\Model\File\FileModel>}
	 */
	public function getAllFiles()
	{
		$urls = array();
		$urls[$this->mainImageKey] = $this;

		if ($this->isScaled())
		{
			 $urls[$this->originalImageKey]  = $this->getOriginalFile();
		}

		$thumbs = $this->getThumbObjects();
		foreach ($thumbs as $thumbName => $thumbObj) {
			$urls[$thumbName] = $thumbObj;
		}

	$results = [
		'files' => $urls, 
		'avif' => [],
		'webp' => [] 
	];
	

		$webps = $this->getWebps();
		$avifs = $this->getAvifs(); 
		
		foreach($webps as $webpName => $webpObj)
		{
			$results['webp'][$webpName]  =  $webpObj; 
		}
		foreach($avifs as $avifName => $avifObj)
		{
			$results['avif'][$avifName]  = $avifObj; 	 
		}


		return $results;	 
	}

	/**
	 * Return the WordPress attachment metadata array, populating the
	 * per-instance cache on first call.
	 *
	 * @return array<string, mixed>|false Result of wp_get_attachment_metadata(), or false when unavailable.
	 */
	public function getWPMetaData()
	{
		if (is_null($this->wp_metadata))
			$this->wp_metadata = wp_get_attachment_metadata($this->id);

		return $this->wp_metadata;
	}

	/**
	 * Whether the main file is a WordPress 5.3+ `-scaled` variant with an
	 * unscaled original companion.
	 *
	 * @return bool
	 */
	public function isScaled()
	{
		return $this->is_scaled;
	}


	/**
	 * Build a MediaLibraryThumbnailModel for every size declared in the WP
	 * attachment metadata.
	 *
	 * Along the way:
	 *   - populates $this->width / $this->height (falling back to on-disk
	 *     measurement, or PDF's `full` size when the top-level values are
	 *     missing);
	 *   - copies the stored filesize onto the main file when available;
	 *   - assigns each thumbnail its WordPress size definition (width /
	 *     height / crop) from UtilHelper::getWordPressImageSizes(), with a
	 *     duplicate-dimensions fallback for sizes not present in the current
	 *     registration (e.g. sizes removed from the theme but still stored
	 *     on old attachments).
	 *
	 * Attachments with no `sizes` array, no width, and an excluded extension
	 * return an empty array — the attachment is treated as non-processable
	 * without touching the disk.
	 *
	 * @return MediaLibraryThumbnailModel[] Thumbnail models keyed by WP size name.
	 */
	protected function loadThumbnailsFromWP()
	{
		$wpmeta = $this->getWPMetaData();
		$wpImageSizes = UtilHelper::getWordPressImageSizes();

		$width = null;
		$height = null;

		if (! isset($wpmeta['width'])) {
			if ('pdf' === $this->getExtension()) {
				$width = $wpmeta['full']['width'];
			}
		} else
			$width = $wpmeta['width'];


		if (! isset($wpmeta['height'])) {
			if ('pdf' === $this->getExtension() ) {
				$height = $wpmeta['full']['height'];
			}
		} else
			$height = $wpmeta['height'];

		if (isset($wpmeta['filesize']) && intval($wpmeta['filesize']) > 0) {
			$this->filesize = $wpmeta['filesize'];
		}

		// if meta is (mostly) empty and no sizes ( no thumbnails ) and no width, this might not be image, nor processable.
		if (is_null($width) && ! isset($wpmeta['sizes']) && true === $this->isExtensionExcluded()) {
			return array();
		}

		if (is_null($width) || is_null($height) && ! $this->is_virtual()) {
			$width = (is_null($width)) ? $this->get('width') : $width;
			$height = (is_null($height)) ? $this->get('height') : $height;
		}

		$this->width = $width;
		$this->height = $height;

		$thumbnails = [];

		if (isset($wpmeta['sizes'])) {
			$meta_sizes = $wpmeta['sizes'];
			$missingDefinitions = [];

			foreach ($meta_sizes as $name => $data) {
				if (isset($data['file'])) {
					$width = (isset($data['width'])) ? $data['width'] : null;
					$height = (isset($data['height'])) ? $data['height'] : null;
					$thumbObj = $this->getThumbnailModel($this->getFileDir() . $data['file'], $name);
					$meta = new ImageThumbnailMeta();
					$meta->originalWidth = $width; // get from WP
					$meta->originalHeight = $height;
					$thumbObj->setName($name); // name is size mostly
					$thumbObj->setMetaObj($meta);

					$thumbObj->width = $width;
					$thumbObj->height = $height;

					if (isset($wpImageSizes[$name])) {
						$thumbObj->setSizeDefinition($wpImageSizes[$name]);
					} else {
						$missingDefinitions[] = $name;
					}

					if (isset($data['filesize']) && intval($data['filesize']) > 0)
						$thumbObj->filesize = $data['filesize'];

					$thumbnails[$name] = $thumbObj;
				}
			}

			// Something went astray, check if a duplicate can be found. This is a fix for when images are a duplicate, but the counter part is not registered in WordPress and SmartCrop is enabled. This then can cause an exception where both duplicates are entered into optimization and causing backup issue.
			if (count($missingDefinitions) > 0) {
				foreach ($missingDefinitions as $thumbName) {
					$targetObj = $thumbnails[$thumbName];
					$width = $targetObj->width;
					$height = $targetObj->height;

					foreach ($meta_sizes as $size_name => $size_data) {
						if ($size_name == $thumbName) // skip self.
						{
							continue;
						}

						if ($size_data['width'] == $width && $size_data['height'] == $height && isset($wpImageSizes[$size_name])) {
							$targetObj->setSizeDefinition($wpImageSizes[$size_name]);
						}
					}
				}
			}
		}
		return $thumbnails;
	}


	/**
	 * Return the retina (@2x) companions for every part of the family
	 * — main file, unscaled original (when scaled), and each thumbnail.
	 *
	 * No-op when the `optimizeRetina` setting is off. The result is memoised
	 * on $this->retinas and reused by getWebps() / getAvifs() /
	 * getOptimizeData().
	 *
	 * The main-file / original-file entries use the reserved size names so
	 * they don't collide with custom-size thumbnails.
	 *
	 * @return MediaLibraryThumbnailModel[] Retinas keyed by size name; empty array when the setting is off or none exist.
	 */
	protected function getRetinas() : array
	{
		// Don't load retina's if option is off.
		if (! \wpSPIO()->settings()->optimizeRetina)
			return [];

		if (! is_null($this->retinas)) {
			return $this->retinas;
		}

		if (! isset($this->retinas[$this->mainImageKey])) {
			$main = $this->getRetina();

			if ($main) {
				$main->setName($this->mainImageKey);
				$this->retinas[$this->mainImageKey] = $main; // to prevent any custom image sizes to get overwritten.
			}
		}

		if ($this->isScaled() && ! isset($this->retinas[$this->originalImageKey])) {
			$retscaled = $this->original_file->getRetina();
			if ($retscaled) {
				$retscaled->setName($this->originalImageKey);
				$this->retinas[$this->originalImageKey] = $retscaled; //see main
			}
		}

		foreach ($this->thumbnails as $thumbname => $thumbObj) {
			if (! isset($this->retinas[$thumbname])) {
				$retinaObj = $thumbObj->getRetina();
				if ($retinaObj)
					$this->retinas[$retinaObj->get('name')] = $retinaObj;
			}
		}

		if (is_null($this->retinas))
		{
			 $this->retinas = []; 
		}

		return $this->retinas;
	}

	/**
	 * Return every WebP companion belonging to this attachment: main file,
	 * each thumbnail, each retina (prefixed with `retina-` so it doesn't
	 * shadow a same-named thumbnail entry), and the unscaled original when
	 * scaled.
	 *
	 * @return \ShortPixel\Model\File\FileModel[] Keyed by size name (or `retina-` + size name).
	 */
	protected function getWebps() : array
	{
		$webps = [];

		$main = $this->getWebp();
		if ($main)
			$webps[$this->mainImageKey] = $main;  // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

		foreach ($this->thumbnails as $thumbname => $thumbObj) {
			$webp = $thumbObj->getWebp();
			if ($webp)
				$webps[$thumbname] = $webp;
		}

		if (! is_null($this->retinas)) {
			foreach ($this->retinas as $retinaName => $retinaObj) {
				$webp = $retinaObj->getWebp();
				if ($webp)
					$webps['retina-' . $retinaName]  = $webp; // adding a prefix to make sure it will not overwrite thumbnames, they share the same name.
			}
		}
		if ($this->isScaled()) {
			$webp = $this->original_file->getWebp();
			if ($webp)
				$webps[$this->originalImageKey] = $webp; //see main
		}

		return $webps;
	}

	/**
	 * AVIF equivalent of getWebps() — walks the same family and returns the
	 * AVIF companion files.
	 *
	 * @return \ShortPixel\Model\File\FileModel[] Keyed by size name (or `retina-` + size name).
	 */
	protected function getAvifs() : array
	{
		$avifs = [];
		$main = $this->getAvif();

		if ($main)
			$avifs[$this->mainImageKey] = $main;  // on purpose not a string, but number to prevent any custom image sizes to get overwritten.

		foreach ($this->thumbnails as $thumbname => $thumbObj) {
			$avif = $thumbObj->getAvif();
			if ($avif)
				$avifs[$thumbname] = $avif;
		}

		if (! is_null($this->retinas)) {
			foreach ($this->retinas as $retinaName => $retinaObj) {
				$avif = $retinaObj->getAvif();
				if ($avif)
					$avifs['retina-' . $retinaName]  = $avif; // adding a prefix to make sure it will not overwrite thumbnames, they share the same name.
			}
		}

		if ($this->isScaled()) {
			$avif = $this->original_file->getAvif();
			if ($avif)
				$avifs[$this->originalImageKey] = $avif; //see main
		}

		return $avifs;
	}

	/**
	 * Count members of the attachment family by category.
	 *
	 * Supported $type values:
	 *   - `thumbnails`   — number of registered thumbnails
	 *   - `webps` / `avifs` / `retinas` — number of *unique* companion files
	 *   - `optimized`    — main + thumbnails with FILE_STATUS_SUCCESS
	 *   - `user_excluded`— main + thumbnails currently excluded by a user
	 *                     setting (path / size / filesize rule)
	 *
	 * @param string                        $type Category to count.
	 * @param array{thumbs_only?: bool} $args When `thumbs_only=true`, the main file is skipped for `optimized` / `user_excluded`.
	 * @return int
	 *
	 * @todo Needs unit test.
	 */
	public function count($type, $args = [])
	{
		$defaults = array(
			'thumbs_only' => false,
		);

		$args = wp_parse_args($args, $defaults);

		$count = 0;

		switch ($type) {
			case 'thumbnails':
				$count = count($this->get('thumbnails'));
				break;
			case 'webps':
				$count = count(array_unique($this->getWebps()));
				break;
			case 'avifs':
				$count = count(array_unique($this->getAvifs()));
				break;
			case 'retinas':
				$count = count(array_unique($this->getRetinas()));
				break;

			case 'optimized':
				if (false === $args['thumbs_only'] && $this->isOptimized()) {
					$count++;
				}

				foreach ($this->get('thumbnails') as $name => $object) {
					if ($object->isOptimized()) {
						$count++;
					}
				}

				break;
			case 'user_excluded':

				if (false === $args['thumbs_only'] && $this->isUserExcluded()) {
					$count++;
				}

				foreach ($this->get('thumbnails') as $name => $object) {
					if ($object->isUserExcluded()) {
						$count++;
					}
				}

				break;
		}

		return $count;
	}

	/**
	 * Apply an API optimization result across the entire attachment family
	 * and persist the resulting state.
	 *
	 * Pipeline:
	 *   1. Runs the main file through parent::handleOptimized() (unless
	 *      already optimized) and folds resize/filesize changes into the
	 *      pending _wp_attachment_metadata payload.
	 *   2. Applies the WebP / AVIF companion result for the main file.
	 *   3. Expands `doubles` into concrete per-size result entries so their
	 *      files get copied + backed up (they share a result payload with a
	 *      "leader" size but land in different files).
	 *   4. Walks every size in `data.sizes`, runs its handleOptimizedFileType
	 *      + handleOptimized, and folds resize/filesize changes for standard
	 *      thumbnails (unlisted `file` thumbnails don't touch wp meta).
	 *      Fatal-status thumbnails propagate up through preventNextTry().
	 *   5. `duplicates` (same file, same result) get their meta cloned from
	 *      the leader while preserving their own `databaseID` so the record
	 *      is updated in place rather than deleted.
	 *   6. Flushes the optimize-data cache, persists SPIO meta via saveMeta(),
	 *      and writes the updated `_wp_attachment_metadata`.
	 *   7. Propagates the same wp meta (and creates a duplicate row if
	 *      missing) for every WPML duplicate attachment.
	 *
	 * @param array{files: array<string, array>, data: array{sizes: array<string,string>, doubles?: array<string,string>, duplicates?: array<string,string>}} $optimizeData Result payload returned by the API layer.
	 * @param array{isConverted?: bool} $args Post-processing hints; `isConverted` is auto-derived from the convertMeta state.
	 * @return bool True on success, false when the main file failed or any thumbnail flagged preventNextTry().
	 */
	public function handleOptimized($optimizeData, $args = array())
	{
		$return = true;
		$wpmeta = wp_get_attachment_metadata($this->get('id'));
		$WPMLduplicates = $this->getWPMLDuplicates();
		$fs = \wpSPIO()->filesystem();

		if (isset($optimizeData['files']) && isset($optimizeData['data'])) {
			$files = $optimizeData['files'];
			$data = $optimizeData['data'];
		} else {
			Log::addError('Something went wrong with handleOptimized', $optimizeData);
		}

		$optimized = [];

		// Main file has a index.
		// @todo In future, he probably should be checked if backup should only be mainfile, or as well the thumbs.
		$mainFile = (isset($files) && isset($files[$this->mainImageKey])) ? $files[$this->mainImageKey] : false;

		// If converted and not using regular backup as leading.
		$isConverted = (true === $this->getMeta()->convertMeta()->isConverted() && true === $this->getMeta()->convertMeta()->omitBackup());

		$args['isConverted'] = $isConverted;

		if (! $this->isOptimized() && isset($mainFile['image'])) // main file might not be contained in results
		{
			$result = parent::handleOptimized($mainFile, $args);
			if (false === $result) {
				return false;
			}

			if ($this->getMeta('resize') == true) {
				$wpmeta['width'] = $this->get('width');
				$wpmeta['height'] = $this->get('height');
			}
			$filesize = $this->getFileSize();
			if ($this->is_virtual() && $filesize == -1 && $this->getMeta('compressedSize') > 0) {
				$filesize = $this->getMeta('compressedSize');
			}
			$wpmeta['filesize'] = $filesize;
		}

		$this->handleOptimizedFileType($mainFile);

		$compressionType = $this->getMeta('compressionType'); // CompressionType not set on subimages etc.

	
		// If thumbnails should not be optimized, they should not be in result Array.
		// #### THUMBNAILS ####
		$thumbObjs = $this->getThumbObjects();

		// Add doubles to the processing list. Doubles are sizes with the same result, but should be copied to it's respective thumbnail file + backup.
		if (isset($data['doubles'])) {
			foreach ($data['doubles'] as $doubleName => $doubleRef) {
				$files[$doubleName] = $files[$doubleRef];
				if (isset($thumbObjs[$doubleName])) {
					$doubleObj = $thumbObjs[$doubleName];
					$data['sizes'][$doubleName] = $doubleObj->getFileName();
				} else {
					Log::addError('Double thumb not set in result: ' . $doubleName, $doubleRef);
				}
			}
		}

		foreach ($data['sizes'] as $sizeName => $fileName) {
			// Check if thumbnail is in the tempfiles return set. This might not always be the case
			if (! isset($files[$sizeName])) {
				continue;
			}

			if ($sizeName === $this->mainImageKey) {
				continue;
			}

			$resultData = $files[$sizeName];
			$thumbnail = (isset($thumbObjs[$sizeName])) ? $thumbObjs[$sizeName] : false;

			if (! is_object($thumbnail)) {
				Log::addError('Thumbnail with size name: '  . $sizeName . ' is not registered in this image. This should not happen, skipping.', $thumbObjs);
				Log::addError('OptimizeData', $optimizeData);
				continue;
			}

			$thumbnail->handleOptimizedFileType($resultData); // check for webps /etc

			if ($thumbnail->isOptimized()) {
				continue;
			}
			// Catch serious issues witht thumbnails, ignore the user ones. if they come back, try to process.
			if (!$thumbnail->isProcessable()  && false === $thumbnail->isUserExcluded()) {
				Log::addWarn('Optimized thumbnail signalled as not processable :' . $sizeName);
				continue; // when excluded.
			}

			$result = false;

			$thumbnail->setMeta('compressionType', $compressionType);
			$result = $thumbnail->handleOptimized($resultData, $args);

			// Always update the WP meta - except for unlisted files.
			if ($thumbnail->get('imageType') == self::IMAGE_TYPE_THUMB && $thumbnail->getMeta('file') === null) {

				$size = $thumbnail->get('size');
				if ($thumbnail->getMeta('resize') == true) {
					$wpmeta['sizes'][$size]['width'] = $thumbnail->get('width');
					$wpmeta['sizes'][$size]['height']  = $thumbnail->get('height');
				}

				$filesize = $thumbnail->getFileSize();
				if ($thumbnail->is_virtual() && $filesize == -1 && $thumbnail->getMeta('compressedSize') > 0) {
					$filesize = $thumbnail->getMeta('compressedSize');
				}

				$wpmeta['sizes'][$size]['filesize'] = $filesize;
			}


			if ($thumbnail->get('prevent_next_try') !== false) // in case of fatal issues.
			{
				$this->preventNextTry($thumbnail->get('prevent_next_try'));
				$return = false; //failed
			}
		}

		// Add duplicates. Duplicates are metadata sizes that have a same file ( identical ) defined pointing.
		if (isset($data['duplicates'])) {

			foreach ($data['duplicates'] as $duplicateName => $duplicateRef) {
				if ($duplicateName === $this->mainImageKey) {
					$thumbnail = $this;
				} elseif ($duplicateName === $this->originalImageKey) {
					$thumbnail = $this->getOriginalFile();
				} else {
					$thumbnail = isset($thumbObjs[$duplicateName]) ? $thumbObjs[$duplicateName] : null;
				}

				if ($duplicateRef === $this->mainImageKey) {
					$duplicateObj = $this;
				} elseif ($duplicateRef === $this->originalImageKey) {
					$duplicateObj = $this->getOriginalFile();
				} else {
					$duplicateObj = $thumbObjs[$duplicateRef];
				}

				if (is_object($thumbnail) && is_object($duplicateObj)) {
					$databaseID = $thumbnail->getMeta('databaseID');
					$thumbnail->setMetaObj($duplicateObj->getMetaObj());
					$thumbnail->setMeta('databaseID', $databaseID);  // keep dbase id the same, otherwise it won't write this thumb to DB due to same ID.
				} else {
					Log::AddError('Duplicate Thumbnail not available: ' . $duplicateName . ' or ref ' . $duplicateRef);
				}
			}
		}

		// Remove Temp Files
		$this->flushOptimizeData();

		$this->saveMeta();
		update_post_meta($this->get('id'), '_wp_attachment_metadata', $wpmeta);

		if (is_array($WPMLduplicates) && count($WPMLduplicates) > 0) {
			// Run the WPML duplicates
			foreach ($WPMLduplicates as $duplicate_id) {
				// Get this Object cacheless, because it can create records when loading.
				$duplicateObj = $fs->getImage($duplicate_id, 'media', false);

				// Save the exact same data under another post. Don't duplicate it, when already there.
				if ($duplicateObj->getParent() === false) {
					$this->createDuplicateRecord($duplicate_id);
				}
				$duplicate_meta = wp_get_attachment_metadata($duplicate_id);

				// If duplicate metadata doesn't not exist  in error state, array_merge could fail. Just don't update without data as well.
				if (is_array($duplicate_meta)) {
					$duplicate_meta = array_merge($duplicate_meta, $wpmeta);
					update_post_meta($duplicate_id, '_wp_attachment_metadata', $duplicate_meta);
				}
			}
		}

		return $return;
	}

	/**
	 * Aggregate the per-file improvement figures across the whole family.
	 *
	 * For the main file and every optimized thumbnail, collects the
	 * percentage + byte savings via ImageModel::getImprovement(). Returns
	 * the per-item breakdown plus the average percentage and total byte
	 * savings.
	 *
	 * @return array{main?: array{0: int|float|null, 1: int|null}, thumbnails?: array<string, array{0: int|float|null, 1: int|null}>, totalpercentage: int, totalsize: int}|false
	 *   Improvements payload, or false when nothing is optimized.
	 */
	public function getImprovements()
	{
		$improvements = array();
		$count = 0;
		$totalsize = 0;
		$totalperc = 0;

		if ($this->isOptimized()) {
			$perc = $this->getImprovement();
			$size = $this->getImprovement(true);
			if (! is_null($size))
				$totalsize += $size;
			if (! is_null($perc))
				$totalperc += $perc;

			$improvements['main'] = array($perc, $size);
			$count++;
		}

		foreach ($this->thumbnails as $thumbObj) {
			if (! $thumbObj->isOptimized())
				continue;

			if (! isset($improvements['thumbnails'])) {
				$improvements['thumbnails'] = array();
			}
			$perc = $thumbObj->getImprovement();
			$size = $thumbObj->getImprovement(true);
			if (! is_null($size)) {
				$totalsize += $size;
			}
			if (! is_null($perc)) {
				$totalperc += $perc;
			}

			$improvements['thumbnails'][$thumbObj->name] = array($perc, $size);
			$count++;
		}

		if ($count == 0)
			return false; // no improvements;

		$improvements['totalpercentage']  = round($totalperc / $count);
		$improvements['totalsize'] = $totalsize;
		return $improvements;
	}


	/**
	 * Build a MediaLibraryThumbnailModel from a raw filesystem path.
	 *
	 * Intended for creating thumbnail objects that don't come from the
	 * standard WP-metadata walk — chiefly unlisted files discovered on disk.
	 * Do not use this for sizes already registered on $thumbnails; call
	 * loadThumbnailsFromWP() instead.
	 *
	 * @param string $path Absolute path to the thumbnail file.
	 * @param string $size Size name to assign.
	 * @return MediaLibraryThumbnailModel
	 */
	private function getThumbnailModel($path, $size)
	{
		$thumbObj = new MediaLibraryThumbnailModel($path, $this->id, $size);
		return $thumbObj;
	}

	/**
	 * Hydrate this instance from the shortpixel_postmeta rows for the
	 * attachment (plus any late-discovered files on disk).
	 *
	 * Flow:
	 *   - No DB rows: build the family from WordPress metadata via
	 *     loadThumbnailsFromWP(), then run checkLegacy() to migrate any
	 *     pre-shortpixel_postmeta data found on the attachment. If migration
	 *     produced something, saveMeta() persists it.
	 *   - DB rows present: hydrate image_meta, then for each WP-declared
	 *     thumbnail merge in the stored meta (removing it from the DB
	 *     payload as it's consumed). Any leftover DB thumbnails with a
	 *     `file` property are unlisted thumbnails — rebuild them from disk.
	 *     Retinas and the unscaled original are loaded the same way.
	 *   - If any object mutated its meta during load (verifyImage repairs,
	 *     etc.), save the changes back to persist the fix — but only when
	 *     this is a real attachment record (not a IMAGE_TYPE_DUPLICATE).
	 *
	 * Finally calls loadLooseItems() to catch anything that isn't tracked by
	 * either WP or SPIO metadata yet.
	 *
	 * @return void
	 */
	protected function loadMeta()
	{
		$metadata = $this->getDBMeta();
		$settings = \wpSPIO()->settings();

		$this->image_meta = new ImageMeta();
		$fs = \wpSPIO()->fileSystem();

		if (! $metadata) {
			// Thumbnails is a an array of ThumbnailModels
			$this->thumbnails = $this->loadThumbnailsFromWP();
			$result = $this->checkLegacy();
			if ($result) {
				$this->saveMeta();
			}
		} elseif (is_object($metadata)) {
			$this->image_meta->fromClass($metadata->image_meta);

			// Loads thumbnails from the WordPress installation to ensure fresh list, discover later added, etc.
			$thumbnails = $this->loadThumbnailsFromWP();

			foreach ($thumbnails as $name => $thumbObj) {
				if (isset($metadata->thumbnails[$name])) // Check WP thumbs against our metadata.
				{
					$thumbMeta = new ImageThumbnailMeta();
					$thumbMeta->fromClass($metadata->thumbnails[$name]); // Load Thumbnail data from our saved Meta in model

					$thumbnails[$name]->setMetaObj($thumbMeta);
					$thumbnails[$name]->verifyImage();
					unset($metadata->thumbnails[$name]);
				}
			}

			// Load Unlisted Thumbnails.
			if (property_exists($metadata, 'thumbnails') && count($metadata->thumbnails) > 0) // unlisted in WordPress metadata sizes. Might be special unlisted one, one that was removed etc.
			{
				foreach ($metadata->thumbnails as $name => $thumbMeta) // <!-- ThumbMeta is Object
				{
					// Load from Class and file, might be an unlisted one. Meta doesn't save file info, so without might prove a problem!

					// If file is not set, it's indication it's not a unlisted image, we can't add it.
					if (! property_exists($thumbMeta, 'file'))
						continue;

					$thumbObj = $this->getThumbnailModel($this->getFileDir() . $thumbMeta->file, $name);

					$newMeta = new ImageThumbnailMeta();
					$newMeta->fromClass($thumbMeta);
					$thumbObj->setMetaObj($newMeta);
					$thumbObj->setName($name);
					$thumbObj->verifyImage();

					if ($thumbObj->exists()) // if we exist.
					{
						$thumbnails[$name] = $thumbObj;
					}
				}
			}

			$this->thumbnails = $thumbnails;

			if (property_exists($metadata, 'retinas') && count($metadata->retinas) > 0) {
				$retinas = $this->getRetinas();
				foreach ($metadata->retinas as $name => $retinaMeta) {
					if (isset($retinas[$name])) {
						$retfile = $retinas[$name];
						$retinaObj = $this->getThumbnailModel($retfile->getFullPath(), $name);
						$retMeta = new ImageThumbnailMeta();
						$retMeta->fromClass($retinaMeta);
						$retinaObj->setMetaObj($retMeta);
						$retinaObj->setName($name);
						$retinaObj->setImageType(self::IMAGE_TYPE_RETINA);
						$retinaObj->is_retina = true;
						$retinaObj->verifyImage();

						$this->retinas[$name] = $retinaObj;
					}
				}
			}


			$orFile = $this->getOriginalFile();

			if ($orFile) {
				$orMeta = new ImageThumbnailMeta();
				if (property_exists($metadata, 'original_file') && is_object($metadata->original_file)) {
					$orMeta->fromClass($metadata->original_file);
				}
				$orFile->setMetaObj($orMeta);
				$orFile->setName($this->originalImageKey);
				$orFile->verifyImage();
				$this->original_file = $orFile;
			}

			// New! @todo Move check functions to this, to check upon load and not randomly around
			$this->verifyImage();



			// If anything changed during load, and this is stored ( ie optimized ) images, update changes.
			if (true === $this->didAnyRecordChange() && ! is_null($this->getMeta('databaseID')) && $this->imageType !== self::IMAGE_TYPE_DUPLICATE) {

				$this->saveMeta();
				$this->resetRecordChanges();
			}
		} // Elseif metadata object.

		$this->loadLooseItems();
	}

	/**
	 * Load and reshape the shortpixel_postmeta rows for this attachment into
	 * the legacy metadata-object shape that loadMeta() expects.
	 *
	 * Handles the tricky routing cases:
	 *   - **Missing table**: when the query returns nothing *and* wpdb's last
	 *     error mentions "exist", calls InstallHelper::checkTables() to fix
	 *     the schema and bails out cleanly.
	 *   - **IMAGE_TYPE_DUPLICATE row**: this attachment is a WPML duplicate
	 *     pointing at a parent; re-queries against the parent id and stores
	 *     the parent id on $this->parent. A duplicate-of-duplicate is
	 *     treated as a bug and the stray record is deleted.
	 *   - **No rows at all**: probes getWPMLDuplicates() for a sibling that
	 *     *does* have a SPIO record; if found, self-heals by inserting a
	 *     duplicate row and returning the parent's meta. Otherwise returns
	 *     false so loadMeta() takes the "no meta yet" path.
	 *
	 * The row-to-object mapping decodes each row's `extra_info` JSON blob,
	 * then routes it to `image_meta`, `retinas`, `original_file`, or
	 * `thumbnails[$size]` depending on `image_type` + `parent`.
	 *
	 * @return \stdClass|false Attachment-shaped meta object, or false when no rows exist and no self-heal was possible.
	 */
	protected function getDBMeta()
	{
		global $wpdb;

		// Main Image.
		$sqlQuery = 'SELECT * FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d ORDER BY parent ASC';
		$sqlPrep = $wpdb->prepare($sqlQuery, $this->id);
		$meta = $wpdb->get_results($sqlPrep);

		// If metadata is null and the last-error discussed about exist (and probably doesn't exist), check the table. s
		if (count($meta) == 0 && strpos($wpdb->last_error, 'exist') !== false) {
			InstallHelper::checkTables();
			return false;
		} elseif (count($meta) == 1 && $meta[0]->image_type == self::IMAGE_TYPE_DUPLICATE) {
			$duplicate_id = (int) $meta[0]->parent;
			$sqlPrep = $wpdb->prepare($sqlQuery, $duplicate_id);
			$meta = $wpdb->get_results($sqlPrep);
			// This is an error state if the parent also seems duplicate (bug - should be fixed). Dump and reacquire
			if (count($meta) == 1 && $meta[0]->image_type == self::IMAGE_TYPE_DUPLICATE) {
				$this->deleteMeta();
			}
			$this->parent =  $duplicate_id;
		} elseif (count($meta) == 0) // no records, no object.
		{

			$duplicates = $this->getWPMLDuplicates();
			if (count($duplicates) > 0) //duplicates found, but not saved.
			{

				$in_str_arr = array_fill(0, count($duplicates), '%s');
				$in_str = join(',', $in_str_arr);

				$prepare = array_merge(array(self::IMAGE_TYPE_MAIN), $duplicates);

				$sql = 'SELECT attach_id FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE image_type = %d and attach_id in ( ' . $in_str . ') ';
				$sql = $wpdb->prepare($sql, $prepare);

				$parent_id = $wpdb->get_var($sql);

				if (is_numeric($parent_id)) {
					$this->createDuplicateRecord($this->id, $parent_id);

					$sqlPrep = $wpdb->prepare($sqlQuery, $parent_id);
					$meta = $wpdb->get_results($sqlPrep); // get the parent meta.
				} else {
					return false;
				}
			} else {
				return false;
			}
		}

		// Thumbnails

		// Mimic the previous SPixel solution regarding the return Metadata Object needed, with all thumbnails there.
		$metadata = new \stdClass;
		$metadata->image_meta = new \stdClass;
		$metadata->thumbnails = array();

		//$metadata = new \stdClass; // main image
		for ($i = 0; $i < count($meta); $i++) {
			$record = $meta[$i];

			// @todo Here goes all the table stuff looking like metadata objects.
			$data = new \stdClass;
			$data->databaseID = $record->id;
			$data->status = $record->status;
			$data->compressionType = $record->compression_type;
			$data->compressedSize = $record->compressed_size;
			$data->originalSize = $record->original_size;

			// @todo This needs to be Mysql TimeStamp -> Unix TS-ilized.
			$data->tsAdded = UtilHelper::DBtoTimestamp($record->tsAdded);
			$data->tsOptimized = UtilHelper::DBtoTimestamp($record->tsOptimized);

			// [...]
			$extra_info = json_decode($record->extra_info);

			// @todo Extra info should probably be stored as JSON?
			if (! is_null($extra_info)) {
				foreach ($extra_info as $name => $val) {
					$data->$name = $val;
				}

				if ($record->parent == 0 && $record->image_type == self::IMAGE_TYPE_MAIN) {
					// Database ID should probably also be stored for the thumbnails, so updating / insert into the database will be easier. We have a free primary key, so why not use it?
					$metadata->image_meta  = $data;
				} elseif ($record->parent == 0 && $record->image_type == self::IMAGE_TYPE_RETINA) {
					$metadata->retinas[$this->mainImageKey] = $data;
				} elseif ($record->parent > 0)  // Thumbnails
				{
					switch ($record->image_type) {
						case self::IMAGE_TYPE_THUMB:
							$metadata->thumbnails[$record->size] = $data;
							break;
						case self::IMAGE_TYPE_RETINA:
							$metadata->retinas[$record->size] = $data;
							break;
						case self::IMAGE_TYPE_ORIGINAL:
							$metadata->original_file = $data;
							break;
					}
				}
			} // extra info if
		} // loop

		return $metadata;
	}

	/**
	 * Persist the entire attachment family to shortpixel_postmeta.
	 *
	 * Emits one createRecord() call per member (main file + every thumbnail
	 * from getThumbObjects(), which spans thumbnails, retinas and the
	 * original), then hands the surviving database IDs to cleanupDatabase()
	 * so any rows we no longer track get pruned.
	 *
	 * IMAGE_TYPE_ORIGINAL rows are written with `size = null` — they own the
	 * "original" slot rather than a WP size slug.
	 *
	 * @return void
	 *
	 * @todo Test with retinas — they may not persist correctly because they
	 *       are keyed after their thumb name (or 0), not a distinct slug.
	 */
	protected function saveDBMeta()
	{
		$records = array();
		$records[] = $this->createRecord($this->toClass(), self::IMAGE_TYPE_MAIN);


		$thumbObjs = $this->getThumbObjects();
		foreach ($thumbObjs as $thumbObj) {

			$name = $thumbObj->get('name');
			$type = $thumbObj->get('imageType');

			if ($type == self::IMAGE_TYPE_ORIGINAL)
				$name = null;

			$records[] = $this->createRecord($thumbObj->toClass(), $type, $name);
		}

		$this->cleanupDatabase($records);
	}


	/**
	 * Insert or update a single shortpixel_postmeta row from a serialised
	 * meta object.
	 *
	 * The core columns (status, compression_type, sizes, timestamps) are
	 * pulled off `$data` into the fixed schema; everything else is JSON-
	 * encoded into the `extra_info` column. Null-valued fields are stripped
	 * from `extra_info` to keep the payload compact.
	 *
	 * IMAGE_TYPE_DUPLICATE rows carry an `attach_id` + `parent` payload
	 * inside `$data` so a duplicate can be created without the caller having
	 * to know about the wiring.
	 *
	 * When inserting, the resulting database ID is written back onto the
	 * appropriate in-memory object (main / thumbnail / retina / original) so
	 * subsequent saves update in place instead of re-inserting.
	 *
	 * @param \stdClass   $data      Serialised meta payload (see ImageMeta::toClass()).
	 * @param int         $imageType One of the ImageModel::IMAGE_TYPE_* constants.
	 * @param string|null $sizeName  WP size name for thumbnails / retinas; null for main and original.
	 * @return int Database ID of the inserted or updated row.
	 */
	private function createRecord($data, $imageType, $sizeName = null)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_postmeta';

		$attach_id = $this->id;

		$parent = ($imageType == self::IMAGE_TYPE_MAIN) ? 0 : $this->id;

		if ($imageType == self::IMAGE_TYPE_DUPLICATE) {
			$attach_id = $data->attach_id;
			$parent = $data->parent;

			unset($data->attach_id);
			unset($data->parent);
		}


		$fields = array(
			'attach_id' => $attach_id,
			'parent' => $parent,
			'image_type' => $imageType,
			'size' => $sizeName,
			'status' => $data->status,
			'compression_type' => $data->compressionType,
			'compressed_size' => $data->compressedSize,
			'original_size' => $data->originalSize,
			'tsAdded' => UtilHelper::timestampToDB($data->tsAdded),
			'tsOptimized' => UtilHelper::timestampToDB($data->tsOptimized),
		);

		unset($data->status);
		unset($data->compressionType);
		unset($data->compressedSize);
		unset($data->originalSize);
		unset($data->tsAdded);
		unset($data->tsOptimized);

		if (property_exists($data, 'databaseID') && intval($data->databaseID) > 0) {
			$databaseID = $data->databaseID;
			$insert = false;
		} else {
			$insert = true;
		}

		if (property_exists($data, 'databaseID')) // It can be null on init.
		{
			unset($data->databaseID);
		}

		if (property_exists($data, 'errorMessage')) {
			if (is_null($data->errorMessage) || strlen(trim($data->errorMessage)) == 0) {
				unset($data->errorMessage);
			}
		}

		foreach ($data as $index => $value) {
			if (is_null($value)) // don't store things that are null
			{
				unset($data->$index);
			}
		}

		$fields['extra_info'] = wp_json_encode($data); // everything else

		$format = array('%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s');

		if ($insert === true) {
			$result = $wpdb->insert($table, $fields, $format);
			$database_id = $wpdb->insert_id;

			if (false === $result) {
				Log::addError('Database error inserting metadata: ', $fields);
			}

			switch ($imageType) {
				case self::IMAGE_TYPE_MAIN:
				case self::IMAGE_TYPE_DUPLICATE:
					$this->setMeta('databaseID', $database_id);
					break;
				case self::IMAGE_TYPE_THUMB:
					$this->thumbnails[$sizeName]->setMeta('databaseID', $database_id);
					break;
				case self::IMAGE_TYPE_RETINA:
					$this->retinas[$sizeName]->setMeta('databaseID', $database_id);
					break;
				case self::IMAGE_TYPE_ORIGINAL:
					$this->original_file->setMeta('databaseID', $database_id);
					break;
			}
		} else {
			$result = $wpdb->update($table, $fields,  array('id' => $databaseID), $format, array('%d'));
			$database_id = $databaseID;
			if (false === $result) {
				Log::addError('Database error updating metadata: ', $fields);
			}
		}



		return $database_id;
	}

	/**
	 * Insert a stub IMAGE_TYPE_DUPLICATE record linking a WPML duplicate
	 * attachment to this one (its "leader").
	 *
	 * The stub has no size/compression/timestamp payload — all the real
	 * optimization data continues to live on the parent's rows; this record
	 * only exists so getDBMeta() can route the duplicate id to the parent.
	 *
	 * @param int      $duplicate_id ID of the duplicate attachment.
	 * @param int|null $parent       Parent attachment id; defaults to $this->id.
	 * @return void
	 */
	private function createDuplicateRecord($duplicate_id, $parent = null)
	{
		$data = new \StdClass;

		$data->parent = ($parent === null) ? $this->id : $parent;
		$data->attach_id = $duplicate_id;
		$imageType = self::IMAGE_TYPE_DUPLICATE;

		$data->status = null;
		$data->tsOptimized = null;
		$data->tsAdded = null;
		$data->compressionType = null;;
		$data->originalSize = null;
		$data->compressedSize = null;

		$this->parent = $data->parent;

		$this->imageType = self::IMAGE_TYPE_DUPLICATE;
		$this->createRecord($data, $imageType);
	}

	/**
	 * Delete any shortpixel_postmeta rows for this attachment that weren't
	 * touched by the current saveDBMeta() pass.
	 *
	 * The `array_filter(..., 'intval')` guard exists as a safety belt:
	 * an empty or zeroed `$records` list would produce a DELETE with no `id`
	 * exclusions and wipe out every row for this attachment, so we bail out
	 * instead.
	 *
	 * @param int[] $records Surviving database IDs from the current save pass.
	 * @return void
	 */
	private function cleanupDatabase($records)
	{
		global $wpdb;

		// Empty numbers might erase the whole thing.
		$records = array_filter($records, 'intval');
		if (count($records) == 0)
			return;


		$in_str_arr = array_fill(0, count($records), '%s');
		$in_str = join(',', $in_str_arr);

		$prepare = array_merge(array($this->id), $records);

		$sql = 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d and id not in (' . $in_str . ') ';
		$sql = $wpdb->prepare($sql, $prepare);

		$wpdb->query($sql);
	}



	/**
	 * Public entry point for persisting this attachment's SPIO meta.
	 *
	 * Currently a thin wrapper around saveDBMeta(); the extra indirection
	 * lets subclasses (or the abstract contract on ImageModel) hook in
	 * additional persistence without touching the DB code.
	 *
	 * @return void
	 */
	public function saveMeta()
	{
		$this->saveDBMeta();
	}

	/**
	 * Delete every shortpixel_postmeta row belonging to this attachment and
	 * clear any lingering "prevent optimization" flag.
	 *
	 * Does not touch WordPress metadata or files on disk — see onDelete()
	 * for the full-cleanup flow.
	 *
	 * @return int|false Number of rows deleted, or false on DB error.
	 */
	public function deleteMeta()
	{
		global $wpdb;

		$this->resetPrevent();


		$sql = 'DELETE FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %s';
		$sql = $wpdb->prepare($sql, $this->id);

		$bool = $wpdb->query($sql);

		return $bool;
	}

	/**
	 * Handle a WordPress attachment being deleted.
	 *
	 * When there are WPML duplicates the physical files are shared, so
	 * `$fileDelete` is forced to false and only meta is cleaned. Otherwise
	 * every member of the family is deleted:
	 *   - the pre-conversion placeholder file (if a legacy conversion left one),
	 *   - main file + every thumbnail + the unscaled original + every retina (via parent::onDelete),
	 *   - the AI-data record for the attachment,
	 *   - any legacy SPIO postmeta keys,
	 *   - the shortpixel_postmeta rows,
	 *   - and the queue entry so nothing tries to process it after deletion.
	 *
	 * @param bool $fileDelete Parameter kept for subclass signature compatibility; the effective value is derived from the WPML-duplicates check.
	 * @return void
	 */
	public function onDelete($fileDelete = false)
	{
		$WPMLduplicates = $this->getWPMLDuplicates();

		$fileDelete = (count($WPMLduplicates) == 0) ? true : false;
		$fs = \wpSPIO()->filesystem();

		// Load before removing meta.
		$isConverted = $this->getMeta()->convertMeta()->isConverted();

		$backupModel = $this->getBackupModel();


		// If file is converted, the backup path can live somewhere else ( on the converted item ), so search in this context instead of imagemodel which will only look for same extension backups.

		if (true === $this->getMeta()->convertMeta()->hasPlaceHolder()) {
			$placeholderFile = $fs->getFile($this->getFileDir() . $this->getMeta()->convertMeta()->getReplacementImageBase() . '.jpg');
			if (true === $placeholderFile->exists()) {
				$placeholderFile->delete();
			}
		}

		// @todo . Probably this should all be removed and moved to a general OnDelete in the BackupModels.
		if ($fileDelete === true)
			parent::onDelete($fileDelete);

		foreach ($this->thumbnails as $thumbObj) {
			if ($fileDelete === true)
				$thumbObj->onDelete($fileDelete);
		}

		if ($this->isScaled()) {
			$originalFile = $this->getOriginalFile();
			if ($fileDelete === true)
				$originalFile->onDelete($fileDelete);
		}

		if (! is_null($this->retinas)) {
			foreach ($this->retinas as $retinaObj) {
				if ($fileDelete === true) {
					$retinaObj->onDelete($fileDelete);
				}
			}
		}

		$aiModel = AiDataModel::getModelByAttachment($this->id);
		$aiModel->onDelete();

		$this->removeLegacy();
		$this->deleteMeta();
		$this->dropFromQueue();

	}

	/**
	 * Whether any member of the family (main file or any thumbnail) has an
	 * unpersisted meta change since load.
	 *
	 * Used by loadMeta() to decide whether an on-load repair should be
	 * flushed back to the database.
	 *
	 * @return bool
	 */
	protected function didAnyRecordChange()
	{
		if (true === $this->didRecordChange()) {
			return true;
		}

		$thumbObjs = $this->getThumbObjects();
		foreach ($thumbObjs as $thumbObj) {
			if (true === $thumbObj->didRecordChange()) {
				return true;
			} else {
			}
		}

		return false;
	}

	/**
	 * Clear the "changed since load" flag on the main file and every
	 * thumbnail, typically called right after a successful saveMeta().
	 *
	 * @return void
	 */
	protected function resetRecordChanges()
	{
		$this->recordChanged(false);

		$thumbObjs = $this->getThumbObjects();
		foreach ($thumbObjs as $thumbObj) {
			$thumbObj->recordChanged(false);
		}
	}

	/**
	 * Remove this attachment from both the regular queue and the bulk queue.
	 *
	 * Called from onDelete() so a deletion doesn't leave a ghost queue entry
	 * that will fail on next drain.
	 *
	 * @return void
	 */
	public function dropFromQueue()
	{
		$queueController = new QueueController();

		$q = $queueController->getQueue($this->type);
		$q->dropItem($this->get('id'));

		// Drop also from bulk if there.

		$queueController = new QueueController(['is_bulk' => true]);
		$q = $queueController->getQueue($this->type);
		$q->dropItem($this->get('id'));
	}

	/**
	 * Return the MediaLibraryThumbnailModel for a WP size name, or false when
	 * no thumbnail with that name exists.
	 *
	 * @param string $name WP size name (e.g. 'thumbnail', 'medium').
	 * @return MediaLibraryThumbnailModel|false
	 */
	public function getThumbNail($name)
	{
		if (isset($this->thumbnails[$name]))
			return $this->thumbnails[$name];

		return false;
	}

	/**
	 * Whether *anything* in this attachment family could still be processed.
	 *
	 * The check runs top-down:
	 *   1. parent::isProcessable() for the main file — fast, and short-circuits when true.
	 *   2. Date-exclusion rule — a matching rule returns false regardless.
	 *   3. `$strict = true` stops here (main file only, thumbnails ignored).
	 *   4. optimizePrevented → always false.
	 *   5. Main file not processable → walk the thumbnails. Any processable
	 *      thumbnail, or any optimized thumbnail that still has a missing
	 *      WebP/AVIF variant, wins. Non-image main extensions (e.g. `webp`
	 *      main + `jpg` thumbs) still return false because getOptimizeData
	 *      would produce an empty URL list.
	 *      Fatal thumbnail states (missing file, unwritable directory) are
	 *      surfaced on `$this->processable_status` so the UI reports them
	 *      instead of the misleading "image already optimized".
	 *   6. Otherwise, if the main image is optimized but a WebP/AVIF variant
	 *      is still needed, the family is processable — unless the directory
	 *      is not writable.
	 *
	 * @param bool $strict When true, ignore thumbnails and only report on the main file.
	 * @return bool
	 */
	public function isProcessable($strict = false)
	{
		$main_bool = $bool = parent::isProcessable();

		$settings = \wpSPIO()->settings();

		if (false !== $this->checkDateExcluded())
		{
			$date_bool = $this->isDateExcluded();
			if (true === $date_bool)
			{
				 return false; 
			}
		}

		// If already true, this item can be processed. No need for further checks.
		if ($strict || true === $bool) {
			return $bool;
		}

		// Never allow optimizePrevented to be processable
		if (true === $this->isOptimizePrevented()) {
			return false;
		}

		if (! $bool) // if parent is not processable, check if thumbnails are, can still have a work to do.
		{

			// This is an extra check for a specific bug. If the metadata is mismatched and there is a processable thumbnails (ie jpeg), but the main file is of a non-processable type ( ie webp ), it shows as processable but will not build an URL list because of the convert rule in getOptimizeData
			if (false === in_array($this->getExtension(), ImageModel::PROCESSABLE_EXTENSIONS)) {
				return false;
			}

			$thumbObjs = $this->getThumbObjects();

			foreach ($thumbObjs as $thumbnail) {

				$bool = $thumbnail->isThumbnailProcessable();

				if ($bool === true) // Is Processable just needs one job
					return true;

				if ($thumbnail->isOptimized() && true === $thumbnail->isProcessableAnyFileType())
					return true;

				if (false === $bool)
				{
					// These statii might in other situations ( i.e wp-cli ) looks processable, but if not warn the user. When the main image is optimized, but thumbnails are prevented somehow it will otherwise return 'image already optimized', which is confusing. 
					$status = $thumbnail->processable_status; 
					$warnable_statii = [ImageModel::P_FILE_NOT_EXIST, ImageModel::P_DIRECTORY_NOTWRITABLE, ImageModel::P_BACKUPDIR_NOTWRITABLE, ImageModel::P_FILE_NOTWRITABLE];

					if (in_array($status, $warnable_statii ))
					{
						$this->processable_status = $status;
					}
				}
			}
		}

		// First test if this file isn't unprocessable for any other reason, then check.  Strict_bool is the result of the main image, and should not be updated / rechecked
		if ((true === $main_bool || $this->processable_status === ImageModel::P_IS_OPTIMIZED) && $this->isProcessableAnyFileType() === true) {
			if (false === $this->is_directory_writable()) {
				$this->processable_status = ImageModel::P_DIRECTORY_NOTWRITABLE;
				$bool = false;
			} else {
				$bool = true;
			}
		}

		return $bool;
	}

	/**
	 * Whether *anything* in this attachment family can still be restored
	 * from backup.
	 *
	 * Defers to parent::isRestorable() first (which handles the main file
	 * itself). If the main file isn't restorable, walks each optimized
	 * thumbnail and finally the unscaled original — one restorable member
	 * is enough for the family to be considered restorable.
	 *
	 * @return bool
	 */
	public function isRestorable() : bool
	{
		$bool = true;
		$bool = parent::isRestorable();


		if (! $bool) // if parent is not processable, check if thumbnails are, can still have a work to do.
		{
			foreach ($this->thumbnails as $thumbnail) {
				if (! $thumbnail->isOptimized())
					continue;

				$bool = $thumbnail->isRestorable();

				if ($bool === true) // Is Restorable just needs one job
					return true;
			}
			if ($this->isScaled() && ! $bool) {
				$originalFile = $this->getOriginalFile();
				$bool = $originalFile->isRestorable();
			}
		}

		return $bool;
	}

	/**
	 * Pre-conversion hook: create the backup(s) needed to make a format
	 * conversion (PNG→JPG, BMP→JPG, HEIC→JPG, etc.) reversible, and record
	 * the attempt on convertMeta so a failure doesn't retry forever.
	 *
	 * Always runs (regardless of the backup setting): loads this attachment
	 * into the BackupModel via `loadMediaItem($this)` so the backup layer
	 * knows about any pending replacementImageBase before it's asked to
	 * copy files.
	 *
	 * When backups are enabled:
	 *   - the main file (or the unscaled original if scaled) is backed up first;
	 *     on failure, records the error on convertMeta, saves, and bails.
	 *   - every thumbnail is backed up too, unless `backup_thumbnails=false`
	 *     (e.g. BMP conversion where the thumbnails are BMPs of no interest).
	 *
	 * Either way saveMeta() runs at the end so filesizes are stored on the
	 * meta even if the physical files are subsequently offloaded and deleted.
	 *
	 * @param array{checksum?: int|string, replacementPath?: string|null, backup_thumbnails?: bool} $args Converter-specific hints. `checksum` is recorded on convertMeta so the same file isn't re-tried.
	 * @return bool True when preparation succeeded (or backups aren't required), false when a backup failed.
	 */
	public function conversionPrepare($args = [])
	{
		$settings = \wpSPIO()->settings();
		$bool = false;

		$defaults = array(
			'checksum' => 1, // use by pngconverter
			'replacementPath' => null, // use by apiconverter, but no effect (@todo ?)
			'backup_thumbnails' => true,  // used by bmpconverter, no specials for thumbs
		);
		$args = wp_parse_args($args, $defaults);

		$backupModel = $this->getBackupModel();
		$backupModel->loadMediaItem($this); // Passes the replacementImageBase if this is different than the current.
		
		if (1 == $settings->backupImages) {
			// only one file needed.
			if ($this->isScaled()) {
				$backupok = $this->getOriginalFile()->createBackup();
			} else {
				$backupok = $this->createBackup();
			}

			if (! $backupok) {
				$response = array(
					'is_error' => true,
					'item_type' => ResponseController::ISSUE_FILE_NOTWRITABLE,
					'message ' => __('ConvertPrepare could not create backup. Please check file permissions', 'shortpixel-image-optimiser'),
				);
				ResponseController::addData($this->get('id'), $response);

				// Bail out with setting flag, so not to repeat.
				$this->getMeta()->convertMeta()->setTried($args['checksum']);
				$this->getMeta()->convertMeta()->setError(Converter::ERROR_BACKUPERROR);

				$this->saveMeta();

				return false;
			}


			if (true === $args['backup_thumbnails']) {
				$thumbObjs = $this->getThumbObjects();
				foreach ($thumbObjs as $thumbObj) {
					$result = $thumbObj->createBackup();
					if (false === $result) {
						Log::addWarning('Backup failed on Thumbitem ' . $thumbObj->getFullPath());
					}
				} // foreach
			} // args
		}

		// Saving Meta to keep filesizes in case everything is offload-deleted.
		$this->saveMeta();

		return true;
	}

	/**
	 * Post-conversion hook for a failed conversion.
	 *
	 * Cleans up the backups that conversionPrepare() created (since the
	 * usual restore-flow won't fire — the image was never optimized), then
	 * records the attempt on convertMeta so the next queue pass doesn't
	 * try again, clears the replacementImageBase and flushes the optimize
	 * cache before persisting.
	 *
	 * @param array{checksum?: int|string} $args Converter-specific hints. `checksum` is stored on convertMeta as the "already tried" marker.
	 * @return void
	 */
	public function conversionFailed($args = array())
	{
		$settings = \wpSPIO()->settings();

		$defaults = array('checksum' => 1);
		$args = wp_parse_args($args, $defaults);

		if ($settings->backupImages == 1) {

			$backupModel = $this->getBackupModel(); 

			// When failed, delete the backups. This can't be done via restore since image is not optimized.
			$backupModel->onDelete($this);
			
			$thumbObjs = $this->getThumbObjects();
			foreach ($thumbObjs as $thumbnail) {
				$backupModel->onDelete($thumbnail);
			}
		}
		// Prevent from retrying next time, since stuff will be requeued.
		$this->getMeta()->convertMeta()->setTried($args['checksum']);
		$this->getMeta()->convertMeta()->setReplacementImageBase(false);

		$this->flushOptimizeData();
		$this->saveMeta();
	}

	/**
	 * Post-conversion hook for a successful conversion.
	 *
	 * Flow:
	 *   1. Marks convertMeta as done (with the `omit_backup` policy for the
	 *      converted file — see ImageConvertMeta::setConversionDone).
	 *   2. Swaps the main file over to the new `.jpg` path, deletes the old
	 *      source, and resets the file info so subsequent size / mime reads
	 *      reflect the JPEG.
	 *   3. Rebuilds the thumbnail collection from wp metadata (converters
	 *      typically regenerate thumbnails through WP core, so the size
	 *      registration may have changed).
	 *   4. Unless `skip_thumbnails=true`, walks the new thumbnails and does
	 *      the same swap for each: point at the new `.jpg` file, delete the
	 *      old-extension companion, refresh file info.
	 *   5. Invalidates every cache that could still hold the old extension
	 *      (wp_metadata, filesystem image cache, optimize-data) and records
	 *      the checksum on convertMeta so a re-queue doesn't re-attempt.
	 *
	 * @param array{checksum?: int|string, omit_backup?: bool, skip_thumbnails?: bool} $args Converter hints. Defaults: `omit_backup=true`, `skip_thumbnails=false`.
	 * @return void
	 */
	public function conversionSuccess($args = array())
	{
		$fs = \wpSPIO()->filesystem();
		$defaults = array(
			'checksum' => 1,
			'omit_backup' => true,
			'skip_thumbnails' => false,
		);

		$args = wp_parse_args($args, $defaults);

		$this->getMeta()->convertMeta()->setConversionDone($args['omit_backup']);
		$mainfile = \wpSPIO()->filesystem()->getfile($this->getFileDir() . $this->getFileBase() . '.jpg');

		if ($this->exists()) // success, remove converted file.
		{
			$this->delete(); // remove the old file.
			$this->fullpath = $mainfile->getFullPath();
			$this->resetStatus();
			$this->setFileInfo();
		}

		// After Convert, reload new meta.
		$this->thumbnails = $this->loadThumbnailsFromWP();
		$this->retinas = null;


		if (false === $args['skip_thumbnails']) {
			$thumbnails = $this->getThumbObjects();

			foreach ($thumbnails as $thumbObj) {
				// Delete thumbnail with the old extension, if exists.
				$file = $fs->getFile($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.jpg');

				if ($thumbObj->exists()) // if new exists, remove old
				{
					if ($thumbObj->getExtension() !== 'jpg') {
						$thumbObj->delete(); // remove the old file.
					}
					$thumbObj->fullpath = $file->getFullPath();
					$thumbObj->resetStatus();
					$thumbObj->setFileInfo();
				}
			}
		}

		$this->wp_metadata = null;  // Remove caching on this one.

		$fs->flushImageCache();
		$this->flushOptimizeData();
		$this->getMeta()->convertMeta()->setTried($args['checksum']);
	// Commented this, unclear why replaceBase is removed after succesfull conversion?
	//	$this->getMeta()->convertMeta()->setReplacementImageBase(false);

		$this->saveMeta();
	}

	/**
	 * Detect and record the WP 5.3+ unscaled original file, when present.
	 *
	 * The original is rejected in two cases:
	 *   - the extension differs *and* is in the "difficult" set (BMP / TIFF)
	 *     where WordPress converts the main file to JPEG at upload but leaves
	 *     an original in a format we can't reliably back up alongside it;
	 *   - the resolved original path equals the current main path (WP didn't
	 *     actually create a `-scaled` variant, so there's no separate original).
	 *
	 * On success, sets $original_file + $is_scaled and tags the original with
	 * the reserved size name so it appears in size-keyed payloads.
	 *
	 * @return false|void False when there is no attachment id yet; otherwise void.
	 */
	protected function setOriginalFile()
	{
		$fs = \wpSPIO()->filesystem();

		if (is_null($this->id))
			return false;

		$originalFile = $fs->getOriginalImage($this->id);
		$originalFile->setName($this->originalImageKey); // required for named API requests et al.
		$originalFile->setImageType(self::IMAGE_TYPE_ORIGINAL);

		// WordPress converts by default in new version s HEIC / BMP to JPG, but leaves the originalFile as Heic, ignore it then. 
		if ($originalFile->getExtension() !== $this->getExtension())
		{
			// $difficult_extensions = ['heic', 'heif', 'bmp', 'tiff']; 
			// Heic / Heif removes here because of backup conflicts ( WP created all thumbnails, but not the main file which is problematic)
			$difficult_extensions = ['bmp', 'tiff']; 
			if (in_array($originalFile->getExtension(), $difficult_extensions))
			{
				return false; 
			}			  
		} 

		if ($originalFile->exists() && $originalFile->getFullPath() !== $this->getfullPath()) {
			$this->original_file = $originalFile;
			$this->is_scaled = true;
		}
	}

	/**
	 * Whether this attachment has a WP 5.3+ unscaled original companion.
	 *
	 * @return bool
	 */
	public function hasOriginal() : bool
	{
		if ($this->original_file)
			return true;
		else
			return false;
	}

	/**
	 * Return the unscaled original companion, or false when none exists.
	 *
	 * @return MediaLibraryThumbnailModel|false
	 */
	public function getOriginalFile()
	{
		if ($this->hasOriginal())
			return $this->original_file;
		else
			return false;
	}


	/**
	 * Return the parent attachment ID when this instance is a WPML
	 * duplicate, or false otherwise.
	 *
	 * @return int|false
	 */
	public function getParent()
	{
		if (is_null($this->parent)) {
			return false;
		}
		if (is_numeric($this->parent)) {
			return $this->parent;
		}

		// Dunno.
		return false;
	}


	/**
	 * Return the IDs of language-duplicate attachments for this one.
	 *
	 * Supports both WPML (via icl_translations trid siblings) and Polylang
	 * (via matching guid + attachment post type). Duplicates that resolve
	 * to a different physical file are filtered out — WPML translations
	 * can legitimately point at unrelated images and we only want the ones
	 * that share the same file on disk so their meta stays in sync.
	 *
	 * The legacy `_icl_lang_duplicate_of` fallback has been removed.
	 *
	 * @return int[] Deduplicated list of duplicate attachment IDs (never contains $this->id).
	 */
	public function getWPMLDuplicates()
	{
		global $wpdb;
		$env = \wpSPIO()->env();

		$duplicates = array();

		if ($env->plugin_active('wpml')) {
			$sql = "select element_id from " . $wpdb->prefix . "icl_translations where trid in (select trid from " . $wpdb->prefix . "icl_translations where element_id = %d) and element_id <> %d";

			$sql = $wpdb->prepare($sql, $this->id, $this->id);
			$results = $wpdb->get_results($sql);

			if (is_array($results)) {
				foreach ($results as $result) {
					if ($result->element_id == $this->id)  // don't select your own.
					{
						continue;
					}
					//$duplicateFile = $fs->getMediaImage($result->element_id);

					// Check if the path is the same. WPML translations can be linked to different images, so this is important.
					// Add. Prev. it loaded to whole media Image but this doesn't go well with loadDbMeta checks, so a rougher check now to see if files are similar. In any case if not identifical, should not be threated as such
					if (get_attached_file($this->id) == get_attached_file($result->element_id)) {
						$duplicates[] = $result->element_id;
					}
				}
			}
		}  // polylang
		if ($env->plugin_active('polylang')) // polylang
		{
			// unholy sql where guid is duplicated.
			$sql = 'SELECT id FROM ' . $wpdb->prefix . 'posts WHERE guid in (select guid from ' . $wpdb->prefix . 'posts where id = %d ) and post_type = %s and id <> %d';

			$sql = $wpdb->prepare($sql, $this->id, 'attachment', $this->id);
			$results = $wpdb->get_col($sql);

			foreach ($results as $index => $element_id) {
				$duplicates[] = intval($element_id);
			}
		}

		return array_unique($duplicates);
	}

	/**
	 * Persistently mark this attachment as "do not auto-optimize", surfacing
	 * a reason string on `_shortpixel_prevent_optimize` post meta.
	 *
	 * The flag survives across requests — clear it via resetPrevent() from
	 * the "Retry" button on the admin UI.
	 *
	 * @param string|int $reason Human-readable reason (or truthy sentinel).
	 * @param int        $status FILE_STATUS_* code stored on image_meta->status; defaults to FILE_STATUS_PREVENT.
	 * @return void
	 */
	protected function preventNextTry($reason = 1, $status = self::FILE_STATUS_PREVENT)
	{
		Log::addWarn($this->get('id') . ' preventing next try: ' . $reason);

		update_post_meta($this->id, '_shortpixel_prevent_optimize', $reason);
		$this->setMeta('status', $status);
		$this->saveMeta();
	}

	/**
	 * Semantic wrapper around preventNextTry() used when an image is being
	 * marked as intentionally completed (e.g. FILE_STATUS_MARKED_DONE) rather
	 * than blocked after a fatal error.
	 *
	 * @param string|int $reason Human-readable reason.
	 * @param int        $status FILE_STATUS_* code to persist.
	 * @return void
	 */
	public function markCompleted($reason, $status)
	{
		return $this->preventNextTry($reason, $status);
	}

	/**
	 * Whether the `_shortpixel_prevent_optimize` flag is set on this
	 * attachment.
	 *
	 * On a positive hit, side-effects: sets $processable_status to
	 * P_OPTIMIZE_PREVENTED, stores the reason on $optimizePreventedReason so
	 * getProcessableReason() can surface it, and caches the answer on
	 * $optimizePrevented so repeat calls avoid the post-meta read.
	 *
	 * @return bool
	 */
	public function isOptimizePrevented()
	{
		if (! is_null($this->optimizePrevented)) {
			return $this->optimizePrevented;
		}

		$reason = get_post_meta($this->id, '_shortpixel_prevent_optimize', true);

		if ($reason === false || strlen($reason) == 0) {
			$this->optimizePrevented = false;
			return false;
		} else {
			$this->processable_status = self::P_OPTIMIZE_PREVENTED;
			$this->optimizePreventedReason  = $reason;
			$this->optimizePrevented = true;
			return true;
		}
	}

	/**
	 * Whether this attachment's post_date falls on the wrong side of a
	 * configured date-exclusion rule.
	 *
	 * The rule payload comes from checkDateExcluded() (a `date` + `when`
	 * pair). `when=before` excludes attachments whose post_date is earlier
	 * than the rule date; `when=after` (the default) excludes ones later
	 * than it. An unparseable rule date logs an error and returns false so
	 * the image doesn't get silently skipped.
	 *
	 * Sets $processable_status to P_EXCLUDE_DATE on match.
	 *
	 * @return bool
	 */
	protected function isDateExcluded()
	{
		 $options = $this->checkDateExcluded();

		 $post = get_post($this->id); 
		 $date = $post->post_date; 

		$postDate = new \DateTime($date);

		 try{
			$date = new \DateTime($options['date']); 
		 }
		 catch(\Exception $e)
		 {
			 Log::addError('Date exclusion - not valid date'); 
			 return false; 
		 }

		 $when = isset($options['when']) ? $options['when'] : 'before'; 

		 $bool = false; 

		 switch($when)
		 {
			 case 'before':
				if ($date->format('U') > $postDate->format('U'))
				{
					$bool = true; 
				}
			 break; 
			 case 'after': 
			 default:
			 if ($date->format('U') < $postDate->format('U'))
				{
					$bool = true; 
				}
			 break; 
		 }

		if (true === $bool)
		{
			 $this->processable_status = ImageModel::P_EXCLUDE_DATE; 
		}

		 return $bool; 

	}

	/**
	 * Backwards-compat shim for the old Regenerate Thumbnails Advanced integration.
	 *
	 * Modern code should call `$this->getBackupModel()->hasBackup($this)`
	 * directly. This wrapper logs a warning on every call so lingering usage
	 * can be tracked down.
	 *
	 * @deprecated Retained only for old RTA. DO NOT USE in new code.
	 * @return bool|\ShortPixel\Model\File\FileModel
	 */
	public function hasBackup()
	{
		Log::addWarn('Has Backup called on MediaLibraryModel - This should not happen');
		 $backupModel = $this->getBackupModel();
		 return $backupModel->hasBackup($this);
	}

	/**
	 * Backwards-compat shim to fetch the main file's backup as a FileModel.
	 *
	 * Modern code should call the BackupController directly.
	 *
	 * @deprecated See hasBackup(). Logs a warning on every call.
	 * @return \ShortPixel\Model\File\FileModel|false
	 */
	public function getBackupFile()
	{
		Log::addWarn('GetBackupFile called on MediaLibraryModel - This should not happen');
		 $backupModel = $this->getBackupModel();
		 $file = $backupModel->hasBackup($this);

		 if (false === is_object($file))
		 {
			 return false;
		 }

		 return $file;

	}



	/**
	 * Whether any member of the family (main file or any thumbnail) is
	 * currently optimized.
	 *
	 * The main file alone can no longer be relied on, because the user can
	 * exclude it while thumbnails were previously optimized — so restore /
	 * re-optimize UI options need to be shown based on the whole family.
	 *
	 * @return bool
	 */
	public function isSomethingOptimized()
	{
		if ($this->isOptimized())
			return true;

		$thumbs = $this->getThumbObjects();
		foreach ($thumbs as $thumbObj) {
			if ($thumbObj->isOptimized()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the first optimized member of the family — the main file when
	 * possible, or the earliest optimized thumbnail otherwise.
	 *
	 * Used by UI flows that need "the file behind the optimization badge"
	 * without caring which specific size it came from.
	 *
	 * @return self|MediaLibraryThumbnailModel|false
	 */
	public function getSomethingOptimized()
	{
		if ($this->isOptimized())
		{
			return $this;
		}

		$thumbs = $this->getThumbObjects();
		foreach ($thumbs as $thumbObj) {
			if ($thumbObj->isOptimized()) {
				return $thumbObj;
			}
		}
		return false;
	}

	/**
	 * Clear the "prevent auto-optimization" flag on this attachment.
	 *
	 * Removes the `_shortpixel_prevent_optimize` post meta, resets the
	 * cached `optimizePrevented` flag, and re-sets a negative status back to
	 * FILE_STATUS_UNPROCESSED so the queue will pick the item up again.
	 * Typically wired to the "Retry" button in the admin UI.
	 *
	 * @return void
	 */
	public function resetPrevent()
	{
		delete_post_meta($this->id, '_shortpixel_prevent_optimize');

		if ($this->getMeta('status')  < 0) {
			$this->setMeta('status', self::FILE_STATUS_UNPROCESSED);
			$this->saveMeta();
		}

		$this->optimizePrevented = null;
	}

	/**
	 * Restore every optimized file in this attachment family from its backup,
	 * roll back any format conversion, delete generated WebP/AVIF companions,
	 * and re-sync the resulting state to WordPress + WPML duplicates.
	 *
	 * Pipeline:
	 *   1. Fire the pre-restore actions (`shortpixel_before_restore_image`
	 *      and `shortpixel/image/before_restore`) so integrations can react
	 *      before the file changes.
	 *   2. Snapshot state that will be wiped by parent::restore() —
	 *      WPML duplicates, resize flag, and the convertMeta object.
	 *   3. Run parent::restore() to restore the main file. If it was
	 *      converted (and the converted format wasn't kept), restore the
	 *      unscaled original first (needed by the Replacer), then call
	 *      restoreConversion() to swap extensions and update WP attachment
	 *      metadata.
	 *   4. If convertMeta.isConverted && !omitBackup (i.e. we're keeping
	 *      the converted file), mark the restore as "not clean" so
	 *      convertMeta stays on the meta after the DB wipe check below.
	 *   5. Walk thumbnails, restoring each; when several thumbnails share a
	 *      filebase (duplicate sizes), only the first restore actually
	 *      writes the file. Update wp meta size/filesize for standard
	 *      thumbnails only.
	 *   6. Do the same walk for retinas and the unscaled original.
	 *   7. Delete non-source WebP / AVIF companions (except when the main
	 *      file's own extension IS webp/avif — deleting it would be wrong).
	 *   8. Remove legacy meta keys, then either deleteMeta() (clean restore
	 *      — nothing left to track) or saveMeta() (partial restore).
	 *   9. If the backup model requires it, regenerate thumbnails via
	 *      generateThumbnails() and refresh wp meta accordingly.
	 *  10. Update `_wp_attachment_metadata`, flush the postmeta cache
	 *      (offload plugins re-cache on read), fire post-restore actions,
	 *      then propagate the entire result to every WPML duplicate.
	 *
	 * @param array $args Reserved for subclass compatibility; currently unused.
	 * @return bool Result of the last-restored member. See @todo in source about the edge case where this reports failure even when everything else succeeded.
	 */
	public function restore($args = [])
	{
		$fs = \wpSPIO()->filesystem();

		do_action('shortpixel_before_restore_image', $this->get('id'));
		do_action('shortpixel/image/before_restore', $this);

		$cleanRestore = true;
		$wpmeta = wp_get_attachment_metadata($this->get('id'));
		$restored = [];

		
		// Get them early in case the filename changes ( ie png to jpg ) because it will stop getting it.
		$WPMLduplicates = $this->getWPMLDuplicates();

		$is_resized = $this->getMeta('resize');
		$convertMeta = $this->getMeta()->convertMeta();
		$was_converted = $convertMeta->isConverted() && true == $convertMeta->omitBackup();

		// ** Warning - This will also reset metadata ****
		$bool = $is_main_restore_ok =  parent::restore();

		// @todo The restoreConversion here - which does call for the replacer is probably the reason only the main file is replaced back
		// Should probably be after the needsgenerate call has finished? 
		// This needs to be here, to be able to translate NewFile to path still (?) 
		if ($was_converted) {
			if ($is_main_restore_ok) {

				// The scaled needs to be restored before the replacer can work
				if ($this->isScaled()) {
					$originalFile = $this->getOriginalFile();
					if ($originalFile->isRestorable()) {
					$restored[$originalFile->getFileBase()] = true; 	
					$bool = $originalFile->restore();		
					}
				}

				$mediaModel = clone $this; 
				$mediaModel->getMeta()->convertMeta()->fromClass($convertMeta);

				$converter = Converter::getConverter(clone $this); // ugly, but no way around.
				
				// @ TODO !! The problem lies here, the restoreConversion doesn't change the filepath back to PNG as it used to.
				$bool = $this->restoreConversion($convertMeta, $converter);

				$wpmeta = wp_get_attachment_metadata($this->get('id')); // png2jpg resets WP metadata.

				$this->resetStatus();
				$this->setFileInfo();
			} else {
				Log::addWarn('Restoring with conversion, but parent was not restored correctly');
				return $bool;
			}
		} 

		// From ThumbnailModel, prevent cleaning all metadata if there is converted item.
		if (true === $this->getMeta()->convertMeta()->isConverted() && false === $this->getMeta()->convertMeta()->omitBackup()) {
			$cleanRestore = false;
		}

		if ($is_resized) {
			$wpmeta['width'] = $this->get('width');
			$wpmeta['height'] = $this->get('height');
		}
		$wpmeta['filesize'] = $this->getFileSize();

		// Loading this early because of all the resetting.
		$webps = $this->getWebps();
		$avifs = $this->getAvifs();


		if (! $bool) {
			$cleanRestore = false;
		}

		foreach ($this->thumbnails as $thumbObj) {
			$filebase = $thumbObj->getFileBase();
			$is_resized = $thumbObj->getMeta('resize');
			$size = $thumbObj->get('size');
			$unlisted_file = $thumbObj->getMeta('file');

			// **** AFTER THIS IMAGE DATA IS WIPED! **** /
			if (isset($restored[$filebase])) {
				$bool = true;  // this filebase already restored. In case of duplicate sizes.
				$thumbObj->image_meta = new ImageThumbnailMeta();
			} elseif ($thumbObj->isRestorable()) {
				$bool = $thumbObj->restore(); // resets metadata
				if (! $bool) {
					$cleanRestore = false;
				} else {
					$restored[$filebase] = true;
				}
			} else {
				// Normal occurence when thumbnails have no backup / not optimized.
				//Log::addWarn('Thumbnail not restorable ' . $size,  $this->getReason('restorable'));
			}

			if ($unlisted_file === null) {

				if ($is_resized) {
					$wpmeta['sizes'][$size]['width'] = $thumbObj->get('width');
					$wpmeta['sizes'][$size]['height']  = $thumbObj->get('height');
				}

				$wpmeta['sizes'][$size]['filesize'] = $thumbObj->getFileSize();
			}

			$thumbObj->resetStatus();
			$thumbObj->setFileInfo();
		}

		if (! is_null($this->retinas)) {
			$restored = array();

			foreach ($this->retinas as $name => $retinaObj) {
				$filebase = $retinaObj->getFileBase();
				$size = $retinaObj->get('size');

				if (isset($restored[$filebase])) {
					$bool = true;  // this filebase already restored. In case of duplicate sizes.
					$retinaObj->image_meta = new ImageThumbnailMeta();
				} elseif ($retinaObj->isRestorable()) {
					$bool = $retinaObj->restore();

					if (! $bool) {
						$cleanRestore = false;
					} else {
						$restored[$filebase] = true;
					}
				}
			}
		}

		if ($this->isScaled()) {
			$originalFile = $this->getOriginalFile();
			if ($originalFile->isRestorable() && false === isset($restored[$originalFile->getFileBase()]) ) {
				$bool = $originalFile->restore();
			}
		}

		if ('webp' !== $this->getExtension()) {

			foreach ($webps as $webpFile) {
				if ($webpFile->exists() && false === $webpFile->is_virtual())
					$webpFile->delete();
			}
		}

		if ('avif' !== $this->getExtension()) {
			foreach ($avifs as $avifFile) {
				if ($avifFile->exists() && false === $avifFile->is_virtual())
					$avifFile->delete();
			}
		}

		// Any legacy will have false information by now; remove.
		$this->removeLegacy();

		/* If some backup where not present (ie single file backup ), the thumbs need to be regenerated. 
		*  Issue with that is that metadata will stop corresponding with saved SPIO meta, so this needs to be wiped.
		* Anything with 'cleanRestore' would need to be solved by this. 
		* - Check for was_converted, because unconverting also runs news thumbnails.
		*/
		$backupModel = $this->getBackupModel(); 
		if (true === $backupModel->needsRegenerate())
		{
			$this->generateThumbnails();
			$wpmeta = wp_get_attachment_metadata($this->get('id'));
			$cleanRestore = true; 
		}


		if (true === $cleanRestore) {
			$this->deleteMeta();
		} else {
			$this->saveMeta(); // Save if something is not restored.
		}

		update_post_meta($this->get('id'), '_wp_attachment_metadata', $wpmeta);
		$flushed = update_postmeta_cache([$this->id]); // attempt to flush cache because offload refetches again

		do_action('shortpixel_after_restore_image', $this->id, $cleanRestore); // legacy
		do_action('shortpixel/image/after_restore', $this, $this->id, $cleanRestore);

		if (is_array($WPMLduplicates) && count($WPMLduplicates) > 0) {
			$current_id = $this->id;

			foreach ($WPMLduplicates as $duplicate_id) {
				$this->id = $duplicate_id;
				//$this->removeLegacy(); // RemoveLegacy upwards already removed.

				$duplicate_meta = wp_get_attachment_metadata($duplicate_id);
				$duplicate_meta = array_merge($duplicate_meta, $wpmeta);
				update_post_meta($duplicate_id, '_wp_attachment_metadata', $duplicate_meta);

				if ($cleanRestore) {
					$this->deleteMeta();
				} else {
					$this->saveMeta();
				}
				do_action('shortpixel_after_restore_image', $this->id, $cleanRestore);
				do_action('shortpixel/image/after_restore', $this, $this->id,  $cleanRestore);
			}
			$this->id = $current_id;
		}

		// @todo Restore can be false if last item failed, which doesn't sound right.
		return $bool;
	}

	/**
	 * Whether a shortpixel_postmeta row exists for the main file specifically.
	 *
	 * Overrides the thumbnail-model version because the main file is
	 * distinguished by `size IS NULL` + `image_type = IMAGE_TYPE_MAIN`,
	 * not by a size slug.
	 *
	 * @return bool
	 */
	public function hasDBRecord()
	{

		global $wpdb;

		$sql = 'SELECT id FROM ' . $wpdb->prefix . 'shortpixel_postmeta WHERE attach_id = %d AND size IS NULL and image_type = %d';
		$sql = $wpdb->prepare($sql, $this->id, self::IMAGE_TYPE_MAIN);

		$id = $wpdb->get_var($sql);

		if (is_null($id)) {
			return false;
		} elseif (is_numeric($id)) {
			return true;
		}
	}


	/**
	 * Roll back a legacy PNG→JPG (or similar) conversion after the backup
	 * has already been copied back to uploads.
	 *
	 * The parent restore has already put a `.jpg` file at the source's
	 * position and cleared the meta, so this method:
	 *   - Resolves the "true" destination file (with the pre-conversion
	 *     extension) via the ImageConvertMeta payload.
	 *   - Queues the converted-format main file + every thumbnail for
	 *     deletion — but defers the deletes until the end because some
	 *     plugins (e.g. Polylang) block deletion of files still attached to
	 *     an attachment.
	 *   - Runs the actual deletes with a dedup guard so shared filebases
	 *     (duplicate sizes) don't trigger a second failing unlink().
	 *   - Delegates the extension-swap + attachment update to the
	 *     Converter's own restore() method.
	 *   - Invalidates wp_metadata and reloads the thumbnail collection so
	 *     the next access sees the restored-format family.
	 *
	 * @param ImageConvertMeta $convertMeta Snapshot of the conversion state taken before parent::restore() wiped the meta.
	 * @param \ShortPixel\Model\Converter\Converter $converter Converter matching the recorded format.
	 * @return bool True; the current implementation has no failure return.
	 *
	 * @todo Move this logic to the BackupModel; the thumbObj loop is the
	 *       reason it hasn't happened yet.
	 */
	protected function restoreConversion($convertMeta, $converter)
	{
		$fs = \wpSPIO()->filesystem();
		$ext = $convertMeta->getFileFormat();
		// ImageModel restore, restored png file to .jpg file ( due to $this)
		// File has just been restored, but it will be wrong extension in uploads

		$destination = $fs->getFile($this->getFileDir() . $this->getFileBase() . '.' . $ext);

		// If scaled in the name, revert to originalFile.
		/*if ($this->isScaled()) {
			$originalFile = $this->getOriginalFile();
			$destination = $fs->getFile($this->getFileDir() . $originalFile->getFileBase() . '.' . $ext);
		} */

		// We can't remove files until the end of process because some plugins will block it.
		$toRemove = array();
		$toRemove[] = $this;
		// Destination is image.png, the original.
		/*if (false === $destination->exists()) { */
			// This is a PNG content file, that has been restored as a .jpg file which is now main.
		/*	$copyok = $this->copy($destination);
			if (false === $copyok) {
				Log::addError('Copy to destination failed!');
				ResponseController::addData('message', __('Restore PNG2JPG : Copying PNG to destination failed', 'shortpixel-image-optimiser'));
				ResponseController::addData('is_error', true);
			} */

			
		/*} elseif (true === $destination->exists() && $destination->getExtension() == $ext) {
			Log::addInfo('Destination exists, but is of correct extension, so fine?'); */
		/* } else { 
			Log::addError('Restoring Converted image not possible, target already exists');
			ResponseController::addData('message', __('Restore PNG2JPG : Restoring to target that already exists', 'shortpixel-image-optimiser'));
			ResponseController::addData('is_error', true);
			return false;
		}  */

		$thumbObjs = $this->getThumbObjects();
		$backupModel = $this->getBackupModel();

		// @todo MOve this logic to BackuModel. Also remove the backup_files entry when deleting files keeping consistency
		foreach ($thumbObjs as $thumbObj) {
			/*if ($backupModel->hasBackup($thumbObj)) {
				$backupFile = $backupModel->getBackupFile($thumbObj);

				if (is_object($backupFile)) {
					// This should delete in restore function.
					$backupFile->delete();

					$backupFileJPG = $fs->getFile($backupFile->getFileDir() . $backupFile->getFileBase() . '.jpg');
					if (is_object($backupFileJPG) && $backupFileJPG->exists()) {
						$backupFileJPG->delete();
					}
				}
			} */

			$toRemove[] = $thumbObj;
		}

		$removed = []; 
		foreach ($toRemove as $fileObj) {
			if (false === $this->is_virtual()) {
				$fullpath = $fileObj->getFullPath();

				// If multiple thumbs pass to the same file, prevent trying to double-delete it.
				if (in_array($fullpath, $removed))
				{
					 continue; 
				}
				$bool = $fileObj->delete();

				if (true === $bool)
				{
					 $removed[] = $fullpath; 
				}

			}

			if ($fileObj->get('is_main_file') == false) {
				$fileObj->image_meta = new ImageThumbNailMeta();
			}
		}

		// Fullpath now will still be .jpg
		// PNGconvert is first, because some plugins check for _attached_file metadata and prevent deleting files if still connected to media library. Exmaple: polylang.
		$converter->restore();

		$this->wp_metadata = null;  // restore changes the metadata.
		$this->thumbnails = $this->loadThumbnailsFromWP();
		$this->retinas = null;

		return true;
	}

	/**
	 * Ask WordPress to re-run thumbnail subsize generation for this
	 * attachment.
	 *
	 * Wrapped in the `as3cf_wait_for_generate_attachment_metadata` filter
	 * (via `returnTrue`) so the WP Offload Media integration doesn't
	 * offload the freshly regenerated files before we're done.
	 *
	 * When the attachment is scaled, subsize generation runs against the
	 * unscaled original so the resulting thumbnails are cut from the full
	 * resolution, not the WP-scaled copy.
	 *
	 * Only used for special cases such as recovering from an offload
	 * mishap; the regular restore/convert flows use generateThumbnails().
	 *
	 * @return void
	 */
	public function wpCreateImageSizes()
	{
		add_filter('as3cf_wait_for_generate_attachment_metadata', array($this, 'returnTrue'));

		$fullpath = $this->getFullPath();
		if ($this->isScaled()) // if scaled, the original file is the main file for thumbnail base
		{
			$originalFile = $this->getOriginalFile();
			$fullpath = $originalFile->getFullPath();
		}
		$res = \wp_create_image_subsizes($fullpath, $this->id);

		remove_filter('as3cf_wait_for_generate_attachment_metadata', array($this, 'returnTrue'));
	}

	/**
	 * Trivial filter callback returning true. Used to force
	 * `as3cf_wait_for_generate_attachment_metadata` on for the duration of
	 * wpCreateImageSizes().
	 *
	 * @return true
	 */
	public function returnTrue()
	{
		return true;
	}

	/**
	 * Public entry point that removes every SPIO-related legacy trace from
	 * an attachment: WP-metadata legacy keys (via removeLegacy()) and the
	 * `_shortpixel_was_converted` / `_shortpixel_status` post-meta flags.
	 *
	 * Kept separate from the private removeLegacy() so callers that only
	 * want to clear WP-metadata leftovers don't have to touch post meta.
	 *
	 * @return void
	 */
	public function removeLegacyShortPixel()
	{
		$bool = $this->removeLegacy();
		if ($bool) {
			delete_post_meta($this->id, '_shortpixel_was_converted');
			delete_post_meta($this->id, '_shortpixel_status');
		}
	}

	/**
	 * Return one unified array of thumbnail-model objects across every
	 * companion — thumbnails, retinas (prefixed `retina_` so they can't
	 * shadow a same-named size), and the unscaled original when scaled.
	 *
	 * Used everywhere the family needs a single loop rather than three
	 * (isProcessable, saveDBMeta, isSomethingOptimized, etc.).
	 *
	 * @return MediaLibraryThumbnailModel[] Keyed by size name.
	 */
	private function getThumbObjects()
	{
		$objects = $this->thumbnails;
		$retinas = $this->getRetinas();

		if (! is_null($retinas) && is_array($retinas)) {
			foreach ($retinas as $retinaObj) {
				$objects['retina_' . $retinaObj->get('name')] = $retinaObj;
			}
		}
		if ($this->isScaled()) {
			$objects[$this->originalImageKey] = $this->getOriginalFile();
		}
		return $objects;
	}

	/**
	 * Populate the family with members that WP metadata and SPIO postmeta
	 * don't know about.
	 *
	 * Runs after loadMeta() so that unlisted thumbnails on disk (from
	 * SHORTPIXEL_CUSTOM_THUMB_SUFFIXES / _INFIXES or the "optimize unlisted"
	 * setting) and retina companions are available to isProcessable() and
	 * handleOptimized() without an extra full walk each time.
	 *
	 * @return void
	 */
	private function loadLooseItems()
	{
		// Load items that might be not recorded when loading.
		$this->addUnlisted();
		$this->getRetinas();
	}

	/**
	 * Regenerate the WordPress thumbnails for this attachment via
	 * wp_generate_attachment_metadata().
	 *
	 * When the attachment is scaled, the unscaled original is used as the
	 * source so the resulting thumbnails are cut from the full resolution
	 * rather than the WP-scaled copy.
	 *
	 * Wraps the generation in the
	 * `shortpixel/converter/prevent-offload` / `-off` action pair so the
	 * offload integration doesn't ship the freshly regenerated files off
	 * before the restore/convert flow is done with them.
	 *
	 * @return array<string, mixed>|\WP_Error Result of wp_generate_attachment_metadata().
	 */
	private function generateThumbnails()
	{
		// Generate not of the -scaled item, then it creates wrong thumbnails.
		if (true === $this->hasOriginal())
		{
			 $originalFile = $this->getOriginalFile();
			 $fullPath = $originalFile->getFullPath(); 
		}
		else 
		{
			 $fullPath = $this->getFullPath();
		}

		$item_id = $this->get('id');
		// Prevent Offload here, otherwise it won't offload anymore when all is done or restored.
		do_action('shortpixel/converter/prevent-offload', $item_id);

		Log::addTemp('Generate Thumbs -- ' . $item_id);
		do_action('shortpixel-thumbnails-before-regenerate', $item_id);
		$metadata = wp_generate_attachment_metadata($item_id, $fullPath);

		do_action('shortpixel/converter/prevent-offload-off', $item_id);

		return $metadata;

	}

	/**
	 * Strip the legacy SPIO keys (`ShortPixel`, `ShortPixelImprovement`,
	 * `ShortPixelPng2Jpg`) from `_wp_attachment_metadata`.
	 *
	 * Called during restore/onDelete so that after the SPIO postmeta rows
	 * are cleared the next loadMeta() call doesn't re-import the same
	 * legacy data via checkLegacy() and undo the operation.
	 *
	 * @return bool True when at least one legacy key was removed.
	 */
	private function removeLegacy()
	{
		$metadata = $this->getWPMetaData();
		$updated = false;


		$unset = array('ShortPixel', 'ShortPixelImprovement', 'ShortPixelPng2Jpg');

		foreach ($unset as $key) {
			if (isset($metadata[$key])) {
				unset($metadata[$key]);
				$updated = true;
			}
		}

		if ($updated === true) {
			wp_update_attachment_metadata($this->id, $metadata);
		}

		return $updated;
	}

	/**
	 * Force-migrate a single attachment from the legacy schema to the
	 * shortpixel_postmeta model.
	 *
	 * Runs checkLegacy() and additionally "self-heals" any family member
	 * that has a backup on disk but no FILE_STATUS_SUCCESS status — marking
	 * it optimized so restore / re-optimize actions work.
	 *
	 * Only intended for the "Migrate all" bulk action and a few debug
	 * paths; the regular loadMeta() already runs checkLegacy() when needed.
	 * The $justConverted guard prevents a double-migration in the same
	 * request.
	 *
	 * @return void
	 */
	public function migrate()
	{
		// Don't double.
		if ($this->justConverted === true)
			return;

		delete_post_meta($this->id, '_shortpixel_was_converted');
		$result = $this->checkLegacy();

		$backupModel = $this->getBackupModel();

		// Check the whole thing to find any images that have a backup, but are not marked as optimized, and just mark them.
		if (! $this->isOptimized() && $backupModel->hasBackup($this)) {
			$this->setMeta('status', self::FILE_STATUS_SUCCESS);
			$result = true;
		}
		if ($this->hasOriginal()) {
			$originalFile = $this->getOriginalFile();
			if (! $originalFile->isOptimized() && $originalFile->hasBackup()) {
				$originalFile->setMeta('status', self::FILE_STATUS_SUCCESS);
				$result = true;
			}
		}
		if (is_array($this->thumbnails) && count($this->thumbnails) > 0) {
			foreach ($this->thumbnails as $thumbObj) {
				if (! $thumbObj->isOptimized() && $backupModel->hasBackup($thumbObj)) {
					$thumbObj->setMeta('status', self::FILE_STATUS_SUCCESS);
					$result = true;
				}
			}
		}
		if (is_array($this->retinas) && count($this->retinas) > 0) {
			foreach ($this->retinas as $retinaObj) {
				if (! $retinaObj->isOptimized() && $backupModel->hasBackup($retinaObj)) {
					$retinaObj->setMeta('status', self::FILE_STATUS_SUCCESS);
					$result = true;
				}
			}
		}


		if ($result) {
			$this->saveMeta();
		}
	}

	/**
	 * Migrate a pre-shortpixel_postmeta attachment into the modern schema.
	 *
	 * Reads the legacy `ShortPixel` block from `_wp_attachment_metadata` and
	 * rehydrates image_meta + every family member. Highlights of the flow:
	 *
	 *   - No `ShortPixel` block, empty block, or "waiting-only" block →
	 *     nothing to migrate; return false.
	 *   - `_shortpixel_was_converted` before the July 2022 pre-bug cutoff +
	 *     an existing backup → treated as a corrupted state, wipes the
	 *     SPIO meta so migration can run cleanly again.
	 *   - Legacy status / compression type / improvement / error message
	 *     are mapped into the modern ImageMeta shape via legacyConvertType()
	 *     and legacyConvertStatus(). Missing dates fall back to the post's
	 *     own publish date.
	 *   - Original file size comes from the backup when available;
	 *     otherwise it's inferred from the improvement percentage.
	 *   - WebP / AVIF companions are located via checkLegacyFileTypeFileName()
	 *     (which can probe S3-offloaded virtual files).
	 *   - PNG→JPG conversion is reconstructed on convertMeta when
	 *     `ShortPixelPng2Jpg` is present.
	 *   - Thumbnails present in `thumbsOptList` (or with a backup on disk)
	 *     get their status / sizes / timestamps recreated. `sp-found-`
	 *     prefixed sizes are tagged as unlisted by setting `meta.file`.
	 *   - The unscaled original and every retina get the same treatment
	 *     when the row is not already present in the DB.
	 *   - Finally stamps `_shortpixel_was_converted = time()` (guard so
	 *     restore doesn't re-migrate), clears `_shortpixel_status`, and
	 *     sets `$justConverted` to block a second migration this request.
	 *
	 * @return bool True when legacy data was migrated, false when there was nothing to do.
	 */
	private function checkLegacy()
	{
		$metadata = $this->getWPMetaData();

		if (! isset($metadata['ShortPixel'])) {
			return false;
		}

		$data = $metadata['ShortPixel'];

		if (count($data) == 0)  // This can happen. Empty array is still nothing to convert.
		{
			return false;
		}

		// Waiting for processing is a state where it's not optimized, or should be.
		// The last check is because it seems that it can be both improved and waiting something ( sigh ) // 04/07/22
		if (count($data) == 1 && isset($data['WaitingProcessing']) && ! isset($data['ShortPixelImprovement'])) {
			return false;
		}

		$backupModel = $this->getBackupModel();

		// This is a switch to prevent converted items to reconvert when the new metadata is removed ( i.e. restore )
		$was_converted = get_post_meta($this->id, '_shortpixel_was_converted', true);
		if ($was_converted == true || is_numeric($was_converted)) {
			$updateTs = 1656892800; // July 4th 2022 - 00:00 GMT
			// Noconversioncheck was mentioned here in the past
			if ($was_converted < $updateTs && $backupModel->hasBackup($this) )  {
				$this->resetPrevent();  // reset any prevented optimized. This would have prob. thrown a backup issue.
				if ($this->isProcessable()) {
					$this->deleteMeta();
					Log::addDebug('Conversion pre-bug detected with backup and still processable. Trying to fix by redoing legacy.');
				}
			} else {
				Log::addDebug('No SPIO5 metadata, but this item was converted, not converting again');
				return false;
			}
		}

		$quotaController = QuotaController::getInstance();
		if ($quotaController->hasQuota() === true) {
			$adminNotices = AdminNoticesController::getInstance();
			$adminNotices->invokeLegacyNotice();
		}

		Log::addDebug("Conversion of legacy: " . $this->get('id'), array($metadata));

		$type = isset($data['type']) ? $this->legacyConvertType($data['type']) : '';

		$improvement = (isset($metadata['ShortPixelImprovement']) && is_numeric($metadata['ShortPixelImprovement']) && $metadata['ShortPixelImprovement'] > 0) ? $metadata['ShortPixelImprovement'] : 0;

		$status = $this->legacyConvertStatus($data, $metadata);

		$error_message = isset($metadata['ShortPixelImprovement']) && ! is_numeric($metadata['ShortPixelImprovement']) ? $metadata['ShortPixelImprovement'] : '';

		//   $retries = isset($data['Retries']) ? intval($data['Retries']) : 0;
		$optimized_thumbnails = (isset($data['thumbsOptList']) && is_array($data['thumbsOptList'])) ? $data['thumbsOptList'] : array();
		$exifkept = (isset($data['exifKept']) && $data['exifKept']  == 1) ? true : false;

		$tsAdded = time();

		if ($this->hasDBRecord() === false) {
			if ($status == self::FILE_STATUS_SUCCESS) {
				//strtotime($tsOptimized)
				$thedate = (isset($data['date'])) ? $data['date'] : false;
				$newdate = \DateTime::createFromFormat('Y-m-d H:i:s', $thedate);

				if ($newdate === false) {
					$newdate = \DateTime::createFromFormat('Y-m-d H:i:s', get_post_time('Y-m-d H:i:s', false, $this->id));
				}

				/// If not date could be established just omit.
				if ($newdate !== false) {
					$newdate = $newdate->getTimestamp();
					$tsOptimized = $newdate;
					$this->image_meta->tsOptimized = $tsOptimized;
				}
			}

			$this->image_meta->wasConverted = true;
			$this->image_meta->status = $status;
			$this->image_meta->improvement = $improvement;
			$this->image_meta->compressionType = $type;
			$this->image_meta->compressedSize = $this->getFileSize();
			$this->image_meta->tsAdded = $tsAdded;
			$this->image_meta->errorMessage = $error_message;
			$this->image_meta->did_keepExif = $exifkept;

			// @todo  This should be checked! 
			if ($backupModel->hasBackup($this)) {
				$backup = $backupModel->getBackupFile($this);
				if (is_object($backup))
					$this->image_meta->originalSize = $backup->getFileSize();
			} elseif (isset($metadata['ShortPixelImprovement'])) {
				// If the improvement is set, calculate back originalsize.
				$imp = intval($metadata['ShortPixelImprovement']); // try to make int. Legacy can contain errors / message / crap here.
				if ($imp > 0)
					$this->image_meta->originalSize = ($this->getFileSize() / (100 - $imp)) * 100;
			}

			$this->image_meta->webp = $this->checkLegacyFileTypeFileName($this, 'webp');
			$this->image_meta->avif = $this->checkLegacyFileTypeFileName($this, 'avif');


			$this->width = isset($metadata['width']) ? $metadata['width'] : false;
			$this->height = isset($metadata['height']) ? $metadata['height'] : false;

			$this->recordChanged(true);
		}

		if (isset($metadata['ShortPixelPng2Jpg'])) {

			$this->image_meta->did_png2jpg = true; //setMeta('did_png2jpg', true);
			$this->getMeta()->convertMeta()->setFileFormat('png');
			$this->getMeta()->convertMeta()->setConversionDone();
		}

		foreach ($this->thumbnails as $thumbname => $thumbnailObj) // ThumbnailModel
		{
			if ($thumbnailObj->hasDBRecord() === true) {
				continue;
			}

			if (in_array($thumbnailObj->getFileName(), $optimized_thumbnails) || $backupModel->hasBackup($thumbnailObj)) {
				$thumbnailObj->image_meta->status = $status;
				$thumbnailObj->image_meta->compressionType = $type;
				$thumbnailObj->image_meta->compressedSize = $thumbnailObj->getFileSize();

				$thumbnailObj->has_backup = false;
				if ($backupModel->hasBackup($thumbnailObj)) {
					$backup = $backupModel->getBackupFile($thumbnailObj);
					if (is_object($backup)) {
						$thumbnailObj->image_meta->originalSize = $backup->getFileSize();
						$thumbnailObj->has_backup = true;
					}
				}

				$thumbnailObj->image_meta->tsAdded = $tsAdded;
				if (isset($tsOptimized))
					$thumbnailObj->image_meta->tsOptimized = $tsOptimized;

				$thumbnailObj->image_meta->webp = $this->checkLegacyFileTypeFileName($thumbnailObj, 'webp');
				$thumbnailObj->image_meta->avif = $this->checkLegacyFileTypeFileName($thumbnailObj, 'avif');

				if (strpos($thumbname, 'sp-found') !== false) // File is 'unlisted', also save file information.
				{
					$thumbnailObj->image_meta->file = $thumbnailObj->getFileName();
				}

				$thumbnailObj->recordChanged(true);
				$this->thumbnails[$thumbname] = $thumbnailObj;
			}
		}

		if ($this->isScaled() && $this->original_file->hasDBRecord() === false) {
			$originalFile = $this->original_file;

			if (isset($metadata['original_image']) || $backupModel->hasBackup($originalFile)) {

				$originalFile->image_meta->status = $status;
				$originalFile->image_meta->compressionType = $type;
				$originalFile->image_meta->compressedSize = $originalFile->getFileSize();

	 			 $originalFile->has_backup = false;

				if ($backupModel->hasBackup($originalFile)) {
					$backup = $backupModel->getBackupFile($originalFile);
					if (is_object($backup)) {
						$originalFile->image_meta->originalSize = $backup->getFileSize();
						$originalFile->has_backup = true;
					}
				}

				$originalFile->image_meta->tsAdded = $tsAdded;
				if (isset($tsOptimized)) {
					$originalFile->image_meta->tsOptimized = $tsOptimized;
				}

				$originalFile->image_meta->webp = $this->checkLegacyFileTypeFileName($originalFile, 'webp');
				$originalFile->image_meta->avif = $this->checkLegacyFileTypeFileName($originalFile, 'avif');


				if (strpos($thumbname, 'sp-found') !== false) // File is 'unlisted', also save file information.
				{
					$originalFile->image_meta->file = $originalFile->getFileName();
				}

				$originalFile->recordChanged(true);
				$this->original_file = $originalFile;
			}
		}

		if (isset($data['retinasOpt'])) {
			$count = $data['retinasOpt']; // a number.
			$addedCounter = 0;

			$retinasOpt = $data['retinasOpt'];
			$retinas = $this->getRetinas();

			if (intval($retinasOpt) > 0 && is_array($retinas)) {
				foreach ($retinas as $index => $retinaObj) // Thumbnail Model
				{
					if ($retinaObj->hasDBRecord() === true) {
						continue;
					}

					// Check if thumbnail ('parent') is Optimized, if so, then retina probably should be optimized as well.
					if ((isset($this->thumbnails[$index]) &&
						is_object($this->thumbnails[$index]) &&
						$this->thumbnails[$index]->isOptimized() ) || $backupModel->hasBackup($retinaObj)) {
						$retinaObj->image_meta->status = $status;
						$retinaObj->image_meta->compressionType = $type;
						if ($status == self::FILE_STATUS_SUCCESS)
							$retinaObj->image_meta->compressedSize = $retinaObj->getFileSize();
						else
							$retinaObj->image_meta->originalSize = $retinaObj->getFileSize();
						//  $retinaObj->image_meta->improvement = -1; // n/a
						$retinaObj->image_meta->tsAdded = $tsAdded;
						if (isset($tsOptimized)) {
							$retinaObj->image_meta->tsOptimized = $tsOptimized;
						}

						if ($backupModel->hasBackup($retinaObj)) {
							$retinaObj->has_backup = true;
							if ($status == self::FILE_STATUS_SUCCESS)
							{	
								$backupFile = $backupModel->getBackupFile($retinaObj);
								if (is_object($backupFile))
								{
									$retinaObj->image_meta->originalSize = $backupFile->getFileSize();
								}
							}
						}

						$retinaObj->recordChanged(true);
						$retinas[$index] = $retinaObj;
						$addedCounter++;
					}
				} // foreach
				$this->retinas = $retinas;
			} // is array.
			if ($count !== $addedCounter) {
				Log::addWarning("Conversion: $count retinas expected in legacy, " . $addedCounter . 'found. This can be due to overlapping image sizes.');
			}
		}

		update_post_meta($this->id, '_shortpixel_was_converted', time());
		delete_post_meta($this->id, '_shortpixel_status');

		$this->justConverted = true;
		return true;
	}

	/**
	 * Resolve the filename of a WebP or AVIF companion for a family member
	 * during legacy migration, including for S3-offloaded files.
	 *
	 * Local files are handled by getImageType() as normal. For virtual files
	 * on the S3 Offload integration, the method probes both the
	 * single-extension (`foo.webp`) and double-extension (`foo.jpg.webp`)
	 * conventions via HTTP `url_exists` checks and returns whichever
	 * actually resolves — falling back to the alternative convention if
	 * the environment-preferred one is missing.
	 *
	 * @param MediaLibraryThumbnailModel|self $fileObj Family member to check.
	 * @param string                          $type    'webp' or 'avif'.
	 * @return string|null Filename of the companion, or null when none is found.
	 */
	private function checkLegacyFileTypeFileName($fileObj, $type)
	{
		$fileType = $fileObj->getImageType($type);
		if ($fileType !== false) {
			return $fileType->getFileName();
		}

		$env = \wpSPIO()->env();
		$fs = \wpSPIO()->filesystem();

		// try the whole thing, but fetching remote URLS, test if really S3 not in case something went wrong with is_virtual, or it's just something messed up.
		if ($fileObj->is_virtual() && $env->plugin_active('s3-offload')) {


			if ($type == 'webp') {
				$is_double = \wpSPIO()->env()->useDoubleWebpExtension();
			}
			if ($type == 'avif') {
				$is_double = \wpSPIO()->env()->useDoubleAvifExtension();
			}

			$url = str_replace('.' . $fileObj->getExtension(), '.' . $type, $fileObj->getURL());
			$double_url = $fileObj->getURL() . '.' . $type;

			$double_filename = $fileObj->getFileName() . '.' . $type;
			$filename =  $fileObj->getFileBase() . '.' . $type;

			if ($is_double) {
				$url_exists = $fs->url_exists($double_url);
				if ($url_exists === true)
					return $double_filename;
			} else {
				$url_exists = $fs->url_exists($url);
				if ($url_exists === true)
					return $filename;
			}

			// If double extension is enabled, but no file, check the alternative.
			if ($is_double) {
				$url_exists = $fs->url_exists($url);
				if ($url_exists === true)
					return $filename;
			} else {
				$url_exists = $fs->url_exists($double_url);
				if ($url_exists === true)
					return $double_filename;
			}
		} // is_virtual

		return null;
	}

	/**
	 * Map the legacy string compression type onto the modern integer constant.
	 *
	 * @param string $string_type Legacy label ('lossy' / 'lossless' / 'glossy').
	 * @return int One of the COMPRESSION_* constants, or -1 for unknown.
	 */
	private function legacyConvertType($string_type)
	{
		switch ($string_type) {
			case 'lossy':
				$type = self::COMPRESSION_LOSSY;
				break;
			case 'lossless':
				$type = self::COMPRESSION_LOSSLESS;
				break;
			case 'glossy':
				$type = self::COMPRESSION_GLOSSY;
				break;
			default:
				$type = -1; //unknown state.
				break;
		}
		return $type;
	}

	/**
	 * Map a legacy ShortPixel data + metadata pair into a modern
	 * FILE_STATUS_* code.
	 *
	 * Priority order:
	 *   - `ShortPixelImprovement` numeric > 0 → FILE_STATUS_SUCCESS.
	 *   - `WaitingProcessing` set → FILE_STATUS_PENDING.
	 *   - Known error codes (`backup-fail`, `write-fail`) → FILE_STATUS_ERROR.
	 *   - Any other negative `ErrCode` → passed through as the status.
	 *
	 * @param array $data     Legacy `ShortPixel` block from wp attachment metadata.
	 * @param array $metadata Full wp attachment metadata (for the sibling `ShortPixelImprovement` key).
	 * @return int FILE_STATUS_* code, or an ErrCode value when the legacy record was in an error state.
	 */
	private function legacyConvertStatus($data, $metadata)
	{

		$waiting = isset($data['WaitingProcessing']) ? true : false;
		$error = isset($data['ErrCode']) ? $data['ErrCode'] : -500;

		if (
			isset($metadata['ShortPixelImprovement']) &&
			is_numeric($metadata["ShortPixelImprovement"]) &&
			is_numeric($metadata["ShortPixelImprovement"]) > 0
		) {
			$status = self::FILE_STATUS_SUCCESS;
		} elseif ($waiting) {
			$status = self::FILE_STATUS_PENDING;
		} elseif ($error == 'backup-fail' || $error == 'write-fail') {
			$status = self::FILE_STATUS_ERROR;
		} elseif ($error < 0) {
			$status = $error;
		}


		return $status;
	}

	/**
	 * Provide a compact representation for var_dump()/debug output that
	 * exposes the family structure without dumping the entire FileModel
	 * plumbing.
	 *
	 * @return array<string, mixed>
	 */
	public function __debugInfo()
	{

		return array(
			'id' => $this->id,
			'exists' => ($this->exists()) ? 'yes' : 'no',
			'is_virtual' => ($this->is_virtual()) ? 'yes' : 'no',
			'fullpath' => $this->getFullPath(), 
			'width' => $this->get('width'),
			'height' => $this->get('height'),
			'image_meta' => $this->image_meta,
			'thumbnails' => $this->thumbnails,
			'retinas' => $this->retinas,
			'original_file' => $this->original_file,
			'is_scaled' => $this->is_scaled,
			'imageType' => $this->imageType,
		);
	}

	/**
	 * Check whether unlisted files exist for this attachment and, if so,
	 * raise the "MSG_UNLISTED_FOUND" admin notice — without actually
	 * registering the unlisted files.
	 *
	 * Cheap paths short-circuit early: only runs once per request (via the
	 * $unlistedNoticeChecked static), skips silent mode, skips when the
	 * "optimize unlisted" setting is already on, and skips when the notice
	 * is already active. Even the first attachments only bump a counter
	 * on the settings model; the actual disk scan runs from attachment 100
	 * onward, so early attachments in a request don't pay for the scan.
	 *
	 * @return void
	 */
	private function checkUnlistedForNotice()
	{
		// Prevent running this more than once per run.
		if (true === self::$unlistedNoticeChecked) {
			return;
		}


		self::$unlistedNoticeChecked = true;

		$settings = \wpSPIO()->settings();
		$control = AdminNoticesController::getInstance();

		// Silent mode has no notices.
		if ($control->isSilentMode()) {
			return;
		}

		$notice =  $control->getNoticeByKey('MSG_UNLISTED_FOUND');

		// already active
		if ($settings->optimizeUnlisted === true)
			return;

		// already notice.
		if (is_object($notice) && is_object($notice->getNoticeObj())) {
			return;
		}

		// todo get counter to indicate
		$counter = $settings->unlistedCounter;

		if ($counter < 100) {
			$settings->unlistedCounter++;
			return;
		}

		// check unlisted.
		$unlisted = $this->addUnlisted(true);


		if (is_array($unlisted) && count($unlisted) > 0) {
			// trigger notice.
			$args = array(
				'count' => count($unlisted),
				'filelist' => $unlisted,
				'name' => $this->getFileName(),
				'id' => $this->get('id'),
			);
			$notice->addManual($args);
		}
		$settings->unlistedCounter = 0;
	}

	/**
	 * Discover unlisted thumbnail files on disk and either register them
	 * on the attachment or just return them for the notice flow.
	 *
	 * Scans the attachment's directory for files matching:
	 *   - `<basename>-<W>x<H>.<ext>` (standard WP-style names) — only when
	 *     the `optimizeUnlisted` setting is on or `$check_only=true`;
	 *   - `<basename>-<W>x<H><suffix>.<ext>` for every SHORTPIXEL_CUSTOM_THUMB_SUFFIXES entry;
	 *   - `<basename><infix>-<W>x<H>.<ext>` for every SHORTPIXEL_CUSTOM_THUMB_INFIXES entry.
	 * The suffix/infix lists are also filterable via
	 * `shortpixel/image/unlisted_suffixes` and `_infixes`.
	 *
	 * Per-request state:
	 *   - $unlistedChecked memoises attachment IDs so a full request only
	 *     scans each attachment once.
	 *   - Virtual attachments skip the scan unless heavy virtual functions
	 *     are enabled — scandir() on an offloaded directory is expensive.
	 *
	 * The matched files are filtered against $currentFiles (main + all
	 * thumbnails + retinas + original) so nothing already tracked is
	 * added, and against WebP/AVIF extensions so companions aren't
	 * accidentally treated as thumbnails.
	 *
	 * @param bool $check_only When true, don't mutate $thumbnails; return the discovered filenames instead. Used by checkUnlistedForNotice().
	 * @return string[]|void List of found filenames when $check_only=true; otherwise void with side-effects on $thumbnails.
	 */
	protected function addUnlisted($check_only = false)
	{
		// Setting must be active.
		/*if (! \wpSPIO()->settings()->optimizeUnlisted )
         return; */
		$searchUnlisted = \wpSPIO()->settings()->optimizeUnlisted;

		// Don't check this more than once per run-time.
		if (in_array($this->get('id'), self::$unlistedChecked) && $check_only === false) {
			return;
		}

		if ($this->is_virtual() && false === \wpSPIO()->env()->useVirtualHeavyFunctions()) {
			return;
		}

		if (defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES')) {
			$suffixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_SUFFIXES);
		} else
			$suffixes = array();

		if (defined('SHORTPIXEL_CUSTOM_THUMB_INFIXES')) {
			$infixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_INFIXES);
		} else {
			$infixes = array();
		}

		$searchSuffixes = array_unique(apply_filters('shortpixel/image/unlisted_suffixes', $suffixes));
		$searchInfixes =  array_unique(apply_filters('shortpixel/image/unlisted_infixes', $infixes));

		// addUnlisted is called by IsProcessable, file might not exist.
		// If virtual, we can't read dir, don't do it.
		if (! $this->exists() || $this->is_virtual()) {
			self::$unlistedChecked[] = $this->get('id');
			return;
		}

		// if all have nothing to do, do nothing.
		if ($searchUnlisted == false && count($searchSuffixes) == 0 && count($searchInfixes) == 0 && $check_only === false) {
			self::$unlistedChecked[] = $this->get('id');
			return;
		}

		$currentFiles = array($this->getFileName());
		foreach ($this->thumbnails as $thumbObj) {
			$currentFiles[] = $thumbObj->getFileName();
		}

		if ($this->isScaled())
			$currentFiles[] = $this->getOriginalFile()->getFileName();

		if (is_array($this->retinas)) {
			foreach ($this->retinas as $retinaObj) {
				$currentFiles[] = $retinaObj->getFileName();
			}
		}

		$processFiles = array();
		$unlisted = array();

		$processFiles[] = $this;
		if ($this->isScaled())
			$processFiles[] = $this->getOriginalFile();

		$all_files = scandir($this->getFileDir(),  SCANDIR_SORT_NONE);
		$all_files = array_diff($all_files, $currentFiles);


		foreach ($processFiles as $mediaItem) {

			$base = $mediaItem->getFileBase();
			$ext = $mediaItem->getExtension();
			$path = (string) $mediaItem->getFileDir();

			if ($searchUnlisted || $check_only === true) {
				$pattern = '/^' . preg_quote($base, '/') . '-\d+x\d+\.' . $ext . '/';
				$thumbs = array();
				$result_files = array_values(preg_grep($pattern, $all_files));
			} else {
				$result_files = array();
			}

			$unlisted = array_merge($unlisted, $result_files);

			if (count($searchSuffixes) > 0) {
				// $suffixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_SUFFIXES);
				if (is_array($searchSuffixes)) {
					foreach ($searchSuffixes as $suffix) {

						$pattern = '/^' . preg_quote($base, '/') . '-\d+x\d+' . $suffix . '\.' . $ext . '/';
						$thumbs = array_values(preg_grep($pattern, $all_files));

						if (count($thumbs) > 0)
							$unlisted = array_merge($unlisted, $thumbs);
					}
				}
			}
			if (count($searchInfixes) > 0) {
				// $infixes = explode(',', SHORTPIXEL_CUSTOM_THUMB_INFIXES);
				if (is_array($searchInfixes)) {
					foreach ($searchInfixes as $infix) {
						//$thumbsCandidates = @glob($base . $infix  . "-*." . $ext);
						$pattern = '/^' . preg_quote($base, '/') . $infix . '-\d+x\d+' . '\.' . $ext . '/';
						$thumbs = array_values(preg_grep($pattern, $all_files));
						if (count($thumbs) > 0)
							$unlisted = array_merge($unlisted, $thumbs);
					}
				}
			}
		}  // processFiles loop

		// Quality check on the thumbs. Must exist,  must be same extension.
		$added = false;

		$foundUnlisted = array(); // found and ready. Used for notice / check only

		foreach ($unlisted as $unName) {
			if (isset($this->thumbnails[$unName])) {
				continue; // don't re-add if not needed.
			}
			$thumbObj = $this->getThumbnailModel($path . $unName, $unName);
			if ($thumbObj->getExtension() == 'webp' || $thumbObj->getExtension() == 'avif') // ignore webp/avif files.
			{
				continue;
			} elseif ($thumbObj->is_readable()) // exclude webps
			{
				if (true === $check_only) {
					$foundUnlisted[] = $unName;
				} else {
					$thumbObj->setName($unName);
					$thumbObj->setMeta('originalWidth', $thumbObj->get('width'));
					$thumbObj->setMeta('originalHeight', $thumbObj->get('height'));
					$thumbObj->setMeta('file', $thumbObj->getFileName());
					$this->thumbnails[$unName] = $thumbObj;
					$added = true;
				}
			} else {
				Log::addWarn("Unlisted Image $unName is not readable (permission error?)");
			}
		}

		if (true === $check_only) {
			return $foundUnlisted;
		}
		self::$unlistedChecked[] = $this->get('id');
	}

	/**
	 * Cache-flush hook: reset the "already checked for unlisted" list so
	 * that when the filesystem controller flushes and images are reloaded,
	 * unlisted files get rediscovered instead of silently disappearing.
	 *
	 * Wired via the filesystem cache-flush action.
	 *
	 * @return void
	 */
	public static function onFlushImageCache()
	{
		self::$unlistedChecked = array();
	}
} // class
