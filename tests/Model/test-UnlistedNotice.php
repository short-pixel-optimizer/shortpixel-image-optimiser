<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\UnlistedNotice.
 *
 * This notice is triggered manually via addManual() — checkTrigger() always
 * returns false so getMessage() is the primary target here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\UnlistedNotice;

class UnlistedNoticeTest extends WP_UnitTestCase {

	private function invokeProtected( $instance, string $method, array $args = array() ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $instance, ...$args );
	}

	public function test_key_is_MSG_UNLISTED_FOUND() {
		$this->assertSame( 'MSG_UNLISTED_FOUND', ( new UnlistedNotice() )->getKey() );
	}

	public function test_checkTrigger_is_always_false_manual_trigger_only() {
		$this->assertFalse( $this->invokeProtected( new UnlistedNotice(), 'checkTrigger' ) );
	}

	public function test_getMessage_includes_data_supplied_via_addManual() {
		$m = new UnlistedNotice();
		$this->invokeProtected( $m, 'addData', array( 'id', 42 ) );
		$this->invokeProtected( $m, 'addData', array( 'name', 'my-image.jpg' ) );
		$this->invokeProtected( $m, 'addData', array( 'filelist', array( 'extra1.jpg', 'extra2.jpg' ) ) );

		$html = $this->invokeProtected( $m, 'getMessage' );

		$this->assertStringContainsString( 'my-image.jpg', $html );
		$this->assertStringContainsString( 'post=42', $html );
		$this->assertStringContainsString( 'extra1.jpg', $html );
		$this->assertStringContainsString( 'extra2.jpg', $html );
		$this->assertStringContainsString( 'Optimize unlisted thumbnails', $html );
	}
}
