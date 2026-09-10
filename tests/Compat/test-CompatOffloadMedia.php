<?php
/**
 * Cross-plugin compatibility: WP Offload Media Lite (Wave 3).
 *
 * Runs with the REAL amazon-s3-and-cloudfront plugin active
 * (bin/test.sh --compat downloads + activates it). The plugin is
 * deliberately left UNCONFIGURED (no provider/bucket) — the point is
 * to verify SPIO's dispatcher and hook wiring boot correctly and that
 * the optimize pipeline is unaffected when media stays local.
 * Covers class/external/offload/Offloader.php + wp-offload-media.php:
 *
 *   - as3cf and SPIO load side by side; as3cf_init fired.
 *   - The Offloader dispatcher picked the `wp-offload` handler (no
 *     virtual-filesystem offloader claimed the slot first).
 *   - The wpOffload shim registered its as3cf-side interception hooks.
 *   - Optimizing a normal (non-offloaded) attachment works end to end
 *     and the files stay local.
 *
 * RENAME DESYNC — BUG #68 (open, HIGH), pinned below:
 * OptimizeAiController::replaceFiles() (the single engine behind both the
 * AI filename rename and the manual "Change Filename" action) renames the
 * local files, backups, DB URLs and attachment metadata but NEVER informs
 * WP Offload Media: no hook fires after a successful rename and
 * wp-offload-media.php contains no rename handling at all. The as3cf item
 * record (wp_as3cf_items) keeps the OLD remote key, so:
 *   - local + remote ("keep local copy"): the disk move happens, but
 *     as3cf filters get_attached_file() into the provider URL, so the
 *     meta rewrite stores THAT — _wp_attached_file ends up holding a
 *     full provider URL, and the rewritten URLs point at a remote object
 *     name that does not exist in the bucket → 404s once as3cf serves
 *     from the provider;
 *   - remote only ("remove local files"): the local move() finds no
 *     source file, fails silently (result discarded — bug #52), yet the
 *     DB/metadata rewrite still runs → the attachment now references a
 *     filename that exists NEITHER locally NOR remotely.
 * The offloaded state is simulated by saving a real as3cf
 * Media_Library_Item row (no S3 credentials needed — approved approach);
 * the desync is asserted on that record, exactly what as3cf uses for
 * serving. Pins flip when replaceFiles() starts updating/re-uploading the
 * offload item (or refuses to rename offloaded attachments).
 *
 * @package Shortpixel_Image_Optimiser
 */

use DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item;
use ShortPixel\External\Offload\Offloader;
use ShortPixel\Model\Queue\QueueItem;

class CompatOffloadMediaTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'Amazon_S3_And_CloudFront' ) ) {
			$this->markTestSkipped( 'WP Offload Media is not loaded — run via bin/test.sh --compat.' );
		}
		// DDL BEFORE parent::set_up(): items_table() auto-installs
		// wp_as3cf_items (CREATE TABLE auto-commits in MySQL). Running it
		// here keeps the implicit COMMIT outside the per-test transaction
		// that parent::set_up() opens, so fixtures never leak.
		Media_Library_Item::items_table();
		parent::set_up();
	}

	// -------------------------------------------------------------------
	// Coexistence + dispatcher
	// -------------------------------------------------------------------

	public function test_offload_media_loads_alongside_spio() {
		$this->assertTrue( class_exists( 'Amazon_S3_And_CloudFront' ), 'The as3cf main class must exist.' );
		$this->assertGreaterThan( 0, did_action( 'as3cf_init' ), 'as3cf_init must have fired — the wpOffload boot depends on it.' );
		$this->assertTrue( \wpSPIO()->env()->plugin_active( 's3-offload' ), "SPIO's environment must detect WP Offload Media as active." );
	}

	public function test_offloader_dispatcher_selected_wp_offload() {
		$offloader = Offloader::getInstance();
		$this->assertSame( 'wp-offload', $offloader->getOffloadName(), 'The dispatcher must have booted the wp-offload handler on as3cf_init.' );

		// Unconfigured Lite install: isActive() must answer with a bool
		// (the wp-offload branch), never the null "not implemented" case.
		$this->assertIsBool( $offloader->isActive( 'wp-offload' ) );
	}

	public function test_wpoffload_as3cf_hooks_are_wired() {
		// Registered in wpOffload::init() — only reachable when the as3cf
		// compatibility checks passed (Media_Library_Item + item handlers).
		$this->assertNotFalse( has_filter( 'as3cf_attachment_file_paths' ), 'WebP/AVIF path injection into as3cf uploads must be wired.' );
		$this->assertNotFalse( has_filter( 'as3cf_pre_update_attachment_metadata' ), 'Metadata-update interception must be wired.' );
		$this->assertNotFalse( has_filter( 'as3cf_pre_handle_item_upload' ), 'Initial-upload interception must be wired.' );
		$this->assertNotFalse( has_filter( 'shortpixel/image/urltopath' ), 'Offloaded-URL resolution must be wired.' );
		$this->assertNotFalse( has_action( 'shortpixel/image/optimised' ), 'The post-optimize offload trigger must be wired.' );
	}

	// -------------------------------------------------------------------
	// Pipeline unaffected while media is local
	// -------------------------------------------------------------------

	public function test_optimize_works_with_unconfigured_offloader() {
		\wpSPIO()->settings()->processThumbnails = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue( $image->isOptimized(), 'Optimization must succeed while as3cf is present but unconfigured.' );

		// Nothing got offloaded: the main file must still exist locally.
		$this->assertFileExists( get_attached_file( $id ), 'The optimized file must remain on the local filesystem.' );
	}

	// -------------------------------------------------------------------
	// BUG #68 — file rename never reaches the offload item
	// -------------------------------------------------------------------

	/**
	 * Save a real as3cf item record marking the attachment as offloaded.
	 * The wp_as3cf_items table was auto-installed by items_table() in
	 * set_up() (items/item.php:508-528) — before the test transaction.
	 */
	private function makeOffloadItem( int $attachment_id ): Media_Library_Item {

		$source_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$remote_key  = 'wp-content/uploads/' . $source_path;

		$item = new Media_Library_Item(
			'aws',
			'us-east-1',
			'spio-test-bucket',
			$remote_key,
			false,
			$attachment_id,
			trailingslashit( wp_upload_dir()['basedir'] ) . $source_path,
			wp_basename( $source_path )
		);
		$saved = $item->save();
		$this->assertIsInt( $saved, 'Precondition: the as3cf item row must save cleanly.' );

		return $item;
	}

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does (:1409-1413). */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	/**
	 * PIN #68a — local + remote copy. Observed (worse than expected): with
	 * an as3cf item row present, as3cf filters get_attached_file() into
	 * the provider URL. The disk move itself still happens (the imageModel
	 * builds its paths from unfiltered metadata), but the meta rewrite
	 * reads the filtered value. Result:
	 *   - the local files ARE renamed on disk;
	 *   - _wp_attached_file is CORRUPTED into a full provider URL
	 *     ("http://bucket.s3...../pin68-local-x.jpg") instead of the
	 *     relative uploads path;
	 *   - the as3cf item record still points at the OLD remote key, so the
	 *     rewritten URLs 404 on the provider;
	 *   - and success is still reported.
	 *
	 * Flip when: replaceFiles() resolves the true local path for offloaded
	 * media and updates the offload item (or triggers an as3cf re-upload /
	 * refuses the rename for offloaded media).
	 */
	public function test_pin68_rename_leaves_offload_item_on_old_remote_key_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );
		$this->makeOffloadItem( $id );

		$new_base = 'pin68-local-' . wp_generate_password( 6, false );
		$result   = $this->renameAttachment( $id, $new_base );

		$this->assertTrue( $result, 'PIN #68: fixed? The rename no longer blindly reports success for offloaded media — flip this pin.' );

		// THE PIN (part 1): _wp_attached_file now holds a provider URL —
		// the engine renamed the as3cf-filtered path, not the local file.
		$attached_meta = (string) get_post_meta( $id, '_wp_attached_file', true );
		$this->assertStringContainsString( $new_base, $attached_meta, 'PIN #68: the meta was rewritten to the new base.' );
		$this->assertStringStartsWith( 'http', $attached_meta, 'PIN #68: fixed? _wp_attached_file is a relative uploads path again — flip this pin.' );

		// Sanity: the LOCAL rename really ran (the imageModel resolves its
		// paths from unfiltered metadata, so the disk move still happens —
		// only the _wp_attached_file rewrite reads the filtered URL).
		$this->assertFileDoesNotExist( $old_file, 'Precondition: the local file left the old name.' );
		$this->assertFileExists( $dir . $new_base . '.jpg', 'Precondition: the local file was renamed on disk.' );

		// THE PIN: the offload item was never told about the rename.
		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'The as3cf item row must still exist.' );
		$this->assertStringContainsString(
			$old_base,
			$item->path(),
			'PIN #68: fixed? The offload item now tracks the rename — flip this pin to a regression test.'
		);
		$this->assertStringNotContainsString(
			$new_base,
			$item->path(),
			'PIN #68: the remote key must NOT know the new base while the bug is present.'
		);
		$this->assertStringContainsString(
			$old_base,
			$item->source_path(),
			'PIN #68: the item source_path still references the old filename.'
		);
	}

	/**
	 * PIN #68b — remote only ("remove local files"). With no local source
	 * files, every FileModel::move() fails silently (discarded result —
	 * bug #52 territory), yet replaceFiles() still returns true and
	 * rewrites _wp_attached_file + metadata to the new base. Combined
	 * with the untouched offload item, the attachment now references a
	 * filename that exists neither locally nor remotely.
	 *
	 * Flip when: replaceFiles() detects the missing/offloaded source and
	 * either refuses the rename or renames the remote object instead.
	 */
	public function test_pin68_remote_only_rename_rewrites_db_while_no_file_moves_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );
		$this->makeOffloadItem( $id );

		// Simulate as3cf "remove local files": wipe main + thumbnails.
		$meta = wp_get_attachment_metadata( $id );
		@unlink( $old_file );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			@unlink( $dir . $size['file'] );
		}
		$this->assertFileDoesNotExist( $old_file, 'Precondition: local main file removed.' );

		$new_base = 'pin68-remote-' . wp_generate_password( 6, false );
		$result   = $this->renameAttachment( $id, $new_base );

		// THE PIN: success is reported although nothing could be moved.
		$this->assertTrue(
			$result,
			'PIN #68: fixed? replaceFiles() now refuses/handles a remote-only rename — flip this pin.'
		);

		// DB was rewritten to the new base anyway... (raw meta — as3cf
		// filters get_attached_file() into the provider URL once an item
		// row exists).
		$this->assertStringContainsString(
			$new_base,
			(string) get_post_meta( $id, '_wp_attached_file', true ),
			'PIN #68: _wp_attached_file was rewritten despite no file moving.'
		);

		// ...but the new file exists nowhere locally...
		$this->assertFileDoesNotExist( $dir . $new_base . '.jpg', 'PIN #68: no local file was created under the new name.' );

		// ...and the offload item still points at the old remote key.
		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'The as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'PIN #68: the remote key is still the old filename.' );
	}
}
