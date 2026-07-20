<?php
/**
 * Tests for ShortPixel\Controller\BulkController.
 *
 * Covers:
 *   - getLogs() / saveLogs() / uninstallPlugin() — option round-trips
 *   - getLogData() — lookup by filename in the stored list
 *   - getLog() — path-traversal rejection and happy-path file retrieval
 *   - addLog() (protected, invoked via reflection) — log rotation, file
 *     renaming, and the known deferred-fix rotation bug at line 357
 *
 * Out of scope (and why):
 *   - createNewBulk / startBulk / finishBulk / isBulkRunning — require a
 *     live QueueController backed by real queue tables and settings; these
 *     are integration-level concerns.
 *   - getAnyCustomOperation / getCustomOperation — also require QueueController.
 *   - addLog() happy-path file rename — depends on FileSystemController's
 *     FileModel::move(); testing the full pipeline with real files is left to
 *     integration tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\BulkController;

class BulkControllerTest extends WP_UnitTestCase {

	/** @var string Backup-folder path resolved at test-time. */
	private $backupPath;

	/** @var string[] Files created during a test that must be cleaned up. */
	private $createdFiles = array();

	public function set_up() {
		parent::set_up();

		$this->resetSingleton();

		// Ensure the backup folder exists so filesystem helpers don't fail.
		$this->backupPath = rtrim( SHORTPIXEL_BACKUP_FOLDER, '/' ) . '/';
		if ( ! is_dir( $this->backupPath ) ) {
			wp_mkdir_p( $this->backupPath );
		}

		// Start each test with no persisted log option.
		delete_option( 'shortpixel-bulk-logs' );
	}

	public function tear_down() {
		delete_option( 'shortpixel-bulk-logs' );

		// Remove any physical files created during tests.
		foreach ( $this->createdFiles as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path );
			}
		}
		$this->createdFiles = array();

		$this->resetSingleton();

		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Reflection helpers
	// -------------------------------------------------------------------------

	private function resetSingleton(): void {
		$ref = new ReflectionClass( BulkController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private function freshController(): BulkController {
		$ref = new ReflectionClass( BulkController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function invokeProtected( BulkController $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( BulkController::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	private function getProtected( BulkController $obj, string $prop ) {
		$ref = new ReflectionClass( BulkController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	private function setProtected( BulkController $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( BulkController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	/**
	 * Build a minimal queue stub that satisfies the interface addLog() calls.
	 *
	 * @param array $statsOverrides Values merged on top of the default stats object.
	 * @param string $type          Queue type identifier.
	 * @param string|false $customOp Custom operation name (or false for none).
	 */
	private function makeQueueStub( array $statsOverrides = array(), string $type = 'media', $customOp = false ): object {
		$defaultStats = (object) array(
			'done'         => 5,
			'in_queue'     => 0,
			'errors'       => 0,
			'fatal_errors' => 0,
			'is_finished'  => true,
			'total'        => 5,
		);

		foreach ( $statsOverrides as $key => $value ) {
			$defaultStats->$key = $value;
		}

		// Custom data: map of key => value returned by getCustomDataItem().
		$customData = array(
			'webpcount'       => 0,
			'avifcount'       => 0,
			'basecount'       => 0,
			'customOperation' => $customOp,
		);

		return new class( $defaultStats, $type, $customData ) {
			private $stats;
			private $type;
			private $customData;

			public function __construct( $stats, $type, $customData ) {
				$this->stats      = $stats;
				$this->type       = $type;
				$this->customData = $customData;
			}

			public function getStats() {
				return $this->stats;
			}

			public function getType(): string {
				return $this->type;
			}

			public function getCustomDataItem( string $key ) {
				return $this->customData[ $key ] ?? false;
			}
		};
	}

	// -------------------------------------------------------------------------
	// getInstance — singleton contract
	// -------------------------------------------------------------------------

	public function test_getInstance_returns_singleton() {
		$a = BulkController::getInstance();
		$b = BulkController::getInstance();

		$this->assertInstanceOf( BulkController::class, $a );
		$this->assertSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// getLogs — option round-trip
	// -------------------------------------------------------------------------

	public function test_getLogs_returns_empty_array_when_option_is_absent() {
		$ctrl = $this->freshController();
		$logs = $ctrl->getLogs();

		$this->assertIsArray( $logs );
		$this->assertCount( 0, $logs );
	}

	public function test_getLogs_returns_persisted_entries_from_the_option() {
		$seeded = array(
			array( 'processed' => 3, 'type' => 'media', 'date' => 1700000000, 'logfile' => 'bulk_media_1700000000.log' ),
			array( 'processed' => 7, 'type' => 'custom', 'date' => 1700001000, 'logfile' => 'bulk_custom_1700001000.log' ),
		);
		update_option( 'shortpixel-bulk-logs', $seeded, false );

		$ctrl = $this->freshController();
		$logs = $ctrl->getLogs();

		$this->assertCount( 2, $logs );
		$this->assertSame( 3, $logs[0]['processed'] );
		$this->assertSame( 'custom', $logs[1]['type'] );
	}

	public function test_getLogs_memoises_on_second_call() {
		$seeded = array(
			array( 'processed' => 1, 'type' => 'media', 'date' => 1700000000, 'logfile' => 'bulk_media_1700000000.log' ),
		);
		update_option( 'shortpixel-bulk-logs', $seeded, false );

		$ctrl = $this->freshController();

		$first  = $ctrl->getLogs();
		// Mutate the option between calls — the cached value should be returned.
		update_option( 'shortpixel-bulk-logs', array(), false );
		$second = $ctrl->getLogs();

		$this->assertSame( $first, $second );
	}

	// -------------------------------------------------------------------------
	// saveLogs (protected) — option write / delete
	// -------------------------------------------------------------------------

	public function test_saveLogs_persists_non_empty_array_to_option() {
		$ctrl = $this->freshController();
		$data = array(
			array( 'processed' => 2, 'type' => 'media', 'date' => time(), 'logfile' => 'bulk_media_99.log' ),
		);

		$this->invokeProtected( $ctrl, 'saveLogs', array( $data ) );

		$stored = get_option( 'shortpixel-bulk-logs' );
		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );
		$this->assertSame( 2, $stored[0]['processed'] );
	}

	public function test_saveLogs_deletes_option_when_array_is_empty() {
		update_option( 'shortpixel-bulk-logs', array( array( 'processed' => 1 ) ), false );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'saveLogs', array( array() ) );

		// Option should be gone (get_option returns the default false).
		$this->assertFalse( get_option( 'shortpixel-bulk-logs', false ) );
	}

	// -------------------------------------------------------------------------
	// uninstallPlugin — deletes the option
	// -------------------------------------------------------------------------

	public function test_uninstallPlugin_removes_the_log_option() {
		update_option( 'shortpixel-bulk-logs', array( array( 'processed' => 1 ) ), false );

		BulkController::uninstallPlugin();

		$this->assertFalse( get_option( 'shortpixel-bulk-logs', false ) );
	}

	public function test_uninstallPlugin_is_idempotent_when_option_already_absent() {
		// Should not throw; option simply stays absent.
		BulkController::uninstallPlugin();

		$this->assertFalse( get_option( 'shortpixel-bulk-logs', false ) );
	}

	// -------------------------------------------------------------------------
	// getLogData — lookup by filename
	// -------------------------------------------------------------------------

	public function test_getLogData_returns_the_matching_entry_by_filename() {
		$target  = 'bulk_media_1700000042.log';
		$seeded  = array(
			array( 'processed' => 1, 'type' => 'media', 'date' => 1700000000, 'logfile' => 'bulk_media_1700000000.log' ),
			array( 'processed' => 9, 'type' => 'media', 'date' => 1700000042, 'logfile' => $target ),
		);
		update_option( 'shortpixel-bulk-logs', $seeded, false );

		$ctrl   = $this->freshController();
		$result = $ctrl->getLogData( $target );

		$this->assertIsArray( $result );
		$this->assertSame( 9, $result['processed'] );
		$this->assertSame( $target, $result['logfile'] );
	}

	public function test_getLogData_returns_false_when_filename_not_found() {
		update_option( 'shortpixel-bulk-logs', array(
			array( 'processed' => 1, 'type' => 'media', 'date' => 1700000000, 'logfile' => 'bulk_media_1700000000.log' ),
		), false );

		$ctrl   = $this->freshController();
		$result = $ctrl->getLogData( 'no-such-file.log' );

		$this->assertFalse( $result );
	}

	public function test_getLogData_returns_false_when_logs_are_empty() {
		$ctrl   = $this->freshController();
		$result = $ctrl->getLogData( 'any.log' );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// getLog — path-traversal rejection and happy path
	// -------------------------------------------------------------------------

	public function test_getLog_returns_false_for_path_traversal_attempt() {
		$ctrl = $this->freshController();

		$this->assertFalse( $ctrl->getLog( '../../../etc/passwd' ) );
	}

	public function test_getLog_returns_false_for_double_dot_only_name() {
		$ctrl = $this->freshController();

		$this->assertFalse( $ctrl->getLog( '../..' ) );
	}

	public function test_getLog_returns_false_when_file_does_not_exist() {
		$ctrl = $this->freshController();

		$this->assertFalse( $ctrl->getLog( 'nonexistent_bulk_log.log' ) );
	}

	public function test_getLog_returns_file_model_when_file_exists_in_backup_folder() {
		// Create a real log file in the backup folder.
		$logName = 'test_bulk_getlog_' . time() . '.log';
		$logPath = $this->backupPath . $logName;
		file_put_contents( $logPath, 'test data' );
		$this->createdFiles[] = $logPath;

		$ctrl   = $this->freshController();
		$result = $ctrl->getLog( $logName );

		$this->assertNotFalse( $result, 'getLog should return a FileModel for a real file' );
		$this->assertIsObject( $result );
	}

	// -------------------------------------------------------------------------
	// addLog (protected) — via reflection with queue stub
	// -------------------------------------------------------------------------

	public function test_addLog_does_not_write_when_done_and_fatal_errors_are_zero() {
		$stub = $this->makeQueueStub( array( 'done' => 0, 'fatal_errors' => 0 ) );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );

		$this->assertFalse( get_option( 'shortpixel-bulk-logs', false ) );
	}

	public function test_addLog_writes_entry_when_done_is_non_zero() {
		$stub = $this->makeQueueStub( array( 'done' => 3 ) );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );

		$stored = get_option( 'shortpixel-bulk-logs', false );
		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );
		$this->assertSame( 3, $stored[0]['processed'] );
		$this->assertSame( 'media', $stored[0]['type'] );
		$this->assertArrayHasKey( 'date', $stored[0] );
		$this->assertArrayHasKey( 'logfile', $stored[0] );
	}

	public function test_addLog_writes_entry_when_fatal_errors_is_non_zero_even_if_done_is_zero() {
		$stub = $this->makeQueueStub( array( 'done' => 0, 'fatal_errors' => 2 ) );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );

		$stored = get_option( 'shortpixel-bulk-logs', false );
		$this->assertIsArray( $stored );
		$this->assertCount( 1, $stored );
		$this->assertSame( 2, $stored[0]['fatal_errors'] );
	}

	public function test_addLog_stores_custom_operation_when_present() {
		$stub = $this->makeQueueStub( array( 'done' => 1 ), 'media', 'bulk-restore' );

		$ctrl = $this->freshController();
		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );

		$stored = get_option( 'shortpixel-bulk-logs' );
		$this->assertSame( 'bulk-restore', $stored[0]['operation'] );
	}

	public function test_addLog_generates_timestamped_logfile_name() {
		$stub = $this->makeQueueStub( array( 'done' => 1 ), 'custom' );

		$ctrl  = $this->freshController();
		$before = time();
		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );
		$after  = time();

		$stored = get_option( 'shortpixel-bulk-logs' );
		$logfile = $stored[0]['logfile'];

		// Must follow bulk_<type>_<timestamp>.log naming convention.
		$this->assertMatchesRegularExpression( '/^bulk_custom_\d+\.log$/', $logfile );

		// Timestamp embedded in the name should be within the test window.
		preg_match( '/bulk_custom_(\d+)\.log/', $logfile, $m );
		$ts = (int) $m[1];
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( $after, $ts );
	}

	/**
	 * With 10 existing entries, addLog() rotates: the oldest metadata entry
	 * is shifted off and the new entry appended, keeping the stored option
	 * at exactly 10 entries.
	 *
	 * The queue stub uses done=99 so the new entry's 'processed' value cannot
	 * collide with the seeded values 1–10.
	 */
	public function test_addLog_trims_oldest_metadata_entry_when_limit_is_ten() {
		// Seed 10 existing entries so the 11th call triggers rotation.
		$existing = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$existing[] = array(
				'processed'    => $i,
				'not_processed'=> 0,
				'errors'       => 0,
				'fatal_errors' => 0,
				'type'         => 'media',
				'date'         => 1700000000 + $i,
				'logfile'      => 'bulk_media_' . ( 1700000000 + $i ) . '.log',
			);
		}
		update_option( 'shortpixel-bulk-logs', $existing, false );

		$stub = $this->makeQueueStub( array( 'done' => 99 ), 'media' );

		$ctrl = $this->freshController();
		// Pre-populate the in-memory cache so getLogs() doesn't re-read the option.
		$this->setProtected( $ctrl, 'logs', $existing );

		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );

		$stored = get_option( 'shortpixel-bulk-logs' );
		// The option must still hold exactly 10 entries (oldest shifted off, new appended).
		$this->assertCount( 10, $stored );
		// The first entry in the original seed (processed=1) should have been removed.
		$processedValues = array_column( $stored, 'processed' );
		$this->assertNotContains( 1, $processedValues, 'Oldest log entry (processed=1) should have been rotated out' );
	}

	// -------------------------------------------------------------------------
	// addLog — rotation deletes the oldest physical log file
	// -------------------------------------------------------------------------

	/**
	 * During rotation, the physical log file belonging to the shifted-out
	 * (oldest) entry is deleted from the backup folder, so bulk_*.log files
	 * do not accumulate.
	 */
	public function test_addLog_rotation_deletes_oldest_log_file() {
		$oldTimestamp = 1700000001;
		$oldLogName   = 'bulk_media_' . $oldTimestamp . '.log';
		$oldLogPath   = $this->backupPath . $oldLogName;

		// Create the physical file for the oldest log entry.
		file_put_contents( $oldLogPath, 'oldest log content' );
		$this->createdFiles[] = $oldLogPath; // always cleaned up by tearDown.

		// Seed exactly 10 entries; the first is the "oldest" that should be evicted.
		$existing = array();
		$existing[] = array(
			'processed'    => 0,
			'not_processed'=> 0,
			'errors'       => 0,
			'fatal_errors' => 0,
			'type'         => 'media',
			'date'         => $oldTimestamp,
			'logfile'      => $oldLogName,
		);
		for ( $i = 2; $i <= 10; $i++ ) {
			$existing[] = array(
				'processed'    => $i,
				'not_processed'=> 0,
				'errors'       => 0,
				'fatal_errors' => 0,
				'type'         => 'media',
				'date'         => 1700000000 + $i,
				'logfile'      => 'bulk_media_' . ( 1700000000 + $i ) . '.log',
			);
		}
		update_option( 'shortpixel-bulk-logs', $existing, false );

		$stub = $this->makeQueueStub( array( 'done' => 1 ), 'media' );

		$ctrl = $this->freshController();
		$this->setProtected( $ctrl, 'logs', $existing );

		$this->invokeProtected( $ctrl, 'addLog', array( $stub ) );

		$this->assertFileDoesNotExist(
			$oldLogPath,
			'The oldest physical log file must be deleted during rotation.'
		);
	}

} // class
