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
 * Two integration points:
 *   1. `shortpixel/aidatamodel/paramlist` (via `checkParamList`) —
 *      inbound: inject the locale into the outgoing AI request params
 *      when WPML reports a locale for the attachment.
 *   2. `shortpixel/ai/succes` (via `successHandle`) — outbound: give
 *      site owners a filter (`shortpixel/wpml/airesult`) to mutate the
 *      AI response before SPIO stores it.
 *
 * Both hooks are gated behind WPML being active — the constructor
 * bails early with `return false;` (yes, a return value from a
 * constructor — harmless, PHP ignores it) if `plugin_active('wpml')`
 * is false, so on non-WPML installs the class is inert.
 *
 * Filter-name note: `checkParamlist` (lowercase L) is the filter name,
 * while the method is defined as `checkParamList` (uppercase L).
 * PHP method-name lookup is case-insensitive so this works, but the
 * casing inconsistency is worth being aware of if you're grepping.
 *
 * Self-boots at file-load time (no singleton wrapper).
 */
class WPML
{

    /**
     * Wire the two filters only when WPML is active — otherwise the
     * class is a no-op on this install.
     */
    public function __construct()
    {
        if (false === \wpSPIO()->env()->plugin_active('wpml'))
        {
            return false;
        }
        add_filter('shortpixel/aidatamodel/paramlist', [$this, 'checkParamlist'], 10, 2);
        add_filter('shortpixel/ai/succes', [$this, 'successHandle'], 10, 2);
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


}


new WPML();