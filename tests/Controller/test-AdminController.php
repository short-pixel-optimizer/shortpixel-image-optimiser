<?php
/**
 * Tests for ShortPixel\Controller\AdminController.
 *
 * Focus areas (pure controller logic that does not need a live optimization run):
 *   - getInstance() — singleton contract and protected static $instance reset.
 *   - generatePluginLinks() — prepends Settings link; verifies array shape.
 *   - addMimes() — HEIC/HEIF always added; WebP/AVIF conditionally added based
 *     on settings; existing values preserved.
 *   - checkPlaceHolder() — returns original URL for non-convertable extensions;
 *     returns modified URL only when the extension is in CONVERTABLE_EXTENSIONS.
 *   - preventImageHook() / static $preventUploadHook interaction — IDs added to
 *     the suppress list are reflected when checked via the public hook surface.
 *   - processCronHook() argument normalisation — verifies args shape passed to
 *     processQueueHook via a spy sub-class (avoids starting the queue loop).
 *   - editAttachmentScreen() — current implementation returns immediately (stub).
 *   - filter_add_where() — SQL fragments for optimized/unoptimized/prevented filters.
 *
 * Out of scope (and why):
 *   - handleImageUploadHook() / handleAiImageUploadHook() — require wpSPIO filesystem,
 *     Converter, and QueueController; tested via integration tests.
 *   - addAttachmentHook() — requires wpSPIO filesystem and Converter.
 *   - onDeleteAttachment() — requires filesystem and MediaLibraryModel.
 *   - processQueueHook() / scanCustomFoldersHook() — starts real queue processing loops;
 *     integration territory.
 *   - checkRestMedia() — requires a fully formed WP_REST_Response with media model data.
 *   - toolbar_shortpixel_processing() — requires QuotaController and AccessModel state.
 *   - printComparer() — requires WP screen infrastructure.
 *   - handleReplaceHook() / handleReplaceEnqueue() / handleAiReplaceEnqueue() — delegation
 *     wrappers; covered by the wrapped method's tests.
 *   - filter_listener() — requires $pagenow global and WP_Query setup.
 *   - loadCronCompat() — file inclusion; not safe to test at unit level.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AdminController;
use ShortPixel\Model\Converter\ApiConverter;

class AdminControllerTest extends WP_UnitTestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Reset the singleton so each test starts with a clean instance. */
	private function resetSingleton(): void {
		$ref = new ReflectionClass( AdminController::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	/** Reset the private static preventUploadHook list between tests. */
	private function resetPreventList(): void {
		$ref = new ReflectionClass( AdminController::class );
		$p   = $ref->getProperty( 'preventUploadHook' );
		$p->setAccessible( true );
		$p->setValue( null, array() );
	}

	/** Reset the private static recentUploads list between tests. */
	private function resetRecentUploads(): void {
		$ref = new ReflectionClass( AdminController::class );
		$p   = $ref->getProperty( 'recentUploads' );
		$p->setAccessible( true );
		$p->setValue( null, array() );
	}

	private function getStaticProp( string $prop ) {
		$ref = new ReflectionClass( AdminController::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( null );
	}

	private function invokePrivate( AdminController $obj, string $method, array $args = array() ) {
		$ref = new ReflectionClass( AdminController::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $obj, ...$args );
	}

	/** Seed the spio_settings option so wpSPIO()->settings() returns expected values. */
	private function seedSettings( array $overrides = array() ): void {
		$current = get_option( 'spio_settings', array() );
		update_option( 'spio_settings', array_merge( $current, $overrides ) );
		// Force the SettingsModel singleton to re-read from the DB on next access.
		// wpSPIO()->settings() delegates to SettingsModel::getInstance(), so we must
		// reset the SettingsModel static $instance rather than a property on wpSPIO().
		$ref = new ReflectionClass( \ShortPixel\Model\SettingsModel::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
	}

	public function set_up() {
		parent::set_up();
		$this->resetSingleton();
		$this->resetPreventList();
		$this->resetRecentUploads();
	}

	public function tear_down() {
		$this->resetSingleton();
		$this->resetPreventList();
		$this->resetRecentUploads();
		delete_option( 'spio_settings' );
		// Also reset SettingsModel singleton so a stale in-memory instance doesn't
		// bleed settings values into the next test after seedSettings() was called.
		$ref = new ReflectionClass( \ShortPixel\Model\SettingsModel::class );
		$p   = $ref->getProperty( 'instance' );
		$p->setAccessible( true );
		$p->setValue( null, null );
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// getInstance — singleton contract
	// -------------------------------------------------------------------------

	public function test_getInstance_returns_AdminController_instance() {
		$a = AdminController::getInstance();
		$this->assertInstanceOf( AdminController::class, $a );
	}

	public function test_getInstance_returns_same_instance_on_repeated_calls() {
		$a = AdminController::getInstance();
		$b = AdminController::getInstance();
		$this->assertSame( $a, $b );
	}

	public function test_getInstance_returns_new_instance_after_singleton_reset() {
		$a = AdminController::getInstance();
		$this->resetSingleton();
		$b = AdminController::getInstance();
		$this->assertNotSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// generatePluginLinks — prepend Settings link
	// -------------------------------------------------------------------------

	public function test_generatePluginLinks_returns_array() {
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->generatePluginLinks( array() );
		$this->assertIsArray( $result );
	}

	public function test_generatePluginLinks_first_element_is_settings_anchor() {
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->generatePluginLinks( array() );
		$first  = reset( $result );
		$this->assertStringContainsString( 'Settings', $first );
		$this->assertStringContainsString( 'wp-shortpixel-settings', $first );
	}

	public function test_generatePluginLinks_existing_links_preserved_after_settings() {
		$ctrl     = AdminController::getInstance();
		$existing = array( '<a href="#">Deactivate</a>' );
		$result   = $ctrl->generatePluginLinks( $existing );

		// Settings added at front → original link must now be at index 1.
		$this->assertCount( 2, $result );
		$this->assertSame( $existing[0], $result[1] );
	}

	public function test_generatePluginLinks_with_multiple_existing_links_keeps_all() {
		$ctrl     = AdminController::getInstance();
		$existing = array( '<a href="#">Activate</a>', '<a href="#">Delete</a>' );
		$result   = $ctrl->generatePluginLinks( $existing );
		$this->assertCount( 3, $result );
	}

	// -------------------------------------------------------------------------
	// addMimes — MIME type extension
	// -------------------------------------------------------------------------

	public function test_addMimes_always_adds_heic() {
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array() );
		$this->assertArrayHasKey( 'heic', $result );
		$this->assertSame( 'image/heic', $result['heic'] );
	}

	public function test_addMimes_always_adds_heif() {
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array() );
		$this->assertArrayHasKey( 'heif', $result );
		$this->assertSame( 'image/heif', $result['heif'] );
	}

	public function test_addMimes_does_not_overwrite_existing_heic_entry() {
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array( 'heic' => 'image/x-heic' ) );
		// pre-existing value must be preserved (the guard `if (! isset(...))`)
		$this->assertSame( 'image/x-heic', $result['heic'] );
	}

	public function test_addMimes_adds_webp_when_createWebp_setting_enabled() {
		$this->seedSettings( array( 'createWebp' => 1 ) );
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array() );
		$this->assertArrayHasKey( 'webp', $result );
		$this->assertSame( 'image/webp', $result['webp'] );
	}

	public function test_addMimes_does_not_add_webp_when_createWebp_setting_disabled() {
		$this->seedSettings( array( 'createWebp' => 0 ) );
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array() );
		$this->assertArrayNotHasKey( 'webp', $result );
	}

	public function test_addMimes_adds_avif_when_createAvif_setting_enabled() {
		$this->seedSettings( array( 'createAvif' => 1 ) );
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array() );
		$this->assertArrayHasKey( 'avif', $result );
		$this->assertSame( 'image/avif', $result['avif'] );
	}

	public function test_addMimes_does_not_add_avif_when_createAvif_setting_disabled() {
		$this->seedSettings( array( 'createAvif' => 0 ) );
		$ctrl   = AdminController::getInstance();
		$result = $ctrl->addMimes( array() );
		$this->assertArrayNotHasKey( 'avif', $result );
	}

	public function test_addMimes_preserves_unrelated_mime_entries() {
		$ctrl   = AdminController::getInstance();
		$input  = array( 'jpg' => 'image/jpeg', 'png' => 'image/png' );
		$result = $ctrl->addMimes( $input );
		$this->assertArrayHasKey( 'jpg', $result );
		$this->assertArrayHasKey( 'png', $result );
	}

	// -------------------------------------------------------------------------
	// checkPlaceHolder — URL rewriting for convertable extensions
	// -------------------------------------------------------------------------

	public function test_checkPlaceHolder_returns_original_url_for_jpeg_extension() {
		$ctrl = AdminController::getInstance();
		$url  = 'https://example.com/wp-content/uploads/photo.jpg';
		// jpg is not in ApiConverter::CONVERTABLE_EXTENSIONS → must pass through unchanged.
		$result = $ctrl->checkPlaceHolder( $url, 0 );
		$this->assertSame( $url, $result );
	}

	public function test_checkPlaceHolder_returns_original_url_for_png_extension() {
		$ctrl = AdminController::getInstance();
		$url  = 'https://example.com/wp-content/uploads/photo.png';
		$result = $ctrl->checkPlaceHolder( $url, 0 );
		$this->assertSame( $url, $result );
	}

	public function test_checkPlaceHolder_returns_original_url_for_webp_extension() {
		$ctrl = AdminController::getInstance();
		$url  = 'https://example.com/wp-content/uploads/photo.webp';
		$result = $ctrl->checkPlaceHolder( $url, 0 );
		$this->assertSame( $url, $result );
	}

	/**
	 * Verify CONVERTABLE_EXTENSIONS constant is the list the production code
	 * uses.  If the constant changes (new formats added), this sentinel must be
	 * updated to reflect the new list.
	 */
	public function test_ApiConverter_CONVERTABLE_EXTENSIONS_contains_expected_set() {
		$expected = array( 'heic', 'tiff', 'tif', 'bmp' );
		$this->assertSame( $expected, ApiConverter::CONVERTABLE_EXTENSIONS );
	}

	// -------------------------------------------------------------------------
	// preventImageHook — adds IDs to the static suppress list
	// -------------------------------------------------------------------------

	public function test_preventImageHook_adds_id_to_prevent_list() {
		$ctrl = AdminController::getInstance();
		$ctrl->preventImageHook( 42 );

		$list = $this->getStaticProp( 'preventUploadHook' );
		$this->assertContains( 42, $list );
	}

	public function test_preventImageHook_accumulates_multiple_ids() {
		$ctrl = AdminController::getInstance();
		$ctrl->preventImageHook( 10 );
		$ctrl->preventImageHook( 20 );
		$ctrl->preventImageHook( 30 );

		$list = $this->getStaticProp( 'preventUploadHook' );
		$this->assertContains( 10, $list );
		$this->assertContains( 20, $list );
		$this->assertContains( 30, $list );
	}

	public function test_preventImageHook_does_not_deduplicate_ids() {
		// The production code does no deduplication; this sentinel pins that.
		$ctrl = AdminController::getInstance();
		$ctrl->preventImageHook( 99 );
		$ctrl->preventImageHook( 99 );

		$list = $this->getStaticProp( 'preventUploadHook' );
		$this->assertSame( 2, count( array_keys( $list, 99 ) ) );
	}

	// -------------------------------------------------------------------------
	// editAttachmentScreen — current implementation is a stub that returns void
	// -------------------------------------------------------------------------

	public function test_editAttachmentScreen_returns_null_immediately() {
		$ctrl   = AdminController::getInstance();
		$post   = new \WP_Post( (object) array( 'ID' => 1 ) );
		$result = $ctrl->editAttachmentScreen( array(), $post );
		// The method has `return;` before any logic → must be null.
		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// processCronHook — arg normalisation (pure logic, no queue execution)
	// -------------------------------------------------------------------------

	/**
	 * Use an anonymous-class spy to capture args without running the queue loop.
	 */
	public function test_processCronHook_passes_max_runs_10_to_processQueueHook() {
		$captured = null;

		$spy = new class extends AdminController {
			public $capturedArgs;
			public function processQueueHook( $args = array() ) {
				$this->capturedArgs = $args;
			}
		};

		$spy->processCronHook( false );

		$this->assertSame( 10, $spy->capturedArgs['max_runs'] );
	}

	public function test_processCronHook_passes_timelimit_50_to_processQueueHook() {
		$spy = new class extends AdminController {
			public $capturedArgs;
			public function processQueueHook( $args = array() ) {
				$this->capturedArgs = $args;
			}
		};

		$spy->processCronHook( false );

		$this->assertSame( 50, $spy->capturedArgs['timelimit'] );
	}

	public function test_processCronHook_passes_source_cron_to_processQueueHook() {
		$spy = new class extends AdminController {
			public $capturedArgs;
			public function processQueueHook( $args = array() ) {
				$this->capturedArgs = $args;
			}
		};

		$spy->processCronHook( false );

		$this->assertSame( 'cron', $spy->capturedArgs['source'] );
	}

	public function test_processCronHook_unwraps_bulk_array_before_passing() {
		// When $bulk is array('bulk' => true), the inner value should be passed.
		$spy = new class extends AdminController {
			public $capturedArgs;
			public function processQueueHook( $args = array() ) {
				$this->capturedArgs = $args;
			}
		};

		$spy->processCronHook( array( 'bulk' => true ) );

		$this->assertTrue( $spy->capturedArgs['bulk'] );
	}

	public function test_processCronHook_passes_raw_bool_bulk_unchanged() {
		$spy = new class extends AdminController {
			public $capturedArgs;
			public function processQueueHook( $args = array() ) {
				$this->capturedArgs = $args;
			}
		};

		$spy->processCronHook( true );

		$this->assertTrue( $spy->capturedArgs['bulk'] );
	}

	// -------------------------------------------------------------------------
	// filter_add_where — SQL fragment generation
	// -------------------------------------------------------------------------

	/**
	 * 'all' filter: where clause returned unchanged.
	 */
	public function test_filter_add_where_returns_where_unchanged_for_all_filter() {
		$ctrl = AdminController::getInstance();

		// Simulate 'all' being selected (no filter_action in REQUEST).
		unset( $_REQUEST['filter_action'] );
		$_REQUEST['shortpixel_status'] = 'all';

		$original = ' AND 1=1';
		$result   = $ctrl->filter_add_where( $original, new \WP_Query() );

		$this->assertSame( $original, $result );

		unset( $_REQUEST['shortpixel_status'] );
	}

	/**
	 * 'optimized' filter: WHERE clause must contain a sub-select targeting
	 * the status column from the ShortPixel meta table.
	 *
	 * Pinned-current-behavior: tests that the SQL is appended (not replaced).
	 */
	public function test_filter_add_where_optimized_appends_subselect_with_status() {
		$ctrl = AdminController::getInstance();

		$_REQUEST['filter_action']      = '1';
		$_REQUEST['shortpixel_status']  = 'optimized';

		$base   = 'WHERE 1=1';
		$result = $ctrl->filter_add_where( $base, new \WP_Query() );

		$this->assertStringStartsWith( $base, $result );
		$this->assertStringContainsString( 'status', $result );
		$this->assertStringContainsString( 'IN', strtoupper( $result ) );

		unset( $_REQUEST['filter_action'], $_REQUEST['shortpixel_status'] );
	}

	/**
	 * 'unoptimized' filter: WHERE clause must contain NOT IN and a status condition.
	 */
	public function test_filter_add_where_unoptimized_appends_not_in_subselect() {
		$ctrl = AdminController::getInstance();

		$_REQUEST['filter_action']     = '1';
		$_REQUEST['shortpixel_status'] = 'unoptimized';

		$base   = 'WHERE 1=1';
		$result = $ctrl->filter_add_where( $base, new \WP_Query() );

		$this->assertStringStartsWith( $base, $result );
		$this->assertStringContainsString( 'NOT IN', strtoupper( $result ) );

		unset( $_REQUEST['filter_action'], $_REQUEST['shortpixel_status'] );
	}

	/**
	 * 'prevented' filter: WHERE clause references _shortpixel_prevent_optimize meta key.
	 *
	 * Bug #26 FIXED (ea3cd51a): the 'prevented' branch now uses `$where .=` instead of
	 * `$where =`, so the original WHERE fragment is preserved and only the new sub-select
	 * is appended.  Previously the original $where was silently discarded.
	 */
	public function test_filter_add_where_prevented_references_prevent_meta_key() {
		$ctrl = AdminController::getInstance();

		$_REQUEST['filter_action']     = '1';
		$_REQUEST['shortpixel_status'] = 'prevented';

		$base   = 'WHERE 1=1';
		$result = $ctrl->filter_add_where( $base, new \WP_Query() );

		$this->assertStringContainsString( '_shortpixel_prevent_optimize', $result );
		// Bug #26 FIXED (ea3cd51a): base must be preserved (append, not replace).
		$this->assertStringStartsWith( $base, $result );

		unset( $_REQUEST['filter_action'], $_REQUEST['shortpixel_status'] );
	}
}
