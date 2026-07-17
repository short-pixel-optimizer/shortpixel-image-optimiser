<?php
/**
 * Integration tests: bulk optimization (Wave 1, high-level end-to-end).
 *
 * Drives the REAL bulk machinery: BulkController::createNewBulk() (prepare
 * phase scans the media library in batches), startBulk(), then loop-driven
 * processQueue() ticks on the bulk queues until the run reports finished.
 *
 * Scope: natively processable raster formats (jpg / png / gif / webp).
 * heic / tiff / bmp go through ApiConverter — a separate API conversion
 * endpoint the HTTP mock does not support yet (Wave 2, with the
 * converter-endpoint mock).
 *
 * pdf is EXCLUDED from the happy-path tests and covered by a pinned test
 * instead: DownloadHelper rejects any download that is not an image unless
 * its extension is whitelisted ('pdf'), but download_url() temp files
 * always end in `.tmp` (wp_tempnam), so the whitelist never matches and
 * PDF optimization always fails at the download step. Broken since
 * 9cd33e9c (2026-04-01); the attempted fix 5c63ce9e checks the wrong
 * extension. Real production bug — reported to Bas.
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

		$this->fail( "Bulk queue not finished after $maxTicks ticks — stats: " . wp_json_encode( $stats ) );
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
}
