<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\LitespeedCache.
 *
 * Skipped at the unit level (integration territory):
 *   - The full checkTriggers() flow → requires the LSCWP_DIR constant AND
 *     the litespeed.conf.img_optm-webp option AND
 *     wpSPIO()->env()->useDoubleWebpExtension(). The "LiteSpeed not
 *     installed" branch is unit-tested below; the rest belongs in the
 *     integration harness.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\LitespeedCache;

class LitespeedCacheTest extends WP_UnitTestCase {

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

	public function test_key_is_MSG_LITESPEED_WEBP() {
		$this->assertSame( 'MSG_LITESPEED_WEBP', ( new LitespeedCache() )->getKey() );
	}

	public function test_errorLevel_is_warning() {
		$this->assertSame( 'warning', $this->getInherited( new LitespeedCache(), 'errorLevel' ) );
	}

	public function test_checkTrigger_returns_false_when_litespeed_is_not_installed() {
		// LSCWP_DIR constant is expected to be undefined in the test env.
		if ( defined( 'LSCWP_DIR' ) ) {
			$this->markTestSkipped( 'LSCWP_DIR is defined; cannot exercise the not-installed branch.' );
		}

		$this->assertFalse( $this->invokeProtected( new LitespeedCache(), 'checkTrigger' ) );
	}

	public function test_getMessage_links_to_the_kb_article() {
		$html = $this->invokeProtected( new LitespeedCache(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'Litespeed', $html );
		$this->assertStringContainsString( 'shortpixel.com/knowledge-base', $html );
	}
}
