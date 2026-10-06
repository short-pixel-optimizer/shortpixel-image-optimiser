<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\ApiNotice.
 *
 * Skipped at the unit level (integration territory):
 *   - Full load() lifecycle → hits NoticeController + activation-date write
 *   - checkTrigger / checkReset → depend on ApiKeyController state (which
 *     requires manipulating stored API-key options + shortpixel_options row;
 *     covered by test-ApiKeyModel and the future integration harness)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\ApiNotice;
use ShortPixel\Model\AdminNoticeModel;

class ApiNoticeTest extends WP_UnitTestCase {

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

	public function test_key_is_MSG_NO_APIKEY() {
		$this->assertSame( 'MSG_NO_APIKEY', ( new ApiNotice() )->getKey() );
	}

	public function test_exclude_screens_includes_settings_page() {
		$this->assertContains(
			'settings_page_wp-shortpixel-settings',
			$this->getInherited( new ApiNotice(), 'exclude_screens' )
		);
	}

	public function test_getMessage_returns_api_key_configuration_prompt() {
		$html = $this->invokeProtected( new ApiNotice(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'API key', $html );
		$this->assertStringContainsString( 'wp-shortpixel-settings', $html );
	}
}
