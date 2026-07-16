<?php
/**
 * Tests for ShortPixel\Model (abstract base class).
 *
 * Covered:
 *   - sanitizeString / sanitizeInteger / sanitizeBoolean / sanitizeArray
 *   - sanitize() dispatcher (all `s` types + missing/unknown handling)
 *   - checkMax / checkMaxLength clamps
 *   - getType, getModel, getData, getSanitizedData
 *
 * The abstract class is exercised through a small in-file subclass with a
 * representative field mix. Protected methods are exposed via thin public
 * wrappers on the subclass so we can assert behaviour without reflection
 * noise in every test.
 *
 * @package Shortpixel_Image_Optimiser
 */

/**
 * Test-only concrete subclass of the abstract Model base.
 *
 * Deliberately covers every sanitize-type the base class handles:
 *   - plain string, string with maxlength
 *   - plain int, int with max clamp
 *   - boolean, array
 *   - the "exception" pass-through and "skip" black-hole types
 *   - a field with no explicit 's' key (defaults to string)
 */
class ModelTestSubject extends \ShortPixel\Model {

	protected $model = array(
		'name'    => array( 's' => 'string' ),
		'email'   => array( 's' => 'string', 'maxlength' => 20 ),
		'age'     => array( 's' => 'int' ),
		'score'   => array( 's' => 'int', 'max' => 100 ),
		'active'  => array( 's' => 'boolean' ),
		'tags'    => array( 's' => 'array' ),
		'raw'     => array( 's' => 'exception' ),
		'ignored' => array( 's' => 'skip' ),
		'no_type' => array(),
	);

	public $name    = 'John';
	public $email   = 'john@example.com';
	public $age     = 30;
	public $score   = 50;
	public $active  = true;
	public $tags    = array( 'php', 'wp' );
	public $raw     = '<html>raw</html>';
	public $ignored = 'nope';
	public $no_type = 'default';

	public function callSanitize( $name, $value ) {
		return $this->sanitize( $name, $value );
	}
	public function callCheckMax( $name, $value ) {
		return $this->checkMax( $name, $value );
	}
	public function callCheckMaxLength( $name, $value ) {
		return $this->checkMaxLength( $name, $value );
	}
}

class ModelTest extends WP_UnitTestCase {

	private function subject(): ModelTestSubject {
		return new ModelTestSubject();
	}

	/*
	 * sanitizeString
	 */

	public function test_sanitizeString_returns_plain_string_unchanged() {
		$this->assertSame( 'hello world', $this->subject()->sanitizeString( 'hello world' ) );
	}

	public function test_sanitizeString_strips_html_tags() {
		// Use a non-block tag: wp_strip_all_tags removes <script>/<style> along
		// with their contents, but for inline tags like <b> the content is
		// kept and only the tags are removed.
		$this->assertSame( 'safe', $this->subject()->sanitizeString( '<b>safe</b>' ) );
	}

	public function test_sanitizeString_casts_non_string_scalar_to_string() {
		$this->assertSame( '42', $this->subject()->sanitizeString( 42 ) );
	}

	/*
	 * sanitizeInteger
	 */

	public function test_sanitizeInteger_parses_numeric_string() {
		$this->assertSame( 42, $this->subject()->sanitizeInteger( '42' ) );
	}

	public function test_sanitizeInteger_truncates_decimals() {
		$this->assertSame( 5, $this->subject()->sanitizeInteger( '5.9' ) );
	}

	public function test_sanitizeInteger_returns_zero_for_non_numeric() {
		$this->assertSame( 0, $this->subject()->sanitizeInteger( 'abc' ) );
		$this->assertSame( 0, $this->subject()->sanitizeInteger( null ) );
	}

	public function test_sanitizeInteger_preserves_negative_values() {
		$this->assertSame( -7, $this->subject()->sanitizeInteger( '-7' ) );
	}

	/*
	 * sanitizeBoolean
	 */

