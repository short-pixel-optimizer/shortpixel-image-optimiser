<?php
/**
 * Integration tests: backup creation + restore flows (Wave 1).
 *
 * Covers both backup modes (both live in LocalBackupController /
 * LocalBackupModel — the mode split happens inside the model):
 *   - normal backups (every file incl. thumbnails gets its own backup);
 *   - Smart Backup (`singleFileBackup`): only the main file is backed up,
 *     thumbnails are regenerated from it on restore.
 *
 * Also exercises the backup punch-list items moved over from the unit
 * suite: getModelById (via getBackupModel), getBackupBaseDirectory,
 * checkFilesinDirectory and autoRemoveBackups (via cronRemoveBackups).
 *
 * Restore runs synchronously through the queue action pipeline
 * (addItemToQueue with ['action' => 'restore'] → ActionController).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\OtherMediaController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\Backup\LocalBackupController;
use ShortPixel\Model\Backup\LocalBackupModel;
use ShortPixel\Model\File\DirectoryModel;

class RestoreFlowTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/** Run the restore action through the real queue pipeline. */
	private function restoreAttachment( int $attachment_id ): void {
		// The DONE optimize item still sits in the ShortQ table; with it
		// present, addItemToQueue() only appends 'restore' as a next_action
		// on that finished item and nothing runs. Purge first — equivalent
		// to the queue cleanup that happens between operations in production.
		$this->purgeQueueTable();

		$imageModel = $this->freshImageModel( $attachment_id );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel, array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();
	}

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

	public function tear_down() {
		// Backup files live outside the DB transaction — remove the whole
		// backup tree so one test's backups can't satisfy another test.
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
		}
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Backup creation during optimization
	// -------------------------------------------------------------------

	public function test_optimize_with_backups_creates_backup_of_main_file() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$originalSize = filesize( get_attached_file( $id ) );
		$this->optimizeAttachment( $id );

		$image  = $this->freshImageModel( $id );
		$backup = BackupController::getBackupModel( $image );
		$this->assertTrue( $backup->hasBackup( $image ), 'Optimizing with backupImages=1 must create a backup.' );

		$backupFile = $backup->getBackupFile( $image );
		$this->assertNotFalse( $backupFile );
		$this->assertSame(
			$originalSize,
			$backupFile->getFileSize(),
			'The backup must be the pre-optimization original (same byte size).'
		);
	}

	public function test_normal_backup_mode_backs_up_thumbnails_too() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'] );

		$this->optimizeAttachment( $id );

		// Main file + every generated thumbnail gets its own backup file.
		$expected = 1 + count( $metadata['sizes'] );
		$this->assertCount(
			$expected,
			$this->backupFilesOnDisk(),
			'Normal backup mode must back up the main file and each thumbnail.'
		);
	}

	// -------------------------------------------------------------------
	// Restore — normal backups
	// -------------------------------------------------------------------

	public function test_restore_reverts_main_file_to_original_bytes() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$path         = get_attached_file( $id );
		$originalSize = filesize( $path );

		$this->optimizeAttachment( $id );
		clearstatcache();
		$this->assertLessThan( $originalSize, filesize( $path ), 'Sanity: optimization must shrink the file first.' );

		$this->restoreAttachment( $id );

		clearstatcache();
		$this->assertSame(
			$originalSize,
			filesize( $path ),
			'Restore must bring back the original file bytes.'
		);

		$image = $this->freshImageModel( $id );
		$this->assertFalse( $image->isOptimized(), 'Image must no longer be marked optimized after restore.' );
	}

	public function test_restore_removes_backup_file() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$this->assertNotEmpty( $this->backupFilesOnDisk(), 'Sanity: backups exist after optimization.' );

		$this->restoreAttachment( $id );

		$remaining = $this->backupFilesOnDisk();
		$this->assertSame(
			array(),
			$remaining,
			'The backups are moved back over the optimized files, so no backup must remain after restore.'
		);
	}

	public function test_restore_removes_webp_companions() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$webp  = $image->getWebp();
		$this->assertNotFalse( $webp );
		$this->assertTrue( $webp->exists(), 'Sanity: WebP companion exists after optimization.' );
		$webpPath = $webp->getFullPath();

		$this->restoreAttachment( $id );

		clearstatcache();
		$this->assertFileDoesNotExist( $webpPath, 'Restore must delete the WebP companion of the optimized file.' );
	}

	// -------------------------------------------------------------------
	// Restore — Smart Backup (singleFileBackup)
	// -------------------------------------------------------------------

	public function test_smart_backup_only_stores_the_main_file() {
		\wpSPIO()->settings()->singleFileBackup = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture must generate thumbnails.' );

		$this->optimizeAttachment( $id );

		$this->assertCount(
			1,
			$this->backupFilesOnDisk(),
			'Smart Backup must store exactly one backup file (the main file), no thumbnail backups.'
		);
	}

	public function test_smart_backup_restore_reverts_main_file_and_regenerates_thumbnails() {
		\wpSPIO()->settings()->singleFileBackup = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$path         = get_attached_file( $id );
		$originalSize = filesize( $path );

		$this->optimizeAttachment( $id );
		$this->restoreAttachment( $id );

		clearstatcache();
		$this->assertSame( $originalSize, filesize( $path ), 'Smart Backup restore must revert the main file.' );

		// Thumbnails had no backup of their own — they must be regenerated
		// from the restored main file and exist on disk again.
		$metadata = wp_get_attachment_metadata( $id );
		$dir      = dirname( $path );
		foreach ( $metadata['sizes'] as $sizeName => $sizeData ) {
			$this->assertFileExists(
				$dir . '/' . $sizeData['file'],
				"Thumbnail '$sizeName' must exist after a Smart Backup restore (regenerated)."
			);
		}

		$image = $this->freshImageModel( $id );
		$this->assertFalse( $image->isOptimized() );
	}

	// -------------------------------------------------------------------
	// Punch list: getModelById (via getBackupModel)
	// -------------------------------------------------------------------

	public function test_getBackupModel_returns_local_model_and_caches_per_id() {
		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->freshImageModel( $id );

		$model = BackupController::getBackupModel( $image );
		$this->assertInstanceOf( LocalBackupModel::class, $model );

		$again = BackupController::getBackupModel( $this->freshImageModel( $id ) );
		$this->assertSame( $model, $again, 'getModelById must cache the BackupModel per attachment id.' );
	}

	public function test_getBackupModel_rejects_thumbnail_level_objects() {
		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->freshImageModel( $id );

		$thumbnails = $image->get( 'thumbnails' );
		$this->assertNotEmpty( $thumbnails );

		$this->expectException( \Exception::class );
		BackupController::getBackupModel( array_values( $thumbnails )[0] );
	}

	// -------------------------------------------------------------------
	// Punch list: getBackupBaseDirectory
	// -------------------------------------------------------------------

	public function test_getBackupBaseDirectory_mirrors_uploads_below_backup_root() {
		\wpSPIO()->settings()->backupImages = 1;
		$controller = BackupController::getBackupController();
		$this->assertInstanceOf( LocalBackupController::class, $controller );

		$method = new ReflectionMethod( LocalBackupController::class, 'getBackupBaseDirectory' );
		$method->setAccessible( true );
		$dir = $method->invoke( $controller );

		$this->assertInstanceOf( DirectoryModel::class, $dir );
		$this->assertStringContainsString( 'ShortpixelBackups', $dir->getPath() );
	}

	// -------------------------------------------------------------------
	// Punch list: checkFilesinDirectory
	// -------------------------------------------------------------------

	public function test_checkFilesinDirectory_prunes_by_creation_date_cutoff() {
		// The filter compares file CREATION time (ctime), which cannot be
		// faked with touch() on Linux — so test both filter directions with
		// cutoffs around "now" instead of faking old files.
		$base = SHORTPIXEL_BACKUP_FOLDER . '/prune-test';
		wp_mkdir_p( $base );

		$file = $base . '/candidate.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $file );

		$controller = BackupController::getBackupController();
		$method     = new ReflectionMethod( LocalBackupController::class, 'checkFilesinDirectory' );
		$method->setAccessible( true );
		$dir = \wpSPIO()->filesystem()->getDirectory( $base );

		// Cutoff in the past: a just-created file is NOT older — must survive.
		$method->invoke( $controller, $dir, time() - DAY_IN_SECONDS );
		clearstatcache();
		$this->assertFileExists( $file, 'Files created after the cutoff must survive.' );

		// Cutoff in the future: the file IS older than the cutoff — pruned.
		$method->invoke( $controller, $dir, time() + DAY_IN_SECONDS );
		clearstatcache();
		$this->assertFileDoesNotExist( $file, 'Files created before the cutoff must be pruned.' );

		rmdir( $base );
	}

	// -------------------------------------------------------------------
	// Punch list: autoRemoveBackups (via cronRemoveBackups)
	// -------------------------------------------------------------------

	public function test_cronRemoveBackups_is_noop_without_settings() {
		$controller = BackupController::getBackupController();
		$this->assertFalse(
			$controller->cronRemoveBackups(),
			'cronRemoveBackups must refuse to run when autoRemoveBackups / period are not configured.'
		);
	}

	// -------------------------------------------------------------------
	// Wave-3 additions (rows 7.3, 7.5, 7.6, 8.3)
	// -------------------------------------------------------------------

	/**
	 * Optimizing a custom-media image stores the backup under
	 * SHORTPIXEL_BACKUP_FOLDER mirroring the custom folder's path relative
	 * to the WordPress root.
	 * Manual plan row 7.3.
	 */
	public function test_custom_media_backup_path_mirrors_source_structure() {
		\wpSPIO()->settings()->backupImages = 1;

		// Set up a custom folder inside wp-content.
		global $wpdb;
		foreach ( array( 'shortpixel_folders', 'shortpixel_meta' ) as $tbl ) {
			$t = $wpdb->prefix . $tbl;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
				$wpdb->query( "DELETE FROM `$t`" );
			}
		}

		$customDir = trailingslashit( WP_CONTENT_DIR ) . 'spio-backup-test-' . wp_generate_password( 8, false ) . '/';
		mkdir( $customDir );
		$src = $this->fixturePath( 'fixture-small.jpg' );
		copy( $src, $customDir . 'backup-test.jpg' );

		try {
			$folder = \ShortPixel\Controller\OtherMediaController::getInstance()->addDirectory( $customDir );
			$this->assertNotFalse( $folder, 'Custom folder must be registered.' );

			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}shortpixel_meta WHERE folder_id = %d", (int) $folder->get( 'id' ) )
			);
			$this->assertNotEmpty( $rows, 'Image must be scanned into shortpixel_meta.' );
			$customId = (int) $rows[0]->id;

			$image = \wpSPIO()->filesystem()->getImage( $customId, 'custom' );
			( new QueueController() )->addItemToQueue( $image );
			$this->runQueueUntilEmpty();

			$optimized = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$this->assertTrue( $optimized->isOptimized(), 'Precondition: custom image must be optimized.' );

			// The backup must live under SHORTPIXEL_BACKUP_FOLDER with a path
			// that mirrors the custom folder's location relative to the WP root.
			$backup = \ShortPixel\Controller\Backup\BackupController::getBackupModel( $optimized );
			$this->assertTrue( $backup->hasBackup( $optimized ), 'A backup must exist for the optimized custom image (row 7.3).' );

			$backupFile = $backup->getBackupFile( $optimized );
			$this->assertNotFalse( $backupFile, 'getBackupFile() must return a FileModel (row 7.3).' );

			$backupPath = $backupFile->getFullPath();
			$this->assertStringContainsString(
				SHORTPIXEL_BACKUP_FOLDER,
				$backupPath,
				'The backup must live under SHORTPIXEL_BACKUP_FOLDER (row 7.3).'
			);

			// The path segment after the backup root must mirror the custom folder
			// path relative to the WordPress root.
			$fs          = \wpSPIO()->filesystem();
			$fileDir     = $fs->getDirectory( $customDir );
			$relativePart = $fileDir->getRelativePath();
			$this->assertNotFalse( $relativePart, 'getRelativePath() must resolve for the custom dir (row 7.3).' );
			$this->assertStringContainsString(
				trim( $relativePart, '/' ),
				$backupPath,
				'The backup path must mirror the custom folder path under SHORTPIXEL_BACKUP_FOLDER (row 7.3).'
			);
		} finally {
			@unlink( $customDir . 'backup-test.jpg' );
			@rmdir( $customDir );
		}
	}

	/**
	 * Disabling backups (backupImages=0) after a first optimization run stops
	 * new backup files being created, but backups from the previous run survive.
	 * Manual plan row 7.5.
	 */
	public function test_disabling_backups_preserves_previously_created_backups() {
		// --- First run with backups ON. ---
		\wpSPIO()->settings()->backupImages = 1;
		$id1 = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id1 );

		$backupsAfterFirstRun = $this->backupFilesOnDisk();
		$this->assertNotEmpty( $backupsAfterFirstRun, 'Precondition: backup files must exist after first optimization (row 7.5).' );

		// --- Second run with backups OFF. ---
		// Reset FIRST: resetPluginSingletons() reloads SettingsModel from the
		// DB and would wipe the unsaved in-memory backupImages write.
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->backupImages = 0;

		$id2 = $this->uploadFixture( 'fixture-small.png' );
		$this->optimizeAttachment( $id2 );

		// Backups from run 1 must still be there.
		$backupsAfterSecondRun = $this->backupFilesOnDisk();
		foreach ( $backupsAfterFirstRun as $expected ) {
			$this->assertContains(
				$expected,
				$backupsAfterSecondRun,
				"Backup file $expected from the first run must survive after disabling backups (row 7.5)."
			);
		}

		// No NEW backup must appear for the second attachment.
		$newBackups = array_diff( $backupsAfterSecondRun, $backupsAfterFirstRun );
		$this->assertEmpty(
			$newBackups,
			'No new backup files must be created when backupImages=0 (row 7.5). New files: ' . implode( ', ', $newBackups )
		);
	}

	/**
	 * When the backup folder exists but is not writable, the optimizer produces
	 * an error or warning and does NOT mark the image as optimized.
	 * Manual plan row 7.6.
	 *
	 * Note: skipped when running as root (root ignores chmod).
	 */
	public function test_nonwritable_backup_folder_produces_error_not_crash() {
		if ( function_exists( 'posix_getuid' ) && posix_getuid() === 0 ) {
			$this->markTestSkipped( 'Running as root; chmod 0555 has no effect.' );
		}

		\wpSPIO()->settings()->backupImages = 1;

		// Create the backup folder and then strip write permission.
		wp_mkdir_p( SHORTPIXEL_BACKUP_FOLDER );
		chmod( SHORTPIXEL_BACKUP_FOLDER, 0555 );

		try {
			$id    = $this->uploadFixture( 'fixture-small.jpg' );
			$image = \wpSPIO()->filesystem()->getImage( $id, 'media' );
			( new QueueController() )->addItemToQueue( $image );

			// The queue must drain without an uncaught exception.
			$this->runQueueUntilEmpty();

			// The image must NOT be marked as optimized because backup creation failed.
			$reloaded = $this->freshImageModel( $id );
			$this->assertFalse(
				$reloaded->isOptimized(),
				'With a non-writable backup folder the image must NOT be marked as optimized (row 7.6).'
			);
		} finally {
			// Restore permissions so tear_down() can remove the backup folder.
			chmod( SHORTPIXEL_BACKUP_FOLDER, 0755 );
		}
	}

	/**
	 * Bulk-restore via BulkController::createNewBulk('media', ['customOp' => 'bulk-restore'])
	 * followed by a bulk run reverts all optimized images and empties the backup folder.
	 * Manual plan row 8.3.
	 */
	public function test_bulk_restore_reverts_all_images_and_empties_backups() {
		\wpSPIO()->settings()->backupImages = 1;
		\wpSPIO()->settings()->processThumbnails = 0; // Keep the run bounded.

		$id1 = $this->uploadFixture( 'fixture-small.jpg' );
		$id2 = $this->uploadFixture( 'fixture-small.png' );

		$this->optimizeAttachment( $id1 );
		$this->optimizeAttachment( $id2 );
		$this->purgeQueueTable();

		$this->assertTrue( $this->freshImageModel( $id1 )->isOptimized(), 'Precondition: attachment 1 must be optimized.' );
		$this->assertTrue( $this->freshImageModel( $id2 )->isOptimized(), 'Precondition: attachment 2 must be optimized.' );
		$this->assertNotEmpty( $this->backupFilesOnDisk(), 'Precondition: backup files must exist.' );

		// Create + start the bulk-restore run and drive it to completion.
		$bulkController = \ShortPixel\Controller\BulkController::getInstance();
		$bulkController->createNewBulk( 'media', array( 'customOp' => 'bulk-restore' ) );

		// Preparation phase MUST run before startBulk: Queue::run() only calls
		// prepareBulkRestore() (which enqueues the restore items) while the
		// queue status is 'preparing'; startBulk() ends that phase.
		$bulkQueue = new QueueController( array( 'is_bulk' => true ) );
		for ( $tick = 0; $tick < 30; $tick++ ) {
			$bulkQueue->processQueue( array( 'media' ) );
			$stats = $bulkQueue->getQueue( 'media' )->getStats();
			if ( false === $stats->is_preparing ) {
				break;
			}
		}

		$bulkController->startBulk( array( 'media' ) );

		// Drive the bulk queue until done (same backdate-based loop used in CLI tests).
		$maxTicks  = 30;
		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$bulkQueue->processQueue( array( 'media' ) );

			$stats = $bulkQueue->getQueue( 'media' )->getStats();
			$hasWork = is_object( $stats ) && ( $stats->in_queue > 0 || $stats->in_process > 0 );
			if ( ! $hasWork ) {
				break;
			}
			$this->backdateQueueItems();
		}

		clearstatcache();
		$this->assertFalse(
			$this->freshImageModel( $id1 )->isOptimized(),
			'Attachment 1 must be reverted to unoptimized after bulk-restore (row 8.3).'
		);
		$this->assertFalse(
			$this->freshImageModel( $id2 )->isOptimized(),
			'Attachment 2 must be reverted to unoptimized after bulk-restore (row 8.3).'
		);

		// All backup files should be gone (moved back to their live locations).
		$remaining = $this->backupFilesOnDisk();
		$this->assertEmpty(
			$remaining,
			'After bulk-restore all backup files must have been moved back; none should remain (row 8.3). Remaining: ' . implode( ', ', $remaining )
		);
	}

	// -------------------------------------------------------------------
	// Rows 19.3 (Envira) + 20.3 (Soliloquy): unlisted suffix thumbnails
	// -------------------------------------------------------------------

	/**
	 * End-to-end: an Envira/Soliloquy-style unlisted thumbnail (suffix _c,
	 * name like `<base>-300x200_c.jpg`) is optimized alongside the main
	 * image and then correctly restored.
	 *
	 * Mechanism: the `shortpixel/image/unlisted_suffixes` filter adds `_c`
	 * so that MediaLibraryModel::addUnlisted() picks up the sibling file.
	 * No real Envira/Soliloquy plugin is needed — both integrations share
	 * the same code path through ImageGalleries::envira_suffixes().
	 *
	 * Both plugins use the same suffix set (_c, _tl, _tr, _br, _bl) and
	 * the same MediaLibraryModel detection logic; one test covers both rows.
	 *
	 * NOTE — $unlistedChecked flush requirement (not a production bug):
	 * MediaLibraryModel keeps a static $unlistedChecked array that memoises
	 * which attachment IDs have already been scanned for unlisted files.  In
	 * a real installation every HTTP request starts with an empty static, so
	 * the optimize and restore requests always see a fresh scan.  In the
	 * test suite both actions run in the same process, so we must call
	 * flushImageCache() between optimize and restore to reset the static —
	 * exactly what ActionController::reoptimizeItem() does in production.
	 *
	 * @covers ShortPixel\ImageGalleries::envira_suffixes
	 * @covers ShortPixel\Model\Image\MediaLibraryModel::addUnlisted
	 * Covers manual plan rows 19.3 (Envira) and 20.3 (Soliloquy).
	 */
	public function test_unlisted_thumbnail_with_envira_suffix_is_optimized_and_restored() {
		\wpSPIO()->settings()->backupImages = 1;

		// Mirror ImageGalleries::envira_suffixes() without requiring the plugin.
		$addSuffixes = function ( $suffixes ) {
			return array_merge( $suffixes, array( '_c', '_tl', '_tr', '_br', '_bl' ) );
		};
		add_filter( 'shortpixel/image/unlisted_suffixes', $addSuffixes );

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// The fixture is 3200×2400 — WP creates a -scaled main file and keeps
		// fixture-small.jpg as the unscaled original.  Envira/Soliloquy crops
		// are generated from the original, so the companion lives beside it and
		// shares the same basename.  addUnlisted() scans processFiles = [main,
		// original] when isScaled(), so the companion is detected either way.
		$originalPath = function_exists( 'wp_get_original_image_path' )
			? wp_get_original_image_path( $id )
			: get_attached_file( $id );

		$uploadDir  = dirname( $originalPath );
		$base       = pathinfo( $originalPath, PATHINFO_FILENAME );
		$ext        = pathinfo( $originalPath, PATHINFO_EXTENSION );

		// Name matches the suffix-pattern: /^<base>-\d+x\d+_c\.<ext>/
		$cFileName = $base . '-300x200_c.' . $ext;
		$cPath     = $uploadDir . '/' . $cFileName;

		// Copy the fixture into place to simulate a gallery-generated crop.
		copy( $this->fixturePath( 'fixture-small.jpg' ), $cPath );

		$originalCSize = filesize( $cPath );
		$this->assertGreaterThan( 0, $originalCSize, 'Sanity: companion file must exist and be non-empty.' );

		try {
			// Auto-enqueue from upload fires before the companion exists.
			// Purge so our explicit optimize below is the first (and only) run,
			// and the companion file is already in place when the model loads.
			$this->purgeQueueTable();

			// Flush the image cache to reset MediaLibraryModel::$unlistedChecked,
			// ensuring addUnlisted() runs fresh and picks up the companion.
			\wpSPIO()->filesystem()->flushImageCache();

			$this->optimizeAttachment( $id );

			// --- Post-optimize assertions ---

			// The companion must have been shrunk by the mock optimizer.
			clearstatcache();
			$this->assertLessThan(
				$originalCSize,
				filesize( $cPath ),
				'Sanity (rows 19.3/20.3): mock optimizer must have shrunk the _c companion; if unchanged, addUnlisted() did not detect it — check suffix filter and file-name pattern.'
			);

			// The companion must have its own backup file.
			// Flush again so the freshly loaded model sees the companion.
			\wpSPIO()->filesystem()->flushImageCache();
			$image  = $this->freshImageModel( $id );
			$thumbs = $image->get( 'thumbnails' );

			$this->assertArrayHasKey(
				$cFileName,
				$thumbs,
				'After optimize the freshly loaded model must include the _c companion in its thumbnails (rows 19.3/20.3).'
			);

			$cThumb  = $thumbs[ $cFileName ];
			$backup  = BackupController::getBackupModel( $image );

			$this->assertTrue(
				$backup->hasBackup( $cThumb ),
				'A backup must exist for the _c companion after optimization (rows 19.3/20.3).'
			);

			// Capture the backup-file path now — after restore the file is gone
			// and hasBackup() / getBackupFile() caches stale state; assert on the
			// raw filesystem path instead (established pattern in this suite).
			$cBackupFileObj = $backup->getBackupFile( $cThumb );
			$this->assertNotFalse(
				$cBackupFileObj,
				'getBackupFile() must return a FileModel for the _c companion (rows 19.3/20.3).'
			);
			$cBackupPath = $cBackupFileObj->getFullPath();
			clearstatcache();
			$this->assertFileExists(
				$cBackupPath,
				'The _c companion backup must physically exist on disk (rows 19.3/20.3).'
			);

			// --- Restore ---

			// Flush before restore so the queue-item model re-discovers the
			// companion (same reset that reoptimizeItem() applies in production).
			\wpSPIO()->filesystem()->flushImageCache();
			$this->restoreAttachment( $id );

			// --- Post-restore assertions ---

			// Companion bytes must be back to the pre-optimization size.
			clearstatcache();
			$this->assertSame(
				$originalCSize,
				filesize( $cPath ),
				'Restore must revert the _c companion to its original byte count (rows 19.3/20.3).'
			);

			// The backup file must be gone (moved back over the optimized copy).
			clearstatcache();
			$this->assertFileDoesNotExist(
				$cBackupPath,
				'The _c companion backup must be gone after restore (rows 19.3/20.3).'
			);

		} finally {
			remove_filter( 'shortpixel/image/unlisted_suffixes', $addSuffixes );
			// The companion is not tracked by WP, so wp_delete_attachment() won't
			// remove it — clean up manually to avoid leaking between test runs.
			@unlink( $cPath );
		}
	}

	/**
	 * Lightweight variation: verifies the same end-to-end flow for the `_tl`
	 * (top-left crop) suffix, confirming that all five Envira/Soliloquy
	 * position suffixes work, not just `_c`.
	 *
	 * Covers manual plan rows 19.3 (Envira) and 20.3 (Soliloquy).
	 */
	public function test_unlisted_thumbnail_tl_suffix_is_restored_correctly() {
		\wpSPIO()->settings()->backupImages = 1;

		$addSuffixes = function ( $suffixes ) {
			return array_merge( $suffixes, array( '_c', '_tl', '_tr', '_br', '_bl' ) );
		};
		add_filter( 'shortpixel/image/unlisted_suffixes', $addSuffixes );

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$originalPath = function_exists( 'wp_get_original_image_path' )
			? wp_get_original_image_path( $id )
			: get_attached_file( $id );

		$uploadDir   = dirname( $originalPath );
		$base        = pathinfo( $originalPath, PATHINFO_FILENAME );
		$ext         = pathinfo( $originalPath, PATHINFO_EXTENSION );

		$tlFileName = $base . '-400x300_tl.' . $ext;
		$tlPath     = $uploadDir . '/' . $tlFileName;

		copy( $this->fixturePath( 'fixture-small.jpg' ), $tlPath );
		$originalTlSize = filesize( $tlPath );

		try {
			$this->purgeQueueTable();
			\wpSPIO()->filesystem()->flushImageCache();
			$this->optimizeAttachment( $id );

			clearstatcache();
			$this->assertLessThan(
				$originalTlSize,
				filesize( $tlPath ),
				'Sanity (rows 19.3/20.3 _tl variant): optimizer must shrink the _tl companion.'
			);

			\wpSPIO()->filesystem()->flushImageCache();
			$image  = $this->freshImageModel( $id );
			$thumbs = $image->get( 'thumbnails' );
			$this->assertArrayHasKey( $tlFileName, $thumbs, '_tl companion must appear in model thumbnails after flush.' );

			$tlThumb       = $thumbs[ $tlFileName ];
			$backup        = BackupController::getBackupModel( $image );
			$tlBackupFile  = $backup->getBackupFile( $tlThumb );
			$this->assertNotFalse( $tlBackupFile, 'Backup file must exist for the _tl companion.' );
			$tlBackupPath  = $tlBackupFile->getFullPath();

			clearstatcache();
			$this->assertFileExists( $tlBackupPath, '_tl companion backup must be on disk before restore.' );

			\wpSPIO()->filesystem()->flushImageCache();
			$this->restoreAttachment( $id );

			clearstatcache();
			$this->assertSame(
				$originalTlSize,
				filesize( $tlPath ),
				'Restore must revert the _tl companion to its original byte count (rows 19.3/20.3).'
			);

			clearstatcache();
			$this->assertFileDoesNotExist(
				$tlBackupPath,
				'The _tl companion backup must be gone after restore (rows 19.3/20.3).'
			);

		} finally {
			remove_filter( 'shortpixel/image/unlisted_suffixes', $addSuffixes );
			@unlink( $tlPath );
		}
	}

	public function test_cronRemoveBackups_prunes_old_year_directories() {
		$settings                          = \wpSPIO()->settings();
		$settings->autoRemoveBackups       = true;
		$settings->autoRemoveBackupsPeriod = 'month';

		$controller = BackupController::getBackupController();
		$this->assertInstanceOf( LocalBackupController::class, $controller );

		// The year/month tree lives below the uploads-relative base dir.
		$method = new ReflectionMethod( LocalBackupController::class, 'getBackupBaseDirectory' );
		$method->setAccessible( true );
		$basePath = rtrim( $method->invoke( $controller )->getPath(), '/' );

		$oldYearDir = $basePath . '/2020/03';
		wp_mkdir_p( $oldYearDir );
		$ancientFile = $oldYearDir . '/ancient.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $ancientFile );

		$emptyYearDir = $basePath . '/2019';
		wp_mkdir_p( $emptyYearDir );

		$currentDir = $basePath . '/' . gmdate( 'Y' ) . '/' . gmdate( 'm' );
		wp_mkdir_p( $currentDir );
		$recentFile = $currentDir . '/recent.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $recentFile );

		// PHP warns on the failed rmdir (see pinned assertion below); don't
		// let the WP test framework convert that warning into a failure.
		@$controller->cronRemoveBackups();

		clearstatcache();
		$this->assertDirectoryDoesNotExist( $emptyYearDir, 'Empty year directories before the cutoff are removed.' );
		$this->assertFileExists( $recentFile, 'Backups from the current month must survive the prune.' );

		// PINNED BUG (sentinel): autoRemoveBackups() deletes old year dirs
		// with DirectoryModel::delete() — a plain, non-recursive rmdir() —
		// which fails on any populated year directory. Old backups are
		// therefore never actually removed on real installs. When this is
		// fixed (recursiveDelete or equivalent), this assertion will fail:
		// flip it to assertDirectoryDoesNotExist and drop the pin.
		$this->assertFileExists(
			$ancientFile,
			'Pinned current behavior: populated pre-cutoff year dirs survive because rmdir() is non-recursive. If this fails, the bug was fixed — update the test.'
		);

		unlink( $ancientFile );
	}
}
