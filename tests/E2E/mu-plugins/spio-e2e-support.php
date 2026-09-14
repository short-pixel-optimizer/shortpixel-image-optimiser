<?php
/**
 * Plugin Name: SPIO E2E — Test Support Endpoint
 * Description: Test-support mu-plugin for the browser E2E suite: a small REST API (namespace spio-e2e/v1) the Playwright side uses to reset state, seed a healthy install, upload fixtures, steer the mock API and inject "hostile" third-party scripts. Inert unless the SPIO_E2E constant is true.
 *
 * Every route requires the X-SPIO-E2E-TOKEN header to equal the
 * SPIO_E2E_TOKEN constant (both defined in docker-compose.e2e.yml). The
 * routes are reachable without pretty permalinks via
 *   POST /?rest_route=/spio-e2e/v1/<route>
 *
 * Routes:
 *   POST reset            wipe attachments + posts, SPIO tables, mock state,
 *                         hostile snippets; then re-apply the seed
 *   POST seed             apply the healthy-install baseline (see spio_e2e_apply_seed)
 *   POST settings         { key: value, … } → wpSPIO()->settings()
 *   GET  settings         the persisted spio_settings option
 *   POST option           { name, value } → update_option
 *   POST fixture          { name } → upload tests/fixtures/<name> as an attachment
 *   GET  attachment/<id>  SPIO view of one attachment (optimized?, meta, alt, file)
 *   POST queue/backdate   age every queue row past ShortQ's process_timeout
 *   POST mock             merge knobs into the mock API (see spio-e2e-mock-api.php)
 *   POST mock/reset       wipe mock knobs, counters, request log, stash
 *   GET  mock/requests    the mock's request log
 *   POST hostile          { snippets: ['window-url-overwrite', …] } → injected
 *                         inline at admin_print_scripts priority 0 on every
 *                         admin page until reset
 *
 * @package Shortpixel_Image_Optimiser
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SPIO_E2E' ) || ! SPIO_E2E ) {
	return;
}

define( 'SPIO_E2E_PLUGIN_DIR', WP_PLUGIN_DIR . '/shortpixel-image-optimiser' );
define( 'SPIO_E2E_HOSTILE_OPTION', 'spio_e2e_hostile_snippets' );

/**
 * The "healthy paying install" baseline — a port of
 * SPIO_IntegrationHelpers::spioSetUpBaseline(). Also called by WP-CLI during
 * provisioning (tests/E2E/provision/seed.php).
 *
 * The API key lives in its own spio_key option (ApiKeyModel); a 20-char key
 * with verifiedKey=true short-circuits remote validation. redirectedSettings
 * = 3 means "settings page already visited AND quick tour finished", so no
 * first-run redirect or tour overlay gets in the way of tests.
 * autoMediaLibrary is OFF in the E2E baseline (unlike the PHPUnit one) so
 * nothing gets optimized unless a test asks for it — deterministic flows.
 */
function spio_e2e_apply_seed() {
	update_option(
		'spio_key',
		array(
			'apiKey'      => str_repeat( 'a', 20 ),
			'verifiedKey' => true,
			'apiKeyTried' => '',
		)
	);

	if ( function_exists( 'wpSPIO' ) ) {
		$settings                     = \wpSPIO()->settings();
		$settings->quotaExceeded      = 0;
		$settings->backupImages       = 1;
		$settings->autoMediaLibrary   = 0;
		$settings->redirectedSettings = 3;
		$settings->enable_ai          = 1;

		// Values the settings specs mutate — pinned back to their defaults so
		// a save in one test can never masquerade as a save in the next
		// (bit us on pin62: compressionType=2 survived from an earlier test).
		$settings->compressionType   = 1;
		$settings->createWebp        = 0;
		$settings->createAvif        = 0;
		$settings->showCustomMedia   = 0;
		$settings->ai_use_exif       = 0;
		$settings->ai_use_post       = 0;
		$settings->aiPreserve        = 0;
		$settings->cloudflareZoneID  = '';
		$settings->cloudflareToken   = '';
		$settings->excludeSizes      = array();
		$settings->excludePatterns   = array();
	}
}

/** Inject enabled hostile snippets inline, before any plugin script. */
function spio_e2e_print_hostile_snippets() {
	$enabled = (array) get_option( SPIO_E2E_HOSTILE_OPTION, array() );
	foreach ( $enabled as $name ) {
		$file = __DIR__ . '/hostile-snippets/' . basename( (string) $name ) . '.js';
		if ( is_file( $file ) ) {
			echo "<script id=\"spio-e2e-hostile-" . esc_attr( $name ) . "\">\n" . file_get_contents( $file ) . "\n</script>\n";
		}
	}
}
add_action( 'admin_print_scripts', 'spio_e2e_print_hostile_snippets', 0 );
add_action( 'wp_print_scripts', 'spio_e2e_print_hostile_snippets', 0 );

