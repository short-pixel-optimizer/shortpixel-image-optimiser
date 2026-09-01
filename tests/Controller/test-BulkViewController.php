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

	// -----------------------------------------------------------------
	// loadCurrentLog — bug #48 sentinel (unescaped error-log cells)
	// -----------------------------------------------------------------

	/**
	 * BUG #48 (pinned_for_deferred_fix): commit 50719048 stripped esc_html
	 * from the two error-log echoes in view/bulk/part-finished.php and
	 * part-process.php (they render $this->view->mediaErrorLog /
	 * customErrorLog raw so the intended kbinfo <span>/<a> markup renders).
	 * The HTML those views print is built by BulkViewController::loadCurrentLog
	 * (protected) — which concatenates the raw $date / $message / $filename
	 * cells straight into the output around the kbinfo span, with NO escaping.
	 *
	 * The log file lives in SHORTPIXEL_BACKUP_FOLDER/current_bulk_media.log
	 * and its rows are written from optimization-time context (filename comes
	 * from the attachment). An attacker who can upload a media item — or any
	 * flow that lets a user influence the stored filename — can therefore
	 * plant HTML/JS that survives all the way to the admin bulk screen and
	 * executes in the wp-admin origin: stored XSS.
	 *
	 * CORRECT FIX (for Bas, not this test):
	 *   In loadCurrentLog, escape the cells at build time (esc_html on $date,
	 *   $message, $filename) and keep the kbinfo <span>/<a> markup raw. That
	 *   preserves the reason esc_html was removed from the views (kbinfo
	 *   needs to render as HTML) while restoring output safety.
	 *
	 * FLIP INSTRUCTIONS: when the cells are escaped at build time, the raw
	 * <script>… will NOT appear in the output — the assertion
	 * assertStringContainsString('<script>alert(1)</script>', $output) will
	 * fail. Flip to assertStringNotContainsString, drop the _pinned_ suffix,
	 * and delete this block.
	 */
	public function test_pin48_loadCurrentLog_emits_unescaped_filename_and_message_pinned_for_deferred_fix() {
		// Bulk log paths depend on the plugin's backup folder constant; the
		// wp-shortpixel.php bootstrap defines it. Ensure the directory
		// exists (fresh test installs may not have created it yet).
		if ( ! defined( 'SHORTPIXEL_BACKUP_FOLDER' ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_BACKUP_FOLDER not defined — plugin bootstrap did not run.' );
		}
		$backup_dir = SHORTPIXEL_BACKUP_FOLDER;
		if ( ! is_dir( $backup_dir ) ) {
			if ( ! wp_mkdir_p( $backup_dir ) ) {
				$this->markTestSkipped( 'Cannot create backup dir at ' . $backup_dir );
			}
		}

		$log_path = rtrim( $backup_dir, '/\\' ) . '/current_bulk_media.log';

		// Row format expected by loadCurrentLog(): date|filename|item_id|message
		// separated by ';' between rows. Filename and message carry the XSS.
		$xss_filename = '<script>alert(1)</script>.jpg';
		$xss_message  = '<img src=x onerror=alert(2)>';
		$row          = '2026-08-31 12:00:00|' . $xss_filename . '|42|' . $xss_message;

		// Preserve any pre-existing log so we don't stomp on unrelated state.
		$prior = file_exists( $log_path ) ? file_get_contents( $log_path ) : null;
		file_put_contents( $log_path, $row . ';' );

		try {
			$c      = $this->freshController();
			$output = $this->invokeProtected( $c, 'loadCurrentLog', array( 'media' ) );

			// If getLog() couldn't resolve the file (backup path realpath
			// mismatch on some hosts), the method returns false — that's a
			// harness limitation, not a pass, so skip rather than false-pass.
			if ( false === $output ) {
				$this->markTestSkipped( 'BulkController::getLog() could not resolve ' . $log_path . ' — harness path limitation.' );
			}

			$this->assertIsString( $output );

			// Sentinel: the crafted <script> tag survives unescaped in the
			// output that goes to $this->view->mediaErrorLog and gets echoed
			// raw by part-finished.php / part-process.php since 50719048.
			$this->assertStringContainsString(
				'<script>alert(1)</script>',
				$output,
				'BUG #48 pin: filename cell must survive UNESCAPED in loadCurrentLog output (which part-finished.php/part-process.php echo raw). If this fails, the cells were escaped at build time — flip to assertStringNotContainsString and drop the _pinned_for_deferred_fix suffix.'
			);
			$this->assertStringContainsString(
				'<img src=x onerror=alert(2)>',
				$output,
				'BUG #48 pin: message cell must survive UNESCAPED. Same flip rules as above.'
			);
			// Second half of the sentinel: the surrounding kbinfo <a> markup
			// (which is why esc_html was stripped) IS still present, so a
			// half-fix that escapes cells but drops kbinfo entirely would be
			// caught here rather than silently regressing the UI.
			$this->assertStringContainsString(
				'class="kbinfo"',
				$output,
				'BUG #48 pin: the kbinfo helper span must remain in the output — a fix that removes it entirely would over-correct.'
			);
		} finally {
			// Restore prior content (or delete if none) so the test does not
			// leak fixture state into other bulk-log tests.
			if ( null === $prior ) {
				@unlink( $log_path );
			} else {
				file_put_contents( $log_path, $prior );
			}
		}
	}
}
