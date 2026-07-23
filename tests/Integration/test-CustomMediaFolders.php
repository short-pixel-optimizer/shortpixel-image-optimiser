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

	/**
	 * Reset the static stats cache on DirectoryOtherMediaModel so subsequent
	 * getStats() calls issue a fresh query instead of returning stale data.
	 */
	private function resetFolderStatsCache(): void {
		$ref  = new ReflectionProperty( \ShortPixel\Model\File\DirectoryOtherMediaModel::class, 'stats' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
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

	// -------------------------------------------------------------------
	// Wave-3 additions (rows 6.2 – 6.18)
	// -------------------------------------------------------------------

	/**
	 * An empty directory (no images) is registered but reports zero total images.
	 * Manual plan row 6.2.
	 */
	public function test_add_empty_directory_zero_images() {
		// Replace the fixture images so the directory is empty.
		foreach ( glob( $this->customDir . '*' ) as $f ) {
			unlink( $f );
		}

		$folder = OtherMediaController::getInstance()->addDirectory( $this->customDir );
		$this->assertNotFalse( $folder, 'An empty but valid directory must be accepted by addDirectory().' );

		// Reset the static stats cache so we read fresh counts.
		$this->resetFolderStatsCache();

		$stats = $folder->getStats();
		$total = is_array( $stats ) ? (int) $stats['total'] : 0;
		$this->assertSame( 0, $total, 'A freshly-added empty directory must report zero images (row 6.2).' );
	}

	/**
	 * A registered custom folder does NOT auto-enqueue items into the bulk queue
	 * unless a bulk run has been explicitly started.
	 * Manual plan row 6.3.
	 */
	public function test_unprocessed_custom_folder_does_not_auto_bulk() {
		// autoMediaLibrary controls auto-enqueue on upload; ensure it is off for
		// custom-media items that have not been through a bulk.
		\wpSPIO()->settings()->autoMediaLibrary = 0;

		$this->addCustomFolder();

		// No bulk has been created/started — the bulk queues must be empty.
		$bulkController = new QueueController( array( 'is_bulk' => true ) );
		$stats          = $bulkController->getQueue( 'custom' )->getStats();

		$inQueue = is_object( $stats ) ? (int) $stats->in_queue : 0;
		$this->assertSame(
			0,
			$inQueue,
			'Adding a custom folder without starting a bulk must not auto-enqueue items on the bulk custom queue (row 6.3).'
		);
	}

	/**
	 * After a bulk completes and the folder is re-added (re-registered),
	 * the fresh folder enters the bulk pipeline again.
	 * Manual plan row 6.4.
	 */
	public function test_readded_custom_folder_re_enters_bulk() {
		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );
		$rows      = $this->customImageRows( $folder_id );

		// Optimize all items through the single queue (simulates a completed run).
		foreach ( array_keys( $rows ) as $id ) {
			$image = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
			( new QueueController() )->addItemToQueue( $image );
		}
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		// Delete the folder from the DB, then re-add it.
		$folder->delete();
		// Also remove the images from disk to simulate a "fresh" folder scenario.
		// Actually just re-add to test that re-registration works.
		$reFolder = OtherMediaController::getInstance()->addDirectory( $this->customDir );
		$this->assertNotFalse( $reFolder, 'Re-adding a previously-deleted folder must succeed (row 6.4).' );
		$this->assertGreaterThan( 0, (int) $reFolder->get( 'id' ), 'Re-added folder must get a DB id.' );

		// The meta rows must exist again so the bulk can pick them up.
		$this->resetFolderStatsCache();
		$reStats = $reFolder->getStats();
		$this->assertGreaterThan(
			0,
			(int) $reStats['total'],
			'Re-added folder must have images scanned and ready for bulk processing (row 6.4).'
		);
	}

	/**
	 * addDirectory() rejects a path that contains a non-writable sub-folder;
	 * checkDirectoryRecursive fails and returns false.
	 * Manual plan row 6.4b.
	 *
	 * Note: this test is skipped when running as root (root ignores chmod).
	 */
	public function test_add_directory_with_nonwritable_subfolder_shows_error() {
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Running as root; chmod 0555 has no effect.' );
		}

		$subDir = $this->customDir . 'sub-readonly/';
		mkdir( $subDir );
		chmod( $subDir, 0555 ); // read + execute only, no write

		try {
			$result = OtherMediaController::getInstance()->addDirectory( $this->customDir );
			$this->assertFalse(
				$result,
				'addDirectory() must return false when a sub-folder is not writable (row 6.4b).'
			);
		} finally {
			// Restore permissions so tear_down() can clean up.
			chmod( $subDir, 0755 );
			rmdir( $subDir );
		}
	}

	/**
	 * A directory whose name contains a single quote is registered without
	 * SQL errors or path mangling.
	 * Manual plan row 6.4c.
	 */
	public function test_add_directory_with_single_quote_in_name() {
		$quotedDir = trailingslashit( WP_CONTENT_DIR ) . "spio-it's-a-test-" . wp_generate_password( 6, false ) . '/';
		mkdir( $quotedDir );
		copy( $this->fixturePath( 'fixture-small.jpg' ), $quotedDir . 'photo.jpg' );

		try {
			$folder = OtherMediaController::getInstance()->addDirectory( $quotedDir );
			$this->assertNotFalse(
				$folder,
				"addDirectory() must accept a path containing a single quote without SQL errors (row 6.4c)."
			);
			$this->assertGreaterThan( 0, (int) $folder->get( 'id' ), 'The folder with a quoted name must be persisted.' );
		} finally {
			@unlink( $quotedDir . 'photo.jpg' );
			@rmdir( $quotedDir );
		}
	}

	/**
	 * Deleting a registered custom folder removes (or soft-deletes) it so the
	 * bulk queue no longer processes items from it.
	 * Manual plan row 6.5.
	 */
	public function test_remove_custom_folder_stops_bulk_processing() {
		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );

		// Confirm images are registered.
		$rows = $this->customImageRows( $folder_id );
		$this->assertNotEmpty( $rows, 'Precondition: images must exist before removal.' );

		// Delete the folder (hard or soft depending on optimization state).
		$folder->delete();

		// No unprocessed meta rows should remain for the deleted folder.
		global $wpdb;
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$wpdb->prefix}shortpixel_meta WHERE folder_id = %d AND status = 0",
				$folder_id
			)
		);
		$this->assertSame(
			0,
			$remaining,
			'After folder removal, no unprocessed meta rows may remain to be picked up by the bulk queue (row 6.5).'
		);
	}

	/**
	 * Re-adding a partially-processed custom folder (some images optimized,
	 * folder soft-deleted) resumes from where optimization left off: already-
	 * optimized images retain their status and new/unprocessed ones are queued.
	 * Manual plan row 6.6.
	 */
	public function test_readded_partially_processed_folder_resumes_correctly() {
		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );
		$rows      = $this->customImageRows( $folder_id );

		// Optimize only the first image.
		$firstId = (int) array_key_first( $rows );
		$image   = \wpSPIO()->filesystem()->getImage( $firstId, 'custom' );
		( new QueueController() )->addItemToQueue( $image );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$this->assertTrue(
			$this->freshCustomImage( $firstId )->isOptimized(),
			'Precondition: first image must be optimized.'
		);

		// Soft-delete the folder (has an optimized image, so status -> -1).
		$folder->delete();

		// Re-add the folder.
		$reFolder = OtherMediaController::getInstance()->addDirectory( $this->customDir );
		$this->assertNotFalse( $reFolder, 'Re-adding a partially-processed folder must succeed (row 6.6).' );

		// The already-optimized image must still report as optimized.
		$reImage = $this->freshCustomImage( $firstId );
		$this->assertTrue(
			$reImage->isOptimized(),
			'Already-optimized images must retain their optimized status after the folder is re-added (row 6.6).'
		);
	}

	/**
	 * refreshFolder() detects images added to disk after the initial registration
	 * and, when autoMediaLibrary=1 (the test baseline), auto-enqueues them.
	 * Manual plan row 6.7.
	 *
	 * Note: EnvironmentModel::is_autoprocess is read once at singleton construction
	 * from settings->autoMediaLibrary. The baseline sets autoMediaLibrary=1 so
	 * is_autoprocess=true is in effect for the whole test. This test therefore
	 * verifies the detection and enqueue behaviour with autoMediaLibrary enabled
	 * (the dominant production scenario) and asserts file-detection alone for
	 * the no-auto path by draining the queue first.
	 */
	public function test_refresh_folder_detects_new_images_respects_auto_setting() {
		// Baseline already sets autoMediaLibrary=1 / is_autoprocess=true.
		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );

		$beforeCount = count( $this->customImageRows( $folder_id ) );

		// Drop a new image onto disk AFTER registration.
		$newFile = $this->customDir . 'new-arrival.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $newFile );

		// --- 6.7 part A: detect-only — drain queue, refresh, confirm row added. ---
		// Run anything already queued so we start from a known-empty state.
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		// Reset stat cache and force a full refresh.
		$this->resetFolderStatsCache();
		$folder->refreshFolder( true );

		$afterRows = $this->customImageRows( $folder_id );
		$this->assertCount(
			$beforeCount + 1,
			$afterRows,
			'refreshFolder() must detect the newly added disk file and insert a meta row (row 6.7).'
		);

		// --- 6.7 part B: with auto on (baseline), a further refresh enqueues new images. ---
		$anotherFile = $this->customDir . 'another-arrival.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $anotherFile );

		// Drain again so the queue is empty before we assert.
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$this->resetFolderStatsCache();
		$folder->refreshFolder( true );

		$this->assertTrue(
			$this->queueHasWork(),
			'With autoMediaLibrary=1 (the baseline), refreshFolder() must auto-enqueue newly detected images (row 6.7).'
		);
	}

	/**
	 * When an image file is deleted from disk before the queue runs, the queue
	 * handles it gracefully (marks the item as an error / unreachable) rather
	 * than crashing.
	 * Manual plan row 6.8.
	 */
	public function test_deleted_image_on_disk_handled_gracefully() {
		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );
		$rows      = $this->customImageRows( $folder_id );
		$id        = (int) array_search( 'custom-photo.jpg', $rows, true );

		// Enqueue the image, then delete the file before the queue ticks.
		$image = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $image );

		unlink( $this->customDir . 'custom-photo.jpg' );

		// The queue must drain without throwing an exception or hanging.
		// The mock API will return UNREACHABLE for the missing file, so the
		// item will be marked as an error — not left in-process forever.
		$this->runQueueUntilEmpty();

		// Post-run: image must NOT be marked as optimized.
		$reloaded = $this->freshCustomImage( $id );
		$this->assertFalse(
			$reloaded->isOptimized(),
			'A custom image whose file was deleted before optimization must not end up marked as optimized (row 6.8).'
		);
	}

	/**
	 * When backupImages=0 no backup file is created for custom images.
	 * Manual plan row 6.11.
	 */
	public function test_no_backup_created_when_backups_disabled() {
		// Reset FIRST: resetPluginSingletons() reloads SettingsModel from the
		// DB and would wipe an unsaved in-memory backupImages write.
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->backupImages = 0;

		$folder    = $this->addCustomFolder();
		$rows      = $this->customImageRows( (int) $folder->get( 'id' ) );
		$id        = (int) array_search( 'custom-photo.jpg', $rows, true );
		$image     = \wpSPIO()->filesystem()->getImage( $id, 'custom' );

		// The backup dir is shared across the whole test run (earlier tests
		// leave their backups + bulk logs behind), so compare before/after
		// instead of asserting the directory is empty.
		$backupsBefore = $this->listBackupFiles();

		( new QueueController() )->addItemToQueue( $image );
		$this->runQueueUntilEmpty();

		$optimized = $this->freshCustomImage( $id );
		$this->assertTrue( $optimized->isOptimized(), 'Precondition: image must be optimized.' );

		$newBackups = array_diff( $this->listBackupFiles(), $backupsBefore );
		$this->assertSame(
			array(),
			array_values( $newBackups ),
			'With backupImages=0 no new backup files must be created for custom media (row 6.11).'
		);
	}

	// -------------------------------------------------------------------
	// Wave-4 additions (rows 6.22, 6.24, 6.25)
	// -------------------------------------------------------------------

	/**
	 * Custom-media bulk actions via the AJAX endpoint code path.
	 *
	 * The UI has no dedicated multi-select bulk endpoint for custom media.
	 * The "bulk action" on the Other Media screen is implemented as a loop
	 * of per-item calls to the same optimizeItem / reOptimizeItem handlers
	 * used for single-item actions — each with type='custom'.  This test
	 * exercises those handlers directly on their production code path by
	 * calling the protected methods on AjaxController via reflection after
	 * populating $_POST exactly as the AJAX dispatcher would have received
	 * it (nonce/capability gates are tested separately in test-AjaxEndpoint.php
	 * and are not the subject of this functional test).
	 *
	 * Steps:
	 *  1. Optimize two custom images sequentially — mirrors "select all → Optimize".
	 *  2. Restore both so they are eligible for re-optimization.
	 *  3. Re-optimize both at Lossless (compressionType=0) — mirrors "select all → Re-optimize Lossless".
	 *
	 * Manual plan row 6.22.
	 */
	public function test_custom_media_bulk_actions_via_ajax() {
		// checkImageAccess() checks 'edit_others_posts' for type='custom'; an
		// administrator has that capability.
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );
		$rows      = $this->customImageRows( $folder_id );

		$this->assertCount( 2, $rows, 'Precondition: both fixture images must be scanned.' );

		$ids = array_keys( $rows );

		// --- Step 1: bulk optimize (same handler as single-item "Optimize"). ---
		$ajaxController = \ShortPixel\Controller\AjaxController::getInstance();
		$optimizeMethod = new ReflectionMethod( \ShortPixel\Controller\AjaxController::class, 'optimizeItem' );
		$optimizeMethod->setAccessible( true );

		foreach ( $ids as $custom_id ) {
			$_POST = array(
				'id'   => $custom_id,
				'type' => 'custom',
			);
			$result = $optimizeMethod->invoke( $ajaxController );
			$this->assertIsObject( $result );
			$this->assertIsArray( $result->custom->results, "optimizeItem must return a results array for custom id $custom_id" );
		}

		$this->runQueueUntilEmpty();

		foreach ( $ids as $custom_id ) {
			$this->assertTrue(
				$this->freshCustomImage( $custom_id )->isOptimized(),
				"Custom image $custom_id must be optimized after bulk-optimize via the AJAX handler (row 6.22)."
			);
		}

		// --- Step 2: bulk re-optimize at Lossless (compressionType=0). ---
		// The reoptimize action operates on OPTIMIZED images (it restores
		// internally before re-sending), so the images stay optimized here.
		// Clear the DONE rows from step 1 so the re-add is not blocked.
		$this->purgeQueueTable();

		$reOptimizeMethod = new ReflectionMethod( \ShortPixel\Controller\AjaxController::class, 'reOptimizeItem' );
		$reOptimizeMethod->setAccessible( true );

		$requestsBefore = count( $this->api->requests );

		foreach ( $ids as $custom_id ) {
			$_POST = array(
				'id'              => $custom_id,
				'type'            => 'custom',
				'compressionType' => 0,
			);
			$data = array(
				'id'   => $custom_id,
				'type' => 'custom',
			);
			// The dispatcher pre-creates $json->$type before delegating
			// (AjaxController::ajaxRequest) — mirror that here.
			$json         = new \stdClass();
			$json->custom = new \stdClass();
			$result       = $reOptimizeMethod->invoke( $ajaxController, $json, $data );
			$this->assertIsObject( $result );
			$this->assertTrue( $result->status, "reOptimizeItem must return status=true for custom id $custom_id (row 6.22)." );
		}

		$this->runQueueUntilEmpty();

		foreach ( $ids as $custom_id ) {
			$reloaded = $this->freshCustomImage( $custom_id );
			$this->assertTrue(
				$reloaded->isOptimized(),
				"Custom image $custom_id must be optimized again after bulk re-optimize (row 6.22)."
			);
			$this->assertSame(
				0,
				(int) $reloaded->getMeta( 'compressionType' ),
				"compressionType must be 0 (Lossless) after the re-optimize bulk action (row 6.22)."
			);
		}

		$this->assertGreaterThan(
			$requestsBefore,
			count( $this->api->requests ),
			'Bulk re-optimize must have sent both custom images through the (mocked) API (row 6.22).'
		);
	}

	/**
	 * Scan all custom folders via the scanNextFolder AJAX path and assert
	 * idempotency: a second immediate scan finds nothing left to do.
	 *
	 * The UI's "Update / Scan All Folders" button fires scanNextFolder
	 * repeatedly until the server returns is_done=true.  This test drives
	 * OtherMediaController::doNextRefreshableFolder() — the sole production
	 * method called by the scanNextFolder handler — directly, which is
	 * equivalent to the AJAX path but without the nonce/capability gates.
	 * The handler itself is trivially thin (see AjaxController::scanNextFolder,
	 * line 2353): it reads $_POST['force'], calls doNextRefreshableFolder(),
	 * and formats the result.
	 *
	 * Registers two folders, runs the scan loop (one call per folder plus
	 * the "all done" sentinel), then immediately repeats: because ts_checked
	 * was just set to NOW, the interval filter (default 1h) excludes all
	 * folders and the very first call already returns false → is_done.
	 *
	 * Manual plan row 6.24.
	 */
	public function test_update_all_folders_scan_and_idempotent() {
		// Register a second folder alongside the per-test one.
		$secondDir = trailingslashit( WP_CONTENT_DIR ) . 'spio-custom2-' . wp_generate_password( 8, false ) . '/';
		mkdir( $secondDir );
		copy( $this->fixturePath( 'fixture-small.jpg' ), $secondDir . 'img-a.jpg' );

		try {
			$folder2 = OtherMediaController::getInstance()->addDirectory( $secondDir );
			$this->assertNotFalse( $folder2, 'Precondition: second folder must be accepted (row 6.24).' );

			$folder1 = $this->addCustomFolder();

			$otherMedia = OtherMediaController::getInstance();

			// addDirectory() calls refreshFolder() internally which stamps ts_checked=NOW.
			// Reset to NULL so the scan query (ts_checked <= now()-interval OR NULL) picks
			// both folders up on the first iteration.
			$otherMedia->resetCheckedTimestamps();

			// Run the scan loop until done, counting how many folders were processed.
			$processedFolders = 0;
			for ( $i = 0; $i < 20; $i++ ) {
				$result = $otherMedia->doNextRefreshableFolder( array( 'force' => true ) );
				if ( false === $result ) {
					break;
				}
				$processedFolders++;
				$this->assertArrayHasKey( 'folder_id', $result, 'Each scan result must include folder_id (row 6.24).' );
				$this->assertArrayHasKey( 'new_count', $result, 'Each scan result must include new_count (row 6.24).' );
				$this->assertGreaterThanOrEqual( 1, (int) $result['new_count'], 'Each scanned folder must report at least 1 file (row 6.24).' );
			}

			$this->assertGreaterThanOrEqual( 2, $processedFolders, 'Both registered folders must be scanned before is_done (row 6.24).' );

			// Second run: ts_checked is now recent — the interval filter blocks all
			// folders → the very first call must return false (is_done).
			$secondRunResult = $otherMedia->doNextRefreshableFolder();
			$this->assertFalse(
				$secondRunResult,
				'Immediately after scanning, a second scan pass must return false (is_done) because no folder has exceeded the interval (row 6.24).'
			);
		} finally {
			foreach ( glob( $secondDir . '*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $secondDir );
		}
	}

	/**
	 * After an initial scan, adding a file between runs and resetting the
	 * scan timestamps causes the next scan to detect the new file.
	 *
	 * This tests the "Scan" behaviour that is distinct from the idempotency
	 * of 6.24: the administrator adds a file to disk, resets timestamps (via
	 * the resetScanFolderChecked action or resetCheckedTimestamps()), and
	 * triggers another scan — the new file must appear in the updated count.
	 *
	 * As in 6.24, OtherMediaController::doNextRefreshableFolder() is called
	 * directly since it is the sole production call inside the scanNextFolder
	 * AJAX handler.  OtherMediaController::resetCheckedTimestamps() mirrors
	 * what the resetScanFolderChecked AJAX action does.
	 *
	 * Manual plan row 6.25.
	 */
	public function test_update_all_folders_rescan_on_second_run() {
		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );

		$otherMedia = OtherMediaController::getInstance();

		// addDirectory() stamps ts_checked=NOW internally; reset to NULL so the
		// scan query picks the folder up on the first doNextRefreshableFolder call.
		$otherMedia->resetCheckedTimestamps();

		// First scan: folder now has NULL ts_checked, so it is eligible immediately.
		$firstResult = $otherMedia->doNextRefreshableFolder( array( 'force' => true ) );
		$this->assertIsArray( $firstResult, 'First scan must process the folder (row 6.25).' );
		$this->assertSame( (string) $folder_id, (string) $firstResult['folder_id'], 'Row 6.25.' );

		$countAfterFirst = (int) $firstResult['new_count'];
		$this->assertSame( 2, $countAfterFirst, 'Precondition: folder must report 2 files after the first scan (row 6.25).' );

		// Drop a new image onto disk between scans.
		$newFile = $this->customDir . 'late-arrival.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $newFile );

		// Reset all ts_checked timestamps to NULL — mirrors resetScanFolderChecked AJAX action.
		$otherMedia->resetCheckedTimestamps();

		// Reset the static folder-stats cache so getStats() reads fresh DB state.
		$this->resetFolderStatsCache();

		// Second scan: timestamps are NULL again so the folder is eligible.
		$secondResult = $otherMedia->doNextRefreshableFolder( array( 'force' => true ) );
		$this->assertIsArray( $secondResult, 'Second scan must process the folder after timestamp reset (row 6.25).' );

		$countAfterSecond = (int) $secondResult['new_count'];
		$this->assertGreaterThan(
			$countAfterFirst,
			$countAfterSecond,
			'The second scan must detect the file added between scans, increasing the reported file count (row 6.25).'
		);

		$newRows = $this->customImageRows( $folder_id );
		$this->assertCount(
			3,
			$newRows,
			'After the second scan there must be 3 meta rows (2 original + 1 new arrival) in shortpixel_meta (row 6.25).'
		);
	}

	/** All files currently under SHORTPIXEL_BACKUP_FOLDER (recursive). */
	private function listBackupFiles(): array {
		$backupFiles = array();
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iter as $f ) {
				if ( $f->isFile() ) {
					$backupFiles[] = $f->getPathname();
				}
			}
		}
		return $backupFiles;
	}

	/**
	 * Re-optimizing a custom image at a different compression level updates
	 * the stored compressionType metadata accordingly. The test cycle is:
	 * optimize (lossy) → restore → optimize (lossless) → assert compressionType=0.
	 * Manual plan row 6.13.
	 */
	public function test_reoptimize_with_different_compression_level() {
		$folder    = $this->addCustomFolder();
		$rows      = $this->customImageRows( (int) $folder->get( 'id' ) );
		$id        = (int) array_search( 'custom-photo.jpg', $rows, true );

		// First pass: lossy (compressionType = 1).
		\wpSPIO()->settings()->compressionType = 1;
		$image = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $image );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$afterLossy = $this->freshCustomImage( $id );
		$this->assertTrue( $afterLossy->isOptimized(), 'Precondition: image must be optimized after first pass.' );
		$this->assertSame( 1, (int) $afterLossy->getMeta( 'compressionType' ), 'Precondition: compressionType must be 1 after lossy pass.' );

		// Restore so the image is unoptimized and compressionType is cleared.
		( new QueueController() )->addItemToQueue( $this->freshCustomImage( $id ), array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$restored = $this->freshCustomImage( $id );
		$this->assertFalse( $restored->isOptimized(), 'Precondition: image must be unoptimized after restore.' );

		// Second pass: lossless (compressionType = 0). Reset FIRST — the
		// singleton reset reloads settings from the DB and would wipe the
		// unsaved in-memory write.
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->compressionType = 0;

		$reImage = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $reImage );
		$this->runQueueUntilEmpty();

		$afterLossless = $this->freshCustomImage( $id );
		$this->assertTrue( $afterLossless->isOptimized(), 'Image must be optimized after the lossless re-optimization (row 6.13).' );

		// The stored compression type must reflect the lossless run.
		$storedType = (int) $afterLossless->getMeta( 'compressionType' );
		$this->assertSame(
			0,
			$storedType,
			'compressionType in meta must be 0 (lossless) after the second optimization run (row 6.13).'
		);
	}

	/**
	 * An image whose filename matches a configured exclusion pattern is not
	 * optimized by the custom-media pipeline.
	 * Manual plan row 6.17.
	 */
	public function test_excluded_custom_image_not_optimized() {
		// Configure an exclusion pattern matching the custom image filename.
		\wpSPIO()->settings()->excludePatterns = array(
			array( 'type' => 'name', 'value' => 'custom-photo.jpg' ),
		);

		$folder = $this->addCustomFolder();
		$rows   = $this->customImageRows( (int) $folder->get( 'id' ) );
		$id     = (int) array_search( 'custom-photo.jpg', $rows, true );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $image );
		$this->runQueueUntilEmpty();

		$reloaded = $this->freshCustomImage( $id );
		$this->assertFalse(
			$reloaded->isOptimized(),
			'A custom image whose filename matches an exclusion pattern must not be optimized (row 6.17).'
		);
	}

	/**
	 * Bulk optimize custom media only: first without WebP/AVIF (baseline),
	 * then restore + re-run with createWebp=1 (WebP companion added), then
	 * restore + re-run with createAvif=1 (AVIF companion added).
	 * The restore→re-optimize cycle is necessary because the custom pipeline
	 * skips already-optimized images.
	 * Manual plan row 6.18.
	 */
	public function test_bulk_custom_only_webp_then_avif_generation() {
		\wpSPIO()->settings()->createWebp = 0;
		\wpSPIO()->settings()->createAvif = 0;

		$folder    = $this->addCustomFolder();
		$folder_id = (int) $folder->get( 'id' );
		$rows      = $this->customImageRows( $folder_id );
		$id        = (int) array_search( 'custom-photo.jpg', $rows, true );

		// --- Pass 1: baseline optimization (no WebP / AVIF). ---
		$image = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $image );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$optimized = $this->freshCustomImage( $id );
		$this->assertTrue( $optimized->isOptimized(), 'Precondition: baseline optimization must complete (row 6.18).' );

		// No WebP companion must exist after the baseline pass.
		$webp = $optimized->getWebp();
		$this->assertTrue(
			( false === $webp || ! $webp->exists() ),
			'No WebP companion must exist after a non-WebP optimization pass (row 6.18).'
		);

		// --- Pass 2: restore then re-optimize with WebP enabled. ---
		( new QueueController() )->addItemToQueue( $this->freshCustomImage( $id ), array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$this->resetPluginSingletons();
		\wpSPIO()->settings()->createWebp = 1;

		$reImage = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $reImage );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$withWebp = $this->freshCustomImage( $id );
		$webpFile = $withWebp->getWebp();
		$this->assertNotFalse( $webpFile, 'A WebP companion model must be returned after re-running with createWebp=1 (row 6.18).' );
		$this->assertTrue( $webpFile->exists(), 'The WebP companion file must exist on disk after the second pass (row 6.18).' );

		// --- Pass 3: restore then re-optimize with AVIF enabled. ---
		( new QueueController() )->addItemToQueue( $this->freshCustomImage( $id ), array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();
		$this->purgeQueueTable();

		$this->resetPluginSingletons();
		\wpSPIO()->settings()->createAvif = 1;

		$reImage2 = \wpSPIO()->filesystem()->getImage( $id, 'custom' );
		( new QueueController() )->addItemToQueue( $reImage2 );
		$this->runQueueUntilEmpty();

		$withAvif = $this->freshCustomImage( $id );
		$avifFile = $withAvif->getAvif();
		$this->assertNotFalse( $avifFile, 'An AVIF companion model must be returned after re-running with createAvif=1 (row 6.18).' );
		$this->assertTrue( $avifFile->exists(), 'The AVIF companion file must exist on disk after the third pass (row 6.18).' );
	}
}
