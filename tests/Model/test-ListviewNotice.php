<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\ListviewNotice.
 *
 * Skipped at the unit level (integration territory):
 *   - checkTrigger / checkReset with a signed-in user + `upload` screen →
 *     needs a live WP_Screen and settled user-option storage. Integration
 *     harness covers the "user has switched to list view" reset path.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\ListviewNotice;

class ListviewNoticeTest extends WP_UnitTestCase {

	private $savedScreenId;

	public function set_up() {
		parent::set_up();
		$this->savedScreenId = \wpSPIO()->env()->screen_id;
	}

	public function tear_down() {
		\wpSPIO()->env()->screen_id = $this->savedScreenId;
		parent::tear_down();
	}

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

	public function test_key_is_MSG_LISTVIEW_ACTIVE() {
		$this->assertSame( 'MSG_LISTVIEW_ACTIVE', ( new ListviewNotice() )->getKey() );
	}

	public function test_constructor_limits_notice_to_the_upload_screen() {
		$this->assertContains(
			'upload',
			$this->getInherited( new ListviewNotice(), 'include_screens' )
		);
	}

	public function test_checkTrigger_returns_false_when_current_screen_is_not_upload() {
		\wpSPIO()->env()->screen_id = 'dashboard';

		$this->assertFalse( $this->invokeProtected( new ListviewNotice(), 'checkTrigger' ) );
	}

	public function test_getMessage_prompts_the_switch_to_list_view() {
		$html = $this->invokeProtected( new ListviewNotice(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'list view', $html );
		$this->assertStringContainsString( 'upload.php?mode=list', $html );
	}
}
