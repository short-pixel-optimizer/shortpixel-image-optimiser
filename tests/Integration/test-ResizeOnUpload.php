<?php
/**
 * Integration tests: resize-on-upload (Wave 2).
 *
 * With resizeImages enabled, the reducer request carries resize
 * (1 = outer/contain, 3 = inner/cover) + resize_width/resize_height, and the
 * API returns the main image scaled down server-side. Client-side,
 * ImageModel::handleOptimized() compares the downloaded dimensions against
 * originalWidth/originalHeight meta, records resize/resizeWidth/resizeHeight
 * meta, and MediaLibraryModel folds the new dimensions into
 * _wp_attachment_metadata.
 *
 * The mock honors the resize params via GD scaling (MockShortPixelApi::
 * resizedVariantBytes), so the full detect-and-record path runs for real.
 *
 * fixture-small.jpg is 1200x900 — safely under the big-image threshold
 * (2560), so no `-scaled` file complicates the main-file assertions.
 *
 * @package Shortpixel_Image_Optimiser
 */

class ResizeOnUploadTest extends SPIO_IntegrationTestCase {

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	private function enableResize( int $width, int $height, string $type = 'outer' ): void {
		$settings               = \wpSPIO()->settings();
		$settings->resizeImages = 1;
		$settings->resizeWidth  = $width;
		$settings->resizeHeight = $height;
		$settings->resizeType   = $type;
	}

	/** The reducer requests (calls to reducer.php) captured by the mock. */
	private function reducerRequests(): array {
		$found = array();
		foreach ( $this->api->requests as $request ) {
			if ( false !== strpos( $request['url'], 'reducer' ) && is_array( $request['request'] ) ) {
				$found[] = $request['request'];
			}
		}
		return $found;
	}

	public function test_request_carries_resize_parameters() {
		$this->enableResize( 800, 800, 'outer' );

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$requests = $this->reducerRequests();
		$this->assertNotEmpty( $requests );
		$request = $requests[0];

		$this->assertSame( 1, (int) $request['resize'], 'resizeType=outer must send resize=1.' );
		$this->assertSame( 800, (int) $request['resize_width'] );
		$this->assertSame( 800, (int) $request['resize_height'] );
	}

	public function test_main_file_is_resized_on_disk_and_meta_recorded() {
		$this->enableResize( 800, 800, 'outer' );

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		// 1200x900 contained in 800x800 => 800x600.
		clearstatcache();
		$size = getimagesize( get_attached_file( $id ) );
		$this->assertSame( 800, $size[0], 'The main file on disk must be scaled to the resize box width.' );
		$this->assertSame( 600, $size[1], 'The main file height must keep the aspect ratio (contain).' );

		$this->assertTrue( (bool) $image->getMeta( 'resize' ), 'The resize flag must be recorded in SPIO meta.' );
		$this->assertSame( 800, (int) $image->getMeta( 'resizeWidth' ) );
		$this->assertSame( 600, (int) $image->getMeta( 'resizeHeight' ) );

		// PINNED BUG: on a FIRST-time optimize no SPIO meta record exists yet,
		// and MediaLibraryModel::loadMeta() only calls verifyImage() (which
		// backfills originalWidth/originalHeight) in its metadata-exists
		// branch. So the resize comparison in ImageModel::handleOptimized()
		// runs against NULL originals, and the next load backfills them from
		// the already-resized file. Correct values would be 1200/900.
		// When these assertions fail with 1200/900 the bug was fixed —
		// flip the expectations and drop this comment.
		$this->assertSame( 800, (int) $image->getMeta( 'originalWidth' ), 'PINNED: originalWidth is backfilled from the resized file, losing the true original (should be 1200).' );
		$this->assertSame( 600, (int) $image->getMeta( 'originalHeight' ), 'PINNED: originalHeight is backfilled from the resized file (should be 900).' );
	}

	public function test_wp_attachment_metadata_reflects_new_dimensions() {
		$this->enableResize( 800, 800, 'outer' );

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertSame( 800, (int) $metadata['width'], '_wp_attachment_metadata width must be updated after resize.' );
		$this->assertSame( 600, (int) $metadata['height'] );
	}

	public function test_image_within_bounds_is_not_resized() {
		$this->enableResize( 2000, 2000, 'outer' );

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		clearstatcache();
		$size = getimagesize( get_attached_file( $id ) );
		$this->assertSame( 1200, $size[0], 'An image already within the resize box must keep its dimensions.' );
		$this->assertSame( 900, $size[1] );

		// PINNED BUG (same root cause as the originalWidth pin above): with
		// originalWidth/Height NULL on a first-time optimize, the
		// "$width != $originalWidth" check in ImageModel::handleOptimized()
		// is true even though the API did not resize anything, so the image
		// is falsely flagged as resized (with resizeWidth = its own width).
		// When these fail (resize false / resizeWidth empty) the bug was
		// fixed — flip to assertFalse( resize ) and drop this comment.
		$this->assertTrue( (bool) $image->getMeta( 'resize' ), 'PINNED: resize flag is falsely set for an unresized first-time optimize (should be false).' );
		$this->assertSame( 1200, (int) $image->getMeta( 'resizeWidth' ), 'PINNED: resizeWidth records the unchanged width (should be unset).' );
	}
}
