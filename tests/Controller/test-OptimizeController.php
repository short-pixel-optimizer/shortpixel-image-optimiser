<?php
/**
 * Tests for ShortPixel\Controller\Optimizer\OptimizeController.
 *
 * Exercises the pure/observable logic that does NOT require a live API call,
 * a real queue database row, or a real filesystem write.
 *
 * Scope:
 *   - Constructor wiring: apiName is 'optimize', $api is an ApiController instance.
 *   - Singleton contract inherited from OptimizerBase (per-class slot).
 *   - checkItem() returns false when the image model is missing.
 *   - checkItem() returns false (with error result) when isProcessable() is false
 *     and the item is not user-excluded or forceExclusion is not set.
 *   - checkItem() returns true when the image model is present and image is processable.
 *   - deleteTempFiles() skips deletion (returns false) when data()->files is NOT null.
 *   - getJsonResponse() shape inherited from OptimizerBase (tested here via the
 *     concrete class to confirm no override breaks it).
 *
 * Out of scope / why:
 *   - sendToProcessing(): calls ApiController::processMediaItem() / processActionItem()
 *     which hits the remote ShortPixel API. Intercepting it cleanly without touching
 *     production code is not feasible at the unit level.
 *   - handleAPIResult() / handleOptimizeAction() / handleAction(): depend on a fully
 *     wired queue object, ResponseController state, and real ImageModel metadata.
 *   - handleOptimizedItem(): downloads files from URLs via DownloadHelper — integration.
 *   - enQueueItem(): requires a live queue row via getCurrentQueue() → addQueueItem().
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Optimizer\OptimizeController;
use ShortPixel\Controller\Optimizer\OptimizerBase;
use ShortPixel\Controller\Api\ApiController;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;

class OptimizeControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetInstances();
	}

	public function tear_down() {
		$this->resetInstances();
		parent::tear_down();
	}

	private function resetInstances(): void {
		$ref = new ReflectionClass( OptimizerBase::class );
		$p   = $ref->getProperty( 'instances' );
		$p->setAccessible( true );
		$p->setValue( null, [] );

		$bi = $ref->getProperty( 'blockedItems' );
		$bi->setAccessible( true );
		$bi->setValue( null, null );
	}

	private function invokePrivate( $object, string $method, array $args = [] ) {
		$ref = new ReflectionClass( get_class( $object ) );
		// Walk up the hierarchy until the method is found.
		while ( ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $object, ...$args );
	}

	private function getPrivate( $object, string $prop ) {
		$ref = new ReflectionClass( get_class( $object ) );
		while ( ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $object );
	}

	/**
	 * Build a minimal ImageModel stub.
	 *
	 * @param bool $processable Result returned by isProcessable().
	 * @param bool $userExcluded Result returned by isUserExcluded().
	 * @param string $reason Human-readable non-processable reason.
	 */
	private function makeImageModelStub( bool $processable = true, bool $userExcluded = false, string $reason = '' ): ImageModel {
		return new class( $processable, $userExcluded, $reason ) extends ImageModel {
			private $proc;
			private $excluded;
			private $reason;
			public function __construct( $proc, $excluded, $reason ) {
				$this->proc     = $proc;
				$this->excluded = $excluded;
				$this->reason   = $reason;
			}
			public function get( $name ) { return null; }
			public function getOptimizeUrls() { return []; }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return []; }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
			public function isProcessable() { return $this->proc; }
			public function isUserExcluded() { return $this->excluded; }
			public function getProcessableReason( $status = null ) { return $this->reason; }
			public function cancelUserExclusions() { $this->proc = true; }
		};
	}

	/** Build a QueueItem with a model attached. */
	private function makeQueueItem( ImageModel $model ): QueueItem {
		return new QueueItem( [ 'imageModel' => $model ] );
	}

	/** Build a QueueItem with NO image model (item_id only). */
	private function makeOrphanQueueItem( int $id = 99 ): QueueItem {
		return new QueueItem( [ 'item_id' => $id ] );
	}

	/*
	 * Constructor wiring
	 */

	public function test_constructor_sets_apiName_to_optimize() {
		$ctrl = new OptimizeController();
		$this->assertSame( 'optimize', $this->getPrivate( $ctrl, 'apiName' ) );
	}

	public function test_constructor_binds_ApiController_instance() {
		$ctrl = new OptimizeController();
		$api  = $this->getPrivate( $ctrl, 'api' );
		$this->assertInstanceOf( ApiController::class, $api );
	}

	/*
	 * Singleton contract
	 */

	public function test_getInstance_returns_OptimizeController_instance() {
		$ctrl = OptimizeController::getInstance();
		$this->assertInstanceOf( OptimizeController::class, $ctrl );
	}

	public function test_getInstance_returns_same_object_on_repeated_calls() {
		$a = OptimizeController::getInstance();
		$b = OptimizeController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * getJsonResponse — inherited shape (no override in OptimizeController)
	 */

	public function test_getJsonResponse_returns_stdClass_with_null_fields() {
		$ctrl = new OptimizeController();
		$json = $this->invokePrivate( $ctrl, 'getJsonResponse' );

		$this->assertInstanceOf( \stdClass::class, $json );
		$this->assertNull( $json->status );
		$this->assertNull( $json->result );
		$this->assertNull( $json->results );
		$this->assertNull( $json->message );
	}

	/*
	 * checkItem — image model missing
	 */

	public function test_checkItem_returns_false_when_image_model_is_null() {
		$ctrl  = new OptimizeController();
		$qItem = $this->makeOrphanQueueItem();

		$this->assertFalse( $ctrl->checkItem( $qItem ) );
	}

	public function test_checkItem_sets_error_result_when_image_model_is_null() {
		$ctrl  = new OptimizeController();
		$qItem = $this->makeOrphanQueueItem();

		$ctrl->checkItem( $qItem );

		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
	}

	/*
	 * checkItem — image is processable
	 */

	public function test_checkItem_returns_true_when_image_is_processable() {
		$ctrl  = new OptimizeController();
		$model = $this->makeImageModelStub( true );
		$qItem = $this->makeQueueItem( $model );

		$this->assertTrue( $ctrl->checkItem( $qItem ) );
	}

	/*
	 * checkItem — image is NOT processable, no forceExclusion
	 */

	public function test_checkItem_returns_false_when_image_is_not_processable() {
		$ctrl  = new OptimizeController();
		$model = $this->makeImageModelStub( false, false, 'Excluded by pattern' );
		$qItem = $this->makeQueueItem( $model );

		$this->assertFalse( $ctrl->checkItem( $qItem ) );
	}

	public function test_checkItem_adds_error_result_when_not_processable() {
		$ctrl  = new OptimizeController();
		$model = $this->makeImageModelStub( false, false, 'Excluded by pattern' );
		$qItem = $this->makeQueueItem( $model );

		$ctrl->checkItem( $qItem );

		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
	}

	/*
	 * checkItem — user-excluded image with forceExclusion override
	 */

	public function test_checkItem_returns_true_when_user_excluded_and_forceExclusion_is_set() {
		$ctrl  = new OptimizeController();
		// User-excluded image that is otherwise processable once exclusions are cancelled.
		$model = $this->makeImageModelStub( false, true, 'User excluded' );
		$qItem = $this->makeQueueItem( $model );
		$qItem->setData( 'forceExclusion', true );

		$this->assertTrue( $ctrl->checkItem( $qItem ) );
	}

	/*
	 * deleteTempFiles — skips deletion when data()->files is NOT null
	 *
	 * The docblock states: "Returns false early (skips deletion) when data()->files
	 * is NOT null, which is the condition used to indicate a multi-round partial
	 * download is still in progress."
	 */

	public function test_deleteTempFiles_returns_false_when_persistent_files_are_set() {
		$ctrl  = new OptimizeController();
		$model = $this->makeImageModelStub( true );
		$qItem = $this->makeQueueItem( $model );

		// Simulate in-progress partial download: data()->files is not null.
		$qItem->setData( 'files', [ 'image.jpg' => [ 'image' => '/tmp/some-file.jpg' ] ] );

		$result = $this->invokePrivate( $ctrl, 'deleteTempFiles', [ $qItem ] );

		$this->assertFalse( $result );
	}
}
