<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Cloudflare edge-cache purge on optimisation and restore.
 *
 * When SPIO finishes optimising or begins restoring an image, the
 * Cloudflare edge still holds the pre-change bytes cached at PoPs
 * around the world. This class fires `POST /zones/{zone}/purge_cache`
 * with the full URL list (main + WebP + AVIF + original + every
 * thumbnail variant) so visitors see the new asset immediately.
 *
 * Wiring:
 *   - Hooks `shortpixel/image/optimised` AND
 *     `shortpixel/image/before_restore` in the constructor — so both
 *     directions of the pipeline invalidate the edge.
 *   - Self-boots at file-load time via `new CloudFlareAPI()` at the
 *     bottom of this file — same rationale as `cacheRemover` and
 *     `Offloader` (constructor registers actions, must attach before
 *     they fire), but WITHOUT a singleton wrapper. That means loading
 *     this file twice would double-register the hooks; the autoloader
 *     manifest is responsible for single-load semantics.
 *
 * Config resolution (in `setup()`):
 *   - Zone ID: `SHORTPIXEL_CFZONE` constant wins, otherwise the
 *     `cloudflareZoneID` setting.
 *   - API token: `SHORTPIXEL_CFTOKEN` constant wins, otherwise the
 *     `cloudflareToken` setting.
 *   - When both are non-empty, `config_ok = true` and the class is
 *     ready to purge. Missing either → the hook handler no-ops
 *     (nothing purged, no error logged).
 *
 * Runtime dependency: PHP cURL. When `curl_init` isn't available the
 * handler logs a warning and skips the purge — the rest of the plugin
 * continues normally.
 *
 * NOTE: leftover scaffolding remains in `start_cloudflare_cache_purge_process`
 * (unused `$prepare_request_info` / `$dispatch_purge_info` — flagged in
 * the deferred-bugs memo). The dead legacy `use_token = false` /
 * email+authkey branch in `addAuth()` was removed in 399b29e2.
 *
 * @package ShortPixel
 */
class CloudFlareAPI {
    /** @var string|null Cloudflare Zone ID resolved from constant or settings during setup(). */
    private $zone_id; // $_cloudflareZoneID
    /** @var string|null Cloudflare API token resolved from constant or settings during setup(). */
    private $token;

    /** @var bool Whether setup() has run. Guards lazy config so we don't re-read constants on every hook fire. */
    private $setup_done = false;
    /** @var bool True when both zone_id and token are non-empty; every hook handler short-circuits if false. */
    private $config_ok = false;


    /** @var bool Declared but never assigned or read — historical flag. Safe to remove. */
    private $cf_exists = true;

    /** @var string Cloudflare API v4 base for zone endpoints. */
    private $api_url = 'https://api.cloudflare.com/client/v4/zones/';

    /**
     * Register the optimise + restore hooks. Config resolution is
     * deferred to `setup()` on the first hook fire so we don't touch
     * settings during file load.
     */
    public function __construct()
    {
        add_action('shortpixel/image/optimised', array( $this, 'check_cloudflare' ), 10 );
				add_action('shortpixel/image/before_restore', array($this, 'check_cloudflare'), 10);
    }

    /**
     * Resolve Cloudflare credentials (zone id + API token). Constants
     * override settings so an operator can force config from
     * wp-config.php even if the settings screen is otherwise empty.
     *
     * `config_ok` is only set when BOTH credentials are non-empty —
     * partial config → skipped purge, not a soft-failure with an
     * error.
     *
     * Idempotent by way of `setup_done`; `check_cloudflare()` gates
     * the call so this only runs once per request.
     *
     * @return void
     */
    public function setup()
    {

        $this->zone_id =  (defined('SHORTPIXEL_CFZONE') ) ? SHORTPIXEL_CFZONE : \wpSPIO()->settings()->cloudflareZoneID;

        $this->token = (defined('SHORTPIXEL_CFTOKEN') ) ? SHORTPIXEL_CFTOKEN : \wpSPIO()->settings()->cloudflareToken;

        if (! empty($this->token) && ! empty($this->zone_id))
        {
          $this->config_ok = true;
        }

        $this->setup_done = true;
    }

