<?php
/**
 * Integration tests: re-optimization flow (Wave 2).
 *
 * The 'reoptimize' action (QueueController::addItemToQueue →
 * ActionController::enqueueItem) is a two-step flow: the item is first
 * RESTORED from backup synchronously (reoptimizeItem → restoreItem), then
 * re-enters the queue with next_action 'optimize' and the new
 * compressionType, so the follow-up API round-trip happens on normal
 * queue ticks.
 *
 * Compression levels: 0 = lossless, 1 = lossy, 2 = glossy.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Model\Image\ImageModel;

class ReoptimizationTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/** Enqueue the reoptimize action with a target level and drive the queue. */
	private function reoptimizeAttachment( int $attachment_id, int $compressionType ): void {
		// The DONE optimize item still sits in the ShortQ table; with it
		// present, addItemToQueue() only appends 'reoptimize' as a
		// next_action on that finished item and nothing runs (same gotcha
		// as the Wave-1 restore tests). Purge the queue first.
		$this->purgeQueueTable();

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertNotFalse( $imageModel );

		$queueController = new QueueController();
		$queueController->addItemToQueue(
			$imageModel,
			array(
				'action'          => 'reoptimize',
				'compressionType' => $compressionType,
			)
		);

		$this->runQueueUntilEmpty();
	}

	public function test_reoptimize_lossy_to_lossless_updates_compression_type() {
		\wpSPIO()->settings()->compressionType = ImageModel::COMPRESSION_LOSSY;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );
		$this->assertSame( ImageModel::COMPRESSION_LOSSY, (int) $image->getMeta( 'compressionType' ), 'First optimization must record the lossy level.' );

		$this->reoptimizeAttachment( $id, ImageModel::COMPRESSION_LOSSLESS );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'The image must be optimized again after reoptimize.' );
		$this->assertSame(
			ImageModel::COMPRESSION_LOSSLESS,
			(int) $image->getMeta( 'compressionType' ),
			'Reoptimize must record the new (lossless) compression level.'
		);
	}

	public function test_reoptimize_to_glossy_updates_compression_type() {
		\wpSPIO()->settings()->compressionType = ImageModel::COMPRESSION_LOSSY;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$this->reoptimizeAttachment( $id, ImageModel::COMPRESSION_GLOSSY );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );
		$this->assertSame( ImageModel::COMPRESSION_GLOSSY, (int) $image->getMeta( 'compressionType' ) );
	}

	public function test_reoptimize_sends_second_reducer_request_with_new_level() {
		\wpSPIO()->settings()->compressionType = ImageModel::COMPRESSION_LOSSY;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		// Isolate the reoptimize round-trip from the initial optimization.
		$this->api->reset();

		$this->reoptimizeAttachment( $id, ImageModel::COMPRESSION_LOSSLESS );

		$reducerLevels = array();
		foreach ( $this->api->requests as $req ) {
			if ( false !== strpos( $req['url'], 'reducer' ) && isset( $req['request']['lossy'] ) ) {
				$reducerLevels[] = (int) $req['request']['lossy'];
			}
		}

		$this->assertNotEmpty( $reducerLevels, 'Reoptimize must trigger a fresh reducer request.' );
		$this->assertContains( ImageModel::COMPRESSION_LOSSLESS, $reducerLevels, 'The new reducer request must carry the new compression level.' );
	}

	public function test_reoptimize_restores_from_backup_and_keeps_backup_afterwards() {
		\wpSPIO()->settings()->compressionType = ImageModel::COMPRESSION_LOSSY;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$originalSize = filesize( get_attached_file( $id ) );

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->hasBackup(), 'Backup must exist before reoptimize can restore.' );

		$this->reoptimizeAttachment( $id, ImageModel::COMPRESSION_GLOSSY );

		clearstatcache();
		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );
		$this->assertTrue( $image->hasBackup(), 'The backup must be recreated for the new optimization round.' );
		$this->assertLessThan(
			$originalSize,
			filesize( get_attached_file( $id ) ),
			'The reoptimized file on disk must be smaller than the original.'
		);
	}
}
