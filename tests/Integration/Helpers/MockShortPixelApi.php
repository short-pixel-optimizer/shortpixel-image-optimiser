<?php
/**
 * HTTP-layer mock of the ShortPixel Reducer API for integration tests.
 *
 * Intercepts every outbound request to *.shortpixel.com via the
 * `pre_http_request` filter so the REAL plugin pipeline (request building,
 * response parsing, file download, meta writing) runs unmodified while no
 * traffic leaves the test machine.
 *
 * Response shapes match REAL captured API traffic (2026-07-16), not the
 * public docs. Gotchas reproduced on purpose:
 *   - HTTP status is always 200; the result lives in Status->Code.
 *   - Both `LosslessSize` AND the misspelled `LoselessSize` are sent.
 *   - `TimeStamp` (capital S), unlike the docs' `Timestamp`.
 *   - Variant URLs use the sentinels "NA" (not available) / "NC"
 *     (not compressable) — never null/missing.
 *
 * Optimized bytes served for downloads:
 *   - main files: `tests/fixtures/optimized/<basename>` when present
 *     (real ShortPixel output committed by Pedro), else GD re-compression
 *     of the source at low quality;
 *   - WebP/AVIF variants: `optimized/<basename>.webp|.avif` when present,
 *     else GD-generated fallback bytes.
 *
 * @package Shortpixel_Image_Optimiser
 */
class MockShortPixelApi {

	/** Status codes as sent by the real API. */
	const CODE_SUCCESS   = 2;
	const CODE_WAITING   = 1;
	const CODE_INVALID_URL    = -102;
	const CODE_UNREACHABLE    = -106;
	const CODE_INVALID_KEY    = -401;
	const CODE_QUOTA_EXCEEDED = -403;

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,array> token => [ 'bytes' => string ] map for /f/ download URLs. */
	private $files = array();

	/** @var array Log of intercepted requests, for assertions: [ [ 'url' => .., 'args' => .., 'request' => decoded params|null ] ]. */
	public $requests = array();

	/** @var int|null When set, the next reducer call returns this Status->Code for every URL instead of success. */
	public $forceStatusCode = null;

	/** @var string|null When set, reducer calls return this RAW body verbatim (malformed-response testing). */
	public $malformedBody = null;

	/** @var string|null When set, reducer calls return a WP_Error('http_request_failed', <this message>) — transport-level failure. */
	public $wpErrorMessage = null;

	/** @var int Number of times a URL should answer CODE_WAITING before turning CODE_SUCCESS (simulates queued processing). */
	public $waitingRounds = 0;

	/** @var array<string,int> Per-URL count of reducer rounds already answered. */
	private $rounds = array();

	/** @var string Temp dir for generated variant bytes. */
	private $tmpDir;

	public static function getInstance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function register(): self {
		$mock = self::getInstance();
		add_filter( 'pre_http_request', array( $mock, 'intercept' ), 10, 3 );
		return $mock;
	}

	public static function unregister(): void {
		if ( null !== self::$instance ) {
			remove_filter( 'pre_http_request', array( self::$instance, 'intercept' ), 10 );
		}
		self::$instance = null;
	}

	private function __construct() {
		$this->tmpDir = trailingslashit( get_temp_dir() ) . 'spio-mock-api/';
		if ( ! is_dir( $this->tmpDir ) ) {
			wp_mkdir_p( $this->tmpDir );
		}
	}

	// -------------------------------------------------------------------
	// Interception
	// -------------------------------------------------------------------

