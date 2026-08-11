<?php
/**
 * Tests for ShortPixel\Controller\Optimizer\OptimizerBase (abstract).
 *
 * OptimizerBase cannot be instantiated directly. All tests use two minimal
 * concrete stubs — ConcreteOptimizerA and ConcreteOptimizerB — declared
 * locally so we can exercise the late-static-binding singleton contract and
 * every pure helper without triggering real queue, API, or filesystem I/O.
 *
 * Scope:
 *   - getInstance() late-static-binding: each subclass gets its own slot.
 *   - Static $instances isolation between test methods (reset via reflection).
 *   - getJsonResponse() shape: null status/result/results/message.
 *   - setCurrentQueue() wires both queue and queueController properties.
 *   - shutdown_registered flag starts as false.
 *   - checkBlockedItems() is a no-op when the blocked list is null or empty.
 *   - checkImageModel() returns true when the model exists, false (+ result) when not.
 *
 * Out of scope / why:
 *   - blockItem() / unBlockItem(): both call getCurrentQueue() which needs a live
 *     queue object wired to a real database row — integration territory.
 *   - finishItemProcess(): calls wpSPIO()->filesystem() and queue->itemDone() —
 *     depends on a bootstrapped filesystem singleton and a real queue row.
 *   - addPreview(): requires BackupController and UiHelper::findBestPreview()
 *     which need real attachment metadata.
 *   - The actual abstract methods (handleAPIResult, sendToProcessing, etc.) are
 *     exercised in the concrete-subclass test files.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Optimizer\OptimizerBase;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;
use ShortPixel\Model\Queue\QueueItemResult;

/**
 * Minimal concrete stub A — used only to verify singleton-per-class behaviour.
 */
class ConcreteOptimizerA extends OptimizerBase {
	public function enqueueItem( QueueItem $qItem, $args = [] ) { return new \stdClass; }
	public function handleAPIResult( QueueItem $qItem ) {}
	protected function HandleItemError( QueueItem $qItem ) {}
	public function sendToProcessing( QueueItem $qItem ) {}
	public function checkItem( QueueItem $qItem ) { return true; }
}

/**
 * Minimal concrete stub B — separate class so its singleton slot is independent
 * of ConcreteOptimizerA's.
 */
class ConcreteOptimizerB extends OptimizerBase {
	public function enqueueItem( QueueItem $qItem, $args = [] ) { return new \stdClass; }
	public function handleAPIResult( QueueItem $qItem ) {}
	protected function HandleItemError( QueueItem $qItem ) {}
	public function sendToProcessing( QueueItem $qItem ) {}
	public function checkItem( QueueItem $qItem ) { return true; }
}

/**
 * Stub that exposes protected helpers as public for direct testing.
 */
class ExposedOptimizerStub extends OptimizerBase {
	public function enqueueItem( QueueItem $qItem, $args = [] ) { return new \stdClass; }
	public function handleAPIResult( QueueItem $qItem ) {}
	protected function HandleItemError( QueueItem $qItem ) {}
	public function sendToProcessing( QueueItem $qItem ) {}
	public function checkItem( QueueItem $qItem ) { return true; }

	/** Expose getJsonResponse() for testing. */
	public function publicGetJsonResponse() {
		return $this->getJsonResponse();
	}

	/** Expose checkImageModel() for testing. */
	public function publicCheckImageModel( QueueItem $qItem ) {
		return $this->checkImageModel( $qItem );
	}

	/** Expose checkBlockedItems() for testing. */
	public function publicCheckBlockedItems() {
		return $this->checkBlockedItems();
	}
}

class OptimizerBaseTest extends WP_UnitTestCase {

	/** Reset the per-class singleton registry before every test. */
	public function set_up() {
		parent::set_up();
		$this->resetInstances();
	}

	public function tear_down() {
		$this->resetInstances();
		parent::tear_down();
	}

	/** Wipe OptimizerBase::$instances so each test starts from a clean slate. */
	private function resetInstances(): void {
		$ref = new ReflectionClass( OptimizerBase::class );
		$p   = $ref->getProperty( 'instances' );
		$p->setAccessible( true );
		$p->setValue( null, [] );

		// Also reset static $blockedItems.
		$bi = $ref->getProperty( 'blockedItems' );
		$bi->setAccessible( true );
		$bi->setValue( null, null );
	}

