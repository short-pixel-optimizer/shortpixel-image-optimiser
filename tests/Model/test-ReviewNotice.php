<?php
/**
 * Tests for ShortPixel\Model\AdminNotices\ReviewNotice — since 2aac87c6 an
 * NPS-style survey widget (1-10 rating, feedback textarea, review CTA) gated
 * on the surveyStatus setting instead of a plain review link.
 *
 * Skipped at the unit level (integration territory):
 *   - Full load() lifecycle → hits NoticeController; the legacy
 *     `wp-short-pixel-activation-date` fallback is exercised below directly
 *     via the checkTrigger + settings pairing.
 *   - The AJAX endpoint behind the widget → tests/Integration/test-SurveyAjax.php.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AdminNotices\ReviewNotice;

class ReviewNoticeTest extends WP_UnitTestCase {

	/** @var mixed */
	private $savedActivationDate;

	/** @var mixed */
	private $savedSurveyStatus;

	public function set_up() {
		parent::set_up();
		$this->savedActivationDate = \wpSPIO()->settings()->activationDate;
		$this->savedSurveyStatus   = \wpSPIO()->settings()->surveyStatus;
	}

	public function tear_down() {
		\wpSPIO()->settings()->activationDate = $this->savedActivationDate;
		\wpSPIO()->settings()->surveyStatus   = $this->savedSurveyStatus;
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

	// -------------------------------------------------------------------
	// NPS survey (2aac87c6): status gating + widget contents
	// -------------------------------------------------------------------

	public function test_checkTrigger_false_once_the_survey_was_answered() {
		\wpSPIO()->settings()->activationDate = time() - ( 15 * DAY_IN_SECONDS );
		\wpSPIO()->settings()->surveyStatus   = 'answered';

		$this->assertFalse( $this->invokeProtected( new ReviewNotice(), 'checkTrigger' ) );
	}

	public function test_checkTrigger_false_once_the_survey_was_dismissed() {
		\wpSPIO()->settings()->activationDate = time() - ( 15 * DAY_IN_SECONDS );
		\wpSPIO()->settings()->surveyStatus   = 'dismissed';

		$this->assertFalse( $this->invokeProtected( new ReviewNotice(), 'checkTrigger' ) );
	}

	public function test_checkReset_false_while_survey_is_pending() {
		\wpSPIO()->settings()->surveyStatus = 'pending';

		$this->assertFalse( $this->invokeProtected( new ReviewNotice(), 'checkReset' ) );
	}

	public function test_checkReset_true_once_survey_is_closed() {
		\wpSPIO()->settings()->surveyStatus = 'answered';
		$this->assertTrue( $this->invokeProtected( new ReviewNotice(), 'checkReset' ) );

		\wpSPIO()->settings()->surveyStatus = 'dismissed';
		$this->assertTrue( $this->invokeProtected( new ReviewNotice(), 'checkReset' ) );
	}

	public function test_getMessage_renders_the_full_1_to_10_rating_scale() {
		$html = $this->invokeProtected( new ReviewNotice(), 'getMessage' );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertStringContainsString( 'data-score="' . $i . '"', $html );
		}
		// Exactly ten buttons, no stray extras.
		$this->assertSame( 10, substr_count( $html, 'spio-survey-score"' ) );
	}

	public function test_getMessage_contains_feedback_step_and_ajax_wiring() {
		$html = $this->invokeProtected( new ReviewNotice(), 'getMessage' );

		$this->assertStringContainsString( 'spio-survey-step-feedback', $html );
		$this->assertStringContainsString( 'spio-survey-feedback-text', $html );
		// The widget must post to the registered wp_ajax action…
		$this->assertStringContainsString( ReviewNotice::AJAX_ACTION, $html );
		// …with a nonce baked into the page.
		$this->assertStringContainsString( 'nonce', $html );
		// Promoter CTA goes to the 5-star-filtered review list.
		$this->assertStringContainsString( 'reviews/?filter=5', $html );
	}
}
