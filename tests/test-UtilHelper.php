<?php
/**
 * Tests for ShortPixel\Helper\UtilHelper.
 *
 * These cover the pure, static utility methods that do not depend on
 * WordPress state or the plugin's settings singleton.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\UtilHelper;

class UtilHelperTest extends WP_UnitTestCase {

	/*
	 * timestampToDB / DBtoTimestamp
	 */

	public function test_timestampToDB_formats_unix_timestamp() {
		$ts = mktime( 12, 34, 56, 6, 15, 2024 );
		$this->assertSame( date( 'Y-m-d H:i:s', $ts ), UtilHelper::timestampToDB( $ts ) );
	}

	public function test_DBtoTimestamp_parses_mysql_datetime() {
		$ts       = mktime( 8, 0, 0, 1, 2, 2023 );
		$mysqlDate = date( 'Y-m-d H:i:s', $ts );
		$this->assertSame( $ts, UtilHelper::DBtoTimestamp( $mysqlDate ) );
	}

	public function test_timestamp_roundtrip_is_stable() {
		$ts = 1_700_000_000; // 2023-11-14 22:13:20 UTC
		$this->assertSame( $ts, UtilHelper::DBtoTimestamp( UtilHelper::timestampToDB( $ts ) ) );
	}

	/*
	 * spNormalizePath
	 */

	public function test_spNormalizePath_collapses_multiple_slashes() {
		$this->assertSame( '/var/www/html/uploads/', UtilHelper::spNormalizePath( '/var//www///html/uploads/' ) );
	}

	public function test_spNormalizePath_preserves_leading_double_slash() {
		// The regex uses a lookbehind `(?<=.)`, so a leading "//" is left alone.
		$this->assertSame( '//server/share/file', UtilHelper::spNormalizePath( '//server//share/file' ) );
	}

	public function test_spNormalizePath_no_change_when_already_normal() {
		$path = '/wp-content/uploads/2024/06/image.jpg';
		$this->assertSame( $path, UtilHelper::spNormalizePath( $path ) );
	}

	/*
	 * arrayFilterNullValues
	 */

	public function test_arrayFilterNullValues_rejects_null_only() {
		$this->assertFalse( UtilHelper::arrayFilterNullValues( null ) );
		$this->assertTrue( UtilHelper::arrayFilterNullValues( 0 ) );
		$this->assertTrue( UtilHelper::arrayFilterNullValues( '' ) );
		$this->assertTrue( UtilHelper::arrayFilterNullValues( false ) );
		$this->assertTrue( UtilHelper::arrayFilterNullValues( 'value' ) );
	}

	public function test_arrayFilterNullValues_used_with_array_filter() {
		$input   = array( 'a', null, 'b', 0, null, false );
		$filtered = array_values( array_filter( $input, array( UtilHelper::class, 'arrayFilterNullValues' ) ) );
		$this->assertSame( array( 'a', 'b', 0, false ), $filtered );
	}

	/*
	 * validateJSON
	 */

	public function test_validateJSON_accepts_valid_object() {
		$this->assertTrue( UtilHelper::validateJSON( '{"a":1,"b":"two"}' ) );
	}

	public function test_validateJSON_rejects_non_string_input() {
		$this->assertFalse( UtilHelper::validateJSON( array( 'a' => 1 ) ) );
		$this->assertFalse( UtilHelper::validateJSON( 42 ) );
		$this->assertFalse( UtilHelper::validateJSON( null ) );
	}

	public function test_validateJSON_fast_rejects_plain_string() {
		// No "{" and no ":" — short-circuits to false.
		$this->assertFalse( UtilHelper::validateJSON( 'not json at all' ) );
	}

	public function test_validateJSON_rejects_malformed_json() {
		$this->assertFalse( UtilHelper::validateJSON( '{"a":1,' ) );
	}

	/*
	 * convertExclusionFileSizeToBytes
	 */

	public function test_convertExclusionFileSizeToBytes_plain_number() {
		$this->assertSame( '500', (string) UtilHelper::convertExclusionFileSizeToBytes( '500' ) );
	}

	public function test_convertExclusionFileSizeToBytes_kilobytes() {
		$this->assertSame( (string) ( 5 * 1024 ), (string) UtilHelper::convertExclusionFileSizeToBytes( '5k' ) );
		$this->assertSame( (string) ( 5 * 1024 ), (string) UtilHelper::convertExclusionFileSizeToBytes( '5kb' ) );
		$this->assertSame( (string) ( 5 * 1024 ), (string) UtilHelper::convertExclusionFileSizeToBytes( '5KB' ) );
	}

	public function test_convertExclusionFileSizeToBytes_megabytes() {
		$this->assertSame( (string) ( 2 * 1024 * 1024 ), (string) UtilHelper::convertExclusionFileSizeToBytes( '2M' ) );
	}

	public function test_convertExclusionFileSizeToBytes_gigabytes() {
		$this->assertSame( (string) ( 1 * 1024 * 1024 * 1024 ), (string) UtilHelper::convertExclusionFileSizeToBytes( '1g' ) );
	}

	public function test_convertExclusionFileSizeToBytes_ignores_surrounding_whitespace() {
		$this->assertSame( (string) ( 3 * 1024 ), (string) UtilHelper::convertExclusionFileSizeToBytes( '  3k  ' ) );
	}
}
