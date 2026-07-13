<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Third-party page/object-cache invalidation on optimisation.
 *
 * When SPIO finishes optimising an image, cached HTML that still
 * points at the old file (or serves the pre-optimised bytes from a
 * memory / edge cache) needs to be purged, otherwise visitors keep
 * seeing the un-optimised version until the cache expires naturally.
 *
 * Wiring:
 *   - Hooks `shortpixel/image/optimised` (in `addHooks()`) so every
 *     optimised item triggers `flushCache($imageItem)`.
 *   - Self-boots at file-load time via `cacheRemover::getInstance()`
 *     at the bottom of this file — same pattern as `Offloader` and
 *     `NextGenController`. Rationale: the constructor registers the
 *     `shortpixel/image/optimised` action and needs to be attached
 *     before that action ever fires.
 *
 * Detected cache plugins (see `checkCaches()`):
 *   W3 Total Cache, WP Super Cache, WP Engine, WP Fastest Cache,
 *   SiteGround SG Optimizer, LiteSpeed Cache.
 *
 * NOTE: two `@todo` markers acknowledge missing integrations —
 * WP Rocket and BlueHost Caching. Adding those is a matter of
 * mirroring the existing pattern (detect in `checkCaches()`, add a
 * `$has_*` flag, add a `remove*()` method, wire in `flushCache()`).
 *
 * The `shortpixel/external/flush_cache` filter is an operator escape
 * hatch — return `false` from that filter to skip every branch of
 * this method (see `flushCache()`).
 *
 * @package ShortPixel
 */
class cacheRemover
{
    /** @var bool WP Super Cache detected. Currently unused — supercache dispatch is commented out in flushCache() and `removeSuperCache()` is dead code. Left flagged with @todo above. */
    protected $has_supercache  = false; // supercache seems to replace quite fine, without our help. @todo Test if this is needed
    /** @var bool W3 Total Cache detected via `w3tc_pgcache_flush` function existence. */
    protected $has_w3tc = false;
    /** @var bool WP Engine detected via `WpeCommon` class existence. */
    protected $has_wpengine = false;
    /** @var bool WP Fastest Cache detected via `WpFastestCache::deleteCache` method + non-empty global. */
    protected $has_fastestcache = false;
    /** @var bool SiteGround SG Optimizer detected via `sg_cachepress_purge_cache` function existence. */
    protected $has_siteground = false;
    /** @var bool LiteSpeed Cache detected via `LSCWP_DIR` constant. */
    protected $has_litespeed = false;

		/** @var cacheRemover|null Singleton instance held by getInstance(). */
		private static $instance;

    /**
     * Register the optimisation hook and take a snapshot of which
     * cache plugins are active right now.
     *
     * The `checkCaches()` snapshot is refreshed on every `flushCache()`
     * call as well (see the note in that method), so the constructor
     * snapshot is a starting value more than a load-bearing one.
     */
    public function __construct()
    {
			 $this->addHooks();
			 $this->checkCaches();
    }

		/**
		 * Return the singleton, constructing it (and wiring the
		 * `shortpixel/image/optimised` hook) on first call.
		 *
		 * @return cacheRemover
		 */
		public static function getInstance()
		{
			 if (is_null(self::$instance))
			 	self::$instance = new cacheRemover();

			return self::$instance;
		}

		/**
		 * Attach `flushCache()` to the `shortpixel/image/optimised`
		 * action so every successful optimisation triggers a cache
		 * flush pass.
		 *
		 * @return void
		 */
		public function addHooks()
		{
			add_action('shortpixel/image/optimised', array($this, 'flushCache'));
		}

    /**
     * Detect which cache plugins are active on the current site and
     * set the `$has_*` flags accordingly. Called from the constructor
     * once (as an initial snapshot) and again from `flushCache()`
     * before every dispatch, because a plugin can be
     * activated/deactivated between requests.
     *
     * Detection strategy differs per plugin:
     *   - function_exists  — most (w3tc, supercache, sg, fastest)
     *   - class_exists     — WpEngine (`WpeCommon`)
     *   - defined          — LiteSpeed (`LSCWP_DIR`)
     *   - method_exists    — WP Fastest also checks the deleteCache
     *     method exists AND the global instance is non-empty
     *
     * @return void
     */
    public function checkCaches()
    {
      if ( function_exists( 'w3tc_pgcache_flush' ) )
        $this->has_w3tc = true;

      if ( function_exists('wp_cache_clean_cache') )
        $this->has_supercache = true;

      if ( class_exists( 'WpeCommon' ) )
          $this->has_wpengine = true;

      global $wp_fastest_cache;
      if ( method_exists( 'WpFastestCache', 'deleteCache' ) && !empty( $wp_fastest_cache ) )
          $this->has_fastestcache = true;

      // SG SuperCacher
      if (function_exists('sg_cachepress_purge_cache')) {
	        $this->has_siteground = true;
      }

      if (defined( 'LSCWP_DIR' ))
      {
          $this->has_litespeed = true;
      }

      // @todo WpRocket?
      // @todo BlueHost Caching?
    }

