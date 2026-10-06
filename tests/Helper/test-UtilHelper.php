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

	/*
	 * alterHtaccess — the generated next-gen delivery rules.
	 *
	 * These are string-level tests on purpose: the behaviour lives in
	 * Apache/LiteSpeed configuration, which PHPUnit cannot execute, so what
	 * can be locked down is exactly what the plugin writes into the file.
	 *
	 * The rules choose a format from the Accept header, which makes every
	 * image response they can affect vary on that header. A server that does
	 * not rename the env var the RewriteRule sets (only Apache adds the
	 * REDIRECT_ prefix) therefore used to send no Vary at all, and a cache
	 * keeping one copy per URL would hand AVIF to a client that asked for
	 * JPEG. Hence the unconditional Vary asserted below.
	 */

	/** @var array<string, string|null> Snapshot of the three .htaccess files alterHtaccess writes. */
	private $savedHtaccess = array();

	/**
	 * Absolute paths of every .htaccess file alterHtaccess touches.
	 *
	 * @return array<string, string>
	 */
	private function htaccessPaths(): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload_dir = wp_upload_dir();

		return array(
			'root'       => get_home_path() . '.htaccess',
			'uploads'    => trailingslashit( $upload_dir['basedir'] ) . '.htaccess',
			'wp_content' => trailingslashit( WP_CONTENT_DIR ) . '.htaccess',
		);
	}

	/**
	 * Snapshots the files so the suite cannot leave rewrite rules behind in
	 * the shared WP test install (null = the file did not exist).
	 */
	private function snapshotHtaccess(): void {
		if ( array() !== $this->savedHtaccess ) {
			return;
		}
		foreach ( $this->htaccessPaths() as $name => $path ) {
			$this->savedHtaccess[ $name ] = file_exists( $path ) ? file_get_contents( $path ) : null;
		}
	}

	private function restoreHtaccess(): void {
		foreach ( $this->htaccessPaths() as $name => $path ) {
			if ( ! array_key_exists( $name, $this->savedHtaccess ) ) {
				continue;
			}
			if ( null === $this->savedHtaccess[ $name ] ) {
				if ( file_exists( $path ) ) {
					@unlink( $path );
				}
			} else {
				file_put_contents( $path, $this->savedHtaccess[ $name ] );
			}
		}
		$this->savedHtaccess = array();
	}

	/**
	 * Runs alterHtaccess and returns the ShortPixelWebp block from the root
	 * .htaccess — i.e. exactly the text a customer's server would evaluate.
	 */
	private function generateRules( bool $webp = true, bool $avif = true ): string {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$this->snapshotHtaccess();

		$paths = $this->htaccessPaths();
		if ( ! is_writable( dirname( $paths['root'] ) ) ) {
			$this->markTestSkipped( 'Site root is not writable in this environment.' );
		}

		UtilHelper::alterHtaccess( $webp, $avif );

		$this->assertFileExists( $paths['root'] );
		$content = file_get_contents( $paths['root'] );

		// Sentinel: without a real block every "does not contain" assertion
		// below would pass on an empty file and prove nothing.
		$this->assertStringContainsString( '# BEGIN ShortPixelWebp', $content );
		$this->assertStringContainsString( 'RewriteEngine On', $content );

		$start = strpos( $content, '# BEGIN ShortPixelWebp' );
		$end   = strpos( $content, '# END ShortPixelWebp' );
		$this->assertNotFalse( $end, 'Block must be terminated by its end marker.' );

		return substr( $content, $start, $end - $start );
	}

	/**
	 * Splits the block at the AVIF section's closing AddType, so each half can
	 * be asserted on separately.
	 *
	 * @return array{0: string, 1: string} [avif section, webp section]
	 */
	private function splitRuleSections( string $rules ): array {
		$boundary = 'AddType image/avif';
		$pos      = strpos( $rules, $boundary );
		$this->assertNotFalse( $pos, 'AVIF section must end with its AddType directive.' );
		$cut = $pos + strlen( $boundary );

		return array( substr( $rules, 0, $cut ), substr( $rules, $cut ) );
	}

	public function tear_down() {
		$this->restoreHtaccess();
		parent::tear_down();
	}

	public function test_alterHtaccess_writes_both_rule_sets_and_negotiates_on_accept() {
		$rules = $this->generateRules();

		// Baseline contract: both formats, both on-disk layouts, mime types.
		$this->assertStringContainsString( 'RewriteCond %{HTTP_ACCEPT} image/avif', $rules );
		$this->assertStringContainsString( 'RewriteCond %{HTTP_ACCEPT} image/webp', $rules );
		$this->assertStringContainsString( 'AddType image/avif .avif', $rules );
		$this->assertStringContainsString( 'AddType image/webp .webp', $rules );
	}

	public function test_alterHtaccess_clears_the_block_but_keeps_other_directives() {
		$this->snapshotHtaccess();
		$paths    = $this->htaccessPaths();
		$sentinel = '# spio-test-unrelated-directive';

		$this->generateRules();
		file_put_contents( $paths['root'], $sentinel . PHP_EOL . file_get_contents( $paths['root'] ) );

		UtilHelper::alterHtaccess( false, false );

		$content = file_get_contents( $paths['root'] );
		$this->assertStringNotContainsString( 'RewriteCond %{HTTP_ACCEPT} image/avif', $content );
		$this->assertStringContainsString( $sentinel, $content, 'Removal must not touch directives outside the markers.' );
	}

	public function test_alterHtaccess_appends_vary_accept_once_for_every_image_response() {
		$rules = $this->generateRules();

		// Sentinel: the rules really do negotiate on Accept, so a Vary header
		// is required on these responses in the first place.
		$this->assertStringContainsString( 'RewriteCond %{HTTP_ACCEPT} image/avif', $rules );

		$varyLines = array_values( preg_grep( '/Header\s+append\s+Vary\s+Accept/i', array_map( 'trim', explode( "\n", $rules ) ) ) );

		// Exactly one append: a second one would emit "Vary: Accept, Accept".
		$this->assertCount( 1, $varyLines );
		$this->assertSame( 'Header append Vary Accept', $varyLines[0] );

		// Unconditional — no env var gating, which only Apache satisfies.
		$this->assertStringNotContainsString( 'env=REDIRECT_', $rules );
		$this->assertStringNotContainsString( 'env=avif', $rules );
		$this->assertStringNotContainsString( 'env=webp', $rules );

		// Scoped to image responses, including the format that is served
		// untouched: a shared cache must not reuse that JPEG for a client
		// which would have been given AVIF or WebP.
		$this->assertStringContainsString( '<FilesMatch "\.(jpe?g|png|gif|webp|avif)$">', $rules );
	}

	public function test_alterHtaccess_rules_pass_through_the_htaccess_rules_filter() {
		$marker = '# spio-test-filter-marker';
		$filter = static function ( $rules ) use ( $marker ) {
			return $marker . PHP_EOL . $rules;
		};
		add_filter( 'shortpixel/install/htaccess_rules', $filter );

		try {
			$rules = $this->generateRules();
			$this->assertStringContainsString( $marker, $rules );
		} finally {
			remove_filter( 'shortpixel/install/htaccess_rules', $filter );
		}
	}

	public function test_alterHtaccess_filter_receives_the_flags_and_can_drop_the_vary_header() {
		$seen   = array();
		$filter = static function ( $rules, $args ) use ( &$seen ) {
			$seen = $args;
			return str_replace( 'Header append Vary Accept', '', $rules );
		};
		add_filter( 'shortpixel/install/htaccess_rules', $filter, 10, 2 );

		try {
			$rules = $this->generateRules( true, true );

			// A site on a CDN that will not cache varying image responses can
			// strip the header without forking the whole rule block.
			$this->assertStringNotContainsString( 'Header append Vary Accept', $rules );
			$this->assertSame( array( 'webp' => true, 'avif' => true ), $seen );
		} finally {
			remove_filter( 'shortpixel/install/htaccess_rules', $filter, 10 );
		}
	}

	public function test_alterHtaccess_ignores_a_filter_that_returns_a_non_string() {
		$filter = static function () {
			return null;
		};
		add_filter( 'shortpixel/install/htaccess_rules', $filter );

		try {
			$rules = $this->generateRules();
			$this->assertStringContainsString( 'Header append Vary Accept', $rules );
			$this->assertStringContainsString( 'AddType image/avif .avif', $rules );
		} finally {
			remove_filter( 'shortpixel/install/htaccess_rules', $filter );
		}
	}

	public function test_alterHtaccess_negotiates_webp_on_the_accept_header_only() {
		$rules = $this->generateRules();
		list( , $webpSection ) = $this->splitRuleSections( $rules );

		// Nothing in the block may key on the user agent. A user-agent match
		// also hands WebP to a client that sent "Accept: image/jpeg", and
		// Vary: Accept cannot describe a user-agent dependency, so a shared
		// cache would store that response for everyone.
		$this->assertStringNotContainsString( 'HTTP_USER_AGENT', $rules );
		$this->assertStringNotContainsString( 'Google Page Speed Insights', $rules );
		$this->assertStringNotContainsString( 'Edge/17', $rules );

		// Sentinel: the WebP rules still exist and still negotiate — the
		// conditions were narrowed, not deleted. Both on-disk layouts stay.
		$this->assertSame(
			2,
			substr_count( $webpSection, 'RewriteCond %{HTTP_ACCEPT} image/webp' ),
			'Both WebP rule groups must still gate on the Accept header.'
		);
		$this->assertStringContainsString( 'RewriteRule ^(.+)$ $1.webp', $webpSection );
		$this->assertStringContainsString( 'RewriteRule (.+)\.(?:jpe?g|png|gif)$ $1.webp', $webpSection );
	}

	public function test_alterHtaccess_avif_rules_cover_explicitly_requested_webp_urls() {
		$rules = $this->generateRules();
		list( $avifSection ) = $this->splitRuleSections( $rules );

		// Contract, not a defect: .webp belongs in the AVIF match list. An
		// .avif file only exists when it came out smaller than the source, so
		// answering a .webp request with AVIF is a win, and it is how WebP
		// files uploaded straight to the media library get optimised delivery.
		// Keep this behaviour; the Vary: Accept above is what makes it safe
		// for shared caches.
		$this->assertStringContainsString( 'RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png|webp)$', $avifSection );
		$this->assertStringContainsString( 'RewriteRule (.+)\.(?:jpe?g|png|webp)$ $1.avif', $avifSection );
	}

	public function test_alterHtaccess_each_section_sets_cache_control_for_its_own_format() {
		$rules = $this->generateRules();
		list( $avifSection, $webpSection ) = $this->splitRuleSections( $rules );

		// Each section caches the format it serves, with the dot escaped so
		// the pattern cannot match an arbitrary character before the
		// extension. Both sections ship together today, which is the only
		// reason the previous crossover went unnoticed.
		$this->assertStringContainsString( '<FilesMatch "\.(avif)$">', $avifSection );
		$this->assertStringContainsString( '<FilesMatch "\.(webp)$">', $webpSection );

		// The crossover and the unescaped dots are gone.
		$this->assertStringNotContainsString( '<FilesMatch ".(webp)$">', $rules );
		$this->assertStringNotContainsString( '<FilesMatch ".(avif)$">', $rules );

		// Sentinel: Cache-Control is still set once per format.
		$this->assertSame( 2, substr_count( $rules, 'Header set Cache-Control "max-age=31536000, public"' ) );
	}

	public function test_alterHtaccess_writes_avif_rules_even_when_the_avif_flag_is_false() {
		// Documents current deliberate behaviour (see the method docblock):
		// both rule sets are always written because older plugin versions may
		// have generated either format. Consequence worth knowing: turning
		// AVIF off in the settings does not stop AVIF being served for files
		// that already exist on disk.
		$rules = $this->generateRules( true, false );

		$this->assertStringContainsString( 'RewriteCond %{HTTP_ACCEPT} image/avif', $rules );
		$this->assertStringContainsString( 'AddType image/avif .avif', $rules );
	}
}