	public function test_sanitizeBoolean_true_and_false_pass_through() {
		$this->assertTrue( $this->subject()->sanitizeBoolean( true ) );
		$this->assertFalse( $this->subject()->sanitizeBoolean( false ) );
	}

	public function test_sanitizeBoolean_treats_truthy_scalars_as_true() {
		$this->assertTrue( $this->subject()->sanitizeBoolean( 1 ) );
		$this->assertTrue( $this->subject()->sanitizeBoolean( 'yes' ) );
		$this->assertTrue( $this->subject()->sanitizeBoolean( '0.5' ) );
	}

	public function test_sanitizeBoolean_treats_falsy_values_as_false() {
		$this->assertFalse( $this->subject()->sanitizeBoolean( 0 ) );
		$this->assertFalse( $this->subject()->sanitizeBoolean( '' ) );
		$this->assertFalse( $this->subject()->sanitizeBoolean( null ) );
		$this->assertFalse( $this->subject()->sanitizeBoolean( array() ) );
	}

	/*
	 * sanitizeArray
	 */

	public function test_sanitizeArray_returns_null_for_non_array_input() {
		$this->assertNull( $this->subject()->sanitizeArray( 'not-an-array' ) );
		$this->assertNull( $this->subject()->sanitizeArray( 42 ) );
		$this->assertNull( $this->subject()->sanitizeArray( null ) );
	}

	public function test_sanitizeArray_sanitizes_flat_string_values() {
		$out = $this->subject()->sanitizeArray( array( 'a' => '<b>x</b>', 'b' => 'plain' ) );
		$this->assertSame( array( 'a' => 'x', 'b' => 'plain' ), $out );
	}

	public function test_sanitizeArray_recurses_into_nested_arrays() {
		$out = $this->subject()->sanitizeArray( array( 'outer' => array( 'inner' => '<em>v</em>' ) ) );
		$this->assertSame( array( 'outer' => array( 'inner' => 'v' ) ), $out );
	}

	public function test_sanitizeArray_sanitizes_keys() {
		$out = $this->subject()->sanitizeArray( array( '<b>key</b>' => 'value' ) );
		$this->assertArrayHasKey( 'key', $out );
		$this->assertSame( 'value', $out['key'] );
	}

	public function test_sanitizeArray_returns_empty_array_for_empty_input() {
		$this->assertSame( array(), $this->subject()->sanitizeArray( array() ) );
	}

	/**
	 * Regression sentinel for a7a0f8f9 — the `is_numeric` branch used to
	 * run before `is_float`, so real PHP floats were truncated through
	 * intval() (1.5 → 1). The float check now runs first.
	 */
	public function test_sanitizeArray_preserves_float_values_without_truncation() {
		$out = $this->subject()->sanitizeArray( array( 'ratio' => 1.5, 'neg' => -0.25 ) );
		$this->assertSame( 1.5, $out['ratio'] );
		$this->assertSame( -0.25, $out['neg'] );
	}

	public function test_sanitizeArray_casts_numeric_strings_and_ints_via_intval() {
		// Numeric *strings* are not is_float(), so they still take the
		// is_numeric branch — including float-shaped strings ('1.5' → 1).
		$out = $this->subject()->sanitizeArray( array( 'int' => 42, 'intstr' => '7', 'floatstr' => '1.5' ) );
		$this->assertSame( 42, $out['int'] );
		$this->assertSame( 7, $out['intstr'] );
		$this->assertSame( 1, $out['floatstr'] );
	}

	/*
	 * sanitize (dispatcher) — invoked via public wrapper
	 */

	public function test_sanitize_returns_null_for_unknown_field() {
		$this->assertNull( $this->subject()->callSanitize( 'not_a_field', 'value' ) );
	}

	public function test_sanitize_dispatches_string_type() {
		$this->assertSame( 'clean', $this->subject()->callSanitize( 'name', '<b>clean</b>' ) );
	}

	public function test_sanitize_dispatches_int_type() {
		$this->assertSame( 12, $this->subject()->callSanitize( 'age', '12.7' ) );
	}

