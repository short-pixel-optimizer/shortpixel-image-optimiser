<?php
/**
 * ItemAccessGuard unit tests.
 *
 * Covers the shared per-image access check that Calin wired in front of every
 * single-image MCP ability (c91cd01c). The guard mirrors
 * AjaxController::checkImageAccess() so REST/MCP callers honour the same
 * per-attachment permission map (image_all / image_user / custom_all) as the
 * classic AJAX handlers.
 *
 * Behaviour under test:
 *  - object image model + editable user     -> null (allow)
 *  - object image model + non-editable user -> access_denied payload
 *  - non-object (false/null/scalar)         -> "does not exist" payload
 *  - custom-type items route via custom_all
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Abilities\ItemAccessGuard;

class ItemAccessGuardTest extends WP_UnitTestCase {

	public function tear_down() {
		// Any test that logs a user in must leave the process user-less so a
		// later test file doesn't inherit unexpected capabilities.
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Minimal stub of the ImageModel `get('type')` / `get('id')` contract that
	 * AccessModel::imageIsEditable() relies on. Kept anonymous-class here (not
	 * a shared trait) because ItemAccessGuard has ZERO reason to see any other
	 * ImageModel surface.
	 */
	private function makeImageModelStub( string $type, int $id ) {
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
			ItemAccessGuard::denyIfNotEditable( $this->makeImageModelStub( 'media', 42 ) )
		);
	}

	public function test_deny_if_not_editable_allows_custom_type_for_editor() {
		// custom_all defaults to edit_others_posts, which editors satisfy.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertNull(
			ItemAccessGuard::denyIfNotEditable( $this->makeImageModelStub( 'custom', 7 ) )
		);
	}

	public function test_deny_if_not_editable_returns_access_denied_for_blocked_user() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ItemAccessGuard::denyIfNotEditable( $this->makeImageModelStub( 'media', 42 ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['error'] );
		$this->assertTrue( $result['access_denied'] );
		$this->assertSame( 42, $result['id'] );
		$this->assertSame( 'media', $result['type'] );
		$this->assertSame( 'This user is not allowed to edit this image', $result['message'] );
	}

	public function test_deny_if_not_editable_blocks_subscriber_for_custom_type() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = ItemAccessGuard::denyIfNotEditable( $this->makeImageModelStub( 'custom', 99 ) );

		$this->assertTrue( $result['error'] );
		$this->assertTrue( $result['access_denied'] );
		$this->assertSame( 'custom', $result['type'] );
		$this->assertSame( 99, $result['id'] );
	}

	public function test_deny_if_not_editable_returns_not_found_for_missing_model() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = ItemAccessGuard::denyIfNotEditable( false );

		$this->assertTrue( $result['error'] );
		$this->assertArrayNotHasKey( 'access_denied', $result );
		$this->assertSame( 'Image does not exist or could not be loaded', $result['message'] );
	}

	public function test_deny_if_not_editable_returns_not_found_for_null_and_scalars() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( null, 0, '', 'not-an-object' ) as $bad ) {
			$result = ItemAccessGuard::denyIfNotEditable( $bad );
			$this->assertTrue( $result['error'], 'is_object() guard must reject scalar: ' . var_export( $bad, true ) );
			$this->assertArrayNotHasKey( 'access_denied', $result );
		}
	}
}
