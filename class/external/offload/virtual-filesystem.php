<?php
namespace ShortPixel\External\Offload;

use ShortPixel\Model\File\FileModel as FileModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Virtual-filesystem adapter — a generic offload handler for stateless
 * remote filesystems (Bitpoke Stack, S3-Uploads-Human, etc.) that don't
 * ship a full WP Offload Media-style API.
 *
 * Instantiated by `Offloader::load()` when `checkVirtualLoaders()`
 * identifies one of the supported plugins. The adapter's job is to make
 * SPIO's file / URL layer treat every file as `VIRTUAL_STATELESS` so the
 * heavy operations (thumbnail generation, unlisted-file scans, retina
 * detection) don't try to touch a local disk that doesn't exist.
 *
 * @package ShortPixel\External\Offload
 */
class VirtualFileSystem
{

		/** @var string Short identifier of the underlying offloader (matches Offloader::$offloadName). */
		protected $offloadName;

		/**
		 * Store the offloader identifier and register the three
		 * virtual-filesystem filters via `listen()`.
		 *
		 * @param string $name Offloader short name (e.g. `stack`, `s3-uploads-human`).
		 */
		public function __construct($name)
		{
				$this->offloadName = $name;
				$this->listen();
		}

		/**
		 * Register the three filters this adapter answers to:
		 *
		 *   - `shortpixel/image/urltopath` → `checkIfOffloaded()`
		 *   - `shortpixel/file/virtual/translate` → `getLocalPathByURL()`
		 *   - `shortpixel/file/virtual/heavy_features` → `extraFeatures()`
		 *
		 * @return void
		 */
		public function listen()
		{
				//  $fs = \wpSPIO()->fileSystem()->startTrustedMode(); // @todo check if this works trusted mode forever.
					add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,3);
					add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));
					add_filter('shortpixel/file/virtual/heavy_features', array($this, 'extraFeatures'), 10);
		}

		/**
		 * `shortpixel/image/urltopath` filter — declare whether the URL
		 * is a virtual (stateless remote) file.
		 *
		 * Only fires the VIRTUAL_STATELESS return for the `s3-uploads-human`
		 * offloader. Any other offloader falls through to the file_exists
		 * probe below. (Prior to 399b29e2 the first `if` used `=` instead
		 * of `===`, silently rewriting `$this->offloadName` on every call
		 * and returning VIRTUAL_STATELESS unconditionally.)
		 *
		 * @param bool   $bool    Existing filter value from prior handlers.
		 * @param string $url     URL being checked.
		 * @param string $rawpath Raw filesystem path candidate.
		 * @return int|false `FileModel::$VIRTUAL_STATELESS` on match, false otherwise.
		 */
		public function checkIfOffloaded($bool, $url, $rawpath)
		{
				// Slow as it is, check nothing.
			 if ('s3-uploads-human' === $this->offloadName)
			 {
				 return FileModel::$VIRTUAL_STATELESS;
			 }

			 if (file_exists($url))
			 {
				 return FileModel::$VIRTUAL_STATELESS;
			 }
			 return false;
		}

		/**
		 * `shortpixel/file/virtual/translate` filter — translate a
		 * virtual URL into a local path.
		 *
		 * The base implementation is a passthrough (no translation).
		 * Subclasses / specific-plugin adapters can override this to
		 * point at a cached local copy when the plugin provides one.
		 *
		 * @param string $path Virtual URL / path passed in by the filter.
		 * @return string Local filesystem path (same as input in the base case).
		 */
		public function getLocalPathByURL($path)
		{
			 return $path;
		}

		/**
		 * `shortpixel/file/virtual/heavy_features` filter — hard-disable
		 * the expensive features that scan outside WP's metadata.
		 *
		 * Features like unlisted-file scanning and retina detection
		 * would need to enumerate remote objects, which is slow enough
		 * to make pages time out on stateless filesystems. Returning
		 * false short-circuits the whole feature family.
		 *
		 * @return false Always false — heavy features stay off for virtual filesystems.
		 */
		public function extraFeatures()
		{
			 return false;
		}

		/**
		 * Whether this adapter is currently active.
		 *
		 * Always true: once the adapter is instantiated, the filters
		 * above are registered unconditionally. There's no "off state"
		 * to detect — a misconfigured install just gets stateless
		 * behaviour on files that could have been local.
		 *
		 * @return true
		 */
		public function isActive()
		{
			 return true;
		}



} // class
