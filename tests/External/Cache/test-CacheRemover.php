<?php
/**
 * Tests for ShortPixel\cacheRemover — the third-party cache
 * invalidation orchestrator that fires on
 * `shortpixel/image/optimised`.
 *
 * Focus areas:
 *   - Singleton contract (getInstance)
 *   - addHooks() actually registers the `shortpixel/image/optimised`
 *     action
 *   - checkCaches() in a clean env → all six `$has_*` flags default
 *     to false (none of these plugins are loaded during unit tests)
 *   - flushCache() — filter escape hatch + post-id resolution
 *     (custom = 0, media = attachment id)
 *   - litespeedReset() — the dual URL-shape handling
 *     (`$urls['urls'] ?? $urls`) for MediaLibraryModel vs
 *     CustomImageModel, plus per-URL purge action fan-out
 *
 * Skipped at the unit level (integration territory — need a real
 * third-party plugin loaded):
 *   - removeSuperCache — dead code (call site is commented out in
 *     flushCache)
 *   - removeW3tcCache / removeWpeCache / removeFastestCache /
 *     removeSiteGround — one-line delegations to plugins that
 *     aren't installed in the test environment
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\cacheRemover;

class CacheRemoverTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		// Reset the singleton so getInstance() constructs fresh per
		// test — otherwise the file-load-time boot leaves state we'd
		// have to work around.
		$this->resetSingleton();

		// Remove any leftover hook binding from a previous test's
		// construction pass (WP's action registry persists across
		// tests in the same process).
		remove_all_actions( 'shortpixel/image/optimised' );
	}

	public function tear_down() {
		$this->resetSingleton();
		remove_all_actions( 'shortpixel/image/optimised' );
		remove_all_filters( 'shortpixel/external/flush_cache' );
		remove_all_actions( 'litespeed_purge_url' );

		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( cacheRemover::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private function getProtected( cacheRemover $c, string $prop ) {
		$ref = new ReflectionClass( cacheRemover::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function invokeProtected( cacheRemover $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( cacheRemover::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	/**
	 * Build a minimal image-item stub. `flushCache()` reads `type` and
	 * `id` via `get()`; `litespeedReset()` reads URLs via
	 * `getAllUrls()`. That's the whole contract we need to fake.
	 */
	private function makeImageItem( string $type, int $id, array $urls = array() ): object {
		return new class( $type, $id, $urls ) {
			public $type;
			public $id;
			public $urls;
			public function __construct( $type, $id, $urls ) {
				$this->type = $type;
				$this->id   = $id;
				$this->urls = $urls;
			}
			public function get( $key ) {
				return $this->$key;
			}
			public function getAllUrls() {
				return $this->urls;
			}
		};
	}

	/*
	 * Singleton contract + hook registration
	 */

	public function test_getInstance_returns_a_cacheRemover() {
		$this->assertInstanceOf( cacheRemover::class, cacheRemover::getInstance() );
	}

	public function test_getInstance_returns_the_same_instance_on_repeated_calls() {
		$a = cacheRemover::getInstance();
		$b = cacheRemover::getInstance();

		// Identity, not equality — a regression that returned a fresh
		// instance every call would still pass assertEquals.
		$this->assertSame( $a, $b );
	}

	public function test_constructor_registers_the_shortpixel_image_optimised_action() {
		cacheRemover::getInstance();

		// has_action returns priority (10 by default here) or false.
		// Using assertNotFalse rather than assertSame(10) so a
		// deliberate priority change wouldn't break this test — the
		// contract is "registered", not "at priority 10".
		$this->assertNotFalse( has_action( 'shortpixel/image/optimised' ) );
	}

	public function test_addHooks_can_be_re_run_and_still_hooks_flushCache() {
		$c = cacheRemover::getInstance();
		remove_all_actions( 'shortpixel/image/optimised' );

		$this->assertFalse(
			has_action( 'shortpixel/image/optimised' ),
			'test setup issue: hook still present after remove_all_actions'
		);

		$c->addHooks();

		$this->assertNotFalse( has_action( 'shortpixel/image/optimised' ) );
	}

	/*
	 * checkCaches() — every $has_* flag stays false in a clean env
	 * (none of these plugins are loaded during unit tests). Six
	 * sentinels because each corresponds to a distinct cache-plugin
	 * detection path.
	 */

	public function test_checkCaches_leaves_every_has_flag_false_in_a_clean_test_environment() {
		$c = cacheRemover::getInstance();
		$c->checkCaches();

		$this->assertFalse( $this->getProtected( $c, 'has_supercache' ), 'has_supercache leaked' );
		$this->assertFalse( $this->getProtected( $c, 'has_w3tc' ), 'has_w3tc leaked' );
		$this->assertFalse( $this->getProtected( $c, 'has_wpengine' ), 'has_wpengine leaked' );
		$this->assertFalse( $this->getProtected( $c, 'has_fastestcache' ), 'has_fastestcache leaked' );
		$this->assertFalse( $this->getProtected( $c, 'has_siteground' ), 'has_siteground leaked' );

		// LiteSpeed detection is a `defined('LSCWP_DIR')` check. If
		// some other test in the process happens to define that
		// constant, this assertion would flake — accepted risk for now
		// because no other test-suite file references LSCWP_DIR.
		$this->assertFalse( $this->getProtected( $c, 'has_litespeed' ), 'has_litespeed leaked' );
	}

	/*
	 * flushCache() — filter escape hatch + post-id resolution
	 */

	public function test_flushCache_returns_false_when_the_escape_hatch_filter_returns_false() {
		$c = cacheRemover::getInstance();
		add_filter( 'shortpixel/external/flush_cache', '__return_false' );

		$result = $c->flushCache( $this->makeImageItem( 'media', 42 ) );

		// Sentinel: strict `assertSame( false, ... )` (not
		// `assertFalse`) so a regression returning null / 0 / '' —
		// which would still be falsy but wouldn't fire the intended
		// "operator vetoed" contract — is caught.
		$this->assertSame( false, $result );
	}

	public function test_flushCache_passes_zero_as_post_id_for_custom_media_items() {
		$c = cacheRemover::getInstance();

		$captured = array();
		add_filter(
			'shortpixel/external/flush_cache',
			function ( $bool, $post_id, $item ) use ( &$captured ) {
				$captured[] = array( 'post_id' => $post_id, 'item' => $item );
				return false; // veto so downstream cache calls don't fire
			},
			10,
			3
		);

		$item = $this->makeImageItem( 'custom', 99 );
		$c->flushCache( $item );

		$this->assertCount( 1, $captured );
		// Sentinel: type-strict comparison + a distinct id (99) so a
		// regression that accidentally returns `$imageItem->get('id')`
		// for custom items — which would happen to be 99, NOT 0 — is
		// caught.
		$this->assertSame( 0, $captured[0]['post_id'] );
		$this->assertSame( $item, $captured[0]['item'] );
	}

	public function test_flushCache_passes_the_attachment_id_as_post_id_for_media_library_items() {
		$c = cacheRemover::getInstance();

		$captured = array();
		add_filter(
			'shortpixel/external/flush_cache',
			function ( $bool, $post_id, $item ) use ( &$captured ) {
				$captured[] = array( 'post_id' => $post_id );
				return false;
			},
			10,
			3
		);

		$c->flushCache( $this->makeImageItem( 'media', 42 ) );

		// Pair with the previous test — 42 for media (non-zero, distinct
		// from the custom-media 0 branch). Two positive sentinels beat
		// one negative one.
		$this->assertSame( 42, $captured[0]['post_id'] );
	}

	/*
	 * litespeedReset() — URL-shape handling. The `$urls['urls'] ??
	 * $urls` ternary is the interesting bit: MediaLibraryModel wraps
	 * URLs under a `urls` key while CustomImageModel returns the raw
	 * list. Both need to hit `litespeed_purge_url` once per URL.
	 */

	public function test_litespeedReset_purges_each_url_from_MediaLibraryModel_shape() {
		$c        = cacheRemover::getInstance();
		$purged   = array();
		add_action(
			'litespeed_purge_url',
			function ( $url ) use ( &$purged ) {
				$purged[] = $url;
			}
		);

		$item = $this->makeImageItem(
			'media',
			1,
			array( 'urls' => array( 'https://example.test/a.jpg', 'https://example.test/b.jpg' ) )
		);
		$this->invokeProtected( $c, 'litespeedReset', array( $item ) );

		// Sentinel: assertSame on ordered array catches both the count
		// AND ordering. A regression that iterated `array_keys` (getting
		// the string "urls" back) would fail here.
		$this->assertSame(
			array( 'https://example.test/a.jpg', 'https://example.test/b.jpg' ),
			$purged
		);
	}

	public function test_litespeedReset_purges_each_url_from_CustomImageModel_raw_shape() {
		$c      = cacheRemover::getInstance();
		$purged = array();
		add_action(
			'litespeed_purge_url',
			function ( $url ) use ( &$purged ) {
				$purged[] = $url;
			}
		);

		// CustomImageModel returns a flat list — no `urls` key wrapping.
		// The `?? $urls` fallback is what makes this branch work.
		$item = $this->makeImageItem(
			'custom',
			0,
			array( 'https://example.test/c.jpg', 'https://example.test/d.jpg' )
		);
		$this->invokeProtected( $c, 'litespeedReset', array( $item ) );

		// Sentinel: verify BOTH URLs pass through (not just the first)
		// AND their content differs from the media-library test's URLs
		// so a hardcoded regression can't slip through both tests.
		$this->assertSame(
			array( 'https://example.test/c.jpg', 'https://example.test/d.jpg' ),
			$purged
		);
	}

	public function test_litespeedReset_ensures_LITESPEED_PURGE_SILENT_is_defined_after_the_call() {
		$c = cacheRemover::getInstance();

		$item = $this->makeImageItem(
			'media',
			1,
			array( 'urls' => array( 'https://example.test/x.jpg' ) )
		);
		$this->invokeProtected( $c, 'litespeedReset', array( $item ) );

		// Once defined, this constant stays defined for the rest of
		// the process — so this test also acts as a smoke check that
		// the constant path executes at least once.
		$this->assertTrue( defined( 'LITESPEED_PURGE_SILENT' ) );
	}
}
