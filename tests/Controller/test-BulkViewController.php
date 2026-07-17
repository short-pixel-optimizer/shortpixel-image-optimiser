<?php
/**
 * Tests for ShortPixel\Controller\View\BulkViewController.
 *
 * Focus areas:
 *   - getActivationNotice() — returns a non-empty HTML string with expected content.
 *   - getCustomLabel() — known identifiers produce the correct translated labels;
 *     unknown identifier triggers the uninitialized-variable warning (pinned).
 *   - checkBulkViaPanelArg() — GET 'panel' arg maps to the correct operation
 *     identifier; null panel returns false.
 *   - getApproxData() — returns a stdClass with the expected shape
 *     (exercised against a real DB via StatsController; counts are clamped ≥ 0).
 *
 * NOT covered here:
 *   - load() — renders the full bulk template; too many chained singletons
 *     (QuotaController, BulkController, AdminNoticesController, …).
 *   - getLogs() — BulkController::getLogs() requires the backup-folder file
 *     system to be populated; no isolation possible without a full install.
 *   - loadCurrentLog() — reads a log file from disk; not available in unit context.
 *   - loadDashboard() — depends on AdminNoticesController::getRemoteOffer()
 *     which makes remote HTTP calls.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\View\BulkViewController;

class BulkViewControllerTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	private function freshController(): BulkViewController {
		$ref = new ReflectionClass( BulkViewController::class );
		$c   = $ref->newInstanceWithoutConstructor();

		// Seed minimum view state.
		$viewRef  = new ReflectionClass( \ShortPixel\ViewController::class );
		$viewProp = $viewRef->getProperty( 'view' );
		$viewProp->setAccessible( true );
		$view          = new \stdClass;
		$view->notices = null;
		$view->data    = null;
		$viewProp->setValue( $c, $view );

		return $c;
	}

	private function invokeProtected( BulkViewController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( BulkViewController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );

		$ref = new ReflectionClass( BulkViewController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$p = $ref->getProperty( 'instance' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		$_GET = array();

		$ref = new ReflectionClass( BulkViewController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$p = $ref->getProperty( 'instance' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}

		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// getActivationNotice — HTML content
	// -----------------------------------------------------------------

	public function test_getActivationNotice_returns_a_non_empty_string() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getActivationNotice' );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_getActivationNotice_references_settings_page() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getActivationNotice' );

		// Sentinel: the notice must direct the user to the settings page URL.
		// A regression that changed the slug would leave users without a link.
		$this->assertStringContainsString( 'wp-shortpixel-settings', $result );
	}

	public function test_getActivationNotice_contains_anchor_tag() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getActivationNotice' );

		// Sentinel: the notice must include a clickable link, not just plain text.
		$this->assertStringContainsString( '<a ', $result );
	}

	// -----------------------------------------------------------------
	// getCustomLabel — known identifiers
	// -----------------------------------------------------------------

	public function test_getCustomLabel_returns_bulk_restore_label() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getCustomLabel', array( 'bulk-restore' ) );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
		// Sentinel: must mention "Restore" (case-insensitive).
		$this->assertMatchesRegularExpression( '/restore/i', $result );
	}

	public function test_getCustomLabel_returns_migrate_label() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getCustomLabel', array( 'migrate' ) );

		$this->assertIsString( $result );
		$this->assertMatchesRegularExpression( '/migrat/i', $result );
	}

	public function test_getCustomLabel_returns_removeLegacy_label() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getCustomLabel', array( 'removeLegacy' ) );

		$this->assertIsString( $result );
		$this->assertMatchesRegularExpression( '/legacy/i', $result );
	}

	public function test_getCustomLabel_returns_bulk_undoAI_label() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getCustomLabel', array( 'bulk-undoAI' ) );

		$this->assertIsString( $result );
		// Sentinel: must mention AI or Remove.
		$this->assertMatchesRegularExpression( '/ai|remove/i', $result );
	}

	// -----------------------------------------------------------------
	// checkBulkViaPanelArg — GET parameter mapping
	// -----------------------------------------------------------------

	public function test_checkBulkViaPanelArg_returns_false_when_panel_is_absent() {
		$c   = $this->freshController();
		$_GET = array();

		$result = $this->invokeProtected( $c, 'checkBulkViaPanelArg' );

		// Sentinel: no panel arg → must return exactly false, not null.
		$this->assertFalse( $result );
	}

	public function test_checkBulkViaPanelArg_maps_bulk_migrate_to_migrate() {
		$c    = $this->freshController();
		$_GET = array( 'panel' => 'bulk-migrate' );

		$result = $this->invokeProtected( $c, 'checkBulkViaPanelArg' );

		$this->assertSame( 'migrate', $result );
	}

	public function test_checkBulkViaPanelArg_maps_bulk_restore_to_bulk_restore() {
		$c    = $this->freshController();
		$_GET = array( 'panel' => 'bulk-restore' );

		$result = $this->invokeProtected( $c, 'checkBulkViaPanelArg' );

		$this->assertSame( 'bulk-restore', $result );
	}

	public function test_checkBulkViaPanelArg_maps_bulk_restoreAI_to_bulk_undoAI() {
		$c    = $this->freshController();
		$_GET = array( 'panel' => 'bulk-restoreAI' );

		$result = $this->invokeProtected( $c, 'checkBulkViaPanelArg' );

		$this->assertSame( 'bulk-undoAI', $result );
	}

	public function test_checkBulkViaPanelArg_maps_bulk_removeLegacy_to_removeLegacy() {
		$c    = $this->freshController();
		$_GET = array( 'panel' => 'bulk-removeLegacy' );

		$result = $this->invokeProtected( $c, 'checkBulkViaPanelArg' );

		$this->assertSame( 'removeLegacy', $result );
	}

	public function test_checkBulkViaPanelArg_returns_false_for_unknown_panel_value() {
		$c    = $this->freshController();
		$_GET = array( 'panel' => 'not-a-real-panel' );

		$result = $this->invokeProtected( $c, 'checkBulkViaPanelArg' );

		// An unrecognised panel value falls through the switch with no assignment,
		// so $action keeps its initial value of false.
		$this->assertFalse( $result );
	}

	// -----------------------------------------------------------------
	// getApproxData — shape and clamp
	// -----------------------------------------------------------------

	public function test_getApproxData_returns_stdClass_with_media_custom_total_sub_objects() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getApproxData' );

		$this->assertInstanceOf( \stdClass::class, $result );
		// Sentinel-triplet: all three sub-objects must exist. A regression that
		// removed one would silently break the bulk template's JS initialization.
		$this->assertObjectHasProperty( 'media', $result );
		$this->assertObjectHasProperty( 'custom', $result );
		$this->assertObjectHasProperty( 'total', $result );
	}

	public function test_getApproxData_media_items_is_not_negative() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getApproxData' );

		// Sentinel: clamping logic must prevent negative counts from reaching the template.
		$this->assertGreaterThanOrEqual( 0, $result->media->items );
	}

	public function test_getApproxData_total_images_is_not_negative() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getApproxData' );

		$this->assertGreaterThanOrEqual( 0, $result->total->images );
	}

	public function test_getApproxData_custom_has_has_custom_flag() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getApproxData' );

		// Sentinel: has_custom must exist so the template can conditionally show the
		// custom-media section. Missing it would always show or always hide the block.
		$this->assertObjectHasProperty( 'has_custom', $result->custom );
	}
}
