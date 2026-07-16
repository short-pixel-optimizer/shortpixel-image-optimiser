<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Shortpixel_Image_Optimiser
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/wp-shortpixel.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

/**
 * Silence ONLY PHP 8.5 deprecations that cannot be fixed while retaining
 * PHP 7.4 back-compat (per the plugin header). Everything else — real
 * warnings, deprecations tied to production bugs, test-fixture races —
 * stays visible so Bas can see the symptoms disappear when the
 * corresponding fix lands.
 *
 * Installed AFTER the WP test bootstrap so we sit on top of any handler
 * WordPress registers, and chain-delegate to it for everything else via
 * `$previous`. Zero effect on PHP 7.4 / 8.3 — none of these deprecations
 * fire there.
 *
 * What we silence and why (both are structurally unfixable in this repo):
 *
 *   - `Reflection[Method|Property]::setAccessible()` (deprecated 8.5,
 *     no-op since 8.1). Removing the calls would break the test suite on
 *     PHP 7.4 where they are still required. 150+ call sites; a global
 *     handler is cheaper than a mass rewrite.
 *
 *   - `SplObjectStorage::attach()` / `SplObjectStorage::contains()`
 *     (deprecated 8.5) — emitted from `vendor/sebastian/recursion-context/`,
 *     a PHPUnit transitive dependency. Vendor code; upstream must fix.
 */
$_spio_previous_error_handler = null;
$_spio_previous_error_handler = set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$_spio_previous_error_handler ) {
		static $silenced_deprecations = array(
			'ReflectionMethod::setAccessible()',
			'ReflectionProperty::setAccessible()',
			'SplObjectStorage::attach()',
			'SplObjectStorage::contains()',
		);
		if ( E_DEPRECATED === $errno ) {
			foreach ( $silenced_deprecations as $needle ) {
				if ( false !== strpos( $errstr, $needle ) ) {
					return true;
				}
			}
		}
		if ( $_spio_previous_error_handler ) {
			return ( $_spio_previous_error_handler )( $errno, $errstr, $errfile, $errline );
		}
		return false; // fall through to PHP's default handler.
	}
);