	/** Build an ImageModel stub that returns the given ID from get('id'). */
	private function makeImageModelStub( int $id ): ImageModel {
		return new class( $id ) extends ImageModel {
			private $stub_id;
			public function __construct( $id ) { $this->stub_id = $id; }
			public function get( $name ) { return $name === 'id' ? $this->stub_id : null; }
			public function getOptimizeUrls() { return []; }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return []; }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
		};
	}

	/** Build a QueueItem with an attached ImageModel stub. */
	private function makeQueueItem( int $id = 1 ): QueueItem {
		$image = $this->makeImageModelStub( $id );
		$qItem = new QueueItem( [ 'imageModel' => $image ] );
		return $qItem;
	}

	/**
	 * Build a QueueItem whose imageModel reports checkImageModelExists() == false.
	 * We achieve this by setting imageModel to null on the QueueItem via reflection.
	 */
	private function makeQueueItemWithBrokenModel(): QueueItem {
		$qItem = new QueueItem( [ 'item_id' => 99 ] );
		// Leave imageModel null — checkImageModelExists() returns false when model is null.
		return $qItem;
	}

	/*
	 * getInstance — late-static-binding contract
	 */

	public function test_getInstance_returns_instance_of_the_called_concrete_class() {
		$a = ConcreteOptimizerA::getInstance();
		$this->assertInstanceOf( ConcreteOptimizerA::class, $a );
	}

	public function test_getInstance_returns_same_object_on_repeated_calls_for_same_class() {
		$a1 = ConcreteOptimizerA::getInstance();
		$a2 = ConcreteOptimizerA::getInstance();
		$this->assertSame( $a1, $a2 );
	}

	public function test_getInstance_returns_distinct_instances_for_different_subclasses() {
		$a = ConcreteOptimizerA::getInstance();
		$b = ConcreteOptimizerB::getInstance();
		$this->assertNotSame( $a, $b );
	}

	public function test_getInstance_keys_are_separate_in_static_instances_array() {
		ConcreteOptimizerA::getInstance();
		ConcreteOptimizerB::getInstance();

		$ref = new ReflectionClass( OptimizerBase::class );
		$p   = $ref->getProperty( 'instances' );
		$p->setAccessible( true );
		$instances = $p->getValue( null );

		$this->assertArrayHasKey( ConcreteOptimizerA::class, $instances );
		$this->assertArrayHasKey( ConcreteOptimizerB::class, $instances );
		$this->assertNotSame( $instances[ ConcreteOptimizerA::class ], $instances[ ConcreteOptimizerB::class ] );
	}

	/*
	 * getJsonResponse — shape contract
	 */

	public function test_getJsonResponse_returns_stdClass() {
		$stub = new ExposedOptimizerStub();
		$json = $stub->publicGetJsonResponse();
		$this->assertInstanceOf( \stdClass::class, $json );
	}

	public function test_getJsonResponse_has_null_status() {
		$stub = new ExposedOptimizerStub();
		$json = $stub->publicGetJsonResponse();
		$this->assertNull( $json->status );
	}

	public function test_getJsonResponse_has_null_result() {
		$stub = new ExposedOptimizerStub();
		$json = $stub->publicGetJsonResponse();
		$this->assertNull( $json->result );
	}

	public function test_getJsonResponse_has_null_results() {
		$stub = new ExposedOptimizerStub();
		$json = $stub->publicGetJsonResponse();
		$this->assertNull( $json->results );
	}

	public function test_getJsonResponse_has_null_message() {
		$stub = new ExposedOptimizerStub();
		$json = $stub->publicGetJsonResponse();
		$this->assertNull( $json->message );
	}

	public function test_getJsonResponse_returns_fresh_object_each_call() {
		$stub = new ExposedOptimizerStub();
		$a = $stub->publicGetJsonResponse();
		$b = $stub->publicGetJsonResponse();
		$this->assertNotSame( $a, $b );
	}

	/*
	 * constructor — initialises $response
	 */

	public function test_constructor_initialises_response_property_as_stdClass() {
		$stub = new ExposedOptimizerStub();
		$ref  = new ReflectionClass( ExposedOptimizerStub::class );
		$p    = $ref->getProperty( 'response' );
		$p->setAccessible( true );
		$this->assertInstanceOf( \stdClass::class, $p->getValue( $stub ) );
	}

	/*
	 * shutdown_registered — initial value
	 */

	public function test_shutdown_registered_is_false_after_construction() {
		$stub = new ExposedOptimizerStub();
		$this->assertFalse( $stub->shutdown_registered );
	}

	/*
	 * setCurrentQueue — wires queue and controller
	 */

	public function test_setCurrentQueue_stores_queue_object() {
		$stub      = new ExposedOptimizerStub();
		$mockQueue = new \stdClass();

		$stub->setCurrentQueue( $mockQueue, null );

		$ref = new ReflectionClass( ExposedOptimizerStub::class );
		$p   = $ref->getProperty( 'currentQueue' );
		$p->setAccessible( true );
		$this->assertSame( $mockQueue, $p->getValue( $stub ) );
	}

	public function test_setCurrentQueue_stores_queue_controller() {
		$stub          = new ExposedOptimizerStub();
		$mockQueue     = new \stdClass();
		$mockCtrl      = new \stdClass();

		$stub->setCurrentQueue( $mockQueue, $mockCtrl );

		$ref = new ReflectionClass( ExposedOptimizerStub::class );
		$p   = $ref->getProperty( 'queueController' );
		$p->setAccessible( true );
		$this->assertSame( $mockCtrl, $p->getValue( $stub ) );
	}

	/*
	 * checkImageModel
	 */

	public function test_checkImageModel_returns_true_when_image_model_exists() {
		$stub  = new ExposedOptimizerStub();
		$qItem = $this->makeQueueItem( 5 );
		$this->assertTrue( $stub->publicCheckImageModel( $qItem ) );
	}

	public function test_checkImageModel_returns_false_when_image_model_is_null() {
		$stub  = new ExposedOptimizerStub();
		$qItem = $this->makeQueueItemWithBrokenModel();
		$this->assertFalse( $stub->publicCheckImageModel( $qItem ) );
	}

	public function test_checkImageModel_adds_error_result_when_model_is_missing() {
		$stub  = new ExposedOptimizerStub();
		$qItem = $this->makeQueueItemWithBrokenModel();

		$stub->publicCheckImageModel( $qItem );

		$this->assertTrue( $qItem->result()->is_error );
		$this->assertTrue( $qItem->result()->is_done );
	}

	/*
	 * checkBlockedItems — safe no-op paths
	 */

	public function test_checkBlockedItems_is_noop_when_blocked_list_is_null() {
		$stub = new ExposedOptimizerStub();

		// blockedItems is already null from resetInstances(); just confirm no exception.
		$stub->publicCheckBlockedItems();
		$this->assertTrue( true ); // Reached here without exception.
	}

	public function test_checkBlockedItems_is_noop_when_blocked_list_is_empty() {
		$stub = new ExposedOptimizerStub();

		$ref = new ReflectionClass( OptimizerBase::class );
		$p   = $ref->getProperty( 'blockedItems' );
		$p->setAccessible( true );
		$p->setValue( null, [] ); // empty array (not null)

		$stub->publicCheckBlockedItems();
		$this->assertTrue( true );
	}

	/**
	 * Regression for bug #3 (fixed in e034b877): checkBlockedItems() is
	 * registered as a shutdown handler (register_shutdown_function in the
	 * OptimizerBase constructor), and PHP's shutdown dispatcher can only
	 * call PUBLIC methods. When the method was protected, every fatal
	 * mid-optimization left its item blocked forever, with only a silent
	 * "Unable to call ..." warning at shutdown.
	 */
	public function test_checkBlockedItems_is_public_for_shutdown_dispatch() {
		$m = new ReflectionMethod( OptimizerBase::class, 'checkBlockedItems' );
		$this->assertTrue(
			$m->isPublic(),
			'checkBlockedItems() must stay public: it runs via register_shutdown_function, which cannot invoke protected methods (bug #3, e034b877).'
		);
	}
}
