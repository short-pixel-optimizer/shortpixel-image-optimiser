<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\NewExclusionFormat.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\NewExclusionFormat;

class NewExclusionFormatTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedExcludePatterns;

	public function set_up() {
		parent::set_up();
		$this->savedExcludePatterns = \wpSPIO()->settings()->excludePatterns;
	}

	public function tear_down() {
		\wpSPIO()->settings()->excludePatterns = $this->savedExcludePatterns;
		parent::tear_down();
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

	public function test_key_is_MSG_EXCLUSION_WARNING() {
		$this->assertSame( 'MSG_EXCLUSION_WARNING', ( new NewExclusionFormat() )->getKey() );
	}

	public function test_checkTrigger_false_when_excludePatterns_is_not_an_array() {
		\wpSPIO()->settings()->excludePatterns = null;
		$this->assertFalse( $this->invokeProtected( new NewExclusionFormat(), 'checkTrigger' ) );
	}

	public function test_checkTrigger_false_when_all_patterns_have_apply_field() {
		\wpSPIO()->settings()->excludePatterns = array(
			array( 'type' => 'name', 'value' => 'foo', 'apply' => 'thumbs' ),
			array( 'type' => 'path', 'value' => 'bar', 'apply' => 'main' ),
		);

		$this->assertFalse( $this->invokeProtected( new NewExclusionFormat(), 'checkTrigger' ) );
	}

	public function test_checkTrigger_true_when_at_least_one_pattern_is_missing_apply_field() {
		\wpSPIO()->settings()->excludePatterns = array(
			array( 'type' => 'name', 'value' => 'foo', 'apply' => 'thumbs' ),
			array( 'type' => 'path', 'value' => 'bar' ), // legacy shape — no apply.
		);

		$this->assertTrue( $this->invokeProtected( new NewExclusionFormat(), 'checkTrigger' ) );
	}

	public function test_getMessage_links_to_the_exclusions_settings_tab() {
		$html = $this->invokeProtected( new NewExclusionFormat(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( '5.5.0', $html );
		$this->assertStringContainsString( 'part=exclusions', $html );
	}
}
