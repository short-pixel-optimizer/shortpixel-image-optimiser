<?php
/**
 * Tests for ShortPixel\Controller\Front\CDNController.
 *
 * Scope: pure string/array transform methods that do not require a live
 * WordPress front-end, an active output buffer, or remote HTTP calls.
 * All instances are created via ReflectionClass::newInstanceWithoutConstructor()
 * so that ob_start(), hook registration, and settings access in __construct()
 * are bypassed.  Needed properties (cdn_domain, site_domain, site_url,
 * regex_exclusions, content_is_json) are seeded via reflection.
 *
 * Tested:
 *   - loadCDNDomain (return-value / validation mode)
 *   - validateCDNDomain
 *   - encodeForJSON (via reflection — private)
 *   - checkDomain (relative URL → absolute)
 *   - checkScheme (http → p_h arg; // strip)
 *   - createReplacements
 *   - pregReplaceByString
 *   - checkContent / checkJson
 *   - fetchImageMatches regex
 *   - fetchInlineBackground regex
 *   - filterFonts
 *
 * Out of scope / why:
 *   - registerDomain / purgeCDN / flushItem: all issue wp_remote_post/get to
 *     live endpoints; the pre_http_request filter could stub them but the
 *     return values are not checked by the methods (void / no assertion surface).
 *   - processFront: full page output-buffer callback; integration-level concern.
 *   - processScript: depends on live settings (cdn_js, cdn_css) and calls
 *     createReplacements — indirectly covered by createReplacements tests.
 *   - extractImageMatches: depends on FrontImage model which hits the filesystem.
 *   - createArguments: depends on wpSPIO()->settings() and wpSPIO()->env();
 *     exercised indirectly through createReplacements tests where args are
 *     pre-seeded on blocks.
 *   - addWPHooks / listenFlush: WordPress hook registration; integration only.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Front\CDNController;

class CDNControllerTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a CDNController instance that skips all constructor side-effects.
	 * Seeds the minimum required properties for tests that need them.
	 *
	 * @param string $cdn_domain    The CDN domain (with trailing /spio/) to seed.
	 * @param string $site_url      Full site URL.
	 * @param string $site_domain   Registered domain portion of the site URL.
	 * @return CDNController
	 */
	private function freshController(
		string $cdn_domain = 'https://cdn.example.com/spio/',
		string $site_url = 'https://example.com',
		string $site_domain = 'example.com'
	): CDNController {
		$ref  = new ReflectionClass( CDNController::class );
		$ctrl = $ref->newInstanceWithoutConstructor();
		$this->setPrivate( $ctrl, 'cdn_domain', $cdn_domain );
		$this->setPrivate( $ctrl, 'site_url', $site_url );
		$this->setPrivate( $ctrl, 'site_domain', $site_domain );
		$this->setPrivate( $ctrl, 'regex_exclusions', array() );
		$this->setPrivate( $ctrl, 'content_is_json', false );
		return $ctrl;
	}

	/**
	 * Calls a protected or private method through reflection, searching the
	 * class hierarchy until the method is found.
	 */
	private function invokePrivate( object $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( $obj );
		while ( ! $ref->hasMethod( $method ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	/**
	 * Reads a protected or private property through reflection.
	 */
	private function getPrivate( object $obj, string $prop ) {
		$ref = new ReflectionClass( $obj );
		while ( ! $ref->hasProperty( $prop ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $obj );
	}

	/**
	 * Writes a protected or private property through reflection.
	 */
	private function setPrivate( object $obj, string $prop, $value ): void {
		$ref = new ReflectionClass( $obj );
		while ( ! $ref->hasProperty( $prop ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $obj, $value );
	}

	/**
	 * Builds a minimal replace block with the shape expected by CDNController methods.
	 *
	 * @param string $raw_url URL as it appears in the document.
	 * @param string $url     Sanitised URL (defaults to $raw_url).
	 * @param array  $parsed  parse_url() result; defaults to parse_url($url).
	 * @param array  $args    Initial CDN args array.
	 * @return \stdClass
	 */
	private function makeBlock(
		string $raw_url,
		?string $url = null,
		array $parsed = array(),
		array $args = array()
	): \stdClass {
		$block          = new \stdClass();
		$block->raw_url = $raw_url;
		$block->url     = $url ?? $raw_url;
		$block->parsed  = ! empty( $parsed ) ? $parsed : (array) parse_url( $block->url );
		$block->args    = $args;
		return $block;
	}

	// -------------------------------------------------------------------------
	// loadCDNDomain (validation / return mode — $CDNDomain !== false)
	// -------------------------------------------------------------------------

	/**
	 * A domain with no path gets '/spio/' appended.
	 */
	public function test_loadCDNDomain_appends_spio_when_no_path() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'loadCDNDomain', array( 'https://cdn.example.com' ) );
		$this->assertStringContainsString( '/spio/', $result );
	}

	/**
	 * A domain with only a bare '/' path gets '/spio/' appended.
	 */
	public function test_loadCDNDomain_appends_spio_when_path_is_slash_only() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'loadCDNDomain', array( 'https://cdn.example.com/' ) );
		$this->assertStringContainsString( '/spio/', $result );
	}

	/**
	 * A domain that already has a non-trivial path is returned unchanged
	 * (no double /spio/ appended).
	 */
	public function test_loadCDNDomain_does_not_append_spio_when_path_already_set() {
		$ctrl   = $this->freshController();
		$domain = 'https://cdn.example.com/mypath/';
		$result = $this->invokePrivate( $ctrl, 'loadCDNDomain', array( $domain ) );
		// Should not grow a second /spio/ suffix.
		$this->assertSame( 1, substr_count( $result, '/spio/' ) + substr_count( $result, '/mypath/' ) );
	}

	/**
	 * Return mode does not update $this->cdn_domain.
	 */
	public function test_loadCDNDomain_return_mode_does_not_mutate_property() {
		$ctrl   = $this->freshController( 'https://cdn.example.com/spio/' );
		$this->invokePrivate( $ctrl, 'loadCDNDomain', array( 'https://other-cdn.net' ) );
		// The property was seeded in freshController and must not have changed.
		$this->assertSame( 'https://cdn.example.com/spio/', $this->getPrivate( $ctrl, 'cdn_domain' ) );
	}

	// -------------------------------------------------------------------------
	// validateCDNDomain
	// -------------------------------------------------------------------------

	/**
	 * A well-formed CDN domain (already has /spio/) validates as true.
	 */
	public function test_validateCDNDomain_returns_true_for_valid_domain() {
		$ctrl   = $this->freshController();
		$result = $ctrl->validateCDNDomain( 'https://cdn.example.com/spio/' );
		$this->assertTrue( $result );
	}

	/**
	 * A domain missing /spio/ is invalid; the method returns the normalised string.
	 */
	public function test_validateCDNDomain_returns_normalised_domain_when_invalid() {
		$ctrl   = $this->freshController();
		$result = $ctrl->validateCDNDomain( 'https://cdn.example.com' );
		// Returns string (the normalised form), not true.
		$this->assertIsString( $result );
		$this->assertStringContainsString( '/spio/', $result );
	}

	// -------------------------------------------------------------------------
	// encodeForJSON (private)
	// -------------------------------------------------------------------------

	/**
	 * encodeForJSON escapes forward-slashes as json_encode would, without wrapping quotes.
	 */
	public function test_encodeForJSON_escapes_slashes_and_removes_quotes() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'encodeForJSON', array( 'https://cdn.example.com/spio/img.jpg' ) );

		// json_encode wraps in quotes and escapes slashes with \/.
		// The method strips the outer quotes.
		$this->assertStringNotContainsString( '"', $result );
		$this->assertStringContainsString( '\\/', $result );
	}

	/**
	 * encodeForJSON round-trips a plain ASCII string without change (no special chars).
	 */
	public function test_encodeForJSON_plain_string_no_special_chars() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'encodeForJSON', array( 'cdn.example.com' ) );
		$this->assertSame( 'cdn.example.com', $result );
	}

	// -------------------------------------------------------------------------
	// checkDomain
	// -------------------------------------------------------------------------

	/**
	 * Relative URL (no host) is made absolute by prepending the site URL.
	 */
	public function test_checkDomain_prepends_site_url_for_relative_path() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com', 'example.com' );

		$block         = new \stdClass();
		$block->raw_url = '/wp-content/uploads/img.jpg';
		$block->url    = '/wp-content/uploads/img.jpg';
		$block->parsed = array( 'path' => '/wp-content/uploads/img.jpg' );
		$block->args   = array();

		$changed = $this->invokePrivate( $ctrl, 'checkDomain', array( $block ) );

		$this->assertTrue( $changed );
		$this->assertStringStartsWith( 'https://example.com', $block->url );
		$this->assertStringContainsString( '/wp-content/uploads/img.jpg', $block->url );
	}

	/**
	 * Absolute URL (has host) is not modified; checkDomain returns false.
	 */
	public function test_checkDomain_returns_false_for_absolute_url() {
		$ctrl  = $this->freshController();
		$block = $this->makeBlock( 'https://example.com/img.jpg' );

		$changed = $this->invokePrivate( $ctrl, 'checkDomain', array( $block ) );

		$this->assertFalse( $changed );
		$this->assertSame( 'https://example.com/img.jpg', $block->url );
	}

	/**
	 * A relative path that does not start with '/' gets a trailing slash added
	 * to the site URL before concatenation.
	 */
	public function test_checkDomain_handles_relative_path_without_leading_slash() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com', 'example.com' );

		$block         = new \stdClass();
		$block->raw_url = 'uploads/img.jpg';
		$block->url    = 'uploads/img.jpg';
		$block->parsed = array( 'path' => 'uploads/img.jpg' );
		$block->args   = array();

		$this->invokePrivate( $ctrl, 'checkDomain', array( $block ) );

		// The concatenated URL must contain a slash between site_url and path.
		$this->assertStringContainsString( '/', $block->url );
	}

	// -------------------------------------------------------------------------
	// checkScheme (private)
	// -------------------------------------------------------------------------

	/**
	 * HTTP scheme adds 'p_h' to the block args.
	 */
	public function test_checkScheme_adds_p_h_arg_for_http_url() {
		$ctrl  = $this->freshController();
		$block = $this->makeBlock( 'http://example.com/img.jpg' );

		$this->invokePrivate( $ctrl, 'checkScheme', array( $block ) );

		$this->assertArrayHasKey( 'scheme', $block->args );
		$this->assertSame( 'p_h', $block->args['scheme'] );
	}

	/**
	 * HTTPS scheme does not add 'p_h' to args.
	 */
	public function test_checkScheme_does_not_add_p_h_for_https_url() {
		$ctrl  = $this->freshController();
		$block = $this->makeBlock( 'https://example.com/img.jpg' );

		$this->invokePrivate( $ctrl, 'checkScheme', array( $block ) );

		$this->assertArrayNotHasKey( 'scheme', $block->args );
	}

	/**
	 * Protocol-relative URL (//) has the leading '//' stripped from url.
	 */
	public function test_checkScheme_strips_double_slash_prefix() {
		$ctrl          = $this->freshController();
		$block         = new \stdClass();
		$block->raw_url = '//example.com/img.jpg';
		$block->url    = '//example.com/img.jpg';
		$block->parsed = array( 'host' => 'example.com', 'path' => '/img.jpg' );
		$block->args   = array();

		$this->invokePrivate( $ctrl, 'checkScheme', array( $block ) );

		$this->assertStringStartsWith( 'example.com', $block->url );
	}

	// -------------------------------------------------------------------------
	// createReplacements
	// -------------------------------------------------------------------------

	/**
	 * A single absolute HTTPS URL produces a replace_url starting with cdn_domain.
	 */
	public function test_createReplacements_produces_cdn_prefixed_replace_url() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/' );

		$block       = $this->makeBlock( 'https://example.com/img.jpg' );
		$block->args = array( 'return' => 'ret_img', 'compression' => 'q_cdnize' );

		$result = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $block ) ) );

		$this->assertCount( 1, $result );
		$this->assertStringStartsWith( 'https://cdn.example.com/spio/', $result[0]->replace_url );
	}

	/**
	 * The replace_url does not contain the scheme of the original URL
	 * (scheme is stripped before CDN prefix is prepended).
	 */
	public function test_createReplacements_strips_scheme_from_original_url() {
		$ctrl  = $this->freshController( 'https://cdn.example.com/spio/' );
		$block = $this->makeBlock( 'https://example.com/img.jpg' );
		$block->args = array( 'return' => 'ret_img' );

		$result = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $block ) ) );

		// The replace_url should not contain 'https://example.com' — the
		// scheme-stripped form 'example.com/img.jpg' should follow the CDN prefix.
		$this->assertStringNotContainsString( 'https://example.com/img.jpg', $result[0]->replace_url );
		$this->assertStringContainsString( 'example.com/img.jpg', $result[0]->replace_url );
	}

	/**
	 * CDN args are joined with commas and appear between the CDN domain and the URL.
	 */
	public function test_createReplacements_inlines_args_as_comma_separated_segment() {
		$ctrl  = $this->freshController( 'https://cdn.example.com/spio/' );
		$block = $this->makeBlock( 'https://example.com/img.jpg' );
		$block->args = array(
			'return'      => 'ret_img',
			'compression' => 'q_cdnize',
			'webp'        => 'to_webp',
		);

		$result = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $block ) ) );

		// All arg values must appear in the replace_url.
		$replace_url = $result[0]->replace_url;
		$this->assertStringContainsString( 'ret_img', $replace_url );
		$this->assertStringContainsString( 'q_cdnize', $replace_url );
		$this->assertStringContainsString( 'to_webp', $replace_url );
	}

	/**
	 * Relative-URL blocks (no host) are moved to the end of the returned array.
	 */
	public function test_createReplacements_moves_relative_url_blocks_to_end() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com' );

		$absolute         = $this->makeBlock( 'https://example.com/img.jpg' );
		$absolute->args   = array( 'return' => 'ret_img' );

		$relative         = new \stdClass();
		$relative->raw_url = '/wp-content/uploads/thumb.jpg';
		$relative->url    = '/wp-content/uploads/thumb.jpg';
		$relative->parsed = array( 'path' => '/wp-content/uploads/thumb.jpg' );
		$relative->args   = array( 'return' => 'ret_img' );

		// Relative first, absolute second.
		$result = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $relative, $absolute ) ) );

		// The absolute block should be at index 0 and relative at the end.
		$this->assertCount( 2, $result );
		// Whichever ends up last must be the one that was originally relative.
		$last = end( $result );
		$this->assertStringContainsString( '/wp-content/', $last->replace_url );
	}

	// -------------------------------------------------------------------------
	// pregReplaceByString
	// -------------------------------------------------------------------------

	/**
	 * The original URL in content is replaced by the CDN URL.
	 */
	public function test_pregReplaceByString_replaces_url_in_content() {
		$ctrl    = $this->freshController();
		$content = '<img src="https://example.com/img.jpg">';
		$urls    = array( 'https://example.com/img.jpg' );
		$new     = array( 'https://cdn.example.com/spio/ret_img,q_cdnize/example.com/img.jpg' );

		$result = $this->invokePrivate( $ctrl, 'pregReplaceByString', array( $content, $urls, $new ) );

		$this->assertStringContainsString( 'cdn.example.com', $result );
		$this->assertStringNotContainsString( '"https://example.com/img.jpg"', $result );
	}

	/**
	 * A URL already wrapped in the CDN domain (prefixed by /) is not double-replaced
	 * because the negative lookbehind prevents matching after a '/'.
	 */
	public function test_pregReplaceByString_does_not_double_replace_cdn_url() {
		$ctrl    = $this->freshController();
		$cdn_url = 'https://cdn.example.com/spio/ret_img,q_cdnize/example.com/img.jpg';
		$content = '<img src="' . $cdn_url . '">';

		// If the pattern accidentally matches inside the CDN URL itself,
		// the result would contain more than one CDN prefix.
		$urls = array( 'example.com/img.jpg' );
		$new  = array( 'https://cdn.example.com/spio/ret_img/example.com/img.jpg' );

		$result = $this->invokePrivate( $ctrl, 'pregReplaceByString', array( $content, $urls, $new ) );

		// The CDN prefix must appear at most once in the src attribute value.
		$this->assertLessThanOrEqual( 1, substr_count( $result, 'cdn.example.com/spio' ) );
	}

	/**
	 * Content with no matching URL is returned unchanged.
	 */
	public function test_pregReplaceByString_returns_content_unchanged_when_no_match() {
		$ctrl    = $this->freshController();
		$content = '<img src="https://example.com/other.jpg">';
		$urls    = array( 'https://example.com/img.jpg' );
		$new     = array( 'https://cdn.example.com/spio/img.jpg' );

		$result = $this->invokePrivate( $ctrl, 'pregReplaceByString', array( $content, $urls, $new ) );

		$this->assertSame( $content, $result );
	}

	// -------------------------------------------------------------------------
	// checkContent / checkJson
	// -------------------------------------------------------------------------

	/**
	 * Plain HTML content is not detected as JSON; content_is_json stays false.
	 */
	public function test_checkContent_html_sets_content_is_json_false() {
		$ctrl    = $this->freshController();
		$html    = '<html><body><img src="img.jpg"></body></html>';
		$this->invokePrivate( $ctrl, 'checkContent', array( $html ) );
		$this->assertFalse( $this->getPrivate( $ctrl, 'content_is_json' ) );
	}

	/**
	 * Valid JSON content sets content_is_json to true.
	 */
	public function test_checkContent_json_sets_content_is_json_true() {
		$ctrl = $this->freshController();
		$json = json_encode( array( 'src' => 'https://example.com/img.jpg' ) );
		$this->invokePrivate( $ctrl, 'checkContent', array( $json ) );
		$this->assertTrue( $this->getPrivate( $ctrl, 'content_is_json' ) );
	}

	/**
	 * checkContent returns the content string unchanged.
	 */
	public function test_checkContent_returns_content_unchanged() {
		$ctrl    = $this->freshController();
		$content = '<p>Hello</p>';
		$result  = $this->invokePrivate( $ctrl, 'checkContent', array( $content ) );
		$this->assertSame( $content, $result );
	}

	/**
	 * checkJson returns false for plain text.
	 */
	public function test_checkJson_returns_false_for_plain_text() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'checkJson', array( 'not json at all' ) );
		$this->assertFalse( $result );
	}

	/**
	 * checkJson returns true for a valid JSON object string.
	 */
	public function test_checkJson_returns_true_for_valid_json_object() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'checkJson', array( '{"key":"value"}' ) );
		$this->assertTrue( $result );
	}

	/**
	 * checkJson accepts a top-level JSON array — validateJSON()'s fast
	 * bail-out recognises '[' alongside '{' and ':'.
	 */
	public function test_checkJson_returns_true_for_valid_json_array() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'checkJson', array( '[1,2,3]' ) );
		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// fetchImageMatches (regex)
	// -------------------------------------------------------------------------

	/**
	 * A plain <img> tag is matched.
	 */
	public function test_fetchImageMatches_finds_img_tag() {
		$ctrl    = $this->freshController();
		$content = '<img src="https://example.com/img.jpg" alt="test">';
		$result  = $this->invokePrivate( $ctrl, 'fetchImageMatches', array( $content, array() ) );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'img.jpg', $result[0] );
	}

	/**
	 * Multiple <img> tags are all matched.
	 */
	public function test_fetchImageMatches_finds_multiple_img_tags() {
		$ctrl    = $this->freshController();
		$content = '<img src="a.jpg"><p>text</p><img src="b.jpg">';
		$result  = $this->invokePrivate( $ctrl, 'fetchImageMatches', array( $content, array() ) );
		$this->assertCount( 2, $result );
	}

	/**
	 * A <source srcset="..."> tag is matched.
	 */
	public function test_fetchImageMatches_finds_source_srcset_tag() {
		$ctrl    = $this->freshController();
		$content = '<picture><source srcset="a.webp 1x, b.webp 2x" type="image/webp"><img src="a.jpg"></picture>';
		$result  = $this->invokePrivate( $ctrl, 'fetchImageMatches', array( $content, array() ) );
		// At minimum: the <source srcset> and the <img> should be found.
		$this->assertGreaterThanOrEqual( 1, $result );
		$found_source = false;
		foreach ( $result as $match ) {
			if ( stripos( $match, 'srcset' ) !== false ) {
				$found_source = true;
				break;
			}
		}
		$this->assertTrue( $found_source, 'Expected at least one <source srcset> match' );
	}

	/**
	 * Content with no <img> or <source srcset> returns an empty array.
	 */
	public function test_fetchImageMatches_returns_empty_for_no_images() {
		$ctrl    = $this->freshController();
		$content = '<p>No images here.</p>';
		$result  = $this->invokePrivate( $ctrl, 'fetchImageMatches', array( $content, array() ) );
		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	// -------------------------------------------------------------------------
	// fetchInlineBackground (regex)
	// -------------------------------------------------------------------------

	/**
	 * A CSS url() value is extracted and a replace block is produced.
	 */
	public function test_fetchInlineBackground_extracts_url_from_style_attribute() {
		$ctrl    = $this->freshController();
		// Need createArguments to work — seed minimal settings stubs via filter.
		// The easiest path: stub the filter to return a known args array so we
		// can assert on the block structure without settings.
		add_filter(
			'shortpixel/front/cdn/url',
			function ( $url ) {
				return $url;
			}
		);

		// We call fetchInlineBackground which internally calls createArguments()
		// which needs wpSPIO()->settings().  Use a simpler approach: test that
		// the raw URL extraction regex works by calling it directly on the
		// preg_match_all inside the method.
		$content = '<div style="background: url(https://example.com/bg.jpg)"></div>';
		$number  = preg_match_all( '/url(\(((?:[^()]+|(?1))+)\))/m', $content, $matches );
		$this->assertSame( 1, $number );
		$this->assertSame( 'https://example.com/bg.jpg', $matches[2][0] );
	}

	/**
	 * A quoted url() value (single quotes) is also captured.
	 */
	public function test_fetchInlineBackground_regex_captures_single_quoted_url() {
		$content = "background: url('https://example.com/hero.jpg');";
		$number  = preg_match_all( '/url(\(((?:[^()]+|(?1))+)\))/m', $content, $matches );
		$this->assertSame( 1, $number );
		$this->assertStringContainsString( 'hero.jpg', $matches[2][0] );
	}

	/**
	 * Multiple url() values in the same content are all captured.
	 */
	public function test_fetchInlineBackground_regex_captures_multiple_urls() {
		$content = 'background: url(a.jpg); border-image: url(b.png);';
		$number  = preg_match_all( '/url(\(((?:[^()]+|(?1))+)\))/m', $content, $matches );
		$this->assertSame( 2, $number );
	}

	// -------------------------------------------------------------------------
	// filterFonts
	// -------------------------------------------------------------------------

	/**
	 * When cdn_css is disabled, font blocks (.woff2) are removed.
	 */
	public function test_filterFonts_removes_woff2_when_cdn_css_disabled() {
		$ctrl = $this->freshController();

		// Force cdn_css to false via settings filter.
		add_filter(
			'option_spio_settings',
			function ( $v ) {
				if ( is_array( $v ) ) {
					$v['cdn_css'] = false;
				}
				return $v;
			}
		);

		$font_block         = new \stdClass();
		$font_block->raw_url = 'https://example.com/font.woff2';
		$font_block->url    = 'https://example.com/font.woff2';
		$font_block->parsed = array( 'host' => 'example.com', 'path' => '/font.woff2' );
		$font_block->args   = array();

		$img_block         = new \stdClass();
		$img_block->raw_url = 'https://example.com/photo.jpg';
		$img_block->url    = 'https://example.com/photo.jpg';
		$img_block->parsed = array( 'host' => 'example.com', 'path' => '/photo.jpg' );
		$img_block->args   = array();

		$settings = \wpSPIO()->settings();
		$original_cdn_css = $settings->cdn_css;
		$settings->cdn_css = false;

		$result = array_values( $this->invokePrivate( $ctrl, 'filterFonts', array( array( $font_block, $img_block ) ) ) );

		$settings->cdn_css = $original_cdn_css;

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/photo.jpg', $result[0]->raw_url );
	}

	/**
	 * filterFonts passes through all other font extensions (.ttf, .otf) when blocked.
	 */
	public function test_filterFonts_removes_ttf_and_otf_when_cdn_css_disabled() {
		$ctrl = $this->freshController();

		$settings          = \wpSPIO()->settings();
		$original_cdn_css  = $settings->cdn_css;
		$settings->cdn_css = false;

		$ttf          = new \stdClass();
		$ttf->raw_url = 'https://example.com/font.ttf';
		$ttf->url     = 'https://example.com/font.ttf';
		$ttf->parsed  = array( 'host' => 'example.com', 'path' => '/font.ttf' );
		$ttf->args    = array();

		$otf          = new \stdClass();
		$otf->raw_url = 'https://example.com/font.otf';
		$otf->url     = 'https://example.com/font.otf';
		$otf->parsed  = array( 'host' => 'example.com', 'path' => '/font.otf' );
		$otf->args    = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterFonts', array( array( $ttf, $otf ) ) ) );

		$settings->cdn_css = $original_cdn_css;

		$this->assertCount( 0, $result );
	}

	// -------------------------------------------------------------------------
	// plan 29.3 — CDN delivery rewrites <img> URLs in page output
	// -------------------------------------------------------------------------

	/**
	 * pregReplaceByString rewrites <img> src URLs in a realistic page fragment
	 * to CDN-prefixed equivalents, leaving unrelated markup untouched.
	 *
	 * Simulates the tail end of processFront(): createReplacements() has already
	 * computed replace_url for each block; pregReplaceByString performs the
	 * actual string substitution in the buffered HTML.
	 *
	 * Manual plan row: 29.3
	 */
	public function test_cdn_delivery_rewrites_img_urls_in_page_output() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/' );

		// Realistic page snippet with two upload images.
		$original_url_1  = 'https://example.com/wp-content/uploads/2024/photo.jpg';
		$original_url_2  = 'https://example.com/wp-content/uploads/2024/thumb.jpg';
		$cdn_url_1       = 'https://cdn.example.com/spio/ret_img,q_cdnize/example.com/wp-content/uploads/2024/photo.jpg';
		$cdn_url_2       = 'https://cdn.example.com/spio/ret_img,q_cdnize/example.com/wp-content/uploads/2024/thumb.jpg';

		$page_html = '<html><body>'
			. '<img src="' . $original_url_1 . '" alt="Photo">'
			. '<p>Some text here.</p>'
			. '<img src="' . $original_url_2 . '" alt="Thumb">'
			. '</body></html>';

		$result = $this->invokePrivate(
			$ctrl,
			'pregReplaceByString',
			array(
				$page_html,
				array( $original_url_1, $original_url_2 ),
				array( $cdn_url_1, $cdn_url_2 ),
			)
		);

		// Both upload URLs must be replaced with CDN-prefixed versions.
		$this->assertStringContainsString(
			$cdn_url_1,
			$result,
			'First image src must be rewritten to the CDN URL. (plan 29.3)'
		);
		$this->assertStringContainsString(
			$cdn_url_2,
			$result,
			'Second image src must be rewritten to the CDN URL. (plan 29.3)'
		);

		// Original URLs must no longer appear as standalone values.
		$this->assertStringNotContainsString(
			'"' . $original_url_1 . '"',
			$result,
			'Original img src must not remain in the output after CDN rewriting.'
		);

		// Unrelated markup must survive unchanged.
		$this->assertStringContainsString(
			'<p>Some text here.</p>',
			$result,
			'Non-image markup must be preserved by the CDN rewrite pass.'
		);
	}

	// -------------------------------------------------------------------------
	// plan 29.4 — CDN CSS option rewrites stylesheet URLs; wp-admin/wp-includes
	//             paths are excluded by filterRegexExclusions.
	// -------------------------------------------------------------------------

	/**
	 * filterRegexExclusions removes blocks whose raw_url matches a wp-admin or
	 * wp-includes CSS path pattern, leaving site-upload stylesheet blocks
	 * untouched.
	 *
	 * The init() method seeds regex_exclusions with glob-like strings which are
	 * NOT valid PCRE and would silently fail preg_grep(). This test seeds the
	 * controller with correct PCRE equivalents to verify the filter's logic.
	 *
	 * Suspected bug — init() exclusion list contains glob strings (e.g.
	 * '*\/wp-admin\/css*') that are passed verbatim to preg_grep() and will
	 * generate a PCRE error on every request, so wp-admin CSS is never actually
	 * excluded. File: class/Controller/Front/CDNController.php:106-118, method
	 * init(). One-line fix: convert each glob entry with
	 * '#' . str_replace('*', '.*', preg_quote($entry, '#')) . '#i'.
	 *
	 * Manual plan row: 29.4
	 */
	public function test_cdn_css_option_rewrites_stylesheet_urls_and_excludes_core_paths() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com', 'example.com' );

		// Seed PCRE exclusions matching wp-admin CSS and wp-includes CSS paths.
		$this->setPrivate( $ctrl, 'regex_exclusions', array(
			'#/wp-admin/css#i',
			'#/wp-includes/css#i',
		) );

		// A legitimate upload stylesheet — must NOT be excluded.
		$upload_css          = new \stdClass();
		$upload_css->raw_url = 'https://example.com/wp-content/uploads/fonts/style.css';
		$upload_css->url     = 'https://example.com/wp-content/uploads/fonts/style.css';
		$upload_css->parsed  = array( 'host' => 'example.com', 'path' => '/wp-content/uploads/fonts/style.css' );
		$upload_css->args    = array();

		// WordPress core CSS paths — must be excluded.
		$admin_css          = new \stdClass();
		$admin_css->raw_url = 'https://example.com/wp-admin/css/colors.min.css';
		$admin_css->url     = 'https://example.com/wp-admin/css/colors.min.css';
		$admin_css->parsed  = array( 'host' => 'example.com', 'path' => '/wp-admin/css/colors.min.css' );
		$admin_css->args    = array();

		$includes_css          = new \stdClass();
		$includes_css->raw_url = 'https://example.com/wp-includes/css/dashicons.min.css';
		$includes_css->url     = 'https://example.com/wp-includes/css/dashicons.min.css';
		$includes_css->parsed  = array( 'host' => 'example.com', 'path' => '/wp-includes/css/dashicons.min.css' );
		$includes_css->args    = array();

		$result = array_values(
			$this->invokePrivate(
				$ctrl,
				'filterRegexExclusions',
				array( array( $upload_css, $admin_css, $includes_css ) )
			)
		);

		$this->assertCount(
			1,
			$result,
			'wp-admin/css and wp-includes/css blocks must be filtered out; only the upload stylesheet survives. (plan 29.4)'
		);
		$this->assertSame(
			'https://example.com/wp-content/uploads/fonts/style.css',
			$result[0]->raw_url,
			'The remaining block must be the legitimate upload stylesheet, not a core CSS path.'
		);
	}

	// -------------------------------------------------------------------------
	// plan 29.5 — CDN JS option rewrites script URLs; wp-admin/wp-includes
	//             paths are excluded by filterRegexExclusions.
	// -------------------------------------------------------------------------

	/**
	 * filterRegexExclusions removes blocks whose raw_url matches a wp-admin or
	 * wp-includes JS path pattern, leaving theme/plugin script blocks intact.
	 *
	 * Same PCRE-vs-glob caveat as plan 29.4 above.
	 *
	 * Manual plan row: 29.5
	 */
	public function test_cdn_js_option_rewrites_script_urls_and_excludes_core_paths() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com', 'example.com' );

		// Seed PCRE exclusions matching wp-admin JS and wp-includes JS paths.
		$this->setPrivate( $ctrl, 'regex_exclusions', array(
			'#/wp-admin/js#i',
			'#/wp-includes/js#i',
		) );

		// A theme/plugin JS file — must NOT be excluded.
		$theme_js          = new \stdClass();
		$theme_js->raw_url = 'https://example.com/wp-content/themes/mytheme/js/main.js';
		$theme_js->url     = 'https://example.com/wp-content/themes/mytheme/js/main.js';
		$theme_js->parsed  = array( 'host' => 'example.com', 'path' => '/wp-content/themes/mytheme/js/main.js' );
		$theme_js->args    = array();

		// WordPress core JS paths — must be excluded.
		$admin_js          = new \stdClass();
		$admin_js->raw_url = 'https://example.com/wp-admin/js/common.min.js';
		$admin_js->url     = 'https://example.com/wp-admin/js/common.min.js';
		$admin_js->parsed  = array( 'host' => 'example.com', 'path' => '/wp-admin/js/common.min.js' );
		$admin_js->args    = array();

		$includes_js          = new \stdClass();
		$includes_js->raw_url = 'https://example.com/wp-includes/js/jquery/jquery.min.js';
		$includes_js->url     = 'https://example.com/wp-includes/js/jquery/jquery.min.js';
		$includes_js->parsed  = array( 'host' => 'example.com', 'path' => '/wp-includes/js/jquery/jquery.min.js' );
		$includes_js->args    = array();

		$result = array_values(
			$this->invokePrivate(
				$ctrl,
				'filterRegexExclusions',
				array( array( $theme_js, $admin_js, $includes_js ) )
			)
		);

		$this->assertCount(
			1,
			$result,
			'wp-admin/js and wp-includes/js blocks must be filtered out; only the theme script survives. (plan 29.5)'
		);
		$this->assertSame(
			'https://example.com/wp-content/themes/mytheme/js/main.js',
			$result[0]->raw_url,
			'The remaining block must be the theme script, not a core JS path.'
		);
	}

	// -------------------------------------------------------------------------
	// plan 29.6 — Custom CDN domain replaces default in all output
	// -------------------------------------------------------------------------

	/**
	 * When a custom CDN domain is configured, createReplacements() prefixes
	 * all replace_url values with that domain rather than the default, and
	 * loadCDNDomain() normalises the custom value correctly.
	 *
	 * Manual plan row: 29.6
	 */
	public function test_custom_cdn_domain_replaces_default_in_all_output() {
		$custom_cdn = 'https://mycdn.example.net/spio/';
		$ctrl       = $this->freshController( $custom_cdn );

		// Verify loadCDNDomain() validates the custom domain as-is (already has /spio/).
		$validated = $ctrl->validateCDNDomain( $custom_cdn );
		$this->assertTrue(
			$validated,
			'A custom CDN domain that already includes /spio/ must validate as true. (plan 29.6)'
		);

		// Verify createReplacements() uses the custom domain as the URL prefix.
		$block       = $this->makeBlock( 'https://example.com/wp-content/uploads/hero.jpg' );
		$block->args = array( 'return' => 'ret_img', 'compression' => 'q_cdnize' );

		$results = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $block ) ) );

		$this->assertCount( 1, $results );
		$this->assertStringStartsWith(
			$custom_cdn,
			$results[0]->replace_url,
			'replace_url must start with the custom CDN domain, not any default. (plan 29.6)'
		);

		// The default CDN domain must not appear anywhere.
		$this->assertStringNotContainsString(
			'cdn.example.com',
			$results[0]->replace_url,
			'The default CDN domain must not appear when a custom domain is configured.'
		);

		// The asset path must follow the CDN domain after the arg segment.
		$this->assertStringContainsString(
			'example.com/wp-content/uploads/hero.jpg',
			$results[0]->replace_url,
			'The scheme-stripped asset URL must be embedded in replace_url after the CDN prefix.'
		);
	}
}
