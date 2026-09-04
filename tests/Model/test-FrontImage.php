<?php
/**
 * Tests for ShortPixel\Model\FrontImage.
 *
 * Focus areas:
 *   - loadImageDom's attribute extraction (img + <source> fallback, empty
 *     attribute filtering, data-srcset → srcset promotion)
 *   - isParseable's composite gate (hasPreventClasses / hasBackground /
 *     hasSource / image_loaded)
 *   - getLazyData's data-lazy- → data- → plain priority order
 *   - buildImage / buildSource / parseReplacement markup generation
 *   - The magic __get / __set accessors
 *
 * Every test picks a URL under the WP uploads path so setupSource()
 * successfully derives the imageBase directory.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\FrontImage;

class FrontImageTest extends WP_UnitTestCase {

	/**
	 * A URL under the WP uploads dir with a processable extension, used as
	 * the default image source. Constructed once per test so tests stay
	 * isolated even when the harness recycles uploads content.
	 */
	private function sampleUrl( string $file = 'sample.jpg' ): string {
		$upload = wp_upload_dir();
		return $upload['baseurl'] . '/' . $file;
	}

	private function getPrivate( FrontImage $f, string $prop ) {
		$ref = new ReflectionClass( FrontImage::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $f );
	}

	private function setPrivate( FrontImage $f, string $prop, $value ): void {
		$ref = new ReflectionClass( FrontImage::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $f, $value );
	}

	private function invokePrivate( FrontImage $f, string $method, array $args = array() ) {
		$ref = new ReflectionClass( FrontImage::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $f, ...$args );
	}

	/*
	 * Constructor + loadImageDom
	 */

	public function test_constructor_parses_img_attributes_into_declared_properties() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" alt="hello" width="120" height="80" class="foo" id="pic-1" />' );

		$this->assertSame( $url, $fi->src );
		$this->assertSame( 'hello', $fi->alt );
		$this->assertSame( '120', $fi->width );
		$this->assertSame( '80', $fi->height );
		$this->assertSame( 'foo', $fi->class );
		$this->assertSame( 'pic-1', $fi->id );
		$this->assertTrue( $this->getPrivate( $fi, 'image_loaded' ) );
	}

	public function test_constructor_falls_back_to_source_element_when_no_img_present() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<source srcset="' . $url . '" />' );

		$this->assertSame( $url, $fi->srcset );
		$this->assertTrue( $this->getPrivate( $fi, 'image_loaded' ) );
	}

	public function test_constructor_leaves_image_loaded_false_when_no_img_or_source_present() {
		$fi = new FrontImage( '<div>no image here</div>' );

		$this->assertFalse( $this->getPrivate( $fi, 'image_loaded' ) );
	}

	public function test_loadImageDom_preserves_empty_attribute_values() {
		// FLIPPED 2026-09-03: efbd5ac9 fixed the lossy behavior where empty /
		// value-less attributes (boolean flags like data-no-lazy, nopin) were
		// dropped during parse+rebuild. Empty values are now stored as ''.
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" alt="" class="" />' );

		$this->assertSame( '', $fi->alt );
		$this->assertSame( '', $fi->class );
		$attributes = $this->getPrivate( $fi, 'attributes' );
		$this->assertSame( '', $attributes['alt'] );
		$this->assertSame( '', $attributes['class'] );
	}

	public function test_loadImageDom_promotes_data_srcset_when_srcset_is_missing() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" data-srcset="' . $url . ' 1x" />' );

		$this->assertSame( $url . ' 1x', $fi->srcset );
	}

	public function test_loadImageDom_records_all_attributes_on_the_attributes_map() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" alt="a" data-custom="x" role="presentation" />' );

		$attrs = $this->getPrivate( $fi, 'attributes' );
		$this->assertSame( $url, $attrs['src'] );
		$this->assertSame( 'a', $attrs['alt'] );
		$this->assertSame( 'x', $attrs['data-custom'] );
		$this->assertSame( 'presentation', $attrs['role'] );
	}

	/*
	 * __get / __set
	 */

	public function test_get_returns_declared_property_value() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" alt="hello" />' );
		$this->assertSame( 'hello', $fi->alt );
	}

	public function test_get_returns_null_for_unknown_property() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" />' );
		$this->assertNull( $fi->definitely_not_a_field );
	}

	public function test_set_writes_to_declared_property() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" />' );
		$fi->alt = 'new-alt';
		$this->assertSame( 'new-alt', $fi->alt );
	}

	public function test_set_silently_drops_writes_to_unknown_property() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" />' );
		$fi->not_a_field = 'nope';
		$this->assertFalse( property_exists( $fi, 'not_a_field' ) );
	}

	/*
	 * hasBackground / hasPreventClasses / hasSource — individual predicates
	 */

	public function test_hasBackground_true_when_style_contains_background() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" style="background: url(a); color: red" />' );
		$this->assertTrue( $fi->hasBackground() );
	}

	public function test_hasBackground_false_when_style_is_null() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" />' );
		$this->assertFalse( $fi->hasBackground() );
	}

	public function test_hasBackground_false_when_style_lacks_background() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" style="color: red" />' );
		$this->assertFalse( $fi->hasBackground() );
	}

	public function test_hasPreventClasses_true_when_class_contains_sp_no_webp() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" class="foo sp-no-webp bar" />' );
		$this->assertTrue( $fi->hasPreventClasses() );
	}

	public function test_hasPreventClasses_true_when_class_contains_rev_sildebg() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" class="rev-sildebg" />' );
		$this->assertTrue( $fi->hasPreventClasses() );
	}

	public function test_hasPreventClasses_false_when_class_is_null() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" />' );
		$this->assertFalse( $fi->hasPreventClasses() );
	}

	public function test_hasPreventClasses_respects_the_shortpixel_front_preventclasses_filter() {
		add_filter( 'shortpixel/front/preventclasses', function () {
			return array( 'custom-prevent' );
		} );

		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" class="custom-prevent" />' );
		$this->assertTrue( $fi->hasPreventClasses() );

		remove_all_filters( 'shortpixel/front/preventclasses' );
	}

	public function test_hasSource_false_when_both_src_and_srcset_are_null() {
		$fi = $this->reflectionInstance();
		$this->assertFalse( $fi->hasSource() );
	}

	public function test_hasSource_true_when_src_is_set() {
		$fi = $this->reflectionInstance();
		$this->setPrivate( $fi, 'src', 'anything' );
		$this->assertTrue( $fi->hasSource() );
	}

	public function test_hasSource_true_when_srcset_is_set() {
		$fi = $this->reflectionInstance();
		$this->setPrivate( $fi, 'srcset', 'anything 1x' );
		$this->assertTrue( $fi->hasSource() );
	}

	/*
	 * isParseable — composite of the above + image_loaded
	 */

	public function test_isParseable_true_when_all_conditions_are_satisfied() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" alt="ok" />' );
		$this->assertTrue( $fi->isParseable() );
	}

	public function test_isParseable_false_when_prevent_class_is_present() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" class="sp-no-webp" />' );
		$this->assertFalse( $fi->isParseable() );
	}

	public function test_isParseable_false_when_style_uses_background() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" style="background: url(x)" />' );
		$this->assertFalse( $fi->isParseable() );
	}

	public function test_isParseable_false_when_dom_did_not_load() {
		$fi = new FrontImage( '<div>no img</div>' );
		$this->assertFalse( $fi->isParseable() );
	}

	/*
	 * checkExtensionConvertable — pure logic (private)
	 */

	public function test_checkExtensionConvertable_true_for_supported_extensions() {
		$fi = $this->reflectionInstance();
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) as $ext ) {
			$this->assertTrue(
				$this->invokePrivate( $fi, 'checkExtensionConvertable', array( "http://example.com/foo.$ext" ) ),
				"$ext should be convertable"
			);
		}
	}

	public function test_checkExtensionConvertable_false_for_unsupported_extensions() {
		$fi = $this->reflectionInstance();
		foreach ( array( 'svg', 'heic', 'mp4', 'txt' ) as $ext ) {
			$this->assertFalse(
				$this->invokePrivate( $fi, 'checkExtensionConvertable', array( "http://example.com/foo.$ext" ) ),
				"$ext should NOT be convertable"
			);
		}
	}

	/*
	 * getLazyData — priority order test
	 */

	public function test_getLazyData_matches_data_lazy_prefix_first() {
		$fi = new FrontImage(
			'<img src="' . $this->sampleUrl() . '" data-lazy-src="LAZY" data-src="DATA" />'
		);

		$this->assertSame( 'LAZY', $this->invokePrivate( $fi, 'getLazyData', array( 'src' ) ) );

		$tags = $this->getPrivate( $fi, 'dataTags' );
		$this->assertSame( 'data-lazy-', $tags['src'] );
	}

	public function test_getLazyData_falls_back_to_data_prefix_when_lazy_is_absent() {
		$fi = new FrontImage(
			'<img src="' . $this->sampleUrl() . '" data-src="DATA" />'
		);

		$this->assertSame( 'DATA', $this->invokePrivate( $fi, 'getLazyData', array( 'src' ) ) );

		$tags = $this->getPrivate( $fi, 'dataTags' );
		$this->assertSame( 'data-', $tags['src'] );
	}

	public function test_getLazyData_falls_back_to_plain_attribute_when_data_prefixes_absent() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" />' );

		$this->assertSame( $url, $this->invokePrivate( $fi, 'getLazyData', array( 'src' ) ) );

		$tags = $this->getPrivate( $fi, 'dataTags' );
		$this->assertSame( '', $tags['src'] );
	}

	/*
	 * getImageData — srcset splitting vs single-src wrapping
	 */

	public function test_getImageData_returns_srcset_entries_split_on_commas() {
		$fi = new FrontImage(
			'<img src="' . $this->sampleUrl() . '" srcset="a.jpg 1x, b.jpg 2x" />'
		);

		$this->assertSame( array( 'a.jpg 1x', ' b.jpg 2x' ), $fi->getImageData() );
	}

	public function test_getImageData_returns_single_src_wrapped_in_array_when_no_srcset() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" />' );

		$this->assertSame( array( $url ), $fi->getImageData() );
	}

	/*
	 * getImageAttributes — leftover attributes only
	 */

	public function test_getImageAttributes_returns_only_non_standard_attributes() {
		$fi = new FrontImage(
			'<img src="' . $this->sampleUrl() . '" alt="a" class="c" data-custom="x" role="presentation" />'
		);

		$out = $this->invokePrivate( $fi, 'getImageAttributes' );

		$this->assertArrayNotHasKey( 'src', $out );
		$this->assertArrayNotHasKey( 'alt', $out );
		$this->assertArrayNotHasKey( 'class', $out );
		$this->assertSame( 'x', $out['data-custom'] );
		$this->assertSame( 'presentation', $out['role'] );
	}

	/*
	 * buildImage — echoes the standard set plus leftover attributes
	 */

	public function test_buildImage_echoes_all_standard_attributes_and_leftovers() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage(
			'<img src="' . $url . '" alt="hi" class="foo" width="10" height="20" data-x="y" />'
		);

		$out = $fi->buildImage();

		$this->assertStringContainsString( 'src="' . $url . '"', $out );
		$this->assertStringContainsString( 'alt="hi"', $out );
		$this->assertStringContainsString( 'class="foo"', $out );
		$this->assertStringContainsString( 'width="10"', $out );
		$this->assertStringContainsString( 'height="20"', $out );
		$this->assertStringContainsString( 'data-x="y"', $out );
	}

	public function test_buildImage_always_emits_alt_even_when_empty() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" />' );

		$this->assertStringContainsString( 'alt=""', $fi->buildImage() );
	}

	/**
	 * Regression for efbd5ac9: boolean / value-less attributes (data-no-lazy,
	 * nopin, etc.) must survive a parse+rebuild as BARE attributes — the old
	 * behavior dropped them entirely, breaking Pinterest / lazy-loading opt-outs.
	 * The FrontImage rebuild loop must emit the name without an ="" value, EXCEPT
	 * for `alt` which stays `alt=""` (ff2305e4, see separate test below).
	 */
	public function test_buildImage_preserves_bare_boolean_attributes_regression_bug3() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage(
			'<img src="' . $url . '" data-no-lazy nopin />'
		);

		$out = $fi->buildImage();

		$this->assertMatchesRegularExpression(
			'/\sdata-no-lazy(?![=\w-])/',
			$out,
			'data-no-lazy must be emitted as a bare attribute (no ="")'
		);
		$this->assertMatchesRegularExpression(
			'/\snopin(?![=\w-])/',
			$out,
			'nopin must be emitted as a bare attribute (no ="")'
		);
		$this->assertStringNotContainsString( 'data-no-lazy=""', $out );
		$this->assertStringNotContainsString( 'nopin=""', $out );
	}

	/**
	 * Regression for efbd5ac9: buildImage() now iterates the ORIGINAL
	 * $attributes map in insertion order rather than emitting a fixed list
	 * of standard attributes first. Any custom order the source markup used
	 * must be preserved so post-content byte comparisons after AI runs
	 * remain stable.
	 */
	public function test_buildImage_preserves_original_attribute_insertion_order() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage(
			'<img data-x="1" class="foo" src="' . $url . '" alt="hi" width="10" />'
		);

		$out = $fi->buildImage();

		$posDataX  = strpos( $out, 'data-x=' );
		$posClass  = strpos( $out, 'class=' );
		$posSrc    = strpos( $out, 'src=' );
		$posAlt    = strpos( $out, 'alt=' );
		$posWidth  = strpos( $out, 'width=' );

		$this->assertNotFalse( $posDataX );
		$this->assertLessThan( $posClass, $posDataX,  'data-x must precede class' );
		$this->assertLessThan( $posSrc,   $posClass,  'class must precede src' );
		$this->assertLessThan( $posAlt,   $posSrc,    'src must precede alt' );
		$this->assertLessThan( $posWidth, $posAlt,    'alt must precede width' );
	}

	/**
	 * Regression for efbd5ac9: src values with entity-encoded ampersands
	 * (`&amp;`) must survive rebuild — the src is run through esc_attr which
	 * re-escapes `&` back to `&amp;`. The old algorithm would frequently
	 * corrupt entities in URLs with query strings.
	 */
	public function test_buildImage_escapes_ampersand_in_src_back_to_amp_entity_regression_bug3() {
		$url = $this->sampleUrl( 'sample.jpg?w=100&amp;h=50' );
		$fi  = new FrontImage( '<img src="' . $url . '" />' );

		$out = $fi->buildImage();

		$this->assertMatchesRegularExpression(
			'/src="[^"]*sample\.jpg\?w=100&amp;h=50"/',
			$out,
			'The &amp; entity in src must survive rebuild'
		);
	}

	/**
	 * Regression for efbd5ac9: buildImage() output must end with a bare `>`
	 * (no `' > '` with trailing space) — the old algorithm concatenated the
	 * closing chevron with surrounding whitespace and produced invalid-ish
	 * `<img ... >` markup that some parsers rendered oddly.
	 */
	public function test_buildImage_ends_with_bare_gt_no_trailing_space() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" alt="ok" />' );

		$out = $fi->buildImage();

		$this->assertStringEndsWith( '>', $out );
		$this->assertStringNotContainsString( ' >', $out, 'No stray space before the closing >' );
	}

	/**
	 * Regression for ff2305e4 ("Fix for Alt"): an alt attribute with an empty
	 * value must be emitted as `alt=""` (screen-reader-valid), NOT as a bare
	 * `alt`. Every other value-less attribute (data-no-lazy, nopin) does emit
	 * bare; alt is the specific exception at FrontImage.php:513.
	 */
	public function test_buildImage_emits_alt_as_empty_string_form_even_when_value_is_empty_regression_ff2305e4() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" alt="" />' );

		$out = $fi->buildImage();

		$this->assertStringContainsString( 'alt=""', $out );
		$this->assertDoesNotMatchRegularExpression(
			'/\salt(?![=\w-])/',
			$out,
			'alt must NOT be emitted as a bare attribute — always alt="" form'
		);
	}

	/**
	 * Regression: the $caption protected property added in efbd5ac9 is a
	 * PLACEHOLDER only. It is not an HTML attribute; buildImage() must
	 * never leak `caption="..."` into the emitted <img>. If this test fails,
	 * the rebuild loop has started iterating declared properties rather than
	 * the $attributes map — a lossy regression.
	 */
	public function test_buildImage_never_leaks_caption_property_as_html_attribute() {
		$fi = new FrontImage( '<img src="' . $this->sampleUrl() . '" alt="hi" />' );
		$fi->caption = 'some caption text';

		$out = $fi->buildImage();

		$this->assertStringNotContainsString( 'caption=', $out, 'caption is not an HTML attribute — must not appear in <img>' );
	}

	/*
	 * buildSource — emits a <source> element with the chosen prefix
	 */

	public function test_buildSource_emits_source_element_with_webp_type() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" />' );

		// Prime dataTags — this is what getImageData() would do in practice.
		$this->invokePrivate( $fi, 'getLazyData', array( 'src' ) );

		$out = $this->invokePrivate(
			$fi,
			'buildSource',
			array( array( '/foo.webp' ), 'webp' )
		);

		$this->assertStringContainsString( '<source', $out );
		$this->assertStringContainsString( 'srcset="/foo.webp"', $out );
		$this->assertStringContainsString( 'type="image/webp"', $out );
	}

	/*
	 * parseReplacement — full <picture> output shape
	 */

	public function test_parseReplacement_produces_picture_block_and_adds_sp_no_webp_class() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" class="original" />' );
		$fi->getImageData(); // prime dataTags

		$html = $fi->parseReplacement( array(
			'avif' => array( '/foo.avif' ),
			'webp' => array( '/foo.webp' ),
		) );

		$this->assertStringStartsWith( '<picture>', $html );
		$this->assertStringEndsWith( '</picture>', $html );
		$this->assertStringContainsString( 'type="image/avif"', $html );
		$this->assertStringContainsString( 'type="image/webp"', $html );
		$this->assertStringContainsString( '<img', $html );
		// The original class survives; sp-no-webp is appended.
		$this->assertStringContainsString( 'class="original sp-no-webp"', $html );
	}

	public function test_parseReplacement_omits_avif_source_when_avif_list_is_empty() {
		$url = $this->sampleUrl();
		$fi  = new FrontImage( '<img src="' . $url . '" />' );
		$fi->getImageData();

		$html = $fi->parseReplacement( array( 'webp' => array( '/foo.webp' ) ) );

		$this->assertStringContainsString( 'type="image/webp"', $html );
		$this->assertStringNotContainsString( 'type="image/avif"', $html );
	}

	/*
	 * Reflection helper — a fresh instance without triggering the DOM parse.
	 */

	private function reflectionInstance(): FrontImage {
		$ref = new ReflectionClass( FrontImage::class );
		return $ref->newInstanceWithoutConstructor();
	}
}