	public function test_sanitize_dispatches_boolean_type() {
		$this->assertTrue( $this->subject()->callSanitize( 'active', 'yes' ) );
		$this->assertFalse( $this->subject()->callSanitize( 'active', 0 ) );
	}

	public function test_sanitize_dispatches_array_type_and_returns_null_for_non_array() {
		$this->assertSame( array( 'a' => 'b' ), $this->subject()->callSanitize( 'tags', array( 'a' => 'b' ) ) );
		$this->assertNull( $this->subject()->callSanitize( 'tags', 'not-an-array' ) );
	}

	public function test_sanitize_exception_type_passes_value_through_untouched() {
		$raw = array( 'anything' => "<script>alert('x')</script>" );
		$this->assertSame( $raw, $this->subject()->callSanitize( 'raw', $raw ) );
	}

	public function test_sanitize_skip_type_always_returns_null() {
		$this->assertNull( $this->subject()->callSanitize( 'ignored', 'whatever' ) );
	}

	public function test_sanitize_defaults_missing_s_key_to_string() {
		// The 'no_type' field's model entry has no 's' — it should be treated as string.
		$this->assertSame( 'clean', $this->subject()->callSanitize( 'no_type', '<b>clean</b>' ) );
	}

	public function test_sanitize_applies_maxlength_for_string_type() {
		$long = str_repeat( 'a', 40 );
		$this->assertSame( str_repeat( 'a', 20 ), $this->subject()->callSanitize( 'email', $long ) );
	}

	public function test_sanitize_applies_max_clamp_for_int_type() {
		$this->assertSame( 100, $this->subject()->callSanitize( 'score', 500 ) );
		$this->assertSame( 42,  $this->subject()->callSanitize( 'score', 42 ) );
	}

	/*
	 * checkMax
	 */

	public function test_checkMax_returns_value_when_no_max_defined() {
		$this->assertSame( 999_999, $this->subject()->callCheckMax( 'age', 999_999 ) );
	}

	public function test_checkMax_returns_value_when_under_max() {
		$this->assertSame( 42, $this->subject()->callCheckMax( 'score', 42 ) );
	}

	public function test_checkMax_clamps_when_over_max() {
		$this->assertSame( 100, $this->subject()->callCheckMax( 'score', 101 ) );
	}

	/*
	 * checkMaxLength
	 */

	public function test_checkMaxLength_returns_value_when_no_maxlength_defined() {
		$long = str_repeat( 'x', 500 );
		$this->assertSame( $long, $this->subject()->callCheckMaxLength( 'name', $long ) );
	}

	public function test_checkMaxLength_returns_value_when_under_limit() {
		$this->assertSame( 'short', $this->subject()->callCheckMaxLength( 'email', 'short' ) );
	}

	public function test_checkMaxLength_truncates_when_over_limit() {
		$in = str_repeat( 'a', 30 );
		$this->assertSame( str_repeat( 'a', 20 ), $this->subject()->callCheckMaxLength( 'email', $in ) );
	}

	/*
	 * getType
	 */

	public function test_getType_returns_type_for_known_field() {
		$this->assertSame( 'string', $this->subject()->getType( 'name' ) );
		$this->assertSame( 'int',    $this->subject()->getType( 'age' ) );
		$this->assertSame( 'array',  $this->subject()->getType( 'tags' ) );
	}

	public function test_getType_returns_false_when_s_key_missing() {
		$this->assertFalse( $this->subject()->getType( 'no_type' ) );
	}

	public function test_getType_returns_null_for_unknown_field() {
		$this->assertNull( $this->subject()->getType( 'not_a_field' ) );
	}

	/*
	 * getModel
	 */

	public function test_getModel_returns_declared_field_names() {
		$fields = $this->subject()->getModel();
		$this->assertContains( 'name',    $fields );
		$this->assertContains( 'age',     $fields );
		$this->assertContains( 'active',  $fields );
		$this->assertContains( 'tags',    $fields );
		$this->assertContains( 'raw',     $fields );
		$this->assertContains( 'ignored', $fields );
	}

