<?php
/**
 * Cross-plugin compatibility: Polylang (Wave 3) — hook/data-level suite.
 *
 * This suite does NOT require the Polylang plugin to be installed and active.
 * Polylang's media-translation model creates duplicate attachment posts that
 * share the same `guid` (Polylang registers every translated copy using the
 * same URL as the original).  SPIO detects these duplicates via the
 * guid-based SQL branch in MediaLibraryModel::getWPMLDuplicates() when
 * `EnvironmentModel::plugin_active('polylang')` returns true.
 *
 * Since `plugin_active('polylang')` is driven by `is_plugin_active()` which
 * reads the `active_plugins` option, we can fake it with
 * `update_option('active_plugins', ['polylang/polylang.php'])` — no PHP
 * class or real Polylang code is needed for the guid-duplicate code path.
 *
 * KEY FINDING: getWPMLDuplicates() has separate WPML and Polylang branches.
 * For Polylang the guid-SQL query returns ONLY the attachment id column
 * (already an int), so no numeric-string normalisation is required (unlike
 * the WPML branch where element_id comes back as a string from wpdb).
 *
 * SINCE 2026-09: the REAL free Polylang plugin is also downloaded +
 * activated by bin/test.sh --compat (wp.org loop) — the real-plugin tests
 * below verify SPIO's hooks survive an actual Polylang boot and self-skip
 * when POLYLANG_VERSION is absent. The original faked-active tests above
 * keep working unchanged (the appended filter entry is simply redundant
 * when the bootstrap already lists polylang).
 *
 * RENAME DESYNC — guid-duplicate translations — BUG #69 (PARTIALLY
 * fixed in 202c6e3c, residual open HIGH):
 * OptimizeAiController::replaceFiles() now loops getWPMLDuplicates() and
 * updates each sibling's _wp_attachment_metadata (regression-covered
 * below), but the duplicate call passes is_duplicate=true which skips
 * update_attached_file() — every guid-duplicate sibling still keeps
 * _wp_attached_file pointing at the now-deleted old filename →
 * get_attached_file() for translations remains broken. Residual pin below.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class CompatPolylangTest extends SPIO_IntegrationTestCase {

	/** @var callable Filter appending polylang to active_plugins, removed in tear_down. */
	private $polylangFilter;

	public function set_up() {
		parent::set_up();

		// The compat bootstrap (tests/bootstrap.php, SPIO_PARTNER_PLUGINS=1)
		// hooks 'pre_option_active_plugins' and fully overrides the option, so
		// update_option('active_plugins') is invisible to is_plugin_active().
		// Append polylang via the same filter at a later priority instead. No
		// actual PHP class from the plugin is loaded; the guid-duplicate code
		// path needs none.
		$this->polylangFilter = function ( $active ) {
			$active = is_array( $active ) ? $active : array();
			if ( ! in_array( 'polylang/polylang.php', $active, true ) ) {
				$active[] = 'polylang/polylang.php';
			}
			return $active;
		};
		add_filter( 'pre_option_active_plugins', $this->polylangFilter, 20 );
	}

	public function tear_down() {
		remove_filter( 'pre_option_active_plugins', $this->polylangFilter, 20 );
		parent::tear_down();
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/** Fresh MediaLibraryModel for the given attachment id. */
	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/**
	 * Create a translation-style duplicate: a new attachment post that
	 * intentionally shares the SAME guid (and physical file) as $source_id,
	 * mirroring what Polylang does when it creates a translated media copy.
	 *
	 * @param  int $source_id Attachment to duplicate.
	 * @return int            ID of the newly inserted attachment.
	 */
	private function createPolylangDuplicate( int $source_id ): int {
		global $wpdb;

		// Fetch the source row's guid.
		$source_guid = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID = %d", $source_id )
		);
		$this->assertNotEmpty( $source_guid, 'Source attachment must have a guid.' );

		// Insert a sibling attachment sharing the SAME guid and file.
		$dup_id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) get_post_mime_type( $source_id ),
				'post_title'     => (string) get_the_title( $source_id ) . ' (Polylang translation)',
				'post_status'    => 'inherit',
				'guid'           => $source_guid,   // ← shared guid: Polylang's hallmark
			),
			get_attached_file( $source_id )
		);
		$this->assertGreaterThan( 0, $dup_id, 'Polylang duplicate attachment must be created.' );

		// Copy the WP attachment metadata to the duplicate as Polylang would.
		update_post_meta( $dup_id, '_wp_attachment_metadata', wp_get_attachment_metadata( $source_id ) );

		// Force the stored guid to match the source (wp_insert_attachment may
		// normalise it — write it directly so the SQL query can match).
		$wpdb->update( $wpdb->posts, array( 'guid' => $source_guid ), array( 'ID' => $dup_id ) );

		return $dup_id;
	}

	// -------------------------------------------------------------------
	// Coexistence
	// -------------------------------------------------------------------

	/**
	 * SPIO must start up cleanly when Polylang is listed as active — no
	 * fatal errors, and the environment must confirm Polylang is detected.
	 *
	 * Manual plan rows 22.x precondition.
	 *
	 * @return void
	 */
	public function test_spio_loads_alongside_polylang_style_duplicated_media() {
		$this->assertTrue(
			\wpSPIO()->env()->plugin_active( 'polylang' ),
			"SPIO's EnvironmentModel must detect Polylang as active when polylang/polylang.php is in active_plugins."
		);

		// SPIO must still be functional: upload + model construction must work.
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$model = $this->freshImageModel( $id );
		$this->assertNotFalse( $model, 'MediaLibraryModel must load for an attachment when Polylang is active.' );
	}

	// -------------------------------------------------------------------
	// 22.1 — guid-duplicate detection
	// -------------------------------------------------------------------

	/**
	 * getWPMLDuplicates() must return the id of an attachment that shares
	 * the same guid, representing a Polylang media translation.
	 *
	 * Manual plan row 22.1.
	 *
	 * @return void
	 */
	public function test_guid_duplicates_are_detected_for_polylang_translations() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createPolylangDuplicate( $id );

		$duplicates = $this->freshImageModel( $id )->getWPMLDuplicates();

		$this->assertContains(
			$dup_id,
			$duplicates,
			'getWPMLDuplicates() must find the Polylang guid-duplicate sibling.'
		);
		$this->assertNotContains(
			$id,
			$duplicates,
			'The attachment itself must never appear in its own duplicate list.'
		);
	}

	// -------------------------------------------------------------------
	// 22.1 (extended) / 22.x — optimizing propagates to Polylang duplicate
	// -------------------------------------------------------------------

	/**
	 * When the original image is optimized, SPIO must propagate the
	 * optimized state to every Polylang guid-duplicate — sharing meta
	 * means the translation is also marked optimized without an extra
	 * API call.
	 *
	 * Manual plan row 22.1 (propagation side).
	 *
	 * @return void
	 */
	public function test_optimizing_original_propagates_to_polylang_duplicate() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createPolylangDuplicate( $id );

		// Both the upload and the wp_insert_attachment duplicate are
		// auto-enqueued (autoMediaLibrary); purge so exactly one explicit
		// optimize run drives the API-call count below.
		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$this->assertTrue(
			$this->freshImageModel( $id )->isOptimized(),
			'Original attachment must be optimized.'
		);

		$duplicate = $this->freshImageModel( $dup_id );
		$this->assertTrue(
			$duplicate->isOptimized(),
			'Polylang guid-duplicate must share the optimized state after the original is optimized.'
		);

		// Only ONE physical file may reach the API. The pipeline legitimately
		// POSTs the same urllist to the reducer twice per job (send, then
		// fetch results), so count UNIQUE urllists rather than raw calls.
		$urllists = array();
		foreach ( $this->api->requests as $r ) {
			if ( false !== strpos( $r['url'], 'reducer' ) && isset( $r['request']['urllist'] ) ) {
				$urllists[ wp_json_encode( $r['request']['urllist'] ) ] = true;
			}
		}
		$this->assertCount(
			1,
			$urllists,
			'Optimizing with a Polylang duplicate must produce exactly one API request — not one per language.'
		);
	}

	// -------------------------------------------------------------------
	// 22.2 — second translation after optimize must not re-optimize
	// -------------------------------------------------------------------

	/**
	 * Adding a second Polylang translation AFTER the image is already
	 * optimized must not trigger a new API request.  The duplicate is
	 * handled by metadata propagation only.
	 *
	 * Manual plan row 22.2.
	 *
	 * @return void
	 */
	public function test_second_translation_of_optimized_image_not_reoptimized() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createPolylangDuplicate( $id );

		$this->optimizeAttachment( $id );
		$this->assertTrue(
			$this->freshImageModel( $id )->isOptimized(),
			'Original must be optimized before adding a second translation.'
		);

		$api_call_count_before = count( $this->api->requests );

		// Add a second translation (another guid-duplicate).
		$dup2_id = $this->createPolylangDuplicate( $id );

		// A clean queue tick must not enqueue or process the already-shared file.
		$this->purgeQueueTable();
		$this->runQueueUntilEmpty();

		$this->assertCount(
			$api_call_count_before,
			$this->api->requests,
			'Adding a second Polylang translation of an already-optimized image must not trigger a new API request.'
		);

		// The new duplicate reports as optimized WITHOUT any extra API call:
		// Polylang copies the attachment meta when creating a translation
		// (mirrored here by createPolylangDuplicate), and the copy of an
		// already-optimized source carries the ShortPixel meta with it.
		$dup2 = $this->freshImageModel( $dup2_id );
		$this->assertTrue(
			$dup2->isOptimized(),
			'A Polylang guid-duplicate created from an already-optimized source carries the optimized meta copy (no extra API call).'
		);
	}

	// -------------------------------------------------------------------
	// 22.4 — deleting a translation preserves backup until last copy gone
	// -------------------------------------------------------------------

	/**
	 * Deleting a Polylang translation attachment must preserve the SPIO
	 * backup while the original (or any other language copy) still
	 * references the same physical file.  The backup must only be removed
	 * when the last attachment sharing the file is deleted.
	 *
	 * Manual plan row 22.4.
	 *
	 * @return void
	 */
	public function test_deleting_translation_preserves_backup_until_last_copy() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createPolylangDuplicate( $id );

		$this->optimizeAttachment( $id );

		$main = $this->freshImageModel( $id );
		$dupl = $this->freshImageModel( $dup_id );
		$this->assertTrue( $main->isOptimized(), 'Original must be optimized before testing backup preservation.' );
		$this->assertTrue( $dupl->isRestorable(), 'Translation must be restorable (backup present) before deletion.' );

		// Capture the backup path up front and assert on the file directly:
		// BackupController keeps a static per-id BackupModel whose hasBackup()
		// result is cached in-process, so isRestorable() reads stale state
		// after a delete — even on a freshly loaded image model.
		$backupFile = $main->getBackupModel()->getBackupFile( $main );
		$this->assertIsObject( $backupFile, 'Backup file model must exist after optimization.' );
		$backupPath = $backupFile->getFullPath();
		$this->assertFileExists( $backupPath, 'Backup file must exist on disk after optimization.' );

		// Delete the translation — guid-duplicate still present (the original),
		// so onDelete() must force $fileDelete=false and keep the backup.
		$dupl->onDelete();

		// The backup must still exist because the original still holds a reference.
		clearstatcache();
		$this->assertFileExists(
			$backupPath,
			'Backup must be preserved after deleting only the Polylang translation while the original still exists.'
		);

		// Now delete the original — the guid-duplicate post still exists in the
		// DB (onDelete does not remove the post), so remove it first to reflect
		// the real-world "last copy" state.
		wp_delete_post( $dup_id, true );
		$main_fresh = $this->freshImageModel( $id );
		$main_fresh->onDelete();

		clearstatcache();
		$this->assertFileDoesNotExist(
			$backupPath,
			'Backup must be removed once the last attachment sharing the physical file is deleted.'
		);
	}

	// -------------------------------------------------------------------
	// REAL Polylang plugin (wp.org download, active since 2026-09)
	// -------------------------------------------------------------------

	/**
	 * With the real plugin loaded, SPIO must detect it WITHOUT the faked
	 * filter and the whole upload → model pipeline must stay functional —
	 * this is the "hooks still work, no surprises" guard Pedro asked for.
	 */
	public function test_real_polylang_loads_alongside_spio() {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			$this->markTestSkipped( 'Real Polylang is not loaded — run via bin/test.sh --compat (downloads polylang from wp.org).' );
		}

		// Detection must hold on the bootstrap's own active_plugins list,
		// independent of this class's appended filter.
		remove_filter( 'pre_option_active_plugins', $this->polylangFilter, 20 );
		$detected = \wpSPIO()->env()->plugin_active( 'polylang' );
		add_filter( 'pre_option_active_plugins', $this->polylangFilter, 20 );

		$this->assertTrue( $detected, 'SPIO must detect the REAL Polylang without any faked filter.' );

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$model = $this->freshImageModel( $id );
		$this->assertNotFalse( $model, 'MediaLibraryModel must load with real Polylang booted.' );
	}

	/** With real Polylang active, optimize must still work end to end. */
	public function test_real_polylang_optimize_pipeline_unaffected() {
		if ( ! defined( 'POLYLANG_VERSION' ) ) {
			$this->markTestSkipped( 'Real Polylang is not loaded — run via bin/test.sh --compat.' );
		}

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		$this->assertTrue(
			$this->freshImageModel( $id )->isOptimized(),
			'Optimization must succeed with the real Polylang plugin active.'
		);
	}

	// -------------------------------------------------------------------
	// PIN — file rename never reaches the guid-duplicate translations
	// -------------------------------------------------------------------

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does (:1409-1413). */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new \ShortPixel\Model\Queue\QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	/**
	 * PIN #69 — PARTIALLY fixed in 202c6e3c (see file docblock):
	 * replaceFiles() now loops getWPMLDuplicates() and calls
	 * replaceMetaData() for every sibling, so the duplicate's
	 * _wp_attachment_metadata['file'] DOES track the rename (regression
	 * assertions below). But the duplicate call passes is_duplicate=true,
	 * which deliberately SKIPS update_attached_file() — so the duplicate's
	 * _wp_attached_file still carries the OLD filename, pointing at a file
	 * that no longer exists on disk → get_attached_file() for the
	 * translation is still broken after the rename (residual pin).
	 *
	 * Flip the residual pin when: the duplicate's _wp_attached_file is
	 * also rewritten (or WPML/Polylang syncing is made to cover it).
	 */
	public function test_pin69_rename_leaves_polylang_duplicate_attached_file_on_old_filename_pinned_for_deferred_fix() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createPolylangDuplicate( $id );
		$this->purgeQueueTable();

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$this->assertSame( $old_file, get_attached_file( $dup_id ), 'Sentinel: original and translation must share the physical file.' );

		$new_base = 'pll-rename-' . wp_generate_password( 6, false );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sanity: the rename must report success.' );

		// The original is fully renamed…
		$this->assertStringContainsString( $new_base, get_attached_file( $id ), 'Sanity: original _wp_attached_file must carry the new base.' );
		$this->assertFileDoesNotExist( $old_file, 'Sanity: the shared physical file was moved to the new name.' );

		// REGRESSION (202c6e3c): the duplicate's metadata['file'] now
		// tracks the rename via the getWPMLDuplicates() loop.
		clean_post_cache( $dup_id );
		$dup_meta = wp_get_attachment_metadata( $dup_id );
		$this->assertStringContainsString(
			$new_base,
			(string) ( $dup_meta['file'] ?? '' ),
			'REGRESSION #69: the duplicate metadata[file] must carry the new base (getWPMLDuplicates loop in replaceFiles).'
		);
		$this->assertStringNotContainsString(
			$old_base,
			(string) ( $dup_meta['file'] ?? '' ),
			'REGRESSION #69: the duplicate metadata[file] must no longer reference the old filename.'
		);

		// RESIDUAL PIN: is_duplicate=true skips update_attached_file(), so
		// _wp_attached_file still points at the old, now-deleted file.
		$dup_attached = get_attached_file( $dup_id );
		$this->assertStringContainsString(
			$old_base,
			$dup_attached,
			'PIN #69 (residual): fixed? The duplicate _wp_attached_file now tracks the rename — flip this pin to a regression test.'
		);
		$this->assertFileDoesNotExist(
			$dup_attached,
			'PIN #69 (residual): the duplicate attached_file references a file that no longer exists — its media is broken after the rename.'
		);
	}
}
