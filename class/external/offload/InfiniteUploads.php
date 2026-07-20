<?php

namespace ShortPixel\External\Offload;

use ShortPixel\Model\File\FileModel as FileModel;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

/**
 * Adapter stub for the InfiniteUploads plugin.
 *
 * Currently a **stub**: the constructor's two filter registrations
 * (`shortpixel/image/urltopath`, `shortpixel/file/virtual/translate`)
 * are commented out and both handler methods have empty bodies. The class
 * is still instantiated by `Offloader::checkVirtualLoaders()` when
 * `INFINITE_UPLOADS_VERSION` is defined AND `infinite_uploads_enabled()`
 * returns true — but since nothing hooks and nothing responds, the
 * integration is effectively a no-op.
 *
 * Either the InfiniteUploads support is unfinished / deferred, or the
 * whole class should be removed together with its dispatch line in
 * `Offloader`. Flagged for triage in the deferred-root-bugs memo.
 *
 * @package ShortPixel\External\Offload
 */
class InfiniteUploads
{

		/**
		 * Constructor.
		 *
		 * Both filter registrations that would wire this class into SPIO's
		 * virtual-filesystem hooks are commented out. The instance is inert
		 * until they're re-enabled.
		 */
		public function __construct()
		{
		//	add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10, 3);
		//	add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));
		}


		/**
		 * Intended callback for `shortpixel/image/urltopath` — decide
		 * whether a given URL points at an InfiniteUploads-offloaded file.
		 *
		 * Currently a no-op; the constructor's `add_filter` call is
		 * commented out so this method is never invoked.
		 *
		 * @param bool   $boolean  Existing filter value from prior handlers.
		 * @param string $url      URL being checked.
		 * @param string $fullpath Full filesystem path candidate.
		 * @return void
		 */
		public function checkIfOffloaded($boolean, $url, $fullpath)
		{

		}

		/**
		 * Intended callback for `shortpixel/file/virtual/translate` —
		 * translate a virtual URL into a local path when InfiniteUploads
		 * has a cached copy locally.
		 *
		 * Currently a no-op; the constructor's `add_filter` call is
		 * commented out so this method is never invoked.
		 *
		 * @return void
		 */
		public function getLocalPathByURL()
		{

		}




}