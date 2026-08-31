<?php
/**
 * Replacer2 replaceContent() — URL replacement across data shapes and the
 * object-injection hardening.
 *
 * Security model (97f2c1f4): replaceContent() unserializes with
 * `allowed_classes => false` UNCONDITIONALLY — for post_content
 * ($strict_check=true) AND metadata ($strict_check=false). Serialized
 * objects therefore become __PHP_Incomplete_Class and the ORIGINAL
 * serialized string is returned untouched: no object is ever instantiated
 * from database-supplied data (no __wakeup/__destruct gadget can fire).
 * This replaced the earlier containsMagicMethods() reflection scan, which
 * instantiated objects first and only then inspected them.
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
	 * path, which before 97f2c1f4 instantiated the object.
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
}
