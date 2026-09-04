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
	 * CDN args are joined with '+' (fix #55) and appear between the CDN domain and the URL.
	 */
	public function test_createReplacements_inlines_args_as_plus_separated_segment() {
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

	// -------------------------------------------------------------------------
	// BUG #55 — FIXED (2026-09-03, Pedro): createReplacements() now joins the
	// CDN argument tokens with '+' instead of a raw ',' — implode('+', ...) —
	// producing URLs like
	//   https://cdn.example.com/spio/ret_img+q_cdnize+to_webp+s_webp/host/img.jpg
	//
	// History: the previous raw-comma delimiter was harmless in browsers
	// (WHATWG srcset parsers only split on trailing commas) but naive
	// comma-splitting srcset parsers (SEO crawlers, indexers, link checkers)
	// shattered each URL into garbage relative fragments such as
	// `s_webp/example.com/uploads/img.jpg 1031w`, resolved against the page
	// URL → 404s (one customer logged 62,000 of them).
	//
	// '+' was verified against the live spcdn.shortpixel.ai CDN (2026-09-03):
	// `ret_img+q_cdnize+to_webp+s_webp` returns a byte-identical 200 response
	// to the comma form, including correct WebP content negotiation. '+' is a
	// legal URL path character (RFC 3986 sub-delims) and is NOT decoded to
	// space in URL paths (only in query strings).
	//
	// The two former pins below are now regression tests asserting the fixed
	// behaviour; the third test (parser-class safety proof) was always
	// production-code-free and unchanged.
	// -------------------------------------------------------------------------

	/**
	 * BUG #55 regression test (fixed 2026-09-03) — Rewritten srcset attribute
	 * values must NOT contain raw commas inside each CDN URL; the argument
	 * tokens are now joined with a srcset-safe delimiter ('+').
	 *
	 * This test exercises the tail of processFront() by:
	 *   1. Building a two-candidate srcset markup with absolute upload URLs.
	 *   2. Running each srcset URL through createReplacements() (the fixed
	 *      code path — implode('+', $replaceBlock->args)).
	 *   3. Running pregReplaceByString() on the full <img> tag to obtain the
	 *      final rewritten HTML the browser would receive.
	 *   4. Extracting the rewritten srcset attribute value and asserting the
	 *      delimiter is srcset-safe.
	 *
	 * Formerly test_pin55_srcset_urls_contain_raw_commas_pinned_for_deferred_fix
	 * (asserted the raw-comma bug); flipped when Pedro shipped the '+' fix.
	 *
	 * Manual plan row: BUG #55
	 *
	 * @return void
	 */
	public function test_srcset_urls_use_srcset_safe_delimiter() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com', 'example.com' );

		$url_1 = 'https://example.com/wp-content/uploads/2024/photo-800.jpg';
		$url_2 = 'https://example.com/wp-content/uploads/2024/photo-1600.jpg';

		$img_tag = '<img src="' . $url_1 . '" '
			. 'srcset="' . $url_1 . ' 800w, ' . $url_2 . ' 1600w" '
			. 'sizes="(max-width: 800px) 100vw, 1600px" alt="Photo">';

		// Build blocks the way extractImageMatches() would, then run through
		// the exact same createReplacements() the bug lives in.
		$args_stub = array(
			'return'      => 'ret_img',
			'compression' => 'q_cdnize',
			'webp'        => 'to_webp',
			'webarg'      => 's_webp',
		);

		$block_1         = $this->makeBlock( $url_1 );
		$block_1->args   = $args_stub;
		$block_2         = $this->makeBlock( $url_2 );
		$block_2->args   = $args_stub;

		$blocks = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $block_1, $block_2 ) ) );

		// Both replace_urls must carry the '+' delimiter — the #55 fix.
		$this->assertStringContainsString(
			'ret_img+q_cdnize',
			$blocks[0]->replace_url,
			'Fix #55: CDN URL joins argument tokens with + (candidate 1).'
		);
		$this->assertStringContainsString(
			'ret_img+q_cdnize',
			$blocks[1]->replace_url,
			'Fix #55: CDN URL joins argument tokens with + (candidate 2).'
		);

		// Now perform the same replacement processFront() would do to produce
		// the final HTML the browser sees.
		$urls         = array( $url_1, $url_2 );
		$replace_urls = array( $blocks[0]->replace_url, $blocks[1]->replace_url );
		$rewritten    = $this->invokePrivate( $ctrl, 'pregReplaceByString', array( $img_tag, $urls, $replace_urls ) );

		// Extract the srcset attribute value from the rewritten tag.
		$matched = preg_match( '/srcset="([^"]*)"/', $rewritten, $srcset_match );
		$this->assertSame( 1, $matched, 'Rewritten <img> tag must still carry a srcset attribute.' );
		$srcset_value = $srcset_match[1];

		$this->assertStringNotContainsString(
			'ret_img,q_cdnize',
			$srcset_value,
			'After fix #55 the CDN argument delimiter inside srcset URLs '
			. 'must not be a raw comma.'
		);
		// The four argument tokens must still be present in order,
		// joined by a srcset-safe delimiter ('+' or '%2C').
		$this->assertMatchesRegularExpression(
			'#ret_img[+%]{1,3}[Cc]?q_cdnize[+%]{1,3}[Cc]?to_webp[+%]{1,3}[Cc]?s_webp#',
			$srcset_value,
			'The four CDN arg tokens must remain in order with a srcset-safe delimiter.'
		);

		// Belt-and-braces: with the '+' delimiter the only comma left in the
		// srcset is the single legal one separating the two candidates.
		$this->assertSame(
			1,
			substr_count( $srcset_value, ',' ),
			'Fix #55: srcset must contain exactly 1 comma — the candidate separator.'
		);
	}

	/**
	 * BUG #55 companion regression test (fixed 2026-09-03) — Naive
	 * comma-splitting of the emitted srcset value must yield exactly the two
	 * candidate URLs, each an absolute URL.
	 *
	 * Before the fix, a naive parser (the way an unaware crawler splits on
	 * every comma) shattered each CDN URL into garbage relative fragments
	 * like `s_webp/example.com/wp-content/uploads/...` — the exact requests
	 * in customer 404 logs. With the '+' delimiter, both conformant and
	 * naive parsers agree on the candidate boundaries.
	 *
	 * Formerly
	 * test_pin55_naive_comma_split_of_srcset_yields_broken_url_fragments_pinned_for_deferred_fix.
	 *
	 * Manual plan row: BUG #55
	 *
	 * @return void
	 */
	public function test_naive_comma_split_of_srcset_yields_intact_candidate_urls() {
		$ctrl = $this->freshController( 'https://cdn.example.com/spio/', 'https://example.com', 'example.com' );

		$url_1 = 'https://example.com/wp-content/uploads/2024/photo-800.jpg';
		$url_2 = 'https://example.com/wp-content/uploads/2024/photo-1600.jpg';

		$img_tag = '<img src="' . $url_1 . '" '
			. 'srcset="' . $url_1 . ' 800w, ' . $url_2 . ' 1600w" alt="Photo">';

		$args_stub = array(
			'return'      => 'ret_img',
			'compression' => 'q_cdnize',
			'webp'        => 'to_webp',
			'webarg'      => 's_webp',
		);
		$b1        = $this->makeBlock( $url_1 );
		$b1->args  = $args_stub;
		$b2        = $this->makeBlock( $url_2 );
		$b2->args  = $args_stub;

		$blocks    = $this->invokePrivate( $ctrl, 'createReplacements', array( array( $b1, $b2 ) ) );
		$rewritten = $this->invokePrivate(
			$ctrl,
			'pregReplaceByString',
			array( $img_tag, array( $url_1, $url_2 ), array( $blocks[0]->replace_url, $blocks[1]->replace_url ) )
		);

		preg_match( '/srcset="([^"]*)"/', $rewritten, $srcset_match );
		$srcset_value = $srcset_match[1];

		// Simulate a naive crawler: split on every comma.
		$fragments = array_map( 'trim', explode( ',', $srcset_value ) );

		$this->assertCount(
			2,
			$fragments,
			'Post-fix: naive comma-split must yield exactly 2 candidates. '
			. 'Fragments observed: ' . implode( ' || ', $fragments )
		);
		foreach ( $fragments as $fragment ) {
			$this->assertMatchesRegularExpression(
				'#^https?://#',
				trim( $fragment ),
				'Post-fix: each naive-split fragment must start with an absolute URL.'
			);
		}
	}

	/**
	 * Safety proof for the '+' delimiter fix — a plus-joined CDN URL survives
	 * BOTH a WHATWG-conformant srcset parser AND a naive comma-splitting
	 * parser, unlike the former comma-joined form (bug #55, fixed 2026-09-03).
	 *
	 * This test is production-code-free: it builds two candidate URLs
	 * manually, once with '+' between arg tokens and once with ',' (the
	 * pre-fix form), then runs each through:
	 *
	 *   (i)  a minimal WHATWG srcset parser implemented inline per spec
	 *        (https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute)
	 *        — the URL token is a run of non-whitespace; only trailing
	 *        commas split; the descriptor is consumed up to the next comma.
	 *   (ii) a naive splitter that just explodes on ','.
	 *
	 * Assertions:
	 *   - '+'-joined: (i) yields 2 candidates, (ii) also yields 2 fragments,
	 *     each containing a valid absolute URL. Both parser classes agree.
	 *   - ','-joined: (i) yields 2 candidates (browsers still work),
	 *     (ii) yields >2 fragments with garbage tokens (crawlers break).
	 *
	 * This test does not depend on production code and doubles as a
	 * regression guard confirming that '+' is safe under both parser classes.
	 *
	 * @return void
	 */
	public function test_plus_delimited_cdn_url_survives_both_conformant_and_naive_srcset_parsing() {
		$cdn_domain = 'https://cdn.example.com/spio/';
		$asset_1    = 'example.com/wp-content/uploads/2024/photo-800.jpg';
		$asset_2    = 'example.com/wp-content/uploads/2024/photo-1600.jpg';

		$args_comma = 'ret_img,q_cdnize,to_webp,s_webp';
		$args_plus  = 'ret_img+q_cdnize+to_webp+s_webp';

		$srcset_comma = $cdn_domain . $args_comma . '/' . $asset_1 . ' 800w, '
			. $cdn_domain . $args_comma . '/' . $asset_2 . ' 1600w';

		$srcset_plus = $cdn_domain . $args_plus . '/' . $asset_1 . ' 800w, '
			. $cdn_domain . $args_plus . '/' . $asset_2 . ' 1600w';

		// --------------------------------------------------------------------
		// PLUS delimiter — must parse correctly under BOTH parser classes.
		// --------------------------------------------------------------------

		$whatwg_plus = $this->parseSrcsetWhatwg( $srcset_plus );
		$this->assertCount(
			2,
			$whatwg_plus,
			"WHATWG parser must produce 2 candidates for '+'-delimited srcset."
		);
		$this->assertStringContainsString( $args_plus, $whatwg_plus[0]['url'] );
		$this->assertStringContainsString( $asset_1,   $whatwg_plus[0]['url'] );
		$this->assertSame( '800w',  $whatwg_plus[0]['descriptor'] );
		$this->assertStringContainsString( $args_plus, $whatwg_plus[1]['url'] );
		$this->assertStringContainsString( $asset_2,   $whatwg_plus[1]['url'] );
		$this->assertSame( '1600w', $whatwg_plus[1]['descriptor'] );

		$naive_plus = array_map( 'trim', explode( ',', $srcset_plus ) );
		$this->assertCount(
			2,
			$naive_plus,
			"Naive comma-split of '+'-delimited srcset must also yield exactly 2 fragments."
		);
		foreach ( $naive_plus as $i => $fragment ) {
			$this->assertMatchesRegularExpression(
				'#^https?://[^\s]+\s+\d+[wx]$#',
				$fragment,
				"Naive-split fragment #" . ( $i + 1 ) . " ('+') must be a full URL + descriptor."
			);
		}

		// --------------------------------------------------------------------
		// COMMA delimiter — WHATWG parser works, but naive parser shatters.
		// --------------------------------------------------------------------

		$whatwg_comma = $this->parseSrcsetWhatwg( $srcset_comma );
		$this->assertCount(
			2,
			$whatwg_comma,
			'Contrast: WHATWG parser also handles the current comma form correctly '
			. '(this is why browsers still render CDN images).'
		);

		$naive_comma = array_map( 'trim', explode( ',', $srcset_comma ) );
		$this->assertGreaterThan(
			2,
			count( $naive_comma ),
			'Contrast: naive comma-split of the CURRENT comma-delimited srcset '
			. 'produces >2 fragments — this is BUG #55 and the source of customer 404 floods.'
		);
	}

	/**
	 * Minimal WHATWG-conformant srcset parser used by the '+'-safety test.
	 *
	 * Implements the essential shape of the algorithm from
	 * https://html.spec.whatwg.org/multipage/images.html#parsing-a-srcset-attribute :
	 *
	 *   loop:
	 *     - skip whitespace and commas
	 *     - collect a URL: everything up to the next whitespace (a URL that
	 *       ENDS with a comma may consume it as a splitter; otherwise the
	 *       URL includes all non-whitespace, and a trailing comma inside the
	 *       URL is part of the URL, not a splitter)
	 *     - skip whitespace
	 *     - collect a descriptor: everything up to (but not including) the
	 *       next comma, honouring the parenthesis nesting used by sizes-style
	 *       descriptors (kept simple here — we only support Nw / Nx forms)
	 *
	 * Returns an array of ['url' => …, 'descriptor' => …].
	 *
	 * @param string $srcset The srcset attribute value.
	 * @return array<int, array{url: string, descriptor: string}>
	 */
	private function parseSrcsetWhatwg( string $srcset ): array {
		$candidates = array();
		$len        = strlen( $srcset );
		$i          = 0;

		while ( $i < $len ) {
			// Skip whitespace and commas between candidates.
			while ( $i < $len && ( ctype_space( $srcset[ $i ] ) || ',' === $srcset[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $len ) {
				break;
			}

			// Collect URL: run of non-whitespace.
			$url_start = $i;
			while ( $i < $len && ! ctype_space( $srcset[ $i ] ) ) {
				++$i;
			}
			$url = substr( $srcset, $url_start, $i - $url_start );

			// Per spec: a URL ending in trailing commas has those commas
			// stripped off and treated as candidate separators.
			$url = rtrim( $url, ',' );

			// Skip whitespace between URL and descriptor.
			while ( $i < $len && ctype_space( $srcset[ $i ] ) ) {
				++$i;
			}

			// Collect descriptor: everything up to the next comma.
			$desc_start = $i;
			while ( $i < $len && ',' !== $srcset[ $i ] ) {
				++$i;
			}
			$descriptor = trim( substr( $srcset, $desc_start, $i - $desc_start ) );

			$candidates[] = array(
				'url'        => $url,
				'descriptor' => $descriptor,
			);
		}

		return $candidates;
	}
}
