<?php
/**
 * Cross-plugin compatibility: WP Offload Media Lite.
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
 * RENAME:
 * OptimizeAiController::replaceFiles() offers the rename to the
 * `shortpixel/image/replace_files` filter first. wpOffload::replaceFiles()
 * answers it for items served by the provider: it copies every provider
 * object to the new key, deletes the old keys and saves the as3cf item
 * with the new path. When the filter declines (item not served by a
 * configured provider) and the image is virtual, the rename is refused.
 * The offloaded state is simulated by saving a real as3cf
 * Media_Library_Item row (no S3 credentials needed; the plugin is
 * unconfigured, so its storage provider is the Null_Provider).
 *
 * replaceFiles() trusts the offloader's "applied", so a false "handled"
 * from wpOffload::replaceFiles() (it answers true when it renamed nothing)
 * desyncs WordPress and WP Offload Media — pinned below in both layouts:
 *   - Local + remote: local file and _wp_attached_file move to the new name
 *     while the as3cf item keeps the old key.
 *   - Remote-only ("remove local files"): success is reported and WordPress
 *     is rewritten to a filename that exists nowhere.
 * Both are deterministic because tests/Integration/bootstrap.php creates
 * the as3cf_files table up front (otherwise as3cf builds the item's
 * objects differently depending on test history).
 *
 * The provider path's other known defects are pinned deterministically
 * elsewhere:
 *   - tests/External/Offload/test-wpOffload.php (stubbed item + client):
 *     "handled" claimed when no provider object matched; provider
 *     exceptions escape.
 *   - tests/Integration/test-ChangeFilename.php: an "applied" rename of a
 *     REMOTE-ONLY (virtual) image; dry-run returning false is by design.
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
		// Both as3cf tables (as3cf_items AND as3cf_files) are installed once
		// in tests/Integration/bootstrap.php, before the first test
		// transaction — see the note there on as3cf's lazy-install static,
		// which makes a per-test call unreliable. This call is kept as a
		// cheap safety net: get_table_name() is a no-op once the table is
		// known to exist, and it keeps the DDL outside the per-test
		// transaction that parent::set_up() opens.
		Media_Library_Item::get_table_name();
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
	// File rename vs the offload item
	// -------------------------------------------------------------------

	/**
	 * Save a real as3cf item record marking the attachment as offloaded.
	 * The wp_as3cf_items table is installed up front by
	 * tests/Integration/bootstrap.php (re-checked in set_up()) — before the
	 * test transaction.
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
	 * Pins a known defect: WP Offload Media claims "handled" without renaming
	 * anything. wpOffload::replaceFiles() returns TRUE when no provider
	 * object matched (also pinned in tests/External/Offload/test-wpOffload.php).
	 * An offloader-handled rename of a local+remote image finishes locally
	 * (covered in tests/Integration/test-ChangeFilename.php), so SPIO renames
	 * the local files and rewrites _wp_attached_file, reports success, and
	 * the as3cf item keeps the OLD key.
	 *
	 * Flip when: wpOffload::replaceFiles() returns false when nothing was
	 * renamed remotely — then restore the "nothing half-done" assertions
	 * (result not true, _wp_attached_file untouched, local file keeps its
	 * name, item keeps its old key).
	 */
	public function test_offload_claims_handled_without_renaming_so_item_keeps_old_key_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );
		$this->makeOffloadItem( $id );

		$new_base = 'rename-local-' . wp_generate_password( 6, false );
		list( $result, $consulted ) = $this->renameWithFilterSpy( $id, $new_base );

		// SENTINEL: the rename really reached the offloader hand-off.
		$this->assertSame( 1, $consulted, 'Sentinel: the replace_files filter must have been consulted exactly once.' );

		// THE PIN: the rename "succeeds" and WordPress moves to the new name...
		$this->assertTrue( $result, 'PIN: fixed? The rename no longer reports success when nothing was renamed remotely — flip this pin.' );
		$this->assertStringContainsString( $new_base, (string) get_post_meta( $id, '_wp_attached_file', true ), 'PIN: _wp_attached_file moved to the new name.' );
		$this->assertFileExists( $dir . $new_base . '.jpg', 'PIN: the local file was renamed.' );

		// ...while the offload item still points at the OLD key: the desync.
		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'Sentinel: the as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'PIN: the offload item keeps the OLD key — WordPress and WP Offload Media now disagree on the filename.' );
		$this->assertStringNotContainsString( $new_base, $item->path(), 'PIN: the offload item was never told about the new name.' );
	}

	/**
	 * Pins a known defect, remote-only variant: replaceFiles() skips its
	 * "copied nothing" bail-out whenever the offloader reports it applied the
	 * rename — correct only if that report is true. wpOffload::replaceFiles()
	 * answers "handled" when NO provider object matched, so with no usable
	 * provider the rename reports success and rewrites WordPress to a
	 * filename that exists nowhere (metadata['file'] on the new name, no file
	 * anywhere under it), while the as3cf item keeps the old key.
	 *
	 * Flip when: wpOffload::replaceFiles() returns false when nothing was
	 * renamed remotely — then restore the "DB untouched" assertions (result not
	 * true, _wp_attached_file raw value unchanged, no file under the new name,
	 * item keeps its old key).
	 */
	public function test_remote_only_rename_claims_success_without_renaming_anything_pinned_for_deferred_fix() {
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

		$new_base = 'rename-remote-' . wp_generate_password( 6, false );
		list( $result, $consulted ) = $this->renameWithFilterSpy( $id, $new_base );

		$this->assertSame( 1, $consulted, 'Sentinel: the replace_files filter must have been consulted exactly once.' );

		// THE PIN: success is reported and WordPress moves to the new name...
		$this->assertTrue( $result, 'PIN: fixed? A remote-only rename without a usable provider no longer reports success — flip this pin.' );
		$this->assertStringContainsString(
			$new_base,
			(string) ( wp_get_attachment_metadata( $id )['file'] ?? '' ),
			'PIN: the attachment metadata was rewritten to the new name.'
		);
		// ...although that file exists nowhere, and the offload item never moved.
		$this->assertFileDoesNotExist( $dir . $new_base . '.jpg', 'PIN: no local file exists under the new name.' );
		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'Sentinel: the as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'PIN: the offload item keeps the OLD key — nothing was renamed in the bucket.' );
	}
}
