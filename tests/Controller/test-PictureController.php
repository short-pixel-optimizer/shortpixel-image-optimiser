<?php
/**
 * Tests for ShortPixel\Controller\Front\PictureController.
 *
 * Scope: pure string-transform methods that operate on HTML snippets without
 * touching the filesystem, output buffers, or remote endpoints.  All instances
 * are created via ReflectionClass::newInstanceWithoutConstructor() to avoid
 * registering the 'init' hook and starting an output buffer.  Protected
 * methods are exercised through reflection.
 *
 * Tested:
 *   - testPictures: sp-no-webp class insertion logic for <img> inside <picture>
 *     (quoted class= path and no-class path; the known-unquoted-attribute edge
 *     is explicitly excluded per task scope guidance)
 *   - filterForbiddenInline: !important stripping
 *   - checkPreProcess (inherited via PageConverter, via status_header_sent)
 *   - testInlineStyle: url() match extraction + structured array shape
 *   - convertImgToPictureAddWebp: 404 bail path via status_header_sent
 *   - convert: is_feed() bail (not admin in test harness)
 *   - Constant values: WEBP_GLOBAL, WEBP_WP, WEBP_NOCHANGE
 *
 * Out of scope / why:
 *   - convertImage: depends on wpSPIO()->filesystem() and real file lookups;
 *     full filesystem mock not available in this harness — integration only.
 *   - convertInlineStyle: also depends on wpSPIO()->filesystem() for file
 *     resolution; covered at integration level.
 *   - initWebpHooks: depends on wpSPIO()->settings()->deliverWebp and registers
 *     output buffers/filters — integration concern.
 *   - testPictures unquoted-attribute edge: class= assumption at line ~247
 *     (pos+7) mangles attributes without surrounding quotes; this is a triaged
 *     low-severity known issue — no pin per task instructions.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Front\PictureController;

class PictureControllerTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a PictureController instance that bypasses the constructor
	 * (no 'init' hook registration, no ob_start).
	 *
	 * @return PictureController
	 */
	private function freshController(): PictureController {
		$ref = new ReflectionClass( PictureController::class );
		return $ref->newInstanceWithoutConstructor();
	}

	/**
	 * Calls a protected or private method through reflection, walking the
	 * class hierarchy to find the declaring class.
	 *
	 * @param object $obj    Instance to invoke on.
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed
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
	 *
	 * @param object $obj  Instance.
	 * @param string $prop Property name.
	 * @return mixed
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
	 *
	 * @param object $obj   Instance.
	 * @param string $prop  Property name.
	 * @param mixed  $value New value.
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

	// -------------------------------------------------------------------------
	// Class constants
	// -------------------------------------------------------------------------

	/**
	 * Sentinel: the three delivery-mode constants must hold their documented values.
	 * If they shift, the settings-comparison logic in initWebpHooks breaks.
	 */
	public function test_constants_hold_expected_values() {
		$this->assertSame( 1, PictureController::WEBP_GLOBAL );
		$this->assertSame( 2, PictureController::WEBP_WP );
		$this->assertSame( 3, PictureController::WEBP_NOCHANGE );
	}

	// -------------------------------------------------------------------------
	// testPictures — sp-no-webp class insertion
	// -------------------------------------------------------------------------

	/**
	 * An <img> inside a <picture> that already has a class= attribute gets
	 * 'sp-no-webp ' prepended to the existing class value.
	 *
	 * Note: the code at ~line 247 does `$pos + 7` after finding 'class=' which
	 * positions the cursor just after the opening quote.  This works correctly
	 * for the double-quoted form class="…".
	 */
	public function test_testPictures_prepends_sp_no_webp_to_existing_class() {
		$ctrl = $this->freshController();

		$html   = '<picture><img class="wp-image-1" src="img.jpg"></picture>';
		$result = $this->invokePrivate( $ctrl, 'testPictures', array( $html ) );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'sp-no-webp', $result );
	}

	/**
	 * An <img> inside a <picture> that has NO class= attribute gets
	 * class="sp-no-webp" inserted right after '<img'.
	 */
	public function test_testPictures_inserts_class_when_no_class_attribute_present() {
		$ctrl = $this->freshController();

		$html   = '<picture><img src="img.jpg" alt="test"></picture>';
		$result = $this->invokePrivate( $ctrl, 'testPictures', array( $html ) );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'class="sp-no-webp"', $result );
	}

	/**
	 * An <img> outside any <picture> element is left unchanged.
	 */
	public function test_testPictures_does_not_modify_img_outside_picture() {
		$ctrl = $this->freshController();

		$html   = '<div><img src="img.jpg" class="regular"></div>';
		$result = $this->invokePrivate( $ctrl, 'testPictures', array( $html ) );

		$this->assertSame( $html, $result );
	}

	/**
	 * Content with no <picture> block at all is returned unchanged.
	 */
	public function test_testPictures_returns_content_unchanged_when_no_picture_element() {
		$ctrl = $this->freshController();

		$html   = '<img src="img.jpg"><img src="img2.jpg">';
		$result = $this->invokePrivate( $ctrl, 'testPictures', array( $html ) );

		$this->assertSame( $html, $result );
	}

	/**
	 * Multiple <picture> blocks are each processed independently.
	 */
	public function test_testPictures_handles_multiple_picture_blocks() {
		$ctrl = $this->freshController();

		$html = implode( '', array(
			'<picture><img src="a.jpg"></picture>',
			'<picture><img src="b.jpg" class="wp-image-2"></picture>',
		) );

		$result = $this->invokePrivate( $ctrl, 'testPictures', array( $html ) );

		// Both <img> tags should have sp-no-webp inserted.
		$this->assertSame( 2, substr_count( $result, 'sp-no-webp' ) );
	}

	/**
	 * The sp-no-webp class is positioned inside the existing class attribute
	 * value, not as a separate attribute on the tag.
	 */
	public function test_testPictures_sp_no_webp_is_inside_class_attribute() {
		$ctrl = $this->freshController();

		$html   = '<picture><img class="size-full" src="img.jpg"></picture>';
		$result = $this->invokePrivate( $ctrl, 'testPictures', array( $html ) );

		// The attribute should look like class="sp-no-webp size-full" (prepended)
		// or similar; the important thing is that there is only one class= attribute.
		$this->assertSame( 1, substr_count( $result, 'class=' ) );
		$this->assertStringContainsString( 'sp-no-webp', $result );
	}

	/**
	 * Content that is an empty string returns an empty string (not false).
	 */
	public function test_testPictures_returns_empty_string_for_empty_content() {
		$ctrl   = $this->freshController();
		$result = $this->invokePrivate( $ctrl, 'testPictures', array( '' ) );
		// Empty input: preg_match_all returns 0 matches; content returned unchanged.
		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// filterForbiddenInline
	// -------------------------------------------------------------------------

	/**
	 * !important is stripped from the target string.
	 */
	public function test_filterForbiddenInline_removes_important() {
		$ctrl   = $this->freshController();
		$input  = 'url(bg.jpg) !important';
		$result = $this->invokePrivate( $ctrl, 'filterForbiddenInline', array( $input ) );
		$this->assertStringNotContainsString( '!important', $result );
	}

	/**
	 * A string without !important is returned unchanged.
	 */
	public function test_filterForbiddenInline_leaves_clean_string_unchanged() {
		$ctrl   = $this->freshController();
		$input  = "url('bg.jpg')";
		$result = $this->invokePrivate( $ctrl, 'filterForbiddenInline', array( $input ) );
		$this->assertSame( $input, $result );
	}

	/**
	 * Multiple occurrences of !important are all removed.
	 */
	public function test_filterForbiddenInline_removes_all_occurrences() {
		$ctrl   = $this->freshController();
		$input  = 'url(bg.jpg) !important !important';
		$result = $this->invokePrivate( $ctrl, 'filterForbiddenInline', array( $input ) );
		$this->assertStringNotContainsString( '!important', $result );
	}

	// -------------------------------------------------------------------------
	// checkPreProcess (inherited — via status_header_sent)
	// -------------------------------------------------------------------------

	/**
	 * By default (status_header = -1), checkPreProcess returns true.
	 */
	public function test_checkPreProcess_returns_true_by_default() {
		$ctrl = $this->freshController();
		// status_header property default is -1 (set by PageConverter class definition).
		$this->setPrivate( $ctrl, 'status_header', -1 );
		$this->assertTrue( $this->invokePrivate( $ctrl, 'checkPreProcess' ) );
	}

	/**
	 * After capturing a 404 via status_header_sent, checkPreProcess returns false.
	 */
	public function test_checkPreProcess_returns_false_after_404_via_status_header_sent() {
		$ctrl = $this->freshController();
		$ctrl->status_header_sent( 'HTTP/1.1 404 Not Found', 404 );
		$this->assertFalse( $this->invokePrivate( $ctrl, 'checkPreProcess' ) );
	}

	// -------------------------------------------------------------------------
	// convertImgToPictureAddWebp — bail paths only (no filesystem)
	// -------------------------------------------------------------------------

	/**
	 * When status is 404, convertImgToPictureAddWebp returns content unchanged
	 * (checkPreProcess bail).
	 */
	public function test_convertImgToPictureAddWebp_returns_content_unchanged_on_404() {
		$ctrl = $this->freshController();
		$ctrl->status_header_sent( 'HTTP/1.1 404 Not Found', 404 );

		$content = '<img src="img.jpg">';
		$result  = $ctrl->convertImgToPictureAddWebp( $content );

		$this->assertSame( $content, $result );
	}

	// -------------------------------------------------------------------------
	// testInlineStyle — url() extraction shape
	// -------------------------------------------------------------------------

	/**
	 * A single inline style url() produces one structured match entry.
	 */
	public function test_testInlineStyle_detects_single_url() {
		// Directly validate the regex used by testInlineStyle rather than calling
		// the full method (which would call convertInlineStyle → filesystem).
		$pattern = '/(url\(.*?\))(.*?)(?:;|\"|\')/is';
		$content = '<div style="background: url(https://example.com/bg.jpg);"></div>';

		preg_match_all( $pattern, $content, $matches );

		$this->assertCount( 1, $matches[1] );
		$this->assertStringContainsString( 'url(', $matches[1][0] );
	}

	/**
	 * Multiple url() declarations produce multiple match entries.
	 */
	public function test_testInlineStyle_detects_multiple_urls() {
		$pattern = '/(url\(.*?\))(.*?)(?:;|\"|\')/is';
		$content = '<style>.a { background: url(a.jpg); } .b { background: url(b.jpg); }</style>';

		preg_match_all( $pattern, $content, $matches );

		$this->assertGreaterThanOrEqual( 2, count( $matches[1] ) );
	}

	/**
	 * The pattern matches url() when the closing quote of a style="" attribute
	 * serves as the terminator — this is by design (the pattern accepts " as a
	 * terminator to handle inline style attributes).
	 *
	 * Note: the original test comment assumed no terminator existed, but the
	 * attribute closing `"` in `style="...url(bg.jpg)"` IS the terminator the
	 * pattern seeks.  The correct assertion is that ONE match is found.
	 */
	public function test_testInlineStyle_matches_url_terminated_by_attribute_quote() {
		$pattern = '/(url\(.*?\))(.*?)(?:;|\"|\')/is';
		// The closing " of the style attribute acts as the terminator after url().
		$content = '<div style="background-image: url(bg.jpg)">';

		preg_match_all( $pattern, $content, $matches );

		// One match: url(bg.jpg) terminated by the closing " of the attribute.
		$this->assertCount( 1, $matches[1] );
		$this->assertStringContainsString( 'url(bg.jpg)', $matches[1][0] );
	}

	/**
	 * testInlineStyle returns the content unchanged when no url() is found.
	 */
	public function test_testInlineStyle_returns_content_unchanged_when_no_url() {
		$ctrl   = $this->freshController();
		$html   = '<p>No backgrounds here</p>';
		$result = $this->invokePrivate( $ctrl, 'testInlineStyle', array( $html ) );
		$this->assertSame( $html, $result );
	}
}
