<?php
/**
 * WP-CLI seed step for the E2E install: `wp eval-file` runs this with
 * WordPress (and the mu-plugins) fully loaded, so it simply calls the same
 * baseline the REST support endpoint applies between tests.
 *
 * SettingsModel persists on shutdown (register_shutdown_function + the
 * shutdown action), which WP-CLI honours at the end of eval-file.
 *
 * @package Shortpixel_Image_Optimiser
 */

if ( ! function_exists( 'spio_e2e_apply_seed' ) ) {
	WP_CLI::error( 'spio-e2e-support.php mu-plugin is not loaded — is SPIO_E2E defined in wp-config.php?' );
}

spio_e2e_apply_seed();
WP_CLI::success( 'SPIO E2E seed applied (verified key, backups on, auto-optimize off, quick tour done).' );
