<?php
/**
 * Cross-plugin compatibility: Bricks (theme).
 *
 * Runs with the REAL Bricks theme active. Bricks is commercial, so
 * bin/test.sh --compat extracts it from a zip dropped into
 * tests/partner-themes/bricks*.zip and tests/bootstrap.php makes it the
 * active theme; without that zip every test here SKIPS.
 *
 * Bricks stores a page's element tree in `_bricks_page_content_2`
 * (BRICKS_DB_PAGE_CONTENT) and shared components in the `bricks_components`
 * option (BRICKS_DB_COMPONENTS), both as arrays that WordPress serializes.
 * A Nested Slider with custom options keeps them as a JSON string in
 * `settings['options']`, and its render() passes any non-array value to
 * stripslashes().
 *
 * replacer2 has no Bricks module: these rows go through the generic path,
 * Replacer::replaceContent(). When a row contains a renamed or converted
 * image URL, the replacer json_decode()s every JSON-looking string it meets
 * while recursing and re-encodes it at every depth, so the slider's options
 * come back as a JSON string. Were they left decoded (a stdClass), Bricks
 * would fatal ("stripslashes(): Argument #1 ($string) must be of type
 * string, stdClass given") on every page that renders the slider; for a
 * component, on every page that uses it. The unit-level counterpart is in
 * Model/test-Replacer.php.
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatBricksTest extends SPIO_IntegrationTestCase {

	/** The custom Splide options a Nested Slider keeps as a JSON string. */
	const SLIDER_OPTIONS = '{"type":"loop","perPage":3,"gap":"1em"}';

	public function set_up() {
		if ( ! defined( 'BRICKS_VERSION' ) || ! class_exists( '\\Bricks\\Frontend' ) ) {
			$this->markTestSkipped( 'Bricks is not the active theme — drop bricks*.zip into tests/partner-themes/ and run bin/test.sh --compat.' );
		}

		parent::set_up();

		// Bricks blocks writes to its page content (update_post_metadata)
		// for users without builder access, so the pages are seeded as an
		// administrator. SPIO's replacer writes with direct SQL and is not
		// subject to that check.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_set_current_user( 0 );

		if ( defined( 'SHORTPIXEL_BACKUP_FOLDER' ) && is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
		}

		parent::tear_down();
	}

	private function uploadPngForQueuePath( string $fixture = 'fixture-small.png' ): int {
		\wpSPIO()->settings()->png2jpg = 0;
		$id = $this->uploadFixture( $fixture );
		\wpSPIO()->settings()->png2jpg = 1;
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->png2jpg = 1;
		return $id;
	}

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does. */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new \ShortPixel\Model\Queue\QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	/**
	 * A Bricks element tree: a Nested Slider with custom options and, next
	 * to it, an Image element showing $url (what makes the replacer match
	 * the row).
	 */
	private function elements( int $attachment_id, string $url ): array {
		return array(
			array(
				'id'       => 'slidr1',
				'name'     => 'slider-nested',
				'parent'   => 0,
				'children' => array(),
				'settings' => array(
					'optionsType' => 'custom',
					'options'     => self::SLIDER_OPTIONS,
				),
			),
			array(
				'id'       => 'image1',
				'name'     => 'image',
				'parent'   => 0,
				'children' => array(),
				'settings' => array(
					'image' => array(
						'id'   => $attachment_id,
						'url'  => $url,
						'size' => 'full',
					),
				),
			),
		);
	}

	/** A published Bricks page whose element tree is $elements. */
	private function createBricksPage( array $elements ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '' ) );
		update_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, $elements );
		return $post_id;
	}

	/** The page's element tree as WordPress loads it, bypassing the cache. */
	private function pageElements( int $post_id ): array {
		wp_cache_delete( $post_id, 'post_meta' );
		return (array) get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );
	}

	/**
	 * Renders an element tree through Bricks; returns the HTML or the
	 * Throwable. A fatal inside an element leaves Bricks' own output buffers
	 * open, so they are closed here.
	 */
	private function render( array $elements ) {
		$level = ob_get_level();
		try {
			return (string) \Bricks\Frontend::render_data( $elements );
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return $e;
		}
	}

	/** Asserts that $result is rendered HTML containing the Nested Slider, not a Throwable. */
	private function assertSliderRenders( $result, string $message ): void {
		$this->assertIsString( $result, $message . ( $result instanceof \Throwable ? ' Got: ' . $result->getMessage() : '' ) );
		$this->assertStringContainsString( 'splide', $result, 'The Nested Slider rendered.' );
	}

	public function test_bricks_is_the_active_theme() {
		$this->assertSame( 'bricks', get_template(), 'tests/bootstrap.php activates the Bricks theme.' );
		$this->assertSame( '_bricks_page_content_2', BRICKS_DB_PAGE_CONTENT );
		$this->assertSame( 'bricks_components', BRICKS_DB_COMPONENTS );
	}

	/**
	 * Baseline for the tests below: an untouched Nested Slider with custom
	 * options, stored the way Bricks stores it, loads back with the options
	 * as a JSON string and renders without error.
	 */
	public function test_nested_slider_with_custom_options_renders() {
		$id      = $this->uploadFixture( 'fixture-small.jpg' );
		$post_id = $this->createBricksPage( $this->elements( $id, wp_get_attachment_url( $id ) ) );

		$elements = $this->pageElements( $post_id );
		$this->assertSame( self::SLIDER_OPTIONS, $elements[0]['settings']['options'], 'The slider options load back as the JSON string.' );

		$html = $this->render( $elements );
		$this->assertIsString( $html, 'The page renders without error.' );
		$this->assertStringContainsString( 'splide', $html, 'The Nested Slider rendered.' );
	}

	/**
	 * Renaming a file (manually or with AI) used on a Bricks page keeps the
	 * page's Nested Slider options a JSON string, and the page still renders.
	 */
	public function test_manual_rename_keeps_the_nested_slider_on_a_bricks_page_working() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$post_id = $this->createBricksPage( $this->elements( $id, wp_get_attachment_url( $id ) ) );

		$new_base = 'bricks-rename-' . strtolower( wp_generate_password( 6, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the rename succeeded.' );

		// SENTINEL: the replacer rewrote the row — the Image element follows the rename.
		$elements = $this->pageElements( $post_id );
		$this->assertStringContainsString( $new_base, $elements[1]['settings']['image']['url'], 'Sentinel: the Image element carries the new name.' );

		$this->assertSame( self::SLIDER_OPTIONS, $elements[0]['settings']['options'], 'The slider options stay the JSON string.' );
		$this->assertSliderRenders( $this->render( $elements ), 'The page renders after the rename.' );
	}

	/**
	 * Converting a PNG used on a Bricks page to JPG keeps the page's Nested
	 * Slider working the same way.
	 */
	public function test_png_conversion_keeps_the_nested_slider_on_a_bricks_page_working() {
		$id      = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->createBricksPage( $this->elements( $id, $url ) );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		// SENTINELS: the conversion ran and the replacer rewrote the row.
		$this->assertSame( 'jpg', strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ), 'Sentinel: the PNG was converted to JPG.' );
		$elements = $this->pageElements( $post_id );
		$this->assertStringEndsWith( '.jpg', $elements[1]['settings']['image']['url'], 'Sentinel: the Image element references the .jpg.' );

		$this->assertSame( self::SLIDER_OPTIONS, $elements[0]['settings']['options'], 'The slider options stay the JSON string.' );
		$this->assertSliderRenders( $this->render( $elements ), 'The page renders after the conversion.' );
	}

	/**
	 * When a shared Bricks component contains the renamed image, its Nested
	 * Slider options in the `bricks_components` option stay a JSON string, so
	 * the pages that use the component keep rendering — whether or not they
	 * show the renamed image.
	 */
	public function test_manual_rename_keeps_the_nested_slider_in_a_shared_component_working() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		update_option(
			BRICKS_DB_COMPONENTS,
			array(
				array(
					'id'       => 'cmpnt1',
					'elements' => $this->elements( $id, wp_get_attachment_url( $id ) ),
				),
			)
		);

		$new_base = 'bricks-component-' . strtolower( wp_generate_password( 6, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the rename succeeded.' );

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( BRICKS_DB_COMPONENTS, 'options' );
		$components = (array) get_option( BRICKS_DB_COMPONENTS );
		$elements   = $components[0]['elements'] ?? array();

		// SENTINEL: the replacer rewrote the option — the component's Image element follows the rename.
		$this->assertStringContainsString( $new_base, $elements[1]['settings']['image']['url'] ?? '', 'Sentinel: the component Image element carries the new name.' );

		$this->assertSame( self::SLIDER_OPTIONS, $elements[0]['settings']['options'], 'The component slider options stay the JSON string.' );
		$this->assertSliderRenders( $this->render( $elements ), 'The component renders after the rename.' );
	}

	/**
	 * Pins a known defect: slider options with non-ASCII text come back from
	 * a rename re-encoded with \uXXXX escapes, although they hold no URL.
	 * Bricks runs stripslashes() on the options before using them, which
	 * turns "é" into "u00e9": the page renders "Cafu00e9" instead of
	 * "Café". Unit-level pin in Model/test-Replacer.php.
	 * @todo In the replacer2 module source, Replacer::replaceContent(): return
	 *       a nested JSON string unchanged when nothing inside it was replaced.
	 * When fixed, this test fails; flip it: the options keep their bytes and
	 * the page shows "Café".
	 */
	public function test_manual_rename_mangles_non_ascii_slider_options_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$options  = '{"type":"loop","perPage":3,"gap":"1em","i18n":{"next":"Café"}}';
		$elements = $this->elements( $id, wp_get_attachment_url( $id ) );
		$elements[0]['settings']['options'] = $options;
		$post_id  = $this->createBricksPage( $elements );

		// SENTINEL: untouched, the page shows the text as written.
		$this->assertStringContainsString( 'Café', (string) $this->render( $this->pageElements( $post_id ) ), 'Sentinel: before the rename the slider carries "Café".' );

		$new_base = 'bricks-unicode-' . strtolower( wp_generate_password( 6, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the rename succeeded.' );

		$elements = $this->pageElements( $post_id );
		$this->assertStringContainsString( $new_base, $elements[1]['settings']['image']['url'], 'Sentinel: the Image element carries the new name.' );
		$html = $this->render( $elements );
		$this->assertSliderRenders( $html, 'Sentinel: the page still renders.' );

		// THE PIN: the options were re-encoded and Bricks strips the escape's backslash.
		$this->assertNotSame( $options, $elements[0]['settings']['options'], 'Pinned: fixed? The options keep their bytes — flip this pin.' );
		$this->assertStringContainsString( 'Cafu00e9', $html, 'Pinned: fixed? The page shows "Café" again — flip this pin.' );
	}
}
