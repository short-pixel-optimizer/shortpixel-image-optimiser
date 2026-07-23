<?php
/**
 * Integration tests: Regenerate Thumbnails Advanced (RTA) hook touchpoints (plan 13).
 *
 * This is a hook-level suite. The Regenerate Thumbnails Advanced plugin does
 * NOT need to be installed. Tests fire the hooks that RTA fires directly.
 *
 * Hook wiring verified in shortpixel-plugin.php (AdminController::initHooks):
 *   line 334: add_action('shortpixel-thumbnails-regenerated',
 *                         [$queueController, 'thumbnailsChangedHookLegacy'], 10, 4)
 *   line 335: add_action('rta/image/thumbnails_regenerated',
 *                         [$queueController, 'thumbnailsChangedHook'], 10, 2)
 *   line 336: add_action('rta/image/thumbnails_removed',
 *                         [$queueController, 'thumbnailsChangedHook'], 10, 2)
 *
 * Both hooks are wired unconditionally (not gated on is_autoprocess) — the
 * thumbnail status reset always fires; re-queuing inside thumbnailsChangedHook
 * is gated on env()->is_autoprocess (QueueController.php line 1014).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Model\Image\ImageModel;

class RTAIntegrationTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Build a $sizes array in the format that RTA passes to the hook — a map
	 * of size-name → size-data-array — from the current attachment metadata.
	 * Returns only the first registered size to keep tests focused.
	 */
	private function firstRegeneratedSize( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['sizes'] ) ) {
			$this->markTestSkipped( 'Fixture did not produce any thumbnails — cannot test thumbnail-regeneration hook.' );
		}
		$sizeName  = array_key_first( $meta['sizes'] );
		$sizeData  = $meta['sizes'][ $sizeName ];
		return array( $sizeName => $sizeData );
	}

	/**
	 * Ensure the RTA-compatible hooks are wired. They are added on the 'init'
	 * action in initHooks(). In the test harness they may already be present;
	 * this helper wires them manually if not.
	 */
	private function ensureRtaHooksWired(): void {
		$queueController = new QueueController();
		if ( ! has_action( 'rta/image/thumbnails_regenerated', array( $queueController, 'thumbnailsChangedHook' ) ) ) {
			add_action( 'rta/image/thumbnails_regenerated', array( $queueController, 'thumbnailsChangedHook' ), 10, 2 );
		}
		if ( ! has_action( 'shortpixel-thumbnails-regenerated', array( $queueController, 'thumbnailsChangedHookLegacy' ) ) ) {
			add_action( 'shortpixel-thumbnails-regenerated', array( $queueController, 'thumbnailsChangedHookLegacy' ), 10, 4 );
		}
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	/**
	 * After optimizing an attachment, firing `rta/image/thumbnails_regenerated`
	 * with one regenerated size must mark THAT thumbnail as UNPROCESSED while
	 * the main file remains in its optimized state.
	 *
	 * Manual-plan row: 13.1 / 13.2 — regenerated thumbs lose optimized state;
	 * main file and other thumbs are untouched.
	 *
	 * @return void
	 */
	public function test_regenerated_thumbnails_marked_unprocessed() {
		$this->ensureRtaHooksWired();
		\wpSPIO()->env()->is_autoprocess = false; // isolate: just test status, not re-queue

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$before = $this->freshImageModel( $id );
		$this->assertTrue( $before->isOptimized(), 'Pre-condition: main file must be optimized.' );

		$sizes = $this->firstRegeneratedSize( $id );
		$sizeName = array_key_first( $sizes );

		$thumbBefore = $before->getThumbnail( $sizeName );
		if ( false === $thumbBefore ) {
			$this->markTestSkipped( "Thumbnail '$sizeName' not found in image model — cannot verify status reset." );
		}
		$this->assertTrue( $thumbBefore->isOptimized(), "Pre-condition: thumbnail '$sizeName' must be optimized." );

		// Fire the RTA hook.
		do_action( 'rta/image/thumbnails_regenerated', $id, $sizes );

		$after     = $this->freshImageModel( $id );
		$thumbAfter = $after->getThumbnail( $sizeName );

		$this->assertNotFalse( $thumbAfter, "Thumbnail '$sizeName' must still exist in the model after the hook." );
		$this->assertFalse(
			$thumbAfter->isOptimized(),
			"Thumbnail '$sizeName' must be marked UNPROCESSED after rta/image/thumbnails_regenerated fires."
		);

		// Main file must still be optimized — hook only touches supplied sizes.
		$this->assertTrue(
			$after->isOptimized(),
			'Main file must remain optimized after thumbnail-regeneration hook (hook only resets supplied thumbnail sizes).'
		);
	}

	/**
	 * When autoprocess is ON and `rta/image/thumbnails_regenerated` fires, the
	 * attachment must be added back to the optimize queue so the regenerated
	 * thumbnails are re-optimized automatically.
	 *
	 * Manual-plan row: 13.1 — thumbnails_changed re-queues when autoprocess is on.
	 *
	 * @return void
	 */
	public function test_regeneration_reenqueues_when_autoprocess_on() {
		$this->ensureRtaHooksWired();
		\wpSPIO()->env()->is_autoprocess = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );
		$this->purgeQueueTable();
		$this->api->reset();

		$sizes = $this->firstRegeneratedSize( $id );

		// Fire the RTA hook.
		do_action( 'rta/image/thumbnails_regenerated', $id, $sizes );

		// The hook must have added the item back to the queue; drive it.
		$this->runQueueUntilEmpty();

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty(
			$reducerCalls,
			'With autoprocess ON, rta/image/thumbnails_regenerated must cause SPIO to re-optimize via the API.'
		);

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'Image must be optimized again after RTA re-queue completes.' );
	}

	/**
	 * Firing the RTA thumbnail-regeneration hook must NOT change the main
	 * file on disk or its modification time — the hook only resets thumbnail
	 * metadata, it must not touch the main file.
	 *
	 * Manual-plan row: 13.2 — main file timestamp and state unchanged by
	 * thumbnail regeneration.
	 *
	 * @return void
	 */
	public function test_main_file_timestamp_and_state_unchanged_by_thumbnail_regeneration() {
		$this->ensureRtaHooksWired();
		\wpSPIO()->env()->is_autoprocess = false;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertFileExists( $mainPath );
		clearstatcache( true, $mainPath );
		$mtimeBefore = filemtime( $mainPath );

		$sizes = $this->firstRegeneratedSize( $id );
		do_action( 'rta/image/thumbnails_regenerated', $id, $sizes );

		clearstatcache( true, $mainPath );
		$mtimeAfter = filemtime( $mainPath );

		$this->assertSame(
			$mtimeBefore,
			$mtimeAfter,
			'rta/image/thumbnails_regenerated must not touch the main file on disk (mtime must be unchanged).'
		);

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main image must still be marked as optimized after the thumbnail-regeneration hook.'
		);
	}

	/**
	 * The legacy `shortpixel-thumbnails-regenerated` hook (four-argument form)
	 * must delegate to thumbnailsChangedHook() and produce the same result as
	 * the modern `rta/image/thumbnails_regenerated` hook — i.e. the specified
	 * thumbnail loses its optimized status.
	 *
	 * Manual-plan row: 13.1 (legacy hook name compatibility).
	 *
	 * @return void
	 */
	public function test_legacy_shortpixel_thumbnails_regenerated_hook_delegates() {
		$this->ensureRtaHooksWired();
		\wpSPIO()->env()->is_autoprocess = false;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$before   = $this->freshImageModel( $id );
		$sizes    = $this->firstRegeneratedSize( $id );
		$sizeName = array_key_first( $sizes );

		$thumbBefore = $before->getThumbnail( $sizeName );
		if ( false === $thumbBefore ) {
			$this->markTestSkipped( "Thumbnail '$sizeName' not found — cannot verify legacy hook delegation." );
		}
		$this->assertTrue( $thumbBefore->isOptimized(), "Pre-condition: '$sizeName' must be optimized." );

		// Fire the legacy four-argument hook.
		// Signature: do_action('shortpixel-thumbnails-regenerated', $postId, $originalMeta, $regeneratedSizes, $bulk)
		$originalMeta = wp_get_attachment_metadata( $id );
		do_action( 'shortpixel-thumbnails-regenerated', $id, $originalMeta, $sizes, false );

		$after      = $this->freshImageModel( $id );
		$thumbAfter = $after->getThumbnail( $sizeName );
		$this->assertNotFalse( $thumbAfter );
		$this->assertFalse(
			$thumbAfter->isOptimized(),
			"Legacy 'shortpixel-thumbnails-regenerated' hook must delegate to thumbnailsChangedHook() and mark the thumbnail UNPROCESSED."
		);
	}
}
