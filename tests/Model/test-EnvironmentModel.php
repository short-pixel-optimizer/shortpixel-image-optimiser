<?php
/**
 * Tests for ShortPixel\Model\EnvironmentModel.
 *
 * Focuses on the pure computation / probe methods and the singleton contract.
 * Methods that require a real WP_Screen, an Offloader instance, an active
 * image editor, or the current_screen hook context are outside the scope of
 * these unit tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\EnvironmentModel;

class EnvironmentModelTest extends WP_UnitTestCase {

	/**
	 * Returns a fresh instance that bypasses the constructor so per-test
	 * property mutations do not leak into the singleton used by other tests.
	 */
	private function freshEnv(): EnvironmentModel {
		$ref = new ReflectionClass( EnvironmentModel::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function invokePrivate( EnvironmentModel $env, string $method, array $args = array() ) {
		$ref = new ReflectionClass( EnvironmentModel::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $env, ...$args );
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = EnvironmentModel::getInstance();
		$b = EnvironmentModel::getInstance();
		$this->assertInstanceOf( EnvironmentModel::class, $a );
		$this->assertSame( $a, $b );
	}

	/*
	 * checkPHPVersion
	 */

	public function test_checkPHPVersion_true_for_older_version() {
		$this->assertTrue( EnvironmentModel::getInstance()->checkPHPVersion( '5.0' ) );
	}

	public function test_checkPHPVersion_true_when_equal_to_current() {
		$this->assertTrue( EnvironmentModel::getInstance()->checkPHPVersion( PHP_VERSION ) );
	}

	public function test_checkPHPVersion_false_for_far_future_version() {
		$this->assertFalse( EnvironmentModel::getInstance()->checkPHPVersion( '99.0' ) );
	}

	/*
	 * useDoubleWebpExtension / useDoubleAvifExtension / useTrustedMode
	 *
	 * These read `define`-time constants. wp-shortpixel.php defines the
	 * WebP / AVIF ones to false as their default; SHORTPIXEL_TRUSTED_MODE is
	 * not defined at all. We only assert the "not enabled" default because
	 * the constants have already been resolved when the plugin loaded — the
	 * "enabled" cases would need a separate PHP process to test.
	 */

	public function test_useDoubleWebpExtension_default_is_false() {
		$this->assertFalse( EnvironmentModel::getInstance()->useDoubleWebpExtension() );
	}

	public function test_useDoubleAvifExtension_default_is_false() {
		$this->assertFalse( EnvironmentModel::getInstance()->useDoubleAvifExtension() );
	}

	public function test_useTrustedMode_default_is_false() {
		$this->assertFalse( EnvironmentModel::getInstance()->useTrustedMode() );
	}

	/*
	 * unitToInt (private) — invoked via reflection
	 */

	public function test_unitToInt_parses_plain_bytes() {
		$this->assertSame( 512, $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '512' ) ) );
	}

	public function test_unitToInt_parses_kilobytes() {
		$this->assertSame( 2 * 1024, $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '2K' ) ) );
	}

	public function test_unitToInt_parses_megabytes() {
		$this->assertSame( 128 * 1024 * 1024, $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '128M' ) ) );
	}

	public function test_unitToInt_parses_gigabytes() {
		$this->assertSame( 1024 * 1024 * 1024, $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '1G' ) ) );
	}

	public function test_unitToInt_returns_minus_one_for_negative_input() {
		$this->assertSame( -1, $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '-1' ) ) );
	}

	public function test_unitToInt_is_case_insensitive_for_unit_suffix() {
		$upper = $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '4M' ) );
		$lower = $this->invokePrivate( $this->freshEnv(), 'unitToInt', array( '4m' ) );
		$this->assertSame( $upper, $lower );
	}

	/*
	 * IsOverTimeLimit
	 *
	 * The check is: elapsed = time() - executionStart, then true when
	 * elapsed >= round(limit * apply_filters('spio/process/max_execution', 90) / 100).
	 * We control executionStart via reflection so we can exercise both
	 * branches deterministically.
	 */

	public function test_IsOverTimeLimit_returns_false_when_limit_is_zero() {
		$env = $this->freshEnv();
		$env->executionStart = time();
		$this->assertFalse( $env->IsOverTimeLimit( array( 'limit' => 0 ) ) );
	}

	public function test_IsOverTimeLimit_returns_false_when_elapsed_is_zero_or_negative() {
		$env = $this->freshEnv();
		// Start "just now" so elapsed can only be 0 or 1 for this fast test.
		$env->executionStart = time();
		$this->assertFalse( $env->IsOverTimeLimit( array( 'limit' => 60 ) ) );
	}

	public function test_IsOverTimeLimit_returns_false_when_elapsed_is_below_threshold() {
		$env = $this->freshEnv();
		// Elapsed = 1 s; threshold at 90% of a 60 s limit is 54 s.
		$env->executionStart = time() - 1;
		$this->assertFalse( $env->IsOverTimeLimit( array( 'limit' => 60 ) ) );
	}

	public function test_IsOverTimeLimit_returns_true_when_elapsed_crosses_threshold() {
		$env = $this->freshEnv();
		// Elapsed = 100 s; threshold at 90% of a 10 s limit is 9 s.
		$env->executionStart = time() - 100;
		$this->assertTrue( $env->IsOverTimeLimit( array( 'limit' => 10 ) ) );
	}

	/*
	 * IsOverMemoryLimit
	 */

	public function test_IsOverMemoryLimit_returns_false_for_unlimited_memory() {
		$env               = $this->freshEnv();
		$env->memoryLimit  = -1;
		$this->assertFalse( $env->IsOverMemoryLimit( 0 ) );
	}

	public function test_IsOverMemoryLimit_returns_true_when_over_threshold() {
		$env              = $this->freshEnv();
		// Any real process uses more than 1 KB, so a 1 KB cap is guaranteed to trip.
		$env->memoryLimit = 1024;
		$this->assertTrue( $env->IsOverMemoryLimit( 0 ) );
	}

	public function test_IsOverMemoryLimit_returns_false_when_under_threshold() {
		$env              = $this->freshEnv();
		// 16 GB cap — PHPUnit will never come close to using that in a single test.
		$env->memoryLimit = 16 * 1024 * 1024 * 1024;
		$this->assertFalse( $env->IsOverMemoryLimit( 0 ) );
	}

	/*
	 * plugin_active
	 */

	public function test_plugin_active_returns_false_for_unknown_plugin_alias() {
		$this->assertFalse( EnvironmentModel::getInstance()->plugin_active( 'not-a-known-alias' ) );
	}

	public function test_plugin_active_returns_true_when_matching_slug_is_in_active_plugins() {
		$previous = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );

		try {
			$this->assertTrue( EnvironmentModel::getInstance()->plugin_active( 'woocommerce' ) );
		} finally {
			update_option( 'active_plugins', $previous );
		}
	}

	public function test_plugin_active_returns_false_when_slug_is_not_active() {
		$previous = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array() );

		try {
			$this->assertFalse( EnvironmentModel::getInstance()->plugin_active( 'woocommerce' ) );
		} finally {
			update_option( 'active_plugins', $previous );
		}
	}

	public function test_plugin_active_scoped_type_only_checks_that_variant() {
		$previous = get_option( 'active_plugins', array() );
		// Activate the "lite" envira but ask for the "pro" variant only.
		update_option( 'active_plugins', array( 'envira-gallery-lite/envira-gallery-lite.php' ) );

		try {
			$this->assertTrue(  EnvironmentModel::getInstance()->plugin_active( 'envira' ) );
			$this->assertTrue(  EnvironmentModel::getInstance()->plugin_active( 'envira', 'lite' ) );
			$this->assertFalse( EnvironmentModel::getInstance()->plugin_active( 'envira', 'pro' ) );
		} finally {
			update_option( 'active_plugins', $previous );
		}
	}

	/*
	 * is_function_usable
	 */

	public function test_is_function_usable_true_for_ubiquitous_php_function() {
		$this->assertTrue( EnvironmentModel::getInstance()->is_function_usable( 'sprintf' ) );
	}

	public function test_is_function_usable_false_for_undefined_function() {
		$this->assertFalse( EnvironmentModel::getInstance()->is_function_usable( 'this_function_does_not_exist_' . uniqid() ) );
	}

	/*
	 * getRelativePluginSlug
	 */

	public function test_getRelativePluginSlug_returns_folder_and_file_pair() {
		$slug = EnvironmentModel::getInstance()->getRelativePluginSlug();
		$this->assertIsString( $slug );
		$this->assertStringEndsWith( '.php', $slug );
		// Must include a "/" so callers can safely feed the value to is_plugin_active().
		$this->assertStringContainsString( '/', $slug );
	}

	/*
	 * Property probes — verify setServer / setWordPress populated the
	 * environment fields with correctly-typed values in the test harness.
	 */

	public function test_setServer_populates_typed_environment_fields() {
		$env = EnvironmentModel::getInstance();
		$this->assertIsBool( $env->is_gd_installed );
		$this->assertIsBool( $env->is_imagick_installed );
		$this->assertIsBool( $env->is_curl_installed );
		$this->assertIsBool( $env->is_nginx );
		$this->assertIsBool( $env->is_apache );
	}

	public function test_setServer_captures_execution_start_and_limit() {
		$env = EnvironmentModel::getInstance();
		$this->assertIsInt( $env->executionStart );
		$this->assertIsInt( $env->executionLimit );
		$this->assertLessThanOrEqual( time(), $env->executionStart );
	}

	public function test_setServer_captures_memory_limit_as_integer() {
		$env = EnvironmentModel::getInstance();
		$this->assertIsInt( $env->memoryLimit );
	}

	public function test_setWordPress_populates_multisite_flags() {
		$env = EnvironmentModel::getInstance();
		$this->assertIsBool( $env->is_multisite );
		$this->assertIsBool( $env->is_mainsite );
	}

	public function test_setWordPress_populates_request_context_flags() {
		$env = EnvironmentModel::getInstance();
		$this->assertIsBool( $env->is_ajaxcall );
		$this->assertIsBool( $env->is_jsoncall );
		$this->assertIsBool( $env->is_croncall );
	}
}
