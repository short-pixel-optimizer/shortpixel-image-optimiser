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
	/**
	 * Breakdance's activation is NOT idempotent, and we re-run activation
	 * on every suite run against a database that persists between runs.
	 *
	 * Breakdance\Setup\init_db_tables issues
	 * `ALTER TABLE {prefix}breakdance_icons CHANGE COLUMN id id SERIAL`.
	 * In MySQL, SERIAL expands to
	 * `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE`, and that UNIQUE
	 * adds a NEW index each time it is applied — MySQL auto-names them
	 * id_2, id_3, ... So the table gained one index per local test run
	 * until it hit MySQL's hard limit, after which every run printed
	 * "Too many keys specified; max 64 keys allowed" twice (once for the
	 * ALTER, once for the follow-up ADD FULLTEXT(name), which can then
	 * never be created).
	 *
	 * CI never saw this because its database is new every run; only
	 * long-lived local databases accumulate. Dropping the table first
	 * makes the activation deterministic — Breakdance recreates it and
	 * re-imports its icon CSV, which is cheap and self-healing.
	 */
	$GLOBALS['wpdb']->query( 'DROP TABLE IF EXISTS `' . $GLOBALS['wpdb']->prefix . 'breakdance_icons`' ); // phpcs:ignore WordPress.DB

	foreach ( wp_get_active_and_valid_plugins() as $_spio_partner_file ) {
		do_action( 'activate_' . plugin_basename( $_spio_partner_file ) );
	}
	unset( $_spio_partner_file );

	/**
	 * Force WP Offload Media's two custom tables into existence up front.
	 *
	 * as3cf creates them lazily on first use, guarded by a per-class static
	 * ("checked_table_exists") that is set BEFORE the install runs. In the
	 * test process something marks the files table as already checked, so
	 * the lazy installer never fires and every Media_Library_Item
	 * construction emits "Table 'wptests_as3cf_files' doesn't exist" — 67
	 * wpdb errors per compat run, each with a full backtrace and an HTML
	 * error div. The tests still passed, because a missing file row is
	 * indistinguishable from "not offloaded", which is why the noise
	 * survived unnoticed.
	 *
	 * init_cache() clears that static, so the following get_table_name()
	 * calls really do install. Done here, before the first test
	 * transaction, so the implicit COMMIT of CREATE TABLE cannot leak into
	 * (or be rolled back with) a test.
	 */
	if ( class_exists( '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item' ) ) {
		\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item::init_cache();
		\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item::get_table_name();
	}
	if ( class_exists( '\DeliciousBrains\WP_Offload_Media\Items\File' ) ) {
		\DeliciousBrains\WP_Offload_Media\Items\File::init_cache();
		\DeliciousBrains\WP_Offload_Media\Items\File::get_table_name();
	}

	// NOTE: the partner-plugin _doing_it_wrong() suppression lives in
	// tests/bootstrap.php, registered BEFORE the WP test bootstrap runs —
	// those notices fire while the partner plugins load, which is long
	// before this file's code executes.
}

require_once __DIR__ . '/Helpers/MockShortPixelApi.php';
require_once __DIR__ . '/Helpers/SPIO_IntegrationHelpers.php';
require_once __DIR__ . '/Helpers/SPIO_IntegrationTestCase.php';
require_once __DIR__ . '/Helpers/SPIO_AjaxTestCase.php';
