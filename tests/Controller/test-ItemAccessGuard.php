<?php
/**
 * ItemAccessGuard unit tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Abilities\ItemAccessGuard;

class ItemAccessGuardTest extends WP_UnitTestCase {

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

	public function test_deny_if_not_editable_returns_null_for_allowed_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertNull(
			ItemAccessGuard::denyIfNotEditable( $this->makeMediaItemStub( 'media', 42 ) )
		);
	}

	public function test_deny_if_not_editable_returns_access_denied_for_blocked_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ItemAccessGuard::denyIfNotEditable( $this->makeMediaItemStub( 'media', 42 ) );

		$this->assertTrue( $result['error'] );
		$this->assertTrue( $result['access_denied'] );
		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'media', $result['type'] );
	}

	public function test_deny_if_not_editable_returns_not_found_for_missing_model() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = ItemAccessGuard::denyIfNotEditable( false );

		$this->assertTrue( $result['error'] );
		$this->assertArrayNotHasKey( 'access_denied', $result );
		$this->assertStringContainsString( 'not found', $result['message'] );
	}
}
