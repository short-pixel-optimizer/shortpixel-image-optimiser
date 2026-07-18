<?php
/**
 * Base class for SPIO integration tests.
 *
 * Provides:
 *   - MockShortPixelApi lifecycle (registered in setUp, state reset per test);
 *   - a plausible verified-API-key settings baseline so the real pipeline
 *     doesn't bail out before reaching the (mocked) HTTP layer;
 *   - fixture upload helper that creates a REAL attachment (files on disk,
 *     WP metadata, thumbnails) from tests/fixtures/;
 *   - queue-driving helpers that loop the real queue until completion
 *     (Wave-1 decision: loop-driven ticks, no cron).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Controller\QuotaController;
use ShortPixel\Controller\Api\RequestManager;
use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Model\SettingsModel;

abstract class SPIO_IntegrationTestCase extends WP_UnitTestCase {

	/** @var MockShortPixelApi */
	protected $api;

	/** @var int[] Attachment ids created via uploadFixture(), cleaned in tearDown. */
	protected $uploadedAttachments = array();

	public function set_up() {
		parent::set_up();

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

	public function tear_down() {
		foreach ( $this->uploadedAttachments as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->uploadedAttachments = array();

		MockShortPixelApi::unregister();
		$this->resetPluginSingletons();

		parent::tear_down();
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
		// QuotaController caches quota) — must re-read per test.
		foreach ( array( SettingsModel::class, ApiKeyController::class, QuotaController::class ) as $class ) {
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
	}

	/**
	 * Empty the ShortQ queue table.
	 *
	 * ShortQ persists items in {$prefix}shortpixel_queue. Creating that
	 * table mid-test (DDL) implicitly commits the wrapping test
	 * transaction, so queued items leak across tests/runs unless purged.
	 */
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
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->query( "DELETE FROM `$table`" );
		}
	}

	protected function purgeQueueTable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->query( "DELETE FROM `$table`" );
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
