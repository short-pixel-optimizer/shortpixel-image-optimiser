<?php
/**
 * Tests for ShortPixel\Controller\Optimizer\ActionController.
 *
 * ActionController handles non-optimisation image actions: restore, reoptimize,
 * PNG-to-JPG conversion, and migration. Most of these methods touch the
 * filesystem, queue database, or the remote API; only the pure-logic branches
 * are exercised here.
 *
 * Scope:
 *   - Constructor wiring: apiName is 'action', $api property is NOT set
 *     (ActionController runs without a remote API).
 *   - Singleton contract inherited from OptimizerBase.
 *   - checkItem() delegates to checkImageModel() — returns false when the model
 *     is missing, true when it is present.
 *   - sendToProcessing() dispatch: verifying routing without executing heavy handlers.
 *     We instrument via a spy subclass that records which handler was invoked.
 *   - handleAPIResult() marks the item failed when is_error is set (via the
 *     currently-set queue). The is_done-without-error branch calls finishItemProcess()
 *     which reaches the queue layer — tested at the level we can observe on the qItem.
 *   - enqueueItem() returns a stdClass with a qstatus field.
 *   - For 'reoptimize' action, the returned qStatus carries RESULT_ITEMS.
 *
 * Out of scope / why:
 *   - restoreItem(): calls imageModel->isRestorable() / restore() and hits the queue
 *     DB (itemFailed / finishItemProcess) — integration territory.
 *   - reoptimizeItem(): internally calls restoreItem() — same dependency chain.
 *   - convertPNG(): requires Converter::getConverter() wired to a live GD/Imagick
 *     environment plus queue DB.
 *   - migrate(): calls imageModel->migrate() which reads/writes postmeta — integration.
 *   - handleAPIResult() is_done-without-error branch: calls finishItemProcess() which
 *     requires a live queue object and item row.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Optimizer\ActionController;
use ShortPixel\Controller\Optimizer\OptimizerBase;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;

/**
 * Spy subclass that intercepts the heavy action handlers and records which
 * action path was triggered by sendToProcessing().
 */
class ActionControllerSpy extends ActionController {

	public $lastCalledAction = null;

	protected function restoreItem( QueueItem $qItem ) {
		$this->lastCalledAction = 'restore';
		$qItem->addResult( [ 'is_done' => true, 'is_error' => false ] );
		return true;
	}

	protected function reoptimizeItem( QueueItem $qItem ) {
		$this->lastCalledAction = 'reoptimize';
		// Set is_error=true so handleAPIResult() takes the itemFailed branch and
		// does NOT call finishItemProcess(). finishItemProcess() reads next_actions
		// (set by newReOptimizeAction) and calls $fs->getImage() → addItemToQueue(),
		// which crashes with a TypeError when getImage() returns false because the
		// stub attachment doesn't exist on disk.
		$qItem->addResult( [ 'is_done' => true, 'is_error' => true ] );
		return true;
	}

	protected function convertPNG( QueueItem $qItem ) {
		$this->lastCalledAction = 'png2jpg';
		$qItem->addResult( [ 'is_done' => true, 'is_error' => false ] );
		return true;
	}

	protected function migrate( QueueItem $qItem ) {
		$this->lastCalledAction = 'migrate';
		$qItem->addResult( [ 'is_done' => true, 'is_error' => false ] );
		return true;
	}
}

class ActionControllerTest extends WP_UnitTestCase {

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

	private function getPrivate( $object, string $prop ) {
		$ref = new ReflectionClass( get_class( $object ) );
		while ( ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $object );
	}

