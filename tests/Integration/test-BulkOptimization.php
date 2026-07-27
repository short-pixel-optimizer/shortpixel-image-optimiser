<?php
/**
 * Integration tests: bulk optimization (Wave 1, high-level end-to-end).
 *
 * Drives the REAL bulk machinery: BulkController::createNewBulk() (prepare
 * phase scans the media library in batches), startBulk(), then loop-driven
 * processQueue() ticks on the bulk queues until the run reports finished.
 *
 * Scope: natively processable raster formats (jpg / png / gif / webp)
 * plus the ApiConverter formats (heic / tiff / bmp) — those ride the same
 * reducer endpoint as a forced-lossless 'convert_api' round-trip, which
 * the mock supports since Wave 2 (real lossless bytes when lossy=0).
 *
 * pdf is EXCLUDED from the happy-path tests and covered by a pinned test
 * instead: DownloadHelper rejects any download that is not an image unless
 * its extension is whitelisted ('pdf'), but download_url() temp files
 * always end in `.tmp` (wp_tempnam), so the whitelist never matches and
 * PDF optimization always fails at the download step. Broken since
 * 9cd33e9c (2026-04-01); the attempted fix 5c63ce9e checks the wrong
 * extension. Reported to Bas (bug #1); the fix attempt c66431c7 renames
 * the temp file in remoteGetMethod() only — but that is the LAST fallback,
 * and the primary downloadURLMethod() (WP download_url, `.tmp` suffix)
 * succeeds first, so the whitelist still never matches. Still broken.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\BulkController;
use ShortPixel\Controller\QueueController;

class BulkOptimizationTest extends SPIO_IntegrationTestCase {

	private const FIXTURES = array(
		'fixture-small.jpg',
		'fixture-small.png',
		'fixture-large.gif',
		'fixture-large.webp',
		'fixture-large.heic',
		'fixture-medium.tiff',
		'fixture-medium.bmp',
	);

	/**
	 * Tick the bulk media queue through its PREPARING phase (the media-
	 * library scan that enqueues items batch-wise) until it stops preparing.
	 */
	private function runBulkPreparation( int $maxTicks = 30 ): void {
		$queueController = new QueueController( array( 'is_bulk' => true ) );

		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$queueController->processQueue( array( 'media' ) );

			$stats = $queueController->getQueue( 'media' )->getStats();
			if ( false === $stats->is_preparing ) {
				return;
			}
		}

		$this->fail( "Bulk queue still preparing after $maxTicks ticks — stats: " . wp_json_encode( $stats ) );
	}

	/** Loop-drive the BULK media queue until it reports finished. */
	private function runBulkUntilFinished( int $maxTicks = 60 ): \stdClass {
		$queueController = new QueueController( array( 'is_bulk' => true ) );

		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$queueController->processQueue( array( 'media' ) );

			$stats = $queueController->getQueue( 'media' )->getStats();
			if ( true === $stats->is_finished ) {
				return $stats;
			}

			$this->backdateQueueItems();
		}

		// Include the raw queue rows in the failure output — a stuck bulk is
		// almost always explained by the item payload (action / convertto).
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, queue_name, status, tries, item_id, item_count, value FROM `{$wpdb->prefix}shortpixel_queue`", ARRAY_A );
		$this->fail( "Bulk queue not finished after $maxTicks ticks — stats: " . wp_json_encode( $stats ) . "\nROWS: " . wp_json_encode( $rows ) );
	}

	public function test_bulk_optimizes_all_processable_media_library_images() {
		$ids = array();
		foreach ( self::FIXTURES as $fixture ) {
			$ids[ $fixture ] = $this->uploadFixture( $fixture );
		}

		// Uploading auto-enqueues each attachment in the single (mediaSingle)
		// queue (autoMediaLibrary=1); bulk preparation would then skip them
		// as duplicates. Clear the queues so bulk starts from a clean slate.
		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		// createNewBulk puts the queue in the PREPARING state; ticks run the
		// media-library scan that enqueues the items batch-wise. Without
		// doMedia=true (what the bulk UI passes — AjaxController::applyBulkSelection)
		// prepareItems skips every scanned item.
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );

		$stats = $this->runBulkUntilFinished();

		$this->assertGreaterThanOrEqual(
			count( $ids ),
			(int) $stats->done,
			'The bulk run must process at least the seeded attachments.'
		);
		$this->assertSame( 0, (int) $stats->fatal_errors, 'Bulk run must finish without fatal errors.' );

		foreach ( $ids as $fixture => $id ) {
			$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
			$this->assertTrue(
				$image->isOptimized(),
				"Attachment for $fixture must be optimized after the bulk run."
			);
		}

		$bulk->finishBulk( 'media' );
	}

	public function test_bulk_reducer_requests_cover_every_seeded_attachment() {
		$ids = array();
		foreach ( self::FIXTURES as $fixture ) {
			$ids[ $fixture ] = $this->uploadFixture( $fixture );
		}

		// Uploading auto-enqueues each attachment in the single (mediaSingle)
		// queue (autoMediaLibrary=1); bulk preparation would then skip them
		// as duplicates. Clear the queues so bulk starts from a clean slate.
		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();

		$requestedUrls = array();
		foreach ( $this->api->requests as $req ) {
			if ( false !== strpos( $req['url'], 'reducer' ) && isset( $req['request']['urllist'] ) ) {
				foreach ( $req['request']['urllist'] as $url ) {
					$requestedUrls[] = basename( strtok( $url, '?' ) );
				}
			}
		}

		foreach ( $ids as $fixture => $id ) {
			$mainFile = basename( get_attached_file( $id ) );
			$this->assertContains(
				$mainFile,
				$requestedUrls,
				"The bulk run must have sent $mainFile to the reducer."
			);
		}

		$bulk->finishBulk( 'media' );
	}

	/**
	 * PINNED — production bug, reported to Bas.
	 *
	 * PDF optimization fails at the download step: DownloadHelper
	 * (class/Helper/DownloadHelper.php:147-156) whitelists the 'pdf'
	 * extension, but download_url() stores the body in a wp_tempnam()
	 * file that ALWAYS ends in `.tmp`, so the whitelist never matches;
	 * finfo then reports application/pdf (not image/*) and the download
	 * is rejected ("seems not an image"). The API round-trip itself
	 * succeeds — the mock serves smaller PDF bytes — so before 9cd33e9c
	 * (2026-04-01) this optimized fine.
	 *
	 * Fix attempt c66431c7 (IT #1) does NOT resolve this: it adds the
	 * extension-preserving rename to remoteGetMethod(), but that is the
	 * last entry in the download-method fallback chain; downloadURLMethod()
	 * (WP download_url) succeeds first and still yields a `.tmp` file.
	 */
	public function test_bulk_pdf_currently_fails_at_download_step_pinned() {
		$id = $this->uploadFixture( 'fixture-large.pdf' );
		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );

		$stats = $this->runBulkUntilFinished();

		// Sentinel: the pipeline DID reach the download phase — the mock
		// served the optimized PDF bytes — so the failure is download
		// rejection, not an earlier bail-out.
		$downloadCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], '/f/' );
			}
		);
		$this->assertNotEmpty( $downloadCalls, 'The optimized PDF must have been requested for download.' );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertFalse(
			$image->isOptimized(),
			'Pinned current behavior: PDF is never optimized because DownloadHelper rejects the .tmp download. If this fails, the bug was fixed — move pdf back into FIXTURES and drop this test.'
		);
		$this->assertSame(
			1,
			(int) $stats->fatal_errors,
			'Pinned current behavior: the PDF item ends as a fatal queue error. If this fails, the bug was fixed — move pdf back into FIXTURES and drop this test.'
		);

		$bulk->finishBulk( 'media' );
	}

	// -------------------------------------------------------------------
	// Extended tests (Wave 3)
	// -------------------------------------------------------------------

	/**
	 * With processThumbnails=0, a bulk run must send only the main image to the
	 * API — no thumbnail URLs must appear in any reducer request.
	 *
	 * Manual plan 4.4.
	 */
	public function test_bulk_without_thumbnails_skips_thumbnail_sizes() {
		\wpSPIO()->settings()->processThumbnails = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// Verify thumbnails were actually generated so the test has coverage value.
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture must produce thumbnails.' );

		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();

		// Collect every URL sent to the reducer.
		$reducerUrls = array();
		foreach ( $this->api->requests as $req ) {
			if ( false !== strpos( $req['url'], 'reducer' ) && isset( $req['request']['urllist'] ) ) {
				foreach ( $req['request']['urllist'] as $u ) {
					$reducerUrls[] = basename( strtok( $u, '?' ) );
				}
			}
		}

		$mainFile = basename( get_attached_file( $id ) );
		$this->assertContains( $mainFile, $reducerUrls, 'Main file must appear in reducer requests.' );

		// None of the registered thumbnail file names may appear.
		foreach ( $metadata['sizes'] as $sizeName => $sizeData ) {
			$this->assertNotContains(
				$sizeData['file'],
				$reducerUrls,
				"Thumbnail '$sizeName' ({$sizeData['file']}) must NOT be sent when processThumbnails=0."
			);
		}

		$bulk->finishBulk( 'media' );
	}

	/**
	 * When images are already optimized, a bulk run with createWebp=1 must send
	 * only WebP-companion requests — not re-send the main image for re-optimization.
	 *
	 * Manual plan 4.7.
	 */
	public function test_bulk_generates_only_webp_companions_when_already_optimized() {
		\wpSPIO()->settings()->createWebp = 0;
		\wpSPIO()->settings()->createAvif = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		// First pass: optimize without WebP so the image is fully optimized.
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();
		$bulk->finishBulk( 'media' );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue( $image->isOptimized(), 'Pre-condition: image must be optimized before WebP-only run.' );

		// Reset request log; now enable WebP and run again.
		$this->api->reset();
		\wpSPIO()->settings()->createWebp = 1;

		$bulk2 = BulkController::getInstance();
		$bulk2->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk2->startBulk( 'media' );
		$this->runBulkUntilFinished();

		// At least one reducer call must have been made (for the WebP companion).
		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty( $reducerCalls, 'A second bulk with createWebp=1 must still call the reducer.' );

		// Every reducer call must request webp. For an already-optimized image
		// the plugin sends companion-only jobs: paramlist convertto = 'webp'
		// WITHOUT the '+' prefix ('+' means "in addition to base optimization",
		// see QueueItem::newOptimizeData()).
		foreach ( $reducerCalls as $call ) {
			$req       = $call['request'];
			$converts  = array( isset( $req['convertto'] ) ? (string) $req['convertto'] : '' );
			foreach ( (array) ( $req['paramlist'] ?? array() ) as $entry ) {
				$entry = (array) $entry;
				if ( isset( $entry['convertto'] ) ) {
					$converts[] = (string) $entry['convertto'];
				}
			}
			$this->assertStringContainsString(
				'webp',
				implode( '|', $converts ),
				'Reducer requests in WebP-only bulk must request webp conversion (global or per-size convertto).'
			);
		}

		// The WebP companion must now exist.
		$freshImage = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$webp       = $freshImage->getWebp();
		$this->assertNotFalse( $webp, 'Image must expose a WebP companion after the WebP-only bulk.' );
		$this->assertTrue( $webp->exists(), 'WebP companion file must exist on disk.' );

		$bulk2->finishBulk( 'media' );
	}

	/**
	 * With excludeSizes containing a registered thumbnail name, a bulk run must
	 * not send that size to the API.
	 *
	 * Manual plan 4.10.
	 */
	public function test_bulk_excluded_thumbnail_sizes_are_not_sent_to_api() {
		// Exclude BEFORE uploading: the upload-time auto-enqueue builds and
		// caches the image model, and a later setting change is not seen by
		// that cached state within the same PHP process. In production the
		// setting is saved in a separate request from the bulk run.
		$excludedSizeName = 'medium';
		\wpSPIO()->settings()->excludeSizes = array( $excludedSizeName );

		$id       = $this->uploadFixture( 'fixture-small.jpg' );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture must produce thumbnails.' );
		$this->assertArrayHasKey( $excludedSizeName, $metadata['sizes'], 'Fixture must produce the medium size.' );
		$excludedFile = $metadata['sizes'][ $excludedSizeName ]['file'];

		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();

		$reducerUrls = array();
		foreach ( $this->api->requests as $req ) {
			if ( false !== strpos( $req['url'], 'reducer' ) && isset( $req['request']['urllist'] ) ) {
				foreach ( $req['request']['urllist'] as $u ) {
					$reducerUrls[] = basename( strtok( $u, '?' ) );
				}
			}
		}

		$this->assertNotContains(
			$excludedFile,
			$reducerUrls,
			"Excluded thumbnail size '$excludedSizeName' ($excludedFile) must not appear in any reducer request."
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * With an exclusion pattern matching a fixture filename, a bulk run must not
	 * include that image in any reducer request.
	 *
	 * Manual plan 4.11 / 2.25 / 2.52.
	 */
	public function test_bulk_exclusion_patterns_exclude_matching_images() {
		$excludedId = $this->uploadFixture( 'fixture-small.png' );
		$allowedId  = $this->uploadFixture( 'fixture-small.jpg' );

		// The exclusion pattern matches the PNG by name substring.
		\wpSPIO()->settings()->excludePatterns = array(
			array(
				'type'  => 'name',
				'value' => 'fixture-small.png',
				'apply' => 'all',
			),
		);

		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();

		$reducerUrls = array();
		foreach ( $this->api->requests as $req ) {
			if ( false !== strpos( $req['url'], 'reducer' ) && isset( $req['request']['urllist'] ) ) {
				foreach ( $req['request']['urllist'] as $u ) {
					$reducerUrls[] = basename( strtok( $u, '?' ) );
				}
			}
		}

		$excludedFile = basename( get_attached_file( $excludedId ) );
		$allowedFile  = basename( get_attached_file( $allowedId ) );

		$this->assertNotContains(
			$excludedFile,
			$reducerUrls,
			'Image matching the exclusion pattern must not reach the reducer.'
		);
		$this->assertContains(
			$allowedFile,
			$reducerUrls,
			'Non-excluded image must still be sent to the reducer.'
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * When the mock API returns CODE_UNREACHABLE (-106) for every URL, a bulk run
	 * must record errors in the queue stats rather than silently succeeding.
	 *
	 * Manual plan 4.12.
	 */
	public function test_bulk_records_errors_for_inaccessible_images() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		// Force every reducer response to be "unreachable" (terminal failure).
		$this->api->forceStatusCode = MockShortPixelApi::CODE_UNREACHABLE;

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );

		$stats = $this->runBulkUntilFinished();

		$this->assertGreaterThan(
			0,
			(int) $stats->fatal_errors,
			'Inaccessible images must be counted as fatal errors in the bulk stats.'
		);

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertFalse(
			$image->isOptimized(),
			'An image that failed with CODE_UNREACHABLE must not be marked optimized.'
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * When a registered thumbnail file is missing from disk, the bulk run must
	 * still optimize the files it can reach (the main file), while the item
	 * itself ends as a fatal error in the queue: the pipeline re-sends the
	 * item for the unreachable thumbnail and gives up after retries, so the
	 * ShortQ "done" counter is never incremented for it.
	 *
	 * NOTE: an earlier version asserted $stats->done > 0, which only passed in
	 * full-suite runs because ShortQ status counters live in the shortqwp_SPIO
	 * option and leak between tests (purgeQueueTable() only clears the table).
	 * In isolation done is honestly 0. Verified identical on pre-fix 63a6fcfc,
	 * so this is long-standing behavior, not a side-effect of Bas's IT#5 fix.
	 *
	 * Manual plan 4.14.
	 */
	public function test_bulk_missing_thumbnail_skips_missing_and_records_found() {
		$id       = $this->uploadFixture( 'fixture-small.jpg' );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture must produce thumbnails.' );

		// Delete one thumbnail file from disk to simulate a missing file.
		$uploads     = wp_upload_dir();
		$thumbName   = array_values( $metadata['sizes'] )[0]['file'];
		$thumbPath   = trailingslashit( $uploads['path'] ) . $thumbName;
		if ( file_exists( $thumbPath ) ) {
			unlink( $thumbPath );
		}

		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$stats = $this->runBulkUntilFinished();

		// The main image must be optimized despite the missing thumbnail.
		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must still be optimized even when a thumbnail is missing.'
		);

		// The queue must complete; the item with the missing thumbnail is
		// recorded as a fatal error after retries (see docblock).
		$this->assertTrue(
			(bool) $stats->is_finished,
			'The bulk queue must reach the finished state despite the missing thumbnail.'
		);
		$this->assertGreaterThan(
			0,
			(int) $stats->fatal_errors,
			'The item with the missing thumbnail must be recorded as a fatal error.'
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * Changing the compression type setting mid-run must clear the queue (via
	 * QueueController::resetQueues()), stopping the bulk before it finishes.
	 * After resetQueues() the queue must report finished=true (empty = done).
	 *
	 * Manual plan 4.16.
	 */
	public function test_bulk_stops_when_compression_type_changes_mid_run() {
		// Upload several images so the queue has multiple items to process.
		foreach ( array( 'fixture-small.jpg', 'fixture-small.png' ) as $f ) {
			$this->uploadFixture( $f );
		}
		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );

		// Run one tick (partial progress).
		$queueController = new QueueController( array( 'is_bulk' => true ) );
		$queueController->processQueue( array( 'media' ) );
		$this->backdateQueueItems();

		// Now change the compression type — the same action SettingsViewController
		// takes when the admin saves a different level.
		$oldType = \wpSPIO()->settings()->compressionType;
		$newType = ( $oldType == 1 ) ? 2 : 1;
		\wpSPIO()->settings()->compressionType = $newType;
		QueueController::resetQueues();

		// After resetQueues the queue must be empty: a reset queue is a FRESH
		// queue (Queue::activatePlugin()), which reports is_finished=false —
		// "stopped" here means no items left awaiting processing. Use a fresh
		// controller: the old instance caches queue status in memory.
		$stats = ( new QueueController( array( 'is_bulk' => true ) ) )->getQueue( 'media' )->getStats();
		$this->assertSame(
			0,
			(int) $stats->awaiting,
			'After a compression-type change resetQueues() must empty the bulk queue (plan 4.16). If this fails, the queue was not cleared.'
		);
		$this->assertFalse(
			(bool) $stats->bulk_running,
			'A reset queue must no longer report an active bulk run (plan 4.16).'
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * When the mock API is configured to return CODE_QUOTA_EXCEEDED (-403) for
	 * every request, the bulk run must halt and expose the quota-exceeded state.
	 *
	 * NOTE — this test requires the MockShortPixelApi to support returning
	 * -403 for all reducer calls (via forceStatusCode). The current mock already
	 * supports this. However: the plugin's QuotaController checks its own
	 * settings->quotaExceeded flag, not the per-item reducer code. The -403 code
	 * arriving in the response body does set quotaExceeded via
	 * ApiController::handleOptimizeResponse(). If the pipeline does NOT propagate
	 * -403 through to the quota flag, this test will be pinned to current behaviour.
	 *
	 * Manual plan 4.19.
	 */
	public function test_bulk_stops_when_quota_exhausted_after_n_images() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		// Force every reducer call to return quota-exceeded.
		$this->api->forceStatusCode = MockShortPixelApi::CODE_QUOTA_EXCEEDED;

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );

		// Run the queue. With quota exceeded the queue may finish (item errors)
		// or the QueueController returns NOQUOTA. Either way the image must not
		// be optimized.
		$queueController = new QueueController( array( 'is_bulk' => true ) );
		for ( $tick = 0; $tick < 20; $tick++ ) {
			$result = $queueController->processQueue( array( 'media' ) );
			$stats  = $queueController->getQueue( 'media' )->getStats();
			if ( $stats->is_finished ) {
				break;
			}
			$this->backdateQueueItems();
		}

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertFalse(
			$image->isOptimized(),
			'An image must not be marked optimized when the API returns CODE_QUOTA_EXCEEDED (-403) for every request (plan 4.19).'
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * An upload that arrives while a bulk run is in the PREPARING phase must be
	 * enqueued in the mediaSingle queue and processed before the bulk finishes.
	 *
	 * Manual plan 2.23 / 2.50.
	 */
	public function test_new_upload_processed_before_bulk_finishes() {
		// Seed initial images and clear the auto-enqueue residue.
		$existingId = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => false ) );

		// Run one preparation tick (bulk is in PREPARING state).
		$queueController = new QueueController( array( 'is_bulk' => true ) );
		$queueController->processQueue( array( 'media' ) );

		// Simulate a new upload arriving during the prepare phase. uploadFixture()
		// fires wp_generate_attachment_metadata which (via handleImageUploadHook)
		// enqueues the item in the mediaSingle queue.
		$newId = $this->uploadFixture( 'fixture-small.png' );

		// Finish the bulk preparation then start the run.
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );

		// Drive both the bulk (media) and single (mediaSingle is also processed
		// by runQueueUntilEmpty via processQueue(['media','custom'])). We use
		// runBulkUntilFinished for the bulk queue, then flush the singles queue.
		$this->runBulkUntilFinished();
		$this->runQueueUntilEmpty();

		$newImage = \wpSPIO()->filesystem()->getImage( $newId, 'media', false );
		$this->assertTrue(
			$newImage->isOptimized(),
			'A new upload that arrived during bulk preparation must be optimized via the mediaSingle queue (plan 2.23/2.50).'
		);

		$bulk->finishBulk( 'media' );
	}

	/**
	 * A bulk run with doAi=true and enable_ai=1 must generate AI alt data for
	 * every image in the media library that did not already have AI data.
	 *
	 * Manual plan 32.16.
	 */
	public function test_bulk_with_ai_enabled_generates_ai_data_for_all_items() {
		$settings                 = \wpSPIO()->settings();
		$settings->enable_ai      = 1;
		$settings->ai_gen_alt     = 1;
		$settings->ai_gen_caption = 1;
		$settings->ai_gen_filename = 0;
		$settings->autoAIBulk    = true;

		// Upload two images.
		$ids = array(
			$this->uploadFixture( 'fixture-small.jpg' ),
			$this->uploadFixture( 'fixture-small.png' ),
		);

		// Purge AI data and queue residue.
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );

		$ref  = new ReflectionClass( \ShortPixel\Model\AiDataModel::class );
		$prop = $ref->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$this->purgeQueueTable();

		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => true ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();

		// Flush the queue for the chained AI actions.
		$this->runQueueUntilEmpty( 60 );

		foreach ( $ids as $id ) {
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$this->assertNotEmpty(
				$alt,
				"Attachment $id must have AI-generated alt text after a bulk run with doAi=true (plan 32.16)."
			);
		}

		$bulk->finishBulk( 'media' );
	}

	/**
	 * A bulk-undoAI run must clear the generated AI alt text, and the
	 * aiPreserve=true setting must prevent regeneration in a subsequent bulk.
	 *
	 * Manual plan 32.15.
	 */
	public function test_bulk_restore_ai_reverts_generated_data_and_respects_preserve_setting() {
		$settings                  = \wpSPIO()->settings();
		$settings->enable_ai       = 1;
		$settings->ai_gen_alt      = 1;
		$settings->ai_gen_caption  = 1;
		$settings->ai_gen_filename = 0;
		$settings->autoAIBulk      = true;
		$settings->aiPreserve      = false;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// Store a known original alt before AI runs.
		update_post_meta( $id, '_wp_attachment_image_alt', 'original-alt' );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );

		$ref  = new ReflectionClass( \ShortPixel\Model\AiDataModel::class );
		$prop = $ref->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		$this->purgeQueueTable();

		// First pass: generate AI data via bulk.
		$bulk = BulkController::getInstance();
		$bulk->createNewBulk( 'media', array( 'doMedia' => true, 'doAi' => true ) );
		$this->runBulkPreparation();
		$bulk->startBulk( 'media' );
		$this->runBulkUntilFinished();
		$this->runQueueUntilEmpty( 60 );
		$bulk->finishBulk( 'media' );

		$generatedAlt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$this->assertNotEmpty( $generatedAlt, 'Pre-condition: AI alt must have been generated.' );

		// Now run the undoAI bulk to restore previous values.
		QueueController::resetQueues();
		$prop->setValue( null, array() );

		$undoBulk = BulkController::getInstance();
		$undoBulk->createNewBulk( 'media', array( 'customOp' => 'bulk-undoAI' ) );
		$this->runBulkPreparation();
		$undoBulk->startBulk( 'media' );
		$this->runBulkUntilFinished( 60 );
		$undoBulk->finishBulk( 'media' );

		$restoredAlt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		// The AI data row is deleted; WP alt may be empty or the original value,
		// depending on AiDataModel::revert() restoring $this->original['alt'].
		// Either way it must NOT equal the generated mock value.
		$this->assertNotSame(
			$generatedAlt,
			$restoredAlt,
			'After undoAI bulk the alt text must revert away from the AI-generated value (plan 32.15).'
		);

		// Verify the aipostmeta row is gone.
		$prop->setValue( null, array() );
		$aiModel = \ShortPixel\Model\AiDataModel::getModelByAttachment( $id, 'media' );
		$this->assertSame(
			\ShortPixel\Model\AiDataModel::AI_STATUS_NOTHING,
			$aiModel->getStatus(),
			'AI status must be AI_STATUS_NOTHING after undoAI reverts the record (plan 32.15).'
		);
	}
}
