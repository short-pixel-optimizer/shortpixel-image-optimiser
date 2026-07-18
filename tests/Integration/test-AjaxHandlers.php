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
}
