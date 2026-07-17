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
