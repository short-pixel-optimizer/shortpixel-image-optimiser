<?php
/**
 * Integration tests: corrupted / broken image handling (Wave 3).
 *
 * Two corruption flavors, generated at runtime (not committed fixtures):
 *   - garbage bytes with a .jpg extension (never a valid image);
 *   - a truncated real JPEG (valid header, body cut off — getimagesize()
 *     still reads dimensions, so WP accepts it further into the pipeline).
 *
 * The real API answers such uploads with -201/-202 (invalid image /
 * unsupported format), which ApiController::handleResponse maps to
 * STATUS_ERROR — a permanent, no-retry failure. The mock's
 * forceStatusCode reproduces that. What we verify is the PLUGIN side:
 * no fatals anywhere in the pipeline, no false optimized state, the
 * broken file left untouched, and the queue not stuck.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class CorruptedImagesTest extends SPIO_IntegrationTestCase {

	const CODE_INVALID_IMAGE = -201;

	/** @var string[] Temp files to remove in tear_down. */
	private $tmpFiles = array();

	public function tear_down() {
		foreach ( $this->tmpFiles as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmpFiles = array();
		parent::tear_down();
	}

	/** Write bytes to a temp file with the given name and return its path. */
	private function makeTmpFile( string $name, string $bytes ): string {
		$path = trailingslashit( get_temp_dir() ) . $name;
		file_put_contents( $path, $bytes );
		$this->tmpFiles[] = $path;
		return $path;
	}

	private function enqueueAndDrain( int $attachment_id ): void {
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$this->assertNotFalse( $imageModel, 'Even a corrupted attachment must yield an image model, not a fatal.' );

		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );
		$this->runQueueUntilEmpty();
	}

	public function test_garbage_bytes_with_jpg_extension_fail_gracefully() {
		$this->api->forceStatusCode = self::CODE_INVALID_IMAGE;

		$source = $this->makeTmpFile( 'spio-garbage.jpg', str_repeat( 'not-an-image-', 512 ) );
		$id     = $this->uploadFile( $source );

		$originalBytes = file_get_contents( get_attached_file( $id ) );

		$this->enqueueAndDrain( $id );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertFalse( $image->isOptimized(), 'Garbage bytes must never end up marked optimized.' );

		clearstatcache();
		$this->assertSame(
			$originalBytes,
			file_get_contents( get_attached_file( $id ) ),
			'The broken file must be byte-identical after the failed optimization.'
		);
	}

	public function test_truncated_jpeg_fails_gracefully_and_is_not_retried_forever() {
		$this->api->forceStatusCode = self::CODE_INVALID_IMAGE;

		$fullBytes = file_get_contents( $this->fixturePath( 'fixture-small.jpg' ) );
		$source    = $this->makeTmpFile( 'spio-truncated.jpg', substr( $fullBytes, 0, (int) ( strlen( $fullBytes ) * 0.4 ) ) );

		// Header intact: WP reads dimensions and lets it into the pipeline.
		$this->assertNotFalse( getimagesize( $source ) );

		$id = $this->uploadFile( $source );
		$this->enqueueAndDrain( $id );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertFalse( $image->isOptimized() );

		// STATUS_ERROR is permanent: the queue must be fully drained, with no
		// waiting retry item left behind.
		$this->assertFalse( $this->queueHasWork(), 'A permanent file error must not leave a retrying item in the queue.' );
	}

	public function test_corrupted_attachment_can_still_be_deleted_cleanly() {
		$this->api->forceStatusCode = self::CODE_INVALID_IMAGE;

		$source = $this->makeTmpFile( 'spio-garbage-del.jpg', str_repeat( "\x00\xFF", 2048 ) );
		$id     = $this->uploadFile( $source );

		$this->enqueueAndDrain( $id );

		$path = get_attached_file( $id );
		wp_delete_attachment( $id, true );

		clearstatcache();
		$this->assertFileDoesNotExist( $path, 'Deleting a corrupted attachment must remove its file without SPIO fatals.' );
	}
}
