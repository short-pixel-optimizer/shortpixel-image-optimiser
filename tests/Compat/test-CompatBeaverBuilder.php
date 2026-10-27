<?php
/**
 * Cross-plugin compatibility: Beaver Builder.
 *
 * Runs with the REAL Beaver Builder Lite plugin active (wordpress.org,
 * fetched by bin/test.sh --compat); without it every test here SKIPS.
 *
 * Beaver Builder stores a layout in `_fl_builder_data` (published) and
 * `_fl_builder_draft` (editor) as an array of stdClass nodes, which
 * WordPress serializes. A Photo module renders `settings->photo_src`
 * directly, so that URL must follow a rename or a PNG→JPG conversion.
 *
 * replacer2 has no Beaver Builder module: these rows go through the
 * generic postmeta path, Replacer::replaceContent(). That unserializes
 * meta values with `allowed_classes => false` (object-injection hardening),
 * which turns every node into a __PHP_Incomplete_Class. The replacer
 * leaves incomplete objects untouched, so the URLs inside the nodes are
 * never rewritten — pinned below. The layout itself stays intact.
 *
 * @package Shortpixel_Image_Optimiser
 */

class CompatBeaverBuilderTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			$this->markTestSkipped( 'Beaver Builder is not loaded — run bin/test.sh --compat.' );
		}

		parent::set_up();
	}

	public function tear_down() {
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
	 * A published Beaver Builder page with one Photo module showing $url,
	 * saved through Beaver Builder's own update_layout_data() for both the
	 * published layout and the editor draft. post_content carries the same
	 * URL, as Beaver Builder's rendered fallback content does, so the tests
	 * can tell that the replacer ran.
	 */
	private function createBeaverBuilderPage( int $attachment_id, string $url ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<img src="' . $url . '" />',
			)
		);

		$layout = array(
			'row1'   => (object) array( 'node' => 'row1', 'type' => 'row', 'parent' => null, 'position' => 0, 'settings' => (object) array() ),
			'group1' => (object) array( 'node' => 'group1', 'type' => 'column-group', 'parent' => 'row1', 'position' => 0, 'settings' => null ),
			'col1'   => (object) array( 'node' => 'col1', 'type' => 'column', 'parent' => 'group1', 'position' => 0, 'settings' => (object) array( 'size' => 100 ) ),
			'photo1' => (object) array(
				'node'     => 'photo1',
				'type'     => 'module',
				'parent'   => 'col1',
				'position' => 0,
				'settings' => (object) array(
					'type'         => 'photo',
					'photo_source' => 'library',
					'photo'        => $attachment_id,
					'photo_src'    => $url,
				),
			),
		);

		\FLBuilderModel::update_layout_data( $layout, 'published', $post_id );
		\FLBuilderModel::update_layout_data( $layout, 'draft', $post_id );
		update_post_meta( $post_id, '_fl_builder_enabled', true );

		return $post_id;
	}

	/** The raw serialized meta value, straight from the database. */
	private function rawLayout( int $post_id, string $key ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $post_id, $key ) );
	}

	/** The Photo module's photo_src as WordPress loads it, bypassing the cache. */
	private function photoSrc( int $post_id, string $key ) {
		wp_cache_delete( $post_id, 'post_meta' );
		$layout = get_post_meta( $post_id, $key, true );
		return is_array( $layout ) && isset( $layout['photo1']->settings->photo_src ) ? $layout['photo1']->settings->photo_src : null;
	}

	/**
	 * Shape sentinel for the tests below: Beaver Builder stores its layout
	 * as serialized stdClass nodes, with the Photo URL inside a node.
	 */
	public function test_layout_is_stored_as_serialized_stdclass_nodes() {
		$id      = $this->uploadFixture( 'fixture-small.jpg' );
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->createBeaverBuilderPage( $id, $url );

		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$raw = $this->rawLayout( $post_id, $key );
			$this->assertStringStartsWith( 'a:', $raw, "$key is a serialized array." );
			$this->assertStringContainsString( 'O:8:"stdClass"', $raw, "$key holds stdClass nodes." );
			$this->assertSame( $url, $this->photoSrc( $post_id, $key ), "$key loads with the Photo URL." );
		}
	}

	/**
	 * Pins a known defect at its cause: the generic replacer returns a
	 * Beaver Builder layout byte-identical, because it unserializes meta
	 * values with `allowed_classes => false` and leaves the resulting
	 * incomplete node objects untouched.
	 * @todo In the replacer2 module source, Replacer::replaceContent():
	 *       unserialize with `allowed_classes => array( 'stdClass' )` —
	 *       stdClass has no magic methods, so it adds no object-injection
	 *       risk, and plain data objects (Beaver Builder nodes, other
	 *       plugins' settings) become replaceable again.
	 * When fixed, this test fails; flip it: the returned layout carries the
	 * new URL and still unserializes to the same node structure.
	 */
	public function test_replacer_returns_beaver_builder_layout_unchanged_pinned_for_deferred_fix() {
		$id      = $this->uploadFixture( 'fixture-small.jpg' );
		$old_url = wp_get_attachment_url( $id );
		$new_url = str_replace( '.jpg', '-renamed.jpg', $old_url );
		$post_id = $this->createBeaverBuilderPage( $id, $old_url );
		$raw     = $this->rawLayout( $post_id, '_fl_builder_data' );

		$replaced = \ShortPixel\Replacer\Replacer::getInstance()->replaceContent( $raw, array( $old_url ), array( $new_url ), false, false );

		$this->assertSame( $raw, $replaced, 'Pinned: fixed? The replacer now rewrites Beaver Builder layouts — flip this pin.' );
	}

	/**
	 * Pins a known defect: converting a PNG to JPG leaves the Beaver
	 * Builder Photo module (published layout and editor draft) pointing at
	 * the .png, which is no longer in the uploads folder. Same cause as
	 * the replacer pin above.
	 * @todo See test_replacer_returns_beaver_builder_layout_unchanged_pinned_for_deferred_fix.
	 * When fixed, this test fails; flip it: both layouts reference the .jpg.
	 */
	public function test_png_conversion_leaves_beaver_builder_layout_unchanged_pinned_for_deferred_fix() {
		$id      = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->createBeaverBuilderPage( $id, $url );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		// SENTINELS: the conversion ran and the replacer rewrote post_content.
		$this->assertSame( 'jpg', strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ), 'Sentinel: the PNG was converted to JPG.' );
		clean_post_cache( $post_id );
		$this->assertStringNotContainsString( $url, get_post_field( 'post_content', $post_id ), 'Sentinel: the replacer ran — post_content no longer references the .png.' );

		// THE PIN: the layout still points at the .png (and still loads).
		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$this->assertSame( $url, $this->photoSrc( $post_id, $key ), "Pinned: fixed? $key no longer references the .png — flip this pin." );
		}
	}

	/**
	 * Pins a known defect: renaming a file (manually or with AI) leaves the
	 * Beaver Builder Photo module pointing at the old filename. Same cause
	 * as the replacer pin above.
	 * @todo See test_replacer_returns_beaver_builder_layout_unchanged_pinned_for_deferred_fix.
	 * When fixed, this test fails; flip it: both layouts reference the new name.
	 */
	public function test_manual_rename_leaves_beaver_builder_layout_unchanged_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->createBeaverBuilderPage( $id, $url );

		$new_base = 'bb-rename-' . strtolower( wp_generate_password( 6, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the rename succeeded.' );

		// SENTINEL: the replacer rewrote post_content.
		clean_post_cache( $post_id );
		$this->assertStringContainsString( $new_base, get_post_field( 'post_content', $post_id ), 'Sentinel: the replacer ran — post_content carries the new name.' );

		// THE PIN: the layout still points at the old name (and still loads).
		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$this->assertSame( $url, $this->photoSrc( $post_id, $key ), "Pinned: fixed? $key no longer references the old name — flip this pin." );
		}
	}
}
