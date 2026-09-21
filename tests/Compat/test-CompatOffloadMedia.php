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
 * RENAME — BUG #68 (1d61b243 + 0db02498; regression-covered below for the
 * paths this suite can reach):
 * OptimizeAiController::replaceFiles() now offers the rename to the
 * `shortpixel/image/replace_files` filter first. wpOffload::replaceFiles()
 * answers it for items served by the provider: it copies every provider
 * object to the new key, deletes the old keys and saves the as3cf item
 * with the new path. When the filter declines (item not served by a
 * configured provider) and the image is virtual, the rename is refused.
 * The offloaded state is simulated by saving a real as3cf
 * Media_Library_Item row (no S3 credentials needed; the plugin is
 * unconfigured, so its storage provider is the Null_Provider).
 *
 * The ORIGINAL #68 desync (local files renamed while the item kept the
 * old remote key, or the DB rewritten while nothing moved) no longer
 * happens: the two regression tests below assert that, with no usable
 * provider, a rename leaves NOTHING half-done. They are outcome-only on
 * purpose. Probed 2026-09-18: which path fires depends on how as3cf lazily
 * builds the item's objects from its as3cf_files table (which the WP test
 * framework may have created as a TEMPORARY table mid-run — hence the
 * "Can't reopen table" noise): with no objects, wpOffload::replaceFiles()
 * answers "handled" and the rename ends on the "copied nothing" bail-out;
 * with objects, copy_objects() on the Null_Provider throws and nothing
 * catches it.
 *
 * The new provider path is itself broken — BUG #73 (open, HIGH), pinned
 * deterministically elsewhere:
 *   - tests/External/Offload/test-wpOffload.php (stubbed item + client):
 *     "handled" claimed when no provider object matched; provider
 *     exceptions escape; every copy forced 'ACL' => 'public-read'; after a
 *     successful rename every thumbnail object records the MAIN filename;
 *   - tests/Integration/test-ChangeFilename.php: an "applied" rename
 *     returns false BEFORE the metadata / content / backup rewrite; every
 *     dry-run returns false.
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
	 * Run the rename, recording (at priority 1, i.e. BEFORE wpOffload's
	 * callback, which may throw) that the `shortpixel/image/replace_files`
	 * filter was consulted, and capturing an escaping exception as the
	 * result instead of failing the test on it.
	 *
	 * @return array{0: bool|\Throwable, 1: int} [rename result or the thrown error, times the filter was consulted]
	 */
	private function renameWithFilterSpy( int $attachment_id, string $new_base ): array {
		$consulted = 0;
		$spy       = function ( $applied ) use ( &$consulted ) {
			$consulted++;
			return $applied;
		};
		add_filter( 'shortpixel/image/replace_files', $spy, 1 );
		try {
			$result = $this->renameAttachment( $attachment_id, $new_base );
		} catch ( \Throwable $e ) {
			$result = $e;
		} finally {
			remove_filter( 'shortpixel/image/replace_files', $spy, 1 );
		}

		return array( $result, $consulted );
	}

	/**
	 * REGRESSION #68a — local + remote copy (replaces pin68a, 2026-09-18).
	 *
	 * Before 1d61b243/0db02498 the local files were renamed and success
	 * reported while the as3cf item kept the OLD remote key (404s once
	 * served from the bucket). Now the rename goes through
	 * wpOffload::replaceFiles() first. With WP Offload Media present but no
	 * usable provider, whichever path the rename takes it must leave NOTHING
	 * half-done: no DB rewrite, local files and the offload item untouched.
	 *
	 * Deliberately outcome-only: which path fires (wpOffload claiming
	 * "handled" → the #73 "copied nothing" bail-out, or copy_objects()
	 * throwing) depends on how as3cf lazily builds the item's objects from
	 * its as3cf_files table in this test process. Those #73 facets are
	 * pinned deterministically in tests/External/Offload/test-wpOffload.php.
	 */
	public function test_regression68_offloaded_rename_with_local_copy_leaves_nothing_half_done() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );
		$this->makeOffloadItem( $id );
		$raw_attached_before = (string) get_post_meta( $id, '_wp_attached_file', true );

		$new_base = 'reg68-local-' . wp_generate_password( 6, false );
		list( $result, $consulted ) = $this->renameWithFilterSpy( $id, $new_base );

		// SENTINEL: the rename really reached the offloader hand-off.
		$this->assertSame( 1, $consulted, 'Sentinel: the replace_files filter must have been consulted exactly once.' );

		$this->assertNotTrue( $result, 'REGRESSION #68: without a usable provider the rename cannot report success.' );
		$this->assertSame( $raw_attached_before, (string) get_post_meta( $id, '_wp_attached_file', true ), 'REGRESSION #68: _wp_attached_file must be untouched.' );
		$this->assertFileExists( $old_file, 'REGRESSION #68: the local file keeps its name.' );
		$this->assertFileDoesNotExist( $dir . $new_base . '.jpg', 'REGRESSION #68: no file may appear under the new name.' );

		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'The as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'REGRESSION #68: the offload item keeps its (still valid) old key.' );
		$this->assertStringNotContainsString( $new_base, $item->path(), 'REGRESSION #68: the offload item must not point at a key that was never created.' );
	}

	/**
	 * REGRESSION #68b — remote only, "remove local files" (replaces pin68b,
	 * 2026-09-18). Before, every copy() failed silently while the
	 * DB/metadata rewrite still ran and true was returned — the attachment
	 * then referenced a filename that existed nowhere. Now nothing is
	 * rewritten, whichever path the rename takes (outcome-only for the same
	 * reason as #68a; the uncaught provider exception it can hit here is a
	 * #73 facet pinned in tests/External/Offload/test-wpOffload.php).
	 */
	public function test_regression68_remote_only_rename_leaves_the_db_untouched() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );
		$this->makeOffloadItem( $id );
		$raw_attached_before = (string) get_post_meta( $id, '_wp_attached_file', true );

		// Simulate as3cf "remove local files": wipe main + thumbnails.
		$meta = wp_get_attachment_metadata( $id );
		@unlink( $old_file );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			@unlink( $dir . $size['file'] );
		}
		$this->assertFileDoesNotExist( $old_file, 'Precondition: local main file removed.' );

		$new_base = 'reg68-remote-' . wp_generate_password( 6, false );
		list( $result, $consulted ) = $this->renameWithFilterSpy( $id, $new_base );

		$this->assertSame( 1, $consulted, 'Sentinel: the replace_files filter must have been consulted exactly once.' );
		$this->assertNotTrue( $result, 'REGRESSION #68: a remote-only rename without a usable provider cannot report success.' );

		// (raw meta — as3cf filters get_attached_file() into the provider
		// URL once an item row exists)
		$this->assertSame(
			$raw_attached_before,
			(string) get_post_meta( $id, '_wp_attached_file', true ),
			'REGRESSION #68: _wp_attached_file must not be rewritten when nothing moved.'
		);
		$this->assertFileDoesNotExist( $dir . $new_base . '.jpg', 'REGRESSION #68: no file may appear under the new name.' );

		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'The as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'REGRESSION #68: the offload item keeps its old key.' );
	}
}
