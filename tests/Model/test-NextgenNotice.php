<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\NextgenNotice.
 *
 * Skipped at the unit level (integration territory):
 *   - Full checkTrigger with a verified API key + NextGen Gallery installed
 *     → needs ApiKeyController state + env->has_nextgen fixture.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\NextgenNotice;

class NextgenNoticeTest extends WP_UnitTestCase {

	private function invokeProtected( $instance, string $method, array $args = array() ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$r = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $instance, ...$args );
	}

	public function test_key_is_MSG_INTEGRATION_NGGALLERY() {
		$this->assertSame( 'MSG_INTEGRATION_NGGALLERY', ( new NextgenNotice() )->getKey() );
	}

	public function test_getMessage_prompts_the_nextgen_integration_toggle() {
		$html = $this->invokeProtected( new NextgenNotice(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'NextGen', $html );
		$this->assertStringContainsString( 'part=optimisation', $html );
	}
}
