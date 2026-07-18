<?php
/**
 * PHPUnit bootstrap for the INTEGRATION suite.
 *
 * Reuses the unit-test bootstrap (WP test lib, plugin load, settings
 * redirect guard, custom-table creation, PHP 8.5 deprecation handler)
 * and adds the integration-only pieces on top: the ShortPixel API mock
 * and the shared integration base class.
 *
 * The integration suite runs the REAL optimize/restore pipeline against
 * the WordPress test install; only outbound HTTP to api.shortpixel.com
 * is intercepted (pre_http_request) — see MockShortPixelApi.
 *
 * @package Shortpixel_Image_Optimiser
 */

require dirname( __DIR__ ) . '/bootstrap.php';

/**
 * Compat runs: fire each partner plugin's activation hook once so their
 * installers create the tables they need (NextGen's ngg_gallery,
 * WooCommerce's wc_* tables). The WP test install never goes through a
 * real activation, and DDL auto-commits in MySQL, so this runs here —
 * before the first test transaction — and survives per-test rollbacks.
 */
if ( '1' === getenv( 'SPIO_PARTNER_PLUGINS' ) ) {
	foreach ( wp_get_active_and_valid_plugins() as $_spio_partner_file ) {
		do_action( 'activate_' . plugin_basename( $_spio_partner_file ) );
	}
	unset( $_spio_partner_file );
}

require_once __DIR__ . '/Helpers/MockShortPixelApi.php';
require_once __DIR__ . '/Helpers/SPIO_IntegrationTestCase.php';
