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
 *   - Pin #50 (HIGH): empty newFileName produces extension-only dotfiles.
 *   - Pin #51 (MEDIUM): base_url mangling when file base appears in the
 *     directory path (uploads subdir named like the file base).
 *   - Pin #53 (MEDIUM, AI-auto path): recent_upload=false guard matches
 *     the attachment's OWN _wp_attached_file rows.
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

	// -------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------

	/**
	 * End-to-end rename: main file + thumbnails on disk, _wp_attached_file
	 * updated, metadata['sizes'][*]['file'] rewritten, embedding
	 * post_content URL rewritten by Replacer2, guid NOT touched, response
	 * carries is_done=true / redirect=reload.
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
		$this->assertTrue( $response->is_done );
		$this->assertSame( 'reload', $response->redirect );
		$this->assertObjectNotHasProperty( 'is_error', $response, 'Happy path must not set is_error' );
		$this->assertStringContainsString( 'replaced', $response->message );

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
		$this->assertTrue( $response->is_done );
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
		$this->assertTrue( $response->is_done, 'is_done is always true — this is the current wire contract' );
		$this->assertTrue( (bool) ( $response->is_error ?? false ), 'Conflict must set is_error=true' );
		$this->assertStringContainsString( 'not replaced', $response->message );

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
		$this->assertTrue( $response->is_done );
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
		$this->assertTrue( $response->is_done );
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
		$this->assertTrue( $response->is_done );
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
		$this->assertTrue( $response->is_done );

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
		$this->assertTrue( $response->is_done );
		$this->assertObjectNotHasProperty( 'is_error', $response );
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
	 * from newFileName='' — that reaches the pinned #50 bug below.
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
	// PIN #50 (HIGH): empty newFileName produces extension-only dotfiles
	// -------------------------------------------------------------------

	/**
	 * PINNED BUG #50 (HIGH): AjaxController::replaceFileName() reads
	 * `$newFileName = isset($_POST['newFileName']) ? sanitize_file_name($_POST['newFileName']) : false`
	 * at class/Controller/AjaxController.php:1364. sanitize_file_name('')
	 * returns '' (NOT false), so the empty string passes the false-check
	 * at :1366 and reaches OptimizeAiController::ajax_replaceFile() at
	 * :777. Line :786 computes `$baseReplace = pathinfo(basename(''),
	 * PATHINFO_FILENAME)` → ''. replaceFiles() then runs
	 * `str_replace($base_filename, '', $fileObj->getFileName())` at :689
	 * on every file, so every filename collapses to '.<ext>' — an
	 * extension-only dotfile on POSIX. The Replacer2 pass also rewrites
	 * content URLs to those dotfile URLs.
	 *
	 * There is NO minimum-length / non-empty guard on the manual path
	 * (the strlen>5 guard at ~:413 lives on the AI path only).
	 *
	 * SENTINEL: sentinels the test setup so a "no-op" (fixed) implementation
	 * cannot pass by coincidence — the sentinel_prefix ensures the ORIGINAL
	 * filename is NOT itself a dotfile, and we assert BOTH that (a) a real
	 * dotfile was created AND (b) the original main file is gone AND
	 * (c) replaceFiles() reported true (which drives the "Files were
	 * replaced" message the user sees). A fix must reject the empty base
	 * BEFORE any move; when it lands the response will carry is_error and
	 * no dotfile will exist.
	 *
	 * FLIP INSTRUCTIONS when SPIO fixes #50: assert that the response
	 * carries is_error=true (or an early error), that the original main
	 * file is still on disk, and that no `.jpg` dotfile was created in
	 * the uploads dir.
	 */
	public function test_pin50_empty_new_filename_produces_extension_only_dotfile_pinned_for_deferred_fix() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$original_file = get_attached_file( $attachment_id );
		$original_dir  = dirname( $original_file );
		$dotfile       = $original_dir . '/.jpg';

		// Sentinel pre-condition (principle 5): make sure no leftover
		// `.jpg` dotfile from another test sits in the uploads dir — that
		// would make (a) pass without the bug firing here.
		if ( file_exists( $dotfile ) ) {
			@unlink( $dotfile );
		}
		$this->assertFileDoesNotExist(
			$dotfile,
			'Sentinel: no leftover .jpg dotfile before the buggy rename'
		);

		// Also verify sanitize_file_name('') is still '' (not false) —
		// principle 2: if WP core ever changes this the test would silently
		// stop firing the bug and land in the missing-key branch instead.
		$this->assertSame(
			'',
			sanitize_file_name( '' ),
			'Sentinel: sanitize_file_name("") must still return "" for #50 to fire'
		);

		$response = $this->doReplaceFileName( $attachment_id, '' );

		$this->assertIsObject( $response, 'Raw: ' . $this->lastRawResponse() );
		$this->assertTrue(
			$response->is_done,
			'PINNED BUG #50: an empty newFileName is currently accepted (is_done=true). ' .
			'FLIP INSTRUCTIONS when fixed: expect an error response and no rename.'
		);
		$this->assertObjectNotHasProperty(
			'is_error',
			$response,
			'PINNED BUG #50: the current buggy path reports success ("Files were replaced"). ' .
			'FLIP INSTRUCTIONS when fixed: expect is_error=true here.'
		);

		// The ORIGINAL main file is gone — the buggy code renamed it to `.jpg`.
		$this->assertFileDoesNotExist(
			$original_file,
			'PINNED BUG #50: the buggy path physically moves the main file. ' .
			'FLIP INSTRUCTIONS when fixed: the original main file must still exist.'
		);

		// And an extension-only dotfile now sits in its place.
		$this->assertFileExists(
			$dotfile,
			'PINNED BUG #50: the buggy path leaves an extension-only ".jpg" dotfile. ' .
			'FLIP INSTRUCTIONS when fixed: assert this file does NOT exist.'
		);

		// Type sentinel (principle 2): is_done is really the PHP bool true,
		// not a truthy string that could survive a partial fix that changed
		// the response shape.
		$this->assertIsBool( $response->is_done );
		$this->assertTrue( $response->is_done );

		// Clean up the dotfile so it does not pollute later tests.
		@unlink( $dotfile );
	}

	// -------------------------------------------------------------------
	// PIN #51 (MEDIUM): base_url mangling when the file base appears
	// inside the directory path.
	// -------------------------------------------------------------------

	/**
	 * PINNED BUG #51 (MEDIUM): replaceFiles() builds the TARGET URL at
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
	 * FLIP INSTRUCTIONS when SPIO fixes #51 (e.g. by anchoring the base
	 * replacement to the basename portion of the URL): change the
	 * assertions to expect the CORRECT rewritten URL
	 * `.../uploads/<orig-dir>/<newbase>.jpg` and to assert that the
	 * mangled URL `.../uploads/<newbase>/<newbase>.jpg` is NOT present.
	 */
	public function test_pin51_base_url_mangling_when_dir_contains_file_base_pinned_for_deferred_fix() {
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
		$this->assertTrue( $response->is_done );

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
			$mangled_url,
			$after_content,
			'PINNED BUG #51: post_content contains the mangled URL ' .
			'(directory ALSO renamed to the new base) because ' .
			'OptimizeAiController.php:679 uses str_replace($base_filename, ' .
			'$newFileBase, $source_url) — every occurrence, including the ' .
			'directory segment. The physical file was NOT moved to that ' .
			'directory, so the URL now points to a non-existent path. ' .
			'FLIP INSTRUCTIONS when fixed: assert $after_content contains ' .
			'$correct_url (basename-anchored) instead of $mangled_url.'
		);
		$this->assertStringNotContainsString(
			$correct_url,
			$after_content,
			'PINNED BUG #51: the correctly-rewritten URL is absent from post_content ' .
			'because the mangling replaced the directory segment too. ' .
			'FLIP INSTRUCTIONS when fixed: this assertion becomes assertStringContainsString.'
		);
	}

	// -------------------------------------------------------------------
	// PIN #53 (MEDIUM, AI-auto path): the recent_upload=false threshold
	// guard counts the attachment's OWN postmeta rows.
	// -------------------------------------------------------------------

	/**
	 * PINNED CONTRACT #53 (MEDIUM, AI-auto path): the recent_upload=false
	 * usage-threshold guard at class/Controller/Optimizer/
	 * OptimizeAiController.php:630-651 uses Finder::posts +
	 * Finder::postmeta with a base_url (extension-stripped path) LIKE
	 * pattern. For an attachment stored under WP core defaults
	 * (_wp_attached_file = 'YYYY/MM/foo.jpg', _wp_attachment_metadata
	 * as a serialised array with the same relative path), neither WP
	 * core postmeta row contains the FULL URL, so the base_url LIKE
	 * does NOT self-match — the guard reports imagePostCount = 0 and
	 * replaceFiles() proceeds with the rename.
	 *
	 * The originally-suspected bug (guard always self-matches → blocks
	 * every AI auto-rename) does NOT fire on this shape; it would fire
	 * on installs where a plugin (Elementor, WPML, EMR) stores the FULL
	 * URL in postmeta. Pedro to confirm with Bas whether that asymmetry
	 * is intentional or the guard should EXPLICITLY exclude self-rows
	 * to be safe against those partner-plugin shapes.
	 *
	 * We PIN the current WP-core-default behavior here (0 self-hits →
	 * guard passes → rename happens). The branch below defends against
	 * a future WP-core / test-lib change that silently starts putting
	 * the full URL somewhere the LIKE catches.
	 *
	 * SENTINELS:
	 *  - Principle 5: pre-count self-hits via the same LIKE the
	 *    production guard would run, so a green result cannot come from
	 *    the guard silently changing shape.
	 *  - Principle 2: assertIsBool on the strict return.
	 *
	 * FLIP INSTRUCTIONS when SPIO tightens the guard to exclude self-
	 * rows in all shapes (or replaces the LIKE with an id-based check):
	 * the test still passes if the exclusion is correct. If SPIO
	 * INSTEAD widens the guard so self-postmeta rows count, the
	 * else-branch below will fire — flip it to assertTrue()/assertFile
	 * DoesNotExist() and remove the wpdb pre-count altogether.
	 */
	public function test_pin53_recent_upload_false_matches_attachments_own_postmeta_pinned_for_deferred_fix() {
		$this->_setRole( 'administrator' );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$imageModel    = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$original_url  = $imageModel->getURL();
		$original_file = get_attached_file( $attachment_id );

		global $wpdb;

		// Sentinel principle 5: verify the exact query the production
		// guard runs. Setup::URL()->getBaseURL() computes the path minus
		// the extension; Finder::posts() and Finder::postmeta() both
		// LIKE-match `%<base_url>%` against post_content / meta_value.
		// If NEITHER a post nor postmeta row matches this base_url even
		// though the attachment (with its own post_content='' and its
		// own _wp_attached_file storing just the relative path
		// `YYYY/MM/foo.jpg`) is the only row present, the guard passes
		// (imagePostCount = 0 < threshold = 1) and replaceFiles reaches
		// the move loop. That is the ACTUAL current behavior — the
		// hardcoded recent_upload=true bypass on the manual path exists
		// for a different reason.
		$path     = parse_url( $original_url, PHP_URL_PATH );
		$base_url = preg_replace( '/\\.[^.\\/]+$/', '', $path );

		$post_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				  WHERE post_status='publish'
				    AND post_content LIKE %s",
				'%' . $wpdb->esc_like( $base_url ) . '%'
			)
		);
		$meta_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_status IN ('publish','inherit')
				   AND pm.meta_value LIKE %s",
				'%' . $wpdb->esc_like( $base_url ) . '%'
			)
		);

		$ctrl  = OptimizeAiController::getInstance();
		$qItem = new QueueItem( array( 'imageModel' => $imageModel ) );

		$ref = new ReflectionClass( OptimizeAiController::class );
		$m   = $ref->getMethod( 'replaceFiles' );
		$m->setAccessible( true );

		$result = $m->invoke(
			$ctrl,
			$qItem,
			'pin53wouldberenamed',
			array(
				'dry_run'        => false,
				'recent_upload'  => false, // exercise the buggy guard
				'imageThreshold' => 1,
				'url'            => $original_url,
			)
		);

		$this->assertIsBool(
			$result,
			'PINNED BUG #53: return type must be a strict bool for the assertion below to be meaningful.'
		);

		// The current guard behavior depends on whether posts/postmeta
		// LIKE-match the base_url. WP core stores _wp_attached_file as
		// `YYYY/MM/foo.jpg` (no /wp-content/uploads/ prefix) and
		// _wp_attachment_metadata as a serialised array holding the
		// same relative path — NEITHER contains the full URL path
		// `/wp-content/uploads/YYYY/MM/foo`, so a fresh upload with no
		// other references passes the guard and the rename proceeds.
		//
		// The recent_upload=true bypass on the manual path
		// (OptimizeAiController.php:790) therefore does NOT protect
		// against a self-postmeta-count guard as previously believed;
		// its real purpose is elsewhere (likely: skipping the check for
		// the manual UX where the user already accepted the risk).
		//
		// PINNED BUG #53: the guard treats DIFFERENT storage shapes
		// asymmetrically — attachments stored under WP core defaults
		// pass (0 self-hits) but attachments stored via plugins like
		// WPML/EMR whose postmeta carries the FULL URL (Elementor,
		// serialised builder JSON) match themselves and fail. That
		// asymmetry is the concrete deferred bug: the guard should
		// EXPLICITLY exclude the attachment's own postmeta rows.
		//
		// We PIN the current WP-core-default behavior here (guard passes
		// for a bare fresh upload — result MUST be true). When SPIO
		// hardens the guard to also handle the WPML/EMR case by
		// explicitly excluding self-rows, the test still passes because
		// the exclusion makes the count 0 either way. If SPIO instead
		// changes the semantics (e.g. checks the attachment's OWN
		// postmeta on purpose), the assertion below will flip and the
		// contract change becomes visible.
		if ( 0 === $post_hits && 0 === $meta_hits ) {
			// Guard passes → rename happened → replaceFiles returned true.
			$this->assertTrue(
				$result,
				'PINNED BUG #53 (WP-core-default shape): with 0 self-hits the ' .
				'guard passes and replaceFiles returns true. If this flips to false ' .
				'SPIO has newly self-count attachments — investigate before flipping the pin.'
			);
			$this->assertFileDoesNotExist(
				$original_file,
				'PINNED BUG #53: the rename actually happened. FLIP INSTRUCTIONS: ' .
				'when SPIO fixes #53 to explicitly exclude self-rows in all shapes ' .
				'(WPML/EMR postmeta with full URLs), this assertion stays green.'
			);
		} else {
			// Some plugin / test-lib WP variant stores the URL in a way
			// that self-matches; the guard blocks the rename.
			$this->assertFalse(
				$result,
				'PINNED BUG #53: the guard is counting the attachments own ' .
				'postmeta rows (' . $meta_hits . ' self-hits) via Finder::postmeta ' .
				'(post_status=inherit). FLIP INSTRUCTIONS when fixed: expect true here.'
			);
			$this->assertFileExists(
				$original_file,
				'PINNED BUG #53: no move happened (guard blocked it). ' .
				'FLIP INSTRUCTIONS when fixed: the original file must be GONE (renamed).'
			);
		}
	}
}
