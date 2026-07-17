<?php
/**
 * Integration tests: upload → optimize → verify (Wave 1).
 *
 * Drives the REAL optimization pipeline — QueueController, ShortQ queue,
 * OptimizeController, ApiController request building, response parsing,
 * DownloadHelper file download, meta persistence — against a real
 * attachment in the WP test install. Only the HTTP layer is mocked
 * (MockShortPixelApi), so a change anywhere in the pipeline that breaks
 * the end-to-end flow fails here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\ImageModel;

class OptimizePipelineTest extends SPIO_IntegrationTestCase {

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		$fs = \wpSPIO()->filesystem();
		return $fs->getImage( $attachment_id, 'media', false );
	}

	// -------------------------------------------------------------------
	// Main file optimization
	// -------------------------------------------------------------------

	public function test_optimize_small_jpg_marks_main_file_optimized() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must be marked optimized after the queue completes.'
		);
	}

	public function test_optimize_sends_reducer_request_with_urllist() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->optimizeAttachment( $id );

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty( $reducerCalls, 'The pipeline must call the reducer endpoint.' );

		$call = array_values( $reducerCalls )[0];
		$this->assertIsArray( $call['request'], 'Reducer request body must be valid JSON.' );
		$this->assertArrayHasKey( 'urllist', $call['request'] );
		$this->assertNotEmpty( $call['request']['urllist'] );
	}

	public function test_optimize_writes_smaller_file_to_disk() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$originalPath = get_attached_file( $id );
		$originalSize = filesize( $originalPath );

		$this->optimizeAttachment( $id );

		clearstatcache();
		$this->assertFileExists( $originalPath );
		$this->assertLessThan(
			$originalSize,
			filesize( $originalPath ),
			'Optimized main file on disk must be smaller than the original.'
		);
	}

	// -------------------------------------------------------------------
	// Thumbnails
	// -------------------------------------------------------------------

	public function test_optimize_covers_all_generated_thumbnails() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture must be large enough to generate thumbnails.' );

		$this->optimizeAttachment( $id );

		$image      = $this->freshImageModel( $id );
		$thumbnails = $image->get( 'thumbnails' );
		$this->assertNotEmpty( $thumbnails, 'Image model must expose thumbnails.' );

		foreach ( $thumbnails as $sizeName => $thumbnail ) {
			$this->assertTrue(
				$thumbnail->isOptimized(),
				"Thumbnail '$sizeName' must be optimized."
			);
		}
	}

	// -------------------------------------------------------------------
	// WebP / AVIF companions
	// -------------------------------------------------------------------

	public function test_optimize_with_createWebp_writes_webp_companion() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		$webp = $image->getWebp();
		$this->assertNotFalse( $webp, 'Image model must expose a WebP companion.' );
		$this->assertTrue( $webp->exists(), 'WebP companion file must exist on disk: ' . $webp->getFullPath() );
	}

	public function test_optimize_with_createAvif_writes_avif_companion() {
		\wpSPIO()->settings()->createAvif = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		$avif = $image->getAvif();
		$this->assertNotFalse( $avif, 'Image model must expose an AVIF companion.' );
		$this->assertTrue( $avif->exists(), 'AVIF companion file must exist on disk: ' . $avif->getFullPath() );
	}

	public function test_optimize_without_conversion_flags_writes_no_companions() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		$webp = $image->getWebp();
		$avif = $image->getAvif();
		$this->assertFalse( $webp !== false && $webp->exists(), 'No WebP file expected when createWebp is off.' );
		$this->assertFalse( $avif !== false && $avif->exists(), 'No AVIF file expected when createAvif is off.' );
	}

	public function test_optimize_with_createWebp_covers_thumbnails() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image      = $this->freshImageModel( $id );
		$thumbnails = $image->get( 'thumbnails' );
		$this->assertNotEmpty( $thumbnails );

		foreach ( $thumbnails as $sizeName => $thumbnail ) {
			$webp = $thumbnail->getWebp();
			$this->assertNotFalse( $webp, "Thumbnail '$sizeName' must expose a WebP companion." );
			$this->assertTrue( $webp->exists(), "Thumbnail '$sizeName' WebP file must exist on disk." );
		}
	}

	// -------------------------------------------------------------------
	// -scaled handling (large fixture)
	// -------------------------------------------------------------------

	public function test_large_upload_produces_scaled_file_and_optimizes_it() {
		$id = $this->uploadFixture( 'fixture-large.jpg' );

		$mainPath = get_attached_file( $id );
		$this->assertStringContainsString(
			'-scaled',
			basename( $mainPath ),
			'A 3200px upload must produce a -scaled main file (big-image threshold).'
		);

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'The -scaled main file must be optimized.' );
	}
}