/** Shared permission check: the token header must match the constant. */
function spio_e2e_rest_permission( $request ) {
	$token = defined( 'SPIO_E2E_TOKEN' ) ? SPIO_E2E_TOKEN : '';
	return '' !== $token && hash_equals( $token, (string) $request->get_header( 'X-SPIO-E2E-TOKEN' ) );
}

function spio_e2e_register_routes() {
	$ns  = 'spio-e2e/v1';
	$def = array( 'permission_callback' => 'spio_e2e_rest_permission' );

	register_rest_route( $ns, '/reset', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_reset' ) );
	register_rest_route( $ns, '/seed', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_seed' ) );
	register_rest_route( $ns, '/settings', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_settings' ) );
	register_rest_route( $ns, '/settings', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_get_settings' ) );
	register_rest_route( $ns, '/option', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_option' ) );
	register_rest_route( $ns, '/fixture', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_fixture' ) );
	register_rest_route( $ns, '/attachment/(?P<id>\d+)', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_attachment' ) );
	register_rest_route( $ns, '/queue/backdate', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_backdate' ) );
	register_rest_route( $ns, '/mock', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_mock' ) );
	register_rest_route( $ns, '/mock/reset', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_mock_reset' ) );
	register_rest_route( $ns, '/mock/requests', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_mock_requests' ) );
	register_rest_route( $ns, '/hostile', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_hostile' ) );
}
add_action( 'rest_api_init', 'spio_e2e_register_routes' );

// -----------------------------------------------------------------------
// Route handlers
// -----------------------------------------------------------------------

function spio_e2e_route_reset( WP_REST_Request $request ) {
	global $wpdb;

	// Content: every attachment (files included) and every post/page.
	$ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		wp_delete_attachment( $id, true );
	}
	$ids = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}

	// SPIO tables (queue + per-attachment meta + AI meta). Folders/custom
	// media meta are left alone until a wave needs them.
	foreach ( array( 'shortpixel_queue', 'shortpixel_postmeta', 'shortpixel_aipostmeta' ) as $table ) {
		$name = $wpdb->prefix . $table;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
			$wpdb->query( "DELETE FROM `$name`" );
		}
	}

	if ( class_exists( 'SPIO_E2E_MockApi' ) ) {
		SPIO_E2E_MockApi::resetAll();
	}
	delete_option( SPIO_E2E_HOSTILE_OPTION );
	delete_transient( 'spio_ai_jwt_token' );

	// Per-user preferences a test may have changed: hidden Media Library
	// columns (Screen Options → user OPTION "manageuploadcolumnshidden",
	// stored blog-prefixed, e.g. wp_manageuploadcolumnshidden) and SPIO's
	// simple/advanced settings mode. delete_user_option() handles the prefix.
	foreach ( get_users( array( 'fields' => 'ID' ) ) as $user_id ) {
		delete_user_option( $user_id, 'manageuploadcolumnshidden' );
		delete_user_option( $user_id, 'manageuploadcolumnshidden', true );
		delete_user_option( $user_id, 'shortpixel-settings-mode' );
		delete_user_option( $user_id, 'shortpixel-settings-mode', true );
	}

	// The JS processor's single-runner lock: a 2-minute 'bulk-secret'
	// transient set by whichever page last processed. If it survives from a
	// previous test (or run), CheckActive() in shortpixel-processor.js sees a
	// server key that doesn't match the new page's localStorage key, parks the
	// processor and only re-checks after 3 minutes — the queue silently stalls
	// (first flaky run, 2026-09-14). Every test starts lock-free; the auth
	// setup clears the localStorage half. InstallHelper does the same delete.
	delete_transient( 'bulk-secret' );

	spio_e2e_apply_seed();

	return rest_ensure_response( array( 'ok' => true ) );
}

function spio_e2e_route_seed( WP_REST_Request $request ) {
	spio_e2e_apply_seed();
	return rest_ensure_response( array( 'ok' => true ) );
}

function spio_e2e_route_settings( WP_REST_Request $request ) {
	$settings = \wpSPIO()->settings();
	$applied  = array();
	foreach ( (array) $request->get_json_params() as $key => $value ) {
		$settings->$key   = $value;
		$applied[ $key ] = $value;
	}
	return rest_ensure_response( array( 'ok' => true, 'applied' => $applied ) );
}

/** The persisted spio_settings option — server-side truth for save round-trips. */
function spio_e2e_route_get_settings( WP_REST_Request $request ) {
	return rest_ensure_response( (array) get_option( 'spio_settings', array() ) );
}

function spio_e2e_route_option( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	if ( empty( $params['name'] ) ) {
		return new WP_Error( 'spio_e2e_bad_request', 'name is required', array( 'status' => 400 ) );
	}
	update_option( (string) $params['name'], isset( $params['value'] ) ? $params['value'] : '' );
	return rest_ensure_response( array( 'ok' => true ) );
}

/**
 * Upload tests/fixtures/<name> as a real attachment — mirrors the PHPUnit
 * helper uploadFixture(): copy into uploads, insert the attachment post and
 * generate full metadata (real thumbnails, -scaled for big images).
 */
function spio_e2e_route_fixture( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	$name   = isset( $params['name'] ) ? basename( (string) $params['name'] ) : '';
	$source = SPIO_E2E_PLUGIN_DIR . '/tests/fixtures/' . $name;

	if ( '' === $name || ! is_file( $source ) ) {
		return new WP_Error( 'spio_e2e_no_fixture', 'Unknown fixture: ' . $name, array( 'status' => 404 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$upload = wp_upload_bits( $name, null, (string) file_get_contents( $source ) );
	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error( 'spio_e2e_upload_failed', $upload['error'], array( 'status' => 500 ) );
	}

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => $upload['type'],
			'post_title'     => pathinfo( $name, PATHINFO_FILENAME ),
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	if ( is_wp_error( $id ) || ! $id ) {
		return new WP_Error( 'spio_e2e_insert_failed', 'wp_insert_attachment failed', array( 'status' => 500 ) );
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	return rest_ensure_response( array( 'id' => (int) $id, 'url' => $upload['url'], 'file' => $upload['file'] ) );
}

function spio_e2e_route_attachment( WP_REST_Request $request ) {
	$id = (int) $request['id'];
	if ( 'attachment' !== get_post_type( $id ) ) {
		return new WP_Error( 'spio_e2e_not_found', 'No such attachment', array( 'status' => 404 ) );
	}

	$image = \wpSPIO()->filesystem()->getMediaImage( $id, false );

	return rest_ensure_response(
		array(
			'id'            => $id,
			'optimized'     => is_object( $image ) ? (bool) $image->isOptimized() : null,
			'attached_file' => get_post_meta( $id, '_wp_attached_file', true ),
			'alt'           => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'file'          => is_object( $image ) ? $image->getFullPath() : null,
		)
	);
}

/** UPDATE shortpixel_queue SET updated='2000-01-01' — bypasses ShortQ's 10s process_timeout (port of backdateQueueItems()). */
function spio_e2e_route_backdate( WP_REST_Request $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'shortpixel_queue';
	$rows  = $wpdb->query( "UPDATE `$table` SET updated = '2000-01-01 00:00:00'" );
	return rest_ensure_response( array( 'ok' => true, 'rows' => (int) $rows ) );
}

function spio_e2e_route_mock( WP_REST_Request $request ) {
	$knobs = array_merge(
		SPIO_E2E_MockApi::defaultKnobs(),
		(array) get_option( SPIO_E2E_MockApi::OPTION_KNOBS, array() ),
		(array) $request->get_json_params()
	);
	update_option( SPIO_E2E_MockApi::OPTION_KNOBS, $knobs, false );
	return rest_ensure_response( array( 'ok' => true, 'knobs' => $knobs ) );
}

function spio_e2e_route_mock_reset( WP_REST_Request $request ) {
	SPIO_E2E_MockApi::resetAll();
	return rest_ensure_response( array( 'ok' => true ) );
}

function spio_e2e_route_mock_requests( WP_REST_Request $request ) {
	return rest_ensure_response( (array) get_option( SPIO_E2E_MockApi::OPTION_REQUESTS, array() ) );
}

function spio_e2e_route_hostile( WP_REST_Request $request ) {
	$params   = (array) $request->get_json_params();
	$snippets = isset( $params['snippets'] ) ? array_map( 'basename', (array) $params['snippets'] ) : array();
	update_option( SPIO_E2E_HOSTILE_OPTION, array_values( $snippets ), false );
	return rest_ensure_response( array( 'ok' => true, 'snippets' => $snippets ) );
}
