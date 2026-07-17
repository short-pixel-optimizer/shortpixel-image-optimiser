<?php
/**
 * Tests for ShortPixel\Controller\View\OtherMediaViewController.
 *
 * Focus areas:
 *   - Constructor reads GET parameters into $currentPage, $orderby, $order,
 *     $search, $show_hidden.
 *   - loadScreenPerPageOption() — returns default when option is absent or zero;
 *     returns the stored value when positive.
 *   - getHeadings() — returns an array with expected column keys.
 *   - filterAllowedOrderBy() — accepts column names listed in headings;
 *     rejects arbitrary strings.
 *   - setScreenOption() — returns intval when the option matches the constant;
 *     passes $status through for other options.
 *   - getPagination() — returns false when total_items <= items_per_page;
 *     returns a non-empty string when there are multiple pages.
 *
 * NOT covered here:
 *   - load() — calls loadView() which requires a template on disk + WordPress screen.
 *   - getItems() / queryItems() — require the wp_shortpixel_meta table and active
 *     OtherMediaController directories.
 *   - doActionColumn() — renders a template; output-only, no return value to assert.
 *   - getFilter() — reads $_GET and builds SQL-interpolated objects; the SQL-assembly
 *     path cannot be safely exercised without a real DB context outside integration tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\View\OtherMediaViewController;

class OtherMediaViewControllerTest extends WP_UnitTestCase {

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/**
	 * Returns a fresh OtherMediaViewController bypassing the constructor.
	 *
	 * The real constructor reads $_GET and calls loadScreenPerPageOption()
	 * which calls get_user_option(); by skipping it we can seed properties
	 * manually and avoid side-effects.
	 */
	private function freshController(): OtherMediaViewController {
		$ref = new ReflectionClass( OtherMediaViewController::class );
		$c   = $ref->newInstanceWithoutConstructor();

		// Seed minimum view state from parent ViewController.
		$viewRef  = new ReflectionClass( \ShortPixel\ViewController::class );
		$viewProp = $viewRef->getProperty( 'view' );
		$viewProp->setAccessible( true );
		$view          = new \stdClass;
		$view->notices = null;
		$view->data    = null;
		$viewProp->setValue( $c, $view );

		// Seed URL (used by getPagination).
		$urlProp = $viewRef->getProperty( 'url' );
		$urlProp->setAccessible( true );
		$urlProp->setValue( $c, 'https://example.test/wp-admin/upload.php?page=wp-short-pixel-custom' );

		return $c;
	}

	private function invokeProtected( OtherMediaViewController $c, string $method, array $args = array() ) {
		$ref = new ReflectionClass( OtherMediaViewController::class );
		while ( $ref && ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Method $method not found on any ancestor" );
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $c, ...$args );
	}

	private function getProtected( OtherMediaViewController $c, string $prop ) {
		$ref = new ReflectionClass( OtherMediaViewController::class );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$this->assertNotFalse( $ref, "Property $prop not found on any ancestor" );
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $c );
	}

	private function setProtected( OtherMediaViewController $c, string $prop, $value ): void {
		$ref = new ReflectionClass( OtherMediaViewController::class );
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
		$_GET = array();

		$ref = new ReflectionClass( OtherMediaViewController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$p = $ref->getProperty( 'instance' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}

	public function tear_down() {
		wp_set_current_user( 0 );
		$_GET = array();

		$ref = new ReflectionClass( OtherMediaViewController::class );
		if ( $ref->hasProperty( 'instance' ) ) {
			$p = $ref->getProperty( 'instance' );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}

		parent::tear_down();
	}

	// -----------------------------------------------------------------
	// Constructor — GET-parameter seeding
	// -----------------------------------------------------------------

	public function test_constructor_defaults_currentPage_to_1_when_paged_absent() {
		$_GET = array();
		// Use the real constructor this time (no heavy singletons needed for GET-only).
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c = new OtherMediaViewController();

		$this->assertSame( 1, $this->getProtected( $c, 'currentPage' ) );
	}

	public function test_constructor_reads_paged_from_GET() {
		$_GET     = array( 'paged' => '3' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c = new OtherMediaViewController();

		$this->assertSame( 3, $this->getProtected( $c, 'currentPage' ) );
	}

	public function test_constructor_defaults_order_to_desc_when_absent() {
		$_GET     = array();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c = new OtherMediaViewController();

		$this->assertSame( 'desc', $this->getProtected( $c, 'order' ) );
	}

	public function test_constructor_sets_search_to_false_when_s_is_absent() {
		$_GET     = array();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c = new OtherMediaViewController();

		$this->assertFalse( $this->getProtected( $c, 'search' ) );
	}

	public function test_constructor_captures_search_string_from_GET() {
		$_GET     = array( 's' => 'landscape' );
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c = new OtherMediaViewController();

		$this->assertSame( 'landscape', $this->getProtected( $c, 'search' ) );
	}

	// -----------------------------------------------------------------
	// loadScreenPerPageOption
	// -----------------------------------------------------------------

	public function test_loadScreenPerPageOption_returns_default_when_option_is_not_set() {
		$c      = $this->freshController();
		$result = $this->invokeProtected(
			$c,
			'loadScreenPerPageOption',
			array( 'shortpixel_test_per_page_' . uniqid(), 20 )
		);

		// Sentinel: absent option → default of 20.
		$this->assertSame( 20, $result );
	}

	public function test_loadScreenPerPageOption_returns_stored_value_when_positive() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$option_key = 'shortpixel_test_per_page_' . uniqid();
		update_user_option( $admin_id, $option_key, 50 );

		$c      = $this->freshController();
		$result = $this->invokeProtected(
			$c,
			'loadScreenPerPageOption',
			array( $option_key, 20 )
		);

		$this->assertSame( 50, $result );
	}

	public function test_loadScreenPerPageOption_returns_default_when_stored_value_is_zero() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$option_key = 'shortpixel_test_per_page_zero_' . uniqid();
		update_user_option( $admin_id, $option_key, 0 );

		$c      = $this->freshController();
		$result = $this->invokeProtected(
			$c,
			'loadScreenPerPageOption',
			array( $option_key, 20 )
		);

		// Sentinel: a zero value must NOT be used (it would show 0 rows per page).
		$this->assertSame( 20, $result );
	}

	// -----------------------------------------------------------------
	// getHeadings — column definition shape
	// -----------------------------------------------------------------

	public function test_getHeadings_returns_array_with_expected_column_keys() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c       = $this->freshController();
		$result  = $this->invokeProtected( $c, 'getHeadings' );

		$this->assertIsArray( $result );

		// Sentinel: every expected column must be present. Missing a column key
		// breaks the template table-column alignment.
		$expected = array( 'checkbox', 'thumbnails', 'name', 'folder', 'type', 'date', 'status' );
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $result,
				"Expected heading key '$key' is missing from getHeadings() result"
			);
		}
	}

	public function test_getHeadings_each_entry_has_a_title_key() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'getHeadings' );

		foreach ( $result as $slug => $heading ) {
			$this->assertArrayHasKey( 'title', $heading,
				"Heading '$slug' is missing the 'title' key"
			);
		}
	}

	// -----------------------------------------------------------------
	// filterAllowedOrderBy — whitelist check
	// -----------------------------------------------------------------

	public function test_filterAllowedOrderBy_accepts_valid_column_name() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c = $this->freshController();

		// 'name' is listed in getHeadings() with orderby => 'name'.
		$result = $this->invokeProtected( $c, 'filterAllowedOrderBy', array( 'name' ) );

		// Sentinel: valid orderby value must pass through unchanged.
		$this->assertSame( 'name', $result );
	}

	public function test_filterAllowedOrderBy_accepts_id_column() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'filterAllowedOrderBy', array( 'id' ) );

		$this->assertSame( 'id', $result );
	}

	public function test_filterAllowedOrderBy_rejects_arbitrary_string_returning_empty() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$c      = $this->freshController();
		$result = $this->invokeProtected( $c, 'filterAllowedOrderBy', array( 'evil; DROP TABLE--' ) );

		// Sentinel: must return empty string (not the injected value).
		$this->assertSame( '', $result );
	}

	// -----------------------------------------------------------------
	// setScreenOption — per-page option filtering
	// -----------------------------------------------------------------

	public function test_setScreenOption_returns_int_value_for_matching_option() {
		$c      = $this->freshController();
		$result = $c->setScreenOption(
			false,
			OtherMediaViewController::OTHER_MEDIA_PER_PAGE_OPTION,
			'35'
		);

		// Sentinel: must return an integer, not the raw string or the $status pass-through.
		$this->assertSame( 35, $result );
	}

	public function test_setScreenOption_passes_status_through_for_other_options() {
		$c      = $this->freshController();
		$result = $c->setScreenOption( 'original-status', 'some_other_option', '10' );

		// Sentinel: unrelated option must not modify $status.
		$this->assertSame( 'original-status', $result );
	}

	// -----------------------------------------------------------------
	// getPagination — page-link logic
	// -----------------------------------------------------------------

	public function test_getPagination_returns_false_when_all_items_fit_on_one_page() {
		$c = $this->freshController();
		$this->setProtected( $c, 'total_items', 5 );
		$this->setProtected( $c, 'items_per_page', 20 );
		$this->setProtected( $c, 'currentPage', 1 );

		$result = $this->invokeProtected( $c, 'getPagination' );

		// Sentinel: no pagination needed → must return exactly false, not ''.
		$this->assertFalse( $result );
	}

	public function test_getPagination_returns_false_when_total_items_equals_per_page() {
		$c = $this->freshController();
		$this->setProtected( $c, 'total_items', 20 );
		$this->setProtected( $c, 'items_per_page', 20 );
		$this->setProtected( $c, 'currentPage', 1 );

		$result = $this->invokeProtected( $c, 'getPagination' );

		$this->assertFalse( $result );
	}

	public function test_getPagination_returns_string_when_multiple_pages_exist() {
		$c = $this->freshController();
		$this->setProtected( $c, 'total_items', 100 );
		$this->setProtected( $c, 'items_per_page', 20 );
		$this->setProtected( $c, 'currentPage', 1 );

		$result = $this->invokeProtected( $c, 'getPagination' );

		// Sentinel: must return a non-empty string (HTML pagination markup).
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	public function test_getPagination_includes_page_number_when_multiple_pages() {
		$c = $this->freshController();
		$this->setProtected( $c, 'total_items', 60 );
		$this->setProtected( $c, 'items_per_page', 20 );
		$this->setProtected( $c, 'currentPage', 2 );

		$result = $this->invokeProtected( $c, 'getPagination' );

		$this->assertIsString( $result );
		// Sentinel: the current page number must appear in the markup.
		$this->assertStringContainsString( '2', $result );
	}
}
