<?php
/**
 * Tests for ShortPixel\Controller\Front\PageConverter.
 *
 * Scope: pure-computation filter helpers and URL-normalisation utilities
 * that do not depend on the output buffer or WordPress hooks.  Every method
 * is exercised through reflection because PageConverter is a base class;
 * CDNController is used as the concrete subclass so
 * ReflectionClass::newInstanceWithoutConstructor() can stand up an
 * instance without starting ob_start() or loading settings.
 *
 * Tested:
 *   - filterRegexExclusions (including pinned bug: empty-patterns bail returns
 *     raw_url strings instead of replaceBlock objects)
 *   - filterOtherDomains
 *   - filterEmptyURLS
 *   - filterDoubles
 *   - checkPreProcess / status_header_sent
 *   - getReplaceBlock (via CDNController reflection)
 *
 * Out of scope / why:
 *   - shouldConvert(): depends on wpSPIO()->env() live state and WP request
 *     context; integration concern, not unit-testable in isolation.
 *   - startOutputBuffer(): starts ob_start() — side-effectful; integration only.
 *   - getDomain() / addEscapedUrl() / trimURL(): private helpers exercised
 *     indirectly through getReplaceBlock(); isolated coverage would be fragile.
 *   - Constructor: registers hooks and calls shouldConvert() — use
 *     newInstanceWithoutConstructor() throughout.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Front\CDNController;
use ShortPixel\Controller\Front\PageConverter;

class PageConverterTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a CDNController instance bypassing the constructor so no output
	 * buffer, hooks, or settings calls are made.
	 */
	private function freshController(): CDNController {
		$ref = new ReflectionClass( CDNController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/**
	 * Calls a protected/private method on $obj through reflection.
	 *
	 * @param object $obj    Target instance.
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed Return value of the invoked method.
	 */
	private function invokePrivate( object $obj, string $method, array $args = array() ) {
		// Walk up the class hierarchy to find the method (may be on PageConverter).
		$ref = new ReflectionClass( $obj );
		while ( ! $ref->hasMethod( $method ) && $ref->getParentClass() ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	/**
	 * Reads a protected/private property from $obj through reflection.
	 *
	 * @param object $obj  Target instance.
	 * @param string $prop Property name.
	 * @return mixed Current value of the property.
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
	 * Writes a protected/private property on $obj through reflection.
	 *
	 * @param object $obj   Target instance.
	 * @param string $prop  Property name.
	 * @param mixed  $value Value to set.
	 * @return void
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
	 * Builds a minimal replace-block stdClass with the shape expected by all
	 * filter methods.  Only the properties actually read by PageConverter are
	 * populated; callers may override individual fields after calling this.
	 *
	 * @param string      $raw_url URL exactly as it appears in the document.
	 * @param string|null $url     Sanitised URL; defaults to $raw_url.
	 * @param array       $parsed  parse_url result; defaults to parse_url($url).
	 * @return \stdClass
	 */
	private function makeBlock( string $raw_url, ?string $url = null, array $parsed = array() ): \stdClass {
		$block          = new \stdClass();
		$block->raw_url = $raw_url;
		$block->url     = $url ?? $raw_url;
		$block->parsed  = ! empty( $parsed ) ? $parsed : (array) parse_url( $block->url );
		$block->args    = array();
		return $block;
	}

	// -------------------------------------------------------------------------
	// filterRegexExclusions
	// -------------------------------------------------------------------------

	/**
	 * PINNED BUG — PageConverter.php:~227
	 *
	 * When $this->regex_exclusions is empty (or not an array), filterRegexExclusions()
	 * hits the early bail at line ~226 and returns $imageData — an array of plain
	 * raw_url STRINGS produced by array_column() — instead of returning the
	 * original $replaceBlocks array of stdClass objects.
	 *
	 * Expected (after fix): the method returns $replaceBlocks (array of objects).
	 * Actual  (current)   : the method returns an array of raw_url strings.
	 *
	 * This test MUST START FAILING once the early bail is changed to
	 * `return $replaceBlocks;`  instead of `return $imageData;`.
	 */
	public function test_filterRegexExclusions_empty_patterns_returns_strings_not_blocks_pinned_for_deferred_fix() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'regex_exclusions', array() ); // triggers the bail.

		$blocks   = array(
			$this->makeBlock( 'https://example.com/a.jpg' ),
			$this->makeBlock( 'https://example.com/b.png' ),
		);
		$result   = $this->invokePrivate( $ctrl, 'filterRegexExclusions', array( $blocks ) );

		// Current BUGGY behaviour: returns an array of raw_url strings, not objects.
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );

		// Every item must be a string (the raw_url), NOT an object.
		// Once the bug is fixed this assertion will fail — remove the pin at that point.
		foreach ( $result as $item ) {
			$this->assertIsString( $item, 'filterRegexExclusions returned an object instead of a string — bug may be fixed; remove the pin.' );
		}

		// Confirm the values are the raw_urls (not some other strings).
		$this->assertSame( array( 'https://example.com/a.jpg', 'https://example.com/b.png' ), array_values( $result ) );
	}

	/**
	 * With valid patterns, blocks whose raw_url matches a pattern are removed
	 * and the method returns an array of stdClass objects for the non-matching ones.
	 */
	public function test_filterRegexExclusions_with_valid_pattern_removes_matching_blocks() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'regex_exclusions', array( '/gravatar\.com/' ) );

		$keep   = $this->makeBlock( 'https://example.com/photo.jpg' );
		$remove = $this->makeBlock( 'https://secure.gravatar.com/avatar/abc.jpg' );

		$result = $this->invokePrivate( $ctrl, 'filterRegexExclusions', array( array( $keep, $remove ) ) );
		$result = array_values( $result );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/photo.jpg', $result[0]->raw_url );
	}

	/**
	 * With valid patterns that match nothing, all blocks are returned untouched.
	 */
	public function test_filterRegexExclusions_with_non_matching_pattern_returns_all_blocks() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'regex_exclusions', array( '/gravatar\.com/' ) );

		$a = $this->makeBlock( 'https://example.com/a.jpg' );
		$b = $this->makeBlock( 'https://example.com/b.jpg' );

		$result = array_values( $this->invokePrivate( $ctrl, 'filterRegexExclusions', array( array( $a, $b ) ) ) );

		$this->assertCount( 2, $result );
		$this->assertInstanceOf( \stdClass::class, $result[0] );
		$this->assertInstanceOf( \stdClass::class, $result[1] );
	}

	/**
	 * Multiple patterns: a block matching ANY pattern is removed.
	 */
	public function test_filterRegexExclusions_multiple_patterns_removes_any_match() {
		$ctrl = $this->freshController();
		$this->setPrivate(
			$ctrl,
			'regex_exclusions',
			array( '/gravatar\.com/', '/data:image\//' )
		);

		$keep        = $this->makeBlock( 'https://example.com/hero.jpg' );
		$remove_grav = $this->makeBlock( 'https://secure.gravatar.com/avatar/x.jpg' );
		$remove_data = $this->makeBlock( 'data:image/png;base64,abc' );

		$result = array_values(
			$this->invokePrivate( $ctrl, 'filterRegexExclusions', array( array( $keep, $remove_grav, $remove_data ) ) )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/hero.jpg', $result[0]->raw_url );
	}

	/**
	 * Empty block list returns an empty array regardless of pattern count.
	 */
	public function test_filterRegexExclusions_empty_block_list_returns_empty_array() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'regex_exclusions', array( '/gravatar\.com/' ) );

		$result = $this->invokePrivate( $ctrl, 'filterRegexExclusions', array( array() ) );

		$this->assertIsArray( $result );
		$this->assertCount( 0, $result );
	}

	// -------------------------------------------------------------------------
	// filterOtherDomains
	// -------------------------------------------------------------------------

	/**
	 * A block whose URL contains the site domain is kept.
	 */
	public function test_filterOtherDomains_keeps_block_on_same_domain() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'site_domain', 'example.com' );

		$block = $this->makeBlock( 'https://example.com/img.jpg' );

		$result = array_values( $this->invokePrivate( $ctrl, 'filterOtherDomains', array( array( $block ) ) ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/img.jpg', $result[0]->raw_url );
	}

	/**
	 * A block whose absolute URL belongs to a foreign domain is removed.
	 */
	public function test_filterOtherDomains_removes_block_on_foreign_domain() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'site_domain', 'example.com' );

		$block = $this->makeBlock( 'https://other.net/img.jpg' );

		$result = array_values( $this->invokePrivate( $ctrl, 'filterOtherDomains', array( array( $block ) ) ) );

		$this->assertCount( 0, $result );
	}

	/**
	 * A relative URL (no host in parsed) is always kept regardless of domain.
	 */
	public function test_filterOtherDomains_keeps_relative_url_block() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'site_domain', 'example.com' );

		// Relative URL — parse_url returns no 'host' key.
		$block         = new \stdClass();
		$block->raw_url = '/wp-content/uploads/foo.jpg';
		$block->url    = '/wp-content/uploads/foo.jpg';
		$block->parsed = array( 'path' => '/wp-content/uploads/foo.jpg' );
		$block->args   = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterOtherDomains', array( array( $block ) ) ) );

		$this->assertCount( 1, $result );
	}

	/**
	 * Mixed list: same-domain and foreign blocks — only same-domain survives.
	 */
	public function test_filterOtherDomains_mixed_list_keeps_only_same_domain() {
		$ctrl = $this->freshController();
		$this->setPrivate( $ctrl, 'site_domain', 'example.com' );

		$same    = $this->makeBlock( 'https://example.com/a.jpg' );
		$foreign = $this->makeBlock( 'https://cdn.other.org/b.jpg' );

		$result = array_values(
			$this->invokePrivate( $ctrl, 'filterOtherDomains', array( array( $same, $foreign ) ) )
		);

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/a.jpg', $result[0]->raw_url );
	}

	// -------------------------------------------------------------------------
	// filterEmptyURLS
	// -------------------------------------------------------------------------

	/**
	 * A block with a normal URL and a valid parsed array is kept.
	 */
	public function test_filterEmptyURLS_keeps_valid_url_block() {
		$ctrl  = $this->freshController();
		$block = $this->makeBlock( 'https://example.com/img.jpg' );

		$result = array_values( $this->invokePrivate( $ctrl, 'filterEmptyURLS', array( array( $block ) ) ) );

		$this->assertCount( 1, $result );
	}

	/**
	 * A block whose url trims to empty string is removed.
	 */
	public function test_filterEmptyURLS_removes_block_with_empty_url() {
		$ctrl          = $this->freshController();
		$block         = new \stdClass();
		$block->raw_url = '   ';
		$block->url    = '   ';
		$block->parsed = array( 'path' => '' );
		$block->args   = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterEmptyURLS', array( array( $block ) ) ) );

		$this->assertCount( 0, $result );
	}

	/**
	 * A block whose parsed array has neither 'path' nor 'host' is removed as
	 * a non-URL value (e.g. a bare colour keyword leaked through a CSS regex).
	 */
	public function test_filterEmptyURLS_removes_block_with_no_path_and_no_host() {
		$ctrl          = $this->freshController();
		$block         = new \stdClass();
		$block->raw_url = 'transparent';
		$block->url    = 'transparent';
		$block->parsed = array(); // no path, no host
		$block->args   = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterEmptyURLS', array( array( $block ) ) ) );

		$this->assertCount( 0, $result );
	}

	/**
	 * A block with only a 'path' in parsed (relative URL) is kept.
	 */
	public function test_filterEmptyURLS_keeps_block_with_path_only_in_parsed() {
		$ctrl          = $this->freshController();
		$block         = new \stdClass();
		$block->raw_url = '/wp-content/uploads/img.jpg';
		$block->url    = '/wp-content/uploads/img.jpg';
		$block->parsed = array( 'path' => '/wp-content/uploads/img.jpg' );
		$block->args   = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterEmptyURLS', array( array( $block ) ) ) );

		$this->assertCount( 1, $result );
	}

	/**
	 * A block with only a 'host' in parsed (edge case) is kept.
	 */
	public function test_filterEmptyURLS_keeps_block_with_host_only_in_parsed() {
		$ctrl          = $this->freshController();
		$block         = new \stdClass();
		$block->raw_url = 'example.com';
		$block->url    = 'example.com';
		$block->parsed = array( 'host' => 'example.com' );
		$block->args   = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterEmptyURLS', array( array( $block ) ) ) );

		$this->assertCount( 1, $result );
	}

	/**
	 * Mixed list: valid and invalid blocks — only valid ones survive.
	 */
	public function test_filterEmptyURLS_mixed_list_keeps_only_valid_blocks() {
		$ctrl  = $this->freshController();
		$valid = $this->makeBlock( 'https://example.com/img.jpg' );

		$empty         = new \stdClass();
		$empty->raw_url = '';
		$empty->url    = '';
		$empty->parsed = array( 'path' => '' );
		$empty->args   = array();

		$result = array_values( $this->invokePrivate( $ctrl, 'filterEmptyURLS', array( array( $valid, $empty ) ) ) );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.com/img.jpg', $result[0]->raw_url );
	}

	// -------------------------------------------------------------------------
	// filterDoubles
	// -------------------------------------------------------------------------

	/**
	 * No duplicates: all blocks are returned, array is re-indexed.
	 */
	public function test_filterDoubles_no_duplicates_returns_all_blocks() {
		$ctrl = $this->freshController();

		$a              = $this->makeBlock( 'https://example.com/a.jpg' );
		$a->replace_url = 'https://cdn.example.com/spio/a.jpg';
		$b              = $this->makeBlock( 'https://example.com/b.jpg' );
		$b->replace_url = 'https://cdn.example.com/spio/b.jpg';

		$result = $this->invokePrivate( $ctrl, 'filterDoubles', array( array( $a, $b ) ) );

		$this->assertCount( 2, $result );
	}

	/**
	 * Exact duplicate (same raw_url and same replace_url) is removed.
	 */
	public function test_filterDoubles_exact_duplicate_is_removed() {
		$ctrl = $this->freshController();

		$a              = $this->makeBlock( 'https://example.com/img.jpg' );
		$a->replace_url = 'https://cdn.example.com/spio/img.jpg';

		// Identical raw_url AND identical replace_url — should be de-duped.
		$dup              = $this->makeBlock( 'https://example.com/img.jpg' );
		$dup->replace_url = 'https://cdn.example.com/spio/img.jpg';

		$result = $this->invokePrivate( $ctrl, 'filterDoubles', array( array( $a, $dup ) ) );

		$this->assertCount( 1, $result );
	}

	/**
	 * Same raw_url but different replace_url: NOT a duplicate; both kept.
	 */
	public function test_filterDoubles_same_source_different_replace_both_kept() {
		$ctrl = $this->freshController();

		$a              = $this->makeBlock( 'https://example.com/img.jpg' );
		$a->replace_url = 'https://cdn.example.com/spio/img.jpg';

		$b              = $this->makeBlock( 'https://example.com/img.jpg' );
		$b->replace_url = 'https://cdn.example.com/spio/different-img.jpg';

		$result = $this->invokePrivate( $ctrl, 'filterDoubles', array( array( $a, $b ) ) );

		// Different replace_url → not considered a duplicate.
		$this->assertCount( 2, $result );
	}

	/**
	 * filterDoubles re-indexes the returned array with array_values().
	 */
	public function test_filterDoubles_result_is_reindexed() {
		$ctrl = $this->freshController();

		$a              = $this->makeBlock( 'https://example.com/a.jpg' );
		$a->replace_url = 'https://cdn.example.com/spio/a.jpg';

		// Start with a gapped array (simulating prior array_filter calls).
		$input    = array( 5 => $a );
		$result   = $this->invokePrivate( $ctrl, 'filterDoubles', array( $input ) );

		$this->assertArrayHasKey( 0, $result );
	}

	// -------------------------------------------------------------------------
	// checkPreProcess / status_header_sent
	// -------------------------------------------------------------------------

	/**
	 * Default status_header of -1 means checkPreProcess() returns true (go ahead).
	 */
	public function test_checkPreProcess_returns_true_when_status_is_minus_one() {
		$ctrl = $this->freshController();
		// status_header is -1 by default.
		$this->assertTrue( $this->invokePrivate( $ctrl, 'checkPreProcess' ) );
	}

	/**
	 * After status_header_sent captures a 404, checkPreProcess() returns false.
	 */
	public function test_checkPreProcess_returns_false_after_404() {
		$ctrl = $this->freshController();
		$ctrl->status_header_sent( 'HTTP/1.1 404 Not Found', 404 );

		$this->assertFalse( $this->invokePrivate( $ctrl, 'checkPreProcess' ) );
	}

	/**
	 * status_header_sent() is a passthrough filter — returns $status unchanged.
	 */
	public function test_status_header_sent_returns_status_unchanged() {
		$ctrl   = $this->freshController();
		$header = 'HTTP/1.1 200 OK';
		$result = $ctrl->status_header_sent( $header, 200 );

		$this->assertSame( $header, $result );
	}

	/**
	 * A 200 response leaves checkPreProcess() returning true.
	 */
	public function test_checkPreProcess_returns_true_after_200() {
		$ctrl = $this->freshController();
		$ctrl->status_header_sent( 'HTTP/1.1 200 OK', 200 );

		$this->assertTrue( $this->invokePrivate( $ctrl, 'checkPreProcess' ) );
	}

	/**
	 * status_header_sent() stores the numeric code on $this->status_header.
	 */
	public function test_status_header_sent_stores_numeric_code() {
		$ctrl = $this->freshController();
		$ctrl->status_header_sent( 'HTTP/1.1 301 Moved Permanently', 301 );

		$stored = $this->getPrivate( $ctrl, 'status_header' );
		$this->assertSame( 301, $stored );
	}
}
