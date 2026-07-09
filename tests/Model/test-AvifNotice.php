<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\AvifNotice.
 *
 * Skipped at the unit level (integration territory):
 *   - check() → performs a real HTTP request via get_headers() to the plugin's
 *     test.avif asset. Would need pre_http_request stubbing plus fetching
 *     from get_headers() (which is not filterable) to unit-test cleanly.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\AvifNotice;

class AvifNoticeTest extends WP_UnitTestCase {

	private function getInherited( $instance, string $prop ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $instance );
	}

	private function setInherited( $instance, string $prop, $value ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $instance, $value );
	}

	private function invokeProtected( $instance, string $method, array $args = array() ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $instance, ...$args );
	}

	public function test_key_is_MSG_AVIF_ERROR() {
		$this->assertSame( 'MSG_AVIF_ERROR', ( new AvifNotice() )->getKey() );
	}

	public function test_errorLevel_is_error() {
		$this->assertSame( 'error', $this->getInherited( new AvifNotice(), 'errorLevel' ) );
	}

	public function test_checkTrigger_is_always_false_because_auto_trigger_is_disabled() {
		$this->assertFalse( $this->invokeProtected( new AvifNotice(), 'checkTrigger' ) );
	}

	public function test_getMessage_renders_configured_error_details_and_dismiss_button() {
		$m = new AvifNotice();

		// Pre-populate the error fields the way check() would.
		$this->setInherited( $m, 'error_message', 'AVIF headers missing' );
		$this->setInherited( $m, 'error_detail', 'Fix your server' );

		$html = $this->invokeProtected( $m, 'getMessage' );

		$this->assertStringContainsString( 'AVIF headers missing', $html );
		$this->assertStringContainsString( 'Fix your server', $html );
		$this->assertStringContainsString( 'notice-dismiss-action', $html );
	}
}
