<?php
/**
 * Cross-plugin compatibility: WP Offload Media Lite (Wave 3).
 *
 * Runs with the REAL amazon-s3-and-cloudfront plugin active
 * (bin/test.sh --compat downloads + activates it). The plugin is
 * deliberately left UNCONFIGURED (no provider/bucket) — the point is
 * to verify SPIO's dispatcher and hook wiring boot correctly and that
 * the optimize pipeline is unaffected when media stays local.
 * Covers class/external/offload/Offloader.php + wp-offload-media.php:
 *
 *   - as3cf and SPIO load side by side; as3cf_init fired.
 *   - The Offloader dispatcher picked the `wp-offload` handler (no
 *     virtual-filesystem offloader claimed the slot first).
 *   - The wpOffload shim registered its as3cf-side interception hooks.
 *   - Optimizing a normal (non-offloaded) attachment works end to end
 *     and the files stay local.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\External\Offload\Offloader;

class CompatOffloadMediaTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'Amazon_S3_And_CloudFront' ) ) {
			$this->markTestSkipped( 'WP Offload Media is not loaded — run via bin/test.sh --compat.' );
		}
		parent::set_up();
	}

	// -------------------------------------------------------------------
	// Coexistence + dispatcher
	// -------------------------------------------------------------------

	public function test_offload_media_loads_alongside_spio() {
		$this->assertTrue( class_exists( 'Amazon_S3_And_CloudFront' ), 'The as3cf main class must exist.' );
		$this->assertGreaterThan( 0, did_action( 'as3cf_init' ), 'as3cf_init must have fired — the wpOffload boot depends on it.' );
		$this->assertTrue( \wpSPIO()->env()->plugin_active( 's3-offload' ), "SPIO's environment must detect WP Offload Media as active." );
	}

	public function test_offloader_dispatcher_selected_wp_offload() {
		$offloader = Offloader::getInstance();
		$this->assertSame( 'wp-offload', $offloader->getOffloadName(), 'The dispatcher must have booted the wp-offload handler on as3cf_init.' );

		// Unconfigured Lite install: isActive() must answer with a bool
		// (the wp-offload branch), never the null "not implemented" case.
		$this->assertIsBool( $offloader->isActive( 'wp-offload' ) );
	}

	public function test_wpoffload_as3cf_hooks_are_wired() {
		// Registered in wpOffload::init() — only reachable when the as3cf
		// compatibility checks passed (Media_Library_Item + item handlers).
		$this->assertNotFalse( has_filter( 'as3cf_attachment_file_paths' ), 'WebP/AVIF path injection into as3cf uploads must be wired.' );
		$this->assertNotFalse( has_filter( 'as3cf_pre_update_attachment_metadata' ), 'Metadata-update interception must be wired.' );
		$this->assertNotFalse( has_filter( 'as3cf_pre_handle_item_upload' ), 'Initial-upload interception must be wired.' );
		$this->assertNotFalse( has_filter( 'shortpixel/image/urltopath' ), 'Offloaded-URL resolution must be wired.' );
		$this->assertNotFalse( has_action( 'shortpixel/image/optimised' ), 'The post-optimize offload trigger must be wired.' );
	}

	// -------------------------------------------------------------------
	// Pipeline unaffected while media is local
	// -------------------------------------------------------------------

	public function test_optimize_works_with_unconfigured_offloader() {
		\wpSPIO()->settings()->processThumbnails = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue( $image->isOptimized(), 'Optimization must succeed while as3cf is present but unconfigured.' );

		// Nothing got offloaded: the main file must still exist locally.
		$this->assertFileExists( get_attached_file( $id ), 'The optimized file must remain on the local filesystem.' );
	}
}
