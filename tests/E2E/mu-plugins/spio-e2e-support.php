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
 *   POST post             { content } or { image_id[, alt] } → published post
 *                         (the latter as a Gutenberg core/image block)
 *   GET  post/<id>        raw post_content + status
 *   POST key              { state: 'none' | 'verified'[, key] } → API-key state
 *   POST custom-folder    { name?, fixtures? } → uploads/<name>/ seeded with fixtures
 *                         (Custom Media target; removed again by reset)
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
	register_rest_route( $ns, '/post', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_post' ) );
	register_rest_route( $ns, '/post/(?P<id>\d+)', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_get_post' ) );
	register_rest_route( $ns, '/key', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_key' ) );
	register_rest_route( $ns, '/custom-folder', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_custom_folder' ) );
	register_rest_route( $ns, '/attachment/(?P<id>\d+)', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_attachment' ) );
	register_rest_route( $ns, '/queue/backdate', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_backdate' ) );
	register_rest_route( $ns, '/bulk-status', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_bulk_status' ) );
	register_rest_route( $ns, '/mock', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_mock' ) );
	register_rest_route( $ns, '/mock/reset', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_mock_reset' ) );
	register_rest_route( $ns, '/mock/requests', $def + array( 'methods' => 'GET', 'callback' => 'spio_e2e_route_mock_requests' ) );
	register_rest_route( $ns, '/hostile', $def + array( 'methods' => 'POST', 'callback' => 'spio_e2e_route_hostile' ) );
}
add_action( 'rest_api_init', 'spio_e2e_register_routes' );

// -----------------------------------------------------------------------
// Route handlers
// -----------------------------------------------------------------------

/**
 * Server-side bulk/queue state — the SAME startup data the bulk screen's JS
 * branches on (QueueController::getStartupData()). screen-bulk.js picks its
 * panel from it on every page load: is_preparing → selection, is_running →
 * process, is_finished + done → finished, in_queue > 0 → summary, otherwise
 * dashboard. A test that wants "the bulk is really over" must wait on this,
 * not on a page load: the reload after Stop can be served before finishBulk
 * has cleared the queues, and the screen then switches away from the
 * server-rendered dashboard (CI flake, both engines, 2026-09-16).
 *
 * `formatNumbers = false` keeps the counters raw instead of localized
 * strings ("1,000"), so they can be compared numerically.
 */
function spio_e2e_route_bulk_status( WP_REST_Request $request ) {
	if ( ! class_exists( '\ShortPixel\Controller\QueueController' ) ) {
		return new WP_Error( 'spio_e2e_no_spio', 'SPIO is not active', array( 'status' => 500 ) );
	}

	$class      = '\ShortPixel\Controller\QueueController';
	$controller = method_exists( $class, 'getInstance' ) ? $class::getInstance() : new $class();
	$data       = $controller->getStartupData( false );

	// json_encode/decode: hand back plain arrays whatever object graph the
	// controller returns.
	return rest_ensure_response( json_decode( wp_json_encode( $data ), true ) );
}

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

	// Bulk/queue STATUS lives outside the queue table (ShortQ status options
	// + SPIO's per-queue cache: preparing/running/finished/bulk_running).
	// Truncating the rows alone leaves a half-finished bulk "preparing", and
	// the next bulk page load then skips the dashboard (Wave 3 conflict
	// tests failed on `#start-optimize` because of exactly that). Reset the
	// queues through SPIO's own controller first.
	if ( class_exists( '\ShortPixel\Controller\QueueController' ) ) {
		\ShortPixel\Controller\QueueController::resetQueues();
	}

	// SPIO tables: queue, per-attachment meta, AI meta, and (Wave 3) the
	// custom-media folders + file meta, so a folder added by one test never
	// leaks into the next ("subfolder of an existing folder" refusals).
	foreach ( array( 'shortpixel_queue', 'shortpixel_postmeta', 'shortpixel_aipostmeta', 'shortpixel_folders', 'shortpixel_meta' ) as $table ) {
		$name = $wpdb->prefix . $table;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
			$wpdb->query( "DELETE FROM `$name`" );
		}
	}

	// Custom-media folders seeded under uploads (see the custom-folder route).
	$uploads = wp_get_upload_dir();
	foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . 'e2e-custom*' ) as $dir ) {
		if ( is_dir( $dir ) ) {
			spio_e2e_rmdir_recursive( $dir );
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
	// Bulk history (BulkController::$logName). The settings overview prints
	// "The last bulk processing ran on: <date>" from it, which would leak a
	// timestamp from any earlier bulk test into later tests and screenshots.
	delete_option( 'shortpixel-bulk-logs' );
	// Cached statistics survive the table truncation above: StatsModel keeps
	// its counters in the `currentStats` setting (the overview's "N Optimized
	// images and thumbnails" line) and StatsController caches the "Average
	// Optimization" dial for an hour in the `average_compression` transient.
	// Left alone, a bulk run in one test changes the overview layout of every
	// later test (caught by the visual determinism check, 2026-09-16).
	if ( class_exists( '\ShortPixel\Controller\StatsController' ) ) {
		\ShortPixel\Controller\StatsController::getInstance()->reset();
	}
	delete_transient( 'average_compression' );

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

/**
 * Create a published post. Either raw `content`, or `image_id` (+ optional
 * `alt`) to build a Gutenberg core/image block referencing that attachment
 * with the exact markup the block editor serializes — so SPIO's in-content
 * matcher (filename-anchored regex) and the editor both recognise it.
 */
function spio_e2e_route_post( WP_REST_Request $request ) {
	$params  = (array) $request->get_json_params();
	$content = isset( $params['content'] ) ? (string) $params['content'] : '';

	if ( '' === $content && ! empty( $params['image_id'] ) ) {
		$image_id = (int) $params['image_id'];
		$alt      = isset( $params['alt'] ) ? (string) $params['alt'] : '';
		$src      = wp_get_attachment_image_url( $image_id, 'large' );
		if ( ! $src ) {
			$src = wp_get_attachment_url( $image_id );
		}
		$content = sprintf(
			'<!-- wp:image {"id":%1$d,"sizeSlug":"large","linkDestination":"none"} -->' . "\n" .
			'<figure class="wp-block-image size-large"><img src="%2$s" alt="%3$s" class="wp-image-%1$d"/></figure>' . "\n" .
			'<!-- /wp:image -->',
			$image_id,
			esc_url( $src ),
			esc_attr( $alt )
		);
	}

	$post_id = wp_insert_post(
		array(
			'post_type'    => isset( $params['post_type'] ) ? (string) $params['post_type'] : 'post',
			'post_status'  => isset( $params['status'] ) ? (string) $params['status'] : 'publish',
			'post_title'   => isset( $params['title'] ) ? (string) $params['title'] : 'SPIO E2E post',
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'spio_e2e_post_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'id'       => (int) $post_id,
			'edit_url' => admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ),
			'url'      => get_permalink( $post_id ),
			'content'  => get_post( $post_id )->post_content,
		)
	);
}

/** Raw post_content (server-side truth for in-content replacement checks). */
function spio_e2e_route_get_post( WP_REST_Request $request ) {
	$post = get_post( (int) $request['id'] );
	if ( ! $post ) {
		return new WP_Error( 'spio_e2e_not_found', 'No such post', array( 'status' => 404 ) );
	}
	clean_post_cache( $post->ID );
	$post = get_post( $post->ID );
	return rest_ensure_response(
		array(
			'id'      => (int) $post->ID,
			'content' => $post->post_content,
			'status'  => $post->post_status,
			'title'   => $post->post_title,
		)
	);
}

/**
 * API-key state: { state: 'none' | 'verified' [, key] }.
 * 'none' = fresh install with no key (onboarding view); 'verified' = the seed's
 * option-based key (or the given one) with remote validation short-circuited.
 * redirectedSettings is set so no first-run redirect fires (0 would make
 * ApiKeyModel::checkRedirect() bounce every admin page to the settings once).
 */
function spio_e2e_route_key( WP_REST_Request $request ) {
	$params = (array) $request->get_json_params();
	$state  = isset( $params['state'] ) ? (string) $params['state'] : 'verified';

	if ( 'none' === $state ) {
		update_option( 'spio_key', array( 'apiKey' => '', 'verifiedKey' => false, 'apiKeyTried' => '' ) );
		if ( function_exists( 'wpSPIO' ) ) {
			\wpSPIO()->settings()->redirectedSettings = isset( $params['redirectedSettings'] ) ? (int) $params['redirectedSettings'] : 2;
		}
	} else {
		$key = isset( $params['key'] ) ? (string) $params['key'] : str_repeat( 'a', 20 );
		update_option( 'spio_key', array( 'apiKey' => $key, 'verifiedKey' => true, 'apiKeyTried' => '' ) );
		if ( function_exists( 'wpSPIO' ) ) {
			\wpSPIO()->settings()->redirectedSettings = 3;
		}
	}

	return rest_ensure_response( array( 'ok' => true, 'state' => $state ) );
}

/** rm -rf inside the uploads dir only (guarded). */
function spio_e2e_rmdir_recursive( $dir ) {
	$uploads = wp_get_upload_dir();
	if ( 0 !== strpos( realpath( $dir ), realpath( $uploads['basedir'] ) ) ) {
		return; // never delete outside uploads
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $dir );
}

/**
 * Create a Custom/Other Media folder to add through the UI:
 * { name?: 'e2e-custom', fixtures: ['fixture-small.jpg', …] } →
 * wp-content/uploads/<name>/ with copies of the fixtures. A NON-numeric
 * direct child of uploads passes every DirectoryOtherMediaModel::checkDirectory()
 * rule (the numeric year dirs are refused as "Media Library"), lives in the
 * Docker volume (never the host checkout — the bind-mounted plugin tree would
 * be ALLOWED and get its fixtures overwritten), and is www-data-writable.
 * Returns the picker's relpath for the folder ("wp-content/uploads/<name>/").
 */
function spio_e2e_route_custom_folder( WP_REST_Request $request ) {
	$params   = (array) $request->get_json_params();
	$name     = isset( $params['name'] ) ? sanitize_file_name( (string) $params['name'] ) : 'e2e-custom';
	$fixtures = isset( $params['fixtures'] ) ? (array) $params['fixtures'] : array( 'fixture-small.jpg' );
	if ( '' === $name || ! preg_match( '/^e2e-custom/', $name ) ) {
		return new WP_Error( 'spio_e2e_bad_request', 'name must start with e2e-custom', array( 'status' => 400 ) );
	}

	$uploads = wp_get_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . $name . '/';
	wp_mkdir_p( $dir );

	$copied = array();
	foreach ( $fixtures as $fixture ) {
		$source = SPIO_E2E_PLUGIN_DIR . '/tests/fixtures/' . basename( (string) $fixture );
		if ( is_file( $source ) && copy( $source, $dir . basename( $source ) ) ) {
			$copied[] = $dir . basename( $source );
		}
	}

	$relpath = str_replace( trailingslashit( ABSPATH ), '', $dir );
	return rest_ensure_response( array( 'path' => $dir, 'relpath' => $relpath, 'files' => $copied ) );
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
