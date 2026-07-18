<?php
/**
 * Integration tests: attachment deletion cleanup (Wave 2).
 *
 * wp_delete_attachment() fires 'delete_attachment', which SPIO hooks at
 * priority 5 (AdminController::onDeleteAttachment). That routes into
 * MediaLibraryModel::onDelete(), which must remove everything SPIO created
 * for the attachment: the backup files (main + thumbnails), any WebP/AVIF
 * companion files, the shortpixel_postmeta rows, and the queue entry.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class DeleteAttachmentTest extends SPIO_IntegrationTestCase {

	/** All backup files currently below the backup root. */
	private function backupFilesOnDisk(): array {
		if ( ! is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			return array();
		}
		$found    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	/** shortpixel_postmeta row count for an attachment id. */
	private function postmetaRowCount( int $attachment_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}shortpixel_postmeta WHERE attach_id = %d", $attachment_id )
		);
	}

	public function set_up() {
		parent::set_up();
		// Other test classes leave their backup files behind (the tree lives
		// outside the DB transaction); start empty so the all-backups-removed
		// assertions only see THIS test's files.
		$this->purgeBackupTree();
	}

	public function tear_down() {
		$this->purgeBackupTree();
		parent::tear_down();
	}

	private function purgeBackupTree(): void {
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
		}
	}

	public function test_delete_optimized_attachment_removes_backup_files() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$this->assertNotEmpty( $this->backupFilesOnDisk(), 'Optimization must have produced backup files.' );

		wp_delete_attachment( $id, true );

		$this->assertEmpty(
			$this->backupFilesOnDisk(),
			'Deleting the attachment must remove all of its backup files.'
		);
	}

	public function test_delete_optimized_attachment_removes_shortpixel_postmeta() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$this->assertGreaterThan( 0, $this->postmetaRowCount( $id ), 'Optimization must have written shortpixel_postmeta rows.' );

		wp_delete_attachment( $id, true );

		$this->assertSame(
			0,
			$this->postmetaRowCount( $id ),
			'Deleting the attachment must remove its shortpixel_postmeta rows.'
		);
	}

	public function test_delete_attachment_removes_webp_companions() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$webp  = $image->getWebp();
		$this->assertNotFalse( $webp );
		$this->assertTrue( $webp->exists(), 'WebP companion must exist after optimizing with createWebp=1.' );
		$webpPath = $webp->getFullPath();

		wp_delete_attachment( $id, true );

		clearstatcache();
		$this->assertFileDoesNotExist( $webpPath, 'Deleting the attachment must remove its WebP companion file.' );
	}

	public function test_delete_queued_attachment_drops_queue_entry() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';
		$rows  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE item_id = %d", $id ) );
		$this->assertGreaterThan( 0, $rows, 'The item must be waiting in the queue table before deletion.' );

		wp_delete_attachment( $id, true );

		// dropItem() removes the DB rows but does NOT refresh the cached
		// stats counters, so assert on the table itself...
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE item_id = %d", $id ) );
		$this->assertSame( 0, $rows, 'Deleting a queued attachment must drop its queue-table rows.' );

		// ...and prove a subsequent tick has nothing to send to the API.
		$queueController = new QueueController();
		$queueController->processQueue( array( 'media' ) );
		$this->assertCount( 0, $this->api->requests, 'No API request may be made for a deleted attachment.' );
	}
}
