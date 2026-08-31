<?php
/**
 * AjaxController handler tests — the data-mutating handlers BEHIND the
 * security gates.
 *
 * test-AjaxEndpoint.php proves the gates (nonce, capability) fire; these
 * tests prove the handlers behind them do the right thing when the gates
 * pass: item actions (optimize/restore/reoptimize/cancel/mark), settings
 * import/export and the backup-removal tool, each through the REAL
 * wp_ajax_shortpixel_ajaxRequest dispatch and, where applicable, the real
 * queue + mocked API pipeline.
 *
 * Also covers per-handler authorization: capability ESCALATION inside the
 * dispatcher (author passes the outer is_author gate but must be stopped by
 * inner is_editor / is_admin_user checks) and per-image access control.
 *
 * Not covered here: toolsRemoveAll (hardUninstall would drop the tables for
 * the rest of the run) and the bulk lifecycle (BulkOptimization suite).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Controller\QueueController;

class AjaxHandlersTest extends SPIO_AjaxTestCase {

	/**
	 * Fire a shortpixel_ajaxRequest screen action as the current user with a
	 * valid outer nonce. Handler-specific fields are merged into $_POST (and
	 * mirrored to $_REQUEST, which PHP would normally populate itself).
	 */
	private function doScreenAction( string $screen_action, array $fields = array() ): ?object {
		$_POST = array_merge(
			array(
				'nonce'         => wp_create_nonce( 'ajax_request' ),
				'screen_action' => $screen_action,
			),
			$fields
		);
		$_REQUEST = $_POST;

		return $this->doAjax( 'shortpixel_ajaxRequest' );
	}

	/** Fresh (uncached) image model for an attachment. */
	private function freshImageModel( int $attachment_id ) {
		$this->resetPluginSingletons();
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
	}

	// -------------------------------------------------------------------
	// Item actions
	// -------------------------------------------------------------------

	public function test_optimize_item_enqueues_and_the_queue_optimizes_it() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		// Uploads auto-enqueue (autoMediaLibrary=1); purge so the queue state
		// observed below is attributable to the ajax call alone.
		$this->purgeQueueTable();

		$response = $this->doScreenAction(
			'optimizeItem',
			array(
				'id'   => $attachment_id,
				'type' => 'media',
			)
		);

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertIsArray( $response->media->results );
		$this->assertNotEmpty( $response->media->results );
		$this->assertTrue( $this->queueHasWork(), 'optimizeItem must have enqueued the item' );

		$this->runQueueUntilEmpty();

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertTrue( $imageModel->isOptimized(), 'The ajax-enqueued item must come out optimized' );
		$this->assertNotEmpty( $this->api->requests, 'Optimization must have gone through the (mocked) API' );
	}

	public function test_optimize_item_rejects_nonexistent_image() {
		$this->_setRole( 'administrator' );

		$response = $this->doScreenAction(
			'optimizeItem',
			array(
				'id'   => 99999999,
				'type' => 'media',
			)
		);

		$this->assertIsObject( $response );
		$this->assertSame( AjaxController::NO_ACCESS, $response->error );
		$this->assertFalse( $response->status );
	}

	public function test_author_cannot_optimize_another_users_image() {
		// Upload as one user…
		$this->_setRole( 'administrator' );
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		// …then attack as an author (passes the outer is_author gate).
		$this->_setRole( 'author' );

		$response = $this->doScreenAction(
			'optimizeItem',
			array(
				'id'   => $attachment_id,
				'type' => 'media',
			)
		);

		$this->assertIsObject( $response );
		$this->assertSame(
			AjaxController::NO_ACCESS,
			$response->error,
			'Per-image access control must stop authors from optimizing images they cannot edit'
		);
		$this->assertFalse( $this->queueHasWork(), 'Nothing may be enqueued for a denied request' );
	}

	public function test_mark_completed_prevents_further_optimization() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$response = $this->doScreenAction(
			'markCompleted',
			array(
				'id'   => $attachment_id,
				'type' => 'media',
			)
		);

		$this->assertIsObject( $response );
		$this->assertTrue( $response->status );
		$this->assertTrue( $response->media->results[0]->is_done );

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertNotFalse(
			$imageModel->isOptimizePrevented(),
			'markCompleted must persist the prevented state on the image'
		);
	}

	public function test_unmark_completed_reenables_optimization() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$this->doScreenAction( 'markCompleted', array( 'id' => $attachment_id, 'type' => 'media' ) );
		$this->assertNotFalse( $this->freshImageModel( $attachment_id )->isOptimizePrevented(), 'Precondition: marked' );

		$response = $this->doScreenAction( 'unMarkCompleted', array( 'id' => $attachment_id, 'type' => 'media' ) );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->status );
		$this->assertFalse(
			$this->freshImageModel( $attachment_id )->isOptimizePrevented(),
			'unMarkCompleted must clear the prevented state'
		);
	}

	public function test_cancel_optimize_drops_the_item_from_the_queue() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$imageModel      = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );
		$this->assertTrue( $this->queueHasWork(), 'Precondition: item queued' );

		$response = $this->doScreenAction( 'cancelOptimize', array( 'id' => $attachment_id, 'type' => 'media' ) );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->status );

		// dropItem() deletes the queue ROW but does not decrement the cached
		// shortqwp_* item counter (that self-heals on the next queue tick), so
		// queueHasWork() would report a phantom item here. Assert the real
		// contract: the item is gone from the queue.
		$queueController = new QueueController();
		$this->assertFalse(
			$queueController->getQueue( 'media' )->isItemInQueue( $attachment_id ),
			'cancelOptimize must drop the queued item'
		);

		$this->assertFalse(
			$this->freshImageModel( $attachment_id )->isOptimized(),
			'A cancelled item must not end up optimized'
		);
	}

	public function test_restore_item_restores_an_optimized_image() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $attachment_id );
		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'Precondition: optimized' );

		// DONE queue rows would swallow the restore's next_action.
		$this->purgeQueueTable();

		$response = $this->doScreenAction( 'restoreItem', array( 'id' => $attachment_id, 'type' => 'media' ) );

		$this->assertIsObject( $response );
		$this->assertTrue( $response->status );

		$this->runQueueUntilEmpty();

		$this->assertFalse(
			$this->freshImageModel( $attachment_id )->isOptimized(),
			'restoreItem must return the image to its unoptimized state'
		);
	}

	public function test_reoptimize_item_runs_the_pipeline_again() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $attachment_id );
		$this->purgeQueueTable();

		$requests_before = count( $this->api->requests );

		$response = $this->doScreenAction(
			'reOptimizeItem',
			array(
				'id'              => $attachment_id,
				'type'            => 'media',
				'compressionType' => 2,
			)
		);

		$this->assertIsObject( $response );
		$this->assertTrue( $response->status );
		$this->assertTrue( $this->queueHasWork(), 'reOptimizeItem must enqueue the item again' );

		$this->runQueueUntilEmpty();

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertTrue( $imageModel->isOptimized(), 'The image must be optimized again after reoptimize' );
		$this->assertGreaterThan(
			$requests_before,
			count( $this->api->requests ),
			'Reoptimization must reach the API again'
		);
	}

	// -------------------------------------------------------------------
	// Settings import / export
	// -------------------------------------------------------------------

	public function test_export_settings_returns_decodable_settings_json() {
		$this->_setRole( 'administrator' );

		$response = $this->doScreenAction(
			'settings/importexport',
			array(
				'type'       => 'settings',
				'actionType' => 'export',
			)
		);

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->status );

		$export = json_decode( $response->settings->exportData, true );
		$this->assertIsArray( $export, 'exportData must be valid JSON' );
		$this->assertArrayHasKey( 'compressionType', $export );
	}

	public function test_import_settings_applies_known_values() {
		$this->_setRole( 'administrator' );

		$this->assertEquals( 1, \wpSPIO()->settings()->compressionType, 'Baseline default' );

		$response = $this->doScreenAction(
			'settings/importexport',
			array(
				'type'       => 'settings',
				'actionType' => 'import',
				'importData' => json_encode( array( 'compressionType' => 2 ) ),
			)
		);

		$this->assertIsObject( $response );
		$this->assertTrue( $response->status );
		$this->assertFalse( $response->settings->results->is_error ?? false );

		$this->assertEquals( 2, \wpSPIO()->settings()->compressionType, 'Imported value must be applied' );
	}

	// -------------------------------------------------------------------
	// Capability escalation inside the dispatcher
	// -------------------------------------------------------------------

	public function test_author_is_blocked_from_admin_level_actions() {
		$this->_setRole( 'author' );

		$response = $this->doScreenAction(
			'toolsRemoveBackup',
			array( 'tools-nonce' => wp_create_nonce( 'empty-backup' ) )
		);

		$this->assertIsObject( $response );
		$this->assertSame(
			AjaxController::NO_ACCESS,
			$response->error,
			'toolsRemoveBackup requires is_admin_user — the outer is_author gate is not enough'
		);
	}

	public function test_author_is_blocked_from_editor_level_actions() {
		$this->_setRole( 'author' );

		$response = $this->doScreenAction( 'createBulk', array() );

		$this->assertIsObject( $response );
		$this->assertSame(
			AjaxController::NO_ACCESS,
			$response->error,
			'createBulk requires is_editor — the outer is_author gate is not enough'
		);
	}

	// -------------------------------------------------------------------
	// Backup-removal tool
	// -------------------------------------------------------------------

	public function test_remove_backup_requires_the_secondary_tools_nonce() {
		$this->_setRole( 'administrator' );
		// Since 4acf1395 (#37) the tools are gated on is_super_admin →
		// manage_network, which single-site admins lack (see pin44 below).
		wp_get_current_user()->add_cap( 'manage_network' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $attachment_id );
		$this->assertTrue( is_dir( SHORTPIXEL_BACKUP_FOLDER ), 'Precondition: backups exist' );

		$response = $this->doScreenAction(
			'toolsRemoveBackup',
			array(
				'type'        => 'settings',
				'tools-nonce' => 'garbage',
			)
		);

		$this->assertIsObject( $response );
		$this->assertStringContainsString( 'Invalid Nonce', $response->settings->results );
		$this->assertTrue( is_dir( SHORTPIXEL_BACKUP_FOLDER ), 'Backups must survive a bad secondary nonce' );
	}

	public function test_remove_backup_deletes_the_backup_folder() {
		$this->_setRole( 'administrator' );
		wp_get_current_user()->add_cap( 'manage_network' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $attachment_id );
		$this->assertTrue( is_dir( SHORTPIXEL_BACKUP_FOLDER ), 'Precondition: backups exist' );

		$response = $this->doScreenAction(
			'toolsRemoveBackup',
			array(
				'type'        => 'settings',
				'tools-nonce' => wp_create_nonce( 'empty-backup' ),
			)
		);

		$this->assertIsObject( $response );
		$this->assertStringContainsString( 'removed', $response->settings->results );
		$this->assertFalse( is_dir( SHORTPIXEL_BACKUP_FOLDER ), 'The backup folder must be gone' );
	}

	public function test_pin44_single_site_administrator_cannot_remove_backups() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $attachment_id );
		$this->assertTrue( is_dir( SHORTPIXEL_BACKUP_FOLDER ), 'Precondition: backups exist' );

		$response = $this->doScreenAction(
			'toolsRemoveBackup',
			array(
				'type'        => 'settings',
				'tools-nonce' => wp_create_nonce( 'empty-backup' ),
			)
		);

		$this->assertIsObject( $response );
		$this->assertSame(
			AjaxController::NO_ACCESS,
			$response->error,
			'PINNED BUG #44: since 4acf1395 (#37) toolsRemoveBackup/toolsRemoveAll require is_super_admin → the raw manage_network cap, which single-site administrators never have — so on single-site installs nobody can use the Remove backups / Remove all data tools, while part-tools.php still shows the buttons. WP core\'s is_super_admin() would be true for these admins. FLIP this test when fixed: a single-site administrator should then get the normal handler response.'
		);
		$this->assertTrue( is_dir( SHORTPIXEL_BACKUP_FOLDER ) );
	}

	// -------------------------------------------------------------------
	// Plan 2.16 / 2.43 — non-image attachment optimize returns not-optimizable message
	// -------------------------------------------------------------------

	/**
	 * Uploading a non-image file (PDF) creates an attachment that SPIO cannot
	 * optimize.  Calling optimizeItem on such an attachment must not crash — it
	 * must return a response (is_optimizable = false) or a NO_ACCESS error
	 * (if the image model fails to load), not a PHP fatal.
	 *
	 * Plan rows: 2.16 / 2.43 — non-image attachment optimize returns not-optimizable.
	 *
	 * NOTE: The PDF fixture produces a valid attachment but wp_generate_attachment_metadata()
	 * does not create image sizes for it, so MediaLibraryModel::isProcessable() returns
	 * false — the queue result will reflect that the item is not optimizable.
	 *
	 * @see class/Controller/AjaxController.php optimizeItem()
	 * @see class/Model/Image/MediaLibraryModel.php isProcessable()
	 */
	public function test_non_image_attachment_optimize_returns_not_optimizable_message() {
		$this->_setRole( 'administrator' );

		// PDFs ARE optimizable when the optimizePdfs setting is on (the
		// baseline default) — switch it off so the PDF models a genuinely
		// non-optimizable attachment.
		\wpSPIO()->settings()->optimizePdfs = 0;

		// Upload a PDF — with optimizePdfs off it cannot be compressed by SPIO.
		$attachment_id = $this->uploadFixture( 'fixture-large.pdf' );
		$this->purgeQueueTable();

		$response = $this->doScreenAction(
			'optimizeItem',
			array(
				'id'   => $attachment_id,
				'type' => 'media',
			)
		);

		// The handler must return JSON (not crash).
		$this->assertIsObject( $response, 'optimizeItem must return JSON for a non-image attachment; raw: ' . $this->lastRawResponse() );

		// Either the pipeline set is_optimizable = false, or access was denied.
		$is_optimizable   = $response->media->is_optimizable ?? null;
		$error            = $response->error ?? null;
		$queue_item_count = (int) ( $response->media->results[0]->in_queue ?? 0 );

		if ( null !== $is_optimizable ) {
			$this->assertFalse(
				(bool) $is_optimizable,
				'A PDF attachment must not be reported as optimizable'
			);
		} elseif ( null !== $error ) {
			// NO_ACCESS is also acceptable — no queue item was added.
			$this->assertSame( AjaxController::NO_ACCESS, $error );
		}

		// In no case may the PDF have been enqueued for optimization.
		$this->assertFalse(
			$this->queueHasWork(),
			'A non-image attachment must never be enqueued for optimization'
		);
	}

	// -------------------------------------------------------------------
	// Plan 2.20 / 2.47 — restore on non-image attachment degrades gracefully
	// -------------------------------------------------------------------

	/**
	 * Calling restoreItem on a non-image attachment (PDF) must not crash.
	 * Since PDF attachments are never optimized there is nothing to restore;
	 * the handler should return a JSON response (possibly NO_ACCESS or
	 * a queue result with no work done) without a fatal error.
	 *
	 * Plan rows: 2.20 / 2.47 — restore on non-image attachment degrades gracefully.
	 *
	 * @see class/Controller/AjaxController.php restoreItem()
	 */
	public function test_restore_on_non_image_attachment_degrades_gracefully() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-large.pdf' );
		$this->purgeQueueTable();

		$response = $this->doScreenAction(
			'restoreItem',
			array(
				'id'   => $attachment_id,
				'type' => 'media',
			)
		);

		// Must return JSON without crashing.
		$this->assertIsObject( $response, 'restoreItem must return JSON for a non-image attachment; raw: ' . $this->lastRawResponse() );

		// Acceptable outcomes: status=true (no-op restore enqueued and completed)
		// or error=NO_ACCESS (model failed to load / is not accessible).
		$status = $response->status ?? null;
		$error  = $response->error ?? null;

		$this->assertTrue(
			null !== $status || null !== $error,
			'restoreItem must set either status or error in the response'
		);

		// The restore queue tick must not have triggered any reducer API call on a PDF.
		if ( $this->queueHasWork() ) {
			$this->runQueueUntilEmpty();
		}

		$api_reducer_calls = array_filter( $this->api->requests, function ( $req ) {
			return false !== strpos( $req['url'], 'reducer' );
		} );
		$this->assertEmpty(
			$api_reducer_calls,
			'Restoring a non-image attachment must not hit the reducer API'
		);
	}

	// -------------------------------------------------------------------
	// Plan 2.15.1 — bulk glossy reoptimize covers mixed optimized and unoptimized
	// -------------------------------------------------------------------

	/**
	 * reOptimizeItem with compressionType=1 (glossy) must re-send both already-
	 * optimized and previously-unoptimized images through the pipeline.  After
	 * both finish the queue must be empty and both items must be marked optimized.
	 *
	 * Plan row: 2.15.1 — bulk glossy reoptimize over mixed optimized/unoptimized.
	 *
	 * @see class/Controller/AjaxController.php reOptimizeItem()
	 */
	public function test_bulk_glossy_reoptimize_covers_mixed_optimized_and_unoptimized_images() {
		$this->_setRole( 'administrator' );

		// Upload two images: optimize only the first.
		$id_optimized   = $this->uploadFixture( 'fixture-small.jpg' );
		$id_unoptimized = $this->uploadFixture( 'fixture-small.png' );
		$this->purgeQueueTable();

		$this->optimizeAttachment( $id_optimized );
		$this->purgeQueueTable();

		$this->assertTrue( $this->freshImageModel( $id_optimized )->isOptimized(), 'Precondition: first image optimized' );
		$this->assertFalse( $this->freshImageModel( $id_unoptimized )->isOptimized(), 'Precondition: second image not optimized' );

		$requests_before = count( $this->api->requests );

		// Re-optimize the first (already optimized) at glossy.
		$response1 = $this->doScreenAction(
			'reOptimizeItem',
			array(
				'id'              => $id_optimized,
				'type'            => 'media',
				'compressionType' => 1,
			)
		);
		$this->assertIsObject( $response1 );
		$this->assertTrue( $response1->status );

		// Optimize the second (never-optimized) at glossy.
		$response2 = $this->doScreenAction(
			'optimizeItem',
			array(
				'id'              => $id_unoptimized,
				'type'            => 'media',
				'compressionType' => 1,
			)
		);
		$this->assertIsObject( $response2 );

		$this->runQueueUntilEmpty();

		$this->assertTrue(
			$this->freshImageModel( $id_optimized )->isOptimized(),
			'Previously-optimized image must still be optimized after glossy reoptimize'
		);
		$this->assertTrue(
			$this->freshImageModel( $id_unoptimized )->isOptimized(),
			'Previously-unoptimized image must be optimized by glossy reoptimize'
		);
		$this->assertGreaterThan(
			$requests_before,
			count( $this->api->requests ),
			'Both images must have been sent through the API'
		);
	}

	// -------------------------------------------------------------------
	// Plan 10.1.2 — editor can reprocess any image
	// -------------------------------------------------------------------

	/**
	 * An editor (edit_others_posts) passes the outer is_author gate AND the
	 * per-image imageIsEditable() check for attachments they did not personally
	 * upload (because is_editor maps to edit_others_posts, which grants access
	 * to all posts).  reOptimizeItem must therefore succeed for an editor acting
	 * on any attachment.
	 *
	 * Plan row: 10.1.2 — editor can reprocess any image.
	 *
	 * @see class/Model/AccessModel.php imageIsEditable()
	 * @see class/Controller/AjaxController.php reOptimizeItem()
	 */
	public function test_editor_can_reprocess_any_image() {
		// Upload as administrator.
		$this->_setRole( 'administrator' );
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $attachment_id );
		$this->purgeQueueTable();
		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'Precondition: image optimized' );

		// Switch to editor — different user, different owner.
		$editor_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$response = $this->doScreenAction(
			'reOptimizeItem',
			array(
				'id'              => $attachment_id,
				'type'            => 'media',
				'compressionType' => 1,
			)
		);

		$this->assertIsObject( $response, 'reOptimizeItem must return JSON for an editor; raw: ' . $this->lastRawResponse() );
		$this->assertFalse(
			isset( $response->error ) && $response->error === AjaxController::NO_ACCESS,
			'An editor must not be blocked from reprocessing any image'
		);
		$this->assertTrue( $response->status, 'reOptimizeItem must succeed for an editor' );
		$this->assertTrue( $this->queueHasWork(), 'reOptimizeItem must enqueue the item for the editor' );

		$this->runQueueUntilEmpty();
		$this->assertTrue(
			$this->freshImageModel( $attachment_id )->isOptimized(),
			'Image must be optimized again after editor reprocess'
		);
	}

	// -------------------------------------------------------------------
	// ai/redoAiReplacement — regression for bug #46
	// -------------------------------------------------------------------

	/**
	 * REGRESSION bug #46 (introduced in 90d1a316 "Bulk redo AI
	 * replacement"): AjaxController::redoAiReplacement() used to call
	 * `$api->redoAiReplacement($queueItem)` — an undefined method (the real
	 * name is redoAIReplace(), "...Replace" not "...Replacement", so PHP's
	 * method-name case-insensitivity could not save it) — making every
	 * single-item `ai/redoAiReplacement` AJAX request fatal. Fixed by
	 * renaming the call to redoAIReplace().
	 *
	 * End-to-end check of the recovery scenario: AI data is GENERATED but
	 * the embedding post still has alt="" (the pre-97f2c1f4 replacer2
	 * singleton stuck state). The single-item redo must not fatal, must
	 * return status=true, and must re-apply the stored alt to the post
	 * content synchronously — no new API calls, no queue round-trip.
	 */
	public function test_single_redo_ai_replacement_reapplies_in_content_alt() {
		$this->_setRole( 'administrator' );

		$settings                  = \wpSPIO()->settings();
		$settings->enable_ai       = 1;
		$settings->ai_gen_alt      = 1;
		$settings->ai_gen_caption  = 1;
		$settings->ai_gen_filename = 0;
		$settings->aiPreserve      = false;

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$img_tag       = '<img src="' . esc_url( wp_get_attachment_url( $attachment_id ) ) . '" alt="" />';
		$post_id       = self::factory()->post->create( array( 'post_content' => $img_tag ) );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );

		$ref  = new ReflectionClass( \ShortPixel\Model\AiDataModel::class );
		$prop = $ref->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$this->purgeQueueTable();

		// Generate the AI data through the queue + mock API.
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		( new QueueController() )->addItemToQueue( $imageModel, array( 'action' => 'requestAlt' ) );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'A mock ai alt text.',
			get_post( $post_id )->post_content,
			'Precondition: the initial generation must fill the in-content alt.'
		);

		// Recreate the stuck state: aipostmeta GENERATED, in-content alt empty.
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $img_tag ) );
		clean_post_cache( $post_id );
		$this->assertStringNotContainsString( 'A mock ai alt text.', get_post( $post_id )->post_content );
		$prop->setValue( null, array() );

		$response = $this->doScreenAction(
			'ai/redoAiReplacement',
			array(
				'id'   => $attachment_id,
				'type' => 'media',
			)
		);

		$this->assertIsObject(
			$response,
			'Regression #46: ai/redoAiReplacement must return JSON, not fatal; raw: ' . $this->lastRawResponse()
		);
		$this->assertTrue(
			$response->status,
			'Regression #46: the single-item redo handler must report success.'
		);

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_id )->post_content,
			'Regression #46: the single-item redo must re-apply the stored AI alt to the embedding post content.'
		);
	}
}
