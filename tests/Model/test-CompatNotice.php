<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\CompatNotice.
 *
 * Skipped at the unit level (integration territory):
 *   - getConflictingPlugins() → calls is_plugin_active() against a curated
 *     list of ~20 competitor plugins. Unit-testing each branch would
 *     require installing every plugin as a fixture; the integration tier
 *     handles this.
 *   - checkTrigger / checkReset → thin wrappers around getConflictingPlugins.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\CompatNotice;

class CompatNoticeTest extends WP_UnitTestCase {

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

	public function test_key_is_MSG_COMPAT() {
		$this->assertSame( 'MSG_COMPAT', ( new CompatNotice() )->getKey() );
	}

	public function test_errorLevel_is_warning() {
		$this->assertSame( 'warning', $this->getInherited( new CompatNotice(), 'errorLevel' ) );
	}

	public function test_getMessage_lists_conflicting_plugins_when_data_is_seeded() {
		$m = new CompatNotice();
		$this->invokeProtected( $m, 'addData', array( 'conflicts', array(
			array(
				'name'    => 'Test Plugin',
				'action'  => 'Deactivate',
				'path'    => 'test/test.php',
				'href'    => null,
				'page'    => null,
				'details' => null,
			),
		) ) );

		$html = $this->invokeProtected( $m, 'getMessage' );

		$this->assertStringContainsString( 'Test Plugin', $html );
		$this->assertStringContainsString( 'Deactivate', $html );
		$this->assertStringContainsString( 'sp-conflict-plugins', $html );
	}

	public function test_getMessage_handles_empty_conflicts_gracefully() {
		$html = $this->invokeProtected( new CompatNotice(), 'getMessage' );

		// Even without conflicts, the introductory copy + <ul> shell must render.
		$this->assertStringContainsString( 'not compatible', $html );
		$this->assertStringContainsString( '<ul', $html );
	}
}
