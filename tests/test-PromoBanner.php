<?php
/**
 * Tests for ShortPixel\PromoBanner\PromoBanner (build/shortpixel/promo-banner).
 *
 * Scope: the show conditions of shouldShow() (campaign id, time window,
 * capability, dismissal, screen condition, the no_banner filter), the
 * inclusive window bounds, markup escaping, the option name / uninstall, and
 * the dismiss AJAX endpoint (nonce, capability, stored campaign). Every
 * banner here uses its own prefix, so the option and AJAX action never touch
 * the plugin's live `spio` banner.
 *
 * Out of scope / why:
 * - The SHORTPIXEL_NO_BANNER constant: a constant cannot be undefined again
 *   inside one PHP process, so defining it would silence every later test.
 * - Asset enqueueing and the CSS / JS: covered by looking at the page, not
 *   by unit tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\PromoBanner\PromoBanner;

class PromoBannerTest extends WP_UnitTestCase {

	private const PREFIX = 'spiotest';

	public function tear_down() {
		PromoBanner::uninstall( self::PREFIX );
		parent::tear_down();
	}

	/** A banner whose window contains now, shown on every screen. */
	private function banner( array $args = array() ): PromoBanner {
		return new PromoBanner(
			array_merge(
				array(
					'id'         => 'test-campaign',
					'start'      => time() - HOUR_IN_SECONDS,
					'end'        => time() + HOUR_IN_SECONDS,
					'prefix'     => self::PREFIX,
					'assets_url' => 'https://example.org/assets/',
					'condition'  => '__return_true',
				),
				$args
			)
		);
	}

	private function loginAs( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_shows_for_an_administrator_inside_the_window() {
		$this->loginAs( 'administrator' );

		$this->assertTrue( $this->banner()->shouldShow() );
	}

	public function test_never_shows_without_a_campaign_id() {
		$this->loginAs( 'administrator' );

		$this->assertFalse( $this->banner( array( 'id' => '' ) )->shouldShow() );
	}

	public function test_does_not_show_before_or_after_the_window() {
		$this->loginAs( 'administrator' );

		$this->assertFalse( $this->banner( array( 'start' => time() + 60, 'end' => time() + 120 ) )->shouldShow(), 'Not before the start.' );
		$this->assertFalse( $this->banner( array( 'start' => time() - 120, 'end' => time() - 60 ) )->shouldShow(), 'Not after the end.' );
	}

	public function test_window_bounds_are_inclusive() {
		$banner = $this->banner( array( 'start' => 1000, 'end' => 2000 ) );

		$this->assertTrue( $banner->isInWindow( 1000 ), 'The start second is inside.' );
		$this->assertTrue( $banner->isInWindow( 2000 ), 'The end second is inside.' );
		$this->assertFalse( $banner->isInWindow( 999 ) );
		$this->assertFalse( $banner->isInWindow( 2001 ) );
	}

	public function test_does_not_show_to_a_user_without_the_capability() {
		$this->loginAs( 'editor' );

		$this->assertFalse( $this->banner()->shouldShow() );
	}

	public function test_does_not_show_when_the_screen_condition_is_false() {
		$this->loginAs( 'administrator' );

		$this->assertFalse( $this->banner( array( 'condition' => '__return_false' ) )->shouldShow() );
	}

	public function test_does_not_show_once_the_campaign_is_dismissed() {
		$this->loginAs( 'administrator' );
		$banner = $this->banner();
		update_option( $banner->getOptionName(), array( 'test-campaign' => time() ), false );

		$this->assertTrue( $banner->isDismissed() );
		$this->assertFalse( $banner->shouldShow() );
	}

	public function test_a_dismissal_of_another_campaign_does_not_hide_this_one() {
		$this->loginAs( 'administrator' );
		$banner = $this->banner();
		update_option( $banner->getOptionName(), array( 'older-campaign' => time() ), false );

		$this->assertFalse( $banner->isDismissed() );
		$this->assertTrue( $banner->shouldShow() );
	}

	public function test_the_no_banner_filter_switches_it_off() {
		$this->loginAs( 'administrator' );
		add_filter( 'shortpixel/settings/no_banner', '__return_true' );

		$this->assertFalse( $this->banner()->shouldShow() );
	}

	public function test_the_option_name_uses_the_prefix_and_uninstall_deletes_it() {
		$banner = $this->banner();
		$this->assertSame( self::PREFIX . '-promo-banner', $banner->getOptionName() );

		update_option( $banner->getOptionName(), array( 'test-campaign' => time() ), false );
		PromoBanner::uninstall( self::PREFIX );

		$this->assertFalse( get_option( $banner->getOptionName() ) );
	}

	public function test_markup_escapes_the_claim_url() {
		$markup = $this->banner( array( 'url' => 'javascript:alert(1)' ) )->getMarkup();

		$this->assertStringNotContainsString( 'javascript:', $markup );
	}

	public function test_markup_carries_the_ajax_action_and_a_valid_nonce() {
		$this->loginAs( 'administrator' );
		$banner = $this->banner();
		$markup = $banner->getMarkup();

		$this->assertStringContainsString( 'data-action="' . self::PREFIX . '_promo_banner_dismiss"', $markup );
		$this->assertSame( 1, preg_match( '/data-nonce="([^"]+)"/', $markup, $m ) );
		$this->assertNotFalse( wp_verify_nonce( $m[1], $banner->getAjaxAction() ), 'The nonce in the markup verifies for the dismiss action.' );
	}
}

