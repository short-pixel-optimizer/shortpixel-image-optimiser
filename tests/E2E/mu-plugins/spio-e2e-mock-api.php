<?php
/**
 * Plugin Name: SPIO E2E — Mock ShortPixel API
 * Description: Test-support mu-plugin for the browser E2E suite. Intercepts every outbound request to *.shortpixel.com at the WP HTTP layer so the REAL plugin pipeline runs while no traffic leaves the container. Inert unless the SPIO_E2E constant is true.
 *
 * Port of tests/Integration/Helpers/MockShortPixelApi.php (the PHPUnit
 * mock) for a LIVE WordPress. Two things differ from the PHPUnit version:
 *
 *   1. State is persisted, not in-memory. Every browser request is a fresh
 *      PHP process, so the /f/<token> download stash lives on disk (uploads/
 *      spio-e2e-mock/), while the test-controllable knobs, per-URL round
 *      counters and the request log live in options.
 *   2. The optimized-bytes fixtures are resolved from the plugin's mounted
 *      location (tests/fixtures/optimized/ inside the plugin directory).
 *
 * Response shapes match REAL captured API traffic (see the PHPUnit mock's
 * header for the gotchas reproduced on purpose: HTTP 200 always, both
 * LosslessSize spellings, TimeStamp capital S, "NA"/"NC" sentinels).
 *
 * Knobs (set via the support endpoint POST /spio-e2e/v1/mock, stored in the
 * spio_e2e_mock_knobs option):
 *   forceStatusCode  int|null  reducer answers this Status->Code for every URL
 *   malformedBody    string    reducer answers this raw body verbatim
 *   wpErrorMessage   string    reducer + api-status answer a WP_Error
 *   waitingRounds    int       reducer rounds of CODE_WAITING before success
 *   aiAddStatus      int|null  add-url.php answers { status: N } (3 = over quota)
 *   aiWaitingRounds  int       get-url.php rounds of { status: 1 } first
 *   aiFields         array     overrides for the get-url.php success payload
 *
 * @package Shortpixel_Image_Optimiser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SPIO_E2E' ) || ! SPIO_E2E ) {
	return;
}

class SPIO_E2E_MockApi {

	const CODE_SUCCESS        = 2;
	const CODE_WAITING        = 1;
	const CODE_INVALID_URL    = -102;
	const CODE_UNREACHABLE    = -106;
	const CODE_INVALID_KEY    = -401;
	const CODE_QUOTA_EXCEEDED = -403;

	const OPTION_KNOBS    = 'spio_e2e_mock_knobs';
	const OPTION_STATE    = 'spio_e2e_mock_state';
	const OPTION_REQUESTS = 'spio_e2e_mock_requests';
	const REQUEST_LOG_CAP = 100;

	/** @var array Test-controllable knobs (see file header). */
	private $knobs;

	/** @var array Per-URL / per-AI-id counters, persisted between requests. */
	private $state;

	public static function register() {
		$mock = new self();
		add_filter( 'pre_http_request', array( $mock, 'intercept' ), 10, 3 );
	}

	/** Defaults for the knobs option. */
	public static function defaultKnobs() {
		return array(
			'forceStatusCode' => null,
			'malformedBody'   => null,
			'wpErrorMessage'  => null,
			'waitingRounds'   => 0,
			'aiAddStatus'     => null,
			'aiWaitingRounds' => 0,
			'aiFields'        => array(),
			// api-status.php (key validation / quota): null = healthy paying
			// account; -401 = invalid key; -403 = quota exceeded (APICallsMade
			// is then reported at the quota ceiling, like the real API).
			'apiStatusCode'   => null,
			// free-sign-up-plugin (new-account onboarding): 'success' | 'existing' | 'error'.
			'signupStatus'    => 'success',
		);
	}

	/** Wipe knobs, counters, request log and the on-disk stash. */
	public static function resetAll() {
		delete_option( self::OPTION_KNOBS );
		delete_option( self::OPTION_STATE );
		delete_option( self::OPTION_REQUESTS );

		$dir = self::stashDir();
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '*' ) as $file ) {
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
			}
		}
	}

	/** Absolute path of the on-disk /f/<token> stash (created on demand). */
	public static function stashDir() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'spio-e2e-mock/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	// -------------------------------------------------------------------
	// Interception
	// -------------------------------------------------------------------

	/**
	 * pre_http_request callback. Non-shortpixel hosts pass through untouched
	 * (WordPress' own update/version checks etc. simply reach the internet,
	 * or fail soft in an offline CI runner).
	 *
	 * @param false|array $preempt
	 * @param array       $args
	 * @param string      $url
	 * @return false|array|WP_Error
	 */
	public function intercept( $preempt, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || false === strpos( $host, 'shortpixel.com' ) ) {
			return $preempt;
		}

		$this->knobs = array_merge( self::defaultKnobs(), (array) get_option( self::OPTION_KNOBS, array() ) );
		$this->state = array_merge(
			array( 'rounds' => array(), 'aiRounds' => array(), 'aiRequestedFields' => array(), 'aiNextId' => 5000 ),
			(array) get_option( self::OPTION_STATE, array() )
		);

		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$request = $this->decodeParams( $args );
		$this->logRequest( $url, $path, $request );

		try {
			if ( false !== strpos( $path, 'reducer' ) ) {
				if ( null !== $this->knobs['wpErrorMessage'] ) {
					return new WP_Error( 'http_request_failed', $this->knobs['wpErrorMessage'] );
				}
				if ( null !== $this->knobs['malformedBody'] ) {
					return $this->httpResponse( $this->knobs['malformedBody'], $args );
				}
				return $this->handleReducer( $args, $request );
			}

			if ( 0 === strpos( $path, '/f/' ) ) {
				return $this->handleDownload( $path, $args );
			}

			if ( false !== strpos( $path, 'add-url.php' ) ) {
				return $this->handleAiAdd( $args, $request );
			}
			if ( false !== strpos( $path, 'get-url.php' ) ) {
				return $this->handleAiGet( $args, $request );
			}

			if ( false !== strpos( $path, 'api-status.php' ) ) {
				if ( null !== $this->knobs['wpErrorMessage'] ) {
					return new WP_Error( 'http_request_failed', $this->knobs['wpErrorMessage'] );
				}
				return $this->handleApiStatus( $args );
			}

			// Onboarding "create a new account" (SettingsViewController::action_request_new_key
			// → POST https://shortpixel.com/free-sign-up-plugin). Knob signupStatus:
			// 'success' (default) → a fresh 20-char key, 'existing' → email already
			// in use, anything else → the unexpected-body error branch.
			if ( false !== strpos( $path, 'free-sign-up-plugin' ) ) {
				$status = isset( $this->knobs['signupStatus'] ) ? (string) $this->knobs['signupStatus'] : 'success';
				if ( 'success' === $status ) {
					return $this->httpResponse( wp_json_encode( array( 'Status' => 'success', 'Details' => str_repeat( 'n', 20 ) ) ), $args );
				}
				if ( 'existing' === $status ) {
					return $this->httpResponse( wp_json_encode( array( 'Status' => 'existing' ) ), $args );
				}
				return $this->httpResponse( wp_json_encode( array( 'Status' => 'error', 'Details' => 'Forced by E2E test' ) ), $args );
			}

			// Anything else on *.shortpixel.com (notices, heartbeat…): benign
			// empty-JSON 200 so callers fail soft.
			return $this->httpResponse( '{}', $args );
		} finally {
			update_option( self::OPTION_STATE, $this->state, false );
		}
	}

	/** Append to the capped request log (url + path + decoded params, no raw args). */
	private function logRequest( $url, $path, $request ) {
		$log   = (array) get_option( self::OPTION_REQUESTS, array() );
		$log[] = array(
			'time'    => gmdate( 'c' ),
			'url'     => $url,
			'path'    => $path,
			'request' => $request,
		);
		if ( count( $log ) > self::REQUEST_LOG_CAP ) {
			$log = array_slice( $log, -self::REQUEST_LOG_CAP );
		}
		update_option( self::OPTION_REQUESTS, $log, false );
	}

	/** Healthy paying account with optimization + AI credits — or the forced failure code from the apiStatusCode knob. */
	private function handleApiStatus( array $args ) {
		$forced = $this->knobs['apiStatusCode'];
		if ( null !== $forced && (int) $forced !== self::CODE_SUCCESS ) {
			$messages = array(
				self::CODE_INVALID_KEY    => 'Invalid API key',
				self::CODE_QUOTA_EXCEEDED => 'Quota exceeded',
			);
			$body = array(
				'Status'                 => array(
					'Code'    => (int) $forced,
					'Message' => isset( $messages[ (int) $forced ] ) ? $messages[ (int) $forced ] : 'Forced by E2E test',
				),
				'Unlimited'              => 'false',
				'PlanType'               => 'Monthly',
				'DateSubscription'       => gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ),
				'DomainCheck'            => 'Accessible',
				'APICallsMade'           => ( self::CODE_QUOTA_EXCEEDED === (int) $forced ) ? 10000 : 100,
				'APICallsQuota'          => 10000,
				'APICallsMadeOneTime'    => 0,
				'APICallsQuotaOneTime'   => 0,
				'CaptionsCallsMade'      => 5,
				'CaptionsCallsQuota'     => 1000,
				'CaptionsCallsRemaining' => 995,
			);
			return $this->httpResponse( wp_json_encode( $body ), $args );
		}

		$body = array(
			'Status'                 => array( 'Code' => 2, 'Message' => 'Success' ),
			'Unlimited'              => 'false',
			'PlanType'               => 'Monthly',
			'DateSubscription'       => gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS ),
			'DomainCheck'            => 'Accessible',
			'APICallsMade'           => 100,
			'APICallsQuota'          => 10000,
			'APICallsMadeOneTime'    => 0,
			'APICallsQuotaOneTime'   => 0,
			'CaptionsCallsMade'      => 5,
			'CaptionsCallsQuota'     => 1000,
			'CaptionsCallsRemaining' => 995,
		);
		return $this->httpResponse( wp_json_encode( $body ), $args );
	}

	// -------------------------------------------------------------------
	// Reducer endpoint
	// -------------------------------------------------------------------

	private function handleReducer( array $args, $request ) {
		$request = is_array( $request ) ? $request : array();
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

	private function buildUrlEntry( $sourceUrl, $index, array $request ) {
		if ( null !== $this->knobs['forceStatusCode'] ) {
			// Real-API-like messages: the plugin shows Status->Message verbatim.
			$messages = array(
				self::CODE_INVALID_URL    => 'Invalid URL',
				self::CODE_UNREACHABLE    => 'URL inaccessible (forced by E2E test)',
				self::CODE_INVALID_KEY    => 'Invalid API key',
				self::CODE_QUOTA_EXCEEDED => 'Quota exceeded',
			);
			$code = (int) $this->knobs['forceStatusCode'];
			return array(
				'Status'      => array( 'Code' => $code, 'Message' => isset( $messages[ $code ] ) ? $messages[ $code ] : 'Forced by E2E test' ),
				'OriginalURL' => $sourceUrl,
			);
		}

		$key = md5( $sourceUrl );
		$this->state['rounds'][ $key ] = isset( $this->state['rounds'][ $key ] ) ? $this->state['rounds'][ $key ] + 1 : 1;
		if ( $this->state['rounds'][ $key ] <= (int) $this->knobs['waitingRounds'] ) {
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

		$convertto = isset( $request['convertto'] ) ? (string) $request['convertto'] : '';
		$paramlist = isset( $request['paramlist'] ) ? array_values( (array) $request['paramlist'] ) : array();
		if ( isset( $paramlist[ $index ] ) ) {
			$param = (array) $paramlist[ $index ];
			if ( isset( $param['convertto'] ) ) {
				$convertto = (string) $param['convertto'];
			}
		}
		$wantWebp = false !== strpos( $convertto, 'webp' );
		$wantAvif = false !== strpos( $convertto, 'avif' );

		$originalSize = (int) filesize( $localPath );

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
			'LoselessSize'       => $losslessSize,
			'LossySize'          => strlen( $lossy ),
			'WebPLosslessSize'   => 'NA',
			'WebPLoselessSize'   => 'NA',
			'WebPLossySize'      => 'NA',
			'AVIFLosslessSize'   => 'NA',
			'AVIFLossySize'      => 'NA',
			'TimeStamp'          => gmdate( 'Y-m-d H:i:s' ),
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
	// AI endpoints (add-url.php / get-url.php)
	// -------------------------------------------------------------------

	private function handleAiAdd( array $args, $request ) {
		if ( null !== $this->knobs['aiAddStatus'] ) {
			return $this->httpResponse(
				wp_json_encode( array( 'status' => (int) $this->knobs['aiAddStatus'], 'error' => 'Forced AI status by E2E test' ) ),
				$args
			);
		}

		$id = (int) $this->state['aiNextId'];
		$this->state['aiNextId'] = $id + 1;

		if ( is_array( $request ) ) {
			$this->state['aiRequestedFields'][ $id ] = array_values( array_intersect(
				array( 'alt', 'caption', 'image_description', 'title', 'file' ),
				array_keys( $request )
			) );
		}

		return $this->httpResponse( wp_json_encode( array( 'id' => $id, 'jwt' => 'mock-ai-jwt-token' ) ), $args );
	}

	private function handleAiGet( array $args, $request ) {
		$id = ( is_array( $request ) && isset( $request['id'] ) ) ? (int) $request['id'] : 0;

		$this->state['aiRounds'][ $id ] = isset( $this->state['aiRounds'][ $id ] ) ? $this->state['aiRounds'][ $id ] + 1 : 1;
		if ( $this->state['aiRounds'][ $id ] <= (int) $this->knobs['aiWaitingRounds'] ) {
			return $this->httpResponse( wp_json_encode( array( 'status' => 1 ) ), $args );
		}

		$defaults = array(
			'alt'               => 'a mock ai alt text',
			'caption'           => 'a mock ai caption',
			'image_description' => 'a mock ai description',
			'title'             => 'a mock ai title',
		);
		if ( isset( $this->state['aiRequestedFields'][ $id ] ) ) {
			$defaults = array_intersect_key( $defaults, array_flip( (array) $this->state['aiRequestedFields'][ $id ] ) );
		}
		$fields = array_merge( $defaults, array( 'relevance' => '9' ), (array) $this->knobs['aiFields'] );

		return $this->httpResponse( wp_json_encode( array_merge( array( 'status' => 2 ), $fields ) ), $args );
	}

	// -------------------------------------------------------------------
	// Download endpoint (/f/<token>.<ext>) — disk-backed stash
	// -------------------------------------------------------------------

	private function handleDownload( $path, array $args ) {
		$token = basename( $path );
		$file  = self::stashDir() . $token;

		if ( ! is_file( $file ) ) {
			return $this->httpResponse( 'mock: unknown file token ' . $token, $args, 404 );
		}

		$bytes = (string) file_get_contents( $file );

		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $bytes );
			$response             = $this->httpResponse( '', $args );
			$response['filename'] = $args['filename'];
			return $response;
		}

		return $this->httpResponse( $bytes, $args );
	}

	private function stashFile( $token, $bytes, $sourcePath, $ext = null ) {
		if ( null === $ext ) {
			$ext = strtolower( pathinfo( $sourcePath, PATHINFO_EXTENSION ) );
		}
		$name = $token . '.' . $ext;
		file_put_contents( self::stashDir() . $name, $bytes );
		return 'http://api.shortpixel.com/f/' . $name;
	}

	// -------------------------------------------------------------------
	// Byte generation (identical to the PHPUnit mock)
	// -------------------------------------------------------------------

	private function variantBytes( $localPath, $variant, $resize = null ) {
		if ( null !== $resize ) {
			$resized = $this->resizedVariantBytes( $localPath, $variant, $resize );
			if ( null !== $resized ) {
				return $resized;
			}
		}

		$basename  = preg_replace( '/-\d+(\.[a-z0-9]+)$/i', '$1', basename( $localPath ) );
		$fixtures  = WP_PLUGIN_DIR . '/shortpixel-image-optimiser/tests/fixtures/optimized/';
		$candidate = ( 'lossy' === $variant ) ? $fixtures . $basename : $fixtures . $basename . '.' . $variant;

		if ( file_exists( $candidate ) ) {
			return (string) file_get_contents( $candidate );
		}

		$img = $this->gdLoad( $localPath );
		if ( false === $img ) {
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
				// fall through
			case 'avif':
				if ( 'avif' === $variant && function_exists( 'imageavif' ) ) {
					imageavif( $img, null, 40 );
					break;
				}
				// fall through
			case 'lossy':
			default:
				imagejpeg( $img, null, 40 );
				break;
		}
		$bytes = ob_get_clean();

		return ( '' === $bytes ) ? null : $bytes;
	}

	private function resizedVariantBytes( $localPath, $variant, array $resize ) {
		$img = $this->gdLoad( $localPath );
		if ( false === $img ) {
			return null;
		}

		$width  = imagesx( $img );
		$height = imagesy( $img );
		$ratios = array( $resize['w'] / $width, $resize['h'] / $height );
		$scale  = ( 1 === $resize['mode'] ) ? max( $ratios ) : min( $ratios );

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

	// -------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------

	private function urlToPath( $url ) {
		$url = strtok( $url, '?' );

		$uploads = wp_get_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			return $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
		}

		$contentUrl = content_url();
		if ( 0 === strpos( $url, $contentUrl ) ) {
			return WP_CONTENT_DIR . substr( $url, strlen( $contentUrl ) );
		}

		return null;
	}

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
}

SPIO_E2E_MockApi::register();