    /**
     * Hook handler called from both `shortpixel/image/optimised` and
     * `shortpixel/image/before_restore`. Runs lazy setup, then
     * dispatches to the actual purge builder when config is complete
     * and cURL is available.
     *
     * Silent no-op when config is missing (expected on installs that
     * never entered Cloudflare credentials). Warn-only when cURL is
     * missing (unusual — most PHP builds ship with it).
     *
     * @param object $imageObj Optimised or about-to-be-restored item — must respond to `getURL()`, `getWebp()`, `getAvif()`, `get('type')`, `hasOriginal()`, `getOriginalFile()`, `get('thumbnails')`.
     * @return void
     */
    public function check_cloudflare($imageObj)
    {
      if (! $this->setup_done)
        $this->setup();

      if ($this->config_ok)
      {
        if (! function_exists('curl_init'))
        {
          Log::addWarn('Cloudflare Config OK, but no CURL to request');
        }
        else
          $this->start_cloudflare_cache_purge_process($imageObj);
      }

    }

    /**
     * Build the full URL list for the given image and fire a single
     * Cloudflare purge_cache request covering all variants.
     *
     * URL collection order (defines the order Cloudflare sees them):
     *   1. Main file URL.
     *   2. Main file's WebP (if present) and AVIF (if present).
     *   3. Media-library only (`type === 'media'`):
     *      a. Original (pre-scaled-big-image) file URL + its
     *         WebP/AVIF variants — only when `hasOriginal()` reports
     *         a stashed original.
     *      b. Every thumbnail's URL + WebP + AVIF, iterated in
     *         `get('thumbnails')` order.
     *
     * Custom-media items (`type === 'custom'`) skip step 3 entirely
     * — CustomImageModel doesn't have originals/thumbnails.
     *
     * Legacy scaffolding still in this method (called out in the
     * class docblock's `@todo` and the deferred-bugs memo):
     *   - `$prepare_request_info` — declared array, never populated,
     *     never sent.
     *   - `$dispatch_purge_info`  — encoded once from an empty
     *     array, never used (only referenced in a commented-out
     *     legacy request line).
     *   - The `if ( ! empty($image_paths) )` branch is effectively
     *     always taken because we always push at least `getURL()` —
     *     the else-branch is unreachable.
     *
     * @param object $imageItem Optimised / restore-target item — see check_cloudflare() for the required interface.
     * @return void
     */
    private function start_cloudflare_cache_purge_process($imageItem ) {

        // Fetch CloudFlare API credentials

            // Fetch all WordPress install possible thumbnail sizes ( this will not return the full size option )
            //$fetch_images_sizes   = get_intermediate_image_sizes();
            $purge_array  = array();
            $prepare_request_info = array();

						$fs = \wpSPIO()->filesystem();

						$image_paths[] = $imageItem->getURL();
						if ($imageItem->getWebp() !== false)
							 $image_paths[] = $fs->pathToUrl($imageItem->getWebp());

						if ($imageItem->getAvif() !== false)
 								 $image_paths[] = $fs->pathToUrl($imageItem->getAvif());

					  if ($imageItem->get('type') == 'media')
						{
								if ($imageItem->hasOriginal())
								{
									 $originalFile = $imageItem->getOriginalFile();
									 $image_paths[] = $originalFile->getURL();

									 if ($originalFile->getWebp() !== false)
		 								 $image_paths[] = $fs->pathToUrl($originalFile->getWebp());

		 							if ($originalFile->getAvif() !== false)
			 								 $image_paths[] = $fs->pathToUrl($originalFile->getAvif());
								}

								if (count($imageItem->get('thumbnails')) > 0)
								{
									 foreach($imageItem->get('thumbnails') as $thumbObj)
									 {
											 $image_paths[] = $thumbObj->getURL();

											 if ($thumbObj->getWebp() !== false)
												 $image_paths[] = $fs->pathToUrl($thumbObj->getWebp());

											if ($thumbObj->getAvif() !== false)
													 $image_paths[] = $fs->pathToUrl($thumbObj->getAvif());
									 }
								}
						}

            if ( ! empty( $image_paths ) ) {
                // Encode the data into JSON before send
                $dispatch_purge_info = function_exists('wp_json_encode') ? wp_json_encode( $prepare_request_info ) : json_encode( $prepare_request_info );

                $response = $this->delete_url_cache_request_action($image_paths);

                // Start the process of cache purge
            } else {
                // No use in running the process
            }
    }

    /**
     * Send a `POST /zones/{zone}/purge_cache` request for the given
     * URL list.
     *
     * Implements https://developers.cloudflare.com/api/operations/zone-purge — the "purge files by URL" variant.
     *
     * @param string[] $files URL list to purge (main + WebP + AVIF + original + thumbnails).
     * @return array|null Decoded JSON response, or null when doRequest bailed out (no cURL).
     */
    private function delete_url_cache_request_action( $files ) {
        $request_url = $this->api_url . $this->zone_id . '/purge_cache';
        $postfields = array('files' => $files);

        return $this->doRequest($request_url, $postfields);
    }

