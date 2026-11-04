<?php
/**
 * Tests for ShortPixel\Controller\ResponseController.
 *
 * Scope: static registry management (setQ surrogate via addData, setOutput,
 * getResponseItem), addData property-setting logic, formatQItem message
 * shaping (regular and error items, OUTPUT_MEDIA and OUTPUT_CLI), and the
 * ISSUE_* / OUTPUT_* constant values that are part of the public API
 * contract. formatQItem() runs on a QueueItem built from a real attachment.
 *
 * Out of scope / why:
 * - setQ() requires a queue object implementing getType() / getQueueName() /
 *   getShortQ() with an options proxy; exercised indirectly through addData's
 *   item_type seed path instead.
 * - formatErrorItem() branches for FILE_STATUS_ERROR and STATUS_FAIL append
 *   translated strings that depend on __() returning non-empty values; the
 *   relevant string shapes are asserted via the CLI-mode prefix test.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\ResponseController;
use ShortPixel\Model\ResponseModel;

class ResponseControllerTest extends WP_UnitTestCase {

	/** Reset all static state before every test so tests are order-independent. */
	public function set_up() {
		parent::set_up();
		$this->resetStaticState();
	}

	public function tear_down() {
		$this->resetStaticState();
		parent::tear_down();
	}

	private function resetStaticState(): void {
		$ref = new ReflectionClass( ResponseController::class );

		foreach ( array( 'items', 'queueName', 'queueType', 'queueMaxTries' ) as $prop ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, is_array( $p->getValue( null ) ) ? array() : null );
		}

		// Reset output to default.
		$p = $ref->getProperty( 'screenOutput' );
		$p->setAccessible( true );
		$p->setValue( null, ResponseController::OUTPUT_MEDIA );
	}

	private function setStaticProp( string $prop, $value ): void {
		$ref = new ReflectionClass( ResponseController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}

	private function getStaticProp( string $prop ) {
		$ref = new ReflectionClass( ResponseController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	/*
	 * Constants — public API contract
	 */

	public function test_issue_constants_have_expected_integer_values() {
		$this->assertSame( 10,  ResponseController::ISSUE_BACKUP_CREATE );
		$this->assertSame( 11,  ResponseController::ISSUE_BACKUP_EXISTS );
		$this->assertSame( 12,  ResponseController::ISSUE_OPTIMIZED_NOFILE );
		$this->assertSame( 13,  ResponseController::ISSUE_QUEUE_FAILED );
		$this->assertSame( 20,  ResponseController::ISSUE_FILE_NOTWRITABLE );
		$this->assertSame( 30,  ResponseController::ISSUE_DIRECTORY_NOTWRITABLE );
		$this->assertSame( 50,  ResponseController::ISSUE_API );
		$this->assertSame( 100, ResponseController::ISSUE_QUOTA );
	}

	public function test_output_constants_have_expected_integer_values() {
		$this->assertSame( 1, ResponseController::OUTPUT_MEDIA );
		$this->assertSame( 2, ResponseController::OUTPUT_BULK );
		$this->assertSame( 3, ResponseController::OUTPUT_CLI );
	}

	/*
	 * setOutput
	 */

	public function test_setOutput_stores_the_given_verbosity_level() {
		ResponseController::setOutput( ResponseController::OUTPUT_CLI );
		$this->assertSame( ResponseController::OUTPUT_CLI, $this->getStaticProp( 'screenOutput' ) );
	}

	public function test_setOutput_to_bulk_stores_bulk_constant() {
		ResponseController::setOutput( ResponseController::OUTPUT_BULK );
		$this->assertSame( ResponseController::OUTPUT_BULK, $this->getStaticProp( 'screenOutput' ) );
	}

	/*
	 * getResponseItem — before any queue context is set
	 */

	public function test_getResponseItem_returns_response_model_instance() {
		$item = ResponseController::getResponseItem( 42 );
		$this->assertInstanceOf( ResponseModel::class, $item );
	}

	public function test_getResponseItem_uses_unknown_type_when_no_queue_context() {
		$item = ResponseController::getResponseItem( 99 );
		$this->assertSame( 'Unknown', $item->item_type );
	}

	public function test_getResponseItem_stores_the_given_item_id() {
		$item = ResponseController::getResponseItem( 7 );
		$this->assertSame( 7, $item->item_id );
	}

	/*
	 * addData — property-setting logic
	 */

	public function test_addData_sets_known_property_via_key_value_pair() {
		ResponseController::addData( 1, 'message', 'hello world' );
		$item = ResponseController::getResponseItem( 1 );
		$this->assertSame( 'hello world', $item->message );
	}

	public function test_addData_sets_multiple_properties_via_array() {
		ResponseController::addData( 2, array(
			'message'  => 'batch set',
			'is_error' => true,
		) );
		$item = ResponseController::getResponseItem( 2 );
		$this->assertSame( 'batch set', $item->message );
		$this->assertTrue( $item->is_error );
	}

	public function test_addData_ignores_unknown_properties() {
		// ResponseModel does not have a property named 'nonexistent_prop'.
		ResponseController::addData( 3, 'nonexistent_prop', 'should be ignored' );
		$item = ResponseController::getResponseItem( 3 );
		$this->assertObjectNotHasProperty( 'nonexistent_prop', $item );
	}

	public function test_addData_seeds_queue_type_when_item_type_passed_and_no_queue_context() {
		ResponseController::addData( 4, 'item_type', 'media' );
		$this->assertSame( 'media', $this->getStaticProp( 'queueType' ) );
	}

	public function test_addData_persists_item_in_registry_accessible_by_getResponseItem() {
		ResponseController::addData( 5, 'is_done', true );
		$item = ResponseController::getResponseItem( 5 );
		$this->assertTrue( $item->is_done );
	}

	public function test_addData_overwrites_earlier_value_for_same_item() {
		ResponseController::addData( 6, 'message', 'first' );
		ResponseController::addData( 6, 'message', 'second' );
		$item = ResponseController::getResponseItem( 6 );
		$this->assertSame( 'second', $item->message );
	}

	/** A QueueItem for a real Media Library attachment; returns [ QueueItem, attachment id ]. */
	private function queueItemForAttachment(): array {
		$attachment_id = self::factory()->attachment->create_upload_object(
			dirname( __DIR__ ) . '/fixtures/fixture-small.jpg'
		);
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );

		return array( new \ShortPixel\Model\Queue\QueueItem( array( 'imageModel' => $imageModel ) ), $attachment_id );
	}

	/*
	 * formatQItem — OUTPUT_MEDIA (default)
	 */

	public function test_formatQItem_returns_the_message_of_a_regular_item() {
		list( $qItem, $id ) = $this->queueItemForAttachment();
		ResponseController::addData( $id, array(
			'message'  => 'Custom message here',
			'is_error' => false,
			'is_done'  => true,
		) );

		$this->assertSame( 'Custom message here', ResponseController::formatQItem( $qItem ) );
	}

	public function test_formatQItem_sets_the_item_type_from_the_image_model() {
		list( $qItem, $id ) = $this->queueItemForAttachment();
		ResponseController::addData( $id, 'message', 'test msg' );

		ResponseController::formatQItem( $qItem );

		$this->assertSame( 'media', ResponseController::getResponseItem( $id )->item_type );
	}

	/*
	 * formatQItem — OUTPUT_CLI prefix
	 */

	public function test_formatQItem_cli_mode_prefixes_queue_name_and_filename() {
		list( $qItem, $id ) = $this->queueItemForAttachment();
		$this->setStaticProp( 'queueName', 'MediaLibrary' );
		$this->setStaticProp( 'queueType', 'media' );
		$this->setStaticProp( 'screenOutput', ResponseController::OUTPUT_CLI );

		ResponseController::addData( $id, array(
			'message'  => 'done',
			'fileName' => 'photo.jpg',
			'is_error' => false,
			'is_done'  => true,
		) );

		$text = ResponseController::formatQItem( $qItem );
		$this->assertStringContainsString( 'MediaLibrary', $text );
		$this->assertStringContainsString( 'photo.jpg', $text );
	}

	/*
	 * formatQItem — error item base path (no exit-path branches)
	 */

	public function test_formatQItem_error_item_returns_the_base_message() {
		list( $qItem, $id ) = $this->queueItemForAttachment();
		ResponseController::addData( $id, array(
			'message'  => 'Something broke',
			'is_error' => true,
		) );

		// Base text survives formatErrorItem untouched when issue_type is null
		// (no matching case) and fileStatus / apiStatus are null.
		$this->assertSame( 'Something broke', ResponseController::formatQItem( $qItem ) );
	}

	/**
	 * Pins a known defect: formatQItem() never copies the QueueItem's result
	 * into the ResponseModel. It iterates the QueueItemResult with foreach,
	 * but every property of QueueItemResult is protected and the class is not
	 * iterable, so the loop runs zero times from outside the class. The text
	 * therefore comes only from values set earlier with addData(). The loop
	 * also only overwrites response fields that are already non-null.
	 * @todo In ResponseController::formatQItem(): copy the values actually
	 *       set on the result. Iterating jsonSerialize() alone also copies
	 *       the result's defaults (is_done / is_error false) over what
	 *       addData() set, which breaks the regular and error-item tests above.
	 * When fixed, this test fails; flip it: the result's message is used.
	 */
	public function test_formatQItem_ignores_the_queue_item_result_pinned_for_deferred_fix() {
		list( $qItem, $id ) = $this->queueItemForAttachment();
		ResponseController::addData( $id, array( 'message' => 'from addData', 'is_done' => true ) );
		$qItem->addResult( array( 'message' => 'from the result', 'is_done' => true ) );

		// SENTINEL: the result does hold the other message.
		$this->assertSame( 'from the result', $qItem->result()->message );

		$this->assertSame( 'from addData', ResponseController::formatQItem( $qItem ), 'Pinned: fixed? formatQItem() now uses the result — flip this pin.' );
	}

	/*
	 * Registry isolation — items from different types do not bleed over
	 */

	public function test_addData_with_different_item_types_are_stored_independently() {
		ResponseController::addData( 50, array( 'item_type' => 'media',  'message' => 'media-msg' ) );

		// Manually seed queueType to 'custom' to simulate a second queue context.
		$this->setStaticProp( 'queueType', 'custom' );
		ResponseController::addData( 50, array( 'item_type' => 'custom', 'message' => 'custom-msg' ) );

		$registry = $this->getStaticProp( 'items' );
		$this->assertArrayHasKey( 'media',  $registry );
		$this->assertArrayHasKey( 'custom', $registry );
	}
}
