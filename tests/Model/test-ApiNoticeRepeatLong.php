<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\ApiNoticeRepeatLong.
 *
 * Skipped at the unit level (integration territory):
 *   - checkTrigger → depends on ApiKeyController state + dismissed status of
 *     both MSG_NO_APIKEY and MSG_NO_APIKEY_REPEAT parent notices
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\ApiNoticeRepeatLong;

class ApiNoticeRepeatLongTest extends WP_UnitTestCase {

	private function getInherited( $instance, string $prop ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $instance );
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

	public function test_key_is_MSG_NO_APIKEY_REPEAT_LONG() {
		$this->assertSame( 'MSG_NO_APIKEY_REPEAT_LONG', ( new ApiNoticeRepeatLong() )->getKey() );
	}

	public function test_errorLevel_is_warning() {
		$this->assertSame( 'warning', $this->getInherited( new ApiNoticeRepeatLong(), 'errorLevel' ) );
	}

	public function test_getMessage_promotes_api_key_signup() {
		$html = $this->invokeProtected( new ApiNoticeRepeatLong(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'API key', $html );
		$this->assertStringContainsString( 'shortpixel.com', $html );
	}
}