	/*
	 * getData
	 */

	public function test_getData_reads_all_model_fields_as_a_map() {
		$data = $this->subject()->getData();
		$this->assertSame( 'John',             $data['name'] );
		$this->assertSame( 30,                 $data['age'] );
		$this->assertTrue( $data['active'] );
		$this->assertSame( array( 'php', 'wp' ), $data['tags'] );
	}

	public function test_getData_strips_slashes_from_string_fields() {
		$subject       = $this->subject();
		$subject->name = "O\\'Reilly";
		$this->assertSame( "O'Reilly", $subject->getData()['name'] );
	}

	public function test_getData_does_not_strip_slashes_from_non_string_fields() {
		$subject       = $this->subject();
		// tags is an array field — stripslashes must not be applied to it.
		$subject->tags = array( "a\\'b" );
		$this->assertSame( array( "a\\'b" ), $subject->getData()['tags'] );
	}

	/**
	 * Regression sentinel for a7a0f8f9 — getData() used to read
	 * `$this->model[$item]['s']` without an isset guard, emitting an
	 * "Undefined array key 's'" warning for fields declared without a
	 * type (like the `no_type` fixture field). The temporary error
	 * handler turns any such warning back into a test failure.
	 */
	public function test_getData_emits_no_warning_for_fields_without_a_type_key() {
		$caught = array();
		set_error_handler( function ( $errno, $errstr ) use ( &$caught ) {
			$caught[] = $errstr;
			return true;
		}, E_WARNING | E_NOTICE );

		try {
			$data = $this->subject()->getData();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'default', $data['no_type'] );
		$this->assertSame( array(), $caught, 'getData() raised warnings: ' . implode( ' | ', $caught ) );
	}

	/*
	 * getSanitizedData
	 */

	public function test_getSanitizedData_sanitizes_provided_fields() {
		$out = $this->subject()->getSanitizedData(
			array(
				'name'   => '<b>Alice</b>',
				'age'    => '25.9',
				'active' => 'yes',
			),
			false // don't fill missing — keep the assertion focused.
		);

		$this->assertSame( 'Alice', $out['name'] );
		$this->assertSame( 25,      $out['age'] );
		$this->assertTrue( $out['active'] );
	}

	public function test_getSanitizedData_drops_unknown_fields() {
		$out = $this->subject()->getSanitizedData( array( 'name' => 'A', 'ghost' => 'x' ), false );
		$this->assertArrayHasKey( 'name', $out );
		$this->assertArrayNotHasKey( 'ghost', $out );
	}

	public function test_getSanitizedData_fills_missing_fields_when_missing_flag_true() {
		$out = $this->subject()->getSanitizedData( array( 'name' => 'A' ), true );

		// Booleans default to 0 …
		$this->assertSame( 0, $out['active'] );
		// … other non-skip fields default to empty string.
		$this->assertSame( '', $out['age'] );
		$this->assertSame( '', $out['tags'] );
		// 'skip' fields are excluded entirely from the filled output.
		$this->assertArrayNotHasKey( 'ignored', $out );
	}

	public function test_getSanitizedData_omits_missing_fields_when_missing_flag_false() {
		$out = $this->subject()->getSanitizedData( array( 'name' => 'A' ), false );
		$this->assertArrayHasKey( 'name', $out );
		$this->assertArrayNotHasKey( 'age',    $out );
		$this->assertArrayNotHasKey( 'active', $out );
	}

	public function test_getSanitizedData_applies_maxlength_and_max_clamps() {
		$out = $this->subject()->getSanitizedData(
			array(
				'email' => str_repeat( 'a', 40 ),
				'score' => 999,
			),
			false
		);
		$this->assertSame( str_repeat( 'a', 20 ), $out['email'] );
		$this->assertSame( 100,                   $out['score'] );
	}
}
