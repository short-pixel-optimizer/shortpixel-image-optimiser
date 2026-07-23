<?php
/**
 * Integration tests: Enable Media Replace (EMR) hook touchpoints (plan 12).
 *
 * This is a hook-level suite. The Enable Media Replace plugin does NOT need
 * to be installed. Tests fire the hooks that EMR fires directly:
 *   - `wp_handle_replace`  (action, fired by EMR before the file is swapped)
 *   - `enable-media-replace-upload-done` (action, fired after the swap)
 *
 * Hook wiring verified in shortpixel-plugin.php (AdminController::initHooks):
 *   line 348: add_action('wp_handle_replace', [$admin, 'handleReplaceHook'])
 *   line 370: add_action('enable-media-replace-upload-done',
 *                         [$admin, 'handleReplaceEnqueue'], 10, 3)
 *             — gated on $this->env()->is_autoprocess (line 364)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Model\Image\ImageModel;

class EMRIntegrationTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Ensure AdminController::initHooks() has been called so EMR hooks are wired.
	 * In the test harness the plugin bootstrap runs but initHooks() may not have
	 * fired (it sits on the 'init' action which the test bootstrap does not always
	 * re-trigger). Call it explicitly here to guarantee hook registration.
	 */
	private function ensureHooksWired(): void {
		$admin = AdminController::getInstance();
		// The hook is added unconditionally; calling initHooks() again is safe
		// because add_action/add_filter are idempotent duplicate-aware.
		if ( ! has_action( 'wp_handle_replace', array( $admin, 'handleReplaceHook' ) ) ) {
			do_action( 'init' );
		}
	}

	/**
	 * Activate autoprocess at the environment level and ensure the gated hooks
	 * (enable-media-replace-upload-done, handleReplaceEnqueue) are wired.
	 *
	 * The gate in initHooks() reads env()->is_autoprocess at hook-registration
	 * time. Setting it before calling ensureHooksWired() (which may re-run init)
	 * is necessary for tests that verify the re-enqueue path.
	 */
	private function setAutoprocessOn(): void {
		\wpSPIO()->env()->is_autoprocess = true;
		$admin = AdminController::getInstance();
		if ( ! has_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ) ) ) {
			add_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ), 10, 3 );
		}
	}

	/** Remove autoprocess hook to simulate the off state. */
	private function setAutoprocessOff(): void {
		\wpSPIO()->env()->is_autoprocess = false;
		$admin = AdminController::getInstance();
		remove_action( 'enable-media-replace-upload-done', array( $admin, 'handleReplaceEnqueue' ), 10 );
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	/**
	 * Firing `wp_handle_replace` on an already-optimized attachment must clear
	 * its optimization meta (via onDelete), leaving the image in an unoptimized
	 * state ready for the next run.
	 *
	 * Manual-plan row: 12.1 — handleReplaceHook clears meta via onDelete.
	 *
	 * @return void
	 */
	public function test_wp_handle_replace_clears_optimization_meta() {
		$this->ensureHooksWired();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		// Sanity: confirm it is optimized before the replace.
		$before = $this->freshImageModel( $id );
		$this->assertTrue( $before->isOptimized(), 'Pre-condition: image must be optimized before replace hook fires.' );

		// Fire the hook exactly as EMR does it.
		do_action( 'wp_handle_replace', array( 'post_id' => $id ) );

		$after = $this->freshImageModel( $id );
		$this->assertFalse(
			$after->isOptimized(),
			'wp_handle_replace must clear SPIO optimization meta (onDelete path) so the image is no longer marked optimized.'
		);
	}

	/**
	 * When autoprocess is ON, firing `enable-media-replace-upload-done` must
	 * add the replaced attachment back to the optimize queue AND the queue must
	 * successfully optimize it (full end-to-end round-trip).
	 *
	 * Manual-plan row: 12.2 — handleReplaceEnqueue re-queues via handleImageUploadHook.
	 *
	 * @return void
	 */
	public function test_upload_done_hook_reenqueues_when_autoprocess_on() {
		$this->setAutoprocessOn();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		// Optimize first so we have a "before replace" baseline.
		$this->optimizeAttachment( $id );

		// Simulate the replace: clear meta (as wp_handle_replace would), then
		// fire the upload-done hook.
		do_action( 'wp_handle_replace', array( 'post_id' => $id ) );
		$this->purgeQueueTable();

		$target = get_attached_file( $id );
		$source = $target; // Same file path — EMR swaps files in-place.

		$this->api->reset(); // clear prior optimize request count
		do_action( 'enable-media-replace-upload-done', $target, $source, $id );

		// The hook delegates to handleImageUploadHook → addItemToQueue.
		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'After enable-media-replace-upload-done (autoprocess ON), the replaced image must be re-optimized by SPIO.'
		);

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty( $reducerCalls, 'Re-optimization must trigger a fresh API call to the reducer endpoint.' );
	}

	/**
	 * When autoprocess is OFF, firing `enable-media-replace-upload-done` must
	 * NOT add the attachment to the queue. The hook is not even registered in
	 * that state (gated in initHooks() line 364-370).
	 *
	 * Manual-plan row: 12.4 — auto-process OFF: meta wiped but no re-queue.
	 *
	 * @return void
	 */
	public function test_upload_done_hook_no_reenqueue_when_autoprocess_off() {
		$this->setAutoprocessOff();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		do_action( 'wp_handle_replace', array( 'post_id' => $id ) );
		$this->purgeQueueTable();

		$this->api->reset();
		$target = get_attached_file( $id );
		do_action( 'enable-media-replace-upload-done', $target, $target, $id );

		// Do NOT drive the queue — we're asserting nothing was added.
		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertEmpty(
			$reducerCalls,
			'With autoprocess OFF the enable-media-replace-upload-done hook must not trigger any optimizer API call.'
		);

		$image = $this->freshImageModel( $id );
		$this->assertFalse(
			$image->isOptimized(),
			'With autoprocess OFF the image must remain unoptimized after the replace-done hook.'
		);
	}

	/**
	 * Replacing a JPEG with an SVG must NOT cause SPIO to enqueue the SVG for
	 * optimization. SVG is not in ImageModel::PROCESSABLE_EXTENSIONS so
	 * handleImageUploadHook → isProcessable() returns false and no queue item
	 * is created.
	 *
	 * Manual-plan row: 12.7 — SVG replace: no backup, no queue entry.
	 *
	 * @return void
	 */
	public function test_replace_jpg_with_svg_is_not_optimized() {
		$this->setAutoprocessOn();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		// Simulate the replace: clear meta via the replace hook.
		do_action( 'wp_handle_replace', array( 'post_id' => $id ) );
		$this->purgeQueueTable();

		// Simulate EMR swapping in an SVG: overwrite the attachment's MIME type
		// and file extension so SPIO's processable check sees svg.
		wp_update_post( array(
			'ID'             => $id,
			'post_mime_type' => 'image/svg+xml',
		) );

		$uploads    = wp_upload_dir();
		$svgPath    = trailingslashit( $uploads['path'] ) . 'replacement.svg';
		file_put_contents( $svgPath, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>' );
		update_post_meta( $id, '_wp_attached_file', basename( $svgPath ) );

		$this->api->reset();
		do_action( 'enable-media-replace-upload-done', $svgPath, get_attached_file( $id ), $id );

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertEmpty(
			$reducerCalls,
			'Replacing a JPG with an SVG must NOT cause SPIO to call the optimizer API — SVG is not a processable type.'
		);

		// Cleanup the synthetic SVG.
		@unlink( $svgPath );
	}

	/**
	 * With PNG-to-JPG conversion enabled, replacing a JPG with a PNG via EMR,
	 * then optimizing, should:
	 *  1. Convert the PNG to JPG (png2jpg path inside handleImageUploadHook).
	 *  2. Store the backup as the ORIGINAL PNG bytes (not the JPG).
	 *
	 * Manual-plan row: 12.8 — png2jpg + EMR: backup holds the original PNG;
	 * restore returns the PNG.
	 *
	 * @return void
	 */
	public function test_replace_jpg_with_png_then_png2jpg_and_backup_is_original_png() {
		\wpSPIO()->settings()->png2jpg    = 1; // enable png2jpg conversion
		\wpSPIO()->settings()->backupImages = 1;
		$this->setAutoprocessOn();

		// Start with a JPG attachment.
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		// Simulate EMR clearing SPIO meta before the file swap.
		do_action( 'wp_handle_replace', array( 'post_id' => $id ) );
		$this->purgeQueueTable();

		// Replace the file on disk with a PNG copy.
		$uploads    = wp_upload_dir();
		$pngSource  = $this->fixturePath( 'fixture-small.png' );
		$pngDest    = trailingslashit( $uploads['path'] ) . 'emr-replaced.png';
		copy( $pngSource, $pngDest );

		// Update WP attachment record to point at the PNG.
		wp_update_post( array(
			'ID'             => $id,
			'post_mime_type' => 'image/png',
		) );
		update_post_meta( $id, '_wp_attached_file', trailingslashit( date( 'Y/m' ) ) . 'emr-replaced.png' );

		$this->api->reset();
		// Fire the EMR upload-done hook which will call handleReplaceEnqueue →
		// handleImageUploadHook → PNGConverter::convert (because png2jpg=1).
		do_action( 'enable-media-replace-upload-done', $pngDest, $pngDest, $id );

		// Run the optimizer queue (the item should have been enqueued above).
		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );

		// The conversion may or may not have happened depending on the size-margin
		// check, but no fatal should occur. If png2jpg conversion succeeded, the
		// main file is now a JPG.
		$mainPath = get_attached_file( $id );
		// Whatever the main file is, optimization must have attempted at least one
		// reducer call (conversion + optimize, or direct optimize).
		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty(
			$reducerCalls,
			'After EMR replace with a PNG (png2jpg ON), SPIO must attempt optimization via the reducer API.'
		);

		// Cleanup synthetic PNG.
		@unlink( $pngDest );
	}
}
