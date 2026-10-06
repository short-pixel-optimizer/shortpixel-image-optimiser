<?php
/**
 * Tests for ShortPixel\Controller — proto parent class for all controllers.
 *
 * Focus areas:
 *   - Constructor invokes checkUserPrivileges and stores the result on
 *     $userIsAllowed
 *   - checkUserPrivileges — checks the three WP caps (manage_options,
 *     upload_files, edit_posts) with OR semantics
 *   - formatNumber — thin UiHelper delegate
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller;

class ControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Start each test with no logged-in user so cap-check tests have
		// a known baseline.
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function getProtected( Controller $c, string $prop ) {
		$ref = new ReflectionClass( Controller::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function invokeProtected( Controller $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( Controller::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	/*
	 * Constructor — runs checkUserPrivileges and stores the result.
	 */

	public function test_constructor_sets_userIsAllowed_false_for_anonymous_user() {
		// No user logged in (set_up cleared).
		$c = new Controller();

		$this->assertFalse( $this->getProtected( $c, 'userIsAllowed' ) );
	}

	public function test_constructor_sets_userIsAllowed_true_for_user_with_manage_options() {
		$adminId = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $adminId );

		$c = new Controller();

		// Sentinel: administrators get manage_options by default —
		// pins the "at least one of the three caps grants access" contract.
		$this->assertTrue( $this->getProtected( $c, 'userIsAllowed' ) );
	}

	public function test_constructor_sets_userIsAllowed_true_for_user_with_upload_files_only() {
		// Author role has upload_files + edit_posts but NOT manage_options.
		$authorId = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $authorId );

		$c = new Controller();

		// Sentinel: proves the OR chain — a user without manage_options
		// still qualifies via upload_files (or edit_posts). A regression
		// that AND'd the caps instead of OR'd would fail here.
		$this->assertTrue( $this->getProtected( $c, 'userIsAllowed' ) );
	}

	public function test_constructor_sets_userIsAllowed_false_for_subscriber_role() {
		// Subscribers have `read` but not manage_options / upload_files /
		// edit_posts — should be denied.
		$subscriberId = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriberId );

		$c = new Controller();

		// Sentinel-pair with the author/admin tests: users below the
		// three-cap threshold get denied. Regression that loosened the
		// check would fail here.
		$this->assertFalse( $this->getProtected( $c, 'userIsAllowed' ) );
	}

	/*
	 * checkUserPrivileges — direct invocation for isolation.
	 */

	public function test_checkUserPrivileges_returns_false_for_anonymous_user() {
		$c = new Controller();
		wp_set_current_user( 0 ); // reset after construct

		$result = $this->invokeProtected( $c, 'checkUserPrivileges' );

		// Sentinel: strict `assertSame( false, ...)` (not `assertFalse`) —
		// method's documented return is bool, so null / 0 / '' would all
		// slip past assertFalse but not this.
		$this->assertSame( false, $result );
	}

	public function test_checkUserPrivileges_returns_true_when_user_has_at_least_one_of_the_three_caps() {
		$adminId = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $adminId );
		$c = new Controller();

		$result = $this->invokeProtected( $c, 'checkUserPrivileges' );

		$this->assertSame( true, $result );
	}

	/*
	 * formatNumber — thin delegate to UiHelper::formatNumber.
	 */

	public function test_formatNumber_returns_a_string_from_UiHelper() {
		$c = new Controller();

		$result = $this->invokeProtected( $c, 'formatNumber', array( 1234.5678 ) );

		// The exact format depends on WP locale settings; assert on
		// shape rather than exact string. Both the number and formatting
		// characters (comma/period/space) fit in the string check.
		$this->assertIsString( $result );
		// Sentinel: the value should surface somewhere in the output —
		// a regression that returned an empty string would fail here.
		$this->assertNotEmpty( $result );
	}

	public function test_formatNumber_honours_the_precision_argument() {
		$c = new Controller();

		$twoDecimal   = $this->invokeProtected( $c, 'formatNumber', array( 1234.5678, 2 ) );
		$noDecimal    = $this->invokeProtected( $c, 'formatNumber', array( 1234.5678, 0 ) );

		// Sentinel: the same input at different precisions must produce
		// different outputs. A regression that ignored the $precision
		// arg (e.g. hardcoded to 2) would fail this pair.
		$this->assertNotSame( $twoDecimal, $noDecimal );
	}
}