    /**
     * Dispatch a cache-flush pass across every detected third-party
     * cache plugin, plus the core WP object cache.
     *
     * Post-id resolution:
     *   - Media-library items → `$imageItem->get('id')` (the attachment ID)
     *   - Custom-media items  → `0` (no attachment post exists)
     *
     * The `$post_id === 0` case falls through to `wp_cache_flush()`
     * (site-wide) because there's no `clean_post_cache` to call —
     * custom media aren't attachments.
     *
     * Filter escape hatch: `apply_filters('shortpixel/external/flush_cache', true, $post_id, $imageItem)`.
     * Return `false` from that filter to skip every branch of this
     * method (including the core `clean_post_cache` / `wp_cache_flush`
     * call) — used by operators who manage cache invalidation from a
     * different subsystem and don't want SPIO to interfere.
     *
     * Return value: `false` when the filter says no, otherwise no
     * return (void). This inconsistency is intentional — callers on
     * the action bus don't read the return, but keeping `false`
     * makes it grep-able.
     *
     * @param object $imageItem Optimised item — must respond to `get('type')`, `get('id')`, and `getAllUrls()`.
     * @return false|void `false` when the filter blocks flushing; void otherwise.
     */
    public function flushCache($imageItem)
    {
        if ($imageItem->get('type') == 'custom')
				{
					$post_id = 0;
				}
				else {
					$post_id = $imageItem->get('id');
				}

        // important - first check the available cache plugins
        $this->checkCaches();
        
        $bool = apply_filters('shortpixel/external/flush_cache', true, $post_id, $imageItem);
        if (false === $bool)
        {
           return false;
        }

        // general WP
        if ($post_id > 0)
          clean_post_cache($post_id);
        else
          wp_cache_flush();

        /*  Verified working without.
          if ($this->has_supercache)
            $this->removeSuperCache();
        */
        if ($this->has_w3tc)
            $this->removeW3tcCache();

        if ($this->has_wpengine)
            $this->removeWpeCache();

        if ($this->has_siteground)
            $this->removeSiteGround();

        if ($this->has_fastestcache)
            $this->removeFastestCache();

        if ($this->has_litespeed)
            $this->litespeedReset($imageItem);

    }

    /**
     * WP Super Cache flush — currently DEAD CODE. The caller branch
     * in `flushCache()` is commented out (with a note "Verified
     * working without.") so this method is never reached. Left in
     * place in case supercache changes behaviour upstream. Flagged in
     * the deferred-root-bugs memo.
     *
     * @return void
     */
    protected function removeSuperCache()
    {
       global $file_prefix, $supercachedir;
	     if ( empty( $supercachedir ) && function_exists( 'get_supercache_dir' ) ) {
	          $supercachedir = get_supercache_dir();
	     }
	     wp_cache_clean_cache( $file_prefix );
    }

    /**
     * W3 Total Cache page-cache flush — single-call delegation.
     *
     * @return void
     */
    protected function removeW3tcCache()
    {
      w3tc_pgcache_flush();
    }

    /**
     * WP Engine cache flush — clears memcached, MaxCDN, and Varnish
     * in that order. Each layer is defensive-guarded because WPE has
     * shipped different subsets of these methods across versions.
     *
     * @return void
     */
    protected function removeWpeCache()
    {
      if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
          \WpeCommon::purge_memcached();
      }
      if ( method_exists( 'WpeCommon', 'clear_maxcdn_cache' ) ) {
          \WpeCommon::clear_maxcdn_cache();
      }
      if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
          \WpeCommon::purge_varnish_cache();
      }
    }

    /**
     * WP Fastest Cache flush — delegates to the plugin's global
     * `$wp_fastest_cache->deleteCache()`. `checkCaches()` already
     * verified the global is set + the method exists, so no
     * additional guarding needed here.
     *
     * @return void
     */
    protected function removeFastestCache()
    {
      global $wp_fastest_cache;
      $wp_fastest_cache->deleteCache();
    }

    /**
     * SiteGround SG Optimizer purge — single-call delegation.
     *
     * @return void
     */
    protected function removeSiteGround()
    {
    		sg_cachepress_purge_cache();
    }

    /**
     * LiteSpeed Cache purge — per-URL fan-out.
     *
     * LiteSpeed purges by URL (not by post ID), so we iterate every
     * URL that belongs to this item — main file + every thumbnail
     * variant — and fire `litespeed_purge_url` for each. The
     * `LITESPEED_PURGE_SILENT` constant is defined defensively to
     * suppress admin notices during the purge storm.
     *
     * URL-shape handling: `getAllUrls()` returns different shapes
     * between MediaLibraryModel (wraps the URL list under a `urls`
     * key) and CustomImageModel (returns the raw list). The
     * `$urls['urls'] ?? $urls` handles both cases without needing to
     * know which model called us.
     *
     * @param object $imageItem Optimised item, must respond to `getAllUrls()`.
     * @return void
     */
    protected function litespeedReset($imageItem)
    {
      // Suppress the notices on purge.
      if (! defined( 'LITESPEED_PURGE_SILENT' ))
      {
         define('LITESPEED_PURGE_SILENT', true);
      }

			$urls = $imageItem->getAllUrls();
      
      // The URLS part doesn't return in the customImageModel, use the whole array instead. 
      $urls = array_values($urls['urls'] ?? $urls);
			foreach($urls as $url)
			{
				 do_action('litespeed_purge_url', $url, false, true);
			}
  //    do_action('litespeed_media_reset', $post_id);
    }

}

// Self-boot at file-load time. Rationale: the constructor registers
// `shortpixel/image/optimised` and needs to be attached before that
// action ever fires. Same pattern as `Offloader` and
// `NextGenController`. Fine as long as this file is loaded early
// enough via the autoloader manifest.
cacheRemover::getInstance();
