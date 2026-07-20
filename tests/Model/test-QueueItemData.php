<?php
/**
 * Tests for ShortPixel\Model\Queue\QueueItemData.
 *
 * Data-container companion to QueueItemResult. Adds a FIFO of next actions,
 * a "keep data" preserved-data map, and a lazy counters object. Every field
 * is protected, so state is inspected via reflection.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Queue\QueueItemData;

class QueueItemDataTest extends WP_UnitTestCase {

	private function getPrivate( QueueItemData $d, string $prop ) {
		$ref = new ReflectionClass( QueueItemData::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $d );
	}

	private function setPrivate( QueueItemData $d, string $prop, $value ): void {
		$ref = new ReflectionClass( QueueItemData::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $d, $value );
	}

	/*
	 * Constructor — declared defaults all null
	 */

	public function test_constructor_leaves_every_field_uninitialised() {
		$d = new QueueItemData();
		$this->assertNull( $this->getPrivate( $d, 'urls' ) );
		$this->assertNull( $this->getPrivate( $d, 'action' ) );
		$this->assertNull( $this->getPrivate( $d, 'next_actions' ) );
		$this->assertNull( $this->getPrivate( $d, 'flags' ) );
		$this->assertNull( $this->getPrivate( $d, 'counts' ) );
	}

	/*
	 * __get — schema-gated reads, with the flags[] coercion
	 */

	public function test_get_returns_the_value_of_a_declared_field() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'action', 'optimize' );
		$this->assertSame( 'optimize', $d->action );
	}

	public function test_get_returns_null_for_unknown_field() {
		$this->assertNull( ( new QueueItemData() )->definitely_unknown );
	}

	public function test_get_coerces_null_flags_to_empty_array() {
		$d = new QueueItemData();
		$this->assertSame( array(), $d->flags );
	}

	public function test_get_coerces_non_array_flags_to_empty_array() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'flags', 'not-an-array' );

		$this->assertSame( array(), $d->flags );
	}

	public function test_get_returns_array_flags_verbatim() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'flags', array( 'a', 'b' ) );

		$this->assertSame( array( 'a', 'b' ), $d->flags );
	}

	/*
	 * __set — schema-gated writes
	 */

	public function test_set_writes_to_a_declared_field() {
		$d          = new QueueItemData();
		$d->action  = 'restore';
		$d->tries   = 3;

		$this->assertSame( 'restore', $this->getPrivate( $d, 'action' ) );
		$this->assertSame( 3, $this->getPrivate( $d, 'tries' ) );
	}

	public function test_set_silently_drops_writes_to_unknown_field() {
		$d = new QueueItemData();
		$d->not_a_real_field = 'nope';

		$this->assertFalse( property_exists( $d, 'not_a_real_field' ) );
	}

	/*
	 * remove — resets to null
	 */

	public function test_remove_clears_a_declared_field() {
		$d = new QueueItemData();
		$d->action = 'restore';

		$d->remove( 'action' );

		$this->assertNull( $this->getPrivate( $d, 'action' ) );
	}

	public function test_remove_is_a_noop_for_unknown_field() {
		$d = new QueueItemData();
		$d->remove( 'not-a-real-field' );
		$this->assertFalse( property_exists( $d, 'not-a-real-field' ) );
	}

	/*
	 * toObject — strips uninitialised fields
	 */

	public function test_toObject_strips_null_fields_and_keeps_the_set_ones() {
		$d          = new QueueItemData();
		$d->action  = 'optimize';
		$d->tries   = 2;

		$obj = $d->toObject();

		$this->assertSame( 'optimize', $obj->action );
		$this->assertSame( 2, $obj->tries );
		$this->assertObjectNotHasProperty( 'urls', $obj );
		$this->assertObjectNotHasProperty( 'files', $obj );
	}

	/*
	 * hasAction — matches current or queued actions
	 */

	public function test_hasAction_true_when_action_matches_current() {
		$d = new QueueItemData();
		$d->action = 'optimize';
		$this->assertTrue( $d->hasAction( 'optimize' ) );
	}

	public function test_hasAction_true_when_action_matches_next_actions_entry() {
		$d = new QueueItemData();
		$d->action = 'restore';
		$this->setPrivate( $d, 'next_actions', array( 'optimize', 'convert_api' ) );

		$this->assertTrue( $d->hasAction( 'convert_api' ) );
	}

	public function test_hasAction_false_when_action_matches_neither_current_nor_queued() {
		$d = new QueueItemData();
		$d->action = 'restore';
		$this->setPrivate( $d, 'next_actions', array( 'optimize' ) );

		$this->assertFalse( $d->hasAction( 'convert_api' ) );
	}

	/*
	 * next-actions FIFO — addNextAction / hasNextAction / popNextAction
	 */

	public function test_addNextAction_initialises_the_list_when_null() {
		$d = new QueueItemData();
		$d->addNextAction( 'optimize' );
		$this->assertSame( array( 'optimize' ), $this->getPrivate( $d, 'next_actions' ) );
	}

	public function test_addNextAction_appends_to_existing_list() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'next_actions', array( 'restore' ) );

		$d->addNextAction( 'optimize' );

		$this->assertSame( array( 'restore', 'optimize' ), $this->getPrivate( $d, 'next_actions' ) );
	}

	public function test_hasNextAction_true_when_queue_has_entries() {
		$d = new QueueItemData();
		$d->addNextAction( 'optimize' );
		$this->assertTrue( $d->hasNextAction() );
	}

	public function test_hasNextAction_returns_false_when_queue_is_empty() {
		$this->assertFalse( ( new QueueItemData() )->hasNextAction() );
	}

	public function test_popNextAction_returns_null_when_queue_is_empty() {
		$this->assertNull( ( new QueueItemData() )->popNextAction() );
	}

	public function test_popNextAction_returns_and_removes_the_first_entry_fifo() {
		$d = new QueueItemData();
		$d->addNextAction( 'restore' );
		$d->addNextAction( 'optimize' );
		$d->addNextAction( 'convert_api' );

		$this->assertSame( 'restore', $d->popNextAction() );
		$this->assertSame( 'optimize', $d->popNextAction() );
		$this->assertSame( 'convert_api', $d->popNextAction() );
		$this->assertNull( $d->popNextAction() );
	}

	/*
	 * addKeepDataArgs / getKeepDataArgs — the preserved-data map
	 */

	public function test_addKeepDataArgs_wraps_a_non_array_input_into_an_array() {
		$d = new QueueItemData();
		$d->addKeepDataArgs( 'compressionType' );
		$this->assertSame( array( 'compressionType' ), $this->getPrivate( $d, 'next_keepdata' ) );
	}

	public function test_addKeepDataArgs_appends_to_existing_map() {
		$d = new QueueItemData();
		$d->addKeepDataArgs( array( 'compressionType' ) );
		$d->addKeepDataArgs( array( 'smartcrop', 'urls' ) );

		$this->assertSame(
			array( 'compressionType', 'smartcrop', 'urls' ),
			$this->getPrivate( $d, 'next_keepdata' )
		);
	}

	public function test_getKeepDataArgs_returns_empty_array_when_nothing_registered() {
		$this->assertSame( array(), ( new QueueItemData() )->getKeepDataArgs() );
	}

	public function test_getKeepDataArgs_pulls_property_values_for_numeric_key_string_value_entries() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'compressionType', 2 );
		$this->setPrivate( $d, 'smartcrop', true );
		$d->addKeepDataArgs( array( 'compressionType', 'smartcrop' ) );

		$this->assertSame(
			array( 'compressionType' => 2, 'smartcrop' => true ),
			$d->getKeepDataArgs()
		);
	}

	public function test_getKeepDataArgs_skips_numeric_entries_whose_property_is_null() {
		$d = new QueueItemData();
		$this->setPrivate( $d, 'compressionType', null );
		$d->addKeepDataArgs( array( 'compressionType' ) );

		$this->assertSame( array(), $d->getKeepDataArgs() );
	}

	public function test_getKeepDataArgs_skips_numeric_entries_that_do_not_map_to_a_property() {
		$d = new QueueItemData();
		$d->addKeepDataArgs( array( 'not_a_real_property' ) );

		$this->assertSame( array(), $d->getKeepDataArgs() );
	}

	public function test_getKeepDataArgs_keeps_string_keyed_entries_verbatim() {
		$d = new QueueItemData();
		$d->addKeepDataArgs( array( 'custom_key' => 'custom_value' ) );

		$this->assertSame( array( 'custom_key' => 'custom_value' ), $d->getKeepDataArgs() );
	}

	public function test_getKeepDataArgs_drops_string_keyed_entries_whose_value_is_null() {
		$d = new QueueItemData();
		$d->addKeepDataArgs( array( 'custom_key' => null ) );

		$this->assertSame( array(), $d->getKeepDataArgs() );
	}

	/*
	 * addCount — lazy counters
	 */

	public function test_addCount_lazily_creates_the_counts_object() {
		$d = new QueueItemData();
		$this->assertNull( $this->getPrivate( $d, 'counts' ) );

		$d->addCount( array( 'baseCount' => 5 ) );

		$counts = $this->getPrivate( $d, 'counts' );
		$this->assertInstanceOf( stdClass::class, $counts );
		$this->assertSame( 5, $counts->baseCount );
	}

	public function test_addCount_merges_new_values_into_existing_counts_object() {
		$d = new QueueItemData();
		$d->addCount( array( 'baseCount' => 5, 'webpCount' => 3 ) );
		$d->addCount( array( 'avifCount' => 2, 'baseCount' => 7 ) );

		$counts = $this->getPrivate( $d, 'counts' );
		$this->assertSame( 7, $counts->baseCount );   // overwritten
		$this->assertSame( 3, $counts->webpCount );   // preserved
		$this->assertSame( 2, $counts->avifCount );   // added
	}
}
