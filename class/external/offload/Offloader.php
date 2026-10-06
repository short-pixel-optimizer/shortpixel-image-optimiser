<?php

namespace ShortPixel\External\Offload;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Offload dispatcher — detects which third-party offload plugin is
 * active and boots the matching handler.
 *
 * Two hook entry points:
 *
 *   - `plugins_loaded` → `load()` — checks for virtual-filesystem-style
 *     offloaders (Stack / Bitpoke MU, S3-Uploads-Human, InfiniteUploads).
 *     When one is detected, instantiates a `VirtualFileSystem` (or the
 *     dedicated `InfiniteUploads` adapter for that specific plugin).
 *   - `as3cf_init` → `initS3Offload()` — WP Offload Media (as3cf) fires
 *     this after plugins_loaded, so this handler runs later and boots
 *     the full-featured `wpOffload` integration when the plugin is
 *     present. If a virtual-filesystem handler already claimed the slot,
 *     the wp-offload path is skipped and a log warning is written.
 *
 * The class is a singleton (see `getInstance()`). It self-boots at the
 * bottom of this file — the `Offloader::getInstance();` line after the
 * class declaration ensures the two `add_action` calls run as soon as
 * the file is loaded by the autoloader. This is intentional so the
 * `plugins_loaded` / `as3cf_init` hooks get registered before either
 * hook fires.
 *
 * @package ShortPixel\External\Offload
 */
class Offloader
{
	/** @var Offloader|null Singleton instance held by getInstance(). */
	private static $instance;

	/** @var VirtualFileSystem|wpOffload|InfiniteUploads|null The active offload handler instance, if any. */
	private static $offload_instance;

	/** @var string|null Short identifier for the detected offloader (`stack`, `s3-uploads-human`, `infinite-uploads`, `wp-offload`). */
	private $offloadName;

	/**
	 * Return the singleton `Offloader` instance, constructing it (and
	 * registering the plugins_loaded / as3cf_init hooks) on first call.
	 *
	 * @return Offloader
	 */
	public static function getInstance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new Offloader();
		}

		return self::$instance;
	}

	/**
	 * Register the two hook entry points. See the class docblock for the
	 * decision order between `load()` (plugins_loaded) and
	 * `initS3Offload()` (as3cf_init).
	 */
	public function __construct()
	{
		add_action('plugins_loaded', array($this, 'load'));
		add_action('as3cf_init', array($this, 'initS3Offload'));
	}

	/**
	 * `plugins_loaded` callback — try to detect a virtual-filesystem
	 * offloader and instantiate its handler.
	 *
	 * Delegates detection to `checkVirtualLoaders()`. On success, wires
	 * a `VirtualFileSystem` adapter (unless the offloader was
	 * InfiniteUploads, which self-instantiates its dedicated adapter
	 * inside `checkVirtualLoaders()`).
	 *
	 * @return void
	 */
	public function load()
	{
		$bool = $this->checkVirtualLoaders();
		if (true === $bool) {
			self::$offload_instance = new VirtualFileSystem($this->offloadName);
		}
	}

	/**
	 * Detect the currently-active virtual-filesystem offload plugin, if
	 * any, and record its short name on `$offloadName`.
	 *
	 * Detection order (first match wins):
	 *
	 *   1. Bitpoke Stack MU (`\Stack\Config` or `STACK_MEDIA_BUCKET`
	 *      constant) → `stack`
	 *   2. Human Made S3-Uploads (`\S3_Uploads\Plugin`) → `s3-uploads-human`
	 *   3. InfiniteUploads (`INFINITE_UPLOADS_VERSION` +
	 *      `infinite_uploads_enabled()` true) → `infinite-uploads`;
	 *      side-effect: instantiates the `InfiniteUploads` adapter here
	 *      so `load()` doesn't wrap it in a plain `VirtualFileSystem`.
	 *
	 * A commented-out WP-Stateless (`ud_check_stateless_media`) branch
	 * is left in place as a reminder that it was tried and didn't work.
	 *
	 * @return bool True when an offloader was detected and `$offloadName` set; false otherwise.
	 */
	protected function checkVirtualLoaders()
	{
		if (class_exists('\Stack\Config')) // Bitpoke Stack MU
		{
			$this->offloadName = 'stack';
			return true;
		} elseif (defined('STACK_MEDIA_BUCKET')) {
			$this->offloadName = 'stack';
			return true;
		} elseif (class_exists('\S3_Uploads\Plugin')) {
			$this->offloadName = 's3-uploads-human';
			return true;
		}
		elseif(defined('INFINITE_UPLOADS_VERSION'))   // infinite uploads
		{
			$this->offloadName = 'infinite-uploads'; 
			if (function_exists('infinite_uploads_enabled') && true == \infinite_uploads_enabled())
			{
				 self::$offload_instance = new InfiniteUploads();
			}
			return true;
		}	
		/* (Doesn't work)
				elseif (function_exists('ud_check_stateless_media'))
				{
					 $this->offloadName = 'wp-stateless';
					 return true;
				} */
		return false;
	}

	/**
	 * `as3cf_init` callback — boot the WP Offload Media (`wpOffload`)
	 * integration if no virtual-filesystem handler claimed the slot on
	 * `plugins_loaded` first.
	 *
	 * The `as3cf_init` hook fires later than `plugins_loaded`, so this
	 * runs after `load()`. When a virtual-filesystem offloader is
	 * already active, we log an error rather than double-instantiate
	 * (an install with two offloaders configured is likely misconfigured).
	 *
	 * @param object $as3cf The WP Offload Media plugin instance (as3cf) passed by the hook.
	 * @return void
	 */
	public function initS3Offload($as3cf)
	{
		if (is_null(self::$offload_instance)) {
			$this->offloadName = 'wp-offload';
			self::$offload_instance = wpOffload::getInstance($as3cf);
		} else {
			Log::addError('Instance is not null - other virtual component has loaded! (' . $this->offloadName . ')');
		}
	}

	/**
	 * Whether the requested offloader is present AND currently offloading.
	 *
	 * Only the `wp-offload` variant delegates to a real `isActive()` check
	 * (the wpOffload class tracks its own `active` + `offloading` state).
	 * Callers asking about a different offloader get `null` (contract:
	 * "not implemented, ask again later") — do NOT treat null as false.
	 * When no offloader was detected at all, returns strict `false`.
	 *
	 * @param string $name Offloader identifier to query (default: `wp-offload`).
	 * @return bool|null False when no offloader is present, true/false when the wp-offload variant answers, null for other offloaders (not implemented).
	 */
	public function isActive($name = 'wp-offload')
	{
		// No offloaders / nothing active.
		if (is_null($this->offloadName))
		{
			 return false;
		}

		if ('wp-offload' == $name)
		{
			return self::$offload_instance->isActive();
		}

		// Other offloaders not implemented. Can be added on demand.
		return null;
	}

	/**
	 * Return the short identifier of the detected offloader (e.g. `stack`,
	 * `wp-offload`, `s3-uploads-human`, `infinite-uploads`), or null when
	 * no offloader was detected.
	 *
	 * @return string|null
	 */
	public function getOffloadName()
	{
		return $this->offloadName;
	}
}

// Self-boot: instantiating the singleton at file load time registers the
// plugins_loaded / as3cf_init hooks before either fires. See the class
// docblock for the rationale.
Offloader::getInstance(); // init
