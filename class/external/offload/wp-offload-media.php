<?php

namespace ShortPixel\External\Offload;

use ShortPixel\Model\File\FileModel as FileModel;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\ResponseController as ResponseController;

/**
 * Integration with **WP Offload Media Lite** (as3cf) — moves optimized
 * files onto S3-compatible storage and rewrites URLs to point at the
 * remote copy.
 *
 * Two-way responsibilities:
 *
 *   1. **Outbound to S3** — after SPIO optimizes an image, re-fire
 *      `wp_update_attachment_metadata` so as3cf notices the changed
 *      file and uploads it. WebP / AVIF companions are attached to the
 *      upload payload via the `as3cf_attachment_file_paths` filter so
 *      they land on the bucket alongside the main file.
 *   2. **Inbound from S3** — when SPIO needs a local path for a file
 *      that's been offloaded (e.g. to run the optimizer on it), resolve
 *      the remote URL back to a source id via as3cf's item lookup, then
 *      derive the intended local path. See `checkIfOffloaded()`,
 *      `getSourceIDByURL()`, and `getLocalPathByURL()`.
 *
 * Guardrails:
 *
 *   - Initial-upload prevention (`preventInitialUploadHandler`) blocks
 *     as3cf from uploading the raw file before SPIO has optimized it —
 *     otherwise the bucket would end up with the un-optimized version
 *     and the optimized copy would never overwrite it cleanly.
 *   - Update-metadata prevention (`preventUpdateMetaData`) suppresses
 *     the intermediate `wp_update_attachment_metadata` call that fires
 *     mid-scaling on `-scaled` uploads, so the `original_source_path`
 *     doesn't get overwritten with the scaled path.
 *   - `preventOffload($id)` / `preventOffloadOff($id)` provide a
 *     stack-like guard the converter uses when it's temporarily
 *     rewriting files and doesn't want as3cf to react to intermediate
 *     states.
 *
 * The class is a singleton bound to the as3cf instance; only
 * `Offloader::initS3Offload()` is expected to call `getInstance()`.
 *
 * Compat mode: this class only supports the modern as3cf "handlers"
 * API (`get_item_handler`). Older versions get a `Notice::addWarning`
 * during `init()` and the integration self-disables.
 *
 * @package ShortPixel\External\Offload
 */
// @integration WP Offload Media Lite
class wpOffload
{
	/** @var object The WP Offload Media plugin instance (as3cf global). */
	protected $as3cf;

	/** @var bool True when as3cf is present and its version is compatible. Set in init(). */
	protected $active = false;

	/** @var bool True when the `copy-to-s3` setting is on and SPIO should keep offloading. Cleared in init() when the setting is off. */
	protected $offloading = true;

	/** @var string Fully-qualified class name for the media-library item class (`\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item`). */
	private $itemClassName;

	/** @var bool True when the modern as3cf handlers API is available. Set in init() and gates several codepaths. */
	private $useHandlers =  false; // Check for newer ItemHandlers or Compat mode.

	/** @var bool When true, `preventInitialUploadHandler` blocks as3cf from uploading; cleared during SPIO's own upload flow. */
	protected $shouldPrevent = true; // if offload should be prevented. This is turned off when SPIO want to tell S3 to offload. Better than removing filter.

	/** @var mixed Reserved / unused — declared but never assigned; flagged in the deferred-root-bugs memo. */
	protected $settings;

	/** @var bool Reserved / unused — declared but never assigned; flagged in the deferred-root-bugs memo. */
	protected $is_cname = false;

	/** @var mixed Reserved / unused — declared but never assigned; flagged in the deferred-root-bugs memo. */
	protected $cname;

	/** @var array<string, int|false> Cache for URL → source_id lookup (`sourceCache`); avoids duplicate DB queries during a request. */
	private static $sources = []; // cache for url > source_id lookup, to prevent duplicate queries.

	/** @var array<int, array<string, string>> Per-source_id cache of size-slug → path mappings (`add_webp_paths` / `getLocalPathByURL`). */
	private static $paths = [];

	/** @var array Reserved / unused — declared but never assigned; flagged in the deferred-root-bugs memo. */
	private static $itemCache = [];

	/** @var array<int, true> Set of attachment ids for which offload is currently prevented (via `preventOffload`). */
	private static $offloadPrevented = array();

	/** @var wpOffload|null Singleton instance held by getInstance(). */
	private static $instance;

	// if might have to do these checks many times for each thumbnails, keep it fastish.
	//protected $retrievedCache = array();

	/**
	 * Constructor — delegates immediately to `init($as3cf)`.
	 *
	 * Only `Offloader::initS3Offload()` is expected to instantiate this
	 * class (via `getInstance($as3cf)`); everyone else should use the
	 * singleton getter.
	 *
	 * @param object $as3cf The WP Offload Media plugin instance passed by the `as3cf_init` hook.
	 */
	public function __construct($as3cf)
	{
		// This must be called before WordPress' init.
		$this->init($as3cf);
	}

	/**
	 * Return the singleton `wpOffload` instance, constructing it on first
	 * call with the supplied as3cf instance.
	 *
	 * The `$as3cf` argument is consumed on the first call only; later
	 * calls ignore it and return the existing instance. Callers other
	 * than `Offloader::initS3Offload()` should be prepared for the
	 * argument to be ignored.
	 *
	 * @param object $as3cf The WP Offload Media plugin instance.
	 * @return wpOffload
	 */
	public static function getInstance($as3cf)
	{
		if (is_null(self::$instance))
		{
		 	self::$instance = new wpOffload($as3cf);
		}

		return self::$instance;
	}

