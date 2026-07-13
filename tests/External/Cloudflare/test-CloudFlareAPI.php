<?php
/**
 * Tests for ShortPixel\CloudFlareAPI — the Cloudflare edge-cache
 * purge integration fired from `shortpixel/image/optimised` and
 * `shortpixel/image/before_restore`.
 *
 * Focus areas:
 *   - Constructor registers BOTH hooks (optimised + before_restore)
 *   - Property defaults (setup_done, config_ok, use_token, api_url)
 *   - setup() config resolution:
 *      · Empty settings   → config_ok=false, use_token=false
 *      · Both credentials → config_ok=true, use_token=true, values cached
 *      · Partial config   → config_ok=false (both credentials required)
 *   - check_cloudflare() runs setup() lazily on first hook fire
 *   - check_cloudflare() short-circuits when config_ok is false —
 *     sentinelled by passing a stdClass that would throw if the
 *     private URL-collection method was reached
 *   - addAuth() Bearer path adds the Authorization header and
 *     preserves any caller-provided headers
 *
 * Skipped at the unit level (integration territory):
 *   - start_cloudflare_cache_purge_process — private; requires a
 *     real image model with getURL / getWebp / getAvif / thumbnails
 *   - delete_url_cache_request_action → doRequest — hits raw cURL,
 *     not the WP HTTP API, so there's no pre_http_request filter to
 *     intercept
 *   - Constant-driven setup (SHORTPIXEL_CFZONE / SHORTPIXEL_CFTOKEN)
 *     — defines can't be un-defined, would leak across the process
 *   - addAuth() legacy (`use_token=false`) branch — dead code that
 *     references undeclared `$this->email` / `$this->authkey`
 *     properties (flagged in the deferred-bugs memo)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\CloudFlareAPI;

class CloudFlareAPITest extends WP_UnitTestCase {

	private $savedZoneID;
	private $savedToken;

	public function set_up() {
		parent::set_up();

		// Snapshot settings so per-test mutation doesn't leak.
		$settings          = \wpSPIO()->settings();
		$this->savedZoneID = $settings->cloudflareZoneID;
		$this->savedToken  = $settings->cloudflareToken;

		// Clear to empty so setup() sees an "unconfigured" install by
		// default. Individual tests re-populate as needed.
		$settings->cloudflareZoneID = '';
		$settings->cloudflareToken  = '';
	}

	public function tear_down() {
		$settings                    = \wpSPIO()->settings();
		$settings->cloudflareZoneID  = $this->savedZoneID;
		$settings->cloudflareToken   = $this->savedToken;

		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function getPrivate( CloudFlareAPI $c, string $prop ) {
		$ref = new ReflectionClass( CloudFlareAPI::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function setPrivate( CloudFlareAPI $c, string $prop, $value ): void {
		$ref = new ReflectionClass( CloudFlareAPI::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $c, $value );
	}

	private function invokePrivate( CloudFlareAPI $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( CloudFlareAPI::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	/*
	 * Constructor — registers both hooks
	 */

	public function test_constructor_registers_the_optimised_hook_on_this_instance() {
		$c = new CloudFlareAPI();

		// has_action( $hook, $callback ) returns priority (int) or
		// false. Checking against THIS specific instance's callback
		// proves OUR constructor registered it — the file-load-boot
		// instance's registration wouldn't match `[$c, ...]`.
		$this->assertNotFalse( has_action( 'shortpixel/image/optimised', array( $c, 'check_cloudflare' ) ) );
	}

	public function test_constructor_registers_the_before_restore_hook_on_this_instance() {
		$c = new CloudFlareAPI();

		// Sentinel-pair with the optimised test: BOTH hooks matter
		// because restoring an image also invalidates the edge, and a
		// regression that only fired on optimise would leave stale
		// bytes at Cloudflare after every restore.
		$this->assertNotFalse( has_action( 'shortpixel/image/before_restore', array( $c, 'check_cloudflare' ) ) );
	}

	/*
	 * Property defaults — pin the initial state so lazy-setup and
	 * config-guarded branches all have known starting values.
	 */

	public function test_freshly_constructed_instance_has_not_run_setup_yet() {
		$c = new CloudFlareAPI();

		// Sentinel: proves setup() is truly lazy. A regression that
		// moved setup() into __construct would flip this true and
		// break the "no settings read at file-load" contract.
		$this->assertFalse( $this->getPrivate( $c, 'setup_done' ) );
		$this->assertFalse( $this->getPrivate( $c, 'config_ok' ) );
		$this->assertFalse( $this->getPrivate( $c, 'use_token' ) );
	}

	public function test_freshly_constructed_instance_has_the_v4_api_url_base() {
		$c = new CloudFlareAPI();

		// The URL is load-bearing — the whole class targets this
		// endpoint. A silent regression to v3 or a typo'd host would
		// send purge requests into the void.
		$this->assertSame(
			'https://api.cloudflare.com/client/v4/zones/',
			$this->getPrivate( $c, 'api_url' )
		);
	}

	/*
	 * setup() — credential resolution
	 */

	public function test_setup_leaves_config_not_ok_when_settings_are_empty() {
		$c = new CloudFlareAPI();
		$c->setup();

		$this->assertTrue( $this->getPrivate( $c, 'setup_done' ), 'setup_done should always flip' );
		$this->assertFalse( $this->getPrivate( $c, 'config_ok' ) );
		$this->assertFalse( $this->getPrivate( $c, 'use_token' ) );
	}

	public function test_setup_marks_config_ok_and_token_mode_when_both_credentials_are_present() {
		\wpSPIO()->settings()->cloudflareZoneID = 'zone-abc-123';
		\wpSPIO()->settings()->cloudflareToken  = 'super-secret-token';

		$c = new CloudFlareAPI();
		$c->setup();

		$this->assertTrue( $this->getPrivate( $c, 'setup_done' ) );
		$this->assertTrue( $this->getPrivate( $c, 'config_ok' ) );
		$this->assertTrue( $this->getPrivate( $c, 'use_token' ) );
		// Sentinel: verify credentials are actually cached on the
		// instance — a regression that flipped the flags without
		// storing values would still pass the flag assertions but
		// send requests with empty auth.
		$this->assertSame( 'zone-abc-123', $this->getPrivate( $c, 'zone_id' ) );
		$this->assertSame( 'super-secret-token', $this->getPrivate( $c, 'token' ) );
	}

	public function test_setup_leaves_config_not_ok_when_only_the_zone_id_is_set() {
		\wpSPIO()->settings()->cloudflareZoneID = 'zone-abc-123';
		// cloudflareToken stays empty from set_up().

		$c = new CloudFlareAPI();
		$c->setup();

		// Sentinel pair (this + the token-only variant below): both
		// credentials are required, not just one. A regression that
		// used OR instead of AND would happily pass on partial config.
		$this->assertFalse( $this->getPrivate( $c, 'config_ok' ) );
	}

	public function test_setup_leaves_config_not_ok_when_only_the_token_is_set() {
		\wpSPIO()->settings()->cloudflareToken = 'super-secret-token';
		// cloudflareZoneID stays empty from set_up().

		$c = new CloudFlareAPI();
		$c->setup();

		$this->assertFalse( $this->getPrivate( $c, 'config_ok' ) );
	}

	/*
	 * check_cloudflare() — lazy setup + config guard
	 */

	public function test_check_cloudflare_runs_setup_on_first_call_when_not_yet_done() {
		$c = new CloudFlareAPI();
		$this->assertFalse( $this->getPrivate( $c, 'setup_done' ), 'test setup issue: setup_done should start false' );

		// Passing stdClass is safe here because config_ok stays false
		// (settings are empty in set_up), so the purge branch never
		// touches the object. We only care about the setup_done flip.
		$c->check_cloudflare( new stdClass() );

		$this->assertTrue( $this->getPrivate( $c, 'setup_done' ) );
	}

	public function test_check_cloudflare_short_circuits_when_config_ok_is_false() {
		$c = new CloudFlareAPI();

		// Force post-setup state with config_ok=false so the config
		// guard is what we're testing (not the setup_done guard).
		$this->setPrivate( $c, 'setup_done', true );
		$this->setPrivate( $c, 'config_ok', false );

		// stdClass has no getURL/getWebp/getAvif/get/etc — if the
		// purge branch were reached, `$imageItem->getURL()` inside
		// start_cloudflare_cache_purge_process would throw
		// `Error: Call to undefined method`. The absence of that
		// exception is the sentinel that the guard held.
		$c->check_cloudflare( new stdClass() );

		$this->assertTrue( true, 'Reached this point → config guard short-circuited correctly' );
	}

	/*
	 * addAuth() — Bearer path only (legacy branch is dead code)
	 */

	public function test_addAuth_adds_bearer_authorization_header_when_use_token_is_true() {
		$c = new CloudFlareAPI();
		$this->setPrivate( $c, 'use_token', true );
		$this->setPrivate( $c, 'token', 'super-secret-token' );

		$result = $this->invokePrivate( $c, 'addAuth', array( array() ) );

		$this->assertArrayHasKey( 'authorization', $result );
		// Sentinel: assert the FULL header value (not just presence)
		// so a regression that emitted the wrong header shape (e.g.
		// dropped the `Authorization:` prefix that doRequest needs
		// for CURLOPT_HTTPHEADER) would fail here.
		$this->assertSame( 'Authorization: Bearer super-secret-token', $result['authorization'] );
	}

	public function test_addAuth_preserves_caller_supplied_headers_when_adding_bearer_auth() {
		$c = new CloudFlareAPI();
		$this->setPrivate( $c, 'use_token', true );
		$this->setPrivate( $c, 'token', 'super-secret-token' );

		$existing = array(
			'content_type' => 'Content-Type: application/json',
			'x-custom'     => 'X-Custom: yes',
		);
		$result = $this->invokePrivate( $c, 'addAuth', array( $existing ) );

		// Sentinel: verify BOTH pre-existing entries are still there
		// AND the new auth entry was appended. A regression that
		// returned `array('authorization' => …)` without the merge
		// would fail this test (but pass the previous one).
		$this->assertSame( 'Content-Type: application/json', $result['content_type'] );
		$this->assertSame( 'X-Custom: yes', $result['x-custom'] );
		$this->assertSame( 'Authorization: Bearer super-secret-token', $result['authorization'] );
	}
}
