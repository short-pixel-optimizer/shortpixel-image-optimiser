<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\SpaiCDN.
 *
 * Skipped at the unit level (integration territory):
 *   - Full checkTrigger / checkReset → depend on wpSPIO()->env()->plugin_active('spai'),
 *     which requires the SPAI plugin file to be installed
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\SpaiCDN;

class SpaiCDNTest extends WP_UnitTestCase {

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

	public function test_key_is_MSG_SPAICDN() {
		$this->assertSame( 'MSG_SPAICDN', ( new SpaiCDN() )->getKey() );
	}

	public function test_errorLevel_is_error() {
		$this->assertSame( 'error', $this->getInherited( new SpaiCDN(), 'errorLevel' ) );
	}

	public function test_getMessage_prompts_deactivation_of_spai() {
		$html = $this->invokeProtected( new SpaiCDN(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'Adaptive Images', $html );
		$this->assertStringContainsString( 'shortpixel_deactivate_conflict_plugin', $html );
	}
}
