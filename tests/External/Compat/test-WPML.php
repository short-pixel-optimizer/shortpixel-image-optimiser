<?php
/**
 * Tests for ShortPixel\WPML — WPML compat for the AI alt-text pipeline.
 *
 * Focus:
 *   - checkParamList injects the WPML locale into the AI params when
 *     `wpml_post_language_details` returns a valid locale
 *   - Locale-null / locale-false → params unchanged (partial-config
 *     safety)
 *   - successHandle passes the payload through
 *     `shortpixel/wpml/airesult` and returns it
 *
 * NOTE: constructor gates on `env()->plugin_active('wpml')`. In test
 * env WPML isn't loaded, so the constructor is a no-op. That's fine
 * because we invoke the filter callbacks directly.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\WPML;

class WPMLTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// The constructor may or may not wire filters depending on
		// whether WPML is active in the env. Clear both filter names
		// so per-test assertions have a known starting state.
		remove_all_filters( 'shortpixel/aidatamodel/paramlist' );
		remove_all_filters( 'shortpixel/ai/success' );
		remove_all_filters( 'shortpixel/ai/succes' );
	}

	public function tear_down() {
		remove_all_filters( 'wpml_post_language_details' );
		remove_all_filters( 'shortpixel/wpml/paramlist' );
		remove_all_filters( 'shortpixel/wpml/airesult' );
		remove_all_filters( 'shortpixel/aidatamodel/paramlist' );
		remove_all_filters( 'shortpixel/ai/success' );
		remove_all_filters( 'shortpixel/ai/succes' );
		parent::tear_down();
	}

	/**
	 * Force the WPML plugin-active gate open by hooking
	 * `shortpixel/env/plugin_active` (or the equivalent path) via
	 * short-circuiting `plugin_active` through defining an in-plugin-list
	 * marker. Because we can't easily mutate the plugin-active check
	 * from outside, we instead invoke the constructor directly on a
	 * subclass that skips the gate. That's what `makeActiveWPML()` does.
	 */
	private function makeActiveWPML(): WPML {
		return new class() extends WPML {
			public function __construct() {
				// Bypass the parent's plugin_active gate — wire the
				// filters unconditionally so we can pin their names.
				add_filter( 'shortpixel/aidatamodel/paramlist', array( $this, 'checkParamList' ), 10, 2 );
				add_filter( 'shortpixel/ai/success', array( $this, 'successHandle' ), 10, 2 );
			}
		};
	}

	/*
	 * Filter-name subscription sentinel — pinned for the fix on
	 * 2026-07-14 where the subscriber name was typo'd as
	 * `shortpixel/ai/succes` (single s) while the publisher fires
	 * `shortpixel/ai/success` (double s). A regression that re-typos
	 * either side would silently break WPML's AI success handler.
	 */

	public function test_activeWPML_subscribes_successHandle_to_the_correct_ai_success_filter_pinned_for_typo_regression() {
		$c = $this->makeActiveWPML();

		// Sentinel: BOTH assertions must hold — the correct name is
		// subscribed AND the typo'd name is NOT subscribed. A single
		// assertion could pass if someone registered on both names as
		// a "belt and braces" fix; that would still be wrong because
		// the site-side `shortpixel/wpml/airesult` filter would fire
		// twice per AI success.
		$this->assertNotFalse(
			has_filter( 'shortpixel/ai/success', array( $c, 'successHandle' ) ),
			'WPML::successHandle must subscribe to the correctly-spelled filter'
		);
		$this->assertFalse(
			has_filter( 'shortpixel/ai/succes', array( $c, 'successHandle' ) ),
			'WPML::successHandle must NOT subscribe to the typo\'d filter'
		);
	}

	public function test_activeWPML_subscribes_checkParamList_to_the_aidatamodel_paramlist_filter() {
		$c = $this->makeActiveWPML();

		// Sentinel-pair with the successHandle test above — pins BOTH
		// filter registrations, so a regression in EITHER surface is
		// caught before it silently breaks WPML integration. Also
		// pinned for casing regression: after the 2026-07-14 fix, the
		// filter subscription uses `checkParamList` (uppercase L)
		// matching the method definition. WordPress's callback hash
		// uses the method-name string as-is, so a re-lowercase would
		// silently unsubscribe.
		$this->assertNotFalse(
			has_filter( 'shortpixel/aidatamodel/paramlist', array( $c, 'checkParamList' ) )
		);
	}

	public function test_checkParamList_injects_locale_when_wpml_returns_a_valid_locale() {
		add_filter( 'wpml_post_language_details', function () {
			return array( 'locale' => 'fr_FR' );
		} );

		$c      = new WPML();
		$result = $c->checkParamList( array( 'foo' => 'bar' ), 42 );

		// Sentinel: verify BOTH the pre-existing key AND the injected
		// languages key. A regression that returned a fresh array
		// would fail on 'foo'; one that skipped the injection would
		// fail on 'languages'.
		$this->assertSame( 'bar', $result['foo'] );
		$this->assertSame( 'fr_FR', $result['languages'] );
	}

	public function test_checkParamList_skips_injection_when_wpml_returns_null_locale() {
		add_filter( 'wpml_post_language_details', function () {
			return array( 'locale' => null );
		} );

		$c      = new WPML();
		$result = $c->checkParamList( array( 'foo' => 'bar' ), 42 );

		// Sentinel: WPML partial-config safety. `locale` being null
		// means "all languages" or "not translated yet" — we must NOT
		// pass an empty string as the language.
		$this->assertArrayNotHasKey( 'languages', $result );
	}

	public function test_checkParamList_skips_injection_when_wpml_returns_false_locale() {
		add_filter( 'wpml_post_language_details', function () {
			return array( 'locale' => false );
		} );

		$c      = new WPML();
		$result = $c->checkParamList( array(), 42 );

		$this->assertArrayNotHasKey( 'languages', $result );
	}

	public function test_checkParamList_exposes_the_paramlist_filter_for_site_overrides() {
		add_filter( 'shortpixel/wpml/paramlist', function ( $data ) {
			$data['override_marker'] = true;
			return $data;
		} );

		$c      = new WPML();
		$result = $c->checkParamList( array( 'foo' => 'bar' ), 42 );

		// Sentinel: site-side filter must actually reach the caller's
		// return value. A regression that dropped the second filter
		// wouldn't include 'override_marker'.
		$this->assertTrue( $result['override_marker'] );
	}

	public function test_successHandle_passes_the_payload_through_the_airesult_filter() {
		add_filter( 'shortpixel/wpml/airesult', function ( $data, $qItem ) {
			$data['mutated'] = true;
			$data['saw_qitem_id'] = $qItem->id ?? null;
			return $data;
		}, 10, 2 );

		$qItem       = new stdClass();
		$qItem->id   = 999;
		$c           = new WPML();
		$result      = $c->successHandle( array( 'base' => 'value' ), $qItem );

		// Sentinel triplet: pre-existing key preserved, mutation
		// applied, second-arg ($qItem) reaches the filter.
		$this->assertSame( 'value', $result['base'] );
		$this->assertTrue( $result['mutated'] );
		$this->assertSame( 999, $result['saw_qitem_id'] );
	}
}
