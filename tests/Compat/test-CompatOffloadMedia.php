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
 * STATE 2026-09-24 (after 88b2bcfe): the original #68 desync is BACK in
 * both layouts, pinned below as #73(b). #73(a) was fixed (e165198f for
 * local+remote, 88b2bcfe for remote-only) by trusting the offloader's
 * "applied" — which un-masked wpOffload::replaceFiles() answering "handled"
 * when it renamed nothing:
 *   - Local + remote: local file and _wp_attached_file move to the new name
 *     while the as3cf item keeps the old key.
 *   - Remote-only ("remove local files"): success is reported and WordPress
 *     is rewritten to a filename that exists nowhere (until 2026-09-24 this
 *     was a regression test asserting the DB stayed untouched).
 * Both are deterministic now that tests/Integration/bootstrap.php creates
 * the as3cf_files table up front. (Before that the table was lazily
 * missing, as3cf built the item's objects differently depending on test
 * history, and these two tests had to stay outcome-only.)
 *
 * The provider path's other defects — BUG #73 (open, HIGH) — are pinned
 * deterministically elsewhere:
 *   - tests/External/Offload/test-wpOffload.php (stubbed item + client):
 *     "handled" claimed when no provider object matched; provider
 *     exceptions escape. (Thumbnail objects recording the MAIN filename,
 *     #73(e), was fixed in 31c93f71.) (The forced 'ACL' => 'public-read' on
 *     every copy is BUG #76, pinned in the same file.)
 *   - tests/Integration/test-ChangeFilename.php: an "applied" rename of a
 *     REMOTE-ONLY (virtual) image still returns false before the metadata /
 *     content / backup rewrite. (The local+remote case was fixed in
 *     e165198f and is regression-covered there; dry-run returning false is
 *     now by design.)
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
	// BUG #68 — file rename never reaches the offload item
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
	 * PIN #73(b) — WP Offload Media claims "handled" without renaming
	 * anything, which since e165198f brings the #68 desync BACK
	 * (was REGRESSION #68a until 2026-09-24).
	 *
	 * History: before 1d61b243/0db02498 the local files were renamed and
	 * success reported while the as3cf item kept the OLD remote key (#68).
	 * 0db02498 then routed renames through wpOffload::replaceFiles() and, by
	 * accident, its "copied nothing" bail-out aborted every offloader-handled
	 * rename — which masked #73(b): wpOffload::replaceFiles() returns TRUE
	 * when no provider object matched, i.e. it claims "handled" having
	 * renamed nothing (pinned deterministically in
	 * tests/External/Offload/test-wpOffload.php).
	 *
	 * e165198f correctly lets an offloader-handled rename of a local+remote
	 * image finish locally (see test_regression73_... in
	 * tests/Integration/test-ChangeFilename.php) — so the false "handled" now
	 * does real damage: SPIO renames the local files and rewrites
	 * _wp_attached_file, reports success, and the as3cf item keeps the OLD
	 * key. Verified 2026-09-24: attached file and local file on the new name,
	 * item path still on the old one.
	 *
	 * Deterministic since as3cf_files is created up front in
	 * tests/Integration/bootstrap.php (it was lazily missing before).
	 *
	 * Flip when: wpOffload::replaceFiles() returns false when nothing was
	 * renamed remotely — then restore the "nothing half-done" assertions
	 * (result not true, _wp_attached_file untouched, local file keeps its
	 * name, item keeps its old key).
	 */
	public function test_pin73_offload_claims_handled_without_renaming_so_item_keeps_old_key_pinned_for_deferred_fix() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$dir      = trailingslashit( dirname( $old_file ) );
		$this->makeOffloadItem( $id );

		$new_base = 'reg68-local-' . wp_generate_password( 6, false );
		list( $result, $consulted ) = $this->renameWithFilterSpy( $id, $new_base );

		// SENTINEL: the rename really reached the offloader hand-off.
		$this->assertSame( 1, $consulted, 'Sentinel: the replace_files filter must have been consulted exactly once.' );

		// THE PIN: the rename "succeeds" and WordPress moves to the new name...
		$this->assertTrue( $result, 'PIN #73(b): fixed? The rename no longer reports success when nothing was renamed remotely — flip this pin.' );
		$this->assertStringContainsString( $new_base, (string) get_post_meta( $id, '_wp_attached_file', true ), 'PIN #73(b): _wp_attached_file moved to the new name.' );
		$this->assertFileExists( $dir . $new_base . '.jpg', 'PIN #73(b): the local file was renamed.' );

		// ...while the offload item still points at the OLD key: the #68 desync.
		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'Sentinel: the as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'PIN #73(b): the offload item keeps the OLD key — WordPress and WP Offload Media now disagree on the filename.' );
		$this->assertStringNotContainsString( $new_base, $item->path(), 'PIN #73(b): the offload item was never told about the new name.' );
	}

	/**
	 * PIN #73(b), remote-only variant — the ORIGINAL #68 remote-only desync is
	 * BACK since 88b2bcfe (was REGRESSION #68b until 2026-09-24).
	 *
	 * History: before 1d61b243/0db02498 every copy() failed silently while the
	 * DB/metadata rewrite still ran and true was returned — the attachment then
	 * referenced a filename that existed nowhere (#68). From 0db02498 the
	 * "copied nothing" bail-out stopped that. 88b2bcfe fixed #73(a) by skipping
	 * the bail-out whenever the offloader reports it applied the rename — which
	 * is correct only if that report is true. wpOffload::replaceFiles() still
	 * answers "handled" when NO provider object matched (#73(b)), so with no
	 * usable provider the rename now reports success and rewrites WordPress to
	 * a filename that exists nowhere, while the as3cf item keeps the old key.
	 * Verified 2026-09-24 (probe): result true, metadata['file'] on the new
	 * name, no file anywhere under it, item path unchanged. (_wp_attached_file
	 * additionally gets the remote URL written into it — pinned separately in
	 * tests/Integration/test-ChangeFilename.php.)
	 *
	 * This is the "(a) must ship with (b)" dependency in the #77 report.
	 *
	 * Flip when: wpOffload::replaceFiles() returns false when nothing was
	 * renamed remotely — then restore the "DB untouched" assertions (result not
	 * true, _wp_attached_file raw value unchanged, no file under the new name,
	 * item keeps its old key).
	 */
	public function test_pin73_remote_only_rename_claims_success_without_renaming_anything_pinned_for_deferred_fix() {
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

		$new_base = 'reg68-remote-' . wp_generate_password( 6, false );
		list( $result, $consulted ) = $this->renameWithFilterSpy( $id, $new_base );

		$this->assertSame( 1, $consulted, 'Sentinel: the replace_files filter must have been consulted exactly once.' );

		// THE PIN: success is reported and WordPress moves to the new name...
		$this->assertTrue( $result, 'PIN #73(b): fixed? A remote-only rename without a usable provider no longer reports success — flip this pin.' );
		$this->assertStringContainsString(
			$new_base,
			(string) ( wp_get_attachment_metadata( $id )['file'] ?? '' ),
			'PIN #73(b): the attachment metadata was rewritten to the new name.'
		);
		// ...although that file exists nowhere, and the offload item never moved.
		$this->assertFileDoesNotExist( $dir . $new_base . '.jpg', 'PIN #73(b): no local file exists under the new name.' );
		wp_cache_flush();
		$item = Media_Library_Item::get_by_source_id( $id );
		$this->assertNotFalse( $item, 'Sentinel: the as3cf item row must still exist.' );
		$this->assertStringContainsString( $old_base, $item->path(), 'PIN #73(b): the offload item keeps the OLD key — nothing was renamed in the bucket.' );
	}
}
