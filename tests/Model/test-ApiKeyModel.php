<?php
/**
 * Tests for ShortPixel\Model\ApiKeyModel.
 *
 * Focuses on the branches that don't need a live network round-trip or a
 * process-terminating redirect. Reflection is used to bypass the constructor
 * and to reach the protected update / clearApiKey / NoticeApiKeyLength methods
 * without going through the singleton wiring in ApiKeyController.
 *
 * Skipped at the unit level (integration territory):
 *   - validateKey() / remoteValidate()    → live HTTP through QuotaController
 *   - processNewKey()                     → depends on a real quotaData response,
 *                                            filesystem->checkBackupFolder() and
 *                                            multisite/site-url context
 *   - checkRedirect() success path        → calls wp_safe_redirect() + exit();
 *                                            requires _die_handler / wp_redirect
 *                                            filter to catch
 *   - checkKey() empty-key clear branch   → transitively calls checkRedirect
 *                                            before clearApiKey; covered by
 *                                            integration tests
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\ApiKeyModel;

class ApiKeyModelTest extends WP_UnitTestCase {

	/**
	 * WordPress option name used by ApiKeyModel to persist its state.
	 */
	private const OPTION_NAME = 'spio_key';

	/**
	 * Legacy per-field option names (pre-consolidated storage).
	 */
	private const LEGACY_KEY_OPTION      = 'wp-short-pixel-apiKey';
	private const LEGACY_VERIFIED_OPTION = 'wp-short-pixel-verifiedKey';
	private const LEGACY_TRIED_OPTION    = 'wp-short-pixel-apiKeyTried';

	/**
	 * A well-formed 20-character key used across happy-path tests.
	 */
	private const VALID_KEY = 'ABCDEFGHIJKLMNOPQRST';

	/**
	 * Saved value of \wpSPIO()->settings()->redirectedSettings, restored in tear_down.
	 * @var mixed
	 */
	private $savedRedirectedSettings;

	public function set_up() {
		parent::set_up();

		// Clear both consolidated and legacy option storage so each test starts clean.
		delete_option( self::OPTION_NAME );
		delete_option( self::LEGACY_KEY_OPTION );
		delete_option( self::LEGACY_VERIFIED_OPTION );
		delete_option( self::LEGACY_TRIED_OPTION );

		// Reset the per-request notice-dedup flag on the class.
		$this->setStaticNotified( array() );

		// Prevent checkRedirect() from ever hitting wp_safe_redirect()+exit()
		// during a test: any truthy redirectedSettings short-circuits the guard.
		$this->savedRedirectedSettings = \wpSPIO()->settings()->redirectedSettings;
		\wpSPIO()->settings()->redirectedSettings = 1;
	}

	public function tear_down() {
		delete_option( self::OPTION_NAME );
		delete_option( self::LEGACY_KEY_OPTION );
		delete_option( self::LEGACY_VERIFIED_OPTION );
		delete_option( self::LEGACY_TRIED_OPTION );

		$this->setStaticNotified( array() );

		\wpSPIO()->settings()->redirectedSettings = $this->savedRedirectedSettings;

		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	/**
	 * Returns a fresh instance with the constructor bypassed and the two
	 * "reads a constant" flags set explicitly so tests are not coupled to
	 * whatever SHORTPIXEL_API_KEY / SHORTPIXEL_HIDE_API_KEY happen to be in
	 * the current process.
	 */
	private function freshModel( bool $isConstant = false, bool $isHidden = false ): ApiKeyModel {
		$ref = new ReflectionClass( ApiKeyModel::class );
		$m   = $ref->newInstanceWithoutConstructor();

		$this->setPrivate( $m, 'key_is_constant', $isConstant );
		$this->setPrivate( $m, 'key_is_hidden', $isHidden );
		$this->setPrivate( $m, 'key_is_verified', false );
		$this->setPrivate( $m, 'key_is_empty', false );
		$this->setPrivate( $m, 'apiKey', '' );
		$this->setPrivate( $m, 'verifiedKey', false );
		$this->setPrivate( $m, 'apiKeyTried', null );

		return $m;
	}

	private function setPrivate( ApiKeyModel $m, string $prop, $value ): void {
		$ref = new ReflectionClass( ApiKeyModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $m, $value );
	}

	private function getPrivate( ApiKeyModel $m, string $prop ) {
		$ref = new ReflectionClass( ApiKeyModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function invokePrivate( ApiKeyModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( ApiKeyModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	private function setStaticNotified( array $value ): void {
		$ref = new ReflectionClass( ApiKeyModel::class );
		$p   = $ref->getProperty( 'notified' );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}

	private function getStaticNotified(): array {
		$ref = new ReflectionClass( ApiKeyModel::class );
		$p   = $ref->getProperty( 'notified' );
		$p->setAccessible( true );
		return $p->getValue();
	}

	/*
	 * Constructor — the only pure invariant we can assert is that the
	 * key_is_constant / key_is_hidden flags reflect the current constants.
	 */

	public function test_constructor_reflects_shortpixel_api_key_constant() {
		$m = new ApiKeyModel();
		$this->assertSame( defined( 'SHORTPIXEL_API_KEY' ), $m->is_constant() );
	}

	public function test_constructor_reflects_shortpixel_hide_api_key_constant() {
		$m        = new ApiKeyModel();
		$expected = defined( 'SHORTPIXEL_HIDE_API_KEY' ) ? (bool) SHORTPIXEL_HIDE_API_KEY : false;
		$this->assertSame( $expected, $m->is_hidden() );
	}

	/*
	 * Simple getters
	 */

	public function test_is_verified_returns_runtime_flag() {
		$m = $this->freshModel();
		$this->assertFalse( $m->is_verified() );

		$this->setPrivate( $m, 'key_is_verified', true );
		$this->assertTrue( $m->is_verified() );
	}

	public function test_is_constant_returns_property() {
		$this->assertTrue( $this->freshModel( true, false )->is_constant() );
		$this->assertFalse( $this->freshModel( false, false )->is_constant() );
	}

	public function test_is_hidden_returns_property() {
		$this->assertTrue( $this->freshModel( false, true )->is_hidden() );
		$this->assertFalse( $this->freshModel( false, false )->is_hidden() );
	}

	public function test_getKey_returns_stored_api_key() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->assertSame( self::VALID_KEY, $m->getKey() );
	}

	public function test_getKey_returns_empty_string_when_unset() {
		$this->assertSame( '', $this->freshModel()->getKey() );
	}

	/*
	 * update() — persistence roundtrip
	 */

	public function test_update_persists_all_three_fields_to_spio_key_option() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'verifiedKey', true );
		$this->setPrivate( $m, 'apiKeyTried', 'triedvalue' );

		$this->assertTrue( $this->invokePrivate( $m, 'update' ) );

		$stored = get_option( self::OPTION_NAME );
		$this->assertIsArray( $stored );
		$this->assertSame( self::VALID_KEY, $stored['apiKey'] );
		$this->assertTrue( $stored['verifiedKey'] );
		$this->assertSame( 'triedvalue', $stored['apiKeyTried'] );
	}

	public function test_update_trims_api_key_before_saving() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', "  " . self::VALID_KEY . "\n" );
		$this->setPrivate( $m, 'verifiedKey', false );
		$this->setPrivate( $m, 'apiKeyTried', '' );

		$this->invokePrivate( $m, 'update' );

		$stored = get_option( self::OPTION_NAME );
		$this->assertSame( self::VALID_KEY, $stored['apiKey'] );
	}

	/*
	 * resetTried()
	 */

	public function test_resetTried_no_op_when_already_null() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKeyTried', null );

		$m->resetTried();

		// No option should have been written.
		$this->assertFalse( get_option( self::OPTION_NAME, false ) );
	}

	public function test_resetTried_clears_field_and_persists() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'apiKeyTried', 'stale-tried-key' );

		$m->resetTried();

		$this->assertNull( $this->getPrivate( $m, 'apiKeyTried' ) );

		$stored = get_option( self::OPTION_NAME );
		$this->assertIsArray( $stored );
		$this->assertNull( $stored['apiKeyTried'] );
	}

	/*
	 * NoticeApiKeyLength — idempotency contract via the static $notified flag.
	 */

	public function test_NoticeApiKeyLength_only_flags_once_per_request() {
		$m = $this->freshModel();

		$this->invokePrivate( $m, 'NoticeApiKeyLength', array( 'short' ) );
		$notified = $this->getStaticNotified();
		$this->assertArrayHasKey( 'apilength', $notified );
		$this->assertTrue( $notified['apilength'] );

		// Second call must be a no-op — flag is already set.
		$this->invokePrivate( $m, 'NoticeApiKeyLength', array( 'still-short' ) );
		$this->assertTrue( $this->getStaticNotified()['apilength'] );
	}

	/*
	 * clearApiKey() / uninstall()
	 */

	public function test_clearApiKey_resets_state_and_deletes_options() {
		// Seed both consolidated and legacy storage so we can assert deletion of both.
		update_option( self::OPTION_NAME, array(
			'apiKey'      => self::VALID_KEY,
			'verifiedKey' => true,
			'apiKeyTried' => 'tried',
		) );
		update_option( self::LEGACY_KEY_OPTION, self::VALID_KEY );
		update_option( self::LEGACY_VERIFIED_OPTION, true );
		update_option( self::LEGACY_TRIED_OPTION, 'legacy-tried' );

		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'verifiedKey', true );
		$this->setPrivate( $m, 'apiKeyTried', 'tried' );
		$this->setPrivate( $m, 'key_is_verified', true );

		$this->invokePrivate( $m, 'clearApiKey' );

		$this->assertSame( '', $this->getPrivate( $m, 'apiKey' ) );
		$this->assertFalse( $this->getPrivate( $m, 'verifiedKey' ) );
		$this->assertSame( '', $this->getPrivate( $m, 'apiKeyTried' ) );
		$this->assertFalse( $this->getPrivate( $m, 'key_is_verified' ) );
		$this->assertTrue( $this->getPrivate( $m, 'key_is_empty' ) );

		$this->assertFalse( get_option( self::OPTION_NAME, false ) );
		$this->assertFalse( get_option( self::LEGACY_KEY_OPTION, false ) );
		$this->assertFalse( get_option( self::LEGACY_VERIFIED_OPTION, false ) );
		$this->assertFalse( get_option( self::LEGACY_TRIED_OPTION, false ) );
	}

	public function test_uninstall_delegates_to_clearApiKey() {
		update_option( self::OPTION_NAME, array(
			'apiKey'      => self::VALID_KEY,
			'verifiedKey' => true,
			'apiKeyTried' => 'tried',
		) );

		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'verifiedKey', true );

		$m->uninstall();

		$this->assertSame( '', $this->getPrivate( $m, 'apiKey' ) );
		$this->assertFalse( $this->getPrivate( $m, 'verifiedKey' ) );
		$this->assertFalse( get_option( self::OPTION_NAME, false ) );
	}

	/*
	 * loadKey() — migration + happy paths only.
	 * The full "unknown key → validateKey" path hits live HTTP and is covered
	 * by integration tests.
	 */

	public function test_loadKey_migrates_legacy_options_to_consolidated_option() {
		// No consolidated option yet — only the three legacy ones.
		update_option( self::LEGACY_KEY_OPTION, self::VALID_KEY );
		update_option( self::LEGACY_VERIFIED_OPTION, true );
		update_option( self::LEGACY_TRIED_OPTION, '' );

		$m = $this->freshModel();

		$this->assertTrue( $m->loadKey() );

		$stored = get_option( self::OPTION_NAME );
		$this->assertIsArray( $stored );
		$this->assertSame( self::VALID_KEY, $stored['apiKey'] );
		$this->assertTrue( $stored['verifiedKey'] );

		// Legacy options must be cleared as part of the migration.
		$this->assertFalse( get_option( self::LEGACY_KEY_OPTION, false ) );
		$this->assertFalse( get_option( self::LEGACY_VERIFIED_OPTION, false ) );
		$this->assertFalse( get_option( self::LEGACY_TRIED_OPTION, false ) );
	}

	public function test_loadKey_reads_from_consolidated_option_when_present() {
		update_option( self::OPTION_NAME, array(
			'apiKey'      => self::VALID_KEY,
			'verifiedKey' => true,
			'apiKeyTried' => '',
		) );

		$m = $this->freshModel();

		$this->assertTrue( $m->loadKey() );
		$this->assertSame( self::VALID_KEY, $m->getKey() );
		$this->assertTrue( $m->is_verified() );
	}

	public function test_loadKey_returns_false_when_no_key_stored_and_hidden() {
		// hidden + empty key should short-circuit inside checkKey and return
		// the stored verifiedKey — which is false because nothing is stored.
		update_option( self::OPTION_NAME, array(
			'apiKey'      => '',
			'verifiedKey' => false,
			'apiKeyTried' => '',
		) );

		$m = $this->freshModel( false, true );

		$this->assertFalse( $m->loadKey() );
	}

	/*
	 * checkKey() — safe branches only.
	 */

	public function test_checkKey_returns_true_when_key_matches_verified_stored() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'verifiedKey', true );

		$this->assertTrue( $m->checkKey( self::VALID_KEY ) );
		$this->assertTrue( $m->is_verified() );
	}

	public function test_checkKey_wrong_length_matching_apiKeyTried_is_silent_noop() {
		$m = $this->freshModel();
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'verifiedKey', true );
		$this->setPrivate( $m, 'apiKeyTried', 'shortkey' );

		// Key differs from stored, wrong length, but equals apiKeyTried — no
		// notice must be raised (self::$notified['apilength'] stays unset) and
		// $valid stays false, so the final "not valid" branch runs and marks
		// key_is_verified as false.
		$this->assertFalse( $m->checkKey( 'shortkey' ) );
		$this->assertArrayNotHasKey( 'apilength', $this->getStaticNotified() );
	}

	public function test_checkKey_wrong_length_with_constant_key_keeps_valid_false_and_notifies() {
		$m = $this->freshModel( true, false );
		$this->setPrivate( $m, 'apiKey', self::VALID_KEY );
		$this->setPrivate( $m, 'verifiedKey', true );
		// apiKeyTried unset so the notice branch fires.

		$this->assertFalse( $m->checkKey( 'shortkey' ) );
		$this->assertArrayHasKey( 'apilength', $this->getStaticNotified() );
		$this->assertFalse( $m->is_verified() );

		// checkKey's tail persists apiKeyTried so the same wrong key isn't
		// re-checked next request.
		$this->assertSame( 'shortkey', $this->getPrivate( $m, 'apiKeyTried' ) );
	}

	public function test_checkKey_empty_key_when_hidden_returns_stored_verified_state() {
		$m = $this->freshModel( false, true );
		$this->setPrivate( $m, 'verifiedKey', true );

		$this->assertTrue( $m->checkKey( '' ) );
		$this->assertTrue( $m->is_verified() );
	}
}
