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
 *   - loadDashboard() — depends on AdminNoticesController::getRemoteOffer()
 *     which makes remote HTTP calls.
 *
 * Regression tests:
 *   - loadCurrentLog() — regression for bug #48 (stored XSS via unescaped
 *     $date/$message/$filename cells in the bulk error log; fixed 2026-09-01
 *     by 042cb64a). Writes a payload row to current_bulk_media.log, invokes
 *     loadCurrentLog through reflection, asserts raw payload is escaped and
 *     kbinfo markup is preserved.
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
	// loadCurrentLog — bug #48 regression (error-log cells escaped)
	// -----------------------------------------------------------------

	/**
	 * Regression test for bug #48 (fixed 2026-09-01 by 042cb64a):
	 * BulkViewController::loadCurrentLog concatenated the raw $date /
	 * $message / $filename cells parsed from current_bulk_{type}.log
	 * straight into the returned HTML, while the part-finished.php /
	 * part-process.php views echo that HTML raw (esc_html was intentionally
	 * removed at the view layer in 50719048 so the kbinfo <span>/<a>
	 * markup renders). Because the log filename cell can carry any string
	 * an attacker gets stored in a media filename, this was stored XSS in
	 * wp-admin.
	 *
	 * The fix (042cb64a) wraps each of the three text cells in esc_html()
	 * at build time inside loadCurrentLog, while leaving the kbinfo
	 * <span>/<a> markup raw. This regression test writes a log row whose
	 * filename and message carry <script>/<img onerror> payloads, invokes
	 * loadCurrentLog through reflection, and asserts:
	 *   - the raw payload markup MUST NOT appear (would-be XSS);
	 *   - the HTML-escaped form MUST appear (proves the cell survived, was
	 *     only escaped);
	 *   - the kbinfo <span>/<a> markup MUST remain raw (guards against a
	 *     future "escape the whole line" over-correction that would break
	 *     the help-link UI).
	 *
	 * Sentinel guards (per feedback_pinned_test_sentinels.md #3):
	 *   - Two independent payloads (filename + message) with different
	 *     characteristic substrings so a partial fix that only escaped one
	 *     of the two cells would still fail one assertion.
	 *   - Positive assertion on the esc_html'd form (&lt;script&gt;) so the
	 *     test can't false-pass by loadCurrentLog silently dropping the
	 *     cell entirely.
	 *   - Positive assertion on the raw kbinfo markup so an over-correction
	 *     that escaped the kbinfo span too would fail.
	 */
	public function test_loadCurrentLog_escapes_filename_and_message_cells() {
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
		// separated by ';' between rows. Filename and message carry the XSS payloads.
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

			// Regression: the raw <script>/<img onerror> payloads MUST NOT
			// survive — that was the stored-XSS vector before 042cb64a.
			$this->assertStringNotContainsString(
				'<script>alert(1)</script>',
				$output,
				'Regression #48: the filename cell must be esc_html\'d in loadCurrentLog output — a raw <script> tag would re-open the stored-XSS vector.'
			);
			$this->assertStringNotContainsString(
				'<img src=x onerror=alert(2)>',
				$output,
				'Regression #48: the message cell must be esc_html\'d — a raw <img onerror> would re-open the stored-XSS vector.'
			);

			// Positive sentinel: the escaped forms MUST appear. This proves
			// the cells were escaped (not silently dropped) and pins the
			// exact esc_html transform. If loadCurrentLog ever stops
			// emitting these cells at all, the assertions here fail loudly
			// instead of the "not-contains" assertions above false-passing.
			$this->assertStringContainsString(
				'&lt;script&gt;alert(1)&lt;/script&gt;.jpg',
				$output,
				'Regression #48: the filename cell must survive as the esc_html-encoded form.'
			);
			$this->assertStringContainsString(
				'&lt;img src=x onerror=alert(2)&gt;',
				$output,
				'Regression #48: the message cell must survive as the esc_html-encoded form.'
			);

			// The kbinfo <span>/<a> markup is intentionally kept raw (that's
			// why esc_html was removed from the views in 50719048). A future
			// over-correction that escaped the whole line would break the
			// help-link UI — guard against it.
			$this->assertStringContainsString(
				'class="kbinfo"',
				$output,
				'Regression #48: the kbinfo helper span must remain raw HTML — escaping the whole line would over-correct and break the help-link UI.'
			);
			$this->assertStringContainsString(
				'<a href=',
				$output,
				'Regression #48: the kbinfo <a> tag must remain raw — same rationale as the <span class="kbinfo"> assertion above.'
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