	/**
	 * pre_http_request callback. Returns false (real request proceeds) for
	 * any non-shortpixel host — the WP test install itself never calls out,
	 * so in practice everything is either handled here or a test bug.
	 *
	 * @param false|array $preempt Filter passthrough.
	 * @param array       $args    HTTP request args.
	 * @param string      $url     Request URL.
	 * @return false|array
	 */
	public function intercept( $preempt, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || false === strpos( $host, 'shortpixel.com' ) ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$this->requests[] = array(
			'url'     => $url,
			'args'    => $args,
			'request' => $this->decodeParams( $args ),
		);

		if ( false !== strpos( $path, 'reducer' ) ) {
			if ( null !== $this->wpErrorMessage ) {
				return new WP_Error( 'http_request_failed', $this->wpErrorMessage );
			}
			if ( null !== $this->malformedBody ) {
				return $this->httpResponse( $this->malformedBody, $args );
			}
			return $this->handleReducer( $args );
		}

		if ( 0 === strpos( $path, '/f/' ) ) {
			return $this->handleDownload( $path, $args );
		}

		// Anything else on *.shortpixel.com (notices endpoint, heartbeat,
		// settings validate…): benign empty-JSON 200 so callers fail soft.
		return $this->httpResponse( '{}', $args );
	}

	// -------------------------------------------------------------------
	// Reducer endpoint
	// -------------------------------------------------------------------

	/**
	 * Answer a reducer.php call.
	 *
	 * Response envelope (matches ApiController::handleOptimizeResponse and
	 * RequestManager::parseResponse): a JSON object with numeric keys — one
	 * per-image object per urllist entry, IN URLLIST ORDER (the plugin maps
	 * entries to size names by index against returndatalist.sizes) — plus
	 * the request's `returndatalist` echoed back verbatim. parseResponse()
	 * json_decodes without assoc and casts to array, so numeric-string keys
	 * become int keys (PHP 7.2+) and entries stay stdClass.
	 *
	 * @return array WP HTTP response array with the JSON body.
	 */
	private function handleReducer( array $args ) {
		$request = $this->decodeParams( $args );
		$urllist = isset( $request['urllist'] ) ? array_values( (array) $request['urllist'] ) : array();

		$response = array();
		foreach ( $urllist as $index => $sourceUrl ) {
			$response[ $index ] = $this->buildUrlEntry( $sourceUrl, $index, $request );
		}

		if ( isset( $request['returndatalist'] ) ) {
			$response['returndatalist'] = $request['returndatalist'];
		}

		return $this->httpResponse( wp_json_encode( $response ), $args );
	}