	/** Build an ImageModel stub. */
	private function makeImageModelStub( int $id = 1 ): ImageModel {
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

	/** Build a QueueItem with a valid image model. */
	private function makeQueueItem( int $id = 1 ): QueueItem {
		return new QueueItem( [ 'imageModel' => $this->makeImageModelStub( $id ) ] );
	}

	/** Build a QueueItem with NO image model. */
	private function makeOrphanQueueItem( int $id = 99 ): QueueItem {
		return new QueueItem( [ 'item_id' => $id ] );
	}

	/*
	 * Constructor wiring
	 */

	public function test_constructor_sets_apiName_to_action() {
		$ctrl = new ActionController();
		$this->assertSame( 'action', $this->getPrivate( $ctrl, 'apiName' ) );
	}

	public function test_constructor_does_not_set_api_property() {
		// ActionController runs without a remote API; $api should remain null.
		$ctrl = new ActionController();
		$api  = $this->getPrivate( $ctrl, 'api' );
		$this->assertNull( $api );
	}

	/*
	 * Singleton contract
	 */

	public function test_getInstance_returns_ActionController_instance() {
		$ctrl = ActionController::getInstance();
		$this->assertInstanceOf( ActionController::class, $ctrl );
	}

	public function test_getInstance_returns_same_object_on_repeated_calls() {
		$a = ActionController::getInstance();
		$b = ActionController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * checkItem
	 */

	public function test_checkItem_returns_false_when_image_model_is_missing() {
		$ctrl  = new ActionController();
		$qItem = $this->makeOrphanQueueItem();
		$this->assertFalse( $ctrl->checkItem( $qItem ) );
	}

	public function test_checkItem_returns_true_when_image_model_is_present() {
		$ctrl  = new ActionController();
		$qItem = $this->makeQueueItem();
		$this->assertTrue( $ctrl->checkItem( $qItem ) );
	}

	/*
	 * sendToProcessing — dispatch routing (via spy)
	 */

	public function test_sendToProcessing_routes_restore_action() {
		$spy   = new ActionControllerSpy();
		$qItem = $this->makeQueueItem();
		$qItem->setData( 'action', 'restore' );

		$spy->sendToProcessing( $qItem );

		$this->assertSame( 'restore', $spy->lastCalledAction );
	}

	public function test_sendToProcessing_routes_reoptimize_action() {
		$spy   = new ActionControllerSpy();
		$qItem = $this->makeQueueItem();
		$qItem->setData( 'action', 'reoptimize' );

		$spy->sendToProcessing( $qItem );

		$this->assertSame( 'reoptimize', $spy->lastCalledAction );
	}

	public function test_sendToProcessing_routes_png2jpg_action() {
		$spy   = new ActionControllerSpy();
		$qItem = $this->makeQueueItem();
		$qItem->setData( 'action', 'png2jpg' );

		$spy->sendToProcessing( $qItem );

		$this->assertSame( 'png2jpg', $spy->lastCalledAction );
	}

	public function test_sendToProcessing_routes_migrate_action() {
		$spy   = new ActionControllerSpy();
		$qItem = $this->makeQueueItem();
		$qItem->setData( 'action', 'migrate' );

		$spy->sendToProcessing( $qItem );

		$this->assertSame( 'migrate', $spy->lastCalledAction );
	}

	public function test_sendToProcessing_does_not_route_unknown_action() {
		$spy   = new ActionControllerSpy();
		$qItem = $this->makeQueueItem();
		$qItem->setData( 'action', 'totally_unknown_action' );

		$spy->sendToProcessing( $qItem );

		$this->assertNull( $spy->lastCalledAction );
	}

	/*
	 * enqueueItem — returns stdClass with qstatus
	 *
	 * We use ActionControllerSpy so sendToProcessing() and handleAPIResult()
	 * don't reach queue DB code (the spy stubs them out).
	 * But handleAPIResult() itself calls getCurrentQueue() → itemFailed() on the
	 * error branch. We pre-wire a minimal anonymous queue stub via setCurrentQueue()
	 * so no real DB call is made.
	 */

	/** Build a minimal queue stub that satisfies handleAPIResult()'s is_error branch. */
	private function makeQueueStub(): object {
		return new class {
			public function itemFailed( $qItem, $fatal = false ) {}
			public function getType() { return 'media'; }
		};
	}

	public function test_enqueueItem_returns_stdClass() {
		$spy = new ActionControllerSpy();
		$spy->setCurrentQueue( $this->makeQueueStub(), null );

		$qItem = $this->makeQueueItem();
		// Force error so handleAPIResult takes the itemFailed path (no finishItemProcess).
		$qItem->addResult( [ 'is_error' => true, 'is_done' => true ] );
		$qItem->setData( 'action', 'restore' );

		$result = $spy->enqueueItem( $qItem, [ 'action' => 'restore' ] );

		$this->assertInstanceOf( \stdClass::class, $result );
		$this->assertObjectHasProperty( 'qstatus', $result );
	}

	public function test_enqueueItem_reoptimize_sets_RESULT_ITEMS_qstatus() {
		$spy = new ActionControllerSpy();
		$spy->setCurrentQueue( $this->makeQueueStub(), null );

		$qItem = $this->makeQueueItem();
		$qItem->addResult( [ 'is_error' => true, 'is_done' => true ] );
		$qItem->setData( 'action', 'reoptimize' );

		$result = $spy->enqueueItem( $qItem, [ 'action' => 'reoptimize' ] );

		$this->assertSame( Queue::RESULT_ITEMS, $result->qstatus );
		$this->assertSame( 1, $result->numitems );
	}

	public function test_enqueueItem_non_reoptimize_action_sets_STATUS_NOT_API_qstatus() {
		$spy = new ActionControllerSpy();
		$spy->setCurrentQueue( $this->makeQueueStub(), null );

		$qItem = $this->makeQueueItem();
		$qItem->addResult( [ 'is_error' => true, 'is_done' => true ] );
		$qItem->setData( 'action', 'restore' );

		$result = $spy->enqueueItem( $qItem, [ 'action' => 'restore' ] );

		$this->assertSame( \ShortPixel\Controller\Api\RequestManager::STATUS_NOT_API, $result->qstatus );
	}
}
