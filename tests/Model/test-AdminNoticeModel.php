<?php
/**
 * Tests for ShortPixel\Model\AdminNoticeModel.
 *
 * Exercises the abstract base's behaviour through a small concrete
 * fixture (SPIO_TestAdminNotice) declared alongside so we can control
 * the two abstract methods (checkTrigger, getMessage) without touching
 * a real notice subclass.
 *
 * Skipped at the unit level (integration territory):
 *   - Full load() lifecycle → depends on NoticeController state we can't easily control
 *   - add() creating a real notice → touches WordPress transients + admin_notices hook
 *   - Screen-limiting in add() → needs a WP_Screen active
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNoticeModel;

/**
 * Concrete stub used to test the abstract base.
 * Declared at file scope so the class autoloader has stable ordering.
 */
class SPIO_TestAdminNotice extends AdminNoticeModel {
	protected $key = 'MSG_TEST_NOTICE';

	public $triggerReturn = false;
	public $messageReturn = 'test message';

	protected function checkTrigger() {
		return $this->triggerReturn;
	}

	protected function getMessage() {
		return $this->messageReturn;
	}
}

class AdminNoticeModelTest extends WP_UnitTestCase {

	private function getPrivate( AdminNoticeModel $m, string $prop ) {
		$ref = new ReflectionClass( AdminNoticeModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function setPrivate( AdminNoticeModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( AdminNoticeModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function invokePrivate( AdminNoticeModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( AdminNoticeModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	/*
	 * getKey — returns the declared key
	 */

	public function test_getKey_returns_the_declared_notice_key() {
		$m = new SPIO_TestAdminNotice();
		$this->assertSame( 'MSG_TEST_NOTICE', $m->getKey() );
	}

	/*
	 * getNoticeObj — returns whatever is stored on $notice (null until add())
	 */

	public function test_getNoticeObj_returns_null_before_load_or_add() {
		$m = new SPIO_TestAdminNotice();
		$this->assertNull( $m->getNoticeObj() );
	}

	public function test_getNoticeObj_returns_the_stored_notice_object_when_set() {
		$stub = new stdClass();
		$m    = new SPIO_TestAdminNotice();
		$this->setPrivate( $m, 'notice', $stub );

		$this->assertSame( $stub, $m->getNoticeObj() );
	}

	/*
	 * addData / getData (protected) — the internal key/value bag consumed by
	 * concrete getMessage() implementations
	 */

	public function test_addData_and_getData_roundtrip() {
		$m = new SPIO_TestAdminNotice();

		$this->invokePrivate( $m, 'addData', array( 'foo', 'bar' ) );

		$this->assertSame( 'bar', $this->invokePrivate( $m, 'getData', array( 'foo' ) ) );
	}

	public function test_getData_returns_false_for_unknown_key() {
		$m = new SPIO_TestAdminNotice();
		$this->assertFalse( $this->invokePrivate( $m, 'getData', array( 'not-set' ) ) );
	}

	public function test_addData_supports_arbitrary_value_types() {
		$m = new SPIO_TestAdminNotice();
		$this->invokePrivate( $m, 'addData', array( 'int', 42 ) );
		$this->invokePrivate( $m, 'addData', array( 'arr', array( 'a', 'b' ) ) );
		$this->invokePrivate( $m, 'addData', array( 'bool', false ) );

		$this->assertSame( 42, $this->invokePrivate( $m, 'getData', array( 'int' ) ) );
		$this->assertSame( array( 'a', 'b' ), $this->invokePrivate( $m, 'getData', array( 'arr' ) ) );
		$this->assertSame( false, $this->invokePrivate( $m, 'getData', array( 'bool' ) ) );
	}

	/*
	 * isDismissed — proxy to the stored notice object
	 */

	public function test_isDismissed_false_when_no_notice_object_is_set() {
		$m = new SPIO_TestAdminNotice();
		$this->assertFalse( $m->isDismissed() );
	}

	public function test_isDismissed_returns_the_stored_notice_dismiss_state() {
		$dismissedNotice = new class {
			public function isDismissed() { return true; }
		};

		$m = new SPIO_TestAdminNotice();
		$this->setPrivate( $m, 'notice', $dismissedNotice );

		$this->assertTrue( $m->isDismissed() );
	}

	public function test_isDismissed_false_when_notice_reports_not_dismissed() {
		$activeNotice = new class {
			public function isDismissed() { return false; }
		};

		$m = new SPIO_TestAdminNotice();
		$this->setPrivate( $m, 'notice', $activeNotice );

		$this->assertFalse( $m->isDismissed() );
	}

	/*
	 * checkReset — base class default is false; subclasses override
	 */

	public function test_base_checkReset_default_is_false() {
		$m = new SPIO_TestAdminNotice();
		$this->assertFalse( $this->invokePrivate( $m, 'checkReset' ) );
	}

	/*
	 * Property defaults
	 */

	public function test_errorLevel_defaults_to_normal() {
		$m = new SPIO_TestAdminNotice();
		$this->assertSame( 'normal', $this->getPrivate( $m, 'errorLevel' ) );
	}

	public function test_suppress_delay_defaults_to_year_in_seconds() {
		$m = new SPIO_TestAdminNotice();
		$this->assertSame( YEAR_IN_SECONDS, $this->getPrivate( $m, 'suppress_delay' ) );
	}

	public function test_include_screens_defaults_to_empty_array() {
		$m = new SPIO_TestAdminNotice();
		$this->assertSame( array(), $this->getPrivate( $m, 'include_screens' ) );
	}

	public function test_exclude_screens_defaults_to_empty_array() {
		$m = new SPIO_TestAdminNotice();
		$this->assertSame( array(), $this->getPrivate( $m, 'exclude_screens' ) );
	}
}