	/**
	 * Build the per-URL response object for one urllist entry.
	 *
	 * @param string $sourceUrl URL as submitted by the plugin (may carry ?ver= query).
	 * @param int    $index     Position in urllist (paramlist is parallel).
	 * @param array  $request   Full decoded request params.
	 * @return array
	 */
	private function buildUrlEntry( $sourceUrl, $index, array $request ) {
		if ( null !== $this->forceStatusCode ) {
			return array(
				'Status'      => array( 'Code' => $this->forceStatusCode, 'Message' => 'Forced by test' ),
				'OriginalURL' => $sourceUrl,
			);
		}

		$key = md5( $sourceUrl );
		$this->rounds[ $key ] = isset( $this->rounds[ $key ] ) ? $this->rounds[ $key ] + 1 : 1;
		if ( $this->rounds[ $key ] <= $this->waitingRounds ) {
			return array(
				'Status'      => array( 'Code' => self::CODE_WAITING, 'Message' => 'Image waiting to be processed' ),
				'OriginalURL' => $sourceUrl,
			);
		}

		$localPath = $this->urlToPath( $sourceUrl );
		if ( null === $localPath || ! file_exists( $localPath ) ) {
			return array(
				'Status'      => array( 'Code' => self::CODE_UNREACHABLE, 'Message' => 'Could not download source URL (mock: no local file for ' . $sourceUrl . ')' ),
				'OriginalURL' => $sourceUrl,
			);
		}

		// Which variants did this URL ask for? Global `convertto` or the
		// per-URL paramlist entry (paramlist wins — mirrors real API).
		// paramlist may arrive as a sequential array or keyed by size name;
		// either way it is parallel to urllist, so match by position.
		$convertto = isset( $request['convertto'] ) ? (string) $request['convertto'] : '';
		$paramlist = isset( $request['paramlist'] ) ? array_values( (array) $request['paramlist'] ) : array();
		if ( isset( $paramlist[ $index ] ) ) {
			$param = (array) $paramlist[ $index ];
			if ( isset( $param['convertto'] ) ) {
				$convertto = (string) $param['convertto'];
			}
		}
		$wantWebp = false !== strpos( $convertto, '+webp' );
		$wantAvif = false !== strpos( $convertto, '+avif' );

		$originalSize = (int) filesize( $localPath );

		// Resize parameters (resize-on-upload): the real API scales images
		// exceeding the target box. resize = 1 (outer/contain) | 3 (inner/cover).
		$resize = null;
		if ( ! empty( $request['resize'] ) ) {
			$resize = array(
				'mode' => (int) $request['resize'],
				'w'    => (int) $request['resize_width'],
				'h'    => (int) $request['resize_height'],
			);
		}

		$lossy    = $this->variantBytes( $localPath, 'lossy', $resize );
		$lossyUrl = $this->stashFile( $key . '-lossy', $lossy, $localPath );

		// Lossless jobs (request lossy=0 — e.g. ApiConverter forces lossless
		// for heic/tiff/bmp conversion) read LosslessURL/LosslessSize; keeping
		// LosslessURL = OriginalURL there makes the plugin mark the item
		// UNCHANGED. Serve real bytes + size for those. Lossy jobs keep the
		// captured real-API shape (LosslessURL echoes OriginalURL).
		$isLossless = isset( $request['lossy'] ) && 0 === (int) $request['lossy'];
		if ( $isLossless ) {
			$losslessUrl  = $this->stashFile( $key . '-lossless', $lossy, $localPath );
			$losslessSize = strlen( $lossy );
		} else {
			$losslessUrl  = $sourceUrl;
			$losslessSize = $originalSize;
		}

		$entry = array(
			'Status'             => array( 'Code' => self::CODE_SUCCESS, 'Message' => 'Success' ),
			'OriginalURL'        => $sourceUrl,
			'LosslessURL'        => $losslessUrl,
			'LossyURL'           => $lossyUrl,
			'WebPLosslessURL'    => 'NA',
			'WebPLossyURL'       => 'NA',
			'AVIFLosslessURL'    => 'NA',
			'AVIFLossyURL'       => 'NA',
			'OriginalSize'       => $originalSize,
			'LosslessSize'       => $losslessSize,
			'LoselessSize'       => $losslessSize, // real API sends BOTH spellings
			'LossySize'          => strlen( $lossy ),
			'WebPLosslessSize'   => 'NA',
			'WebPLoselessSize'   => 'NA',
			'WebPLossySize'      => 'NA',
			'AVIFLosslessSize'   => 'NA',
			'AVIFLossySize'      => 'NA',
			'TimeStamp'          => gmdate( 'Y-m-d H:i:s' ), // capital S — matches real API
			'PercentImprovement' => $originalSize > 0 ? round( ( 1 - strlen( $lossy ) / $originalSize ) * 100, 2 ) : 0,
		);

		if ( $wantWebp ) {
			$webp = $this->variantBytes( $localPath, 'webp', $resize );
			if ( null !== $webp ) {
				$entry['WebPLossyURL']  = $this->stashFile( $key . '-lossy-webp', $webp, $localPath, 'webp' );
				$entry['WebPLossySize'] = strlen( $webp );
			}
		}
		if ( $wantAvif ) {
			$avif = $this->variantBytes( $localPath, 'avif', $resize );
			if ( null !== $avif ) {
				$entry['AVIFLossyURL']  = $this->stashFile( $key . '-lossy-avif', $avif, $localPath, 'avif' );
				$entry['AVIFLossySize'] = strlen( $avif );
			}
		}

		return $entry;
	}

	// -------------------------------------------------------------------
	// Download endpoint (/f/<token>[.ext])
	// -------------------------------------------------------------------

