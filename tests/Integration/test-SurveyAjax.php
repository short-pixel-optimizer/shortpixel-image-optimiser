<?php
/**
 * End-to-end coverage for the NPS survey AJAX endpoint (2aac87c6):
 * AjaxController::ajax_submitSurvey() behind the real
 * wp_ajax_shortpixel_survey_submit dispatch.
 *
 * Flow under test (see ReviewNotice::getMessage() for the client side):
 * - 'rating' 9-10 (promoter): survey closes immediately, cta = 'review',
 *   NOTHING is posted to the feedback endpoint.
 * - 'rating' 1-8: survey stays 'pending' (waiting for the textarea), the
 *   bare score is forwarded to api.shortpixel.com/v2/feedback.php right
 *   away so an abandoned flow still leaves a signal.
 * - 'feedback': stores the text, closes the survey, forwards score+text
 *   in a second feedback.php call.
 * - 'dismiss': closes the survey without a score.
 * - Closed surveys refuse any further calls (idempotency/replay guard).
 *
 * The outbound feedback POST is swallowed by MockShortPixelApi's catch-all
 * (benign 200 '{}' for unknown *.shortpixel.com paths) and recorded in
 * $this->api->requests, which is what the transmission assertions read.
 *
 * Documented-behaviour note (NOT a strict requirement): out-of-range or
 * missing ratings are CLAMPED into 1..10 (missing → 1) rather than
 * rejected — see test_out_of_range_rating_is_clamped_not_rejected.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Model\AdminNotices\ReviewNotice;

class SurveyAjaxTest extends SPIO_AjaxTestCase {

	public function set_up() {
		parent::set_up();

		// Every test starts with an open survey.
		$settings = \wpSPIO()->settings();
		$settings->surveyStatus     = 'pending';
		$settings->surveyScore      = 0;
		$settings->surveyFeedback   = '';
		$settings->surveyAnsweredAt = null;
	}

	/**
	 * Fire a shortpixel_survey_submit request with a valid nonce.
	 *
	 * @param array $fields survey_action / rating / feedback fields.
	 * @return object|null Decoded JSON response.
	 */
	private function doSurvey( array $fields ): ?object {
		$_POST = array_merge(
			array( 'nonce' => wp_create_nonce( ReviewNotice::AJAX_ACTION ) ),
			$fields
		);
		$_REQUEST = $_POST;

		return $this->doAjax( ReviewNotice::AJAX_ACTION );
	}

	/** All mock-recorded calls that went to the feedback endpoint. */
	private function feedbackCalls(): array {
		return array_values( array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], '/v2/feedback.php' );
			}
		) );
	}

	// -------------------------------------------------------------------
	// Rating step
	// -------------------------------------------------------------------

	public function test_promoter_rating_closes_the_survey_with_a_review_cta() {
		$this->_setRole( 'administrator' );

		$response = $this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 9 ) );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->status );
		$this->assertSame( 'review', $response->cta );

		$settings = \wpSPIO()->settings();
		$this->assertSame( 'answered', $settings->surveyStatus );
		$this->assertSame( 9, $settings->surveyScore );
		$this->assertNotEmpty( $settings->surveyAnsweredAt );

		// Promoters are steered to WordPress.org — nothing goes to the
		// feedback endpoint.
		$this->assertCount( 0, $this->feedbackCalls() );
	}

	public function test_detractor_rating_keeps_survey_open_and_pings_the_score() {
		$this->_setRole( 'administrator' );

		$response = $this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 5 ) );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->status );
		$this->assertSame( 'feedback', $response->cta );

		$settings = \wpSPIO()->settings();
		// Still pending: the textarea submit closes it.
		$this->assertSame( 'pending', $settings->surveyStatus );
		$this->assertSame( 5, $settings->surveyScore );

		// The bare score must be forwarded immediately (abandon-safe).
		$calls = $this->feedbackCalls();
		$this->assertCount( 1, $calls );
		$details = $calls[0]['args']['body']['wordpress']['deactivated_plugin']['uninstall_details'];
		$this->assertSame( 'Rating: 5', $details );
	}

	// -------------------------------------------------------------------
	// Feedback step
	// -------------------------------------------------------------------

	public function test_feedback_submit_stores_text_closes_survey_and_forwards_it() {
		$this->_setRole( 'administrator' );

		// Step 1: the detractor click (sends the bare-score ping).
		$this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 3 ) );

		// Step 2: the textarea submit.
		$response = $this->doSurvey( array(
			'survey_action' => 'feedback',
			'rating'        => 3,
			'feedback'      => 'The bulk page is confusing.',
		) );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $response->status );
		$this->assertSame( 'thanks', $response->cta );

		$settings = \wpSPIO()->settings();
		$this->assertSame( 'answered', $settings->surveyStatus );
		$this->assertSame( 3, $settings->surveyScore );
		$this->assertSame( 'The bulk page is confusing.', $settings->surveyFeedback );
		$this->assertNotEmpty( $settings->surveyAnsweredAt );

		// Two transmissions: bare score, then score + text.
		$calls = $this->feedbackCalls();
		$this->assertCount( 2, $calls );
		$details = $calls[1]['args']['body']['wordpress']['deactivated_plugin']['uninstall_details'];
		$this->assertSame( 'Rating: 3 - Feedback: The bulk page is confusing.', $details );
	}

	public function test_feedback_text_is_sanitised_and_capped_at_2000_chars() {
		$this->_setRole( 'administrator' );

		$long = str_repeat( 'x', 2500 ) . '<script>alert(1)</script>';
		$response = $this->doSurvey( array(
			'survey_action' => 'feedback',
			'rating'        => 2,
			'feedback'      => $long,
		) );

		$this->assertTrue( $response->status );

		$stored = \wpSPIO()->settings()->surveyFeedback;
		$this->assertSame( 2000, mb_strlen( $stored ) );
		$this->assertStringNotContainsString( '<script>', $stored );
	}

	// -------------------------------------------------------------------
	// Dismiss + replay guard
	// -------------------------------------------------------------------

	public function test_dismiss_closes_the_survey_without_transmitting_anything() {
		$this->_setRole( 'administrator' );

		$response = $this->doSurvey( array( 'survey_action' => 'dismiss' ) );

		$this->assertTrue( $response->status );

		$settings = \wpSPIO()->settings();
		$this->assertSame( 'dismissed', $settings->surveyStatus );
		$this->assertNotEmpty( $settings->surveyAnsweredAt );
		$this->assertCount( 0, $this->feedbackCalls() );
	}

	public function test_closed_survey_refuses_further_submissions() {
		$this->_setRole( 'administrator' );

		$this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 10 ) );
		$this->assertSame( 'answered', \wpSPIO()->settings()->surveyStatus );

		// Replay attempt: must be refused, score must not change.
		$response = $this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 1 ) );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertFalse( $response->status );
		$this->assertSame( 10, \wpSPIO()->settings()->surveyScore );
		$this->assertCount( 0, $this->feedbackCalls() );
	}

	public function test_unknown_survey_action_is_refused() {
		$this->_setRole( 'administrator' );

		$response = $this->doSurvey( array( 'survey_action' => 'exploit' ) );

		$this->assertFalse( $response->status );
		$this->assertSame( 'pending', \wpSPIO()->settings()->surveyStatus );
	}

	// -------------------------------------------------------------------
	// Gates
	// -------------------------------------------------------------------

	public function test_non_admin_users_are_denied() {
		$this->_setRole( 'editor' );

		$response = $this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 9 ) );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertFalse( $response->status );
		$this->assertSame( AjaxController::NO_ACCESS, $response->error );
		$this->assertSame( 'pending', \wpSPIO()->settings()->surveyStatus );
	}

	public function test_invalid_nonce_is_rejected() {
		$this->_setRole( 'administrator' );

		$_POST = array(
			'nonce'         => 'bogus',
			'survey_action' => 'rating',
			'rating'        => 9,
		);
		$_REQUEST = $_POST;
		$response = $this->doAjax( ReviewNotice::AJAX_ACTION );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertFalse( $response->status );
		$this->assertSame( AjaxController::NONCE_FAILED, $response->error );
		$this->assertSame( 'pending', \wpSPIO()->settings()->surveyStatus );
	}

	// -------------------------------------------------------------------
	// Documented behaviour: clamping instead of rejection
	// -------------------------------------------------------------------

	/**
	 * The handler clamps ratings into 1..10 (max(1, min(10, intval))) —
	 * a missing or garbage rating therefore lands on 1 and an oversized
	 * one on 10, silently recorded as a real answer. Documented here so a
	 * deliberate change to strict rejection shows up as a test change.
	 */
	public function test_out_of_range_rating_is_clamped_not_rejected() {
		$this->_setRole( 'administrator' );

		$response = $this->doSurvey( array( 'survey_action' => 'rating', 'rating' => 99 ) );

		$this->assertTrue( $response->status );
		$this->assertSame( 'review', $response->cta ); // clamped to 10 → promoter path.
		$this->assertSame( 10, \wpSPIO()->settings()->surveyScore );
	}
}
