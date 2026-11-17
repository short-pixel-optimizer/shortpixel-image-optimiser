<?php
/**
 * Quota check: when the plugin asks the ShortPixel API, and what a check
 * costs while api.shortpixel.com cannot be reached.
 *
 * The quota is loaded on demand: by the first getQuota(),
 * getAvailableQuota() or hasQuota() of a request, from the `quotaData`
 * transient, or from the API when that is cold. `admin_init` does not load
 * it, so admin-ajax.php requests of visitors never reach the API.
 *
 * The transport failure is simulated with `pre_http_request`; no socket is
 * opened. "A new request" is simulated by dropping the QuotaController
 * singleton and the cache items CacheController keeps in a static.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QuotaController;

class QuotaOutageTest extends SPIO_IntegrationTestCase {

	/** @var array<int, array{method:string,url:string,timeout:mixed}> Requests to api-status.php. */
	private $requests = array();

	/** @var bool False lets the mock API answer as a healthy account. */
	private $unreachable = true;

	/** @var callable|null Runs once, inside the first API request. */
	private $duringFirstRequest = null;

	public function set_up() {
		parent::set_up();
		$this->requests           = array();
		$this->unreachable        = true;
		$this->duringFirstRequest = null;
		// After the mock API (priority 10), so this answer stands.
		add_filter( 'pre_http_request', array( $this, 'interceptApiStatus' ), 99, 3 );
		delete_transient( QuotaController::CACHE_NAME );
		$this->newRequest();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'interceptApiStatus' ), 99 );
		remove_all_filters( 'wp_doing_ajax' );
		delete_transient( QuotaController::CACHE_NAME );
		$this->newRequest();
		parent::tear_down();
	}

	/** pre_http_request: records api-status.php calls and, by default, fails them at transport level. */
	public function interceptApiStatus( $preempt, $args, $url ) {
		if ( false === strpos( (string) $url, 'api-status.php' ) ) {
			return $preempt;
		}
		$this->requests[] = array(
			'method'  => isset( $args['method'] ) ? $args['method'] : '',
			'url'     => $url,
			'timeout' => isset( $args['timeout'] ) ? $args['timeout'] : null,
		);
		if ( 1 === count( $this->requests ) && null !== $this->duringFirstRequest ) {
			$callback                 = $this->duringFirstRequest;
			$this->duringFirstRequest = null;
			$callback();
		}
		if ( ! $this->unreachable ) {
			return $preempt;
		}
		return new \WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
	}

	/**
	 * State of a new PHP request: no QuotaController singleton, and none of
	 * the cache items CacheController keeps in a static for a request.
	 */
	private function newRequest(): void {
		\Closure::bind(
			function () {
				self::$instance = null;
			},
			null,
			QuotaController::class
		)();
		\Closure::bind(
			function () {
				self::$cached_items = array();
			},
			null,
			\ShortPixel\Controller\CacheController::class
		)();
	}

	/**
	 * `admin_init` also fires for admin-ajax.php requests of visitors who
	 * are not logged in. It must not load the quota: on a cold cache that
	 * is a call to the API, which a storefront request would wait for.
	 */
	public function test_admin_init_of_an_anonymous_ajax_request_does_not_ask_the_api() {
		wp_set_current_user( 0 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		// SENTINELS: a visitor, an AJAX request, a cold cache.
		$this->assertFalse( is_user_logged_in(), 'Sentinel: nobody is logged in.' );
		$this->assertTrue( wp_doing_ajax(), 'Sentinel: an AJAX request.' );
		$this->assertFalse( get_transient( QuotaController::CACHE_NAME ), 'Sentinel: the quota cache is cold.' );
		$this->assertNotFalse( has_action( 'admin_init', array( \wpSPIO(), 'admin_init' ) ), 'Sentinel: the plugin runs on admin_init.' );

		\wpSPIO()->admin_init();

		$this->assertCount( 0, $this->requests, 'admin_init made no request to the API.' );
		$this->assertFalse( get_transient( QuotaController::CACHE_NAME ), 'and cached nothing.' );

		// SENTINEL: a quota load in this state does reach the (recording) API.
		QuotaController::getInstance()->hasQuota();
		$this->assertGreaterThan( 0, count( $this->requests ), 'Sentinel: loading the quota would have been recorded.' );
	}

	/**
	 * A check that starts with hasQuota() makes one round against an
	 * unreachable API: three attempts (POST, POST over the other protocol,
	 * GET), each allowed to wait 10 seconds. The message of the failed
	 * request is added to the result; no socket is probed.
	 */
	public function test_a_check_started_by_hasQuota_makes_one_round_of_three_attempts() {
		QuotaController::getInstance()->hasQuota();

		$this->assertSame( array( 'POST', 'POST', 'GET' ), wp_list_pluck( $this->requests, 'method' ) );
		$this->assertSame( array( 10, 10, 10 ), wp_list_pluck( $this->requests, 'timeout' ) );
		$this->assertStringStartsWith( 'https://', $this->requests[0]['url'] );
		$this->assertStringStartsWith( 'http://', $this->requests[1]['url'], 'The second attempt flips the protocol.' );

		$cached = get_transient( QuotaController::CACHE_NAME );
		$this->assertIsArray( $cached );
		$this->assertFalse( $cached['GetSuccess'] );
		$this->assertStringContainsString( 'Debug info: <i>cURL error 28: Connection timed out</i>', $cached['Message'] );
	}

	/**
	 * A check that starts with getQuota() (or getAvailableQuota()) on a cold
	 * cache makes the same single round as hasQuota(). getQuotaData() asks
	 * hasQuota() for the cache lifetime after the remote call; it must answer
	 * from the result just loaded, not start a second check.
	 */
	public function test_a_check_started_by_getQuota_makes_one_round_of_three_attempts() {
		QuotaController::getInstance()->getQuota();

		$this->assertSame( array( 'POST', 'POST', 'GET' ), wp_list_pluck( $this->requests, 'method' ) );
	}

	/**
	 * With a healthy API the first getQuota() on a cold cache makes one
	 * request, and a request with a warm cache makes none.
	 */
	public function test_getQuota_asks_a_healthy_api_once_on_a_cold_cache() {
		$this->unreachable = false;

		$quota = QuotaController::getInstance()->getQuota();

		// SENTINEL: the mock API answered as a paying account.
		$this->assertGreaterThan( 0, $quota->monthly->total, 'Sentinel: the quota was read from the API.' );
		$this->assertCount( 1, $this->requests );

		// A warm cache needs no request at all.
		$this->newRequest();
		QuotaController::getInstance()->getQuota();
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * PIN — nothing marks a quota check as running. A second request that
	 * arrives while the first one is still waiting for the API finds the
	 * cache just as cold and starts its own attempts. The second request
	 * is simulated inside the first HTTP attempt.
	 * @todo See the @todo in QuotaController::getQuotaData().
	 * Fixed when the second request makes no API request of its own.
	 */
	public function test_a_concurrent_request_starts_its_own_quota_check_pinned_for_deferred_fix() {
		$first                    = QuotaController::getInstance();
		$this->duringFirstRequest = function () {
			$this->newRequest();
			$this->assertFalse( get_transient( QuotaController::CACHE_NAME ), 'Sentinel: nothing is cached while the first check waits.' );
			QuotaController::getInstance()->hasQuota();
		};

		$first->hasQuota();

		$this->assertCount( 6, $this->requests, 'PIN: fixed? 3 requests (the first check only) — flip this pin.' );
	}

	/**
	 * A failed check is remembered for five minutes: requests within that
	 * time use it, the first request after it checks again.
	 */
	public function test_a_failed_quota_check_is_cached_for_five_minutes() {
		QuotaController::getInstance()->hasQuota();
		$this->assertCount( 3, $this->requests, 'Sentinel: the check ran.' );

		$expires = (int) get_option( '_transient_timeout_' . QuotaController::CACHE_NAME ) - time();
		$this->assertGreaterThan( 4 * MINUTE_IN_SECONDS, $expires );
		$this->assertLessThanOrEqual( 5 * MINUTE_IN_SECONDS, $expires );

		// Within that time: a new request does not ask again.
		$this->newRequest();
		QuotaController::getInstance()->hasQuota();
		QuotaController::getInstance()->getQuota();
		$this->assertCount( 3, $this->requests, 'The cached failure is used.' );

		// After it: the next request repeats the check.
		delete_transient( QuotaController::CACHE_NAME );
		$this->newRequest();
		QuotaController::getInstance()->hasQuota();
		$this->assertCount( 6, $this->requests );
	}

	/**
	 * hasQuota() loads the quota once per request: further calls answer
	 * from the settings flag without touching the cache or the API.
	 */
	public function test_hasQuota_loads_the_quota_once_per_request() {
		$this->unreachable = false;
		$quota             = QuotaController::getInstance();

		$this->assertTrue( $quota->hasQuota() );
		$this->assertCount( 1, $this->requests, 'Sentinel: the first call loaded the quota.' );

		delete_transient( QuotaController::CACHE_NAME );
		\Closure::bind(
			function () {
				self::$cached_items = array();
			},
			null,
			\ShortPixel\Controller\CacheController::class
		)();
		$this->assertTrue( $quota->hasQuota() );
		$this->assertCount( 1, $this->requests, 'No second load in the same request.' );
	}
}
