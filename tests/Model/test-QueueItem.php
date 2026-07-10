<?php
/**
 * Tests for ShortPixel\Model\Queue\QueueItem.
 *
 * Focus areas:
 *   - Constructor's dual init shape (imageModel vs. item_id)
 *   - setModel / setData / block / data() / result() / __get / set
 *   - getQueueItem / returnEnqueue payload shape
 *   - Simple new*Action methods (Restore, RemoveLegacy, Migrate, GetAltData,
 *     ReOptimize, retrieveAlt)
 *   - newAction() carry-forward of next_actions + keep_data
 *   - addResult forwarding
 *   - getAPIController action → controller routing
 *   - checkImageModelExists guard
 *
 * Skipped at the unit level (integration territory — need a live ImageModel
 * with real optimize URLs, AI backend, or filesystem):
 *   - newDumpAction               → calls $imageModel->getOptimizeUrls()
 *   - newOptimizeAction           → builds params from getOptimizeData(), invokes Converter::getConverter
 *   - requestAltAction            → hits AiDataModel + AI backend
 *   - newRemoveBackgroundAction   → UiHelper::findBestPreview + hex-alpha wiring
 *   - newScaleImageAction         → getOriginalFile + getUrl
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Queue\QueueItemData;
use ShortPixel\Model\Queue\QueueItemResult;
use ShortPixel\Model\Image\ImageModel;

class QueueItemTest extends WP_UnitTestCase {

	private function getPrivate( QueueItem $q, string $prop ) {
		$ref = new ReflectionClass( QueueItem::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $q );
	}

	private function setPrivate( QueueItem $q, string $prop, $value ): void {
		$ref = new ReflectionClass( QueueItem::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $q, $value );
	}

	private function invokePrivate( QueueItem $q, string $method, array $args = array() ) {
		$ref = new ReflectionClass( QueueItem::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $q, ...$args );
	}

	/**
	 * Build a mock ImageModel subclass overriding `get()` so setModel()
	 * can capture the id without instantiating a real MediaLibraryModel.
	 */
	private function makeImageModelStub( int $id ): ImageModel {
		return new class( $id ) extends ImageModel {
			private $stub_id;
			public function __construct( $id ) {
				$this->stub_id = $id;
			}
			public function get( $name ) {
				if ( $name === 'id' ) {
					return $this->stub_id;
				}
				return null;
			}
			public function getOptimizeUrls() { return array(); }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return array(); }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
		};
	}

	/*
	 * Constructor — the two shapes plus the always-seeded data object
	 */

	public function test_constructor_always_seeds_a_fresh_data_object() {
		$q = new QueueItem();
		$this->assertInstanceOf( QueueItemData::class, $q->data() );
	}

	public function test_constructor_captures_item_id_when_provided() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertSame( 42, $this->getPrivate( $q, 'item_id' ) );
		$this->assertNull( $this->getPrivate( $q, 'imageModel' ) );
	}

	public function test_constructor_binds_image_model_when_provided_and_captures_id() {
		$image = $this->makeImageModelStub( 7 );

		$q = new QueueItem( array( 'imageModel' => $image ) );

		$this->assertSame( $image, $this->getPrivate( $q, 'imageModel' ) );
		$this->assertSame( 7, $this->getPrivate( $q, 'item_id' ) );
	}

	public function test_constructor_with_no_args_leaves_item_id_and_imageModel_null() {
		$q = new QueueItem();
		$this->assertNull( $this->getPrivate( $q, 'item_id' ) );
		$this->assertNull( $this->getPrivate( $q, 'imageModel' ) );
	}

	public function test_constructor_ignores_non_object_imageModel() {
		$q = new QueueItem( array( 'imageModel' => 'not-an-object' ) );
		$this->assertNull( $this->getPrivate( $q, 'imageModel' ) );
	}

	/*
	 * setModel — assigns and captures id
	 */

	public function test_setModel_assigns_the_image_model_and_captures_its_id() {
		$image = $this->makeImageModelStub( 42 );

		$q = new QueueItem();
		$q->setModel( $image );

		$this->assertSame( $image, $this->getPrivate( $q, 'imageModel' ) );
		$this->assertSame( 42, $this->getPrivate( $q, 'item_id' ) );
	}

	/*
	 * setData / setFromQueueData — proxies to data->__set
	 */

	public function test_setData_writes_through_to_the_data_object() {
		$q = new QueueItem();
		$q->setData( 'action', 'optimize' );

		$this->assertSame( 'optimize', $q->data()->action );
	}

	public function test_setFromQueueData_iterates_and_forwards_each_key_to_setData() {
		$q = new QueueItem();
		$q->setFromQueueData( (object) array(
			'action' => 'restore',
			'tries'  => 2,
		) );

		$this->assertSame( 'restore', $q->data()->action );
		$this->assertSame( 2, $q->data()->tries );
	}

	/*
	 * block — dual getter/setter behaviour
	 */

	public function test_block_with_no_argument_returns_current_data_block_value() {
		$q = new QueueItem();
		$q->data()->block = true;
		$this->assertTrue( $q->block() );
	}

	public function test_block_with_argument_sets_the_data_block_field() {
		$q = new QueueItem();
		$q->block( true );
		$this->assertTrue( $q->data()->block );

		$q->block( false );
		$this->assertFalse( $q->data()->block );
	}

	/*
	 * data() / result() — accessors, result() lazily creates
	 */

	public function test_data_returns_the_internal_QueueItemData() {
		$q = new QueueItem();
		$this->assertSame( $q->data(), $this->getPrivate( $q, 'data' ) );
	}

	public function test_result_lazily_creates_a_QueueItemResult_with_the_item_id() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertNull( $this->getPrivate( $q, 'result' ) );

		$result = $q->result();

		$this->assertInstanceOf( QueueItemResult::class, $result );
		$this->assertSame( 42, $result->item_id );
	}

	public function test_result_returns_the_same_instance_on_repeated_calls() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertSame( $q->result(), $q->result() );
	}

	/*
	 * set() — protected-property write guarded by property_exists
	 */

	public function test_set_writes_to_a_known_property() {
		$q = new QueueItem();
		$q->set( 'item_count', 5 );
		$this->assertSame( 5, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_set_silently_ignores_unknown_property() {
		$q = new QueueItem();
		$q->set( 'not_a_real_property', 'ignored' );
		$this->assertFalse( property_exists( $q, 'not_a_real_property' ) );
	}

	/*
	 * __get — schema-gated reads, null for unknown
	 */

	public function test_get_returns_the_value_of_a_declared_property() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->assertSame( 42, $q->item_id );
	}

	public function test_get_returns_null_for_unknown_property() {
		$q = new QueueItem();
		$this->assertNull( $q->definitely_not_a_field );
	}

	/*
	 * getQueueItem — wraps the stored envelope with the fresh data payload
	 */

	public function test_getQueueItem_returns_false_when_no_envelope_is_set() {
		$this->assertFalse( ( new QueueItem() )->getQueueItem() );
	}

	public function test_getQueueItem_populates_value_from_the_current_data_object() {
		$envelope = (object) array( 'id' => 42 );

		$q = new QueueItem( array( 'item_id' => 42 ) );
		$this->setPrivate( $q, 'queueItem', $envelope );
		$q->data()->action = 'optimize';

		$out = $q->getQueueItem();

		$this->assertSame( 42, $out->id );
		$this->assertObjectHasAttribute( 'value', $out );
		$this->assertSame( 'optimize', $out->value->action );
	}

	/*
	 * returnEnqueue — array shape used to feed the underlying shortq layer
	 */

	public function test_returnEnqueue_returns_id_value_item_count_at_minimum() {
		$q = new QueueItem( array( 'item_id' => 42 ) );
		$q->data()->action = 'optimize';
		$q->set( 'item_count', 3 );

		$payload = $q->returnEnqueue();

		$this->assertSame( 42, $payload['id'] );
		$this->assertSame( 3, $payload['item_count'] );
		$this->assertSame( 'optimize', $payload['value']->action );
		$this->assertArrayNotHasKey( 'order', $payload );
	}

	public function test_returnEnqueue_includes_order_when_queue_list_order_is_set() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->data()->queue_list_order = 99;

		$payload = $q->returnEnqueue();

		$this->assertSame( 99, $payload['order'] );
	}

	/*
	 * setDebug
	 */

	public function test_setDebug_flips_the_debug_active_flag() {
		$q = new QueueItem();
		$this->assertFalse( $this->getPrivate( $q, 'debug_active' ) );

		$q->setDebug();

		$this->assertTrue( $this->getPrivate( $q, 'debug_active' ) );
	}

	/*
	 * Simple new*Action methods — each sets the action label and item_count
	 * and does not require an ImageModel.
	 */

	public function test_newRestoreAction_sets_restore_action_and_item_count_1() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->newRestoreAction();

		$this->assertSame( 'restore', $q->data()->action );
		$this->assertSame( 1, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_newRemoveLegacyAction_sets_removeLegacy_action_and_item_count_1() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->newRemoveLegacyAction();

		$this->assertSame( 'removeLegacy', $q->data()->action );
		$this->assertSame( 1, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_newMigrateAction_sets_migrate_action_and_item_count_1() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->newMigrateAction();

		$this->assertSame( 'migrate', $q->data()->action );
		$this->assertSame( 1, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_getAltDataAction_sets_getAltData_action_and_item_count_0() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->getAltDataAction();

		$this->assertSame( 'getAltData', $q->data()->action );
		$this->assertSame( 0, $this->getPrivate( $q, 'item_count' ) );
	}

	/*
	 * retrieveAltAction — remote_id capture + defensive isset guard
	 * (Bas's fix in b8d29c4: `$args['remote_id']` is now optional; missing
	 * keys default to null instead of raising an undefined-index notice.)
	 */

	public function test_retrieveAltAction_captures_remote_id_when_provided() {
		$q = new QueueItem( array( 'item_id' => 1 ) );

		$q->retrieveAltAction( array( 'remote_id' => 'abc-123' ) );

		$this->assertSame( 'abc-123', $q->data()->remote_id );
		$this->assertSame( 'retrieveAlt', $q->data()->action );
		$this->assertSame( 0, $q->data()->tries );
		$this->assertSame( 1, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_retrieveAltAction_defaults_remote_id_to_null_when_args_omit_it() {
		$q = new QueueItem( array( 'item_id' => 1 ) );

		// Sentinel: catch E_NOTICE / E_WARNING at the test level so a
		// silently-swallowed undefined-index (e.g. if the harness's
		// convertNoticesToExceptions isn't in effect) still fails the
		// test loudly. Handler returns true so PHP doesn't chain to the
		// default handler and we keep control of the assertion.
		$noticed = false;
		$previous = set_error_handler( function ( $errno ) use ( &$noticed ) {
			if ( $errno & ( E_NOTICE | E_WARNING | E_USER_NOTICE | E_USER_WARNING ) ) {
				$noticed = true;
			}
			return true;
		} );

		try {
			$q->retrieveAltAction( array() );
		} finally {
			set_error_handler( $previous );
		}

		$this->assertFalse( $noticed, 'retrieveAltAction raised a notice when remote_id was omitted' );
		// Positive side-effect assertions — a "did not throw" check alone
		// could pass on a partial execution path.
		$this->assertNull( $q->data()->remote_id );
		$this->assertSame( 'retrieveAlt', $q->data()->action );
		$this->assertSame( 0, $q->data()->tries );
		$this->assertSame( 1, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_retrieveAltAction_writes_returndatalist_when_provided() {
		$q = new QueueItem( array( 'item_id' => 1 ) );

		$q->retrieveAltAction( array(
			'remote_id'      => 'abc-123',
			'returndatalist' => array( 'alt' => array( 'status' => 1 ) ),
		) );

		$this->assertSame(
			array( 'alt' => array( 'status' => 1 ) ),
			$q->data()->returndatalist
		);
	}

	/*
	 * newReOptimizeAction — chains optimize, keep_data covers compressionType + smartcrop
	 */

	public function test_newReOptimizeAction_chains_optimize_and_registers_keep_data() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->newReOptimizeAction();

		$this->assertSame( 'reoptimize', $q->data()->action );
		$this->assertSame( array( 'optimize' ), $q->data()->next_actions );
		$this->assertSame( 1, $this->getPrivate( $q, 'item_count' ) );
	}

	public function test_newReOptimizeAction_applies_smartcrop_and_compressionType_args() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->newReOptimizeAction( array( 'smartcrop' => true, 'compressionType' => 2 ) );

		$this->assertTrue( $q->data()->smartcrop );
		$this->assertSame( 2, $q->data()->compressionType );
	}

	/*
	 * newAction() — carry-forward of next_actions + keep_data across the reset
	 */

	public function test_newAction_preserves_queued_next_actions_across_the_data_reset() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->newReOptimizeAction( array( 'compressionType' => 2, 'smartcrop' => true ) );

		// This is what happens when the reoptimize stage transitions to optimize:
		$this->invokePrivate( $q, 'newAction' );

		// The chained optimize action must survive.
		$this->assertSame( array( 'optimize' ), $q->data()->next_actions );

		// keep_data fields must be applied to the fresh data object.
		$this->assertSame( 2, $q->data()->compressionType );
		$this->assertTrue( $q->data()->smartcrop );
	}

	public function test_newAction_creates_a_fresh_result_object() {
		$q       = new QueueItem( array( 'item_id' => 1 ) );
		$before  = $q->result();
		$before->message = 'stale';

		$this->invokePrivate( $q, 'newAction' );

		$after = $q->result();
		$this->assertNotSame( $before, $after );
		$this->assertNull( $after->message );
	}

	/*
	 * addResult — forwards field-by-field to result()
	 */

	public function test_addResult_forwards_each_entry_to_the_result_object() {
		$q = new QueueItem( array( 'item_id' => 1 ) );
		$q->addResult( array(
			'message'   => 'ok',
			'apiStatus' => 2,
			'is_done'   => true,
		) );

		$this->assertSame( 'ok', $q->result()->message );
		$this->assertSame( 2, $q->result()->apiStatus );
		$this->assertTrue( $q->result()->is_done );
	}

	/*
	 * getAPIController — action → controller routing
	 */

	public function test_getAPIController_routes_optimize_family_to_OptimizeController() {
		$q = new QueueItem();
		foreach ( array( 'optimize', 'dumpItem', 'convert_api', 'remove_background', 'scale_image' ) as $action ) {
			$this->assertInstanceOf(
				\ShortPixel\Controller\Optimizer\OptimizeController::class,
				$q->getAPIController( $action ),
				"Action '$action' should route to OptimizeController"
			);
		}
	}

	public function test_getAPIController_routes_ai_family_to_OptimizeAiController() {
		$q = new QueueItem();
		foreach ( array( 'requestAlt', 'retrieveAlt', 'getAltData', 'undoAI', 'redoAI' ) as $action ) {
			$this->assertInstanceOf(
				\ShortPixel\Controller\Optimizer\OptimizeAiController::class,
				$q->getAPIController( $action ),
				"Action '$action' should route to OptimizeAiController"
			);
		}
	}

	public function test_getAPIController_routes_action_family_to_ActionController() {
		$q = new QueueItem();
		foreach ( array( 'restore', 'reoptimize', 'migrate', 'png2jpg', 'removeLegacy' ) as $action ) {
			$this->assertInstanceOf(
				\ShortPixel\Controller\Optimizer\ActionController::class,
				$q->getAPIController( $action ),
				"Action '$action' should route to ActionController"
			);
		}
	}

	public function test_getAPIController_returns_null_for_unknown_action() {
		$q = new QueueItem();
		$this->assertNull( $q->getAPIController( 'not_a_real_action' ) );
	}

	public function test_getAPIController_falls_back_to_current_data_action_when_called_with_no_argument() {
		$q = new QueueItem();
		$q->data()->action = 'restore';

		$this->assertInstanceOf(
			\ShortPixel\Controller\Optimizer\ActionController::class,
			$q->getAPIController()
		);
	}

	/*
	 * checkImageModelExists
	 */

	public function test_checkImageModelExists_false_when_no_image_model_bound() {
		$this->assertFalse( ( new QueueItem( array( 'item_id' => 42 ) ) )->checkImageModelExists() );
	}

	public function test_checkImageModelExists_true_when_image_model_bound() {
		$q = new QueueItem( array( 'imageModel' => $this->makeImageModelStub( 42 ) ) );
		$this->assertTrue( $q->checkImageModelExists() );
	}
}