/**
 * The dismiss endpoint through the real admin-ajax dispatch.
 */
class PromoBannerAjaxTest extends WP_Ajax_UnitTestCase {

	private const PREFIX = 'spiotest';

	/** @var PromoBanner */
	private $banner;

	public function set_up() {
		parent::set_up();
		$this->banner = new PromoBanner(
			array(
				'id'        => 'ajax-campaign',
				'start'     => time() - HOUR_IN_SECONDS,
				'end'       => time() + HOUR_IN_SECONDS,
				'prefix'    => self::PREFIX,
				'condition' => '__return_true',
			)
		);
		$this->banner->register();
	}

	public function tear_down() {
		PromoBanner::uninstall( self::PREFIX );
		parent::tear_down();
	}

	/** Dispatch the dismiss action; returns the decoded JSON answer. */
	private function dismiss( $nonce ) {
		$_POST['nonce'] = $nonce;
		try {
			$this->_handleAjax( $this->banner->getAjaxAction() );
		} catch ( WPAjaxDieContinueException $e ) {
			// wp_send_json_* ends the request this way under the test die handler.
		}
		return json_decode( $this->_last_response, true );
	}

	public function test_an_administrator_with_a_valid_nonce_dismisses_the_campaign() {
		$this->_setRole( 'administrator' );

		$answer = $this->dismiss( wp_create_nonce( $this->banner->getAjaxAction() ) );

		$this->assertTrue( $answer['success'] );
		$stored = get_option( $this->banner->getOptionName() );
		$this->assertSame( array( 'ajax-campaign' ), array_keys( $stored ), 'Only the current campaign is stored.' );
		$this->assertTrue( $this->banner->isDismissed() );
	}

	public function test_a_wrong_nonce_is_refused() {
		$this->_setRole( 'administrator' );

		$answer = $this->dismiss( 'not-a-nonce' );

		$this->assertFalse( $answer['success'] );
		$this->assertFalse( get_option( $this->banner->getOptionName() ), 'Nothing is stored.' );
	}

	public function test_a_user_without_the_capability_is_refused() {
		$this->_setRole( 'editor' );

		$answer = $this->dismiss( wp_create_nonce( $this->banner->getAjaxAction() ) );

		$this->assertFalse( $answer['success'] );
		$this->assertFalse( get_option( $this->banner->getOptionName() ), 'Nothing is stored.' );
	}
}
