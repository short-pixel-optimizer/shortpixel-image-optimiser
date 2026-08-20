<?php
/**
 * Shared integration-test helpers (trait).
 *
 * Holds everything SPIO integration tests need regardless of their WP base
 * class: the MockShortPixelApi lifecycle, the healthy-install settings
 * baseline, fixture upload helpers, queue-driving loops and the singleton /
 * table hygiene that keeps tests independent.
 *
 * Lives in a trait because the suite needs the SAME baseline under two
 * different WP base classes: WP_UnitTestCase (SPIO_IntegrationTestCase) and
 * WP_Ajax_UnitTestCase (SPIO_AjaxTestCase) — PHP has no multiple
 * inheritance, and duplicating this much load-bearing setup would drift.
 *
 * Consumers call spioSetUpBaseline() / spioTearDownBaseline() from their
 * set_up() / tear_down() around the parent calls.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\QuotaController;
use ShortPixel\Controller\Api\RequestManager;
use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Model\SettingsModel;

trait SPIO_IntegrationHelpers {

	/** @var MockShortPixelApi */
	protected $api;

	/** @var int[] Attachment ids created via uploadFixture(), cleaned in tearDown. */
	protected $uploadedAttachments = array();

	/**
	 * Per-test baseline: mock API registered, plugin singletons dropped, a
	 * verified key + healthy settings, and empty queue/meta tables.
	 *
	 * Call AFTER parent::set_up().
	 */
	protected function spioSetUpBaseline(): void {
		$this->api = MockShortPixelApi::register();
		$this->api->reset();

		$this->resetPluginSingletons();

		// Baseline: verified key, backups on, quota fine — the state of a
		// healthy paying install. Individual tests override as needed.
		//
		// The API key lives in its own 'spio_key' option (ApiKeyModel), not
		// in SettingsModel. checkKey() short-circuits to $verifiedKey when
		// the loaded key equals the stored apiKey, so a matching 20-char
		// key + verifiedKey=true passes without any remote validation.
		update_option(
			'spio_key',
			array(
				'apiKey'      => str_repeat( 'a', 20 ),
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);

		$settings                     = \wpSPIO()->settings();
		$settings->quotaExceeded      = 0;
		$settings->backupImages       = 1;
		$settings->autoMediaLibrary   = 1;
		$settings->redirectedSettings = 1;

		// The settings baseline above must be visible to ApiKeyController /
		// QuotaController, which cache state at construction — drop them
		// AFTER writing settings so their next getInstance() reloads.
		$this->resetPluginSingletons();

		$this->purgeQueueTable();
		$this->purgeMetaTable();
	}

	/** Per-test cleanup mirror of spioSetUpBaseline(). Call BEFORE parent::tear_down(). */
	protected function spioTearDownBaseline(): void {
		foreach ( $this->uploadedAttachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->uploadedAttachments = array();

		MockShortPixelApi::unregister();
		$this->resetPluginSingletons();
	}

	// -------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------

	/** Absolute path of a file in tests/fixtures/. */
	protected function fixturePath( string $name ): string {
		$path = dirname( __DIR__, 2 ) . '/fixtures/' . $name;
		if ( ! file_exists( $path ) ) {
			$this->fail( "Fixture not found: $path — fixtures live in tests/fixtures/." );
		}
		return $path;
	}

	/**
	 * Upload a fixture as a real Media Library attachment.
	 *
	 * Copies the fixture into the WP uploads dir, creates the attachment
	 * post, and generates full attachment metadata — which produces real
	 * thumbnail files and, for images over the big-image threshold
	 * (2560px), the `-scaled` main file.
	 *
	 * @param string $fixture Filename inside tests/fixtures/.
	 * @return int Attachment id.
	 */
	protected function uploadFixture( string $fixture ): int {
		return $this->uploadFile( $this->fixturePath( $fixture ) );
	}

	/** Upload an arbitrary file on disk as a real Media Library attachment. */
	protected function uploadFile( string $source ): int {
		$uploads = wp_upload_dir();

		$target = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], basename( $source ) );
		copy( $source, $target );

		$filetype = wp_check_filetype( basename( $target ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'],
				'post_title'     => sanitize_file_name( pathinfo( $target, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$target
		);
		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id, 'wp_insert_attachment must succeed for ' . basename( $source ) );

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $target );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$this->uploadedAttachments[] = $attachment_id;

		return $attachment_id;
	}

	// -------------------------------------------------------------------
	// Queue driving
	// -------------------------------------------------------------------

	/**
	 * Enqueue one attachment for optimization and tick the queue until the
	 * item is done (or $maxTicks safety valve trips).
	 *
	 * @param int   $attachment_id Attachment to optimize.
	 * @param array $args          Extra args for addItemToQueue.
	 * @param int   $maxTicks      Safety limit on queue ticks.
	 * @return void
	 */
	protected function optimizeAttachment( int $attachment_id, array $args = array(), int $maxTicks = 25 ): void {
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$this->assertNotFalse( $imageModel, "Could not load image model for attachment $attachment_id" );

		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel, $args );

		$this->runQueueUntilEmpty( $maxTicks );
	}

	/**
	 * Loop-drive the media + custom queues until both report no more work.
	 *
	 * @param int $maxTicks Safety limit; the test fails when exceeded so a
	 *                      stuck queue surfaces as a failure, not a hang.
	 */
	protected function runQueueUntilEmpty( int $maxTicks = 25 ): void {
		$queueController = new QueueController();

		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$queueController->processQueue( array( 'media', 'custom' ) );

			if ( ! $this->queueHasWork() ) {
				return;
			}

			// ShortQ only retries IN_PROCESS items after process_timeout
			// (10s wall clock). Backdate items so the next tick picks them
			// up immediately instead of the test sleeping.
			$this->backdateQueueItems();
		}

		$this->fail( "Queue still has work after $maxTicks ticks — pipeline stuck (check mock responses / item errors)." );
	}

	/** Whether any SPIO queue still holds in-process or waiting items. */
	protected function queueHasWork(): bool {
		$queueController = new QueueController();
		foreach ( array( 'media', 'custom' ) as $type ) {
			$q     = $queueController->getQueue( $type );
			$stats = $q->getStats();
			if ( is_object( $stats ) && ( $stats->in_queue > 0 || $stats->in_process > 0 ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------
	// Plugin state hygiene
	// -------------------------------------------------------------------

	/**
	 * Reset SPIO singletons that cache cross-test state.
	 *
	 * RequestManager subclasses (ApiController, OptimizeController, …) keep
	 * a static $instances map; SettingsModel caches the settings row. Both
	 * must be dropped so each test reads fresh DB state.
	 */
	protected function resetPluginSingletons(): void {
		$ref = new ReflectionClass( RequestManager::class );
		if ( $ref->hasProperty( 'instances' ) ) {
			$prop = $ref->getProperty( 'instances' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		}

		// Standalone singletons that cache settings-derived state at
		// construction time (ApiKeyController loads + verifies the key,
		// QuotaController caches quota, MultiSettingsModel caches the
		// spio_wpmu network option) — must re-read per test.
		foreach ( array( SettingsModel::class, \ShortPixel\Model\MultiSettingsModel::class, ApiKeyController::class, QuotaController::class ) as $class ) {
			$ref = new ReflectionClass( $class );
			if ( $ref->hasProperty( 'instance' ) ) {
				$prop = $ref->getProperty( 'instance' );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		}

		// OtherMediaController caches the folders-table existence and custom-
		// image counts in statics; stale values hide folders/images created
		// or purged by other tests.
		$ref = new ReflectionClass( \ShortPixel\Controller\OtherMediaController::class );
		foreach ( array( 'instance', 'hasFoldersTable', 'hasCustomImages' ) as $name ) {
			if ( $ref->hasProperty( $name ) ) {
				$prop = $ref->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, null );
			}
		}

		// BackupController picks No/Local backup based on settings at first
		// call and caches BackupModels per attachment id — WP test rollbacks
		// reuse attachment ids, so a stale cache would poison later tests.
		$ref = new ReflectionClass( BackupController::class );
		foreach ( array( 'instance' => null, 'models' => array(), 'model' => null ) as $name => $empty ) {
			if ( $ref->hasProperty( $name ) ) {
				$prop = $ref->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, $empty );
			}
		}

		// FileSystemController keeps loaded image models in static caches.
		// Model objects cache derived state (e.g. optimizePrevented) that is
		// NOT refreshed when the underlying post meta changes, so a "fresh"
		// getImage() after a mutation would still return the stale object.
		$ref = new ReflectionClass( \ShortPixel\Controller\FileSystemController::class );
		foreach ( array( 'mediaItems', 'customItems' ) as $name ) {
			if ( $ref->hasProperty( $name ) ) {
				$prop = $ref->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, array() );
			}
		}
	}

	/** Make all queued items immediately eligible for (re)processing. */
	protected function backdateQueueItems(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';
		$wpdb->query( "UPDATE `$table` SET updated = '2000-01-01 00:00:00'" );
	}

	/**
	 * Empty the shortpixel_postmeta table (per-test, from set_up only).
	 *
	 * Like the queue table it is created mid-test via DDL (implicit commit),
	 * so its rows survive the WP test-transaction rollback while attachment
	 * ids get reused — leftover rows would make a FRESH upload look
	 * already-converted/optimized. Never call this mid-test: it would wipe
	 * the optimization meta of the attachments under test.
	 */
	protected function purgeMetaTable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_postmeta';

		// No SHOW TABLES guard: when the table is (re)created mid-run its DDL
		// is rewritten to CREATE TEMPORARY TABLE by the WP test framework, and
		// temporary tables are INVISIBLE to SHOW TABLES — the guard would skip
		// the purge while the table (and its rows) very much exist.
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `$table`" );
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Empty the ShortQ queue table.
	 *
	 * ShortQ persists items in {$prefix}shortpixel_queue. Creating that
	 * table mid-test (DDL) implicitly commits the wrapping test
	 * transaction, so queued items leak across tests/runs unless purged.
	 */
	protected function purgeQueueTable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';

		// No SHOW TABLES guard — see purgeMetaTable(): the table can be a
		// session TEMPORARY table (WPQ recreates it via dbDelta when its
		// shortqwp_* status option is missing, and the WP test framework
		// rewrites that DDL), which SHOW TABLES cannot see.
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `$table`" );
		$wpdb->suppress_errors( $suppress );

		// Queue::getStats() reads CACHED counters from shortqwp_* options —
		// not live row counts. Purging the table without dropping these would
		// leave queueHasWork() reporting phantom items.
		$status_options = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'shortqwp\_%'"
		);
		foreach ( $status_options as $option_name ) {
			delete_option( $option_name );
		}

		// ShortQ also caches items in memory (WPQ::$itemCache) and queue
		// objects (ShortQ::$queues); without clearing these, a DB purge is
		// invisible — isItemInQueue() re-finds the cached item and even
		// writes it back to the table via updateItem().
		foreach ( array(
			\ShortPixel\ShortQ\Queue\WPQ::class          => 'itemCache',
			\ShortPixel\ShortQ\ShortQ::class             => 'queues',
			\ShortPixel\Controller\Queue\Queue::class    => 'isInQueue',
		) as $class => $name ) {
			$ref = new ReflectionClass( $class );
			if ( $ref->hasProperty( $name ) ) {
				$prop = $ref->getProperty( $name );
				$prop->setAccessible( true );
				$prop->setValue( null, array() );
			}
		}
	}
}
