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

	public function tear_down() {
		remove_all_filters( 'wpml_post_language_details' );
		remove_all_filters( 'shortpixel/wpml/paramlist' );
		remove_all_filters( 'shortpixel/wpml/airesult' );
		parent::tear_down();
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
