<?php
/**
 * Integration tests: WP/LR Sync (Photo Engine) hook touchpoints (plan 18).
 *
 * This is a hook-level suite. The WP/LR Sync plugin by Meow Apps does NOT
 * need to be installed. Tests fire the sync hook directly.
 *
 * Hook wiring verified in shortpixel-plugin.php (AdminController::initHooks):
 *   line 402: add_action('wplr_sync_media',
 *                         [AjaxController::getInstance(), 'onWpLrSyncMedia'], 10, 2)
 *
 * This hook is wired UNCONDITIONALLY (not gated on is_autoprocess).
 *
 * AjaxController::onWpLrSyncMedia($row) reads $row->wp_id and delegates to
 * onWpLrUpdateMedia($imageId) which:
 *   1. Calls $mediaItem->onDelete() — clears optimization meta + backup.
 *   2. Flushes the filesystem cache.
 *   3. Calls QueueController::addItemToQueue($mediaItem) — always enqueues,
 *      regardless of is_autoprocess.
 *
 * Note: wplr_update_media is commented out in the plugin (line 401). Only
 * wplr_sync_media is active.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Controller\QueueController;

class PhotoEngineTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Ensure the wplr_sync_media action hook is wired. It is registered
	 * unconditionally on 'init' in initHooks(). Add it manually if the test
	 * harness has not yet fired 'init'.
	 */
	private function ensureWplrHookWired(): void {
		$ajaxController = AjaxController::getInstance();
		if ( ! has_action( 'wplr_sync_media', array( $ajaxController, 'onWpLrSyncMedia' ) ) ) {
			add_action( 'wplr_sync_media', array( $ajaxController, 'onWpLrSyncMedia' ), 10, 2 );
		}
	}

	/**
	 * Build the $row object that WP/LR Sync passes to the wplr_sync_media action.
	 * The only field SPIO reads is `wp_id` (AjaxController::onWpLrSyncMedia line 1196).
	 */
	private function makeSyncRow( int $attachment_id ): object {
		return (object) array( 'wp_id' => $attachment_id );
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	/**
	 * After an image is optimized, firing `wplr_sync_media` (Photo Engine's
	 * resync action) must:
	 *  1. Clear the optimization meta (onDelete path in onWpLrUpdateMedia).
	 *  2. Add the image back to the optimize queue.
	 *
	 * Manual-plan row: 18.1 — wplr_sync_media clears meta and re-queues.
	 *
	 * @return void
	 */
	public function test_wplr_sync_media_clears_meta_and_reenqueues() {
		$this->ensureWplrHookWired();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$before = $this->freshImageModel( $id );
		$this->assertTrue( $before->isOptimized(), 'Pre-condition: image must be optimized before wplr_sync_media fires.' );

		$this->purgeQueueTable();
		$this->api->reset();

		// Fire the Photo Engine sync hook.
		do_action( 'wplr_sync_media', $this->makeSyncRow( $id ) );

		// 1. Meta must be cleared.
		$afterSync = $this->freshImageModel( $id );
		$this->assertFalse(
			$afterSync->isOptimized(),
			'wplr_sync_media must clear SPIO optimization meta via onDelete so the image is no longer marked optimized.'
		);

		// 2. The item must now be in the queue (onWpLrUpdateMedia calls addItemToQueue).
		$this->assertTrue(
			$this->queueHasWork(),
			'wplr_sync_media must add the image back to the SPIO optimize queue.'
		);
	}

	/**
	 * After `wplr_sync_media` clears meta and re-queues, driving the queue
	 * must successfully re-optimize the image (full end-to-end round-trip
	 * with a fresh API call).
	 *
	 * Manual-plan row: 18.2 — re-optimization after Lightroom resync.
	 *
	 * @return void
	 */
	public function test_reoptimization_after_lightroom_resync() {
		$this->ensureWplrHookWired();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$this->purgeQueueTable();
		$this->api->reset();

		// Trigger the Photo Engine resync.
		do_action( 'wplr_sync_media', $this->makeSyncRow( $id ) );

		// Drive the queue — onWpLrUpdateMedia already enqueued the item.
		$this->runQueueUntilEmpty();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Image must be fully re-optimized after wplr_sync_media clears meta and the queue is driven.'
		);

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty(
			$reducerCalls,
			'Re-optimization after wplr_sync_media must produce a fresh API call to the reducer endpoint.'
		);
	}
}
