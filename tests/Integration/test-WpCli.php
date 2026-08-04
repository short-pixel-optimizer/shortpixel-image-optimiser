<?php
/**
 * WP-CLI command integration tests.
 *
 * Exercises the REAL command classes (WpCliController, SpioSingle, SpioBulk —
 * class/external/wp-cli/) against the live test WordPress + mocked ShortPixel
 * API. The wp-cli runtime itself is stubbed (Helpers/WpCliStub.php): commands
 * are invoked as plain method calls and all \WP_CLI output is recorded for
 * assertions. WP-CLI's exit-on-error semantics are honoured — the stub's
 * error() throws, which several commands rely on for control flow.
 *
 * Queue-mode note: SpioSingle runs against the SINGLE queues
 * ('mediaSingle'/'customSingle'), SpioBulk forces is_bulk and runs against the
 * BULK queues ('media'/'custom') — the trait's queueHasWork() only sees the
 * single queues, hence the bulk-aware twin below.
 *
 * Tick note: `run` without --ticks loops until done with sleep(--wait)
 * between ticks and no way to backdate the ShortQ 10s retry gate mid-loop —
 * so tests drive `run --ticks=1 --wait=0` in an outer loop that backdates
 * items and flushes Queue::$isInQueue between ticks (same pattern as the
 * cron-dispatch suite).
 *
 * @package Shortpixel_Image_Optimiser
 */

require_once __DIR__ . '/Helpers/WpCliStub.php';

use ShortPixel\Controller\QueueController;
use ShortPixel\SpioBulk;
use ShortPixel\SpioCommandBase;
use ShortPixel\SpioSingle;
use ShortPixel\WpCliController;

class WpCliTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	private function flushQueueStatusCache(): void {
		$prop = new ReflectionProperty( \ShortPixel\Controller\Queue\Queue::class, 'isInQueue' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/** Bulk-mode twin of the trait's queueHasWork() (bulk queue names differ). */
	private function bulkQueueHasWork(): bool {
		$controller = new QueueController( array( 'is_bulk' => true ) );
		foreach ( array( 'media', 'custom' ) as $type ) {
			$stats = $controller->getQueue( $type )->getStats();
			if ( is_object( $stats ) && ( $stats->in_queue > 0 || $stats->in_process > 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Drive `run --ticks=1 --wait=0` in a loop until the relevant queues
	 * drain, backdating the 10s retry gate between ticks.
	 */
	private function runCliTicks( SpioCommandBase $cli, array $assoc, int $maxTicks = 25, bool $bulk = false ): void {
		$assoc = array_merge( $assoc, array( 'ticks' => 1, 'wait' => 0 ) );

		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$this->flushQueueStatusCache();
			$cli->run( array(), $assoc );

			$hasWork = $bulk ? $this->bulkQueueHasWork() : $this->queueHasWork();
			if ( ! $hasWork ) {
				return;
			}
			$this->backdateQueueItems();
		}

		$this->fail( "CLI-driven queue still has work after $maxTicks ticks.\n--- CLI output ---\n" . WP_CLI::allText() );
	}

	/** Upload a fixture and drop the auto-enqueued optimize item. */
	private function freshAttachment(): int {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		return $id;
	}

	/** Rows of the last table rendered via \WP_CLI\Utils\format_items(). */
	private function lastTable(): array {
		$this->assertNotEmpty( WP_CLI::$tables, "A table should have been rendered.\n--- CLI output ---\n" . WP_CLI::allText() );
		return end( WP_CLI::$tables )['items'];
	}

	// -------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------

	public function test_controller_registers_spio_command_groups() {
		WpCliController::$instance = null;
		WP_CLI::reset();

		WpCliController::getInstance();

		$this->assertArrayHasKey( 'spio', WP_CLI::$commands );
		$this->assertSame( '\ShortPixel\SpioSingle', WP_CLI::$commands['spio'] );
		$this->assertArrayHasKey( 'spio bulk', WP_CLI::$commands );
		$this->assertSame( '\ShortPixel\SpioBulk', WP_CLI::$commands['spio bulk'] );
	}

	// -------------------------------------------------------------------
	// add
	// -------------------------------------------------------------------

	public function test_add_requires_an_id() {
		$cli = new SpioSingle();

		try {
			$cli->add( array(), array() );
			$this->fail( 'add without an id must raise a WP-CLI error (which exits).' );
		} catch ( WP_CLI_Stub_ExitException $e ) {
			$this->assertStringContainsString( 'Specify an Media Library Item ID', $e->getMessage() );
		}

		$this->assertFalse( $this->queueHasWork(), 'Nothing may be enqueued on an id-less add' );
	}

	public function test_add_rejects_unknown_id() {
		$cli = new SpioSingle();

		try {
			$cli->add( array( 99999999 ), array() );
			$this->fail( 'add with a non-existing attachment id must raise a WP-CLI error.' );
		} catch ( WP_CLI_Stub_ExitException $e ) {
			$this->assertNotEmpty( WP_CLI::messagesOfType( 'error' ) );
		}

		$this->assertFalse( $this->queueHasWork(), 'Nothing may be enqueued for an unknown id' );
	}

	public function test_add_with_halt_enqueues_without_processing() {
		$id  = $this->freshAttachment();
		$cli = new SpioSingle();

		$cli->add( array( $id ), array( 'halt' => true ) );

		$successes = WP_CLI::messagesOfType( 'success' );
		$this->assertNotEmpty( $successes, "add must report success.\n" . WP_CLI::allText() );
		$this->assertStringContainsString( 'You can optimize images via the run command', WP_CLI::allText() );

		$this->assertTrue( $this->queueHasWork(), '--halt must leave the item waiting in the queue' );

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$this->assertFalse( $imageModel->isOptimized(), '--halt must not process the item' );

		// add() ends with a status() call — the queue table must show the item.
		$rows = $this->lastTable();
		$this->assertSame( 'media', $rows[0]['queue name'] );
		$this->assertGreaterThanOrEqual( 1, (int) $rows[0]['in queue'] );
	}

	/**
	 * Bug #18 FIXED (a2d45fa1): QueueController::addItemToQueue() now attaches
	 * the default "Item %s added to Queue" message when the enqueue result has
	 * no message of its own (the old `&&`/`||` precedence bug made the guard
	 * unreachable for the normal message===null case, so every CLI add printed
	 * a blank Success line). Flipped from the pinned empty-message assertion.
	 */
	public function test_add_success_message_is_populated() {
		$id  = $this->freshAttachment();
		$cli = new SpioSingle();

		$cli->add( array( $id ), array( 'halt' => true ) );

		$successes = WP_CLI::messagesOfType( 'success' );
		$this->assertNotEmpty( $successes );
		$this->assertStringContainsString(
			'added to',
			$successes[0],
			'Since a2d45fa1 (bug #18 fix) the enqueue success message must carry the default "added to Queue" text.'
		);
	}

	// -------------------------------------------------------------------
	// run
	// -------------------------------------------------------------------

	public function test_run_with_ticks_optimizes_the_queued_item() {
		$id  = $this->freshAttachment();
		$cli = new SpioSingle();

		$cli->add( array( $id ), array( 'halt' => true ) );
		WP_CLI::reset();

		$this->runCliTicks( $cli, array( 'queue' => 'media' ) );

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue( $imageModel->isOptimized(), "run --ticks must optimize the queued item.\n" . WP_CLI::allText() );

		// The 'finished' line only prints on the tick AFTER the queue drains
		// (RESULT_QUEUE_EMPTY) — run one more tick to see it.
		$cli->run( array(), array( 'queue' => 'media', 'ticks' => 1, 'wait' => 0 ) );
		$this->assertStringContainsString( 'All Queues report processing has finished', WP_CLI::allText() );

		// Bug #32 FIXED (af5794d8): displayResult() fetches the magic property
		// into a local var before empty() — QueueItemResult has __get/__set
		// but no __isset, so empty() directly on $result->improvements was
		// always true. The per-size improvements table renders again.
		$improvementTables = array_filter(
			WP_CLI::$tables,
			function ( $table ) {
				return in_array( 'improvement', $table['fields'], true );
			}
		);
		$this->assertNotEmpty(
			$improvementTables,
			'Since af5794d8 (bug #32 fix) a successful optimization must render the per-size improvements table.'
		);
	}

	// -------------------------------------------------------------------
	// status / settings / clear
	// -------------------------------------------------------------------

	public function test_status_reports_queue_counts() {
		$id  = $this->freshAttachment();
		$cli = new SpioSingle();

		$cli->add( array( $id ), array( 'halt' => true ) );
		WP_CLI::reset();

		$cli->status( array(), array() );

		$rows   = $this->lastTable();
		$byName = array();
		foreach ( $rows as $row ) {
			$byName[ $row['queue name'] ] = $row;
		}

		$this->assertArrayHasKey( 'media', $byName );
		$this->assertArrayHasKey( 'custom', $byName );
		$this->assertSame( 1, (int) $byName['media']['in queue'] );
		$this->assertSame( 0, (int) $byName['custom']['in queue'] );
	}

	public function test_settings_lists_operator_settings() {
		$settings                    = \wpSPIO()->settings();
		$settings->backupImages      = 1;
		$settings->processThumbnails = 0;
		$settings->createWebp        = 1;
		$settings->createAvif        = 0;

		( new SpioSingle() )->settings();

		$values = array();
		foreach ( $this->lastTable() as $row ) {
			$values[ $row['setting'] ] = $row['value'];
		}

		$this->assertSame( 'Yes', $values['Image Backup'] );
		$this->assertSame( 'No', $values['Processed Thumbnails'] );
		$this->assertSame( 'Yes', $values['Creates Webp'] );
		$this->assertSame( 'No', $values['Creates Avif'] );
	}

	public function test_clear_empties_the_queue() {
		$id  = $this->freshAttachment();
		$cli = new SpioSingle();

		$cli->add( array( $id ), array( 'halt' => true ) );
		$this->assertTrue( $this->queueHasWork(), 'Precondition: item waiting' );
		WP_CLI::reset();

		$cli->clear( array(), array() );

		$this->assertStringContainsString( 'Queue(s) cleared', implode( ' ', WP_CLI::messagesOfType( 'success' ) ) );
		$this->flushQueueStatusCache();
		$this->assertFalse( $this->queueHasWork(), 'clear must empty the queue(s)' );
	}

	// -------------------------------------------------------------------
	// requestAlt
	// -------------------------------------------------------------------

	public function test_request_alt_via_cli_generates_alt_text() {
		\wpSPIO()->settings()->ai_gen_alt = 1;

		// WP test rollbacks reuse attachment ids while aipostmeta rows (DDL
		// table) survive — leftovers would make the item look already
		// generated. Same hygiene as the AiPipeline suite.
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );
		$prop = new ReflectionProperty( \ShortPixel\Model\AiDataModel::class, 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$id  = $this->freshAttachment();
		$cli = new SpioSingle();

		$cli->requestAlt( array( $id ), array() );

		$this->assertTrue( $this->queueHasWork(), 'requestAlt must enqueue the AI item (it does not process by itself)' );

		$this->runCliTicks( $cli, array( 'queue' => 'media' ) );

		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $id, '_wp_attachment_image_alt', true ),
			"The CLI-enqueued AI request must produce the mock alt text after run.\n" . WP_CLI::allText()
		);

		// Bug #19 FIXED (e19a0236): displayResult() now guards the improvements
		// table with `false === empty($result->improvements)` instead of
		// property_exists() (which was always true — the property is declared,
		// null on AI results). AI successes no longer render a bogus table with
		// a bare '%' Total row, and the "array offset on null" warnings at :497
		// are gone. Flipped from the pinned bogus-table assertions.
		$bogusTables = array_filter(
			WP_CLI::$tables,
			function ( $table ) {
				return in_array( 'improvement', $table['fields'], true );
			}
		);
		$this->assertEmpty(
			$bogusTables,
			'Since e19a0236 (bug #19 fix) an AI success must not render an improvements table.'
		);
	}

	// -------------------------------------------------------------------
	// restore
	// -------------------------------------------------------------------

	public function test_restore_via_cli_restores_synchronously() {
		$id = $this->freshAttachment();
		$this->optimizeAttachment( $id );

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue( $imageModel->isOptimized(), 'Precondition: item optimized' );
		WP_CLI::reset();

		// Restore is an ActionController direct action: it executes inside the
		// addItemToQueue() call — no run needed afterwards.
		( new SpioSingle() )->restore( array( $id ), array() );

		$successes = WP_CLI::messagesOfType( 'success' );
		$this->assertNotEmpty( $successes, "restore must report success.\n" . WP_CLI::allText() );
		$this->assertStringContainsString( 'Item restored', $successes[0] );

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertFalse( $imageModel->isOptimized(), 'The image must be back to unoptimized state' );
	}

	public function test_restore_of_unoptimized_item_errors() {
		$id = $this->freshAttachment();

		try {
			( new SpioSingle() )->restore( array( $id ), array() );
			$this->fail( 'Restoring a never-optimized item must raise a WP-CLI error.' );
		} catch ( WP_CLI_Stub_ExitException $e ) {
			$this->assertStringContainsString( 'Restoring Item', $e->getMessage() );
		}
	}

	/**
	 * Restore a custom-media image via `wp spio restore ID --type=custom`.
	 * The command resolves the image via filesystem()->getImage($id, 'custom'),
	 * runs the restore action synchronously through the queue, and reports success.
	 * Manual plan row 11.2.5 (custom-type variant).
	 */
	public function test_restore_via_cli_with_type_custom() {
		\wpSPIO()->settings()->backupImages = 1;

		// --- Set up a custom folder and register one image. ---
		global $wpdb;
		foreach ( array( 'shortpixel_folders', 'shortpixel_meta' ) as $tbl ) {
			$t = $wpdb->prefix . $tbl;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
				$wpdb->query( "DELETE FROM `$t`" );
			}
		}

		$customDir = trailingslashit( WP_CONTENT_DIR ) . 'spio-cli-restore-' . wp_generate_password( 8, false ) . '/';
		mkdir( $customDir );
		$src = $this->fixturePath( 'fixture-small.jpg' );
		copy( $src, $customDir . 'cli-restore.jpg' );

		try {
			$folder = \ShortPixel\Controller\OtherMediaController::getInstance()->addDirectory( $customDir );
			$this->assertNotFalse( $folder, 'Custom folder must register successfully.' );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}shortpixel_meta WHERE folder_id = %d",
					(int) $folder->get( 'id' )
				)
			);
			$this->assertNotEmpty( $rows, 'Image must be scanned into shortpixel_meta.' );
			$customId = (int) $rows[0]->id;

