<?php
/**
 * Tests for ShortPixel\Controller\Api\ApiController.
 *
 * Scope: handleResponse() status-code dispatch (error codes, quota, queue-full,
 * maintenance, no-key), handleActionResponse() waiting / success branches,
 * handleNewSuccess() image/webp/avif result shaping (lossy vs lossless, NC/NA
 * sentinels, file-size margin), checkFileSizeMargin() logic, and returnFailure /
 * returnRetry / returnSuccess shapes inherited from RequestManager.
 * Two pinned regressions: the undefined $APIresponse variable in the fallback
 * branch of handleOptimizeResponse(), and the implicit-null return from
 * handleActionResponse() on an unrecognised status.
 *
 * Out of scope / why:
 * - processMediaItem() / processActionItem(): require a fully wired ImageModel
 *   and would call doRequest() → wp_remote_post() (network). Excluded.
 * - dumpMediaItem(): fires wp_remote_post() directly; skipped.
 * - getInstance() singleton: covered via the RequestManager base tests.
 * - handleOptimizeResponse() full round-trips: require wiring ResponseController
 *   and a rich API payload; only the failure/waiting branches are covered here.
 *   The $APIresponse undefined-variable bug is pinned separately.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Api\ApiController;
use ShortPixel\Controller\Api\RequestManager;
use ShortPixel\Model\Queue\QueueItem;

class ApiControllerTest extends WP_UnitTestCase {

	/** @var ApiController */
	private $api;

	public function set_up() {
		parent::set_up();
		// Reset the shared singleton so each test starts with a clean instance.
		$this->resetSingleton();
		$this->api = new ApiController();
	}

	public function tear_down() {
		$this->resetSingleton();
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( ApiController::class );
		// RequestManager stores instances on the static $instances map.
		$p = $ref->getParentClass()->getProperty( 'instances' );
		$p->setAccessible( true );
		$instances = $p->getValue( null ) ?? [];
		unset( $instances[ ApiController::class ] );
		$p->setValue( null, $instances );
	}

	// -----------------------------------------------------------------------
	// Reflection helpers
	// -----------------------------------------------------------------------

	private function invokeProtected( string $method, array $args = [] ) {
		$ref = new ReflectionClass( ApiController::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $this->api, ...$args );
	}

	/**
	 * Build a minimal QueueItem with the given action and no image model.
	 */
	private function makeQueueItem( string $action = 'optimize', array $extraData = [] ): QueueItem {
		$qItem = new QueueItem( [ 'item_id' => 1 ] );
		$qItem->data()->action = $action;
		foreach ( $extraData as $key => $val ) {
			$qItem->data()->$key = $val;
		}
		return $qItem;
	}

	/**
	 * Build a raw wp_remote_post()-style response array with a JSON body.
	 */
	private function makeRawResponse( $body ): array {
		return [
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		];
	}

	// -----------------------------------------------------------------------
	// Error constants
	// -----------------------------------------------------------------------

	public function test_error_constants_have_expected_integer_values() {
		$this->assertSame( -902, ApiController::ERR_FILE_NOT_FOUND );
		$this->assertSame( -903, ApiController::ERR_TIMEOUT );
		$this->assertSame( -904, ApiController::ERR_SAVE );
		$this->assertSame( -999, ApiController::ERR_UNKNOWN );
	}

	// -----------------------------------------------------------------------
	// handleResponse — Status object dispatch (known error codes)
	// -----------------------------------------------------------------------

	/**
	 * Build an API body where the top-level Status object signals a known error.
	 */
	private function statusBody( int $code, string $message ): string {
		return json_encode( [ 'Status' => [ 'Code' => $code, 'Message' => $message ] ] );
	}

	public function test_handleResponse_invalid_url_minus_102_returns_status_error() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -102, 'Invalid URL' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_ERROR, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
	}

	public function test_handleResponse_invalid_image_minus_201_returns_status_error() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -201, 'Invalid image' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_ERROR, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
	}

	public function test_handleResponse_quota_exceeded_minus_301_returns_quota_exceeded() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -301, 'Quota exceeded' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_QUOTA_EXCEEDED, $result['apiStatus'] );
		// returnRetry: is_error true, is_done false.
		$this->assertTrue( $result['is_error'] );
		$this->assertFalse( $result['is_done'] );
	}

	public function test_handleResponse_quota_exceeded_minus_403_returns_quota_exceeded() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -403, 'Quota exceeded (403)' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_QUOTA_EXCEEDED, $result['apiStatus'] );
	}

	public function test_handleResponse_file_no_longer_available_minus_302_returns_fail() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -302, 'No longer available' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_done'] );
	}

	public function test_handleResponse_mixed_domain_minus_306_returns_fail() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -306, 'Mixed domains' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
	}

	public function test_handleResponse_invalid_api_key_minus_401_returns_no_key() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -401, 'Invalid API key' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_NO_KEY, $result['apiStatus'] );
		$this->assertTrue( $result['is_done'] );
	}

	public function test_handleResponse_wrong_api_key_minus_402_returns_no_key() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -402, 'Wrong API key' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_NO_KEY, $result['apiStatus'] );
	}

	public function test_handleResponse_queue_full_minus_404_returns_queue_full_and_retries() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -404, 'Queue full' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_QUEUE_FULL, $result['apiStatus'] );
		// returnRetry: not done.
		$this->assertFalse( $result['is_done'] );
	}

	public function test_handleResponse_maintenance_minus_500_returns_maintenance_and_retries() {
		$qItem    = $this->makeQueueItem();
		$response = $this->makeRawResponse( $this->statusBody( -500, 'Maintenance' ) );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_MAINTENANCE, $result['apiStatus'] );
		$this->assertFalse( $result['is_done'] );
	}

	// -----------------------------------------------------------------------
	// handleResponse — unrecognized / missing Status (fallback)
	// -----------------------------------------------------------------------

	public function test_handleResponse_unrecognized_body_returns_fail() {
		$qItem    = $this->makeQueueItem();
		// Body that is valid JSON but has no Status and no image array.
		$response = $this->makeRawResponse( [ 'garbage' => true ] );
		$result   = $this->invokeProtected( 'handleResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
	}

	// -----------------------------------------------------------------------
	// handleActionResponse — waiting branch
	// -----------------------------------------------------------------------

	private function makeActionResponse( int $code, string $losslessUrl = '', string $originalUrl = '' ): array {
		$item               = new stdClass();
		$item->Status       = new stdClass();
		$item->Status->Code = $code;
		if ( $losslessUrl ) {
			$item->LosslessURL  = $losslessUrl;
			$item->OriginalURL  = $originalUrl;
		}
		return [ $item ];
	}

	public function test_handleActionResponse_unchanged_code_returns_status_unchanged() {
		$qItem    = $this->makeQueueItem( 'remove_background' );
		$response = $this->makeActionResponse( RequestManager::STATUS_UNCHANGED );
		$result   = $this->invokeProtected( 'handleActionResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_UNCHANGED, $result['apiStatus'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertFalse( $result['is_done'] );
	}

	public function test_handleActionResponse_waiting_code_returns_status_unchanged() {
		$qItem    = $this->makeQueueItem( 'scale_image' );
		$response = $this->makeActionResponse( RequestManager::STATUS_WAITING );
		$result   = $this->invokeProtected( 'handleActionResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_UNCHANGED, $result['apiStatus'] );
	}

	public function test_handleActionResponse_success_returns_success_with_urls() {
		$qItem    = $this->makeQueueItem( 'remove_background' );
		$response = $this->makeActionResponse(
			RequestManager::STATUS_SUCCESS,
			'https://example.com/opt.jpg',
			'https://example.com/orig.jpg'
		);
		$result = $this->invokeProtected( 'handleActionResponse', [ $qItem, $response ] );
		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['apiStatus'] );
		$this->assertFalse( $result['is_error'] );
		$this->assertTrue( $result['is_done'] );
		$this->assertSame( 'https://example.com/opt.jpg', $result['optimized'] );
		$this->assertSame( 'https://example.com/orig.jpg', $result['original'] );
	}

	// -----------------------------------------------------------------------
	// handleActionResponse — pinned: implicit null on unknown status
	// -----------------------------------------------------------------------

	/**
	 * Pinned regression: ApiController::handleActionResponse() has no default /
	 * else branch for status codes other than UNCHANGED, WAITING, or SUCCESS.
	 *
	 * EXPECTED (after fix): A defined return value (e.g. returnFailure) for any
	 * unrecognised status code.
	 *
	 * ACTUAL (current): The method falls through without an explicit return,
	 * yielding null — the caller (handleResponse) passes null to
	 * QueueItem::addResult(), which iterates over null causing a foreach warning.
	 *
	 * This test MUST FAIL once a default return is added.
	 */
	public function test_handleActionResponse_unknown_status_returns_null_pinned_for_deferred_fix() {
		$qItem    = $this->makeQueueItem( 'remove_background' );
		$response = $this->makeActionResponse( -999 ); // completely unknown code

		$result = $this->invokeProtected( 'handleActionResponse', [ $qItem, $response ] );

		// Current (buggy) behaviour: null is returned.
		$this->assertNull( $result, 'Expected null (current buggy behaviour); fix must return a proper array.' );
	}

	// -----------------------------------------------------------------------
	// handleNewSuccess — image result shaping (lossless)
	// -----------------------------------------------------------------------

	/**
	 * Build a minimal stdClass API image object as returned by the ShortPixel API.
	 */
	private function makeFileData(
		string $losslessUrl  = 'https://sp.com/img-lossless.jpg',
		int    $losslessSize = 50000,
		string $lossyUrl     = 'https://sp.com/img-lossy.jpg',
		int    $lossySize    = 45000,
		int    $originalSize = 80000,
		string $originalUrl  = 'https://sp.com/img-orig.jpg'
	): stdClass {
		$obj                  = new stdClass();
		$obj->LosslessURL     = $losslessUrl;
		$obj->LosslessSize    = $losslessSize;
		$obj->LossyURL        = $lossyUrl;
		$obj->LossySize       = $lossySize;
		$obj->OriginalURL     = $originalUrl;
		$obj->OriginalSize    = $originalSize;
		return $obj;
	}

	private function makeQueueItemWithCompression( int $compressionType = 0 ): QueueItem {
		$qItem = $this->makeQueueItem( 'optimize' );
		$qItem->data()->compressionType = $compressionType;
		return $qItem;
	}

	public function test_handleNewSuccess_lossless_sets_lossless_url_and_size() {
		$qItem    = $this->makeQueueItemWithCompression( 0 ); // 0 = lossless
		$fileData = $this->makeFileData();
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( 'https://sp.com/img-lossless.jpg', $result['image']['url'] );
		$this->assertSame( 50000, $result['image']['optimizedSize'] );
	}

	public function test_handleNewSuccess_lossy_sets_lossy_url_and_size() {
		$qItem    = $this->makeQueueItemWithCompression( 1 ); // 1 = lossy
		$fileData = $this->makeFileData();
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( 'https://sp.com/img-lossy.jpg', $result['image']['url'] );
		$this->assertSame( 45000, $result['image']['optimizedSize'] );
	}

	public function test_handleNewSuccess_original_size_falls_back_to_file_data_original_size() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData( 'https://sp.com/l.jpg', 50000, 'https://sp.com/lo.jpg', 45000, 80000 );
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ]; // no fileSize override

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( 80000, $result['image']['originalSize'] );
	}

	public function test_handleNewSuccess_data_fileSize_overrides_api_original_size() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData( 'https://sp.com/l.jpg', 50000, 'https://sp.com/lo.jpg', 45000, 80000 );
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full', 'fileSize' => 99999 ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( 99999, $result['image']['originalSize'] );
	}

	public function test_handleNewSuccess_same_original_and_optimized_sets_unchanged_status() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		// OriginalURL == LosslessURL triggers STATUS_UNCHANGED.
		$fileData = $this->makeFileData( 'https://sp.com/orig.jpg', 80000, 'https://sp.com/lo.jpg', 45000, 80000, 'https://sp.com/orig.jpg' );
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_UNCHANGED, $result['image']['status'] );
	}

	public function test_handleNewSuccess_optimized_bigger_exceeds_margin_sets_optimized_bigger() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		// Original = 1000, optimized = 1100 → +10% → exceeds default 5% margin.
		$fileData = $this->makeFileData(
			'https://sp.com/l.jpg', 1100,
			'https://sp.com/lo.jpg', 1100,
			1000,
			'https://sp.com/o.jpg'
		);
		// Production guards the STATUS_OPTIMIZED_BIGGER assignment behind
		// `isset($data['resize']) && 4 <> $data['resize']` (4 = smartcrop).
		// A standard resize (e.g. 1 = fit) must be present; without it the
		// status is silently left at STATUS_SUCCESS even when the margin is exceeded.
		$data = [ 'fileName' => 'img.jpg', 'imageName' => 'full', 'resize' => 1 ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_OPTIMIZED_BIGGER, $result['image']['status'] );
	}

	// -----------------------------------------------------------------------
	// handleNewSuccess — WebP NC / NA sentinels
	// -----------------------------------------------------------------------

	public function test_handleNewSuccess_webp_NC_sets_not_compatible_status() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData();
		$fileData->WebPLosslessURL  = 'NC';
		$fileData->WebPLosslessSize = 0;
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_NOT_COMPATIBLE, $result['webp']['status'] );
	}

	public function test_handleNewSuccess_webp_NA_leaves_status_skip() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData();
		$fileData->WebPLosslessURL  = 'NA';
		$fileData->WebPLosslessSize = 0;
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_SKIP, $result['webp']['status'] );
	}

	public function test_handleNewSuccess_webp_valid_url_sets_success_status() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData();
		$fileData->WebPLosslessURL  = 'https://sp.com/img.webp';
		$fileData->WebPLosslessSize = 40000; // smaller than original 80000.
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['webp']['status'] );
		$this->assertSame( 'https://sp.com/img.webp', $result['webp']['url'] );
	}

	// -----------------------------------------------------------------------
	// handleNewSuccess — AVIF NC / NA / valid sentinels
	// -----------------------------------------------------------------------

	public function test_handleNewSuccess_avif_NC_sets_not_compatible_status() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData();
		$fileData->AVIFLosslessURL  = 'NC';
		$fileData->AVIFLosslessSize = 0;
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_NOT_COMPATIBLE, $result['avif']['status'] );
	}

	public function test_handleNewSuccess_avif_valid_url_sets_success_status() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData();
		$fileData->AVIFLosslessURL  = 'https://sp.com/img.avif';
		$fileData->AVIFLosslessSize = 38000;
		$data     = [ 'fileName' => 'img.jpg', 'imageName' => 'full' ];

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_SUCCESS, $result['avif']['status'] );
	}

	// -----------------------------------------------------------------------
	// handleNewSuccess — missing fileName / imageName returns failure
	// -----------------------------------------------------------------------

	public function test_handleNewSuccess_missing_fileName_returns_failure() {
		$qItem    = $this->makeQueueItemWithCompression( 0 );
		$fileData = $this->makeFileData();
		$data     = [ 'imageName' => 'full' ]; // fileName deliberately omitted.

		$result = $this->invokeProtected( 'handleNewSuccess', [ $qItem, $fileData, $data ] );

		$this->assertSame( RequestManager::STATUS_FAIL, $result['apiStatus'] );
		$this->assertTrue( $result['is_error'] );
	}

	// -----------------------------------------------------------------------
	// checkFileSizeMargin — private, tested via reflection
	// -----------------------------------------------------------------------

	private function invokeCheckMargin( int $fileSize, int $resultSize ): bool {
		$ref = new ReflectionClass( ApiController::class );
		$m   = $ref->getMethod( 'checkFileSizeMargin' );
		$m->setAccessible( true );
		return $m->invoke( $this->api, $fileSize, $resultSize );
	}

	public function test_checkFileSizeMargin_returns_true_when_result_smaller() {
		$this->assertTrue( $this->invokeCheckMargin( 100, 80 ) );
	}

	public function test_checkFileSizeMargin_returns_true_when_result_equal() {
		$this->assertTrue( $this->invokeCheckMargin( 100, 100 ) );
	}

	public function test_checkFileSizeMargin_returns_true_when_file_size_is_zero() {
		$this->assertTrue( $this->invokeCheckMargin( 0, 999 ) );
	}

	public function test_checkFileSizeMargin_returns_true_within_default_5_percent_margin() {
		// 100 → 104 = +4% — within the 5% default filter value.
		$this->assertTrue( $this->invokeCheckMargin( 100, 104 ) );
	}

	public function test_checkFileSizeMargin_returns_false_when_result_exceeds_margin() {
		// 100 → 110 = +10% — exceeds the 5% default.
		$this->assertFalse( $this->invokeCheckMargin( 100, 110 ) );
	}

	public function test_checkFileSizeMargin_filter_negative_value_always_returns_true() {
		add_filter( 'shortpixel/api/filesizeMargin', function () { return -1; } );
		$result = $this->invokeCheckMargin( 100, 200 ); // +100%, but filter disables check.
		remove_all_filters( 'shortpixel/api/filesizeMargin' );
		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// handleOptimizeResponse — pinned: $APIresponse undefined variable
	// -----------------------------------------------------------------------

	/**
	 * Pinned regression: ApiController::handleOptimizeResponse() line ~492.
	 *
	 * ACTUAL (bug): The fallback else-branch at line 492 uses the undefined
	 * variable `$APIresponse` inside `isset($APIresponse[0]->Status->Message)`.
	 * Because `isset()` suppresses undefined-variable notices, no PHP error fires.
	 * The isset always evaluates to false (null[0] is null), so the if-branch that
	 * would include the API's own Status->Message in the returned error string is
	 * NEVER reached — even when `$response[0]->Status->Message` is populated.
	 *
	 * EXPECTED (after fix): `$APIresponse` should be `$response`. Once fixed, the
	 * if-branch will be taken and the returned message will include the API status
	 * message (e.g. "Unknown error from API").
	 *
	 * This test pins the buggy silent-branch by asserting that the API's own
	 * Status->Message is NOT present in the returned error message string.
	 * The assertion will flip (the string WILL appear) once the bug is corrected.
	 *
	 * This test MUST FAIL once `$APIresponse` is corrected to `$response` at line ~492.
	 */
	public function test_handleOptimizeResponse_undefined_APIresponse_in_fallback_branch_pinned_for_deferred_fix() {
		$qItem = $this->makeQueueItem( 'optimize' );
		$qItem->data()->urls = [ 'full' => 'https://example.com/img.jpg' ];

		// returndatalist with a sizes entry is required to pass the early guard.
		$returnDataList            = new stdClass();
		$returnDataList->sizes     = (object) [ 'full' => 'img.jpg' ];
		$returnDataList->fileSizes = new stdClass();
		$returnDataList->doubles   = new stdClass();
		$returnDataList->duplicates = new stdClass();

		// An image object whose Status->Code is not UNCHANGED/WAITING/SUCCESS, so
		// imageList stays empty and waiting == 0 — forcing the fallback branch.
		// Status->Message is deliberately set to a recognisable sentinel so we can
		// detect whether the if-branch (which includes it) or the else-branch was taken.
		$imageObj                  = new stdClass();
		$imageObj->Status          = new stdClass();
		$imageObj->Status->Code    = -999; // unknown — forces the fallback branch
		$imageObj->Status->Message = 'SENTINEL_MESSAGE_FROM_API';
		$imageObj->OriginalURL     = 'https://example.com/img.jpg';

		$response = [
			'returndatalist' => $returnDataList,
			0                => $imageObj,
		];

		$ref = new ReflectionClass( ApiController::class );
		$m   = $ref->getMethod( 'handleOptimizeResponse' );
		$m->setAccessible( true );
		$result = $m->invoke( $this->api, $qItem, $response );

		// Precondition: the method must still return a valid array from the fallback branch.
		$this->assertIsArray( $result, 'Expected an array result from the fallback branch.' );

		// Current (buggy) behaviour: isset($APIresponse[0]->Status->Message) is always
		// false (undefined var → null), so the else-branch runs and the API message is
		// NEVER included in the returned message string.
		// Once the bug is fixed ($APIresponse → $response), the if-branch will be taken
		// and SENTINEL_MESSAGE_FROM_API will appear in $result['message'] — this assertion
		// will then fail, signalling the pin can be removed.
		$this->assertStringNotContainsString(
			'SENTINEL_MESSAGE_FROM_API',
			(string) $result['message'],
			'Bug present: API Status->Message should NOT appear in message (if-branch is dead due to undefined $APIresponse).'
		);
	}
}
