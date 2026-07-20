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

	public function test_validateJSON_accepts_valid_array() {
		$this->assertTrue( UtilHelper::validateJSON( '[1,2,3]' ) );
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

	/*
	 * getPostMetaTable
	 */

	public function test_getPostMetaTable_returns_prefixed_table_name() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'shortpixel_postmeta', UtilHelper::getPostMetaTable() );
	}

	/*
	 * getRelativeUploadPath
	 */

	public function test_getRelativeUploadPath_strips_uploads_basedir() {
		$uploads = wp_get_upload_dir();
		$abs     = trailingslashit( $uploads['basedir'] ) . '2024/06/image.jpg';
		$this->assertSame( '2024/06/image.jpg', UtilHelper::getRelativeUploadPath( $abs ) );
	}

	public function test_getRelativeUploadPath_leaves_unrelated_paths_untouched() {
		$path = '/some/other/absolute/path.jpg';
		$this->assertSame( $path, UtilHelper::getRelativeUploadPath( $path ) );
	}

	/*
	 * shortPixelIsPluginActive
	 */

	public function test_shortPixelIsPluginActive_reflects_active_plugins_option() {
		$previous = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array( 'fake-plugin/fake-plugin.php' ) );

		$this->assertTrue( UtilHelper::shortPixelIsPluginActive( 'fake-plugin/fake-plugin.php' ) );
		$this->assertFalse( UtilHelper::shortPixelIsPluginActive( 'not-there/not-there.php' ) );

		update_option( 'active_plugins', $previous );
	}

	/*
	 * getWordPressImageSizes
	 */

	public function test_getWordPressImageSizes_returns_default_sizes_with_dimensions() {
		$sizes = UtilHelper::getWordPressImageSizes();
		$this->assertIsArray( $sizes );
		$this->assertNotEmpty( $sizes );

		// Every intermediate size WP registers should carry width and height keys.
		foreach ( array( 'thumbnail', 'medium', 'large' ) as $name ) {
			$this->assertArrayHasKey( $name, $sizes, "Missing default size: {$name}" );
			$this->assertArrayHasKey( 'width',  $sizes[ $name ] );
			$this->assertArrayHasKey( 'height', $sizes[ $name ] );
		}
	}

	public function test_getWordPressImageSizes_is_filterable() {
		$fake = array( 'width' => 42, 'height' => 42, 'crop' => false, 'nice-name' => 'Fake' );

		$filter = function ( $sizes ) use ( $fake ) {
			$sizes['test-injected'] = $fake;
			return $sizes;
		};

		add_filter( 'shortpixel/settings/image_sizes', $filter );
		$sizes = UtilHelper::getWordPressImageSizes();
		remove_filter( 'shortpixel/settings/image_sizes', $filter );

		$this->assertArrayHasKey( 'test-injected', $sizes );
		$this->assertSame( $fake, $sizes['test-injected'] );
	}

	/*
	 * matchExclusion (protected — invoked via reflection)
	 */

	private function invokeMatchExclusion( array $pattern, array $options ): bool {
		$defaults = array( 'is_thumbnail' => false, 'is_custom' => false, 'thumbname' => null );
		$options  = array_merge( $defaults, $options );

		$ref    = new ReflectionClass( UtilHelper::class );
		$method = $ref->getMethod( 'matchExclusion' );
		$method->setAccessible( true );
		return (bool) $method->invoke( null, $pattern, $options );
	}

	public function test_matchExclusion_apply_all_always_matches() {
		$this->assertTrue( $this->invokeMatchExclusion( array( 'apply' => 'all' ), array() ) );
	}

	public function test_matchExclusion_only_thumbs_requires_thumbnail_flag() {
		$pattern = array( 'apply' => 'only-thumbs' );
		$this->assertTrue(  $this->invokeMatchExclusion( $pattern, array( 'is_thumbnail' => true ) ) );
		$this->assertFalse( $this->invokeMatchExclusion( $pattern, array( 'is_thumbnail' => false ) ) );
	}

	public function test_matchExclusion_only_custom_requires_custom_flag() {
		$pattern = array( 'apply' => 'only-custom' );
		$this->assertTrue(  $this->invokeMatchExclusion( $pattern, array( 'is_custom' => true ) ) );
		$this->assertFalse( $this->invokeMatchExclusion( $pattern, array( 'is_custom' => false ) ) );
	}

	public function test_matchExclusion_thumblist_matches_named_thumbnail() {
		$pattern = array( 'apply' => 'selected-thumbs', 'thumblist' => array( 'medium', 'large' ) );
		$this->assertTrue(  $this->invokeMatchExclusion( $pattern, array( 'thumbname' => 'medium' ) ) );
		$this->assertFalse( $this->invokeMatchExclusion( $pattern, array( 'thumbname' => 'thumbnail' ) ) );
	}

	public function test_matchExclusion_thumblist_without_thumbname_returns_false() {
		$pattern = array( 'apply' => 'selected-thumbs', 'thumblist' => array( 'medium' ) );
		$this->assertFalse( $this->invokeMatchExclusion( $pattern, array( 'thumbname' => null ) ) );
	}

	public function test_matchExclusion_unrecognised_apply_scope_returns_false() {
		$pattern = array( 'apply' => 'not-a-real-scope' );
		$this->assertFalse( $this->invokeMatchExclusion( $pattern, array() ) );
	}

	/*
	 * getExifParameter
	 *
	 * Reads and mutates \wpSPIO()->settings() directly. The setter does not
	 * persist to the DB (that only happens on ->save()), but the values live on
	 * the settings singleton for the lifetime of the request, so each test
	 * restores the previous state to keep other tests isolated.
	 */

	public function test_getExifParameter_sums_exif_and_exif_ai_settings() {
		$settings = \wpSPIO()->settings();
		$prevExif = $settings->exif;
		$prevAi   = $settings->exif_ai;

		try {
			$settings->exif    = 3;
			$settings->exif_ai = 4;
			$this->assertSame( 7, UtilHelper::getExifParameter() );

			$settings->exif    = 0;
			$settings->exif_ai = 0;
			$this->assertSame( 0, UtilHelper::getExifParameter() );
		} finally {
			$settings->exif    = $prevExif;
			$settings->exif_ai = $prevAi;
		}
	}

	/*
	 * getExclusions
	 */

	public function test_getExclusions_returns_empty_array_when_settings_value_is_not_array() {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->excludePatterns;

		try {
			$settings->excludePatterns = null;
			$this->assertSame( array(), UtilHelper::getExclusions() );
		} finally {
			$settings->excludePatterns = $prev;
		}
	}

	public function test_getExclusions_returns_all_patterns_when_filter_is_false() {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->excludePatterns;

		try {
			$settings->excludePatterns = array(
				array( 'type' => 'name', 'value' => 'skipme', 'apply' => 'all' ),
				array( 'type' => 'name', 'value' => 'otherwise' ), // no apply → defaulted to 'all'
			);

			$out = UtilHelper::getExclusions();

			$this->assertCount( 2, $out );
			$this->assertSame( 'all', $out[0]['apply'] );
			$this->assertSame( 'all', $out[1]['apply'], 'Missing apply key should be defaulted to "all".' );
		} finally {
			$settings->excludePatterns = $prev;
		}
	}

	public function test_getExclusions_with_filter_returns_only_matching_patterns() {
		$settings = \wpSPIO()->settings();
		$prev     = $settings->excludePatterns;

		try {
			$settings->excludePatterns = array(
				array( 'type' => 'name', 'value' => 'a', 'apply' => 'only-thumbs' ),
				array( 'type' => 'name', 'value' => 'b', 'apply' => 'only-custom' ),
				array( 'type' => 'name', 'value' => 'c', 'apply' => 'all' ),
			);

			$matches = UtilHelper::getExclusions( array( 'filter' => true, 'is_thumbnail' => true ) );

			$values = array_column( $matches, 'value' );
			$this->assertContains( 'a', $values, 'only-thumbs pattern should match when is_thumbnail=true.' );
			$this->assertContains( 'c', $values, 'apply=all pattern should always match.' );
			$this->assertNotContains( 'b', $values, 'only-custom pattern should not match a thumbnail.' );
		} finally {
			$settings->excludePatterns = $prev;
		}
	}
}