			// Optimize the image through the standard queue pipeline.
			$image = \wpSPIO()->filesystem()->getImage( $customId, 'custom' );
			( new \ShortPixel\Controller\QueueController() )->addItemToQueue( $image );
			$this->runQueueUntilEmpty();
			$this->purgeQueueTable();

			$optimized = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$this->assertTrue( $optimized->isOptimized(), 'Precondition: custom image must be optimized before CLI restore.' );

			// --- Invoke the CLI restore with --type=custom. ---
			WP_CLI::reset();

			$cli = new SpioSingle();
			$cli->restore( array( $customId ), array( 'type' => 'custom' ) );

			$successes = WP_CLI::messagesOfType( 'success' );
			$this->assertNotEmpty(
				$successes,
				"wp spio restore $customId --type=custom must report success.\n" . WP_CLI::allText()
			);
			$this->assertStringContainsString(
				'Item restored',
				$successes[0],
				"The success message must contain 'Item restored' (row 11.2.5).\n" . WP_CLI::allText()
			);

			// The image must now be back to unoptimized state.
			$restored = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$this->assertFalse(
				$restored->isOptimized(),
				'Custom image must be marked as unoptimized after CLI restore with --type=custom (row 11.2.5).'
			);

			// The disk file must be back to the original byte size.
			clearstatcache();
			$originalSize = filesize( $src );
			$this->assertSame(
				$originalSize,
				filesize( $customDir . 'cli-restore.jpg' ),
				'The restored custom file on disk must match the original fixture byte size (row 11.2.5).'
			);
		} finally {
			@unlink( $customDir . 'cli-restore.jpg' );
			@rmdir( $customDir );
		}
	}

	// -------------------------------------------------------------------
	// bulk lifecycle
	// -------------------------------------------------------------------

	public function test_bulk_lifecycle_create_prepare_start_run() {
		$settings                    = \wpSPIO()->settings();
		$settings->processThumbnails = 0;
		$settings->autoAIBulk        = 0;

		$id_one = $this->uploadFixture( 'fixture-small.jpg' );
		$id_two = $this->uploadFixture( 'fixture-small.png' );
		$this->purgeQueueTable();

		$bulk  = new SpioBulk();
		$assoc = array(
			'queue' => 'media',
			'wait'  => 0,
		);

		$bulk->create( array(), $assoc );
		$this->assertStringContainsString( 'Bulk media created. Ready to prepare', WP_CLI::allText() );

		// prepare() forwards $assoc to run(); --ticks bounds the loop so a
		// wedged preparing phase fails instead of hanging the suite.
		$bulk->prepare( array(), array_merge( $assoc, array( 'ticks' => 25 ) ) );
		$this->assertStringContainsString( 'Bulk Preparing is done', WP_CLI::allText() );

		$bulk->start( array(), $assoc );
		$this->assertStringContainsString( 'Start signal for Bulk Processing given.', WP_CLI::allText() );

		$this->runCliTicks( $bulk, $assoc, 30, true );

		foreach ( array( $id_one, $id_two ) as $id ) {
			$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
			$this->assertTrue( $imageModel->isOptimized(), "Bulk run must optimize attachment $id.\n" . WP_CLI::allText() );
		}
	}
}