	/** @return array WP HTTP response serving the stashed bytes (stream-aware). */
	private function handleDownload( $path, array $args ) {
		$token = basename( $path );

		if ( ! isset( $this->files[ $token ] ) ) {
			return $this->httpResponse( 'mock: unknown file token ' . $token, $args, 404 );
		}

		$bytes = $this->files[ $token ]['bytes'];

		// download_url() and DownloadHelper use stream mode: WP expects the
		// body to be written to $args['filename'] and left off the response.
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $bytes );
			$response             = $this->httpResponse( '', $args );
			$response['filename'] = $args['filename'];
			return $response;
		}

		return $this->httpResponse( $bytes, $args );
	}

	// -------------------------------------------------------------------
	// Byte generation
	// -------------------------------------------------------------------

	/**
	 * Produce "optimized" bytes for a local source file.
	 *
	 * Preference order:
	 *   1. Real ShortPixel output from tests/fixtures/optimized/ — matched
	 *      by basename, using the double-extension convention for variants
	 *      (fixture-large.jpg, fixture-large.jpg.webp, fixture-large.jpg.avif).
	 *   2. GD re-compression at low quality (guaranteed smaller for photos).
	 *   3. For formats GD can't write (avif on older PHP): recompressed JPEG
	 *      bytes — the plugin writes whatever it downloads, it does not
	 *      re-validate the container format.
	 *
	 * When $resize is set and the source exceeds the target box, the bytes
	 * are GD-scaled (skipping the pre-optimized fixture, which has the
	 * original dimensions) — mirrors the API's server-side resize.
	 *
	 * @param string     $localPath Source file on disk.
	 * @param string     $variant   'lossy' | 'webp' | 'avif'.
	 * @param array|null $resize    { mode: 1(outer/contain)|3(inner/cover), w, h } or null.
	 * @return string|null Bytes, or null when no variant could be produced.
	 */
	private function variantBytes( $localPath, $variant, $resize = null ) {
		if ( null !== $resize ) {
			$resized = $this->resizedVariantBytes( $localPath, $variant, $resize );
			if ( null !== $resized ) {
				return $resized;
			}
		}

		// Leftover files from earlier runs/tests make wp_unique_filename
		// append -2, -3… to uploads; strip that suffix so the optimized-
		// fixture lookup still matches (fixture-large-2.heic → fixture-large.heic).
		$basename  = preg_replace( '/-\d+(\.[a-z0-9]+)$/i', '$1', basename( $localPath ) );
		$fixtures  = dirname( __DIR__, 2 ) . '/fixtures/optimized/';
		$candidate = ( 'lossy' === $variant ) ? $fixtures . $basename : $fixtures . $basename . '.' . $variant;

		if ( file_exists( $candidate ) ) {
			return (string) file_get_contents( $candidate );
		}

		$img = $this->gdLoad( $localPath );
		if ( false === $img ) {
			// Non-raster source (pdf…): "optimize" by truncation-safe copy.
			$bytes = (string) file_get_contents( $localPath );
			return ( 'lossy' === $variant ) ? $bytes : null;
		}

		ob_start();
		switch ( $variant ) {
			case 'webp':
				if ( function_exists( 'imagewebp' ) ) {
					imagewebp( $img, null, 40 );
					break;
				}
				// no webp support: fall through to jpeg bytes
			case 'avif':
				if ( 'avif' === $variant && function_exists( 'imageavif' ) ) {
					imageavif( $img, null, 40 );
					break;
				}
				// no avif support: fall through to jpeg bytes
			case 'lossy':
			default:
				imagejpeg( $img, null, 40 );
				break;
		}
		$bytes = ob_get_clean();
		if ( PHP_VERSION_ID < 80000 ) {
			// No-op from 8.0 and deprecated in 8.5; still frees memory on 7.4.
			imagedestroy( $img );
		}

		return ( '' === $bytes ) ? null : $bytes;
	}

	/**
	 * GD-scaled variant bytes for a source exceeding the resize box.
	 *
	 * @return string|null Bytes, or null when the source fits the box already
	 *                     (caller falls back to the normal variant path).
	 */
	private function resizedVariantBytes( $localPath, $variant, array $resize ) {
		$img = $this->gdLoad( $localPath );
		if ( false === $img ) {
			return null;
		}

		$width  = imagesx( $img );
		$height = imagesy( $img );
		$ratios = array( $resize['w'] / $width, $resize['h'] / $height );
		// outer (1) = contain: both sides fit the box; inner (3) = cover.
		$scale = ( 3 === $resize['mode'] ) ? max( $ratios ) : min( $ratios );

		if ( $scale >= 1 ) {
			return null;
		}

		$img = imagescale( $img, (int) round( $width * $scale ), (int) round( $height * $scale ) );

		ob_start();
		if ( 'webp' === $variant && function_exists( 'imagewebp' ) ) {
			imagewebp( $img, null, 40 );
		} elseif ( 'avif' === $variant && function_exists( 'imageavif' ) ) {
			imageavif( $img, null, 40 );
		} else {
			imagejpeg( $img, null, 40 );
		}
		$bytes = ob_get_clean();

		return ( '' === $bytes ) ? null : $bytes;
	}

	/** @return \GdImage|resource|false */
	private function gdLoad( $path ) {
		$info = @getimagesize( $path );
		if ( false === $info ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				return @imagecreatefromjpeg( $path );
			case IMAGETYPE_PNG:
				return @imagecreatefrompng( $path );
			case IMAGETYPE_GIF:
				return @imagecreatefromgif( $path );
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
			case IMAGETYPE_BMP:
				return function_exists( 'imagecreatefrombmp' ) ? @imagecreatefrombmp( $path ) : false;
			default:
				return false;
		}
	}

	/**
	 * Store variant bytes under a token and return its public download URL.
	 *
	 * @return string http://api.shortpixel.com/f/<token>.<ext>
	 */
	private function stashFile( $token, $bytes, $sourcePath, $ext = null ) {
		if ( null === $ext ) {
			$ext = strtolower( pathinfo( $sourcePath, PATHINFO_EXTENSION ) );
		}
		$name                 = $token . '.' . $ext;
		$this->files[ $name ] = array( 'bytes' => $bytes );
		return 'http://api.shortpixel.com/f/' . $name;
	}

	// -------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------

	/** Map an uploads or wp-content URL (possibly carrying ?ver=) to a local file path. */
	private function urlToPath( $url ) {
		$url = strtok( $url, '?' );

		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			return $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		}

		// Custom media ("Other Media") files live outside uploads.
		$contentUrl = content_url();
		if ( 0 === strpos( $url, $contentUrl ) ) {
			return WP_CONTENT_DIR . substr( $url, strlen( $contentUrl ) );
		}

		return null;
	}

	/**
	 * Decode the request payload. The reducer request body is RAW JSON
	 * (RequestManager::getRequest(): body = json_encode($requestBody)).
	 * AdminNoticesController uses the older `body[params] = json` envelope —
	 * handled too so stray notice fetches don't break assertions.
	 */
	private function decodeParams( array $args ) {
		if ( isset( $args['body'] ) && is_string( $args['body'] ) ) {
			$decoded = json_decode( $args['body'], true );
			return is_array( $decoded ) ? $decoded : null;
		}
		if ( isset( $args['body']['params'] ) && is_string( $args['body']['params'] ) ) {
			$decoded = json_decode( $args['body']['params'], true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}

	/** Build a WP-HTTP-shaped response array. */
	private function httpResponse( $body, array $args, $code = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => ( 200 === $code ) ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Reset per-test mutable state while keeping the filter registered. */
	public function reset(): void {
		$this->requests        = array();
		$this->files           = array();
		$this->rounds          = array();
		$this->forceStatusCode = null;
		$this->malformedBody   = null;
		$this->wpErrorMessage  = null;
		$this->waitingRounds   = 0;
	}
}
