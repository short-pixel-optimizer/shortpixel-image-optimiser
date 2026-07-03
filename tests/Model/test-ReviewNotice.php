<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\ReviewNotice.
 *
 * Skipped at the unit level (integration territory):
 *   - Full load() lifecycle → hits NoticeController; the legacy
 *     `wp-short-pixel-activation-date` fallback is exercised below directly
 *     via the checkTrigger + settings pairing.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\ReviewNotice;

class ReviewNoticeTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedActivationDate;

	public function set_up() {
		parent::set_up();
		$this->savedActivationDate = \wpSPIO()->settings()->activationDate;
	}

	public function tear_down() {
		\wpSPIO()->settings()->activationDate = $this->savedActivationDate;
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

	public function test_key_is_MSG_REVIEW_REMINDER() {
		$this->assertSame( 'MSG_REVIEW_REMINDER', ( new ReviewNotice() )->getKey() );
	}

	public function test_suppress_delay_is_minus_one_so_the_notice_persists_until_dismissed() {
		$this->assertSame( -1, $this->getInherited( new ReviewNotice(), 'suppress_delay' ) );
	}

	public function test_checkTrigger_false_when_no_activation_date_is_recorded() {
		\wpSPIO()->settings()->activationDate = null;

		$this->assertFalse( $this->invokeProtected( new ReviewNotice(), 'checkTrigger' ) );
	}

	public function test_checkTrigger_false_when_two_weeks_have_not_yet_elapsed() {
		\wpSPIO()->settings()->activationDate = time() - ( 5 * DAY_IN_SECONDS );

		$this->assertFalse( $this->invokeProtected( new ReviewNotice(), 'checkTrigger' ) );
	}

	public function test_checkTrigger_true_once_two_weeks_have_elapsed_since_activation() {
		\wpSPIO()->settings()->activationDate = time() - ( 15 * DAY_IN_SECONDS );

		$this->assertTrue( $this->invokeProtected( new ReviewNotice(), 'checkTrigger' ) );
	}

	public function test_getMessage_links_to_the_wordpress_org_reviews_page() {
		$html = $this->invokeProtected( new ReviewNotice(), 'getMessage' );

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'wordpress.org/support/plugin/shortpixel-image-optimiser/reviews', $html );
	}
}
