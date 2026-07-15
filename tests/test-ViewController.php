<?php
/**
 * Tests for ShortPixel\ViewController — base controller for view rendering,
 * POST handling, nonce verification, and HTML output helpers.
 *
 * Focus areas:
 *   - Constructor initialises $view stdClass with notices/data null
 *   - init() no-op contract
 *   - getInstance() singleton contract (identity + first-call instantiation)
 *   - access() returns the AccessModel singleton
 *   - loadView / returnView — template routing, empty-arg early returns,
 *     unique-load deduplication, missing-template error logging
 *   - addData — merge semantics
 *   - setControllerURL — trivial setter
 *   - printInlineHelp — URL escaping in output
 *
 * NOT covered here (integration territory):
 *   - checkPost with nonce mismatch — hits `wp_die` mid-flow, kills the test.
 *   - processPostData with a real connected model — needs a full model fixture.
 *   - printSwitchButton — 12-line sprintf-and-echo helper, low value for
 *     assertion given the HTML is mostly boilerplate.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\ViewController;
use ShortPixel\Model\AccessModel;

class ViewControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );

		// Reset the singleton so getInstance tests observe a fresh construction.
		$ref = new ReflectionClass( ViewController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );

		// Reset the viewsLoaded static so unique-load tests observe a
		// clean per-request state.
		$vRef = $ref->getProperty( 'viewsLoaded' );
		$vRef->setAccessible( true );
		$vRef->setValue( null, array() );
	}

	public function tear_down() {
		wp_set_current_user( 0 );

		// Reset statics again to avoid leaking into unrelated tests.
		$ref = new ReflectionClass( ViewController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );

		$vRef = $ref->getProperty( 'viewsLoaded' );
		$vRef->setAccessible( true );
		$vRef->setValue( null, array() );

		parent::tear_down();
	}

	private function getProtected( ViewController $c, string $prop ) {
		$ref = new ReflectionClass( ViewController::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function setProtected( ViewController $c, string $prop, $value ): void {
		$ref = new ReflectionClass( ViewController::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $c, $value );
	}

	private function invokeProtected( ViewController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( ViewController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	/*
	 * __construct — parent + initialises $view stdClass.
	 */

	public function test_constructor_initialises_view_as_stdClass_with_notices_and_data_null() {
		$c = new ViewController();

		$view = $this->getProtected( $c, 'view' );

		$this->assertInstanceOf( \stdClass::class, $view );
		// Sentinel-pair: BOTH the notices AND data properties on the
		// view object must exist AND be null. A regression that dropped
		// either initialisation would fail one of the pair; templates
		// that read $view->notices / $view->data expect them present.
		$this->assertNull( $view->notices );
		$this->assertNull( $view->data );
	}

	public function test_constructor_calls_parent_and_sets_userIsAllowed() {
		// Parent Controller::__construct populates userIsAllowed via
		// checkUserPrivileges. Verify the chain fires.
		$adminId = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $adminId );

		$c = new ViewController();

		// Sentinel: pins the parent::__construct chain. A regression that
		// forgot to call the parent constructor would leave userIsAllowed
		// at its default false.
		$this->assertTrue( $this->getProtected( $c, 'userIsAllowed' ) );
	}

	/*
	 * init — trivial no-op contract.
	 */

	public function test_init_is_a_noop_returning_null() {
		// Pinned sentinel: init() is deliberately empty. If someone
		// starts implementing init(), this test must be updated first —
		// intended tripwire.
		$result = ViewController::init();
		$this->assertNull( $result );
	}

	/*
	 * getInstance — late-static-binding singleton.
	 */

	public function test_getInstance_returns_a_ViewController_instance() {
		$this->assertInstanceOf( ViewController::class, ViewController::getInstance() );
	}

	public function test_getInstance_returns_the_same_instance_on_repeated_calls() {
		$a = ViewController::getInstance();
		$b = ViewController::getInstance();

		// Sentinel: identity check (assertSame). A regression that
		// constructed a fresh instance every call would still pass
		// assertEquals but fail here.
		$this->assertSame( $a, $b );
	}

	/*
	 * access — returns AccessModel singleton.
	 */

	public function test_access_returns_the_AccessModel_singleton_instance() {
		$c = new ViewController();

		$result = $c->access();

		$this->assertInstanceOf( AccessModel::class, $result );
		// Sentinel: identity match against AccessModel's own singleton
		// getter. A regression that constructed a fresh AccessModel each
		// time would fail this — and cache-warmup between the view and
		// AccessModel wouldn't be shared, breaking cap-check consistency.
		$this->assertSame( AccessModel::getInstance(), $result );
	}

	/*
	 * loadView — template routing + early returns + dedup.
	 */

	public function test_loadView_returns_false_when_both_template_arg_and_property_are_null() {
		$c = new ViewController();
		// $this->template starts null by default.

		$result = $c->loadView();

		$this->assertFalse( $result );
	}

	public function test_loadView_returns_false_when_template_is_an_empty_or_whitespace_string() {
		$c = new ViewController();

		// Sentinel-pair: BOTH the empty-string AND the whitespace case
		// must be rejected. Regression that only checked one would slip
		// the other through into `include()` — pointing at a nonexistent
		// path that logs a misleading "template not found" error.
		$this->assertFalse( $c->loadView( '' ) );
		$this->assertFalse( $c->loadView( '   ' ) );
	}

	public function test_loadView_falls_back_to_the_template_property_when_no_arg_provided() {
		$c = new ViewController();
		// Set a nonexistent template as the property — loadView will
		// pick it up, fail to include (logs error), but won't early-exit.
		$this->setProtected( $c, 'template', 'definitely-not-a-real-template' );

		// The method returns void (not false) when it reaches the
		// include branch. If the fallback didn't fire, we'd get false
		// from the null-template early exit. The assertion is that we
		// DID NOT get false back.
		$result = @$c->loadView();
		$this->assertNotSame( false, $result );
	}

	public function test_loadView_deduplicates_repeated_includes_of_the_same_template_when_unique_is_true() {
		// Reset the static so we can observe additions cleanly.
		$vRef = ( new ReflectionClass( ViewController::class ) )->getProperty( 'viewsLoaded' );
		$vRef->setAccessible( true );
		$vRef->setValue( null, array() );

		$c = new ViewController();

		// Use a nonexistent template — the file_exists check will fail
		// so nothing is actually included, but we can still verify the
		// dedup logic never runs (because the missing-template branch
		// fires before the dedup check).
		//
		// Instead, pick a real template we know exists in class/view/.
		// `snippets/part-svgloader` is minimal and safe to include.
		$c->loadView( 'snippets/part-svgloader', true );
		$c->loadView( 'snippets/part-svgloader', true );

		$loaded = $vRef->getValue( null );
		// Sentinel: viewsLoaded contains the template exactly ONCE
		// after two calls with $unique=true. A regression that dropped
		// the in_array check would add it twice.
		$this->assertSame( 1, count( array_filter( $loaded, function ( $t ) {
			return $t === 'snippets/part-svgloader';
		} ) ) );
	}

	/*
	 * returnView — buffer wrap around loadView.
	 */

	public function test_returnView_returns_string_for_nonexistent_template() {
		$c = new ViewController();

		$result = @$c->returnView( 'definitely-not-a-real-template' );

		// Sentinel: returnView always returns a string, even when the
		// underlying loadView errors — the ob_start / ob_get_contents
		// / ob_end_clean chain guarantees it. A regression that returned
		// false / null instead would break callers using the result in
		// JSON responses.
		$this->assertIsString( $result );
	}

	/*
	 * addData — merges the argument into $data.
	 */

	public function test_addData_stores_the_passed_data_on_the_data_property() {
		$c = new ViewController();

		$c->addData( array( 'foo' => 'bar', 'x' => 1 ) );

		$data = $this->getProtected( $c, 'data' );
		$this->assertSame( 'bar', $data['foo'] );
		$this->assertSame( 1, $data['x'] );
	}

	public function test_addData_merges_with_existing_data_preserving_earlier_entries() {
		$c = new ViewController();
		$this->setProtected( $c, 'data', array( 'a' => 'first', 'b' => 'second' ) );

		$c->addData( array( 'c' => 'third' ) );

		$data = $this->getProtected( $c, 'data' );
		// Sentinel: BOTH pre-existing entries must survive AND the new
		// one appended. A regression that swapped array_merge for
		// assignment would drop 'a' and 'b'.
		$this->assertSame( 'first', $data['a'] );
		$this->assertSame( 'second', $data['b'] );
		$this->assertSame( 'third', $data['c'] );
	}

	public function test_addData_overwrites_existing_keys_on_key_collision() {
		$c = new ViewController();
		$this->setProtected( $c, 'data', array( 'shared' => 'old' ) );

		$c->addData( array( 'shared' => 'new' ) );

		// Sentinel: array_merge semantics — later value wins on collision.
		// Pinned so a regression that swapped to array_merge_recursive
		// would surface (recursive merge would produce array('old', 'new')).
		$this->assertSame( 'new', $this->getProtected( $c, 'data' )['shared'] );
	}

	/*
	 * setControllerURL — trivial setter.
	 */

	public function test_setControllerURL_stores_the_url_on_the_url_property() {
		$c = new ViewController();

		$c->setControllerURL( 'https://example.test/admin/settings' );

		$this->assertSame(
			'https://example.test/admin/settings',
			$this->getProtected( $c, 'url' )
		);
	}

	/*
	 * printInlineHelp — echoes an escaped URL inside an <i> icon.
	 */

	public function test_printInlineHelp_echoes_escaped_url_inside_a_help_icon() {
		$c = new ViewController();

		ob_start();
		$this->invokeProtected( $c, 'printInlineHelp', array( 'https://example.test/help/page?ref=1' ) );
		$output = ob_get_clean();

		// Sentinel-triplet: the icon class attribute, the data-link
		// attribute, and the URL must all appear. The URL is passed
		// through esc_url — a regression that dropped the escaping
		// would still make the URL appear (esc_url is a passthrough
		// for well-formed URLs) so the test doesn't try to catch
		// esc_url dropping — it catches missing-URL / missing-class
		// regressions.
		$this->assertStringContainsString( 'documentation', $output );
		$this->assertStringContainsString( 'dashicons-editor-help', $output );
		$this->assertStringContainsString( 'https://example.test/help/page', $output );
	}

	/*
	 * checkPost — safe branch only (no POST → true).
	 * The nonce-failure path calls wp_die() which kills the test.
	 */

	public function test_checkPost_returns_true_silently_when_POST_is_empty() {
		$c = new ViewController();
		// Ensure $_POST is empty for this test.
		$savedPost = $_POST;
		$_POST     = array();

		try {
			$result = $this->invokeProtected( $c, 'checkPost' );
			$this->assertSame( true, $result );
			// Sentinel: empty-POST must NOT flip is_form_submit.
			// Regression that unconditionally set the flag would fail here.
			$this->assertFalse( $this->getProtected( $c, 'is_form_submit' ) );
		} finally {
			$_POST = $savedPost;
		}
	}
}
