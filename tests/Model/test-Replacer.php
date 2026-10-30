<?php
/**
 * Replacer2 replaceContent() — URL replacement across data shapes and the
 * object-injection hardening.
 *
 * Security model: replaceContent() unserializes with
 * `allowed_classes => false` UNCONDITIONALLY — for post_content
 * ($strict_check=true) AND metadata ($strict_check=false). Serialized
 * objects therefore become __PHP_Incomplete_Class and the ORIGINAL
 * serialized string is returned untouched: no object is ever instantiated
 * from database-supplied data (no __wakeup/__destruct gadget can fire).
 *
 * Verified side effect of the hardening: URLs inside serialized OBJECT
 * values (e.g. widget/option payloads storing stdClass) are NO LONGER
 * replaced — the whole serialized blob is returned as-is. Serialized
 * ARRAYS are unaffected and still get their URLs replaced.
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
	 * Serialized objects must be bailed on wholesale: allowed_classes=false
	 * turns them into __PHP_Incomplete_Class and the original serialized
	 * string comes back byte-identical — even in the non-strict (metadata)
	 * path.
	 */
	public function test_serialized_object_is_returned_unchanged_in_both_check_modes() {
		$payload = new stdClass();
		$payload->url = self::OLD_URL;
		$serialized   = serialize( $payload );

		$this->assertSame(
			$serialized,
			$this->replaceContent( $serialized, true ),
			'strict path: serialized objects must be returned untouched (no unserialize-to-object)'
		);
		$this->assertSame(
			$serialized,
			$this->replaceContent( $serialized, false ),
			'metadata path: serialized objects must be returned untouched too — allowed_classes is false unconditionally'
		);
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

	public function test_array_wrapping_a_serialized_object_keeps_the_object_blob_intact() {
		$payload      = new stdClass();
		$payload->url = self::OLD_URL;

		$content = serialize(
			array(
				'plain'  => self::OLD_URL,
				'object' => serialize( $payload ),
			)
		);

		$result       = $this->replaceContent( $content, false );
		$unserialized = unserialize( $result );

		$this->assertSame( self::NEW_URL, $unserialized['plain'], 'Sibling scalar values must still be replaced' );
		$this->assertSame(
			serialize( $payload ),
			$unserialized['object'],
			'The nested serialized-object blob must survive untouched (side effect of the hardening: its URL is NOT replaced)'
		);
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
}
