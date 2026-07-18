<?php
/**
 * Integration tests: custom media ("Other Media" folders) (Wave 2).
 *
 * Custom media lets SPIO optimize images OUTSIDE the Media Library:
 * OtherMediaController::addDirectory() registers a folder (row in
 * shortpixel_folders), scans it (refreshFolder) and records each image as
 * a row in shortpixel_meta. Those rows are CustomImageModel instances
 * (type 'custom') that ride the same queue pipeline as media items, just
 * on the 'custom' queue — which runQueueUntilEmpty() already ticks.
 *
 * Eligibility rules (DirectoryOtherMediaModel::checkDirectory): the folder
 * must live inside the WP root, must not be the backup dir, and must not
 * contain Media Library images.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\OtherMediaController;
use ShortPixel\Controller\QueueController;

class CustomMediaFoldersTest extends SPIO_IntegrationTestCase {

	/** @var string Absolute path of the per-test custom folder (trailing slash). */
	private $customDir;

	public function set_up() {
		parent::set_up();

		// Both custom-media tables survive the test transaction (DDL-created),
		// and stale folder rows point at long-gone per-test directories.
		global $wpdb;
		foreach ( array( 'shortpixel_folders', 'shortpixel_meta' ) as $name ) {
			$table = $wpdb->prefix . $name;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "DELETE FROM `$table`" );
			}
		}

		$this->customDir = trailingslashit( WP_CONTENT_DIR ) . 'spio-custom-' . wp_generate_password( 8, false ) . '/';
		mkdir( $this->customDir );
		copy( $this->fixturePath( 'fixture-small.jpg' ), $this->customDir . 'custom-photo.jpg' );
		copy( $this->fixturePath( 'fixture-small.png' ), $this->customDir . 'custom-graphic.png' );
	}

	public function tear_down() {
		if ( is_dir( $this->customDir ) ) {
			foreach ( glob( $this->customDir . '*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->customDir );
		}

		parent::tear_down();
	}

	/** Register the per-test folder and return the folder model. */
	private function addCustomFolder() {
		$folder = OtherMediaController::getInstance()->addDirectory( $this->customDir );
		$this->assertNotFalse( $folder, 'addDirectory must accept a writable folder inside the WP root.' );
		return $folder;
	}

	/** shortpixel_meta rows (id => name) for a folder id. */
	private function customImageRows( int $folder_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, name FROM {$wpdb->prefix}shortpixel_meta WHERE folder_id = %d", $folder_id )
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->id ] = $row->name;
		}
		return $out;
	}

	private function freshCustomImage( int $custom_id ) {
		return \wpSPIO()->filesystem()->getImage( $custom_id, 'custom', false );
	}

	public function test_add_directory_registers_folder_and_scans_images() {
		$folder = $this->addCustomFolder();

		$this->assertGreaterThan( 0, (int) $folder->get( 'id' ), 'The folder must be persisted with a DB id.' );

		$rows = $this->customImageRows( (int) $folder->get( 'id' ) );
		$this->assertCount( 2, $rows, 'Both images in the folder must be scanned into shortpixel_meta.' );
		$this->assertContains( 'custom-photo.jpg', $rows );
		$this->assertContains( 'custom-graphic.png', $rows );
	}

	public function test_custom_image_optimizes_through_custom_queue() {
		$folder = $this->addCustomFolder();
		$rows   = $this->customImageRows( (int) $folder->get( 'id' ) );
		$id     = array_search( 'custom-photo.jpg', $rows, true );
		$this->assertIsInt( $id );

		$originalSize = filesize( $this->customDir . 'custom-photo.jpg' );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		$this->assertNotFalse( $image );

		$queueController = new QueueController();
		$queueController->addItemToQueue( $image );
		$this->runQueueUntilEmpty();

		$image = $this->freshCustomImage( $id );
		$this->assertTrue( $image->isOptimized(), 'The custom image must be optimized after the queue completes.' );

		clearstatcache();
		$this->assertLessThan(
			$originalSize,
			filesize( $this->customDir . 'custom-photo.jpg' ),
			'The optimized custom file on disk must be smaller than the original.'
		);
	}

	public function test_custom_image_backup_created_and_restore_reverts() {
		$folder = $this->addCustomFolder();
		$rows   = $this->customImageRows( (int) $folder->get( 'id' ) );
		$id     = array_search( 'custom-photo.jpg', $rows, true );

		$originalSize = filesize( $this->customDir . 'custom-photo.jpg' );

		$queueController = new QueueController();
		$queueController->addItemToQueue( \wpSPIO()->filesystem()->getImage( $id, 'custom' ) );
		$this->runQueueUntilEmpty();

		$image = $this->freshCustomImage( $id );
		$this->assertTrue( $image->isOptimized() );
		$this->assertTrue( $image->isRestorable(), 'Optimizing a custom image must leave it restorable (backup present).' );

		// Same ShortQ gotcha as media restores: the DONE optimize item would
		// swallow the restore as a mere next_action. Purge the queue first.
		$this->purgeQueueTable();
		$queueController = new QueueController();
		$queueController->addItemToQueue( $this->freshCustomImage( $id ), array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();

		clearstatcache();
		$image = $this->freshCustomImage( $id );
		$this->assertFalse( $image->isOptimized(), 'The custom image must no longer be optimized after restore.' );
		$this->assertSame(
			$originalSize,
			filesize( $this->customDir . 'custom-photo.jpg' ),
			'Restore must bring back the original file bytes.'
		);
	}

	public function test_add_directory_rejects_media_library_year_folder() {
		// checkifMediaLibrary only rejects DIRECT 4-digit-year subdirs of
		// uploads (uploads/2026); deeper month dirs and uploads itself pass.
		$uploads = wp_upload_dir();
		$yearDir = trailingslashit( dirname( $uploads['path'] ) );
		$this->assertMatchesRegularExpression( '#/\d{4}/$#', $yearDir );

		$folder = OtherMediaController::getInstance()->addDirectory( $yearDir );

		$this->assertFalse( $folder, 'A Media Library year folder must be rejected as a custom folder.' );
	}
}
