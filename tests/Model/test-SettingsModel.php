<?php
/**
 * Tests for ShortPixel\Model\SettingsModel.
 *
 * Uses two strategies for isolation:
 *
 *   1. A fresh non-singleton instance created via
 *      ReflectionClass::newInstanceWithoutConstructor() so the constructor's
 *      shutdown-hook registration and DB read are skipped. That's enough to
 *      exercise the schema, sanitizer dispatch, magic accessors and the
 *      exists/isset/setIfEmpty/getExport surface without touching the singleton.
 *
 *   2. The real singleton, wrapped in try/finally with save/restore of the
 *      original value, for tests that specifically need the callable AI
 *      defaults (they bind $this in the constructor).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\SettingsModel;

class SettingsModelTest extends WP_UnitTestCase {

	/**
	 * Returns a fresh SettingsModel with the settings array initialised to
	 * empty, so __get falls straight through to defaults.
	 */
	private function freshSettings(): SettingsModel {
		$ref = new ReflectionClass( SettingsModel::class );
		$s   = $ref->newInstanceWithoutConstructor();

		$p = $ref->getProperty( 'settings' );
		$p->setAccessible( true );
		$p->setValue( $s, array() );

		return $s;
	}

	private function setPrivate( SettingsModel $s, string $prop, $value ): void {
		$ref = new ReflectionClass( SettingsModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $s, $value );
	}

	private function getPrivate( SettingsModel $s, string $prop ) {
		$ref = new ReflectionClass( SettingsModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $s );
	}

	private function invokePrivate( SettingsModel $s, string $method, array $args = array() ) {
		$ref = new ReflectionClass( SettingsModel::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $s, ...$args );
	}

	/*
	 * Singleton contract
	 */

	public function test_getInstance_returns_same_instance() {
		$a = SettingsModel::getInstance();
		$b = SettingsModel::getInstance();
		$this->assertInstanceOf( SettingsModel::class, $a );
		$this->assertSame( $a, $b );
	}

	/*
	 * exists() / isset() — model vs stored
	 */

	public function test_exists_true_for_declared_setting() {
		$this->assertTrue( $this->freshSettings()->exists( 'compressionType' ) );
	}

	public function test_exists_false_for_unknown_setting() {
		$this->assertFalse( $this->freshSettings()->exists( 'no_such_setting_' . uniqid() ) );
	}

	public function test_isset_false_when_setting_has_not_been_stored() {
		$this->assertFalse( $this->freshSettings()->isset( 'compressionType' ) );
	}

	public function test_isset_true_after_setting_is_written() {
		$s                    = $this->freshSettings();
		$s->compressionType  = 2;
		$this->assertTrue( $s->isset( 'compressionType' ) );
	}

	/*
	 * getType — reads the 's' field from the model definition
	 */

	public function test_getType_returns_declared_type_for_known_field() {
		$s = $this->freshSettings();
		$this->assertSame( 'int',     $s->getType( 'compressionType' ) );
		$this->assertSame( 'boolean', $s->getType( 'processThumbnails' ) );
		$this->assertSame( 'string',  $s->getType( 'CDNDomain' ) );
	}

	public function test_getType_returns_null_for_unknown_field() {
		$this->assertNull( $this->freshSettings()->getType( 'ghost_field' ) );
	}

	/*
	 * __set / __get roundtrip
	 */

	public function test_set_and_get_int_roundtrips_the_value() {
		$s                   = $this->freshSettings();
		$s->compressionType  = 2;
		$this->assertSame( 2, $s->compressionType );
	}

	public function test_set_and_get_string_roundtrips_the_value() {
		$s            = $this->freshSettings();
		$s->CDNDomain = 'https://example.test/spio';
		$this->assertSame( 'https://example.test/spio', $s->CDNDomain );
	}

	public function test_set_and_get_boolean_coerces_via_sanitize() {
		$s                    = $this->freshSettings();
		$s->processThumbnails = 0; // falsy → false
		$this->assertFalse( $s->processThumbnails );

		$s->processThumbnails = 'yes'; // truthy string → true
		$this->assertTrue( $s->processThumbnails );
	}

	public function test_set_unknown_setting_is_noop() {
		$s                     = $this->freshSettings();
		$s->definitely_unknown = 'discarded';

		$this->assertFalse( $s->isset( 'definitely_unknown' ) );
	}

	/*
	 * Sanitisation clamps: maxlength and max
	 */

	public function test_set_clamps_int_field_via_max() {
		$s                     = $this->freshSettings();
		// ai_limit_alt_chars has max 200.
		$s->ai_limit_alt_chars = 500;
		$this->assertSame( 200, $s->ai_limit_alt_chars );
	}

	public function test_set_truncates_string_field_via_maxlength() {
		$s                = $this->freshSettings();
		// ai_alt_context has maxlength 500.
		$long             = str_repeat( 'a', 800 );
		$s->ai_alt_context = $long;
		$this->assertSame( 500, strlen( $s->ai_alt_context ) );
	}

	/*
	 * Defaults on read
	 */

	public function test_get_returns_scalar_default_when_setting_is_unset() {
		// compressionType has default 1.
		$this->assertSame( 1, $this->freshSettings()->compressionType );
	}

	public function test_get_returns_boolean_default_when_setting_is_unset() {
		// processThumbnails has default true.
		$this->assertTrue( $this->freshSettings()->processThumbnails );
	}

	public function test_get_unknown_setting_returns_null() {
		$s = $this->freshSettings();
		$this->assertNull( $s->totally_unknown_setting );
	}

	/*
	 * Callable defaults — exercised via the real singleton because the
	 * callables bind $this in the constructor. Restore the setting after
	 * the read to keep other tests isolated.
	 */

	public function test_get_ai_general_context_default_executes_callable_and_includes_site_url() {
		$s = SettingsModel::getInstance();
		// If the setting has been persisted, __get returns that; use reflection
		// to unset it so we exercise the callable default path.
		$originalSettings = $this->getPrivate( $s, 'settings' );
		$scratch          = $originalSettings;
		unset( $scratch['ai_general_context'] );
		$this->setPrivate( $s, 'settings', $scratch );

		try {
			$out = $s->ai_general_context;
			$this->assertIsString( $out );
			$this->assertNotEmpty( $out );
			$this->assertStringContainsString( get_bloginfo( 'url' ), $out );
		} finally {
			$this->setPrivate( $s, 'settings', $originalSettings );
		}
	}

	public function test_get_ai_language_default_returns_current_locale() {
		$s = SettingsModel::getInstance();

		$originalSettings = $this->getPrivate( $s, 'settings' );
		$scratch          = $originalSettings;
		unset( $scratch['ai_language'] );
		$this->setPrivate( $s, 'settings', $scratch );

		try {
			$this->assertSame( get_locale(), $s->ai_language );
		} finally {
			$this->setPrivate( $s, 'settings', $originalSettings );
		}
	}

	/*
	 * setIfEmpty
	 */

	public function test_setIfEmpty_writes_when_setting_is_absent() {
		$s = $this->freshSettings();

		$this->assertTrue( $s->setIfEmpty( 'CDNDomain', 'https://only-if-empty.test' ) );
		$this->assertSame( 'https://only-if-empty.test', $s->CDNDomain );
	}

	public function test_setIfEmpty_does_not_overwrite_when_setting_is_present() {
		$s            = $this->freshSettings();
		$s->CDNDomain = 'https://existing.test';

		$this->assertFalse( $s->setIfEmpty( 'CDNDomain', 'https://replaced.test' ) );
		$this->assertSame( 'https://existing.test', $s->CDNDomain );
	}

	public function test_setIfEmpty_returns_false_for_unknown_setting() {
		$s = $this->freshSettings();
		$this->assertFalse( $s->setIfEmpty( 'no_such_field', 'v' ) );
	}

	/*
	 * getExport — filters fields whose model entry carries 'export' => false
	 */

	public function test_getExport_includes_regular_exportable_fields() {
		$s = $this->freshSettings();
		$s->CDNDomain = 'https://exportable.test';

		$out = $s->getExport();
		$this->assertArrayHasKey( 'CDNDomain', $out );
	}

	public function test_getExport_excludes_fields_marked_non_exportable() {
		$s              = $this->freshSettings();
		// currentStats has 'export' => false.
		$s->currentStats = array( 'sample' => 1 );

		$out = $s->getExport();
		$this->assertArrayNotHasKey( 'currentStats',   $out );
		$this->assertArrayNotHasKey( 'quotaExceeded',  $out );
		$this->assertArrayNotHasKey( 'activationDate', $out );
	}

	/*
	 * deleteOption — clears a stored setting from the in-memory state
	 */

	public function test_deleteOption_clears_a_stored_setting() {
		$s = $this->freshSettings();

		// Use a fresh option_name so deleteOption's implicit save() does not
		// touch the real spio_settings row.
		$this->setPrivate( $s, 'option_name', 'spio_settings_test_' . uniqid() );

		$s->CDNDomain = 'https://to-delete.test';
		$this->assertTrue( $s->isset( 'CDNDomain' ) );

		$s->deleteOption( 'CDNDomain' );
		$this->assertFalse( $s->isset( 'CDNDomain' ) );
	}

	/*
	 * check() (protected) — legacy keepExif → exif rename + settings/check filter
	 */

	public function test_check_migrates_legacy_keepExif_to_exif() {
		$s = $this->freshSettings();

		$out = $this->invokePrivate( $s, 'check', array( array( 'keepExif' => 1 ) ) );

		// The legacy key is removed from the returned settings array…
		$this->assertArrayNotHasKey( 'keepExif', $out );
		// …and the value is transposed onto the exif setting via $this->set().
		$this->assertSame( 1, $s->exif );
		// Bug #8 FIXED (867b3573): the migrated value is also present in the
		// RETURNED array (previously only $this->set() was called but $settings['exif']
		// was never written, so the caller's copy did not carry the new key).
		$this->assertArrayHasKey( 'exif', $out );
		$this->assertSame( 1, $out['exif'] );
	}

	public function test_check_dispatches_the_settings_check_filter() {
		$s = $this->freshSettings();

		$filter = function ( $settings ) {
			$settings['__filter_ran'] = true;
			return $settings;
		};
		add_filter( 'shortpixel/settings/check', $filter );

		try {
			$out = $this->invokePrivate( $s, 'check', array( array( 'plain' => 'v' ) ) );
			$this->assertArrayHasKey( '__filter_ran', $out );
			$this->assertTrue( $out['__filter_ran'] );
		} finally {
			remove_filter( 'shortpixel/settings/check', $filter );
		}
	}
}
