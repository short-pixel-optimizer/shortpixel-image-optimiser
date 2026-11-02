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
 * meta values with `allowed_classes => array( 'stdClass' )`: the nodes are
 * plain stdClass objects (no magic methods, so no object-injection risk),
 * their URLs are rewritten and the layout is serialized again with the
 * same node structure.
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
	 * The generic replacer rewrites the Photo URL inside a Beaver Builder
	 * layout and keeps the node structure: the result unserializes to the
	 * same nodes, with only photo_src changed.
	 */
	public function test_replacer_rewrites_the_url_inside_a_beaver_builder_layout() {
		$id      = $this->uploadFixture( 'fixture-small.jpg' );
		$old_url = wp_get_attachment_url( $id );
		$new_url = str_replace( '.jpg', '-renamed.jpg', $old_url );
		$post_id = $this->createBeaverBuilderPage( $id, $old_url );
		$raw     = $this->rawLayout( $post_id, '_fl_builder_data' );

		$replaced = \ShortPixel\Replacer\Replacer::getInstance()->replaceContent( $raw, array( $old_url ), array( $new_url ), false, false );

		$this->assertIsString( $replaced, 'The layout is returned serialized.' );
		$this->assertSame( str_replace( 's:' . strlen( $old_url ) . ':"' . $old_url . '"', 's:' . strlen( $new_url ) . ':"' . $new_url . '"', $raw ), $replaced, 'Only photo_src changes, with a correct string length.' );

		$layout = unserialize( $replaced, array( 'allowed_classes' => array( 'stdClass' ) ) );
		$this->assertSame( array( 'row1', 'group1', 'col1', 'photo1' ), array_keys( $layout ), 'The node structure is kept.' );
		$this->assertSame( $new_url, $layout['photo1']->settings->photo_src );
	}

	/**
	 * Converting a PNG to JPG points the Beaver Builder Photo module
	 * (published layout and editor draft) at the .jpg.
	 */
	public function test_png_conversion_rewrites_the_beaver_builder_layout() {
		$id      = $this->uploadPngForQueuePath( 'fixture-small.png' );
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->createBeaverBuilderPage( $id, $url );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		// SENTINEL: the conversion ran.
		$this->assertSame( 'jpg', strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ), 'Sentinel: the PNG was converted to JPG.' );
		$new_url = wp_get_attachment_url( $id );

		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$this->assertSame( $new_url, $this->photoSrc( $post_id, $key ), "$key references the converted .jpg." );
		}
	}

	/**
	 * Renaming a file (manually or with AI) points the Beaver Builder Photo
	 * module (published layout and editor draft) at the new filename.
	 */
	public function test_manual_rename_rewrites_the_beaver_builder_layout() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$url     = wp_get_attachment_url( $id );
		$post_id = $this->createBeaverBuilderPage( $id, $url );

		$new_base = 'bb-rename-' . strtolower( wp_generate_password( 6, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the rename succeeded.' );
		$new_url = wp_get_attachment_url( $id );
		$this->assertStringContainsString( $new_base, $new_url, 'Sentinel: the attachment carries the new name.' );

		foreach ( array( '_fl_builder_data', '_fl_builder_draft' ) as $key ) {
			$this->assertSame( $new_url, $this->photoSrc( $post_id, $key ), "$key references the new name." );
		}
	}
}
