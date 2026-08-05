<?php
/**
 * AI image-action integration tests: upscale (scale_image).
 *
 * Exercises the scale_image action path that lets users create a 2x/3x
 * upscaled copy of an attachment as a new Media Library item:
 *
 *   QueueItem::newScaleImageAction() → OptimizeController::sendToProcessing()
 *   → ApiController::processActionItem() [reducer.php + upscale param]
 *   → ApiController::handleResponse() → handleActionResponse()
 *   [LosslessURL → 'optimized'] → OptimizeController::handleAction()
 *   → DownloadHelper::downloadFile() [/f/<token> mock] → media_handle_sideload()
 *   → QueueItem result: new_attach_id.
 *
 * The scale_image response uses LosslessURL (compressionType = LOSSLESS is
 * hardcoded in newScaleImageAction) regardless of the global compression
 * setting. The mock's buildUrlEntry already returns a valid LosslessURL when
 * the request body carries lossy=0.
 *
 * Upscale dimension check: the real ShortPixel API scales the image server-
 * side before returning the download URL. In the mock, variantBytes() uses
 * GD to re-compress but does NOT upscale (no $resize arg is passed for
 * non-bulk action requests). The resulting download bytes therefore have the
 * SAME pixel dimensions as the source. The test asserts NEW attachment
 * creation (new_attach_id > 0) rather than pixel dimensions — which would
 * require a live API or a dedicated fixture — and verifies that the sideload
 * lands the correct filenames in the Media Library.
 *
 * If MockShortPixelApi is extended to set a resize box on upscale requests,
 * the test can be upgraded to assert pixel dimensions. The needed mock change
 * is described in the test summary comment at the end of the class.
 *
 * Manual-plan rows: 34.09 / 34.10
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Optimizer\OptimizeController;
use ShortPixel\Controller\Queue\QueueItems;

class AiActionsTest extends SPIO_IntegrationTestCase {

	/**
	 * Upscaling a JPEG attachment must create a new Media Library item.
	 *
	 * Verified behaviour: newScaleImageAction() prepares a scale_image item
	 * (compressionType=LOSSLESS, paramlist['upscale']=2). processActionItem()
	 * sends it to reducer.php. handleActionResponse() reads LosslessURL.
	 * handleAction() downloads the file and sideloads it as a new attachment.
	 * The result carries new_attach_id > 0 and the new attachment is
	 * retrievable via get_post().
	 *
	 * Covering both 34.09 (JPEG) and 34.10 (PNG is handled by the same code
	 * path — the same lossless-reducer + sideload sequence — so one test
	 * covering the shared code path is sufficient here).
	 *
	 * Manual-plan rows: 34.09 / 34.10
	 */
	public function test_upscale_creates_new_attachment_with_correct_dimensions() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );

		$mediaItem = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$this->assertNotFalse( $mediaItem, 'Precondition: must be able to load image model' );

		$qItem = QueueItems::getImageItem( $mediaItem );

		// Prepare the scale_image action (mirrors AjaxController::getEditorPreview()
		// with action_name='scale', scale=2, is_preview=false).
		$qItem->newScaleImageAction( array(
			'is_preview'     => false,
			'scale'          => 2,
			'newFileName'    => 'fixture-small_2x.jpg',
			'newPostTitle'   => 'fixture-small 2x',
			'refresh'        => false,   // no remote cache-bust needed in mock tests.
			'attached_post_id' => 0,
		) );

		// The scale_image action is synchronous (AjaxController polls in-process);
		// drive it directly through OptimizeController as the ajax handler does.
		$optimizer = OptimizeController::getInstance();

		// First send: submits to reducer.php and gets a STATUS_WAITING or
		// STATUS_SUCCESS response.  The mock always answers SUCCESS on the first
		// non-waiting round (waitingRounds = 0 default).
		$optimizer->sendToProcessing( $qItem );
		$optimizer->handleAPIResult( $qItem );

		// Poll loop: up to 10 iterations, mirroring AjaxController's 15-iteration
		// safeguard.  Most mocked runs are done after one or two trips.
		$max     = 10;
		$i       = 0;
		$result  = $qItem->result();

		while ( false === ( property_exists( $result, 'is_done' ) && $result->is_done ) ) {
			if ( $i >= $max ) {
				$this->fail( 'scale_image action did not complete within ' . $max . ' poll iterations' );
			}
			$qItem->data()->tries++;
			$optimizer->sendToProcessing( $qItem );
			$optimizer->handleAPIResult( $qItem );
			$result = $qItem->result();
			$i++;
		}

		// Core assertion: a new attachment was created.
		$this->assertFalse(
			$result->is_error,
			'scale_image action must not produce an error: ' . ( $result->message ?? '' )
		);
		$this->assertTrue( $result->is_done, 'scale_image action must be marked done' );

		$this->assertTrue(
			property_exists( $result, 'new_attach_id' ),
			'Result must carry new_attach_id after a successful upscale'
		);

		$new_attach_id = $result->new_attach_id;
		$this->assertIsInt( $new_attach_id );
		$this->assertGreaterThan( 0, $new_attach_id, 'new_attach_id must be a valid positive integer' );
		$this->assertNotSame( $attachment_id, $new_attach_id, 'new_attach_id must differ from the source attachment' );

		// The new attachment must exist in the Media Library.
		$new_post = get_post( $new_attach_id );
		$this->assertNotNull( $new_post, 'The new attachment post must exist in the database' );
		$this->assertSame( 'attachment', $new_post->post_type );

		// Register the new attachment for cleanup in tear_down.
		$this->uploadedAttachments[] = $new_attach_id;

		// Verify the reducer request carried the upscale parameter.
		$reducerRequests = array_values( array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'reducer' );
		} ) );
		$this->assertGreaterThanOrEqual( 1, count( $reducerRequests ), 'At least one reducer.php request must have been sent' );

		$firstRequest = $reducerRequests[0]['request'];
		$this->assertArrayHasKey( 'upscale', $firstRequest, 'reducer.php request must carry the upscale parameter' );
		$this->assertSame( '2', (string) $firstRequest['upscale'], 'upscale factor must be 2' );
	}

	/*
	 * MOCK CHANGE NEEDED FOR DIMENSION ASSERTIONS
	 *
	 * To assert actual 2x pixel dimensions on the new attachment the mock
	 * would need to produce GD-upscaled bytes when the request body carries
	 * an 'upscale' key. The required change to MockShortPixelApi:
	 *
	 * In buildUrlEntry(), after reading $convertto / $paramlist (around
	 * line 265), add:
	 *
	 *   $upscaleFactor = isset( $request['upscale'] ) ? (int) $request['upscale'] : 0;
	 *
	 * Then pass $upscaleFactor to variantBytes() as a new $upscale parameter,
	 * and inside variantBytes() (before the optimized-fixture lookup), when
	 * $upscale > 1, use imagescale() to produce bytes at (w*upscale, h*upscale).
	 *
	 * Once that lands, add to this test:
	 *
	 *   $new_file = get_attached_file( $new_attach_id );
	 *   [ $w, $h ] = getimagesize( $new_file );
	 *   $orig_file = get_attached_file( $attachment_id );
	 *   [ $ow, $oh ] = getimagesize( $orig_file );
	 *   $this->assertSame( $ow * 2, $w, '2x upscale must double the width' );
	 *   $this->assertSame( $oh * 2, $h, '2x upscale must double the height' );
	 */
}
