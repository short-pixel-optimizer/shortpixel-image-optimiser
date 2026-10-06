<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\LegacyNotice.
 *
 * This notice is triggered manually — checkTrigger() intentionally returns
 * false so getMessage() is the only content-bearing method to cover.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\LegacyNotice;

class LegacyNoticeTest extends WP_UnitTestCase {

	private function invokeProtected( $instance, string $method, array $args = array() ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $instance, ...$args );
	}

	public function test_key_is_MSG_CONVERT_LEGACY() {
		$this->assertSame( 'MSG_CONVERT_LEGACY', ( new LegacyNotice() )->getKey() );
	}

	public function test_checkTrigger_is_always_false_manual_trigger_only() {
		$this->assertFalse( $this->invokeProtected( new LegacyNotice(), 'checkTrigger' ) );
	}

	public function test_getMessage_advertises_the_bulk_migration_flow() {
		$html = $this->invokeProtected( new LegacyNotice(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'outdated optimization format', $html );
		$this->assertStringContainsString( 'bulk-migrate', $html );
	}
}
