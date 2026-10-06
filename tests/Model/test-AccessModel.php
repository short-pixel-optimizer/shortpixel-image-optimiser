<?php
/**
 * Tests for ShortPixel\Model\AccessModel.
 *
 * Covers the singleton contract, the capability-map defaults + filter, the
 * getCap fallback, and the imageIsEditable routing (via a stub media-item
 * object that satisfies the `get('type')` / `get('id')` contract).
 *
 * The wp_get_current_user-dependent methods (noticeIsAllowed, userIsAllowed,
 * imageIsEditable's true branches) are exercised as an admin user so has_cap
 * returns true; the resulting assertions confirm the routing rather than the
 * WordPress capability system itself.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\AccessModel;

class AccessModelTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
	}

	public function tear_down() {
		$this->resetSingleton();
		remove_all_filters( 'shortpixel/init/permissions' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function resetSingleton(): void {
		$ref = new ReflectionClass( AccessModel::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	private function getPrivate( AccessModel $m, string $prop ) {
		$ref = new ReflectionClass( AccessModel::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $m );
	}

	private function invokePrivate( AccessModel $m, string $method, array $args = array() ) {
		$ref = new ReflectionClass( AccessModel::class );
		$r   = $ref->getMethod( $method );
		$r->setAccessible( true );
		return $r->invoke( $m, ...$args );
	}

	/*
	 * Singleton contract
	 */

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = AccessModel::getInstance();
		$b = AccessModel::getInstance();
		$this->assertInstanceOf( AccessModel::class, $a );
		$this->assertSame( $a, $b );
	}

	/*
	 * Default capability map
	 */

	public function test_constructor_seeds_the_default_capability_map() {
		$m    = new AccessModel();
		$caps = $this->getPrivate( $m, 'caps' );

		$this->assertSame( 'activate_plugins', $caps['notices'] );
		$this->assertSame( 'manage_options', $caps['quota-warning'] );
		$this->assertSame( 'edit_others_posts', $caps['image_all'] );
		$this->assertSame( 'edit_post', $caps['image_user'] );
		$this->assertSame( 'edit_others_posts', $caps['custom_all'] );
		$this->assertSame( 'manage_options', $caps['is_admin_user'] );
		$this->assertSame( 'edit_others_posts', $caps['is_editor'] );
		$this->assertSame( 'edit_posts', $caps['is_author'] );
		$this->assertSame( array(), $caps['actions'] );
	}

	public function test_permissions_filter_can_override_the_map_before_first_construction() {
		add_filter( 'shortpixel/init/permissions', function ( $caps ) {
			$caps['notices'] = 'custom_cap';
			return $caps;
		} );

		$m    = new AccessModel();
		$caps = $this->getPrivate( $m, 'caps' );

		$this->assertSame( 'custom_cap', $caps['notices'] );
		// Untouched entries survive the filter.
		$this->assertSame( 'manage_options', $caps['quota-warning'] );
	}

	/*
	 * getCap — known slug returns mapped cap; unknown returns the default.
	 */

	public function test_getCap_returns_mapped_capability_for_known_slug() {
		$m = new AccessModel();
		$this->assertSame( 'activate_plugins', $this->invokePrivate( $m, 'getCap', array( 'notices' ) ) );
		$this->assertSame( 'edit_post', $this->invokePrivate( $m, 'getCap', array( 'image_user' ) ) );
	}

	public function test_getCap_returns_default_manage_options_for_unknown_slug() {
		$m = new AccessModel();
		$this->assertSame( 'manage_options', $this->invokePrivate( $m, 'getCap', array( 'does_not_exist' ) ) );
	}

	public function test_getCap_respects_caller_supplied_default() {
		$m = new AccessModel();
		$this->assertSame( 'read', $this->invokePrivate( $m, 'getCap', array( 'does_not_exist', 'read' ) ) );
	}

	/*
	 * isFeatureAvailable — currently returns true for every case (avif, webp,
	 * unknown). The tests pin the current contract so a future gating change
	 * fails loudly.
	 */

	public function test_isFeatureAvailable_returns_true_for_webp() {
		$this->assertTrue( AccessModel::getInstance()->isFeatureAvailable( 'webp' ) );
	}

	public function test_isFeatureAvailable_returns_true_for_avif() {
		$this->assertTrue( AccessModel::getInstance()->isFeatureAvailable( 'avif' ) );
	}

	public function test_isFeatureAvailable_returns_true_for_unknown_feature() {
		$this->assertTrue( AccessModel::getInstance()->isFeatureAvailable( 'unknown-thing' ) );
	}

	/*
	 * imageIsEditable — routes on the item type. Uses a stub media-item that
	 * satisfies the `get()` contract, plus an admin session so the capability
	 * checks resolve true and we can observe the routing.
	 */

	private function makeMediaItemStub( string $type, int $id ) {
		return new class( $type, $id ) {
			private $type;
			private $id;
			public function __construct( $type, $id ) {
				$this->type = $type;
				$this->id   = $id;
			}
			public function get( $name ) {
				return $this->{$name} ?? null;
			}
		};
	}

	public function test_imageIsEditable_true_for_custom_type_when_user_has_custom_all() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue(
			AccessModel::getInstance()->imageIsEditable( $this->makeMediaItemStub( 'custom', 1 ) )
		);
	}

	public function test_imageIsEditable_true_for_media_type_when_user_has_image_all() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue(
			AccessModel::getInstance()->imageIsEditable( $this->makeMediaItemStub( 'media', 1 ) )
		);
	}

	public function test_imageIsEditable_false_for_unknown_type() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertFalse(
			AccessModel::getInstance()->imageIsEditable( $this->makeMediaItemStub( 'nonsense', 1 ) )
		);
	}

	public function test_imageIsEditable_false_for_media_type_when_user_lacks_capabilities() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse(
			AccessModel::getInstance()->imageIsEditable( $this->makeMediaItemStub( 'media', 1 ) )
		);
	}
}
