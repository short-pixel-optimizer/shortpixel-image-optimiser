<?php
/**
 * Tests for ShortPixel\Controller\View\SettingsViewController.
 *
 * Focus areas:
 *   - indicateAjaxSave() — simple flag setter.
 *   - addReturnFormData() — accumulator semantics.
 *   - settingLink() — HTML output, active-class injection, URL shape.
 *   - getMaxIntermediateImageSize() — floor-clamp, returns width+height array.
 *   - processWebP() — delivery-type collapsing logic (via reflection with
 *     mocked $is_nginx = false to avoid htaccess writes). Includes pinned
 *     regression for the assignment-as-comparison bug on line ~1325.
 *   - processExcludeFolders() — no exclusions key → empty array;
 *     valid JSON array → accepted; invalid regex → error-flagged entry.
 *   - processPostData() — exif inversion, png2jpg checkbox collapsing,
 *     excludeSizes normalisation, ignore-fields stripping.
 *   - doRedirect() URL-building in ajax-save mode only (avoids the
 *     wp_redirect()+exit() end path).
 *
 * NOT covered here (hit wp_redirect / wp_send_json / exit on every path):
 *   - load() / load_settings() — renders the settings template; too many
 *     singleton dependencies (QuotaController, BulkController, Offloader …).
 *   - action_addkey() / action_request_new_key() — always exit via doRedirect().
 *   - processSave() — calls doRedirect() on every branch.
 *   - handleAjaxSave() — calls wp_send_json() and exits.
 *   - loadQuotaData() / loadAPiKeyData() / loadDashBoardInfo() — require live
 *     QuotaController / ApiKeyController with a real or stub API key.
 *
 * BUG FIXED (b8d8f38d):
 *   processWebP() line ~1325: `elseif ($altering = 'deliverWebpAlteredGlobal')`
 *   ASSIGNMENT bug replaced with Yoda comparison `'deliverWebpAlteredGlobal' == $altering`.
 *   Unknown altering types now leave $deliverwebp = 0. Test updated accordingly.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\View\SettingsViewController;

class SettingsViewControllerTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Returns a fresh SettingsViewController bypassing the constructor.
	 *
	 * The real constructor calls wpSPIO()->settings() and ApiKeyController
	 * which require a bootstrapped environment. newInstanceWithoutConstructor()
	 * lets us test individual methods in isolation by seeding only the
	 * properties we need.
	 */
	private function freshController(): SettingsViewController {
		$ref = new ReflectionClass( SettingsViewController::class );
		$c   = $ref->newInstanceWithoutConstructor();

		// Seed the minimum view state that parent::__construct() would set up.
		$viewRef = new ReflectionClass( \ShortPixel\ViewController::class );

		$viewProp = $viewRef->getProperty( 'view' );
		$viewProp->setAccessible( true );
		$view          = new \stdClass;
		$view->notices = null;
		$view->data    = null;
		$viewProp->setValue( $c, $view );

		// Seed the url property (used by doRedirect / settingLink).
		$urlProp = $viewRef->getProperty( 'url' );
		$urlProp->setAccessible( true );
		$urlProp->setValue( $c, 'https://example.test/wp-admin/options-general.php?page=wp-shortpixel-settings' );

		return $c;
	}

	private function invokeProtected( SettingsViewController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( SettingsViewController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	private function getProtected( SettingsViewController $c, string $prop ) {
		$ref = new ReflectionClass( SettingsViewController::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function setProtected( SettingsViewController $c, string $prop, $value ): void {
		$ref = new ReflectionClass( SettingsViewController::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $c, $value );
	}

	public function set_up() {
		parent::set_up();
		wp_set_current_user( 0 );

		// Reset SettingsViewController singleton.
		$ref = new ReflectionClass( SettingsViewController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$p = $ref->getProperty( 'instance' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		$_GET  = array();
		$_POST = array();

		$ref = new ReflectionClass( SettingsViewController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$p = $ref->getProperty( 'instance' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}

		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// indicateAjaxSave — flag setter
	// -----------------------------------------------------------------

	public function test_indicateAjaxSave_sets_is_ajax_save_to_true() {
		$c = $this->freshController();

		$this->assertFalse( $this->getProtected( $c, 'is_ajax_save' ) );

		$c->indicateAjaxSave();

		// Sentinel: must flip to exactly true, not a truthy int.
		$this->assertTrue( $this->getProtected( $c, 'is_ajax_save' ) );
	}

	// -----------------------------------------------------------------
	// addReturnFormData — accumulator
	// -----------------------------------------------------------------

	public function test_addReturnFormData_appends_records_in_order() {
		$c = $this->freshController();

		$record1 = array( 'field' => 'CDNDomain', 'old_value' => 'bad', 'new_value' => 'fixed' );
		$record2 = array( 'field' => 'otherField', 'old_value' => 'x', 'new_value' => 'y' );

		$this->invokeProtected( $c, 'addReturnFormData', array( $record1 ) );
		$this->invokeProtected( $c, 'addReturnFormData', array( $record2 ) );

		$stored = $this->getProtected( $c, 'returnFormData' );

		// Sentinel: two entries appended in insertion order.
		$this->assertCount( 2, $stored );
		$this->assertSame( 'CDNDomain', $stored[0]['field'] );
		$this->assertSame( 'otherField', $stored[1]['field'] );
	}

	public function test_addReturnFormData_starts_empty() {
		$c      = $this->freshController();
		$stored = $this->getProtected( $c, 'returnFormData' );
		$this->assertIsArray( $stored );
		$this->assertCount( 0, $stored );
	}

	// -----------------------------------------------------------------
	// settingLink — HTML output
	// -----------------------------------------------------------------

	public function test_settingLink_returns_anchor_tag_with_part_arg() {
		$c = $this->freshController();
		$this->setProtected( $c, 'display_part', 'overview' );

		$html = $this->invokeProtected( $c, 'settingLink', array(
			array( 'part' => 'optimisation', 'title' => 'Optimisation' )
		) );

		// Sentinel-triplet: must be an anchor, must contain the part name, must
		// contain the page slug — any of the three absent indicates a URL-building regression.
		$this->assertStringContainsString( '<a ', $html );
		$this->assertStringContainsString( 'part=optimisation', $html );
		$this->assertStringContainsString( 'wp-shortpixel-settings', $html );
	}

	public function test_settingLink_adds_active_class_when_part_matches_display_part() {
		$c = $this->freshController();
		$this->setProtected( $c, 'display_part', 'webp' );

		$html = $this->invokeProtected( $c, 'settingLink', array(
			array( 'part' => 'webp', 'title' => 'WebP' )
		) );

		// Sentinel: 'active' class must appear when part == display_part.
		$this->assertStringContainsString( 'active', $html );
	}

	public function test_settingLink_does_not_add_active_class_for_non_matching_part() {
		$c = $this->freshController();
		$this->setProtected( $c, 'display_part', 'overview' );

		$html = $this->invokeProtected( $c, 'settingLink', array(
			array( 'part' => 'debug', 'title' => 'Debug' )
		) );

		// Sentinel: 'active' must NOT appear when parts differ.
		$this->assertStringNotContainsString( 'active', $html );
	}

	public function test_settingLink_prepends_dashicon_when_icon_is_given_and_position_is_left() {
		$c = $this->freshController();

		$html = $this->invokeProtected( $c, 'settingLink', array(
			array( 'part' => 'help', 'title' => 'Help', 'icon' => 'dashicons-editor-help', 'icon_position' => 'left' )
		) );

		$this->assertStringContainsString( 'dashicons-editor-help', $html );
	}

	// -----------------------------------------------------------------
	// getMaxIntermediateImageSize — floor clamp
	// -----------------------------------------------------------------

	public function test_getMaxIntermediateImageSize_returns_array_with_width_and_height_keys() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getMaxIntermediateImageSize' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'width', $result );
		$this->assertArrayHasKey( 'height', $result );
	}

	public function test_getMaxIntermediateImageSize_clamps_result_to_at_least_100() {
		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getMaxIntermediateImageSize' );

		// Sentinel: both dimensions must be >= 100 regardless of registered sizes.
		$this->assertGreaterThanOrEqual( 100, $result['width'] );
		$this->assertGreaterThanOrEqual( 100, $result['height'] );
	}

	// -----------------------------------------------------------------
	// processExcludeFolders — pattern parsing
	// -----------------------------------------------------------------

	public function test_processExcludeFolders_returns_empty_array_when_no_exclusions_key() {
		$c    = $this->freshController();
		$post = array( 'somethingElse' => 'value' );

		$result = $this->invokeProtected( $c, 'processExcludeFolders', array( $post ) );

		// Sentinel: must set excludePatterns to an empty array, not null / missing.
		$this->assertArrayHasKey( 'excludePatterns', $result );
		$this->assertSame( array(), $result['excludePatterns'] );
	}

	public function test_processExcludeFolders_decodes_valid_json_entries_into_accepted_array() {
		$c = $this->freshController();

		$entry = json_encode( array( 'type' => 'name', 'value' => 'my-image' ) );
		$post  = array( 'exclusions' => array( $entry ) );

		$result = $this->invokeProtected( $c, 'processExcludeFolders', array( $post ) );

		$this->assertArrayHasKey( 'excludePatterns', $result );
		$this->assertCount( 1, $result['excludePatterns'] );
		$this->assertSame( 'my-image', $result['excludePatterns'][0]['value'] );
	}

	public function test_processExcludeFolders_flags_invalid_regex_with_has_error() {
		$c = $this->freshController();

		// Intentionally broken regex (unbalanced brackets).
		$entry = json_encode( array( 'type' => 'regex-name', 'value' => '[invalid' ) );
		$post  = array( 'exclusions' => array( $entry ) );

		// The method adds a Notice::addWarning() for invalid patterns; swallow that.
		$result = $this->invokeProtected( $c, 'processExcludeFolders', array( $post ) );

		$this->assertArrayHasKey( 'excludePatterns', $result );
		$this->assertCount( 1, $result['excludePatterns'] );
		// Sentinel: invalid regex must be flagged — a regression that dropped the
		// preg_match() check would leave 'has-error' absent.
		$this->assertArrayHasKey( 'has-error', $result['excludePatterns'][0] );
		$this->assertTrue( $result['excludePatterns'][0]['has-error'] );
	}

	public function test_processExcludeFolders_accepts_valid_regex() {
		$c = $this->freshController();

		$entry = json_encode( array( 'type' => 'regex-name', 'value' => '/^my-pattern$/' ) );
		$post  = array( 'exclusions' => array( $entry ) );

		$result = $this->invokeProtected( $c, 'processExcludeFolders', array( $post ) );

		$this->assertCount( 1, $result['excludePatterns'] );
		// Sentinel: valid regex must NOT be flagged with has-error.
		$this->assertArrayNotHasKey( 'has-error', $result['excludePatterns'][0] );
	}

	// -----------------------------------------------------------------
	// processWebP — delivery-type collapsing
	// -----------------------------------------------------------------

	/**
	 * Helper: returns a base POST array with deliverWebp enabled and $is_nginx
	 * seeded on the controller to avoid htaccess writes.
	 */
	private function makeWebpPost( string $type, string $altering = '' ): array {
		return array(
			'deliverWebp'           => '1',
			'createWebp'            => '1',
			'deliverWebpType'       => $type,
			'deliverWebpAlteringType' => $altering,
		);
	}

	public function test_processWebP_returns_0_when_deliverWebp_is_not_set() {
		$c = $this->freshController();
		$this->setProtected( $c, 'is_nginx', true ); // avoid htaccess calls

		$post   = array( 'createWebp' => '1' );
		$result = $this->invokeProtected( $c, 'processWebP', array( $post ) );

		// Sentinel: no deliverWebp key in POST → collapsed value must be 0 (disabled).
		$this->assertSame( 0, $result['deliverWebp'] );
	}

	public function test_processWebP_returns_3_for_unaltered_delivery_type() {
		$c = $this->freshController();
		$this->setProtected( $c, 'is_nginx', true );

		$post   = $this->makeWebpPost( 'deliverWebpUnaltered' );
		$result = $this->invokeProtected( $c, 'processWebP', array( $post ) );

		$this->assertSame( 3, $result['deliverWebp'] );
	}

	public function test_processWebP_unknown_altering_type_gives_0() {
		/**
		 * Bug #12 FIXED (b8d8f38d): the elseif assignment bug
		 *   `elseif ($altering = 'deliverWebpAlteredGlobal')`
		 * has been replaced with a Yoda comparison
		 *   `elseif ('deliverWebpAlteredGlobal' == $altering)`.
		 *
		 * Previously, an empty (or unknown) altering type caused the assignment to
		 * evaluate as truthy, always setting $deliverwebp = 1 (global htaccess).
		 * After the fix, an empty altering type correctly leaves $deliverwebp = 0
		 * (no valid sub-type selected).
		 */
		$c = $this->freshController();
		$this->setProtected( $c, 'is_nginx', true );

		// deliverWebpType is 'deliverWebpAltered' but altering type is empty —
		// no valid sub-type is active, so the correct result is 0.
		$post = array(
			'deliverWebp'             => '1',
			'createWebp'              => '1',
			'deliverWebpType'         => 'deliverWebpAltered',
			'deliverWebpAlteringType' => '', // empty — neither WP nor global
		);

		$result = $this->invokeProtected( $c, 'processWebP', array( $post ) );

		// Bug #12 FIXED (b8d8f38d): now correctly 0 when no sub-type is selected.
		$this->assertSame( 0, $result['deliverWebp'],
			'With an empty altering type, deliverWebp must be 0 (no valid sub-type)'
		);
	}

	public function test_processWebP_correctly_gives_2_for_WP_picture_tag() {
		$c = $this->freshController();
		$this->setProtected( $c, 'is_nginx', true );

		$post   = $this->makeWebpPost( 'deliverWebpAltered', 'deliverWebpAlteredWP' );
		$result = $this->invokeProtected( $c, 'processWebP', array( $post ) );

		// The WP picture-tag branch IS reachable correctly (first if, not elseif).
		// This is unaffected by the assignment bug and should always give 2.
		$this->assertSame( 2, $result['deliverWebp'] );
	}

	public function test_processWebP_strips_sub_type_fields_from_result() {
		$c = $this->freshController();
		$this->setProtected( $c, 'is_nginx', true );

		$post   = $this->makeWebpPost( 'deliverWebpAltered', 'deliverWebpAlteredWP' );
		$result = $this->invokeProtected( $c, 'processWebP', array( $post ) );

		// Sentinel-pair: both sub-type keys must be absent from the processed array
		// so they are not accidentally written to the settings model.
		$this->assertArrayNotHasKey( 'deliverWebpAlteringType', $result );
		$this->assertArrayNotHasKey( 'deliverWebpType', $result );
	}

	// -----------------------------------------------------------------
	// processPostData — field transforms
	// -----------------------------------------------------------------

	/**
	 * processPostData() calls parent::processPostData() at the end, which
	 * requires $this->model to be set. We feed a minimal mock-like stdClass so
	 * we can observe the transformations that happen before the parent call.
	 *
	 * The parent call will try to iterate over $this->model->getData(), so we
	 * provide a stub that returns an empty array for getData() and accepts any
	 * property set.
	 */
	private function seedModel( SettingsViewController $c ): void {
		// Minimal SettingsModel stand-in: getData() returns [], getType() returns 'string',
		// exists() returns true.
		$stub = new class {
			public $compressionType = 0;
			public $useCDN          = false;
			public $CDNDomain       = '';
			public $enable_ai       = false;
			public function getData() { return []; }
			public function getType( $name ) { return 'string'; }
			public function exists( $name ) { return true; }
			// parent::processPostData() ends in $this->model->getSanitizedData($post, false)
			// and assigns the result to $this->postData. All transforms under test
			// (exif inversion, png2jpg collapsing, …) happen BEFORE that call, so a
			// pass-through lets the assertions observe them unaltered.
			public function getSanitizedData( $post, $strict = false ) { return $post; }
		};
		$this->setProtected( $c, 'model', $stub );

		// Also stub keyModel so the apiKey branch in processPostData doesn't blow up.
		$keyStub = new class {
			public function is_constant() { return true; }
			public function is_verified() { return false; }
		};
		$this->setProtected( $c, 'keyModel', $keyStub );
	}

	/**
	 * Invokes processPostData() and closes any output buffers it leaves open.
	 *
	 * processPostData() constructs a CDNController, whose init() calls
	 * startOutputBuffer('processFront') — an ob_start() with a flush callback
	 * meant to wrap the whole page in a real request. In a test it simply
	 * leaks a buffer level, which PHPUnit flags as risky. Discard it here.
	 */
	private function invokeProcessPostData( SettingsViewController $c, array $post ) {
		$level = ob_get_level();
		try {
			return $this->invokeProtected( $c, 'processPostData', array( $post ) );
		} finally {
			$guard = 0;
			while ( ob_get_level() > $level && $guard++ < 10 ) {
				if ( ! @ob_end_clean() ) {
					break;
				}
			}
		}
	}

	public function test_processPostData_inverts_exif_checkbox() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		// exif NOT in POST → should become 1 (keep EXIF on).
		$post = array(
			'compressionType' => '1',
			'deliverWebp'     => '0',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );

		// Sentinel: exif absent in POST means "don't strip EXIF" → stored as 1.
		$this->assertSame( 1, $postData['exif'] );
	}

	public function test_processPostData_sets_exif_to_0_when_checkbox_present() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		// exif PRESENT in POST → should become 0 (strip EXIF).
		$post = array(
			'compressionType' => '1',
			'exif'            => '1',
			'deliverWebp'     => '0',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );
		$this->assertSame( 0, $postData['exif'] );
	}

	public function test_processPostData_collapses_png2jpg_checkbox_pair() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		// png2jpg=1 + png2jpgForce=1 → 2.
		$post = array(
			'compressionType' => '1',
			'png2jpg'         => '1',
			'png2jpgForce'    => '1',
			'deliverWebp'     => '0',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );
		// Sentinel-triplet: 0/1/2 are the three states; the forced variant must be 2.
		$this->assertSame( 2, $postData['png2jpg'] );
	}

	public function test_processPostData_sets_png2jpg_to_1_when_only_png2jpg_present() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		$post = array(
			'compressionType' => '1',
			'png2jpg'         => '1',
			'deliverWebp'     => '0',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );
		$this->assertSame( 1, $postData['png2jpg'] );
	}

	public function test_processPostData_sets_png2jpg_to_0_when_absent() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		$post = array(
			'compressionType' => '1',
			'deliverWebp'     => '0',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );
		$this->assertSame( 0, $postData['png2jpg'] );
	}

	public function test_processPostData_normalises_excludeSizes_to_empty_array_when_absent() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		$post = array(
			'compressionType' => '1',
			'deliverWebp'     => '0',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );
		$this->assertIsArray( $postData['excludeSizes'] );
		$this->assertCount( 0, $postData['excludeSizes'] );
	}

	public function test_processPostData_strips_ignore_fields() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );

		$post = array(
			'compressionType'  => '1',
			'deliverWebp'      => '0',
			'sp-nonce'         => 'abc123',
			'_wp_http_referer' => '/wp-admin/',
			'screen_action'    => 'form_submit',
			'nonce'            => 'nonce_value',
			'save'             => 'Save Settings',
		);
		$this->invokeProcessPostData( $c, $post );

		$postData = $this->getProtected( $c, 'postData' );

		// Sentinel-set: every ignore-listed key must be absent from postData.
		$this->assertArrayNotHasKey( 'sp-nonce', $postData );
		$this->assertArrayNotHasKey( '_wp_http_referer', $postData );
		$this->assertArrayNotHasKey( 'screen_action', $postData );
		$this->assertArrayNotHasKey( 'nonce', $postData );
		$this->assertArrayNotHasKey( 'save', $postData );
	}

	public function test_processPostData_sets_do_redirect_when_save_bulk_present() {
		$c = $this->freshController();
		$this->seedModel( $c );
		$this->setProtected( $c, 'is_nginx', true );
		$this->setProtected( $c, 'postData', array() );
		$this->setProtected( $c, 'do_redirect', false );

		$post = array(
			'compressionType' => '1',
			'deliverWebp'     => '0',
			'save-bulk'       => 'Save & Bulk Optimize',
		);
		$this->invokeProcessPostData( $c, $post );

		// Sentinel: save-bulk in POST must flip $this->do_redirect to true.
		$this->assertTrue( $this->getProtected( $c, 'do_redirect' ) );
	}

	// -----------------------------------------------------------------
	// doRedirect — ajax-save path only
	// -----------------------------------------------------------------

	/**
	 * Builds a spy that intercepts at the handleAjaxSave() seam.
	 *
	 * doRedirect() with is_ajax_save = true calls handleAjaxSave($redirect, $url),
	 * which in production ends in wp_send_json() → plain `die` (uncatchable
	 * outside a real AJAX request — it would kill the whole PHPUnit process).
	 * The spy records the arguments and throws to halt doRedirect() BEFORE it
	 * falls through to its own wp_redirect() + exit() tail.
	 */
	private function ajaxSaveSpy(): SettingsViewController {
		return new class extends SettingsViewController {
			public $spyRedirect = null;
			public $spyUrl      = null;
			public function __construct() { /* no hooks, no model loading */ }
			protected function handleAjaxSave( $redirect, $url = false ) {
				$this->spyRedirect = $redirect;
				$this->spyUrl      = $url;
				throw new \RuntimeException( 'ajax-save-intercepted' );
			}
		};
	}

	public function test_doRedirect_in_ajax_mode_bulk_targets_bulk_page_url() {
		$c = $this->ajaxSaveSpy();
		$this->setProtected( $c, 'is_ajax_save', true );
		$this->setProtected( $c, 'display_part', 'optimisation' );

		try {
			$this->invokeProtected( $c, 'doRedirect', array( 'bulk' ) );
			$this->fail( 'Expected doRedirect to enter handleAjaxSave()' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'ajax-save-intercepted', $e->getMessage() );
		}

		// Sentinel: 'bulk' must resolve to the bulk-optimize admin page URL.
		$this->assertSame( 'bulk', $c->spyRedirect );
		$this->assertStringContainsString( 'wp-short-pixel-bulk', $c->spyUrl );
	}

	public function test_doRedirect_in_ajax_mode_self_passes_self_marker_through() {
		$c = $this->ajaxSaveSpy();
		$this->setProtected( $c, 'is_ajax_save', true );
		$this->setProtected( $c, 'display_part', 'overview' );
		$this->setProtected( $c, 'url', 'https://example.test/wp-admin/options-general.php?page=wp-shortpixel-settings' );

		try {
			$this->invokeProtected( $c, 'doRedirect', array( 'self' ) );
			$this->fail( 'Expected doRedirect to enter handleAjaxSave()' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'ajax-save-intercepted', $e->getMessage() );
		}

		// Sentinel: the 'self' marker must reach handleAjaxSave unchanged —
		// production only adds the 'redirect' JSON key when $redirect !== 'self',
		// so mangling this marker would reintroduce an unwanted page redirect.
		$this->assertSame( 'self', $c->spyRedirect );
	}
}
