<?php
/**
 * Integration tests: MediaPress hook touchpoints (plan 17).
 *
 * This is a hook-level suite. The MediaPress plugin does NOT need to be
 * installed. Tests fire the MediaPress upload hook directly.
 *
 * Hook wiring verified in shortpixel-plugin.php (AdminController::initHooks):
 *   line 376: add_filter('mpp_generate_metadata',
 *                         [$admin, 'handleImageUploadHook'], 10, 2)
 *             — gated on $this->env()->is_autoprocess (line 364)
 *
 * MediaPress uses the WordPress attachment system internally: `mpp_generate_metadata`
 * is a filter parallel to `wp_generate_attachment_metadata` — it carries the same
 * ($meta, $attachment_id) signature and SPIO's handler delegates directly to
 * handleImageUploadHook(), so a real WP attachment id is all that is needed here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminController;
use ShortPixel\Controller\QueueController;

class MediaPressTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Ensure the mpp_generate_metadata filter hook is wired for the current
	 * test. In the harness autoprocess may already be on (set by baseline);
	 * the hook may have been registered by initHooks() on 'init'. If not,
	 * wire it explicitly.
	 */
	private function ensureMppHookWired(): void {
		$admin = AdminController::getInstance();
		if ( ! has_filter( 'mpp_generate_metadata', array( $admin, 'handleImageUploadHook' ) ) ) {
			add_filter( 'mpp_generate_metadata', array( $admin, 'handleImageUploadHook' ), 10, 2 );
		}
		\wpSPIO()->env()->is_autoprocess = true;
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	/**
	 * Firing the `mpp_generate_metadata` filter on a real WP attachment (as
	 * MediaPress does after generating gallery image metadata) must cause SPIO
	 * to add the image to the optimize queue when autoprocess is ON.
	 *
	 * Manual-plan row: 17.1 — mpp_generate_metadata enqueues gallery image.
	 *
	 * @return void
	 */
	public function test_mpp_generate_metadata_hook_enqueues_gallery_image() {
		$this->ensureMppHookWired();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		// uploadFixture calls wp_generate_attachment_metadata which already
		// triggers handleImageUploadHook. Purge the queue to get a clean slate
		// so we can observe only the mpp hook's effect.
		$this->purgeQueueTable();
		$this->api->reset();

		// Simulate MediaPress firing its metadata filter.
		$meta = wp_get_attachment_metadata( $id );
		apply_filters( 'mpp_generate_metadata', $meta, $id );

		// The item should now be in the queue. Drive the queue.
		$this->runQueueUntilEmpty();

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty(
			$reducerCalls,
			'mpp_generate_metadata must cause SPIO to enqueue the image and call the optimizer API when autoprocess is ON.'
		);
	}

	/**
	 * An image uploaded via the MediaPress `mpp_generate_metadata` hook must
	 * be fully optimized end-to-end: the queue runs, the API is called, and
	 * the image model reports isOptimized() === true after the queue drains.
	 *
	 * Manual-plan row: 17.2 — MediaPress image optimizes end-to-end.
	 *
	 * @return void
	 */
	public function test_mediapress_image_optimizes_end_to_end() {
		$this->ensureMppHookWired();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->api->reset();

		// Simulate MediaPress generating metadata after gallery image creation.
		$meta = wp_get_attachment_metadata( $id );
		apply_filters( 'mpp_generate_metadata', $meta, $id );

		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'An image uploaded via mpp_generate_metadata must be fully optimized after the queue drains.'
		);
	}
}
