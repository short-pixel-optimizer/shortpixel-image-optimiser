<?php
/**
 * Integration tests for the "Change Filename" feature exposed on the
 * media-edit screen.
 *
 * Flow under test:
 *   res/js/screens/screen-media.js → AJAX `shortpixel_ajaxRequest`
 *   with screen_action `media/replaceFileName` → AjaxController::
 *   replaceFileName() (class/Controller/AjaxController.php:1359) →
 *   OptimizeAiController::ajax_replaceFile() (class/Controller/Optimizer/
 *   OptimizeAiController.php:777) → replaceFiles() (:618) which moves
 *   every physical file (main + thumbs + webp/avif companions), renames
 *   the local backup, rewrites URLs in post_content / postmeta via
 *   Replacer2, and finally updates _wp_attached_file +
 *   _wp_attachment_metadata via replaceMetaData().
 *
 * The feature is DECOUPLED from AI: no aipostmeta row is required and
 * OptimizeAiController::ajax_replaceFile() hardcodes recent_upload=true,
 * bypassing the usage-threshold guard.
 *
 * Coverage split:
 *   - Happy-path (single-file + scaled + webp companion + serialized
 *     postmeta) rewrite + metadata + guid untouched.
 *   - Standalone no-AI path (no aipostmeta row created before or after).
 *   - Conflict abort: existing target file → false, source untouched,
 *     _wp_attached_file untouched, post_content untouched.
 *   - Path traversal + extension change neutralised by
 *     pathinfo(basename(), PATHINFO_FILENAME) at :786.
 *   - Access control: author on someone else's attachment → NO_ACCESS.
 *   - Missing newFileName key → error response, no rename.
 *   - Regression #50 (fixed 202c6e3c): empty/short newFileName rejected
 *     by the strlen<3 guard, no rename, no extension-only dotfiles.
 *   - Regression #51 (fixed 202c6e3c): base_url built basename-anchored,
 *     no mangling when the file base appears in the directory path.
 *   - Pin #53 (MEDIUM, AI-auto path): recent_upload=false guard matches
 *     the attachment's OWN _wp_attached_file rows.
 *   - #66 contract: a successful rename records replaced_content
 *     ['replaced_url'] for the Gutenberg editor (moved here from the
 *     flipped #52 unit test).
 *   - Pin #73 (HIGH, 0db02498): the "copied nothing" bail-out also fires
 *     when an offloader handled the rename via the
 *     `shortpixel/image/replace_files` filter (no metadata / content
 *     rewrite at all) and on every dry-run.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Model\Queue\QueueItem;

class ChangeFilenameTest extends SPIO_AjaxTestCase {

	/** Fire the media/replaceFileName screen action as the current user. */
	private function doReplaceFileName( int $attachment_id, string $newFileName ): ?object {
		$_POST = array(
			'nonce'         => wp_create_nonce( 'ajax_request' ),
			'screen_action' => 'media/replaceFileName',
			'id'            => $attachment_id,
			'type'          => 'media',
			'newFileName'   => $newFileName,
		);
		$_REQUEST = $_POST;
		return $this->doAjax( 'shortpixel_ajaxRequest' );
	}

	/**
	 * The rename result inside a media/replaceFileName response.
	 *
	 * Since 8b625159 (the #77 fix) a rename that reached the engine answers
	 * like the queue does: `media.results[0]` carries is_done / is_error /
	 * message / item_id / apiName='ai', and `media.qstatus` is
	 * STATUS_SUCCESS. The JS 'ShortPixelMedia.reloadWindow' listener reads
	 * results[0] and only reloads when is_error is false; on an error it
	 * shows the message. The input-validation rejections (missing, empty or
	 * too-short name) still answer with the old flat object.
	 */
	private function renameResult( ?object $response ): object {
		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( isset( $response->media->results[0] ), 'The rename result must sit in media.results[0]. Raw: ' . $this->lastRawResponse() );
		return (object) $response->media->results[0];
	}

	/** Fire the same action WITHOUT the newFileName key at all. */
	private function doReplaceFileNameMissingKey( int $attachment_id ): ?object {
		$_POST = array(
			'nonce'         => wp_create_nonce( 'ajax_request' ),
			'screen_action' => 'media/replaceFileName',
			'id'            => $attachment_id,
			'type'          => 'media',
		);
		$_REQUEST = $_POST;
		return $this->doAjax( 'shortpixel_ajaxRequest' );
	}

	/** Fresh (uncached) image model for an attachment. */
	private function freshImageModel( int $attachment_id ) {
		$this->resetPluginSingletons();
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
	}

	/** Absolute path to the WP uploads dir for this test run. */
	private function uploadsBasedir(): string {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] );
	}

	/**
	 * Run the rename engine directly (the same call AjaxController::
	 * replaceFileName() makes) and hand back both the bool result and the
	 * QueueItem, so tests can inspect what replaceFiles() recorded on it.
	 *
	 * @return array{0: bool, 1: QueueItem}
	 */
	private function renameViaEngine( int $attachment_id, string $new_base ): array {
		$imageModel = $this->freshImageModel( $attachment_id );
		$queueItem  = new QueueItem( array( 'imageModel' => $imageModel ) );
		$result     = $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
		return array( $result, $queueItem );
	}

	/** Invoke the protected replaceFiles() with explicit args (e.g. dry_run). */
	private function replaceFilesWithArgs( int $attachment_id, string $new_base, array $args ): bool {
		$imageModel = $this->freshImageModel( $attachment_id );
		$queueItem  = new QueueItem( array( 'imageModel' => $imageModel ) );
		$method     = new ReflectionMethod( OptimizeAiController::class, 'replaceFiles' );
		$method->setAccessible( true );
		return $method->invoke( new OptimizeAiController(), $queueItem, $new_base, $args );
	}

	// -------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------

	/**
	 * End-to-end rename: main file + thumbnails on disk, _wp_attached_file
	 * updated, metadata['sizes'][*]['file'] rewritten, embedding
	 * post_content URL rewritten by Replacer2, guid NOT touched, response
	 * result carries is_done=true / is_error=false (see renameResult()).
	 */
	public function test_happy_path_renames_files_updates_meta_and_rewrites_post_content() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_url  = wp_get_attachment_url( $attachment_id );
		$original_file = get_attached_file( $attachment_id );
		$this->assertFileExists( $original_file, 'Precondition: source file present' );
		$original_meta = wp_get_attachment_metadata( $attachment_id );
		$this->assertNotEmpty( $original_meta['sizes'], 'Precondition: metadata has size entries' );
		$original_guid = get_post( $attachment_id )->guid;
		$original_base = pathinfo( $original_file, PATHINFO_FILENAME );

		// Embed the image URL in a post so Replacer2 has content to rewrite.
		$post_id = self::factory()->post->create(
			array( 'post_content' => 'Look at this <img src="' . esc_url( $original_url ) . '" alt="" />' )
		);

		$new_base = 'renamed-happy-' . wp_generate_password( 6, false );

		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertSame( $attachment_id, (int) $this->renameResult( $response )->item_id );
		$this->assertFalse( $this->renameResult( $response )->is_error, 'Happy path must report is_error=false' );
		$this->assertSame( 'Files were replaced', $this->renameResult( $response )->message );

		// The old main file must be gone; the new one must be there.
		$this->assertFileDoesNotExist( $original_file, 'Old file must be moved off disk' );
		$new_file = dirname( $original_file ) . '/' . $new_base . '.jpg';
		$this->assertFileExists( $new_file, 'New file must exist on disk' );

		// _wp_attached_file must point at the new base.
		$attached = get_attached_file( $attachment_id );
		$this->assertStringContainsString( $new_base . '.jpg', $attached, '_wp_attached_file must be updated' );

		// Thumbnails must be renamed as well.
		$new_meta = wp_get_attachment_metadata( $attachment_id );
		foreach ( $new_meta['sizes'] as $sizeName => $sizeData ) {
			$this->assertStringContainsString(
				$new_base,
				$sizeData['file'],
				"Metadata for size $sizeName must reference the new base"
			);
			$this->assertFileExists(
				dirname( $new_file ) . '/' . $sizeData['file'],
				"Thumbnail file for size $sizeName must exist on disk"
			);
		}
		foreach ( $original_meta['sizes'] as $sizeName => $sizeData ) {
			$this->assertFileDoesNotExist(
				dirname( $original_file ) . '/' . $sizeData['file'],
				"Old thumbnail for size $sizeName must have been moved"
			);
		}

		// post_content URL must be rewritten by Replacer2.
		clean_post_cache( $post_id );
		$post_content = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString(
			$original_base . '.jpg',
			$post_content,
			'The old filename must not survive in post_content'
		);
		$this->assertStringContainsString(
			$new_base . '.jpg',
			$post_content,
			'The new filename must be written to post_content by Replacer2'
		);

		// wp_posts.guid is the permanent identifier — must not be touched.
		clean_post_cache( $attachment_id );
		$this->assertSame(
			$original_guid,
			get_post( $attachment_id )->guid,
			'wp_posts.guid must not be rewritten by the rename'
		);
	}

	/**
	 * The manual rename path must work with NO prior AI data anywhere.
	 * Same E2E as above but explicitly asserts the aipostmeta table is
	 * empty both before and after the operation.
	 */
	public function test_standalone_no_ai_path_renames_without_touching_aipostmeta() {
		$this->_setRole( 'administrator' );

		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );

		$countBefore = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}shortpixel_aipostmeta`"
		);
		$this->assertSame( 0, $countBefore, 'Precondition: aipostmeta must be empty' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );
		$new_base      = 'no-ai-rename-' . wp_generate_password( 6, false );

		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertFileDoesNotExist( $original_file );
		$this->assertFileExists( dirname( $original_file ) . '/' . $new_base . '.jpg' );

		$suppress2  = $wpdb->suppress_errors( true );
		$countAfter = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}shortpixel_aipostmeta`"
		);
		$wpdb->suppress_errors( $suppress2 );
		$this->assertSame(
			0,
			$countAfter,
			'A manual rename must not create any aipostmeta rows'
		);
	}

	// -------------------------------------------------------------------
	// Conflict abort — no side effects
	// -------------------------------------------------------------------

	/**
	 * When a file with the target name already exists in the same dir the
	 * conflict guard at OptimizeAiController.php:718-726 aborts the whole
	 * replace before ANY move. Source files must stay, _wp_attached_file
	 * must be unchanged, embedding post_content must not be rewritten,
	 * response reports is_error.
	 */
	public function test_conflict_abort_leaves_files_and_content_untouched() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file  = get_attached_file( $attachment_id );
		$original_url   = wp_get_attachment_url( $attachment_id );
		$original_base  = pathinfo( $original_file, PATHINFO_FILENAME );
		$original_meta  = wp_get_attachment_metadata( $attachment_id );
		$original_dir   = dirname( $original_file );

		// Choose a target base and pre-create the main-file conflict.
		$target_base = 'conflict-' . wp_generate_password( 6, false );
		file_put_contents( $original_dir . '/' . $target_base . '.jpg', 'pre-existing' );

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . esc_url( $original_url ) . '" alt="" />' )
		);
		$original_content = get_post( $post_id )->post_content;

		$response = $this->doReplaceFileName( $attachment_id, $target_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done, 'is_done is always true — this is the current wire contract' );
		$this->assertTrue( $this->renameResult( $response )->is_error, 'Conflict must set is_error=true (#77: the JS shows the message instead of reloading)' );
		$this->assertStringContainsString( 'not replaced', $this->renameResult( $response )->message );

		// No physical move happened.
		$this->assertFileExists( $original_file, 'Source main file must survive a conflict abort' );
		foreach ( $original_meta['sizes'] as $sizeName => $sizeData ) {
			$this->assertFileExists(
				$original_dir . '/' . $sizeData['file'],
				"Source thumbnail $sizeName must survive a conflict abort"
			);
		}

		// _wp_attached_file must reference the OLD base.
		$attached = get_attached_file( $attachment_id );
		$this->assertStringContainsString(
			$original_base . '.jpg',
			$attached,
			'_wp_attached_file must not be rewritten on conflict'
		);

		// Post content must not be rewritten.
		clean_post_cache( $post_id );
		$this->assertSame(
			$original_content,
			get_post( $post_id )->post_content,
			'Replacer2 must not touch post_content when the rename aborted'
		);
	}

	// -------------------------------------------------------------------
	// Path traversal + extension change neutralised
	// -------------------------------------------------------------------

	/**
	 * OptimizeAiController.php:786 pipes the incoming filename through
	 * pathinfo(basename(), PATHINFO_FILENAME) so directory components and
	 * extension changes are stripped: `../../evil.php` becomes 'evil',
	 * and str_replace at :689 keeps the ORIGINAL extension. The rename
	 * must therefore stay inside the uploads dir and preserve `.jpg`.
	 */
	public function test_path_traversal_and_extension_change_are_neutralised() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );
		$original_dir  = dirname( $original_file );

		// The path-traversal string sanitize_file_name() collapses to
		// "..-..-evil.php" (dots kept, slashes stripped). We construct
		// the expected server-side base ourselves so the assertion is not
		// hostage to sanitize_file_name() future changes.
		$evil     = '../../evil.php';
		$expected_base = pathinfo( basename( sanitize_file_name( $evil ) ), PATHINFO_FILENAME );

		$uploads_basedir = $this->uploadsBasedir();

		$response = $this->doReplaceFileName( $attachment_id, $evil );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertObjectNotHasProperty( 'is_error', $response, 'Sanitised base must succeed' );

		$new_file = $original_dir . '/' . $expected_base . '.jpg';
		$this->assertFileExists( $new_file, 'Rename must land next to the source, extension unchanged' );
		$this->assertFileDoesNotExist( $original_file, 'Old file must be moved' );

		// Absolutely nothing should have been written outside the uploads dir.
		$this->assertStringStartsWith(
			$uploads_basedir,
			realpath( $new_file ),
			'New file must live inside the uploads dir'
		);

		// No literal `.php` file must have been created anywhere in uploads
		// (defence-in-depth against any regression that stripped only the
		// leading `..`).
		$this->assertFileDoesNotExist(
			$original_dir . '/evil.php',
			'No .php file may have been created'
		);
	}

	// -------------------------------------------------------------------
	// Scaled attachment: both -scaled and original files renamed
	// -------------------------------------------------------------------

	/**
	 * fixture-large.jpg is 3200x2400 → WP creates BOTH a `<base>-scaled.jpg`
	 * main file and the unscaled `<base>.jpg` (metadata->original_image).
	 * The rename must move both files and metadata->original_image must
	 * reference the new base too.
	 *
	 * @see class/Controller/Optimizer/OptimizeAiController.php:780-784
	 */
	public function test_scaled_attachment_renames_both_scaled_and_original_files() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-large.jpg' );
		$this->purgeQueueTable();

		$image  = $this->freshImageModel( $attachment_id );
		$this->assertTrue( $image->isScaled(), 'Precondition: fixture-large.jpg must be auto-scaled' );

		$original_scaled_file = get_attached_file( $attachment_id );
		$this->assertFileExists( $original_scaled_file );
		$original_meta        = wp_get_attachment_metadata( $attachment_id );
		$this->assertNotEmpty( $original_meta['original_image'], 'Precondition: original_image in metadata' );
		$original_unscaled    = dirname( $original_scaled_file ) . '/' . $original_meta['original_image'];
		$this->assertFileExists( $original_unscaled, 'Precondition: unscaled original file on disk' );

		$new_base = 'scaled-rename-' . wp_generate_password( 6, false );
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertObjectNotHasProperty( 'is_error', $response );

		$this->assertFileDoesNotExist( $original_scaled_file, 'Old -scaled file must be moved' );
		$this->assertFileDoesNotExist( $original_unscaled, 'Old unscaled original must be moved' );

		$new_scaled   = dirname( $original_scaled_file ) . '/' . $new_base . '-scaled.jpg';
		$new_unscaled = dirname( $original_scaled_file ) . '/' . $new_base . '.jpg';
		$this->assertFileExists( $new_scaled, 'New -scaled file must exist' );
		$this->assertFileExists( $new_unscaled, 'New unscaled original must exist' );

		$new_meta = wp_get_attachment_metadata( $attachment_id );
		$this->assertSame(
			$new_base . '.jpg',
			$new_meta['original_image'],
			'metadata[original_image] must reference the new base'
		);
		$this->assertStringContainsString(
			$new_base . '-scaled.jpg',
			$new_meta['file'],
			'metadata[file] must reference the new -scaled main'
		);
	}

	// -------------------------------------------------------------------
	// WebP companion renamed alongside the main file
	// -------------------------------------------------------------------

	/**
	 * WebP variants are discovered by MediaLibraryModel::getWebps() based on
	 * side-by-side `<base>.jpg.webp` files. Dropping one such file next to
	 * the main image before the rename must cause it to be moved to the new
	 * `<newbase>.jpg.webp` alongside the new main.
	 *
	 * @see class/Model/Image/MediaLibraryModel.php:471-507 (getAllFiles)
	 * @see class/Controller/Optimizer/OptimizeAiController.php:694-715 (webp/avif branch)
	 */
	public function test_webp_companion_is_renamed_with_the_main_file() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );
		$original_dir  = dirname( $original_file );
		$webp_source   = $original_file . '.webp';
		// Copy the shipped webp fixture as a plausible-but-cheap webp file.
		copy( $this->fixturePath( 'fixture-large.webp' ), $webp_source );
		$this->assertFileExists( $webp_source, 'Precondition: webp companion in place' );

		// Force the model to rebuild its file family so it picks up the new
		// webp companion on disk.
		$this->resetPluginSingletons();

		$new_base = 'webp-rename-' . wp_generate_password( 6, false );
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertObjectNotHasProperty( 'is_error', $response );

		$new_file = $original_dir . '/' . $new_base . '.jpg';
		$this->assertFileExists( $new_file, 'Sanity: main file moved' );

		$new_webp = $original_dir . '/' . $new_base . '.jpg.webp';
		$this->assertFileExists(
			$new_webp,
			'WebP companion must be renamed alongside the main file'
		);
		$this->assertFileDoesNotExist(
			$webp_source,
			'Old WebP companion must be gone'
		);
	}

	// -------------------------------------------------------------------
	// Serialized postmeta rewrite
	// -------------------------------------------------------------------

	/**
	 * Page-builders store attachment URLs inside PHP-serialized postmeta.
	 * Replacer2 walks the postmeta table and rewrites the URL in-place; the
	 * serialised structure must remain valid.
	 *
	 * @see build/shortpixel/replacer2/src/Classes/Finder.php::postmeta()
	 */
	public function test_serialized_postmeta_is_rewritten_and_stays_valid() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_url  = wp_get_attachment_url( $attachment_id );
		$original_file = get_attached_file( $attachment_id );
		$original_base = pathinfo( $original_file, PATHINFO_FILENAME );

		$carrier_id = self::factory()->post->create( array( 'post_content' => 'no urls here' ) );
		$serialised = array(
			'widget'   => 'image',
			'settings' => array(
				'image'    => array( 'url' => $original_url, 'id' => $attachment_id ),
				'children' => array(
					array( 'src' => $original_url ),
				),
			),
		);
		update_post_meta( $carrier_id, '_elementor_data_like', $serialised );

		$new_base = 'serial-rename-' . wp_generate_password( 6, false );
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );

		// Force WP to re-read from DB so the assertion sees the Replacer2 write.
		wp_cache_delete( $carrier_id, 'post_meta' );

		$stored = get_post_meta( $carrier_id, '_elementor_data_like', true );
		$this->assertIsArray( $stored, 'Serialised postmeta must round-trip as an array' );
		$this->assertSame(
			$new_base . '.jpg',
			basename( $stored['settings']['image']['url'] ),
			'Nested serialised URL must be rewritten to the new base'
		);
		$this->assertSame(
			$new_base . '.jpg',
			basename( $stored['settings']['children'][0]['src'] ),
			'Deeply nested serialised URL must be rewritten to the new base'
		);
		$this->assertStringNotContainsString(
			$original_base . '.jpg',
			maybe_serialize( $stored ),
			'Old base must be gone from the serialised postmeta'
		);
	}

	// -------------------------------------------------------------------
	// Editor markup: Gutenberg block + Classic srcset
	// -------------------------------------------------------------------

	/**
	 * Block Editor (Gutenberg) stores images as wp:image block comments with
	 * JSON attributes plus a figure/img body carrying the wp-image-<ID>
	 * class. The rename rewrite runs plain URL replacement over
	 * post_content, so the block must come out with the new URL while the
	 * comment-delimited structure still parses via parse_blocks() and the
	 * id attribute / wp-image class stay intact (they are what ties the
	 * block to the attachment).
	 */
	public function test_gutenberg_image_block_is_rewritten_and_still_parses() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_url  = wp_get_attachment_url( $attachment_id );
		$original_file = get_attached_file( $attachment_id );
		$original_base = pathinfo( $original_file, PATHINFO_FILENAME );

		$block_markup = '<!-- wp:image {"id":' . $attachment_id . ',"sizeSlug":"full","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-full"><img src="' . esc_url( $original_url ) . '" alt="" class="wp-image-' . $attachment_id . '"/></figure>' . "\n"
			. '<!-- /wp:image -->';

		$post_id = self::factory()->post->create( array( 'post_content' => $block_markup ) );
		$this->assertStringContainsString(
			$original_base . '.jpg',
			get_post( $post_id )->post_content,
			'Sentinel: the block must embed the original URL before the rename'
		);

		$new_base = 'gberg-rename-' . wp_generate_password( 6, false );
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertObjectNotHasProperty( 'is_error', $response );

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringNotContainsString( $original_base . '.jpg', $content, 'Old filename must be gone from the block markup' );
		$this->assertStringContainsString( $new_base . '.jpg', $content, 'New filename must be written into the block markup' );

		// The block must still be a valid, parseable wp:image block.
		$blocks = array_values( array_filter( parse_blocks( $content ), function ( $b ) {
			return 'core/image' === $b['blockName'];
		} ) );
		$this->assertCount( 1, $blocks, 'The rewritten content must still parse as exactly one core/image block' );
		$this->assertSame( $attachment_id, $blocks[0]['attrs']['id'] ?? null, 'The block id attribute must survive the rewrite' );
		$this->assertStringContainsString(
			'wp-image-' . $attachment_id,
			$blocks[0]['innerHTML'],
			'The wp-image-<ID> class (attachment linkage) must survive the rewrite'
		);
		$this->assertStringContainsString( $new_base . '.jpg', $blocks[0]['innerHTML'], 'The parsed block body must carry the new URL' );
	}

	/**
	 * Classic Editor content commonly carries a responsive srcset listing
	 * the thumbnail URLs. replaceFiles() feeds EVERY file of the family
	 * (main + each size) into the Replacer search/replace arrays
	 * (OptimizeAiController.php:758-785), so every srcset entry must be
	 * rewritten — a partial rewrite would leave dead thumbnail URLs.
	 */
	public function test_classic_editor_srcset_entries_are_all_rewritten() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_url  = wp_get_attachment_url( $attachment_id );
		$original_file = get_attached_file( $attachment_id );
		$original_base = pathinfo( $original_file, PATHINFO_FILENAME );
		$meta          = wp_get_attachment_metadata( $attachment_id );
		$this->assertNotEmpty( $meta['sizes'], 'Precondition: thumbnails must exist for a srcset' );

		$base_url = trailingslashit( dirname( $original_url ) );
		$srcset   = array( esc_url( $original_url ) . ' ' . $meta['width'] . 'w' );
		foreach ( $meta['sizes'] as $sizeData ) {
			$srcset[] = esc_url( $base_url . $sizeData['file'] ) . ' ' . $sizeData['width'] . 'w';
		}
		$content = '<img src="' . esc_url( $original_url ) . '" srcset="' . implode( ', ', $srcset ) . '" alt="classic srcset" />';

		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );

		$new_base = 'srcset-rename-' . wp_generate_password( 6, false );
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertObjectNotHasProperty( 'is_error', $response );

		clean_post_cache( $post_id );
		$after = get_post( $post_id )->post_content;

		$this->assertStringNotContainsString(
			$original_base,
			$after,
			'No srcset entry may still reference the old base — every size URL must be rewritten'
		);
		foreach ( wp_get_attachment_metadata( $attachment_id )['sizes'] as $sizeName => $sizeData ) {
			$this->assertStringContainsString(
				$sizeData['file'],
				$after,
				"srcset entry for size $sizeName must reference the renamed thumbnail"
			);
		}
		$this->assertStringContainsString( $new_base . '.jpg', $after, 'The main src must carry the new base' );
	}

	// -------------------------------------------------------------------
	// Access control
	// -------------------------------------------------------------------

	/**
	 * The outer gate is is_author (edit_posts) — passed by any author.
	 * The per-image gate is imageIsEditable(): edit_others_posts OR
	 * edit_post on the specific attachment id. An author cannot edit
	 * another user's post, so the rename must return NO_ACCESS and no
	 * file may be renamed.
	 */
	public function test_author_cannot_rename_another_users_attachment() {
		// Upload as admin.
		$this->_setRole( 'administrator' );
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );

		// Attack as an author.
		$this->_setRole( 'author' );

		$response = $this->doReplaceFileName( $attachment_id, 'evil-author-rename' );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertSame(
			AjaxController::NO_ACCESS,
			$response->error,
			'Per-image access control must stop authors from renaming other users images'
		);
		$this->assertFileExists( $original_file, 'No move may happen on a denied request' );
	}

	/** An administrator ALWAYS has edit_others_posts → rename succeeds. */
	public function test_administrator_can_rename_any_attachment() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$original_file = get_attached_file( $attachment_id );

		$new_base = 'admin-rename-' . wp_generate_password( 6, false );
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );
		$this->assertFalse( $this->renameResult( $response )->is_error );
		$this->assertFileDoesNotExist( $original_file );
		$this->assertFileExists( dirname( $original_file ) . '/' . $new_base . '.jpg' );
	}

	// -------------------------------------------------------------------
	// Missing newFileName key
	// -------------------------------------------------------------------

	/**
	 * When the POST does not contain the newFileName key at all,
	 * replaceFileName() short-circuits with a "This image could not be
	 * loaded" error and no rename takes place. Note this is DIFFERENT
	 * from newFileName='' — that is rejected by the strlen<3 guard
	 * (regression #50 below).
	 */
	public function test_missing_newFileName_key_returns_error_without_rename() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );

		$response = $this->doReplaceFileNameMissingKey( $attachment_id );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( (bool) ( $response->is_error ?? false ), 'Missing key must yield is_error' );
		$this->assertNotEmpty( $response->message );
		$this->assertFileExists( $original_file, 'No rename may occur when the key is missing' );
	}

	// -------------------------------------------------------------------
	// REGRESSION #50 (fixed 202c6e3c): empty/short newFileName is rejected
	// -------------------------------------------------------------------

	/**
	 * REGRESSION TEST for BUG #50 (fixed in 202c6e3c): an empty
	 * newFileName used to pass the `false === $newFileName` check (because
	 * sanitize_file_name('') returns '', not false), reach
	 * OptimizeAiController::ajax_replaceFile() with an empty file base and
	 * rename every file to an extension-only dotfile ('.jpg') while the
	 * Replacer2 pass rewrote content URLs accordingly.
	 *
	 * The fix adds a `strlen($newFileName) < 3` guard in
	 * AjaxController::replaceFileName() that fires AFTER sanitisation, so
	 * both an empty string and a value that sanitises to fewer than 3
	 * characters are rejected with an error response before any rename.
	 * (WP's sanitize_file_name also trims leading dots, so a '.jpg'-style
	 * input cannot smuggle an empty PATHINFO_FILENAME base past the
	 * length guard either.)
	 *
	 * The rejection response shape differs from the success path: it has
	 * `error` + `is_error` + `message` and NO `is_done`/`redirect`.
	 */
	public function test_regression50_empty_new_filename_rejected_without_rename() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );
		$original_dir  = dirname( $original_file );
		$dotfile       = $original_dir . '/.jpg';

		// Sentinel: no leftover `.jpg` dotfile from another test — the
		// dotfile absence assertion below must be attributable to THIS call.
		if ( file_exists( $dotfile ) ) {
			@unlink( $dotfile );
		}
		$this->assertFileDoesNotExist( $dotfile, 'Sentinel: no leftover .jpg dotfile before the rename attempt' );

		// Sentinel: sanitize_file_name('') is still '' (not false) — the
		// empty value must reach the strlen guard, not the missing-key branch.
		$this->assertSame( '', sanitize_file_name( '' ), 'Sentinel: sanitize_file_name("") must still return ""' );

		$response = $this->doReplaceFileName( $attachment_id, '' );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( (bool) ( $response->is_error ?? false ), 'REGRESSION #50: empty newFileName must be rejected with is_error' );
		$this->assertObjectNotHasProperty( 'is_done', $response, 'REGRESSION #50: the rejection path must not report is_done' );
		$this->assertNotEmpty( $response->error ?? '', 'REGRESSION #50: the rejection carries an error text' );

		// No rename happened: original intact, no extension-only dotfile.
		$this->assertFileExists( $original_file, 'REGRESSION #50: the original main file must be untouched' );
		$this->assertFileDoesNotExist( $dotfile, 'REGRESSION #50: no extension-only ".jpg" dotfile may be created' );
	}

	/**
	 * REGRESSION TEST for BUG #50 (companion): a 1-2 character name is
	 * rejected by the same `strlen($newFileName) < 3` guard, and a value
	 * that SANITISES below 3 characters (guard runs post-sanitisation) is
	 * rejected too.
	 */
	public function test_regression50_short_new_filename_rejected_without_rename() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );

		// Two characters: below the minimum of 3.
		$response = $this->doReplaceFileName( $attachment_id, 'ab' );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( (bool) ( $response->is_error ?? false ), 'REGRESSION #50: 2-char newFileName must be rejected' );
		$this->assertFileExists( $original_file );

		// Sanitises to below 3: '???a' → 'a' after sanitize_file_name().
		$this->assertLessThan( 3, strlen( sanitize_file_name( '???a' ) ), 'Sentinel: the crafted input must sanitise below 3 chars' );

		$response = $this->doReplaceFileName( $attachment_id, '???a' );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( (bool) ( $response->is_error ?? false ), 'REGRESSION #50: sanitised-below-minimum newFileName must be rejected' );
		$this->assertFileExists( $original_file, 'REGRESSION #50: no rename may occur on rejection' );
	}

	// -------------------------------------------------------------------
	// REGRESSION #51 (fixed 202c6e3c): base_url no longer mangled when
	// the file base appears inside the directory path.
	// -------------------------------------------------------------------

	/**
	 * REGRESSION TEST for BUG #51 (fixed in 202c6e3c — URL building is
	 * now basename-anchored): replaceFiles() used to build the TARGET URL at
	 * class/Controller/Optimizer/OptimizeAiController.php:679 as
	 *
	 *     $target_url = str_replace($base_filename, $newFileBase, $source_url);
	 *
	 * str_replace() replaces EVERY occurrence, so when the file base
	 * also appears as a DIRECTORY segment in the URL (e.g. attachment
	 * `.../uploads/photo/photo.jpg` with base "photo"), the target URL
	 * receives the new base in BOTH places: `.../uploads/<newbase>/<newbase>.jpg`.
	 *
	 * The physical move only renames the FILE — not the directory — so
	 * the file lives at `.../uploads/photo/<newbase>.jpg`, while
	 * post_content / postmeta are rewritten to
	 * `.../uploads/<newbase>/<newbase>.jpg`, i.e. a URL whose DIRECTORY
	 * does not exist on disk → dead link.
	 *
	 * (Same root cause as the directory-mangling in the base_url
	 * computation at :675-677; both stem from unanchored str_replace on
	 * paths where the file base is a substring of the directory.)
	 *
	 * We reproduce the shape by filtering `upload_dir` to force uploads
	 * into a subdir named exactly like the fixture's file base.
	 *
	 * SENTINELS:
	 *  - Principle 5: assert the ORIGINAL URL is embedded in the post
	 *    BEFORE the rename (fixture-drift guard).
	 *  - Principle 5: assert file base == last dir segment (bug shape guard).
	 *  - Principle 2: assertions are string-contains + string-not-contains
	 *    on the actual post_content, not on truthy-but-wrong return values.
	 *
	 * The assertions below verify that only the final path segment is
	 * rewritten, leaving the containing directory unchanged.
	 */
	public function test_regression51_base_url_not_mangled_when_dir_contains_file_base() {
		$this->_setRole( 'administrator' );

		// Force uploads under a subdir named "photo" so the fixture ends
		// up at `.../uploads/photo/photo.jpg` — file base "photo" is now a
		// substring of the directory path.
		$subdir = 'spio-pin51-photo';
		$filter = function ( $u ) use ( $subdir ) {
			$u['subdir'] = '/' . $subdir;
			$u['path']   = $u['basedir'] . '/' . $subdir;
			$u['url']    = $u['baseurl'] . '/' . $subdir;
			return $u;
		};
		add_filter( 'upload_dir', $filter );

		// The file base must literally equal the last dir segment.
		$src = tempnam( sys_get_temp_dir(), 'photo-' );
		unlink( $src );
		copy( $this->fixturePath( 'fixture-small.jpg' ), $src . '.jpg' );
		// Rename to enforce a base of "photo".
		$renamed = dirname( $src ) . '/' . $subdir . '.jpg';
		copy( $src . '.jpg', $renamed );
		unlink( $src . '.jpg' );

		$attachment_id = $this->uploadFile( $renamed );
		@unlink( $renamed );

		$original_url  = wp_get_attachment_url( $attachment_id );
		$original_file = get_attached_file( $attachment_id );
		$original_base = pathinfo( $original_file, PATHINFO_FILENAME );
		$original_dir  = basename( dirname( $original_file ) );

		// Sentinels: the base must literally equal the enclosing dir name —
		// that is the shape that triggers #51.
		$this->assertSame( $subdir, $original_base, 'Sentinel: base must equal the enclosing dir name' );
		$this->assertSame( $subdir, $original_dir, 'Sentinel: enclosing dir must equal the enclosing dir name' );
		$this->assertStringContainsString(
			'/' . $subdir . '/' . $subdir . '.',
			$original_file,
			'Sentinel: file path must contain "/<base>/<base>." — the shape that triggers #51'
		);

		// Embed the CORRECT original URL in a post.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . esc_url( $original_url ) . '" alt="pin51" />' )
		);
		$this->assertStringContainsString(
			$original_url,
			get_post( $post_id )->post_content,
			'Sentinel: original URL must be embedded in the post BEFORE the rename'
		);

		$this->purgeQueueTable();

		$new_base = 'pin51new' . wp_generate_password( 4, false ); // 12-char, no dashes
		$response = $this->doReplaceFileName( $attachment_id, $new_base );

		remove_filter( 'upload_dir', $filter );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue( $this->renameResult( $response )->is_done );

		// The physical file moved to <orig-dir>/<newbase>.jpg — the dir
		// itself was NOT renamed (that's the whole point of the bug).
		$expected_new_file = dirname( $original_file ) . '/' . $new_base . '.jpg';
		$this->assertFileExists(
			$expected_new_file,
			'Sanity: the buggy path still physically moves the main file inside the ORIGINAL directory'
		);
		$this->assertFileDoesNotExist(
			$original_file,
			'Sanity: the old file is gone from the ORIGINAL directory'
		);

		clean_post_cache( $post_id );
		$after_content = get_post( $post_id )->post_content;

		// The correct rewritten URL should be `.../<orig-dir>/<newbase>.jpg` —
		// same directory as the file on disk. Under the bug the URL is
		// `.../<newbase>/<newbase>.jpg` (directory ALSO replaced), which
		// points to a directory that does not exist on disk → dead link.
		$correct_url = str_replace( $subdir . '.jpg', $new_base . '.jpg', $original_url );
		$mangled_url = str_replace( $subdir, $new_base, $original_url );

		$this->assertNotSame(
			$correct_url,
			$mangled_url,
			'Sentinel: mangled and correct URLs must differ — otherwise the fixture does not trip #51'
		);

		$this->assertStringContainsString(
			$correct_url,
			$after_content,
			'Only the filename segment should be rewritten in post_content.'
		);
		$this->assertStringNotContainsString(
			$mangled_url,
			$after_content,
			'The containing directory must not be rewritten with the filename base.'
		);
	}

	// -------------------------------------------------------------------
	// REGRESSION #53 (fixed in 80ac531b): the usage check ignores the
	// attachment's OWN postmeta rows.
	// -------------------------------------------------------------------

	/**
	 * REGRESSION #53 (self-match) — replaceFiles() skips the rename of an
	 * image that published content already uses (recent_upload !== true). It
	 * probes post_content and postmeta with a LIKE on the extension-stripped
	 * URL path. Postmeta of ATTACHMENTS (post_status 'inherit') is included, so
	 * on sites where a plugin stores the FULL URL in the attachment's own
	 * postmeta the image matched ITSELF and every AI rename was blocked.
	 * WP core itself stores relative paths, so a stock install never
	 * self-matched — this test plants the full URL to reproduce the shape.
	 * 80ac531b excludes the item (and its WPML/Polylang siblings) from the
	 * postmeta probe (Finder::postmeta 'exclude_post_ids').
	 */
	public function test_regression53_usage_check_ignores_the_attachments_own_postmeta() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$imageModel    = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$original_url  = $imageModel->getURL();
		$original_file = get_attached_file( $attachment_id );

		// The shape a builder / partner plugin leaves: the full URL in the
		// attachment's OWN postmeta.
		add_post_meta( $attachment_id, '_spio_test_full_url', $original_url );

		// SENTINEL (principle 5): the same LIKE the guard runs really matches
		// the attachment's own row — without the exclusion it would self-block.
		global $wpdb;
		$base_url  = preg_replace( '/\\.[^.\\/]+$/', '', parse_url( $original_url, PHP_URL_PATH ) );
		$self_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_value LIKE %s",
				$attachment_id,
				'%' . $wpdb->esc_like( $base_url ) . '%'
			)
		);
		$this->assertGreaterThan( 0, $self_hits, 'Sentinel: the attachment\'s own postmeta matches the usage probe.' );

		$ctrl  = OptimizeAiController::getInstance();
		$qItem = new QueueItem( array( 'imageModel' => $imageModel ) );
		$m     = ( new ReflectionClass( OptimizeAiController::class ) )->getMethod( 'replaceFiles' );
		$m->setAccessible( true );

		$result = $m->invoke(
			$ctrl,
			$qItem,
			'regression53-' . strtolower( wp_generate_password( 4, false, false ) ),
			array(
				'dry_run'        => false,
				'recent_upload'  => false, // run the usage check
				'imageThreshold' => 1,
				'url'            => $original_url,
			)
		);

		$this->assertTrue( $result, 'REGRESSION #53: the attachment\'s own postmeta must not count as a use — the rename goes ahead.' );
		$this->assertFileDoesNotExist( $original_file, 'REGRESSION #53: the file was really renamed.' );
	}

	// -------------------------------------------------------------------
	// #66 editor contract — moved here from the (flipped) #52 unit test
	// -------------------------------------------------------------------

	/**
	 * CONTRACT (a5ad9805, part of the #66 Gutenberg fix): after a real,
	 * successful, non-dry-run rename, replaceFiles() stores the new file URL
	 * on the queue result as replaced_content['replaced_url'], which
	 * screen-media.js UpdateGutenBerg() uses to refresh the image block's url
	 * in an open editor. Previously asserted inside the #52 pin on a
	 * copy-failure path; since 0db02498 that path bails out before recording
	 * anything, so the contract is verified here on a real rename.
	 */
	public function test_successful_rename_records_replaced_url_for_the_editor() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$new_base = 'replaced-url-' . wp_generate_password( 6, false );
		list( $result, $queueItem ) = $this->renameViaEngine( $attachment_id, $new_base );

		$this->assertTrue( $result, 'Sanity: a plain local rename must succeed.' );
		$this->assertStringContainsString( $new_base, get_attached_file( $attachment_id ), 'Sanity: the rename really happened.' );

		$replaced = $queueItem->result()->replaced_content;
		$this->assertIsArray( $replaced );
		$this->assertArrayHasKey( 'replaced_url', $replaced, 'replaceFiles() must record the new URL as replaced_content[replaced_url].' );
		$this->assertStringContainsString( $new_base, (string) $replaced['replaced_url'], 'replaced_url must point at the NEW file base.' );
	}

	// -------------------------------------------------------------------
	// BUG #73 — offloader-handled renames (WP Offload Media)
	// -------------------------------------------------------------------

	/**
	 * Make the attachment look the way WP Offload Media leaves it after
	 * "Remove files from server": the attached file resolves to a remote URL
	 * and the offload hook vouches for it. FileModel::UrlToPath() then marks
	 * the main file (and the thumbnails derived from it) as VIRTUAL.
	 */
	private function makeRemoteOnly( int $attachment_id ): void {
		$local = get_attached_file( $attachment_id );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$dir   = trailingslashit( dirname( $local ) );
		@unlink( $local );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			@unlink( $dir . $size['file'] );
		}

		$remote   = 'https://bucket.example.test/wp-content/uploads/' . get_post_meta( $attachment_id, '_wp_attached_file', true );
		$old_base = pathinfo( $local, PATHINFO_FILENAME );
		add_filter(
			'get_attached_file',
			static function ( $file, $id ) use ( $attachment_id, $remote ) {
				return ( (int) $id === $attachment_id ) ? $remote : $file;
			},
			10,
			2
		);
		// Vouch ONLY for objects that exist in the bucket (the old name and
		// its sizes), like the real offload hook. Vouching for every URL would
		// make the not-yet-created target names look taken, and the rename
		// would stop at the filename-conflict guard instead.
		add_filter(
			'shortpixel/image/urltopath',
			static function ( $result, $url ) use ( $old_base ) {
				return ( false !== strpos( (string) $url, $old_base ) ) ? \ShortPixel\Model\File\FileModel::$VIRTUAL_REMOTE : $result;
			},
			10,
			2
		);
	}

	/**
	 * REGRESSION #73(a) — local + remote copy (flipped from the pin,
	 * 2026-09-24, fixed in e165198f).
	 *
	 * 0db02498 bailed out whenever $copySource was empty, and an offloader
	 * that reported "applied" skipped the local copy loop, so every
	 * offloader-handled rename stopped before replaceMetaData() and told the
	 * user it failed. e165198f now also runs the local copy loop when the
	 * image is NOT virtual (`false === $applied || false === is_virtual()`),
	 * i.e. when the files exist on disk as well as in the bucket, and deletes
	 * the local sources afterwards whether or not the offloader applied.
	 *
	 * Simulated with a filter reporting "applied" exactly like
	 * wpOffload::replaceFiles() does (no S3 bucket needed).
	 */
	public function test_regression73_offloader_handled_rename_with_local_copy_completes() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$old_file = get_attached_file( $attachment_id );
		$dir      = trailingslashit( dirname( $old_file ) );

		$seen      = array();
		$offloader = function ( $applied, $sourceFiles ) use ( &$seen ) {
			$seen[] = is_array( $sourceFiles ) ? count( $sourceFiles ) : -1;
			return true; // "the offloader renamed the remote files itself"
		};
		add_filter( 'shortpixel/image/replace_files', $offloader, 10, 2 );

		$new_base = 'reg73-' . wp_generate_password( 6, false );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );
		remove_filter( 'shortpixel/image/replace_files', $offloader, 10 );

		// SENTINEL: the offloader hand-off really happened, with the real files.
		$this->assertCount( 1, $seen, 'Sentinel: the replace_files filter must have been consulted exactly once.' );
		$this->assertGreaterThan( 0, $seen[0], 'Sentinel: the filter must have received the source files.' );

		$this->assertTrue( $result, 'REGRESSION #73: an offloader-handled rename of a local+remote image must complete and report success.' );
		$this->assertStringContainsString( $new_base, get_attached_file( $attachment_id ), 'REGRESSION #73: _wp_attached_file must carry the new name.' );
		$this->assertStringContainsString(
			$new_base,
			(string) ( wp_get_attachment_metadata( $attachment_id )['file'] ?? '' ),
			'REGRESSION #73: the attachment metadata must carry the new name.'
		);
		$this->assertFileExists( $dir . $new_base . '.jpg', 'REGRESSION #73: the local copy must now exist under the new name.' );
		$this->assertFileDoesNotExist( $old_file, 'REGRESSION #73: the local source must be removed after the rename.' );
	}

	/** Rename a remote-only image while an offloader reports it renamed the bucket. */
	private function renameRemoteOnlyWithOffloaderApplied( int $attachment_id, string $new_base ): array {
		$seen      = 0;
		$offloader = function () use ( &$seen ) {
			$seen++;
			return true; // the offloader renamed (and deleted) the remote objects
		};
		add_filter( 'shortpixel/image/replace_files', $offloader, 10, 1 );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );
		remove_filter( 'shortpixel/image/replace_files', $offloader, 10 );
		return array( $result, $seen );
	}

	/**
	 * REGRESSION #73(a) — remote-only images (flipped 2026-09-24, fixed in
	 * 88b2bcfe; the defect was confirmed by Pedro on a real WP Offload Media
	 * site: "Copy failed to copy anything" in the log, bucket renamed,
	 * attachment slug unchanged).
	 *
	 * With "Remove files from server" the image is virtual, so no local copy
	 * is made and $copySource stays empty. The bail-out used to fire anyway and
	 * return false BEFORE replaceMetaData(). 88b2bcfe only bails when the
	 * offloader did NOT apply (`... && false === $applied`), so the rename now
	 * carries on to the metadata / slug / backup / content steps.
	 *
	 * The virtual state is simulated with an https:// URL; real WP Offload
	 * Media hands back a stream-wrapper path (s3://…). Both contain "://", so
	 * pathIsUrl() treats both as virtual.
	 */
	public function test_regression73_remote_only_offloader_handled_rename_completes() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->makeRemoteOnly( $attachment_id );
		// SENTINEL: the image really is virtual.
		$this->assertTrue( $this->freshImageModel( $attachment_id )->is_virtual(), 'Sentinel: the attachment must resolve as a virtual (remote-only) image.' );

		$new_base = 'reg73-remote-' . wp_generate_password( 6, false );
		list( $result, $seen ) = $this->renameRemoteOnlyWithOffloaderApplied( $attachment_id, $new_base );

		$this->assertSame( 1, $seen, 'Sentinel: the replace_files filter must have been consulted exactly once.' );
		$this->assertTrue( $result, 'REGRESSION #73(a): a remote-only rename the offloader applied must complete.' );
		$this->assertStringContainsString(
			$new_base,
			(string) ( wp_get_attachment_metadata( $attachment_id )['file'] ?? '' ),
			'REGRESSION #73(a): the attachment metadata must carry the new name.'
		);
	}

	/**
	 * REGRESSION (fixed in 80eecd0f, 2026-09-25; confirmed by Pedro on a real
	 * WP Offload Media site before the fix) — a remote-only rename must keep
	 * _wp_attached_file RELATIVE.
	 *
	 * The bug: replaceMetaData() read `get_attached_file($item_id)` — the
	 * FILTERED value. For a file missing locally WP Offload Media returns the
	 * remote location there (a stream-wrapper path s3://…, or the provider
	 * URL), so update_attached_file() stored that absolute location instead
	 * of "YYYY/MM/name.jpg". 80eecd0f reads it unfiltered
	 * (`get_attached_file($item_id, true)`).
	 */
	public function test_regression_remote_only_rename_keeps_attached_file_relative() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$raw_before = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		// SENTINEL: WordPress stores a relative path to begin with.
		$this->assertStringNotContainsString( '://', $raw_before, 'Sentinel: _wp_attached_file starts relative.' );

		$this->makeRemoteOnly( $attachment_id );
		$new_base = 'pin-attached-' . wp_generate_password( 6, false );
		list( $result ) = $this->renameRemoteOnlyWithOffloaderApplied( $attachment_id, $new_base );
		$this->assertTrue( $result, 'Sanity: the remote-only rename completes (#73(a) fixed).' );

		$raw_after = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$this->assertStringContainsString( $new_base, $raw_after, 'Sanity: _wp_attached_file was rewritten.' );
		$this->assertStringNotContainsString(
			'://',
			$raw_after,
			'REGRESSION: _wp_attached_file must stay relative after a remote-only rename (no s3:// or URL).'
		);
		$this->assertStringStartsWith( dirname( $raw_before ) . '/', $raw_after, 'REGRESSION: same YYYY/MM directory, relative.' );
	}

	/**
	 * CONTRACT — dry-run returns false (by design since e165198f) — when NO
	 * offloader applied the rename. Since 88b2bcfe the bail-out only fires
	 * when `false === $applied`, so a dry-run on an offloaded image the
	 * offloader "applied" (wpOffload skips its provider calls on dry_run but
	 * still returns true) carries on and reports true.
	 *
	 * Formerly pinned as a #73 facet: dry-run never copies, so the
	 * `count($copySource) === 0` bail-out made it return false. e165198f made
	 * that explicit (`|| true === $args['dry_run']`) and now also passes
	 * dry_run to the offloader filter so wpOffload skips the provider calls.
	 * No user-facing caller exists (the only production dry_run call sits in
	 * a commented-out debug block in EditMediaViewController). Note the
	 * bail-out still logs "Copy failed to copy anything" for a dry-run,
	 * which is misleading in a debug log.
	 */
	public function test_dry_run_always_returns_false_and_changes_nothing() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$old_file = get_attached_file( $attachment_id );

		$new_base = 'dry-' . wp_generate_password( 6, false );
		$result   = $this->replaceFilesWithArgs( $attachment_id, $new_base, array( 'dry_run' => true, 'recent_upload' => true ) );

		$this->assertFileExists( $old_file, 'Dry-run must not touch the file.' );
		$this->assertSame( $old_file, get_attached_file( $attachment_id ), 'Dry-run must not touch _wp_attached_file.' );
		$this->assertFalse( $result, 'Dry-run returns false by design (e165198f).' );
	}

	// -------------------------------------------------------------------
	// Renaming keeps the attachment title (fixed in 88b2bcfe)
	// -------------------------------------------------------------------

	/**
	 * REGRESSION (flipped 2026-09-24): e165198f made replaceMetaData() set
	 * post_title to the new file base on every rename, so a title the user
	 * wrote was lost (and WPML/Polylang translations would all have got the
	 * same untranslated filename). 88b2bcfe stopped touching post_title. The
	 * slug (post_name) still follows the new file base — that is intended.
	 */
	public function test_rename_keeps_a_custom_attachment_title_and_updates_the_slug() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$custom_title = 'Red boots on a mountain trail';
		wp_update_post( array( 'ID' => $attachment_id, 'post_title' => $custom_title ) );
		clean_post_cache( $attachment_id );
		// SENTINEL: the attachment really carries a human-written title.
		$this->assertSame( $custom_title, get_post( $attachment_id )->post_title, 'Sentinel: the custom title must be stored before the rename.' );

		$new_base = 'reg-title-' . strtolower( wp_generate_password( 6, false ) );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );
		$this->assertTrue( $result, 'Sanity: the rename itself must succeed.' );

		clean_post_cache( $attachment_id );
		$post = get_post( $attachment_id );
		$this->assertSame( $custom_title, $post->post_title, 'REGRESSION: the custom title must survive the rename.' );
		$this->assertSame( sanitize_title( $new_base ), $post->post_name, 'The attachment slug follows the new file base.' );
	}

	/**
	 * REGRESSION #81 (found 2026-09-28 in 3fd40001, fixed in dfa346be) —
	 * stripping a dimension or "-scaled" suffix from a filename must keep the
	 * main image intact.
	 *
	 * 3fd40001 added a skip to replaceFileBaseInPath() for any path whose name
	 * already matched `^<new>(-scaled)?(-\d+x\d+)?$` (to stop WPML-synced
	 * translations being renamed twice). The CURRENT name of the image being
	 * renamed matches that too when the new name is the old one minus such a
	 * suffix ("banner-1920x600" → "banner"), so the files moved on disk but
	 * _wp_attached_file and metadata['file'] kept the deleted name, and the
	 * rename reported success. dfa346be removed the skip: since 8153f606 the
	 * WPML siblings get the item's new values directly, so it guarded nothing.
	 *
	 * Reach: a fresh upload cannot carry such a name — wp_unique_filename()
	 * always appends "-1" to names ending in -scaled / -rotated / -WxH
	 * (WP 5.3+). It takes a name from before WP 5.3, a previous SPIO rename
	 * (a typed name does not go through wp_unique_filename — this test uses
	 * that route), or an import that bypasses it.
	 */
	public function test_regression81_stripping_a_dimension_suffix_keeps_the_main_file_intact() {
		$this->_setRole( 'administrator' );
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		// A real-world name with a dimension suffix that is NOT a registered
		// thumbnail size (so the target-conflict guard stays out of the way).
		$base = 'banner' . wp_generate_password( 4, false, false );
		list( $first ) = $this->renameViaEngine( $attachment_id, $base . '-1920x600' );
		$this->assertTrue( $first, 'Sanity: the first rename (adding the suffix) works.' );
		$this->assertSame( $base . '-1920x600.jpg', basename( get_attached_file( $attachment_id ) ), 'Sentinel: the image is now called <base>-1920x600.' );
		$dir = dirname( get_attached_file( $attachment_id ) );

		list( $result ) = $this->renameViaEngine( $attachment_id, $base );
		$this->assertTrue( $result, 'The rename reports success.' );

		// SENTINEL: the files really moved to the new name on disk.
		$this->assertFileExists( $dir . '/' . $base . '.jpg', 'Sentinel: the main file was renamed on disk.' );
		$this->assertFileDoesNotExist( $dir . '/' . $base . '-1920x600.jpg', 'Sentinel: the old main file is gone.' );

		clean_post_cache( $attachment_id );
		$raw  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$meta = wp_get_attachment_metadata( $attachment_id );

		// REGRESSION #81: WordPress follows the rename and points at a file that exists.
		$this->assertSame(
			$base . '.jpg',
			basename( $raw ),
			'REGRESSION #81: _wp_attached_file must follow the rename.'
		);
		$this->assertSame( $base . '.jpg', basename( (string) ( $meta['file'] ?? '' ) ), 'REGRESSION #81: metadata[file] must follow the rename.' );
		$this->assertFileExists( $dir . '/' . basename( $raw ), 'REGRESSION #81: the referenced main file exists.' );
	}

	/**
	 * PIN (beta report #6, "an AI filename change breaks the image when the
	 * name has .jpg in the middle") — Replacer2 derives the base URL with
	 *     str_replace('.' . pathinfo($url, PATHINFO_EXTENSION), '', $url)
	 * (build/shortpixel/replacer2/src/Replacer.php:145 and
	 * src/Classes/Url.php:21), which removes EVERY ".jpg" in the URL, not only
	 * the extension: ".../pic.jpg-edit.jpg" becomes ".../pic-edit", which
	 * matches nothing in post_content. The files and _wp_attached_file are
	 * renamed, but the posts keep the old URLs — the image is broken in the
	 * post and in the editor. Normal names ("pic.jpg") are unaffected.
	 *
	 * Suggested fix (in the replacer2 MODULE source, then rebuild — build/ is
	 * generated): strip only the trailing extension, e.g.
	 *     $base_url = preg_replace('/\.' . preg_quote($ext, '/') . '$/', '', $base_url);
	 * FLIP-when-fixed: the post content points at the renamed files.
	 */
	public function test_pin_rename_leaves_post_urls_when_the_name_contains_the_extension_pinned_for_deferred_fix() {
		$this->_setRole( 'administrator' );
		$tmp = trailingslashit( get_temp_dir() ) . 'pic' . strtolower( wp_generate_password( 4, false, false ) ) . '.jpg-edit.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $tmp );
		$attachment_id = $this->uploadFile( $tmp );
		$this->purgeQueueTable();

		$old_main  = wp_get_attachment_url( $attachment_id );
		$meta      = wp_get_attachment_metadata( $attachment_id );
		$uploads   = wp_upload_dir();
		$old_thumb = $uploads['url'] . '/' . $meta['sizes']['medium']['file'];
		$this->assertStringContainsString( '.jpg-edit', basename( $old_main ), 'Sentinel: the name has ".jpg" in the middle.' );

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . esc_url( $old_main ) . '" alt="" /><img src="' . esc_url( $old_thumb ) . '" alt="" />' )
		);

		$new_base = 'renamed-pic-' . strtolower( wp_generate_password( 4, false, false ) );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );

		// SENTINELS: the rename itself succeeded.
		$this->assertTrue( $result, 'Sentinel: the rename reports success.' );
		$this->assertSame( $new_base . '.jpg', basename( get_attached_file( $attachment_id ) ), 'Sentinel: _wp_attached_file carries the new name.' );
		$this->assertFileExists( get_attached_file( $attachment_id ), 'Sentinel: the renamed file exists.' );

		// THE PIN: the post still points at the old, now-missing files.
		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;
		$this->assertStringContainsString( basename( $old_main ), $content, 'PIN (beta #6): fixed? The post now points at the renamed main file — flip this pin.' );
		$this->assertStringNotContainsString( $new_base, $content, 'PIN (beta #6): no URL in the post was rewritten.' );
	}

	/**
	 * REGRESSION #79 (fixed in 9c3dab50) — after a rename ShortPixel's own
	 * image meta must carry the NEW WebP/AVIF filenames, for the main image
	 * and every thumbnail.
	 *
	 * replaceFiles() renamed the .webp/.avif companions on disk but left
	 * image_meta 'webp' / 'avif' on the old names. getImageType() returns the
	 * stored name without checking it exists, so delete, restore and the
	 * WebP/AVIF cleanup tools targeted missing files and the renamed
	 * companions were orphaned.
	 */
	public function test_regression79_rename_updates_the_webp_avif_names_in_shortpixel_meta() {
		$this->_setRole( 'administrator' );
		\wpSPIO()->settings()->createWebp = 1;
		\wpSPIO()->settings()->createAvif = 1;

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $attachment_id );
		$this->purgeQueueTable();

		$before = $this->freshImageModel( $attachment_id );
		$this->assertTrue( $before->isOptimized(), 'Sentinel: the image is optimized.' );
		$old_webp = $before->getWebp();
		$old_avif = $before->getAvif();
		$this->assertTrue( is_object( $old_webp ) && $old_webp->exists(), 'Sentinel: a WebP companion exists and is recorded.' );
		$this->assertTrue( is_object( $old_avif ) && $old_avif->exists(), 'Sentinel: an AVIF companion exists and is recorded.' );
		// Plain strings: FileModel instances are cached per path and the rename moves them.
		$old_webp_name = (string) $before->getMeta( 'webp' );
		$old_avif_name = (string) $before->getMeta( 'avif' );
		$old_webp_path = $old_webp->getFullPath();
		$this->assertNotSame( '', $old_webp_name, 'Sentinel: the webp filename is stored in the meta.' );
		$dir = dirname( get_attached_file( $attachment_id ) );

		$new_base = 'pin79-' . strtolower( wp_generate_password( 6, false, false ) );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );
		$this->assertTrue( $result, 'Sanity: the rename succeeds.' );

		// SENTINEL: the companions really were renamed on disk.
		$renamed = glob( $dir . '/' . $new_base . '*.{webp,avif}', GLOB_BRACE );
		$this->assertNotEmpty( $renamed, 'Sentinel: renamed .webp/.avif files exist on disk.' );
		$this->assertFileDoesNotExist( $old_webp_path, 'Sentinel: the old .webp is gone.' );

		// REGRESSION #79: the meta names the renamed companions, for the main image…
		$after = $this->freshImageModel( $attachment_id );
		$this->assertNotSame( $old_webp_name, $after->getMeta( 'webp' ), 'REGRESSION #79: the webp meta changed.' );
		$this->assertStringStartsWith( $new_base, (string) $after->getMeta( 'webp' ), 'REGRESSION #79: the webp meta carries the new name.' );
		$this->assertStringStartsWith( $new_base, (string) $after->getMeta( 'avif' ), 'REGRESSION #79: the avif meta carries the new name.' );
		$this->assertTrue( $after->getWebp()->exists(), 'REGRESSION #79: getWebp() points at an existing file.' );
		$this->assertTrue( $after->getAvif()->exists(), 'REGRESSION #79: getAvif() points at an existing file.' );

		// …and for every thumbnail that has companions.
		$checked = 0;
		foreach ( $after->get( 'thumbnails' ) as $name => $thumb ) {
			foreach ( array( 'webp', 'avif' ) as $type ) {
				$stored = $thumb->getMeta( $type );
				if ( empty( $stored ) ) {
					continue;
				}
				$checked++;
				$this->assertStringStartsWith( $new_base, (string) $stored, "REGRESSION #79: thumbnail $name $type meta carries the new name." );
				$this->assertFileExists( $dir . '/' . $stored, "REGRESSION #79: thumbnail $name $type file exists." );
			}
		}
		$this->assertGreaterThan( 0, $checked, 'Sentinel: thumbnails with webp/avif companions were checked.' );

		// Deleting the attachment now removes the renamed companions.
		wp_delete_attachment( $attachment_id, true );
		$this->assertEmpty( glob( $dir . '/' . $new_base . '*.{webp,avif}', GLOB_BRACE ), 'REGRESSION #79: no renamed .webp/.avif is orphaned after delete.' );
		foreach ( glob( $dir . '/' . $new_base . '*' ) as $leftover ) {
			unlink( $leftover ); // keep the shared uploads dir clean for later runs
		}
	}

	/**
	 * REGRESSION (found 2026-10-01 on Pedro's test site with a persistent
	 * object cache, fixed in ba79abb5) — after a rename, the URL rewrite in
	 * post content must invalidate the post cache.
	 *
	 * Replacer::doReplaceQuery() updates post_content with direct SQL; without
	 * clean_post_cache() get_post() kept serving the OLD content from the
	 * object cache — on Redis / Memcached for every later request, so the
	 * block editor opened old URLs (files already moved) and the next save or
	 * autosave wrote them back. The WP test framework's in-memory cache shows
	 * the same staleness inside one request, so no Redis is needed here.
	 */
	public function test_regression_rename_refreshes_the_post_cache_with_the_new_url() {
		$this->_setRole( 'administrator' );
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$old_name = basename( get_attached_file( $attachment_id ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<img src="' . esc_url( wp_get_attachment_url( $attachment_id ) ) . '" alt="" />',
			)
		);
		// Prime the object cache the way any page view / editor load does.
		$this->assertStringContainsString( $old_name, get_post( $post_id )->post_content, 'Sentinel: the post shows the image.' );

		$new_base = 'cache-pin-' . strtolower( wp_generate_password( 4, false, false ) );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );
		$this->assertTrue( $result, 'Sentinel: the rename succeeded.' );

		global $wpdb;
		$db_content = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		// SENTINEL: the database really was rewritten.
		$this->assertStringContainsString( $new_base, $db_content, 'Sentinel: the database holds the new URL.' );

		// REGRESSION: the cached post follows the database, no manual flush.
		$content = get_post( $post_id )->post_content;
		$this->assertStringContainsString( $new_base, $content, 'REGRESSION: get_post() returns the renamed URL.' );
		$this->assertStringNotContainsString( $old_name, $content, 'REGRESSION: no stale old URL.' );
	}

	/**
	 * PIN (found 2026-10-01 reviewing 9c3dab50, LOW: no user-facing caller
	 * uses dry_run today) — a dry-run writes the NEW webp/avif names into
	 * ShortPixel's meta although no file moves. 9c3dab50 calls setMeta() while
	 * building the rename plan and saveMeta() after the copy loop, neither
	 * guarded by dry_run; afterwards getWebp()/getAvif() point at files that
	 * do not exist.
	 * Fix: only setMeta()/saveMeta() when false === $args['dry_run'].
	 * FLIP-when-fixed: the stored names stay the same and still exist.
	 */
	public function test_pin_dry_run_writes_the_new_webp_avif_names_into_the_meta_pinned_for_deferred_fix() {
		\wpSPIO()->settings()->createWebp = 1;
		\wpSPIO()->settings()->createAvif = 1;
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $attachment_id );
		$this->purgeQueueTable();

		$before = $this->freshImageModel( $attachment_id );
		$webp   = (string) $before->getMeta( 'webp' );
		$avif   = (string) $before->getMeta( 'avif' );
		$this->assertNotSame( '', $webp, 'Sentinel: the webp name is stored.' );

		$this->replaceFilesWithArgs( $attachment_id, 'dry79-' . wp_generate_password( 6, false ), array( 'dry_run' => true, 'recent_upload' => true ) );

		$after = $this->freshImageModel( $attachment_id );
		// SENTINEL: the dry-run really left the files alone.
		$this->assertFileExists( dirname( get_attached_file( $attachment_id ) ) . '/' . $webp, 'Sentinel: the original .webp is still on disk.' );
		// THE PIN: the meta now names files that were never created.
		$this->assertNotSame( $webp, (string) $after->getMeta( 'webp' ), 'PIN: fixed? A dry-run no longer changes the stored webp name — flip this pin (assertSame).' );
		$this->assertFalse( $after->getWebp()->exists(), 'PIN: the stored webp name points at a missing file.' );
	}

	/**
	 * PIN #82 (found 2026-10-01 reviewing ba79abb5, deferred to 6.6.x) — after a rename, the URL
	 * rewrite in POSTMETA (page builders: Elementor, Breakdance… keep image
	 * URLs there) and in OPTIONS (widgets, theme mods) leaves the object cache
	 * stale. ba79abb5 added clean_post_cache() to the post_content pass only;
	 * Replacer::handleMetaData() still updates with direct SQL. On Redis /
	 * Memcached a page builder then loads the old URLs and saves them back.
	 * VERIFIED fix (temp-applied 2026-10-01): after each meta UPDATE,
	 * wp_cache_delete(<object id>, 'post_meta'|'comment_meta'|'term_meta'|
	 * 'user_meta'); for options wp_cache_delete('alloptions'/'notoptions',
	 * 'options') + the option's own key.
	 * FLIP-when-fixed: both cached reads return the new URL.
	 */
	public function test_pin82_rename_leaves_cached_postmeta_and_options_with_the_old_url_pinned_for_deferred_fix() {
		$this->_setRole( 'administrator' );
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$url      = wp_get_attachment_url( $attachment_id );
		$old_name = basename( $url );

		$page_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_content' => '' ) );
		update_post_meta( $page_id, '_spio_test_builder_data', 'image:' . $url );
		update_option( 'spio_test_widget_image', 'image:' . $url );
		// Prime the caches the way a page view does.
		$this->assertStringContainsString( $old_name, get_post_meta( $page_id, '_spio_test_builder_data', true ) );
		$this->assertStringContainsString( $old_name, get_option( 'spio_test_widget_image' ) );

		$new_base = 'meta-cache-' . strtolower( wp_generate_password( 4, false, false ) );
		list( $result ) = $this->renameViaEngine( $attachment_id, $new_base );
		$this->assertTrue( $result, 'Sentinel: the rename succeeded.' );

		global $wpdb;
		// SENTINELS: the database itself was rewritten.
		$this->assertStringContainsString( $new_base, (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_spio_test_builder_data'", $page_id ) ), 'Sentinel: postmeta rewritten in the database.' );
		$this->assertStringContainsString( $new_base, (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'spio_test_widget_image'" ), 'Sentinel: option rewritten in the database.' );

		// THE PIN: the cached reads still return the old URL.
		$this->assertStringContainsString( $old_name, get_post_meta( $page_id, '_spio_test_builder_data', true ), 'PIN #82: fixed? Cached postmeta now follows the rename — flip this pin.' );
		$this->assertStringContainsString( $old_name, get_option( 'spio_test_widget_image' ), 'PIN #82: fixed? The cached option now follows the rename — flip this pin.' );
	}
}