	/**
	 * Boot the integration: verify as3cf compatibility, capture references,
	 * detect the `copy-to-s3` setting, then register the full set of
	 * `add_action` / `add_filter` hooks SPIO needs to interoperate with
	 * as3cf.
	 *
	 * Bails early with a `Notice::addWarning` (and does NOT register hooks)
	 * when:
	 *
	 *   - The as3cf `Media_Library_Item` class is missing entirely (plugin
	 *     not installed / too old to have namespaced classes).
	 *   - as3cf's modern handlers API (`get_item_handler`) is missing —
	 *     this class doesn't support the legacy pre-handlers codepath.
	 *
	 * When the `copy-to-s3` setting is off, hooks are still registered
	 * but `$offloading` is set to false so the outbound-side handlers
	 * short-circuit.
	 *
	 * Hooks registered (grouped by concern):
	 *
	 *   **Outbound / lifecycle**
	 *   - `shortpixel/image/optimised` → `image_upload`
	 *   - `shortpixel/image/after_restore` → `image_restore`
	 *   - `shortpixel-thumbnails-before-regenerate` → `remove_remote`
	 *   - `shortpixel/converter/prevent-offload` → `preventOffload`
	 *   - `shortpixel/converter/prevent-offload-off` → `preventOffloadOff`
	 *   - `shortpixel/image/convertpng2jpg_success` → `updateOriginalPath`
	 *
	 *   **as3cf-side interception**
	 *   - `as3cf_attachment_file_paths` → `add_webp_paths`
	 *   - `as3cf_pre_update_attachment_metadata` → `preventUpdateMetaData`
	 *   - `as3cf_pre_handle_item_upload` → `preventInitialUploadHandler`
	 *
	 *   **URL / path resolution**
	 *   - `shortpixel_get_original_image_path` → `checkScaledUrl`
	 *   - `shortpixel/image/urltopath` → `checkIfOffloaded`
	 *   - `shortpixel/file/virtual/translate` → `getLocalPathByURL`
	 *   - `shortpixel/front/webp_notfound` → `fixWebpRemotePath`
	 *
	 * @param object $as3cf The WP Offload Media plugin instance.
	 * @return false|void False when the compatibility check fails; otherwise no return value.
	 */
	public function init($as3cf)
	{

		if (! class_exists('\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item')) {
			Notice::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'shortpixel-image-optimiser'), true);
			return false;
		}

		$this->itemClassName = '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item';

		if (method_exists($as3cf, 'get_item_handler')) {
			$this->useHandlers = true; // we have a new version
		} else {
			Notice::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'shortpixel-image-optimiser'), true);
			return false;
		}

		$this->as3cf = $as3cf;
		$this->active = true;

		// if setting to upload to bucket is off, don't hook or do anything really.
		if (! $this->as3cf->get_setting('copy-to-s3')) {
			$this->offloading = false;
		}

		add_action('shortpixel/image/optimised', array($this, 'image_upload'), 10);
		add_action('shortpixel/image/after_restore', array($this, 'image_restore'), 10, 3); // hit this when restoring.
		add_action('shortpixel-thumbnails-before-regenerate', array($this, 'remove_remote'), 10);
		add_action('shortpixel/converter/prevent-offload', array($this, 'preventOffload'), 10);
		add_action('shortpixel/converter/prevent-offload-off', array($this, 'preventOffloadOff'), 10);

		add_filter('as3cf_attachment_file_paths', array($this, 'add_webp_paths'), 10, 3);

	//	add_filter('as3cf_remove_source_files_from_provider', array($this, 'remove_webp_paths'));

		add_filter('as3cf_pre_update_attachment_metadata', array($this, 'preventUpdateMetaData'), 10, 4);
		add_filter('as3cf_pre_handle_item_upload', array($this, 'preventInitialUploadHandler'), 10, 3);

		add_filter('shortpixel_get_original_image_path', array($this, 'checkScaledUrl'), 10, 2);

		add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10, 3);
		add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'), 10, 2);

		// for webp picture paths rendered via output
		add_filter('shortpixel/front/webp_notfound', array($this, 'fixWebpRemotePath'), 10, 4);

		// Fix for updating source paths when converting
		add_action('shortpixel/image/convertpng2jpg_success', array($this, 'updateOriginalPath'));
	}

	/**
	 * Filter callback: force `get_attached_file` to return the unfiltered
	 * value (second argument `true`) rather than any offloader-modified
	 * variant.
	 *
	 * Currently not hooked from this file — kept for external callers /
	 * historical wiring. The `$file` argument is discarded; the return
	 * value is always the raw attached-file path.
	 *
	 * @param string $file      Existing filter value (ignored).
	 * @param int    $attach_id WordPress attachment id.
	 * @return string Unfiltered attached-file path.
	 */
	public function returnOriginalFile($file, $attach_id)
	{
		$file = get_attached_file($attach_id, true);
		return $file;
	}

	/**
	 * Resolve the appropriate as3cf media-item class for the current
	 * plugin version.
	 *
	 * The modern handlers API (`get_source_type_class('media-library')`)
	 * returns the class dynamically. The `useHandlers` gate is set to
	 * true in `init()` — if it's false we fall back to the cached
	 * `$itemClassName` (`\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item`),
	 * kept for backward compatibility with pre-handlers as3cf builds.
	 *
	 * @return string Fully-qualified class name that responds to `get_by_source_id`, `create_from_source_id`, `get_item_source_by_remote_url`.
	 */
	private function getMediaClass()
	{
		if ($this->useHandlers) {
			$class = $this->as3cf->get_source_type_class('media-library');
		} else {
			$class = $this->itemClassName; //backward compat.
		}

		return $class;
	}

	/**
	 * Whether this offloader is considered active — i.e. both **available**
	 * (`$active`, set in init() after the compat check) and **actively
	 * offloading** (`$offloading`, cleared when as3cf's `copy-to-s3`
	 * setting is off).
	 *
	 * Callers use this to short-circuit outbound work: if the plugin is
	 * present but offloading is off, we still register the URL-resolution
	 * hooks (so previously-offloaded files can be found) but skip the
	 * `image_upload` / `image_restore` re-upload steps.
	 *
	 * @return bool
	 */
	public function isActive()
	{
		 return $this->active && $this->offloading;
	}

	/**
	 * `shortpixel/converter/prevent-offload` action — mark an attachment
	 * as "do not offload right now".
	 *
	 * Used by the converter (PNG→JPG, HEIC→JPG, etc.) around the window
	 * where the file is being rewritten. `preventOffloadOff` clears the
	 * flag when the converter is done. Every offload-related handler in
	 * this class consults `self::$offloadPrevented[$attach_id]` before
	 * doing work.
	 *
	 * @param int $attach_id WordPress attachment id to block.
	 * @return void
	 */
	// This is used in the converted. Might be deployed elsewhere for better control.
	public function preventOffload($attach_id)
	{
		self::$offloadPrevented[$attach_id] = true;
	}

	/**
	 * `shortpixel/converter/prevent-offload-off` action — clear the
	 * "do not offload" flag on an attachment.
	 *
	 * Companion to `preventOffload`. Called by the converter after it
	 * finishes rewriting a file.
	 *
	 * @param int $attach_id WordPress attachment id to unblock.
	 * @return void
	 */
	public function preventOffloadOff($attach_id)
	{
		unset(self::$offloadPrevented[$attach_id]);
	}

	/**
	 * `as3cf_pre_update_attachment_metadata` filter — block as3cf's
	 * mid-upload metadata refresh when an attachment is on the
	 * "prevent offload" list.
	 *
	 * The bug this guards against: when WordPress uploads a large image
	 * that will be `-scaled`, `wp_create_image_subsizes()` fires
	 * `wp_update_attachment_metadata` **after** the file has been moved
	 * but **before** any thumbnails are generated. as3cf sees this and
	 * uploads the freshly-moved main file, then later the scaled
	 * thumbnails, and its internal `original_source_path` ends up
	 * equal to `source_path`. That breaks SPIO's optimizer when the
	 * local copy is subsequently deleted — it can no longer figure out
	 * where to restore the original from.
	 *
	 * Returning `true` here tells as3cf to cancel the metadata update
	 * and wait for the later, complete one.
	 *
	 * @param mixed  $bool                Existing filter value (passed through when not blocked).
	 * @param array  $data                Attachment metadata array (unused).
	 * @param int    $post_id             WordPress attachment id.
	 * @param object $old_provider_object Previous as3cf provider snapshot (unused).
	 * @return bool True to cancel the update, otherwise the passed-through `$bool`.
	 */
	// When Offload is not offloaded but is created during the process of generate metadata in WP, wp_create_image_subsizes fires an update metadata after just moving the upload, before making any thumbnails.  If this is the case and the file has an -scaled / original image setup, the original_source_path becomes the same as the source_path which creates issue later on when dealing with optimizing it, if the file is deleted on local server.  Prevent this, and lean on later update metadata.
	public function preventUpdateMetaData($bool, $data, $post_id, $old_provider_object)
	{
		if (isset(self::$offloadPrevented[$post_id])) {
			Log::addDebug('Offloading of updated metadata prevented for ' . $post_id);
			return true; // return true to cancel.
		}

		return $bool;
	}

	/**
	 * `shortpixel/image/after_restore` action — re-upload a restored
	 * attachment so the bucket holds the freshly-restored originals.
	 *
	 * Sequence:
	 *
	 *   1. Bail on non-media-library items (custom folders aren't
	 *      supported by this integration).
	 *   2. `remove_remote()` — delete the previously-offloaded copies
	 *      so we can upload cleanly.
	 *   3. If offloading is disabled at the plugin level (`isActive()`
	 *      false), bail — we've done our cleanup and there's nothing
	 *      to re-upload.
	 *   4. `wpCreateImageSizes()` — regenerate any thumbnails that
	 *      were excluded from backups (SPIO's excluded-size rules mean
	 *      those don't come back via `restore()`).
	 *   5. `image_upload()` — re-upload the full family via the normal
	 *      metadata-refresh path.
	 *
	 * @param object $mediaItem SPIO MediaLibraryModel that was just restored.
	 * @param int    $id        WordPress attachment id.
	 * @param bool   $clean     True when every backup member was restored; false for partial restores. (Currently unused.)
	 * @return false|void False when the item isn't a media-library attachment or offloading is off; otherwise no return value.
	 */
	public function image_restore($mediaItem, $id, $clean)
	{
		$settings = \wpSPIO()->settings();

		// Only medialibrary offloading supported.
		if ('media' !== $mediaItem->get('type')) {
			return false;
		}

		$result = $this->remove_remote($id);

		if (false === $this->isActive())
		{
			return false; 
		}

		// If there are excluded sizes, there are not in backups. might not be left on remote, or ( if delete ) on server, so just generate the images and move them.
		$mediaItem->wpCreateImageSizes();		
		$this->image_upload($mediaItem);
	}

	/**
	 * Delete the offloaded copies of an attachment from the S3 bucket.
	 *
	 * Called from `image_restore()` (before re-upload) and hooked to
	 * `shortpixel-thumbnails-before-regenerate` (so stale thumbnails
	 * don't linger on S3 after regeneration).
	 *
	 * Uses as3cf's `Remove_Provider_Handler` with `verify_exists_on_local`
	 * set to null — that suppresses the Pro-tier "download the remote
	 * file back before removing" behaviour that would otherwise re-pull
	 * any leftover WebP companions.
	 *
	 * @param int $id WordPress attachment id.
	 * @return bool True when a remove was dispatched, false when the item wasn't offloaded to begin with.
	 */
	public function remove_remote($id)
	{
		$a3cfItem = $this->getItemById($id); // MediaItem is AS3CF Object
		if ($a3cfItem === false) {
			Log::addDebug('S3-Offload MediaItem not remote - ' . $id);
			return false;
		}


		$remove = \DeliciousBrains\WP_Offload_Media\Items\Remove_Provider_Handler::get_item_handler_key_name();
		$itemHandler = $this->as3cf->get_item_handler($remove);

		// Given option prevents offload pro from downloading, then re-uploading left webp files etc. (see core-pro.php)
		$itemHandler->handle($a3cfItem, ['verify_exists_on_local' => null]);


		return true;

	}


	/**
	 * Fetch the as3cf media-library item for a WordPress attachment id.
	 *
	 * When `$create=true`, a missing item is created on the fly via
	 * `create_from_source_id($id)` — used by `image_upload()` when we
	 * need to force as3cf to notice a new file that hasn't been seen yet.
	 *
	 * @param int  $id     WordPress attachment id.
	 * @param bool $create When true, create the item if it doesn't exist yet (default false).
	 * @return object|false as3cf Media_Library_Item instance, or false when the item doesn't exist and `$create=false`.
	 */
	protected function getItemById($id, $create = false)
	{
		$class = $this->getMediaClass();
		$mediaItem = $class::get_by_source_id($id);

		if (true === $create && $mediaItem === false) {
			$mediaItem = $class::create_from_source_id($id);
		}

		return $mediaItem;
	}

	/**
	 * Per-request URL → source_id cache, shared across every lookup path
	 * in this class.
	 *
	 * Three shapes, depending on which arguments are set:
	 *
	 *   1. **Read** — `sourceCache($url)` (no second arg) returns the
	 *      cached source_id for `$url`, or `null` when the URL isn't
	 *      cached yet. `false` is a valid cached value meaning "confirmed
	 *      not offloaded", so callers must distinguish `null` from
	 *      `false` explicitly.
	 *   2. **Write (new entry)** — `sourceCache($url, $source_id)`
	 *      writes the mapping and returns the just-stored `$source_id`.
	 *   3. **Write (existing entry)** — same call when `$url` is already
	 *      cached is a no-op that still returns the cached `$source_id`.
	 *      This is deliberate — earlier writers should win.
	 *
	 * URLs are normalised by stripping the scheme before lookup — as3cf's
	 * bucket URLs can arrive with mixed http/https schemes and we don't
	 * want cache misses on that alone.
	 *
	 * @param string        $url       URL being looked up / cached.
	 * @param int|false|null $source_id When set, write the mapping. Pass `false` to cache "confirmed not offloaded".
	 * @return int|false|null Cached source_id, `false` for "confirmed not offloaded", or `null` when uncached (read shape only).
	 */
	private function sourceCache($url, $source_id = null)
	{
		// remove scheme, this causes issues wit hte checkIfOffloaded is confused about the scheme. In general one might optimize this by checking without schemes in general, but this probably bites with the different offload container options
		$parsedUrl = parse_url($url);
		if (isset($parsedUrl['scheme'])) {
			$url = str_replace($parsedUrl['scheme'], '', $url);
		}

		if ($source_id === null && isset(static::$sources[$url])) {
			$source_id = static::$sources[$url];
			return $source_id;
		} elseif ($source_id !== null) {
			if (! isset(static::$sources[$url])) {
				static::$sources[$url]  = $source_id;
			}

			return $source_id;
		}

		return null;
	}

	/**
	 * `shortpixel/image/urltopath` filter — decide whether a given URL
	 * points at an as3cf-offloaded file.
	 *
	 * Fast paths, in order:
	 *
	 *   1. If the URL is already in the source cache, use the cached
	 *      answer (including `false` for "confirmed not offloaded").
	 *   2. When the *URL's* extension is webp/avif but the *rawpath's*
	 *      extension isn't, skip the lookup entirely — SPIO's own
	 *      generated webp/avif companions aren't tracked in as3cf's
	 *      path DB and the `get_item_source_by_remote_url` query would
	 *      just return nothing. `fixWebpRemotePath()` handles the "does
	 *      the sibling exist offloaded" question via a different path.
	 *   3. Otherwise, delegate to `getSourceIDByURL()` for the full
	 *      lookup (parse_url normalisation, thumbnail-suffix strip,
	 *      double-extension probing).
	 *
	 * @param bool   $bool    Existing filter value from prior handlers (ignored here).
	 * @param string $url     URL being resolved.
	 * @param string $rawpath Raw filesystem path candidate — used only for its extension.
	 * @return int|false `FileModel::$VIRTUAL_REMOTE` when the URL resolves to an offloaded item, false otherwise.
	 */
	public function checkIfOffloaded($bool, $url, $rawpath)
	{
		$source_id = $this->sourceCache($url);
		$orig_url = $url;

		if (is_null($source_id)) {
			$extension = substr($url, strrpos($url, '.') + 1);
			// Check the file extension without loading anything of the fileObj ( virtual )
			$file_extension = substr($rawpath, strrpos($rawpath, '.') + 1);

			// If these filetypes are not in the cache, they cannot be found via geSourceyIDByUrl method ( not in path DB ), so it's pointless to try. If they are offloaded, at some point the extra-info might load.
			if (false == in_array($file_extension, ['webp', 'avif'])) {
				if ($extension == 'webp' || $extension == 'avif') {
					return false;
				}
			}

			$source_id = $this->getSourceIDByURL($url);
		}


		if ($source_id !== false) {
			return FileModel::$VIRTUAL_REMOTE;
		} else {
			return false;
		}
	}

	/**
	 * Resolve a URL to its as3cf source_id, trying several lookup shapes
	 * because as3cf's `get_item_source_by_remote_url` is picky about
	 * exact URL form.
	 *
	 * Lookup order (first hit wins; each writes back to the source cache
	 * for future calls):
	 *
	 *   1. **Raw URL** — direct hit on the source cache.
	 *   2. **Scheme-normalised URL** — some as3cf configurations store
	 *      URLs without a scheme; we retry with `https://` prepended.
	 *   3. **Thumbnail-stripped URL** — a `/(.*)-\d+[xX]\d+(\.\w+)/`
	 *      regex removes WordPress's `-<W>x<H>` thumbnail suffix so we
	 *      hit the main image entry, which is what as3cf indexes.
	 *   4. **Double-extension URL** — when a URL has `.jpg.webp` style
	 *      double extensions (SPIO's double-webp delivery), retry with
	 *      only the last extension so the base `.jpg` entry can match.
	 *
	 * On a hit, an extra pass fetches the as3cf item's `extra_info()`
	 * and warms the cache with every thumbnail/variant URL it lists —
	 * so subsequent per-thumbnail lookups short-circuit at step 1.
	 *
	 * The `$cacheHit` flag tracks whether the current answer came from
	 * an intermediate cache hit; when true we skip both the final cache
	 * write and the extra_info() warming pass (both were done at the
	 * earlier write time).
	 *
	 * @param string $url URL to resolve.
	 * @return int|false as3cf source_id when found, `false` when not offloaded.
	 */
	protected function getSourceIDByURL($url)
	{
		$source_id = $this->sourceCache($url); // check cache first.
		$cacheHit = false; // prevent a cache hit to be cached again.
		$raw_url = $url; // keep raw. If resolved, add the raw url to the cache.

		// If in cache, we are done.
		if (! is_null($source_id)) {
			return $source_id;
		}

		if (is_null($source_id)) // check on the raw url.
		{
			$class = $this->getMediaClass();

			$parsedUrl = parse_url($url);

			if (
				(! isset($parsedUrl['scheme'])
					|| ! in_array($parsedUrl['scheme'], array('http', 'https')))

			) {
				if (substr($url, 0, 2) === '//')
					$url = 'https:' . $url;
				else
					$url = 'https://' . $url;
			}

			$source_id = $this->sourceCache($url);

			if (is_null($source_id)) {
				$source = $class::get_item_source_by_remote_url($url);

				$source_id = isset($source['id']) ? intval($source['id']) : null;
			} else {
				$cacheHit = true; // hit the cache. Yeah.
				$this->sourceCache($raw_url, $source_id);
			}
		}

		if (is_null($source_id)) // check now via the thumbnail hocus.
		{
			$pattern = '/(.*)-\d+[xX]\d+(\.\w+)/m';
			$url = preg_replace($pattern, '$1$2', $url);

			$source_id = $this->sourceCache($url); // check cache first.

			if (is_null($source_id)) {
				$source = $class::get_item_source_by_remote_url($url);
				$source_id = isset($source['id']) ? intval($source['id']) : null;
			} else {
				$cacheHit = true;
				$this->sourceCache($raw_url, $source_id);
			}
		}

		// Check issue with double extensions. If say double webp/avif is on, the double extension causes the URL not to be found (ie .jpg)
		if (is_null($source_id)) {
			if (substr_count($parsedUrl['path'], '.') > 1) {
				// Get extension
				$ext = substr(strrchr($url, '.'), 1);

				// Remove all extensions from the URL
				$checkurl = substr($url, 0, strpos($url, '.'));

				// Add back the last one.
				$checkurl .= '.' . $ext;

				// Retry
				$source_id = $this->sourceCache($checkurl); // check cache first.

				if (is_null($source_id)) {
					$source = $class::get_item_source_by_remote_url($url);
					$source_id = isset($source['id']) ? intval($source['id']) : null;
				} else {
					$cacheHit = true;
					$this->sourceCache($raw_url, $source_id);
				}
			}
		}

		if (is_null($source_id)) {
			$source_id = false;
		}

		if (false === $cacheHit) {
			$this->sourceCache($url, $source_id);  // cache it.
		}

		if ($source_id !== false && false === $cacheHit) {

			// get item
			$item = $this->getItemById($source_id);
			if (is_object($item) && method_exists($item, 'extra_info')) {
				$baseUrl = str_replace(basename($url), '', $url);
				//$rawBaseUrl =
				$extra_info = $item->extra_info();

				if (isset($extra_info['objects'])) {
					foreach ($extra_info['objects'] as $extraItem) {
						if (is_array($extraItem) && isset($extraItem['source_file'])) {
							// Add source stuff into cache.
							$this->sourceCache($baseUrl . $extraItem['source_file'], $source_id);
						}
					}
				}

			}

		}

		return $source_id;
	}

	/**
	 * `shortpixel/file/virtual/translate` filter — translate an offloaded
	 * URL back to the intended local filesystem path.
	 *
	 * Three fast paths before the fallback:
	 *
	 *   1. If the URL doesn't resolve to a known source_id, return false
	 *      (caller will treat it as a non-offloaded / regular file).
	 *   2. When an `$imageModel` is passed AND that model has a `size`
	 *      slug AND we've cached a per-thumbnail path for it, return
	 *      that path directly. This is the hot path during optimization
	 *      when we already warmed `self::$paths` in `add_webp_paths()`.
	 *   3. Fall back to a substring scan of `self::$paths[$source_id]`
	 *      for any entry whose value contains `basename($url)`. Handles
	 *      the case where the model's `size` slug doesn't match as3cf's
	 *      internal size names.
	 *
	 * Final fallback (no cache): fetch the as3cf item, take
	 * `original_source_path()`, and swap the main-file basename with
	 * the URL's basename so a thumbnail URL translates to the local
	 * thumbnail path in the same folder.
	 *
	 * @param string      $url         S3-based URL to translate.
	 * @param object|null $imageModel  Optional ImageModel providing a size slug + name hint (default null).
	 * @return string|false Local filesystem path, or false when the URL doesn't resolve to any known source_id.
	 */
	public function getLocalPathByURL($url, $imageModel = null)
	{
		$source_id = $this->getSourceIDByURL($url);

		if ($source_id === false) {
			return false;
		}

		if (false === is_null($imageModel) && is_object($imageModel))
		{
			$size = $imageModel->get('size'); 
			$name = $imageModel->get('name');
			
			// First trick, try to find the ImageModel Thumbnail name from the paths cache. 
			if (null !== $size && isset(static::$paths[$source_id]) && isset(static::$paths[$source_id][$size]))
			{
				return static::$paths[$source_id][$size];
			}
			
			/*elseif (null !== $name && isset(static::$paths[$source_id]) && isset(static::$paths[$source_id][$name])) 
			{
				return static::$paths[$source_id][$name];
			} */
		}

		/*$position = array_search(basename($url), self::$paths); 
		if (in_array(basename($url), self::$paths))
		{
			
		} */

		if (isset(self::$paths[$source_id]))
		{
			$base_url = basename($url); 
			foreach(self::$paths[$source_id] as $key => $path)
			{
				if (true === str_contains($path, $base_url))
				{
					return self::$paths[$source_id][$key];
				}
			}
		}

		$item = $this->getItemById($source_id);

		$original_path = $item->original_source_path(); // $values['original_source_path'];

		if (wp_basename($url) !== wp_basename($original_path)) // thumbnails translate to main file.
		{
			$original_path = str_replace(wp_basename($original_path), wp_basename($url), $original_path);
		}

		$fs = \wpSPIO()->filesystem();
		$base = $fs->getWPUploadBase();

		$file  = $base . $original_path;
		return $file;
	}


	/**
	 * Post-conversion handler (e.g. PNG→JPG).
	 *
	 * Not currently hooked — kept as a helper for callers that want to
	 * force a re-upload after a conversion. Delegates to `image_upload()`
	 * so the bucket picks up the converted file. The commented-out
	 * `remove_remote()` line above the delegate suggests a prior version
	 * of the flow also removed the pre-conversion copy first; that was
	 * dropped because as3cf's own update-metadata path handles it.
	 *
	 * @param object $mediaItem SPIO MediaLibraryModel just converted.
	 * @return void
	 */
	public function image_converted($mediaItem)
	{
		$fs = \wpSPIO()->fileSystem();

		$id = $mediaItem->get('id');
		//$this->remove_remote($id);
		$this->image_upload($mediaItem);
	}

	/**
	 * `shortpixel/image/optimised` action — push the optimized file
	 * (and any thumbnails / companions) up to the S3 bucket.
	 *
	 * Rather than calling as3cf's upload API directly, we re-fire
	 * `wp_update_attachment_metadata` with the current metadata array
	 * so as3cf's own metadata hook picks it up and does the right thing
	 * — including WebP/AVIF companion delivery via
	 * `add_webp_paths()` which is wired to `as3cf_attachment_file_paths`.
	 *
	 * `$shouldPrevent` is toggled off across the metadata refresh so
	 * `preventInitialUploadHandler` doesn't block the upload we just
	 * asked for.
	 *
	 * Bails early when:
	 *   - The item isn't a media-library attachment.
	 *   - The as3cf item lookup returns false (never seen).
	 *   - as3cf can't create-on-demand AND `copy-to-s3` is off.
	 *
	 * @param object $mediaLibraryObject SPIO MediaLibraryModel that was just optimized.
	 * @return false|void False on bail, otherwise no return value.
	 */
	public function image_upload($mediaLibraryObject)
	{
		$id = $mediaLibraryObject->get('id');
		$a3cfItem = $this->getItemById($id);

		// Only medialibrary offloading supported.
		if ('media' !== $mediaLibraryObject->get('type')) {
			return false;
		}

		if (false === $a3cfItem) {
			return false;
		}

		$item = $this->getItemById($id, true);

		if ($item === false && ! $this->as3cf->get_setting('copy-to-s3')) {
			// abort if not already uploaded to provider and the copy setting is off
			Log::addDebug('As3cf image upload is off and object not previously uploaded');
			return false;
		}

		// Add Web/Avifs back under new method.
		$this->shouldPrevent = false;

		// The Handler doesn't work properly /w local removal if not the exact correct files are passed (?) . Offload does this probably via update metadata function, so let them sort it out with this . (until it breaks)
		$meta = wp_get_attachment_metadata($id);
		wp_update_attachment_metadata($id, $meta);


		$this->shouldPrevent = true;
	}


	/**
	 * `shortpixel_get_original_image_path` filter — strip `-scaled` from
	 * a filepath so callers get the unscaled original.
	 *
	 * WP Offload Media (with "remove file from server" enabled) can
	 * return the same path for `get_attached_file()` and
	 * `wp_get_original_image_path()`, which then confuses SPIO's
	 * downloader — it tries to copy the remote file to the scaled path
	 * even when it needs the original. This filter forces the original
	 * shape by stripping a trailing `-scaled` suffix from the basename.
	 *
	 * The strip is anchored to `-scaled.<ext>` at the end of the path, so
	 * folder names containing `-scaled` (e.g. `my-scaled-photos/`) are
	 * left untouched.
	 *
	 * @param string $filepath Filepath from the upstream filter.
	 * @param int    $id       WordPress attachment id (unused).
	 * @return string Filepath with a trailing `-scaled` suffix removed.
	 */
	public function checkScaledUrl($filepath, $id)
	{
		// Original filepath can never have a scaled in there.
		if (strpos($filepath, '-scaled') !== false) {
			$filepath = preg_replace('/-scaled(\.[a-z0-9]+)$/i', '$1', $filepath);
			//$filepath = str_replace('-scaled', '', $filepath);
		}
		return $filepath;
	}

	/**
	 * `as3cf_pre_handle_item_upload` filter — cancel as3cf's initial
	 * upload of a raw (un-optimized) file, so the bucket only ever
	 * holds the optimized version.
	 *
	 * Decision cascade:
	 *
	 *   1. **No quota** → return false (don't prevent). SPIO can't
	 *      optimize without quota, so let as3cf upload the raw file
	 *      rather than losing it entirely.
	 *   2. **`copy-to-s3` off** → return false (don't prevent). If the
	 *      site owner turned offload off entirely, respect that.
	 *   3. **`shouldPrevent` false** → return false (don't prevent).
	 *      This is the guard SPIO's own `image_upload` uses when it
	 *      *wants* the upload to go through.
	 *   4. **On the prevent-offload list** → return a `WP_Error`
	 *      (`upload-prevented`) which as3cf treats as "abort this
	 *      upload". Used by the converter around its rewrite window.
	 *   5. **Fallback** → pass through the incoming `$bool`.
	 *
	 * @param mixed  $bool         Incoming filter value from previous handlers.
	 * @param object $as3cf_item   as3cf Media_Library_Item instance being uploaded.
	 * @param array  $options      Upload options (unused).
	 * @return mixed False to allow, WP_Error to abort, or the passed-through `$bool`.
	 */
	public function preventInitialUploadHandler($bool, $as3cf_item, $options)
	{

		$fs = \wpSPIO()->filesystem();
		$settings = \WPSPIO()->settings();

		$post_id = $as3cf_item->source_id();

		$quotaController = quotaController::getInstance();
		if ($quotaController->hasQuota() === false) {
			return false;
		}

		if (! $this->offloading) {
			return false;
		}

		if ($this->shouldPrevent === false) // if false is returned, it's NOT prevented, so on-going.
		{
			return false;
		}

		if (isset(self::$offloadPrevented[$post_id])) {
			Log::addDebug('Offload Prevented via static for ' . $post_id);
			$error = new \WP_Error('upload-prevented', 'No offloading at this time, thanks');
			return $error;
		}

		if (true === $bool)
		{
			Log::addDebug('Offload Prevented via bool for ' . $post_id);
		}
		

		return $bool;
	}

	/**
	 * `shortpixel/image/convertpng2jpg_success` action — rewrite as3cf's
	 * stored `original_path` / `original_source_path` after a PNG→JPG
	 * conversion so future lookups find the renamed file.
	 *
	 * When a file is converted, its filename changes (e.g. `photo.png`
	 * → `photo.jpg`), but as3cf's stored paths still reference the
	 * old name. Without this rewrite, subsequent
	 * `get_item_source_by_remote_url` calls miss the item and SPIO
	 * loses track of the offloaded original.
	 *
	 * Detection uses `wp_basename` comparison — the WP-side "original"
	 * path (respecting the `emr_unfiltered_get_attached_file` and
	 * `emr/replace/original_image_path` filters from Enable Media
	 * Replace) is treated as the source of truth. If its basename
	 * doesn't match as3cf's stored `original_path` basename, both
	 * `original_path` and `original_source_path` are rewritten with
	 * the WP basename and the item is saved.
	 *
	 * Side-effect: `self::$sources = []` — the URL → source_id cache
	 * is wiped because the previously-cached URLs now point at renamed
	 * paths.
	 *
	 * @param object $imageModel SPIO ImageModel of the just-converted attachment.
	 * @return false|void False when the attachment isn't offloaded; otherwise no return value.
	 */
	public function updateOriginalPath($imageModel)
	{
		$post_id = $imageModel->get('id');

		$item = $this->getItemById($post_id);

		if (false === $item) // item not offloaded.
		{
			return false;
		}

		$original_path = $item->original_path(); // Original path (non-scaled-)
		$original_source_path = $item->original_source_path();
		$path = $item->path();
		$source_path = $item->source_path();

		$wp_original = wp_get_original_image_path($post_id, apply_filters('emr_unfiltered_get_attached_file', true));
		$wp_original = apply_filters('emr/replace/original_image_path', $wp_original, $post_id);
		$wp_source = trim(get_attached_file($post_id, apply_filters('emr_unfiltered_get_attached_file', true)));

		$updated = false;

		self::$sources = [];  // Wipe the source cache to prevent lingering stuff. 

		// If image is replaced with another name, the original soruce path will not match.  This could also happen when an image is with -scaled as main is replaced by an image that doesn't have it.  In all cases update the table to reflect proper changes.
		if (wp_basename($wp_original) !== wp_basename($original_path)) {

			$newpath = str_replace(wp_basename($original_path), wp_basename($wp_original), $original_path);

			$item->set_original_path($newpath);

			$newpath = str_replace(wp_basename($original_source_path), wp_basename($wp_original), $original_source_path);
			$updated = true;

			$item->set_original_source_path($newpath);

			$item->save();
		}
	}

	/**
	 * Expand a size-slug → path map to also include the WebP and AVIF
	 * companion paths for each entry.
	 *
	 * For every original path, up to four extra entries can be produced:
	 *
	 *   - `<size>_webp`  — `<fileName>.webp` (append form, e.g. `photo.jpg.webp`)
	 *   - `<size>_webp2` — `<fileBase>.webp` (replace form, e.g. `photo.webp`)
	 *   - `<size>_avif`  — `<fileName>.avif`
	 *   - `<size>_avif2` — `<fileBase>.avif`
	 *
	 * When the source file's own extension is already `webp` (or `avif`),
	 * the matching companion series is skipped — the file *is* the
	 * companion. When `$check_exists=true`, each candidate is only added
	 * if the file actually exists on disk (used at upload-time so the
	 * bucket doesn't get 404-ing companions); when false, all candidates
	 * are added blindly (used during removal to catch any variant that
	 * might exist remotely).
	 *
	 * @param array<string, string> $paths        Input map: size slug → filesystem path.
	 * @param bool                  $check_exists When true, only include companions that exist on disk (default true).
	 * @return array<string, string> The input map plus any `<size>_webp*` / `<size>_avif*` entries.
	 */
	private function getWebpPaths($paths, $check_exists = true)
	{
		$newPaths = array();
		$fs = \wpSPIO()->fileSystem();

		foreach ($paths as $size => $path) {
			$file = $fs->getFile($path);

			$basedir = $file->getFileDir();

			if (is_null($basedir)) // This could only happen if path is completely empty.
			{
				continue;
			}

			$basepath = $basedir->getPath();

			$newPaths[$size] = $path;

			// If webp/avif is native, don't add them. 
			$addWebp = $addAvif = true; 
			if ('webp' == $file->getExtension())
			{
				 $addWebp = false; 
			}

			if ('avif' == $file->getExtension())
			{
				$addAvif = false; 
			}

			$webpformat1 = $basepath . $file->getFileName() . '.webp';
			$webpformat2 = $basepath . $file->getFileBase() . '.webp';

			$avifformat =  $basepath . $file->getFileName() . '.avif';
			$avifformat2 = $basepath . $file->getFileBase() . '.avif';


			if (true === $addWebp)
			{
				if ($check_exists) {
					if (file_exists($webpformat1))
						$newPaths[$size . '_webp'] =  $webpformat1;
				} else {
					$newPaths[$size . '_webp'] =  $webpformat1;
				}
	
				if ($check_exists) {
					if (file_exists($webpformat2))
						$newPaths[$size . '_webp2'] =  $webpformat2;
				} else {
					$newPaths[$size . '_webp2'] =  $webpformat2;
				}
	
			}

			if (true === $addAvif)
			{
				if ($check_exists) {
					if (file_exists($avifformat)) {
						$newPaths[$size . '_avif'] = $avifformat;
					}
				} else {
					$newPaths[$size . '_avif'] = $avifformat;
				}
	
				if ($check_exists) {
					if (file_exists($avifformat2)) {
						$newPaths[$size . '_avif2'] = $avifformat2;
					}
				} else {
					$newPaths[$size . '_avif2'] = $avifformat2;
				}	
			}

		}

		return $newPaths;
	}

	/**
	 * `as3cf_attachment_file_paths` filter — augment as3cf's upload
	 * payload with existing WebP/AVIF companions.
	 *
	 * as3cf calls this filter to learn which files it should upload
	 * alongside the main attachment. We insert the WebP/AVIF paths
	 * from `getWebpPaths()` so the bucket ends up with the full family.
	 *
	 * Result is cached per attachment id in `self::$paths` — subsequent
	 * calls short-circuit to the cached map. This also populates the
	 * cache used by `getLocalPathByURL()` for local-path resolution.
	 *
	 * @param array<string, string> $paths         Existing size → path map from as3cf.
	 * @param int                   $attachment_id WordPress attachment id.
	 * @param array                 $meta          Attachment metadata (unused).
	 * @return array<string, string> Augmented size → path map.
	 * @todo Verify the caching interaction with later `remove_remote()` calls doesn't leave stale entries.
	 */
	public function add_webp_paths($paths, $attachment_id, $meta)
	{ // @todo Check if this works.
		if (isset(self::$paths[$attachment_id]))
		{
			return self::$paths[$attachment_id];
		}

		$paths = $this->getWebpPaths($paths, true);

		self::$paths[$attachment_id] = $paths;
		return $paths;
	}

	/*
	public function remove_webp_paths($paths)
	{
		$paths = $this->getWebpPaths($paths, false);
		return $paths;
	}
	*/

	/**
	 * `shortpixel/front/webp_notfound` filter — decide whether a WebP/AVIF
	 * companion exists remotely when the local file wasn't found.
	 *
	 * The frontend PictureController uses this to emit `<picture>` tags
	 * with a WebP source only when the WebP actually exists. as3cf's
	 * `get_item_source_by_remote_url` can't find thumbnails directly
	 * (it only indexes the main image), so we probe the main URL's
	 * source_id first and, if found, treat the WebP as present.
	 *
	 * Fast-path: when the webp URL isn't cached but the main URL IS,
	 * return false — the caller already knows the family is offloaded
	 * but the specific companion isn't tracked, so don't advertise it.
	 *
	 * @param bool   $bool          Incoming filter value.
	 * @param object $fileObj       SPIO FileModel of the WebP/AVIF companion being probed.
	 * @param string $url           URL of the main file (e.g. the `.jpg`).
	 * @param object $imagebaseDir  SPIO DirectoryModel of the remote path (unused).
	 * @return object|false The `$fileObj` when the main file resolves to an offloaded source, false otherwise.
	 */
	public function fixWebpRemotePath($bool, $fileObj, $url, $imagebaseDir)
	{
		$extension = $fileObj->getExtension();
		$fs = \wpSPIO()->filesystem();

		$webpUrl = $fileObj->getFullPath();
		$main_is_loaded = $this->sourceCache($url); // main image, check if loaded.
		if ($fs->pathIsURL($webpUrl)) {
			$url = $webpUrl;
			$res = $this->sourceCache($url);

			if (is_null($res) && ! is_null($main_is_loaded)) {
				return false;
			}
		}

		$source_id = $this->getSourceIDByURL($url);

		if (false === $source_id) {
			return false;
		} else {
			return $fileObj;
		}
	}
}
