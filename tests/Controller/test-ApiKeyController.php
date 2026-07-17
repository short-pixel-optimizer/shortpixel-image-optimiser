<?php
/**
 * Tests for ShortPixel\Controller\ApiKeyController.
 *
 * Scope: singleton contract, delegation accessors (getKeyForDisplay,
 * forceGetApiKey, keyIsVerified, getKeyModel), and the round-trip between
 * stored option state and the controller's public API. Uses the real WordPress
 * options table, which WP_UnitTestCase rolls back after each test.
 *
 * Out of scope / why:
 * - load() → ApiKeyModel::loadKey() → checkKey() → validateKey() → remoteValidate()
 *   hits the live ShortPixel API; skipped entirely to avoid network calls.
 * - uninstall() / uninstallPlugin() delete options and fire admin notices;
 *   teardown is handled automatically by WP_UnitTestCase, making these
 *   effectively integration-level operations already covered by install tests.
 * - The SHORTPIXEL_API_KEY / SHORTPIXEL_HIDE_API_KEY constants cannot be
 *   defined after the test process started, so constant-based branches are
 *   untestable from userland in this environment.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Model\ApiKeyModel;

class ApiKeyControllerTest extends WP_UnitTestCase {

	/** Reset the singleton and the spio_key option before every test. */
	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		// Seed a known option state: no key stored.
		delete_option( 'spio_key' );
	}

	public function tear_down() {
		$this->resetSingleton();
		delete_option( 'spio_key' );
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( ApiKeyController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/**
	 * Seed a stored key that will look "already verified" so loadKey() does not
	 * try to call the remote API.
	 *
	 * A stored verifiedKey=true + a key that equals apiKeyTried means checkKey()
	 * follows the "all is fine" branch and returns $this->verifiedKey.
	 *
	 * For keys whose length != 20 characters checkKey() fires NoticeApiKeyLength
	 * which calls Notice::addError — tolerable in a test context.
	 */
	private function seedVerifiedKey( string $key = 'AAAABBBBCCCCDDDDEEEE' ): void {
		update_option( 'spio_key', array(
			'apiKey'      => $key,
			'verifiedKey' => true,
			'apiKeyTried' => $key,
		) );
	}

	/*
	 * getInstance — singleton contract
	 */

	public function test_getInstance_returns_api_key_controller_instance() {
		$this->seedVerifiedKey();
		$ctrl = ApiKeyController::getInstance();
		$this->assertInstanceOf( ApiKeyController::class, $ctrl );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$this->seedVerifiedKey();
		$a = ApiKeyController::getInstance();
		$b = ApiKeyController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * getKeyModel
	 */

	public function test_getKeyModel_returns_api_key_model() {
		$this->seedVerifiedKey();
		$ctrl = ApiKeyController::getInstance();
		$this->assertInstanceOf( ApiKeyModel::class, $ctrl->getKeyModel() );
	}

	/*
	 * keyIsVerified — delegates to ApiKeyModel::is_verified()
	 */

	public function test_keyIsVerified_returns_true_when_stored_key_is_verified() {
		$this->seedVerifiedKey();
		$ctrl = ApiKeyController::getInstance();
		$this->assertTrue( $ctrl->keyIsVerified() );
	}

	public function test_keyIsVerified_returns_false_when_no_key_is_stored() {
		// No option seeded — empty key → checkKey returns false.
		$ctrl = ApiKeyController::getInstance();
		$this->assertFalse( $ctrl->keyIsVerified() );
	}

	/*
	 * forceGetApiKey
	 */

	public function test_forceGetApiKey_returns_stored_key_string() {
		$this->seedVerifiedKey( 'AAAABBBBCCCCDDDDEEEE' );
		$ctrl = ApiKeyController::getInstance();
		$this->assertSame( 'AAAABBBBCCCCDDDDEEEE', $ctrl->forceGetApiKey() );
	}

	/**
	 * PINNED — production bug in ApiKeyModel::loadKey() (class/Model/ApiKeyModel.php ~152).
	 *
	 * INTENDED: forceGetApiKey() is documented `@return string` and should return ''
	 *   when no key is stored.
	 *
	 * ACTUAL: When the consolidated option ('wp-short-pixel-apiKey-options') does not
	 *   exist, loadKey() takes the legacy migration branch (line ~150). It reads the
	 *   legacy 'wp-short-pixel-apiKey' option with a default of `false`. The resulting
	 *   `$apikeySettings` array is then ['apiKey' => false, ...]. At line ~168,
	 *   `isset($apikeySettings['apiKey'])` is true (the key exists, value is false),
	 *   so `$this->apiKey` is set to `false`. getKey() / forceGetApiKey() then returns
	 *   `false` instead of the documented `''`.
	 *
	 * FIX: Change the legacy-migration default from `false` to `''`, or add a
	 *   `?? ''` / `?: ''` cast on line ~168.
	 *
	 * This test MUST FAIL until the fix lands.
	 */
	public function test_forceGetApiKey_returns_empty_string_when_no_key_pinned_for_deferred_fix() {
		$ctrl = ApiKeyController::getInstance();
		$this->assertIsString( $ctrl->forceGetApiKey() );
		$this->assertSame( '', $ctrl->forceGetApiKey() );
	}

	/*
	 * getKeyForDisplay
	 */

	public function test_getKeyForDisplay_returns_key_when_not_hidden() {
		$this->seedVerifiedKey( 'AAAABBBBCCCCDDDDEEEE' );
		$ctrl = ApiKeyController::getInstance();

		// key_is_hidden defaults to false (no SHORTPIXEL_HIDE_API_KEY constant).
		$result = $ctrl->getKeyForDisplay();
		$this->assertNotFalse( $result, 'Expected key string, got false (key marked hidden unexpectedly).' );
		$this->assertSame( 'AAAABBBBCCCCDDDDEEEE', $result );
	}

	public function test_getKeyForDisplay_returns_false_when_key_is_hidden() {
		$this->seedVerifiedKey( 'AAAABBBBCCCCDDDDEEEE' );
		$ctrl = ApiKeyController::getInstance();

		// Simulate hidden flag via reflection on the underlying model.
		$model = $ctrl->getKeyModel();
		$ref   = new ReflectionClass( ApiKeyModel::class );
		$p     = $ref->getProperty( 'key_is_hidden' );
		$p->setAccessible( true );
		$p->setValue( $model, true );

		$this->assertFalse( $ctrl->getKeyForDisplay() );
	}
}
