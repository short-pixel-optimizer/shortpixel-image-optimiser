<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * WPML (multilingual) compatibility shim for the AI alt-text pipeline.
 *
 * WPML tags every attachment with a language locale. When SPIO
 * requests an AI-generated alt text for an image, the AI backend needs
 * that locale so the returned text is in the right language for the
 * post's WPML translation.
 *
 * Three integration points:
 *   1. `shortpixel/aidatamodel/paramlist` (via `checkParamList`) —
 *      inbound: inject the locale into the outgoing AI request params
 *      when WPML reports a locale for the attachment.
 *   2. `shortpixel/ai/success` (via `successHandle`) — outbound: give
 *      site owners a filter (`shortpixel/wpml/airesult`) to mutate the
 *      AI response before SPIO stores it.
 *   3. `shortpixel/image/file_filter_site_url` (via `checkHomeURL`) —
 *      URL→path resolution: swap the home_url base for site_url, since
 *      WPML's language-in-directories mode rewrites home_url.
 *
 * All hooks are gated behind WPML being active — the constructor
 * bails early with `return false;` (yes, a return value from a
 * constructor — harmless, PHP ignores it) if `plugin_active('wpml')`
 * is false, so on non-WPML installs the class is inert.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class WPML
{

    /**
     * Wire the filters only when WPML is active — otherwise the
     * class is a no-op on this install.
     */
    public function __construct()
    {
        if (false === \wpSPIO()->env()->plugin_active('wpml'))
        {
            return false;
        }
        add_filter('shortpixel/aidatamodel/paramlist', [$this, 'checkParamList'], 10, 2);
        add_filter('shortpixel/ai/success', [$this, 'successHandle'], 10, 2);
        add_filter('shortpixel/image/file_filter_site_url',[$this, 'checkHomeURL']);
    }


    /**
     * Inject the attachment's WPML locale into the AI request params
     * so the returned alt-text is in the right language.
     *
     * The `$languages['locale']` field is defensively checked for
     * both null and false — WPML returns those on partial setup
     * (multilingual mode disabled per-post, or "all languages"
     * selection).
     *
     * Also exposes `shortpixel/wpml/paramlist` for site-side overrides
     * of the final params object.
     *
     * @param array $data    AI request params being built.
     * @param int   $item_id Attachment ID.
     * @return array Params with `languages` set when WPML has a locale for this item.
     */
    public function checkParamList($data, $item_id)
    {
        $languages = apply_filters('wpml_post_language_details', null, $item_id);

        if (is_array($languages) && isset($languages['locale']))
		{
            // This can happen if WPML is not fully configured.
            if (false === is_null($languages['locale']) && false !== $languages['locale'])
            {
			    $data['languages'] = $languages['locale'];
            }
		}

        $data = apply_filters('shortpixel/wpml/paramlist', $data);
        return $data;

    }

    /**
     * Passthrough for the AI success response — exposes
     * `shortpixel/wpml/airesult` so site owners can mutate the AI's
     * response before SPIO stores it (e.g., manual translation
     * overrides).
     *
     * @param array  $data  AI response payload.
     * @param object $qItem Queue item context for the filter.
     * @return array Filtered response payload.
     */
    public function successHandle($data, $qItem)
    {
        $data = apply_filters('shortpixel/wpml/airesult', $data, $qItem);
        return $data;
    }

    /**
     * Base URL used by FileModel::UrlToPath() when resolving an image URL
     * to a local path (filter `shortpixel/image/file_filter_site_url`).
     *
     * WPML's "language in directories" mode rewrites home_url() to include
     * the language segment (e.g. /de/), which breaks the URL→path
     * replacement. site_url() is not translated, so use that instead. The
     * scheme is stripped the same way as the FileModel default.
     *
     * @param string $home_url The scheme-less home_url-based default.
     * @return string Scheme-less site_url-based replacement.
     */
    public function checkHomeURL($home_url)
    {
            $site_url = str_replace('http:', '', site_url('', 'http'));
            return $site_url;
    }


}


new WPML();