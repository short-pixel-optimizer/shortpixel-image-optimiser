<?php
/**
 * Replacer2 replaceContent() — URL replacement across data shapes and the
 * object-injection hardening.
 *
 * Security model: replaceContent() unserializes with
 * `allowed_classes => array( 'stdClass' )` — for post_content
 * ($strict_check=true) AND metadata ($strict_check=false). stdClass has no
 * magic methods, so plain data objects (Beaver Builder nodes, settings
 * payloads) are unserialized and their URLs replaced. Every other class
 * becomes __PHP_Incomplete_Class and the ORIGINAL serialized string is
 * returned untouched: no object of a real class is ever instantiated from
 * database-supplied data (no __wakeup/__destruct gadget can fire).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Replacer\Replacer;

class ReplacerTest extends WP_UnitTestCase {

	private const OLD_URL = 'http://example.org/wp-content/uploads/old-image.jpg';
	private const NEW_URL = 'http://example.org/wp-content/uploads/new-image.jpg';

	private function replaceContent( $content, bool $strict_check = true ) {
		return Replacer::getInstance()->replaceContent(
			$content,
			array( self::OLD_URL ),
			array( self::NEW_URL ),
			false,
			$strict_check
		);
	}

	public function test_plain_string_url_is_replaced() {
		$result = $this->replaceContent( 'Image lives at ' . self::OLD_URL . ' here.' );
		$this->assertSame( 'Image lives at ' . self::NEW_URL . ' here.', $result );
	}

	public function test_serialized_array_urls_are_replaced() {
		$content = serialize(
			array(
				'main'  => self::OLD_URL,
				'other' => 'untouched',
				'deep'  => array( 'thumb' => self::OLD_URL ),
			)
		);

		$result = $this->replaceContent( $content, false );

		$this->assertIsString( $result );
		$unserialized = unserialize( $result );
		$this->assertSame( self::NEW_URL, $unserialized['main'], 'Serialized array values must still be replaced' );
		$this->assertSame( self::NEW_URL, $unserialized['deep']['thumb'], 'Nested serialized array values must still be replaced' );
		$this->assertSame( 'untouched', $unserialized['other'] );
	}

	/**
	 * A serialized stdClass is unserialized, its URL replaced and the value
	 * serialized again, with correct string lengths, in both check modes.
	 */
	public function test_serialized_stdclass_url_is_replaced_in_both_check_modes() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;
		$serialized   = serialize( $payload );

		$expected      = new stdClass();
		$expected->url = self::NEW_URL;

		$this->assertSame( serialize( $expected ), $this->replaceContent( $serialized, true ), 'strict path: the stdClass URL is replaced.' );
		$this->assertSame( serialize( $expected ), $this->replaceContent( $serialized, false ), 'metadata path: the stdClass URL is replaced.' );
	}

	/**
	 * The classic object-injection shape: a serialized object of a class
	 * with a magic method. It must never be instantiated (its __wakeup must
	 * not run) and the stored data must not be corrupted.
	 */
	public function test_serialized_object_with_magic_method_is_never_instantiated() {
		// Hand-built payload for a class that DOES exist and has __wakeup.
		$serialized = 'O:24:"SPIO_Test_Wakeup_Canary_":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';

		$result = $this->replaceContent( $serialized, false );

		$this->assertSame(
			$serialized,
			$result,
			'A serialized object carrying a magic-method gadget must be returned unchanged, never unserialized into a live object'
		);
	}

	/**
	 * A serialized object of a class other than stdClass, nested as a string
	 * inside an array, survives byte-identical: the class is not allowed, the
	 * nested value becomes an incomplete object and is left alone.
	 */
	public function test_array_wrapping_a_serialized_custom_object_keeps_the_object_blob_intact() {
		$blob    = 'O:24:"SPIO_Test_Wakeup_Canary_":1:{s:3:"url";s:' . strlen( self::OLD_URL ) . ':"' . self::OLD_URL . '";}';
		$content = serialize(
			array(
				'plain'  => self::OLD_URL,
				'object' => $blob,
			)
		);

		$unserialized = unserialize( $this->replaceContent( $content, false ) );

		$this->assertSame( self::NEW_URL, $unserialized['plain'], 'Sibling scalar values must still be replaced' );
		$this->assertSame( $blob, $unserialized['object'], 'The nested custom-object blob must survive untouched.' );
	}

	/**
	 * Pins a known defect: a serialized stdClass nested AS A STRING inside
	 * serialized data (a plugin that calls serialize() itself before saving)
	 * comes back as a live stdClass object instead of a serialized string.
	 * replaceContent() unserializes every nested serialized string but never
	 * serializes it again below the top level. The stored shape changes from
	 * string to object, so the owning plugin's own unserialize() gets an
	 * object. Same cause as the nested-array variant of the serialized-option
	 * corruption pinned in Integration/test-ConversionReplacement.php.
	 * @todo In the replacer2 module source, Replacer::replaceContent():
	 *       serialize a nested value again when this call unserialized it,
	 *       independent of $in_deep (as is already done for nested JSON).
	 * When fixed, this test fails; flip it: `object` is still a string, now
	 * holding the serialized stdClass with the new URL.
	 */
	public function test_array_wrapping_a_serialized_stdclass_returns_it_as_an_object_pinned_for_deferred_fix() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;

		$content = serialize(
			array(
				'plain'  => self::OLD_URL,
				'object' => serialize( $payload ),
			)
		);

		$unserialized = unserialize( $this->replaceContent( $content, false ) );

		// SENTINEL: the row was processed.
		$this->assertSame( self::NEW_URL, $unserialized['plain'], 'Sentinel: sibling scalar values are replaced.' );

		// THE PIN: the nested serialized string is now an object.
		$this->assertInstanceOf( stdClass::class, $unserialized['object'], 'Pinned: fixed? The nested serialized stdClass stays a string — flip this pin.' );
		$this->assertSame( self::NEW_URL, $unserialized['object']->url, 'Sentinel: its URL was replaced.' );
	}

	/**
	 * Bricks-shaped page data: a serialized array of elements, where the
	 * nested slider keeps its custom options as a JSON string next to an
	 * image element that holds the URL being replaced.
	 */
	private function bricksPageContent( string $options_json ): array {
		return array(
			array(
				'id'       => 'slider1',
				'name'     => 'slider-nested',
				'settings' => array(
					'optionsType' => 'custom',
					'options'     => $options_json,
				),
			),
			array(
				'id'       => 'image1',
				'name'     => 'image',
				'settings' => array( 'image' => array( 'url' => self::OLD_URL ) ),
			),
		);
	}

	/**
	 * A JSON string nested inside serialized data stays a string.
	 * replaceContent() json_decode()s every JSON-looking string it meets and
	 * re-encodes it at every depth, not only at the top level. Were it left
	 * decoded, the value would be stored back as a stdClass object and Bricks,
	 * which reads its nested slider options with stripslashes(), would fatal
	 * ("stdClass given") on every page showing the element.
	 */
	public function test_json_string_nested_in_serialized_data_stays_a_json_string() {
		$options = '{"type":"loop","perPage":3,"gap":"1em"}';
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		// SENTINEL: the URL beside it was replaced.
		$this->assertSame( self::NEW_URL, $result[1]['settings']['image']['url'], 'Sentinel: the image URL was replaced.' );

		$this->assertSame( $options, $result[0]['settings']['options'], 'The nested JSON string must come back as the same string.' );
	}

	/**
	 * A nested JSON string that carries the URL is replaced inside and
	 * stored back as a JSON string.
	 */
	public function test_json_string_with_the_url_nested_in_serialized_data_is_replaced_and_stays_a_json_string() {
		$options = wp_json_encode( array( 'cover' => self::OLD_URL ), JSON_UNESCAPED_SLASHES );
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		$this->assertIsString( $result[0]['settings']['options'] );
		$this->assertSame(
			wp_json_encode( array( 'cover' => self::NEW_URL ), JSON_UNESCAPED_SLASHES ),
			$result[0]['settings']['options'],
			'The URL inside the nested JSON string was replaced.'
		);
	}

	/**
	 * Pins a known defect: a nested JSON string that holds none of the search
	 * URLs is still decoded and re-encoded with the replacer's json_flags
	 * (JSON_UNESCAPED_SLASHES only), so it does not come back byte-identical:
	 * non-ASCII text turns into \uXXXX escapes. Bricks reads its slider
	 * options with stripslashes() before json_decode(), so "Café" stored as
	 * "Caf\u00e9" is shown as "Cafu00e9". Only rows the replacer matched (the
	 * row holds the replaced URL somewhere else) are affected.
	 * @todo In the replacer2 module source, Replacer::replaceContent(): return
	 *       a nested JSON string unchanged when nothing inside it was replaced.
	 * When fixed, this test fails; flip it: `options` is byte-identical.
	 */
	public function test_nested_json_string_without_the_url_is_re_encoded_with_unicode_escapes_pinned_for_deferred_fix() {
		$options = '{"label":"Café"}';
		$content = serialize( $this->bricksPageContent( $options ) );

		$result = unserialize( $this->replaceContent( $content, false ) );

		// SENTINEL: the row was processed (URL beside it replaced) and the value is still a string.
		$this->assertSame( self::NEW_URL, $result[1]['settings']['image']['url'], 'Sentinel: the image URL was replaced.' );
		$this->assertIsString( $result[0]['settings']['options'], 'Sentinel: the nested JSON stays a string.' );

		// THE PIN: same data, different bytes.
		$this->assertSame( '{"label":"Caf\\u00e9"}', $result[0]['settings']['options'], 'Pinned: fixed? The untouched JSON string keeps its bytes — flip this pin.' );
	}

	/**
	 * replace() pairs every source size with the target URL of the SAME size,
	 * also when a size missing in the target is filled in by findNearestSize().
	 * str_replace() pairs the search and replace arrays by position, not by
	 * key, and a nearest-size URL is appended at the end of the replace array;
	 * replace() therefore rebuilds the replace array in the order of the
	 * search array. Without that, the source "medium" URL would be rewritten
	 * to the target "large" file and the source "large" URL to the nearest
	 * file picked for "medium".
	 */
	public function test_replace_pairs_a_nearest_size_fallback_with_its_own_source_size() {
		$base = 'http://example.org/wp-content/uploads/2026/11/';

		$source_meta = array(
			'file'   => '2026/11/src.jpg',
			'width'  => 2000,
			'height' => 1333,
			'sizes'  => array(
				'thumbnail' => array( 'file' => 'src-150x150.jpg', 'width' => 150, 'height' => 150 ),
				'medium'    => array( 'file' => 'src-300x200.jpg', 'width' => 300, 'height' => 200 ),
				'large'     => array( 'file' => 'src-1024x683.jpg', 'width' => 1024, 'height' => 683 ),
			),
		);
		// The target has no "medium": its closest width to 300 is the 400px "custom" size.
		$target_meta = array(
			'file'   => '2026/11/tgt.jpg',
			'width'  => 2000,
			'height' => 1333,
			'sizes'  => array(
				'thumbnail' => array( 'file' => 'tgt-150x150.jpg', 'width' => 150, 'height' => 150 ),
				'custom'    => array( 'file' => 'tgt-400x267.jpg', 'width' => 400, 'height' => 267 ),
				'large'     => array( 'file' => 'tgt-1024x683.jpg', 'width' => 1024, 'height' => 683 ),
			),
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<img src="' . $base . 'src-300x200.jpg"><img src="' . $base . 'src-1024x683.jpg">',
			)
		);

		$replacer = Replacer::getInstance();
		$replacer->setSource( $base . 'src.jpg' );
		$replacer->setTarget( $base . 'tgt.jpg' );
		$replacer->setSourceMeta( $source_meta );
		$replacer->setTargetMeta( $target_meta );
		$updated = $replacer->replace();

		$this->assertIsInt( $updated, 'Sentinel: replace() ran instead of returning errors.' );
		clean_post_cache( $post_id );
		$content = get_post_field( 'post_content', $post_id );

		// Misaligned pairs would swap the two files: medium → large, large → 400px.
		$this->assertSame(
			'<img src="' . $base . 'tgt-400x267.jpg"><img src="' . $base . 'tgt-1024x683.jpg">',
			$content,
			'The source medium URL gets the nearest target size, the source large URL the target large size.'
		);
	}
}
