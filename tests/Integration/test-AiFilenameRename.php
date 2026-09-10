<?php
/**
 * AI-generated filename rename — integration tests (mocked AI API).
 *
 * Complements tests/Integration/test-ChangeFilename.php (manual rename
 * flavor) with the AI flavor: the API returns `generated_file_name`,
 * AiController::handleResponse() maps it to aiData['filebase']
 * (sanitize_text_field), and OptimizeAiController::HandleSuccess() calls
 * replaceFiles() when the (formatted) filebase is a string, longer than
 * 5 chars and different from the current file base
 * (OptimizeAiController.php rename gate).
 *
 * Coverage:
 *  - autoAI ON (upload-hook semantics: recent_upload=true) E2E rename —
 *    files, thumbnails, _wp_attached_file, metadata sizes.
 *  - autoAI OFF (manual ai/requestalt semantics: no recent_upload) must
 *    produce the SAME rename for an unreferenced image.
 *  - Bulk semantics (optimize + chained requestAlt on one item) rename too.
 *  - ai_gen_filename enabled AFTER an aipostmeta row exists is a NO-OP:
 *    AiDataModel::isProcessable() returns false (P_ALREADYDONE) — there is
 *    no field-level regeneration or filename-only bulk. FEATURE REQUEST in
 *    the dev backlog (Pedro, 2026-09-10) — NOT a numbered bug; this test
 *    documents the current no-op and will need updating when the feature
 *    lands.
 *  - Rename gate: short (<=5 chars) filebase ignored; identical filebase
 *    ignored; ai_filename_prefix/postfix are baked into the final name.
 *  - Backups follow the rename (LocalBackupModel::renameBackup) and a
 *    subsequent restore returns the ORIGINAL bytes under the NEW name
 *    (the old filename is gone for good — by design, nothing reverts it).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Model\AiDataModel;

class AiFilenameRenameTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		parent::set_up();

		$settings                  = \wpSPIO()->settings();
		$settings->enable_ai       = 1;
		$settings->ai_gen_alt      = 1;
		$settings->ai_gen_caption  = 1;
		$settings->ai_gen_filename = 1;

		$this->purgeAiData();
	}

	public function tear_down() {
		$this->purgeAiData();
		parent::tear_down();
	}

	/** Drop aipostmeta rows and the AiDataModel in-memory model cache. */
	private function purgeAiData(): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );

		$ref  = new ReflectionClass( AiDataModel::class );
		$prop = $ref->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		delete_transient( 'spio_ai_jwt_token' );
	}

	/** Upload a fixture and clear the auto-enqueued optimize item. */
	private function freshAttachment(): int {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		return $attachment_id;
	}

	/**
	 * Enqueue a requestAlt item. $recent_upload=true mirrors the autoAI
	 * upload hook (AdminController::handleAiImageUploadHook sets it for
	 * fresh uploads); false mirrors the manual ai/requestalt AJAX path.
	 */
	private function enqueueAi( int $attachment_id, bool $recent_upload ): object {
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$args       = array( 'action' => 'requestAlt' );
		if ( $recent_upload ) {
			$args['recent_upload'] = true;
		}
		return ( new QueueController() )->addItemToQueue( $imageModel, $args );
	}

	private function freshImageModel( int $attachment_id ) {
		$this->resetPluginSingletons();
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
	}

	/** Assert the complete rename landed for $new_base (disk + DB). */
	private function assertRenamedTo( int $attachment_id, string $old_file, string $new_base ): void {
		$dir = trailingslashit( dirname( $old_file ) );

		$this->assertFileDoesNotExist( $old_file, 'The old main file must be moved off disk.' );
		$this->assertFileExists( $dir . $new_base . '.jpg', 'The new main file must exist on disk.' );

		$attached = get_attached_file( $attachment_id );
		$this->assertStringContainsString( $new_base . '.jpg', $attached, '_wp_attached_file must carry the new base.' );

		$meta = wp_get_attachment_metadata( $attachment_id );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $sizeName => $sizeData ) {
			$this->assertStringContainsString( $new_base, $sizeData['file'], "Size $sizeName must reference the new base." );
			$this->assertFileExists( $dir . $sizeData['file'], "Thumbnail for $sizeName must exist under the new base." );
		}
	}

	/** All backup files currently below the backup root, one flat list of basenames. */
	private function backupBasenames(): array {
		if ( ! is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			return array();
		}
		$found    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$found[] = $file->getFilename();
			}
		}
		return $found;
	}

	// -------------------------------------------------------------------
	// autoAI ON vs OFF — both must produce the same rename
	// -------------------------------------------------------------------

	/**
	 * Auto-AI on upload: recent_upload=true (usage guard bypassed). The
	 * API's generated_file_name must land as the real filename: main file,
	 * thumbnails, _wp_attached_file and metadata sizes all renamed.
	 */
	public function test_auto_ai_upload_renames_files_to_the_generated_filename() {
		$this->api->aiFields = array( 'generated_file_name' => 'auto-seo-filebase' );

		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );
		$old_base      = pathinfo( $old_file, PATHINFO_FILENAME );

		$result = $this->enqueueAi( $attachment_id, true );
		$this->assertFalse( $result->is_error, 'AI enqueue must succeed.' );
		$this->runQueueUntilEmpty();

		$this->assertRenamedTo( $attachment_id, $old_file, 'auto-seo-filebase' );

		// The alt landed too — the rename is part of the same HandleSuccess.
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		// The pre-rename base is preserved for reference in the AI record:
		// handleNewData routes 'original_filebase' into current['filebase']
		// (AiDataModel.php:376-377) and the first record copies current →
		// original, so BOTH snapshots keep the pre-rename base.
		$aiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		// (setCurrentData stores the full filename incl. extension.)
		$this->assertSame(
			$old_base,
			pathinfo( (string) ( $aiModel->getOriginalData()['filebase'] ?? '' ), PATHINFO_FILENAME ),
			'The AI record must preserve the pre-rename filebase in its original snapshot.'
		);
	}

	/**
	 * autoAI OFF: the manual ai/requestalt path enqueues WITHOUT
	 * recent_upload, so the usage guard runs — for an image not referenced
	 * in any published content it passes, and the outcome must be the SAME
	 * rename as the auto path.
	 */
	public function test_manual_ai_request_with_autoai_off_produces_the_same_rename() {
		\wpSPIO()->settings()->autoAI = 0;
		$this->api->aiFields          = array( 'generated_file_name' => 'manual-seo-filebase' );

		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );

		$result = $this->enqueueAi( $attachment_id, false );
		$this->assertFalse( $result->is_error, 'Manual AI enqueue must succeed.' );
		$this->runQueueUntilEmpty();

		$this->assertRenamedTo( $attachment_id, $old_file, 'manual-seo-filebase' );
	}

	/**
	 * Bulk semantics: optimize + chained requestAlt on the same item (the
	 * shape Queue::prepareItems() produces when autoAIBulk is on). Both
	 * legs must land: the item is optimized AND renamed.
	 */
	public function test_bulk_style_optimize_with_chained_ai_renames_too() {
		\wpSPIO()->settings()->processThumbnails = 0;
		\wpSPIO()->settings()->autoAIBulk        = 1;
		$this->api->aiFields                     = array( 'generated_file_name' => 'bulk-seo-filebase' );

		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );

		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$qc         = new QueueController();
		$optResult  = $qc->addItemToQueue( $imageModel );
		$this->assertFalse( $optResult->is_error );
		$aiResult = $this->enqueueAi( $attachment_id, false );
		$this->assertFalse( $aiResult->is_error );

		$this->runQueueUntilEmpty( 40 );

		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'The optimization leg must complete.' );
		$this->assertRenamedTo( $attachment_id, $old_file, 'bulk-seo-filebase' );
	}

	// -------------------------------------------------------------------
	// ai_gen_filename enabled AFTER AI data exists — documented NO-OP
	// -------------------------------------------------------------------

	/**
	 * FEATURE GAP (dev backlog, unnumbered — Pedro 2026-09-10): once an
	 * aipostmeta row exists, AiDataModel::isProcessable() short-circuits
	 * to P_ALREADYDONE, so enabling ai_gen_filename afterwards and
	 * re-running AI (single or bulk) does NOT generate a filename — no
	 * field-level regeneration exists. The only workaround is undo-AI
	 * (wipes the row, costs a new credit) + fresh request.
	 *
	 * Update this test when the regeneration feature lands.
	 */
	public function test_enabling_filename_generation_after_ai_data_exists_is_a_noop() {
		// Round 1: AI WITHOUT filename generation.
		\wpSPIO()->settings()->ai_gen_filename = 0;

		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );

		$this->enqueueAi( $attachment_id, true );
		$this->runQueueUntilEmpty();

		$this->assertFileExists( $old_file, 'Round 1 must not rename (ai_gen_filename=0).' );
		$aiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, $aiModel->getStatus(), 'Precondition: the AI row exists.' );

		// Round 2: customer enables filename generation and retries.
		\wpSPIO()->settings()->ai_gen_filename = 1;
		$this->api->aiFields                   = array( 'generated_file_name' => 'late-seo-filebase' );
		$this->purgeQueueTable();

		// The model refuses: the record already exists.
		$this->purgeAiDataModelCacheOnly();
		$freshAiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$this->assertFalse( $freshAiModel->isProcessable(), 'isProcessable() must refuse: P_ALREADYDONE.' );

		$this->enqueueAi( $attachment_id, true );
		$this->runQueueUntilEmpty();

		// The documented no-op: nothing was renamed.
		$this->assertFileExists( $old_file, 'FEATURE GAP: enabling ai_gen_filename after generation must currently change nothing.' );
		$dir = trailingslashit( dirname( $old_file ) );
		$this->assertFileDoesNotExist( $dir . 'late-seo-filebase.jpg', 'No file under the late filebase may appear.' );
	}

	/** Clear only the in-memory AiDataModel cache (keep DB rows). */
	private function purgeAiDataModelCacheOnly(): void {
		$ref  = new ReflectionClass( AiDataModel::class );
		$prop = $ref->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	// -------------------------------------------------------------------
	// Rename gate specifics
	// -------------------------------------------------------------------

	/**
	 * HandleSuccess() only renames for a filebase LONGER than 5 chars
	 * (hardcoded gate). A 5-char-or-shorter generated name must be ignored.
	 */
	public function test_short_generated_filebase_is_ignored_by_the_rename_gate() {
		$this->api->aiFields = array( 'generated_file_name' => 'abcde' );

		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );

		$this->enqueueAi( $attachment_id, true );
		$this->runQueueUntilEmpty();

		$this->assertFileExists( $old_file, 'A <=5-char filebase must not trigger a rename.' );
		$this->assertFileDoesNotExist( trailingslashit( dirname( $old_file ) ) . 'abcde.jpg' );
		// The rest of the AI result still lands.
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/** A generated filebase identical to the current base must not rename. */
	public function test_identical_generated_filebase_does_not_rename() {
		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );
		$old_base      = pathinfo( $old_file, PATHINFO_FILENAME );

		$this->api->aiFields = array( 'generated_file_name' => $old_base );

		$this->enqueueAi( $attachment_id, true );
		$this->runQueueUntilEmpty();

		$this->assertFileExists( $old_file, 'An identical filebase must leave the file untouched.' );
		$this->assertStringContainsString( $old_base . '.jpg', get_attached_file( $attachment_id ) );
	}

	/**
	 * ai_filename_prefix / ai_filename_postfix are applied by
	 * formatResultData() (empty spacer for the filebase) and must be part
	 * of the final on-disk filename.
	 */
	public function test_filename_prefix_and_postfix_are_baked_into_the_renamed_file() {
		$settings                      = \wpSPIO()->settings();
		$settings->ai_filename_prefix  = 'pre-';
		$settings->ai_filename_postfix = '-post';
		$this->api->aiFields           = array( 'generated_file_name' => 'seo-core-name' );

		$attachment_id = $this->freshAttachment();
		$old_file      = get_attached_file( $attachment_id );

		$this->enqueueAi( $attachment_id, true );
		$this->runQueueUntilEmpty();

		$this->assertRenamedTo( $attachment_id, $old_file, 'pre-seo-core-name-post' );
	}

	// -------------------------------------------------------------------
	// Backups + restore after an AI rename
	// -------------------------------------------------------------------

	/**
	 * Optimize (creates backups) → AI rename → the backup files must be
	 * renamed along (LocalBackupModel::renameBackup), and a restore must
	 * return the ORIGINAL bytes under the NEW name. By design nothing
	 * reverts the filename itself (documented in undoAltData()).
	 */
	public function test_rename_moves_backups_and_restore_lands_original_bytes_under_new_name() {
		\wpSPIO()->settings()->processThumbnails = 0;
		\wpSPIO()->settings()->backupImages      = 1;
		$this->api->aiFields                     = array( 'generated_file_name' => 'restored-seo-name' );

		$attachment_id  = $this->freshAttachment();
		$old_file       = get_attached_file( $attachment_id );
		$old_base       = pathinfo( $old_file, PATHINFO_FILENAME );
		$original_bytes = file_get_contents( $old_file );

		$this->optimizeAttachment( $attachment_id );
		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'Precondition: optimized.' );

		$backupsBefore = $this->backupBasenames();
		$this->assertNotEmpty(
			preg_grep( '/^' . preg_quote( $old_base, '/' ) . '\./', $backupsBefore ),
			'Precondition: a backup under the old base exists.'
		);

		$this->purgeQueueTable();
		$this->enqueueAi( $attachment_id, true );
		$this->runQueueUntilEmpty();

		$new_file = trailingslashit( dirname( $old_file ) ) . 'restored-seo-name.jpg';
		$this->assertFileExists( $new_file, 'Precondition: the AI rename landed.' );

		// Backups were renamed along with the live files.
		$backupsAfter = $this->backupBasenames();
		$this->assertNotEmpty(
			preg_grep( '/^restored-seo-name\./', $backupsAfter ),
			'The backup must be renamed to the new base.'
		);
		$this->assertEmpty(
			preg_grep( '/^' . preg_quote( $old_base, '/' ) . '\./', $backupsAfter ),
			'No backup under the old base may remain.'
		);

		// Restore through the real queue pipeline.
		$this->purgeQueueTable();
		\wpSPIO()->filesystem()->flushImageCache();
		$imageModel = $this->freshImageModel( $attachment_id );
		( new QueueController() )->addItemToQueue( $imageModel, array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();

		// Original bytes, NEW name — the old filename is gone for good.
		$this->assertFileExists( $new_file, 'Restore must land under the renamed file.' );
		$this->assertSame( $original_bytes, file_get_contents( $new_file ), 'Restore must return the pre-optimization bytes.' );
		$this->assertFileDoesNotExist( $old_file, 'By design, restore does NOT resurrect the old filename.' );
		$this->assertFalse( $this->freshImageModel( $attachment_id )->isOptimized(), 'The attachment must be back to unoptimized.' );
	}
}