    /**
     * Attach Cloudflare Bearer auth header to an outgoing request.
     *
     * Adds `Authorization: Bearer <token>` — the only supported auth
     * mode. The legacy v1 email + auth-key branch was removed in
     * 399b29e2 (it referenced undeclared `$this->email` / `$this->authkey`
     * properties and was structurally unreachable anyway).
     *
     * @param array $headers Existing header map (`slug => "Header: value"` shape).
     * @return array Header map with the auth entry added.
     */
    private function addAuth($headers)
    {
       $headers['authorization'] = 'Authorization: Bearer ' . $this->token;
       return $headers;
    }


    /**
     * Low-level cURL POST wrapper used by `delete_url_cache_request_action()`.
     *
     * Behaviour:
     *   - Returns `false` immediately when cURL isn't available
     *     (redundant with the check in `check_cloudflare()` but kept
     *     as belt-and-braces).
     *   - Default `Content-Type: application/json` header is merged
     *     with per-call `$headers` (caller headers win via
     *     `wp_parse_args`), then filtered through `array_values` +
     *     `array_filter` so empty entries are dropped before hitting
     *     `CURLOPT_HTTPHEADER`.
     *   - Timeouts are aggressive: 5s connect, 10s total. Cloudflare's
     *     purge endpoint is usually sub-second so this is fine, but
     *     network hiccups will surface as null responses.
     *   - Uses a fixed Chrome/Windows User-Agent string (probably to
     *     dodge any UA-based filtering upstream).
     *   - Response is `json_decode(..., true)` so the caller sees an
     *     associative array on success.
     *   - Non-array response  → `Log::addWarn` ("not responding correctly").
     *   - `success = false`   → `Log::addWarn` with any `errors.message`.
     *   - Otherwise           → `Log::addInfo` "successfully requested clear cache".
     *
     * @param string $url        Fully-qualified API URL (zone id already interpolated).
     * @param array  $postfields Body to JSON-encode and POST.
     * @param array  $headers    Optional extra headers to merge with the defaults.
     * @return array|false Decoded JSON response, or false when cURL is missing.
     */
    private function doRequest($url, $postfields, $headers = array())
    {
      if(!function_exists('curl_init'))
      { return false; }

      $curl_connection = curl_init();

      $default_headers =
        array('content_type' => 'Content-Type: application/json');

      $default_headers = $this->addAuth($default_headers);

      $headers = wp_parse_args($headers, $default_headers);
      $headers = array_filter(array_values($headers));

      $postfields = wp_json_encode($postfields);

      curl_setopt( $curl_connection, CURLOPT_URL, $url );
      curl_setopt( $curl_connection, CURLOPT_CUSTOMREQUEST, "POST" );
      curl_setopt( $curl_connection, CURLOPT_POSTFIELDS, $postfields);
      curl_setopt( $curl_connection, CURLOPT_RETURNTRANSFER, true );
      curl_setopt( $curl_connection, CURLOPT_HTTPHEADER, $headers );
      curl_setopt( $curl_connection, CURLOPT_CONNECTTIMEOUT, 5);  // in seconds!
      curl_setopt( $curl_connection, CURLOPT_TIMEOUT, 10); // in seconds!
      curl_setopt( $curl_connection, CURLOPT_USERAGENT, '"User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.87 Safari/537.36"' );

      $request_response = curl_exec( $curl_connection );
      $result           = json_decode( $request_response, true );
      curl_close( $curl_connection );

      if ( ! is_array( $result ) ) {
          Log::addWarn( 'ShortPixel - CloudFlare: The CloudFlare API is not responding correctly', $result);
      } elseif ( isset( $result['success'] ) && isset( $result['errors'] ) && false === (bool) $result['success'] ) {
          Log::addWarn( 'ShortPixel - CloudFlare, Error messages: '
              . (isset($result['errors']['message']) ? $result['errors']['message'] : json_encode($result['errors'])) );
      } else {
          Log::addInfo('ShortPixel - CloudFlare successfully requested clear cache for: ', array($postfields));
      }

      return $result;
    }
}

// Self-boot at file-load time. The constructor registers
// `shortpixel/image/optimised` and `shortpixel/image/before_restore`,
// so it must run before those actions can fire. Unlike `cacheRemover`
// and `Offloader`, this file uses a bare `new` (no singleton), so a
// second `require` of this file would double-register both hooks.
// The autoloader manifest is responsible for single-load semantics.
// The `$c` variable is never referenced — the whole point is the
// constructor's side effect.
$c = new CloudFlareAPI();  // monitor hook.
