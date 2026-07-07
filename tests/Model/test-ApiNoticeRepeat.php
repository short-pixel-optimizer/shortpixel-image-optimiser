<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\ApiNoticeRepeat.
 *
 * Skipped at the unit level (integration territory):
 *   - checkTrigger → depends on ApiKeyController::keyIsVerified() and the
 *     dismissed state of the parent MSG_NO_APIKEY notice
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\ApiNoticeRepeat;

class ApiNoticeRepeatTest extends WP_UnitTestCase {

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

	public function test_key_is_MSG_NO_APIKEY_REPEAT() {
		$this->assertSame( 'MSG_NO_APIKEY_REPEAT', ( new ApiNoticeRepeat() )->getKey() );
	}

	public function test_errorLevel_is_warning() {
		$this->assertSame( 'warning', $this->getInherited( new ApiNoticeRepeat(), 'errorLevel' ) );
	}

	public function test_getMessage_prompts_for_api_key_activation() {
		$html = $this->invokeProtected( new ApiNoticeRepeat(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'API key', $html );
		$this->assertStringContainsString( 'shortpixel.com', $html );
	}
}
