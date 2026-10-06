<?php
/**
 * Cross-plugin compatibility: WPML.
 *
 * Runs with the REAL WPML (sitepress-multilingual-cms) plugin active.
 * WPML is commercial, so bin/test.sh --compat extracts it from a zip
 * dropped into tests/partner-plugins/ (gitignored); without that zip
 * every test here SKIPS.
 *
 * Covers the SPIO x WPML integration surfaces:
 *
 *   - class/external/wpml.php — the AI alt-text locale shim: its two
 *     filters are only wired when plugin_active('wpml') is true, and
 *     checkParamList() injects the attachment's WPML locale into the
 *     outgoing AI request params.
 *   - OptimizeAiController::WPMLCheckReplace() — the replace-time
 *     language guard for AI text replacement (it compares 'language_code',
 *     never the non-existent 'code' key) at both the guard level and
 *     end-to-end through handleReplace().
 *   - MediaLibraryModel::getWPMLDuplicates() — translation duplicates
 *     found via the real icl_translations table (same-trid siblings),
 *     restricted to attachments sharing the same physical file; on
 *     optimize, handleOptimized() propagates meta to every duplicate.
 *   - QueueController::addWpmlAiItemsToQueue() — the requestAlt
 *     per-language fan-out (requestAlt is exempt from the duplicate-active
 *     check, so the ORIGINAL attachment is queued alongside its fan-out
 *     variants), and the isDuplicateActive() skip for same-file translations.
 *
 * Own-file translations (WPML Media Translation add-on) are covered in
 * test-CompatWPMLMedia.php.
 *
 * The icl_translations rows are seeded per test (WPML only fills them
 * once its setup wizard ran); the table itself is created by WPML's
 * activation or, failing that, by ensureIclTranslationsTable() with
 * the same columns SPIO queries.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class CompatWPMLTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		if ( ! class_exists( 'SitePress' ) ) {
			$this->markTestSkipped( 'WPML is not loaded — drop its zip into tests/partner-plugins/ and run bin/test.sh --compat.' );
		}

		// DDL auto-commits, so the table must exist BEFORE the test
		// transaction starts in parent::set_up().
		$this->ensureIclTranslationsTable();

		parent::set_up();
	}

	/**
	 * WPML normally creates icl_translations during its own setup; if the
	 * activation hook alone didn't (the wizard hasn't run in this install),
	 * create it with the columns SPIO's duplicate query uses.
	 */
	private function ensureIclTranslationsTable(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return;
		}
		$wpdb->query(
			"CREATE TABLE {$table} (
				translation_id bigint unsigned NOT NULL AUTO_INCREMENT,
				element_type varchar(60) NOT NULL DEFAULT 'post_post',
				element_id bigint unsigned NULL,
				trid bigint unsigned NOT NULL,
				language_code varchar(7) NOT NULL,
				source_language_code varchar(7) NULL,
				PRIMARY KEY (translation_id)
			)"
		);
	}

	private function insertTranslationRow( int $element_id, int $trid, string $lang, ?string $source = null ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'icl_translations',
			array(
				'element_type'         => 'post_attachment',
				'element_id'           => $element_id,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $source,
			)
		);
	}

	/**
	 * WPML core's own _wp_attached_file sync, replicated
	 * (sitepress classes/media/duplication/Hooks.php syncAttachedFile, on
	 * `update_postmeta`): when an ORIGINAL attachment's _wp_attached_file
	 * changes, WPML copies the new value to every translation of it that
	 * still holds the previous value. Only originals sync (IfOriginalPost /
	 * PostTranslations::getIfOriginal) — a translation changing its file
	 * propagates nothing. Not active in this test install, so tests that
	 * need real-site ordering install it explicitly.
	 */
	private function addWpmlSyncAttachedFileHook(): void {
		add_action(
			'update_postmeta',
			function ( $meta_id, $object_id, $meta_key, $meta_value ) {
				global $wpdb;
				if ( '_wp_attached_file' !== $meta_key ) {
					return;
				}
				$table = $wpdb->prefix . 'icl_translations';
				$row   = $wpdb->get_row( $wpdb->prepare( "SELECT trid, source_language_code FROM $table WHERE element_id = %d AND element_type = 'post_attachment'", $object_id ) );
				if ( ! $row || null !== $row->source_language_code ) {
					return; // Not an original: WPML does not sync.
				}
				$previous     = get_post_meta( $object_id, '_wp_attached_file', true );
				$translations = $wpdb->get_col( $wpdb->prepare( "SELECT element_id FROM $table WHERE trid = %d AND element_id <> %d", $row->trid, $object_id ) );
				foreach ( $translations as $translation_id ) {
					if ( get_post_meta( (int) $translation_id, '_wp_attached_file', true ) === $previous ) {
						update_post_meta( (int) $translation_id, '_wp_attached_file', $meta_value );
					}
				}
			},
			10,
			4
		);
	}

	/** A second attachment record pointing at the SAME file on disk (a WPML duplicate). */
	private function createDuplicateAttachment( int $source_id ): int {
		$dup_id = wp_insert_attachment(
			array(
				'post_mime_type' => get_post_mime_type( $source_id ),
				'post_title'     => get_the_title( $source_id ) . ' (translation)',
				'post_status'    => 'inherit',
			),
			get_attached_file( $source_id )
		);
		$this->assertGreaterThan( 0, $dup_id, 'Duplicate attachment must be created.' );
		update_post_meta( $dup_id, '_wp_attachment_metadata', wp_get_attachment_metadata( $source_id ) );
		return $dup_id;
	}

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/** Run the shared rename engine exactly like AjaxController::replaceFileName does (:1409-1413). */
	private function renameAttachment( int $attachment_id, string $new_base ): bool {
		$this->resetPluginSingletons();
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		$queueItem  = new \ShortPixel\Model\Queue\QueueItem( array( 'imageModel' => $imageModel ) );

		return $queueItem->getApiController( 'requestAlt' )->ajax_replaceFile( $queueItem, $new_base );
	}

	// -------------------------------------------------------------------
	// Coexistence + wiring
	// -------------------------------------------------------------------

	public function test_wpml_loads_alongside_spio() {
		$this->assertTrue( defined( 'ICL_SITEPRESS_VERSION' ), 'WPML version constant must be defined.' );
		$this->assertTrue( \wpSPIO()->env()->plugin_active( 'wpml' ), "SPIO's environment must detect WPML as active." );
	}

	public function test_spio_wpml_shim_hooks_are_wired() {
		// Both are gated on plugin_active('wpml') in the WPML shim's
		// constructor — they only exist in this compat run.
		$this->assertNotFalse( has_filter( 'shortpixel/aidatamodel/paramlist', array( $this->spioWpmlInstance(), 'checkParamList' ) ), 'The AI paramlist locale filter must be wired.' );
		$this->assertNotFalse( has_filter( 'shortpixel/ai/success', array( $this->spioWpmlInstance(), 'successHandle' ) ), 'The AI success passthrough filter must be wired.' );
	}

	/** The WPML shim instance registered on the AI paramlist filter. */
	private function spioWpmlInstance() {
		global $wp_filter;
		foreach ( $wp_filter['shortpixel/aidatamodel/paramlist']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \ShortPixel\WPML ) {
					return $callback['function'][0];
				}
			}
		}
		$this->fail( 'No ShortPixel\WPML instance found on the AI paramlist filter.' );
	}

	// -------------------------------------------------------------------
	// AI locale shim behavior
	// -------------------------------------------------------------------

	public function test_ai_paramlist_receives_wpml_locale() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// This unconfigured WPML install has no language data yet, so
		// answer its own lookup filter the way a configured WPML would.
		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function () {
				return array( 'locale' => 'de_DE', 'language_code' => 'de' );
			}
		);

		$params = apply_filters( 'shortpixel/aidatamodel/paramlist', array(), $id );
		$this->assertSame( 'de_DE', $params['languages'] ?? null, 'The WPML locale must be injected into the AI request params.' );
	}

	public function test_ai_paramlist_unchanged_without_locale() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// WPML reports no locale (multilingual mode off / "all languages").
		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function () {
				return array( 'locale' => null );
			}
		);

		$params = apply_filters( 'shortpixel/aidatamodel/paramlist', array(), $id );
		$this->assertArrayNotHasKey( 'languages', $params, 'No locale means no languages param.' );
	}

	// -------------------------------------------------------------------
	// AI replace-time language guard (WPMLCheckReplace)
	// -------------------------------------------------------------------

	/** Reflection access to the protected OptimizeAiController::WPMLCheckReplace(). */
	private function invokeWpmlCheckReplace( int $post_id, int $queue_item_id ): bool {
		$controller = \ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance();
		$method     = new ReflectionMethod( \ShortPixel\Controller\Optimizer\OptimizeAiController::class, 'WPMLCheckReplace' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $post_id, $queue_item_id );
	}

	/**
	 * When WPML cannot resolve a language for either side, the guard must
	 * refuse the replacement (fail closed).
	 */
	public function test_wpml_replace_guard_fails_closed_without_language_details() {
		remove_all_filters( 'wpml_post_language_details' );
		add_filter( 'wpml_post_language_details', '__return_null' );

		$this->assertFalse(
			$this->invokeWpmlCheckReplace( 12345, 67890 ),
			'With no WPML language details available, the replace guard must refuse the replacement.'
		);
	}

	/**
	 * Regression test: WPMLCheckReplace() compares the real `language_code`
	 * keys (WPML never provides a `code` key; comparing that would make both
	 * sides undefined, let different-language pages through and raise
	 * "Undefined array key" warnings on PHP 8). A post in a different
	 * language than the queued item must be refused, with no warning raised.
	 */
	public function test_wpml_replace_guard_blocks_other_languages() {
		$post_id  = 12345;
		$queue_id = 67890;

		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function ( $details, $id ) use ( $post_id ) {
				// Realistic WPML payload: language_code + locale, no 'code' key.
				return ( $id === $post_id )
					? array( 'language_code' => 'de', 'locale' => 'de_DE' )
					: array( 'language_code' => 'en', 'locale' => 'en_US' );
			},
			10,
			2
		);

		$this->assertFalse(
			$this->invokeWpmlCheckReplace( $post_id, $queue_id ),
			'A post in a different WPML language must be refused by the replace guard (comparing undefined \'code\' keys would let it through).'
		);
	}

	// -------------------------------------------------------------------
	// Translation duplicates
	// -------------------------------------------------------------------

	public function test_getWPMLDuplicates_finds_same_file_translations_only() {
		$id       = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id   = $this->createDuplicateAttachment( $id );
		$other_id = $this->uploadFixture( 'fixture-small.png' ); // different file on disk

		$trid = 991;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );
		// A translation legitimately linked to a DIFFERENT image — must be
		// filtered out, only same-file siblings share SPIO meta.
		$this->insertTranslationRow( $other_id, $trid, 'fr', 'en' );

		// element_id comes back from wpdb as numeric strings — normalize.
		$duplicates = array_map( 'intval', $this->freshImageModel( $id )->getWPMLDuplicates() );

		$this->assertContains( $dup_id, $duplicates, 'The same-file translation must be reported as a duplicate.' );
		$this->assertNotContains( $other_id, $duplicates, 'A translation pointing at a different file must be filtered out.' );
		$this->assertNotContains( $id, $duplicates, 'The attachment itself must never be in its own duplicate list.' );
	}

	public function test_optimize_propagates_to_wpml_duplicate() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$trid = 992;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->optimizeAttachment( $id );

		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'Main attachment must be optimized.' );

		$duplicate = $this->freshImageModel( $dup_id );
		// getParent() returns the DB value, which may be a numeric string.
		$this->assertEquals( $id, $duplicate->getParent(), 'Optimizing must create the duplicate record linking the translation to its parent.' );
		$this->assertTrue( $duplicate->isOptimized(), 'The WPML duplicate must share the optimized state.' );
	}

	// -------------------------------------------------------------------
	// Later translation does not re-optimize
	// -------------------------------------------------------------------

	/**
	 * Adding a third WPML translation AFTER the image is already optimized
	 * must not enqueue a new API request.
	 *
	 * Optimize, then add another language row; the queue tick must produce
	 * no new API call.
	 *
	 * @return void
	 */
	public function test_later_translation_of_optimized_image_is_not_reoptimized() {
		$id      = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id  = $this->createDuplicateAttachment( $id );
		$dup2_id = $this->createDuplicateAttachment( $id );

		$trid = 993;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->optimizeAttachment( $id );
		$this->assertTrue( $this->freshImageModel( $id )->isOptimized(), 'Original must be optimized before adding a second translation.' );

		$api_call_count_before = count( $this->api->requests );

		// Add a second translation row AFTER the optimize has completed.
		$this->insertTranslationRow( $dup2_id, $trid, 'fr', 'en' );
		update_post_meta( $dup2_id, '_wp_attachment_metadata', wp_get_attachment_metadata( $id ) );

		// A queue tick must not produce any new API request for the already-optimized file.
		$this->purgeQueueTable();
		$this->runQueueUntilEmpty();

		$this->assertCount(
			$api_call_count_before,
			$this->api->requests,
			'Adding a later WPML translation of an already-optimized image must not trigger a new API request.'
		);
	}

	// -------------------------------------------------------------------
	// Bulk deduplicates WPML translations in API call count
	// -------------------------------------------------------------------

	/**
	 * When running bulk optimization with WPML translations present, each
	 * physical file must only be sent to the API once — not once per language.
	 *
	 * Seed two images each with one translation, run bulk, assert API call
	 * count equals the number of unique source files.
	 *
	 * @return void
	 */
	public function test_bulk_deduplicates_wpml_translations_in_api_count() {
		// Image A with German translation.
		$id_a     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_a_id = $this->createDuplicateAttachment( $id_a );
		$this->insertTranslationRow( $id_a, 994, 'en' );
		$this->insertTranslationRow( $dup_a_id, 994, 'de', 'en' );

		// Image B with French translation.
		$id_b     = $this->uploadFixture( 'fixture-small.png' );
		$dup_b_id = $this->createDuplicateAttachment( $id_b );
		$this->insertTranslationRow( $id_b, 995, 'en' );
		$this->insertTranslationRow( $dup_b_id, 995, 'fr', 'en' );

		// Both uploads and the wp_insert_attachment duplicates were already
		// auto-enqueued (autoMediaLibrary); purge so only the explicit adds
		// below determine what gets processed.
		$this->purgeQueueTable();

		// Queue ALL four attachment IDs as if a bulk run enqueued them.
		$queueController = new QueueController();
		foreach ( array( $id_a, $dup_a_id, $id_b, $dup_b_id ) as $attachment_id ) {
			$queueController->addItemToQueue(
				\wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false )
			);
		}

		$this->runQueueUntilEmpty();

		// Only the two source files (A and B) must reach the API; their same-file
		// translation duplicates must be handled as metadata propagation only.
		// The pipeline legitimately POSTs the same urllist to the reducer twice
		// per job (send, then fetch results), so count UNIQUE urllists.
		$urllists = array();
		foreach ( $this->api->requests as $r ) {
			if ( false !== strpos( $r['url'], 'reducer' ) && isset( $r['request']['urllist'] ) ) {
				$urllists[ wp_json_encode( $r['request']['urllist'] ) ] = true;
			}
		}
		$this->assertCount(
			2,
			$urllists,
			'Bulk with WPML translations must call the API only once per unique physical file, not once per language.'
		);

		// Both translations must nonetheless report as optimized.
		$this->assertTrue( $this->freshImageModel( $dup_a_id )->isOptimized(), 'German translation of image A must be marked optimized.' );
		$this->assertTrue( $this->freshImageModel( $dup_b_id )->isOptimized(), 'French translation of image B must be marked optimized.' );
	}

	// -------------------------------------------------------------------
	// Deleting a translation preserves backup until last copy gone
	// -------------------------------------------------------------------

	/**
	 * Deleting a WPML translation attachment must NOT remove the backup when
	 * other language versions of the same physical file still exist.  The
	 * backup may only be deleted when the last remaining attachment sharing
	 * the file is deleted.
	 *
	 * Optimize original + duplicate, delete translation
	 * attachment via onDelete(), assert backup still present; then delete the
	 * original, assert backup gone.
	 *
	 * @return void
	 */
	public function test_deleting_translation_preserves_backup_until_last_copy() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$trid = 996;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->optimizeAttachment( $id );

		$main  = $this->freshImageModel( $id );
		$dupl  = $this->freshImageModel( $dup_id );
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

		// Delete the translation — WPML duplicate present means file must be kept.
		$dupl->onDelete();
		// Remove the icl_translations row to reflect the real-world state after deletion.
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'icl_translations', array( 'element_id' => $dup_id ) );

		// The backup must still exist because the original still holds a reference.
		clearstatcache();
		$this->assertFileExists(
			$backupPath,
			'Backup must be preserved after deleting only the translation while the original still exists.'
		);

		// Now delete the original — no more duplicates, backup must go.
		$main_fresh = $this->freshImageModel( $id );
		$main_fresh->onDelete();

		clearstatcache();
		$this->assertFileDoesNotExist(
			$backupPath,
			'Backup must be removed once the last attachment sharing the physical file is deleted.'
		);
	}

	// -------------------------------------------------------------------
	// AI requestAlt fan-out (addWpmlAiItemsToQueue)
	// -------------------------------------------------------------------

	/** All item_ids currently persisted in the ShortQ queue table. */
	private function queuedItemIds(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'shortpixel_queue';
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT item_id FROM `$table`" ) );
	}

	/**
	 * requestAlt on an image with a WPML translation must enqueue BOTH
	 * language variants: each duplicate is a separate attachment record and
	 * needs its own AI request (QueueController::addWpmlAiItemsToQueue).
	 *
	 * Regression test: addItemToQueue() runs addWpmlAiItemsToQueue() before
	 * the isDuplicateActive() check, so requestAlt actions are exempt from
	 * the duplicate-active check — otherwise the just-queued language
	 * variants make the original count as "duplicate already active in
	 * queue" and its own alt text is never generated.
	 */
	public function test_requestalt_fanout_queues_translation_and_original() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$trid = 997;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		// Drop the auto-enqueued optimize items so only the requestAlt adds count.
		$this->purgeQueueTable();

		( new QueueController() )->addItemToQueue(
			$this->freshImageModel( $id ),
			array( 'action' => 'requestAlt' )
		);

		$queued = $this->queuedItemIds();
		$this->assertContains( $dup_id, $queued, 'The WPML language variant must get its own requestAlt queue item.' );
		$this->assertContains(
			$id,
			$queued,
			'The original must be queued too — not skipped as "duplicate active" right after its own fan-out.'
		);
	}

	// -------------------------------------------------------------------
	// Queue::isDuplicateActive — translation of a queued item is skipped
	// -------------------------------------------------------------------

	/**
	 * Enqueuing a translation while its same-file sibling is already in the
	 * queue must be refused (Queue::isDuplicateActive) — the physical file
	 * would otherwise be optimized twice.
	 */
	public function test_translation_of_queued_item_is_skipped_as_duplicate() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );

		$trid = 998;
		$this->insertTranslationRow( $id, $trid, 'en' );
		$this->insertTranslationRow( $dup_id, $trid, 'de', 'en' );

		$this->purgeQueueTable();

		$queueController = new QueueController();
		$queueController->addItemToQueue( $this->freshImageModel( $id ) );
		$this->assertContains( $id, $this->queuedItemIds(), 'The original must be queued for optimization.' );

		$queueController->addItemToQueue( $this->freshImageModel( $dup_id ) );

		$this->assertNotContains(
			$dup_id,
			$this->queuedItemIds(),
			'A same-file WPML translation must be skipped while its sibling is already queued.'
		);
	}

	// -------------------------------------------------------------------
	// handleReplace end-to-end — WPML language guard
	// -------------------------------------------------------------------

	/**
	 * Regression test, end-to-end leg: handleReplace() runs every result
	 * through WPMLCheckReplace(). With the `language_code` comparison the
	 * same-language post gets the AI alt while the other-language post is
	 * left untouched.
	 *
	 * The in-content alt starts EMPTY: the default 'missing'
	 * mode only fills empty alts, so an empty alt is the shape that gets
	 * written — the WPML language discrimination stays the point under test.
	 */
	public function test_handlereplace_skips_other_language_posts_end_to_end() {
		$id         = $this->uploadFixture( 'fixture-small.jpg' );
		$imageModel = $this->freshImageModel( $id );

		$img_tag    = '<img src="' . esc_url( wp_get_attachment_url( $id ) ) . '" alt="" />';
		$post_same  = self::factory()->post->create( array( 'post_content' => $img_tag ) );
		$post_other = self::factory()->post->create( array( 'post_content' => $img_tag ) );

		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function ( $details, $lookup_id ) use ( $post_other ) {
				// Realistic WPML payload: language_code + locale, no 'code' key.
				return ( (int) $lookup_id === (int) $post_other )
					? array( 'language_code' => 'de', 'locale' => 'de_DE' )
					: array( 'language_code' => 'en', 'locale' => 'en_US' );
			},
			10,
			2
		);

		$qItem   = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		$results = array(
			array( 'post_id' => $post_same, 'content' => get_post( $post_same )->post_content ),
			array( 'post_id' => $post_other, 'content' => get_post( $post_other )->post_content ),
		);
		$args    = array(
			'aiData'     => array( 'alt' => 'AI pinned alt', 'caption' => 0 ),
			'qItem'      => $qItem,
			// handleReplace() reads args['prevAiData'] (undo exact-match
			// support); production callers always pass it.
			'prevAiData' => array(),
		);

		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace( $results, $args );

		// Replacer's Updater writes post_content via direct SQL — invalidate
		// the WP post cache before re-reading.
		clean_post_cache( $post_same );
		clean_post_cache( $post_other );

		$this->assertStringContainsString(
			'AI pinned alt',
			get_post( $post_same )->post_content,
			'The same-language post must receive the AI alt text.'
		);
		$this->assertStringNotContainsString(
			'AI pinned alt',
			get_post( $post_other )->post_content,
			'The different-language post must NOT be replaced (comparing undefined \'code\' keys would replace it anyway).'
		);
	}

	// -------------------------------------------------------------------
	// WPML same-file translation filename rename
	// -------------------------------------------------------------------

	/**
	 * REGRESSION — same-file translations follow a rename, exactly once.
	 *
	 * replaceFiles() collects the WPML siblings before anything is touched:
	 * once the renamed item's _wp_attached_file is rewritten, the same-file
	 * check in getWPMLDuplicates() no longer matches and translations would
	 * stay on the old, deleted file.
	 *
	 * On a real site WPML's own sync hook may already have copied
	 * the new _wp_attached_file to the translations while the original was
	 * updated, before replaceFiles() reaches its duplicate pass; a plain
	 * str_replace() would then rename a second time whenever the new base
	 * contains the old one. The target name here contains the source
	 * basename so that case is visible.
	 */
	public function test_rename_does_not_duplicate_basename_on_wpml_same_file_translation() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9101, 'en' );
		$this->insertTranslationRow( $dup_id, 9101, 'de', 'en' );
		$this->purgeQueueTable();

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$this->assertSame( $old_file, get_attached_file( $dup_id ), 'Sentinel: original and translation must share the physical file.' );
		$this->assertContains(
			$dup_id,
			array_map( 'intval', $this->freshImageModel( $id )->getWPMLDuplicates() ),
			'Sentinel: the sibling must be listed as a WPML duplicate — the fix has the data it needs.'
		);

		// Real-site ordering: WPML syncs the translation's _wp_attached_file
		// the moment the original's is updated, before SPIO's duplicate pass.
		$this->addWpmlSyncAttachedFileHook();

		$new_base = $old_base . '-wpml-rename-' . wp_generate_password( 6, false );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sanity: the rename must report success.' );

		$this->assertStringContainsString( $new_base, get_attached_file( $id ), 'Sanity: original _wp_attached_file must carry the new base.' );
		$this->assertFileDoesNotExist( $old_file, 'Sanity: the shared physical file was moved to the new name.' );

		clean_post_cache( $dup_id );
		$dup_meta          = wp_get_attachment_metadata( $dup_id );
		$expected_filename = $new_base . '.' . pathinfo( $old_file, PATHINFO_EXTENSION );
		// The new base CONTAINS the old one on purpose, so a second
		// str_replace() pass would yield "<old>-wpml-rename-x-wpml-rename-x".
		$this->assertSame(
			$expected_filename,
			basename( (string) ( $dup_meta['file'] ?? '' ) ),
			'REGRESSION: the WPML translation metadata[file] must carry the new basename exactly once.'
		);
		$dup_attached = get_attached_file( $dup_id );
		$this->assertSame(
			$expected_filename,
			basename( $dup_attached ),
			'REGRESSION: the WPML translation _wp_attached_file must carry the new basename exactly once.'
		);
		$this->assertSame(
			get_attached_file( $id ),
			$dup_attached,
			'REGRESSION: the WPML translation _wp_attached_file must point at the renamed file, same as the original.'
		);
		$this->assertFileExists( $dup_attached, 'REGRESSION: the translation references a file that exists.' );
	}

	/**
	 * REGRESSION — the real-site scenario: rename started FROM THE
	 * TRANSLATION. The original must follow, one file backs both.
	 */
	public function test_rename_from_the_translation_updates_the_original() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9104, 'en' );
		$this->insertTranslationRow( $dup_id, 9104, 'de', 'en' );
		$this->purgeQueueTable();

		$old_file = get_attached_file( $id );
		$old_base = pathinfo( $old_file, PATHINFO_FILENAME );
		$this->assertSame( $old_file, get_attached_file( $dup_id ), 'Sentinel: original and translation must share the physical file.' );
		$this->assertContains(
			$id,
			array_map( 'intval', $this->freshImageModel( $dup_id )->getWPMLDuplicates() ),
			'Sentinel: seen from the translation, the original must be listed as a WPML duplicate.'
		);

		$new_base = 'wpml-from-de-' . wp_generate_password( 6, false );
		$this->assertTrue( $this->renameAttachment( $dup_id, $new_base ), 'Sanity: the rename must report success.' );
		$this->assertStringContainsString( $new_base, get_attached_file( $dup_id ), 'Sanity: the translation carries the new base.' );
		$this->assertFileDoesNotExist( $old_file, 'Sanity: the shared physical file was moved.' );

		clean_post_cache( $id );
		$orig_meta = wp_get_attachment_metadata( $id );
		$this->assertStringContainsString(
			$new_base,
			(string) ( $orig_meta['file'] ?? '' ),
			'REGRESSION: renaming from the translation must update the ORIGINAL metadata[file] too.'
		);
		$this->assertStringNotContainsString( $old_base, (string) ( $orig_meta['file'] ?? '' ) );
		$this->assertSame(
			get_attached_file( $dup_id ),
			get_attached_file( $id ),
			'REGRESSION: renaming from the translation must update the ORIGINAL _wp_attached_file too.'
		);
		$this->assertFileExists( get_attached_file( $id ) );
	}

	// -------------------------------------------------------------------
	// Per-language AI renames — one file on disk per image
	// -------------------------------------------------------------------

	/**
	 * WPML core's own deletion guard (class-wpml-attachment-action.php:114-128,
	 * SitePress 4.9.6), replicated verbatim.
	 *
	 * It is NOT registered in this test install (WPML's attachment action is
	 * only booted on a configured site, and has_filter('wp_delete_file') is
	 * false here), so the one-file-for-all-languages test installs it
	 * explicitly — without it the defect cannot be observed at all. Note get_file_name() strips any -WxH size
	 * suffix before looking the file up, so ONE sibling still holding the
	 * full-size name protects every thumbnail of that image too.
	 */
	private function addWpmlDeleteFileGuard(): void {
		add_filter(
			'wp_delete_file',
			function ( $file ) {
				global $wpdb;
				if ( ! $file ) {
					return $file;
				}
				$upload    = wp_upload_dir();
				$ext       = pathinfo( $file, PATHINFO_EXTENSION );
				$full_size = preg_replace( '/(-\d+x\d+)?\.' . $ext . '$/', ".$ext", $file );
				$relative  = ltrim( str_replace( $upload['basedir'], '', $full_size ), '/' );
				$has_owner = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key = '_wp_attached_file'",
						$relative
					)
				);
				return $has_owner ? null : $file;
			}
		);
	}

	/**
	 * REGRESSION — the AI rename runs for the MAIN language only.
	 *
	 * QueueController queues the AI job once per WPML language and each
	 * answer carries its own translated filebase; renaming the ONE shared
	 * file once per language would leave N copies on disk (WPML's delete
	 * guard keeps each old set alive). HandleSuccess() therefore gates the AI
	 * rename on getWPMLDuplicates(true): when the image has WPML translation
	 * rows, only the item whose row has no source_language_code (the main
	 * language) renames; translations log
	 * "Replace files cancelled due to duplicate situation".
	 *
	 * This covers the data that gate reads. The HandleSuccess() branch itself
	 * needs a full AI result and is not driven here.
	 */
	public function test_getWPMLDuplicates_alldata_marks_the_main_language() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9103, 'en' );
		$this->insertTranslationRow( $dup_id, 9103, 'de', 'en' );

		foreach ( array( $id, $dup_id ) as $asked ) {
			$all = $this->freshImageModel( $asked )->getWPMLDuplicates( true );

			// Includes the item ITSELF (the gate looks itself up by id)...
			$this->assertArrayHasKey( $id, $all, 'The main-language item must be listed (asked for ' . $asked . ').' );
			$this->assertArrayHasKey( $dup_id, $all, 'The translation must be listed (asked for ' . $asked . ').' );
			// ...and flags which one may rename.
			$this->assertTrue( $all[ $id ]['is_main_language'], 'REGRESSION: the source-language item is the main language.' );
			$this->assertFalse( $all[ $dup_id ]['is_main_language'], 'REGRESSION: the translation is not — its AI rename is skipped.' );
			$this->assertSame( 'de', $all[ $dup_id ]['language_code'] );
		}

		// Contract: the default call still returns sibling ids only, never self.
		$this->assertSame( array( $dup_id ), array_map( 'intval', array_values( $this->freshImageModel( $id )->getWPMLDuplicates() ) ) );
	}

	/**
	 * CONTRACT — "main language" means the ORIGINAL of the image's
	 * translation group, not the site's default language (an image uploaded
	 * while the admin works in Romanian becomes the main entry for THAT
	 * image).
	 *
	 * WPML rows for such an image: the Romanian attachment has no
	 * source_language_code (it is the original), the English one has
	 * source_language_code='ro'. getWPMLDuplicates(true) keys
	 * is_main_language on source_language_code IS NULL, so the Romanian item
	 * is the one whose AI filename is applied (HandleSuccess gate) — the
	 * same item WPML's own _wp_attached_file sync propagates from. If the
	 * product wants filenames in the SITE default language instead, this is
	 * the test to change.
	 */
	public function test_wpml_original_uploaded_in_a_non_default_language_is_the_main_language() {
		$ro_id = $this->uploadFixture( 'fixture-small.jpg' );
		$en_id = $this->createDuplicateAttachment( $ro_id );
		$this->insertTranslationRow( $ro_id, 9105, 'ro' );
		$this->insertTranslationRow( $en_id, 9105, 'en', 'ro' );

		foreach ( array( $ro_id, $en_id ) as $asked ) {
			$all = $this->freshImageModel( $asked )->getWPMLDuplicates( true );
			$this->assertTrue( $all[ $ro_id ]['is_main_language'], 'The Romanian ORIGINAL is the main language (asked for ' . $asked . ').' );
			$this->assertSame( 'ro', $all[ $ro_id ]['language_code'] );
			$this->assertFalse( $all[ $en_id ]['is_main_language'], 'The English TRANSLATION is not, even if English is the site default.' );
		}
	}

	/**
	 * REGRESSION — after the (single) main-language rename, ONE file backs
	 * every language.
	 *
	 * The translations follow the rename, so WPML's delete_file_filter
	 * (class-wpml-attachment-action.php:114-128, replicated in
	 * addWpmlDeleteFileGuard()) lets the old file go. If they still
	 * referenced the old filename, the guard would refuse to delete it — two
	 * copies on disk, translations showing the OLD file.
	 */
	public function test_main_language_rename_leaves_one_file_for_all_languages() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9102, 'en' );
		$this->insertTranslationRow( $dup_id, 9102, 'de', 'en' );
		$this->purgeQueueTable();

		$old_file = get_attached_file( $id );
		$dir      = dirname( $old_file );
		$en_base  = 'wpml-en-' . wp_generate_password( 6, false );

		$this->assertSame( $old_file, get_attached_file( $dup_id ), 'Sentinel: both languages share one physical file.' );

		$this->addWpmlDeleteFileGuard();
		// SENTINEL: the guard that makes this bug observable is really armed.
		$this->assertNull(
			apply_filters( 'wp_delete_file', $old_file ),
			'Sentinel: WPML\'s delete guard must refuse the shared file while an attachment still references it.'
		);

		// The main language renames the shared file (the only rename the AI
		// performs).
		$this->assertTrue( $this->renameAttachment( $id, $en_base ), 'Sanity: the main-language rename must report success.' );

		$ext = pathinfo( $old_file, PATHINFO_EXTENSION );
		$this->assertFileExists( trailingslashit( $dir ) . $en_base . '.' . $ext, 'Sanity: the renamed file exists.' );

		$this->assertFileDoesNotExist(
			$old_file,
			'REGRESSION: the original file must be gone after the main-language rename — one copy per image.'
		);
		$this->assertSame(
			get_attached_file( $id ),
			get_attached_file( $dup_id ),
			'REGRESSION: the translation must reference the renamed file.'
		);
	}

	/**
	 * REGRESSION — a real-site case: a BIG image whose
	 * upload name ends in "-scaled", uploaded in a secondary language
	 * (Romanian = its original), renamed from ANOTHER secondary language
	 * (Spanish) by prefixing "rename-".
	 *
	 * Two WordPress core behaviours explain the resulting names; neither is a
	 * SPIO bug:
	 *   1. wp_unique_filename() ALWAYS appends "-1" to a name ending in
	 *      -scaled / -rotated / -WxH (reserved for generated sub-sizes), even
	 *      with no clash: "…-047-scaled.jpg" is stored as "…-047-scaled-1.jpg".
	 *   2. Images above big_image_size_threshold (2560px) keep the upload as
	 *      metadata['original_image'] and serve "<name>-scaled.jpg", so the
	 *      served file is "…-047-scaled-1-scaled.jpg" BEFORE any rename.
	 * SPIO's Change Filename field shows the ORIGINAL's name for scaled
	 * images (getAltData()), so prefixing it yields the new original
	 * "rename-…-scaled-1.jpg" and the served "rename-…-scaled-1-scaled.jpg" —
	 * the correct result, identical in all three languages, one set on disk.
	 */
	public function test_big_scaled_named_image_renamed_from_a_secondary_translation_stays_consistent() {
		// Unique per run: the uploads dir persists between runs, and a leftover
		// renamed set would (correctly) trip the target-conflict guard.
		$upload_name = 'gucci-' . strtolower( wp_generate_password( 5, false, false ) ) . '-pre-fall-2025-collection-the-impression-047-scaled';
		$tmp         = trailingslashit( get_temp_dir() ) . $upload_name . '.jpg';
		copy( $this->fixturePath( 'fixture-large.jpg' ), $tmp ); // 3200px wide: above the 2560px threshold.
		$ro = $this->uploadFile( $tmp );
		$es = $this->createDuplicateAttachment( $ro );
		$en = $this->createDuplicateAttachment( $ro );
		$this->insertTranslationRow( $ro, 9200, 'ro' );        // uploaded in Romanian → the original
		$this->insertTranslationRow( $es, 9200, 'es', 'ro' );
		$this->insertTranslationRow( $en, 9200, 'en', 'ro' );
		$this->purgeQueueTable();
		$this->addWpmlSyncAttachedFileHook(); // real-site ordering (it does not fire here: Spanish is not the original)

		$uploads = wp_upload_dir();
		$dir     = dirname( (string) get_post_meta( $ro, '_wp_attached_file', true ) );
		$abs     = function ( $name ) use ( $uploads, $dir ) {
			return $uploads['basedir'] . '/' . $dir . '/' . $name;
		};
		$stored = $upload_name . '-1';

		// SENTINELS — WordPress core, before SPIO does anything.
		$meta = wp_get_attachment_metadata( $ro );
		$this->assertSame( $stored . '.jpg', $meta['original_image'] ?? null, 'Sentinel: WP appended "-1" to a name ending in -scaled and kept it as the original.' );
		$this->assertSame( $stored . '-scaled.jpg', basename( get_attached_file( $ro ) ), 'Sentinel: WP serves the big-image "-scaled" copy — the double "-scaled" exists BEFORE the rename.' );
		$es_model = $this->freshImageModel( $es );
		$this->assertTrue( $es_model->isScaled() );
		$this->assertSame( $stored . '.jpg', $es_model->getOriginalFile()->getFileName(), 'Sentinel: the Change Filename field shows the ORIGINAL name.' );

		// The user prefixes the field value on the SPANISH translation.
		$new_base = 'rename-' . $stored;
		$this->assertTrue( $this->renameAttachment( $es, $new_base ), 'The rename must report success.' );

		foreach ( array( 'ro' => $ro, 'es' => $es, 'en' => $en ) as $lang => $id ) {
			clean_post_cache( $id );
			$m = wp_get_attachment_metadata( $id );
			$this->assertSame( $dir . '/' . $new_base . '-scaled.jpg', (string) get_post_meta( $id, '_wp_attached_file', true ), "[$lang] _wp_attached_file = the renamed served file." );
			$this->assertSame( $dir . '/' . $new_base . '-scaled.jpg', $m['file'] ?? null, "[$lang] metadata[file] follows." );
			$this->assertSame( $new_base . '.jpg', $m['original_image'] ?? null, "[$lang] metadata[original_image] follows." );
		}

		$this->assertFileExists( $abs( $new_base . '-scaled.jpg' ) );
		$this->assertFileExists( $abs( $new_base . '.jpg' ) );
		$this->assertFileDoesNotExist( $abs( $stored . '-scaled.jpg' ), 'One set on disk: the old served file is gone.' );
		$this->assertFileDoesNotExist( $abs( $stored . '.jpg' ), 'One set on disk: the old original is gone.' );
		$this->assertSame( $new_base . '.jpg', $this->freshImageModel( $es )->getOriginalFile()->getFileName(), 'The field now shows the renamed original.' );
	}

	// -------------------------------------------------------------------
	// AI rename usage check with translations (mocked AI API)
	// -------------------------------------------------------------------

	/**
	 * AI settings for a rename run through the real queue: filename on, the
	 * mock API answers with $name as the generated filename.
	 */
	private function enableAiRename( string $name ): void {
		$settings                  = \wpSPIO()->settings();
		$settings->enable_ai       = 1;
		$settings->ai_gen_alt      = 1;
		$settings->ai_gen_filename = 1;
		$this->api->aiFields['generated_file_name'] = $name;

		// Fresh AI state: no stored AI rows / cached models / token.
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );
		$prop = ( new ReflectionClass( \ShortPixel\Model\AiDataModel::class ) )->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
		delete_transient( 'spio_ai_jwt_token' );
	}

	/** A published post in $lang (WPML post row in trid $trid) whose content is $content. */
	private function createPostInLanguage( string $content, int $trid, string $lang, ?string $source = null ): int {
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => $content ) );
		$wpdb->insert(
			$wpdb->prefix . 'icl_translations',
			array(
				'element_type'         => 'post_post',
				'element_id'           => $post_id,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $source,
			)
		);
		return $post_id;
	}

	private function imgTag( int $attachment_id ): string {
		return '<img src="' . esc_url( wp_get_attachment_url( $attachment_id ) ) . '" alt="" />';
	}

	/** Run the AI job for $start_id (a non-upload run: bulk / Media Library) until the queue is empty. */
	private function runAiFor( int $start_id ): void {
		$this->purgeQueueTable();
		( new QueueController() )->addItemToQueue( $this->freshImageModel( $start_id ), array( 'action' => 'requestAlt' ) );
		$this->runQueueUntilEmpty();
	}

	/**
	 * An EN image with a DE same-file translation, used ONLY in the DE
	 * translation of a post (the EN post does not show it). The AI job is a
	 * per-language fan-out; only the main language (EN) may rename, and its
	 * usage check must still find the DE post: the file keeps its name for
	 * every language.
	 */
	public function test_ai_rename_keeps_the_name_when_only_a_translated_post_uses_the_image() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9201, 'en' );
		$this->insertTranslationRow( $dup_id, 9201, 'de', 'en' );
		$old_file = get_attached_file( $id );

		$this->createPostInLanguage( '<p>No image in the English version.</p>', 9202, 'en' );
		$de_post = $this->createPostInLanguage( $this->imgTag( $dup_id ), 9202, 'de', 'en' );
		// SENTINEL: the DE post really shows the shared file.
		$this->assertStringContainsString( basename( $old_file ), get_post( $de_post )->post_content );

		$this->enableAiRename( 'wpml-used-de-' . strtolower( wp_generate_password( 4, false, false ) ) );
		$this->runAiFor( $id );

		// SENTINEL: the AI run completed for the main language.
		$this->assertNotEmpty( get_post_meta( $id, '_wp_attachment_image_alt', true ), 'Sentinel: the AI run completed.' );

		foreach ( array( 'en' => $id, 'de' => $dup_id ) as $lang => $att ) {
			clean_post_cache( $att );
			$this->assertSame( $old_file, get_attached_file( $att ), "[$lang] An image used in a translated post keeps its name." );
		}
		$this->assertFileExists( $old_file, 'The shared file was not moved.' );
	}

	/**
	 * Same as above, but the AI job is started from the TRANSLATION (the user
	 * works in the DE Media Library). The fan-out still reaches the main
	 * language, which must not rename a file the DE post uses.
	 */
	public function test_ai_rename_from_the_translation_keeps_the_name_when_a_translated_post_uses_the_image() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9203, 'en' );
		$this->insertTranslationRow( $dup_id, 9203, 'de', 'en' );
		$old_file = get_attached_file( $id );

		$this->createPostInLanguage( '<p>No image in the English version.</p>', 9204, 'en' );
		$this->createPostInLanguage( $this->imgTag( $dup_id ), 9204, 'de', 'en' );

		$this->enableAiRename( 'wpml-from-de-' . strtolower( wp_generate_password( 4, false, false ) ) );
		$this->runAiFor( $dup_id );

		$this->assertNotEmpty( get_post_meta( $dup_id, '_wp_attachment_image_alt', true ), 'Sentinel: the AI run completed for the translation.' );
		foreach ( array( 'en' => $id, 'de' => $dup_id ) as $lang => $att ) {
			clean_post_cache( $att );
			$this->assertSame( $old_file, get_attached_file( $att ), "[$lang] The image keeps its name." );
		}
		$this->assertFileExists( $old_file );
	}

	/**
	 * The image's ORIGINAL is in a non-default language (RO), the post that
	 * uses it only exists in the EN translation. The RO item is the one that
	 * may rename; its usage check must see the EN post.
	 */
	public function test_ai_rename_keeps_the_name_when_the_image_is_used_only_in_another_language_than_its_original() {
		$ro_id = $this->uploadFixture( 'fixture-small.jpg' );
		$en_id = $this->createDuplicateAttachment( $ro_id );
		$this->insertTranslationRow( $ro_id, 9205, 'ro' );
		$this->insertTranslationRow( $en_id, 9205, 'en', 'ro' );
		$old_file = get_attached_file( $ro_id );

		$this->createPostInLanguage( '<p>Fără imagine.</p>', 9206, 'ro' );
		$this->createPostInLanguage( $this->imgTag( $en_id ), 9206, 'en', 'ro' );

		$this->enableAiRename( 'wpml-used-en-' . strtolower( wp_generate_password( 4, false, false ) ) );
		$this->runAiFor( $en_id );

		$this->assertNotEmpty( get_post_meta( $en_id, '_wp_attachment_image_alt', true ), 'Sentinel: the AI run completed.' );
		foreach ( array( 'ro' => $ro_id, 'en' => $en_id ) as $lang => $att ) {
			clean_post_cache( $att );
			$this->assertSame( $old_file, get_attached_file( $att ), "[$lang] The image keeps its name." );
		}
		$this->assertFileExists( $old_file );
	}

	/**
	 * Counterpart: no post uses the image in any language — the main language
	 * renames it once, and every translation follows to the one new file.
	 * Also proves the translation's own attachment metadata does not count as
	 * a use (the usage probe excludes the item and its WPML siblings).
	 */
	public function test_ai_rename_of_an_unused_translated_image_renames_it_once_for_all_languages() {
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9207, 'en' );
		$this->insertTranslationRow( $dup_id, 9207, 'de', 'en' );
		$old_file = get_attached_file( $id );
		// The sibling's own postmeta holds the full URL (the shape some
		// multilingual setups leave) — it must not count as a use.
		add_post_meta( $dup_id, '_spio_test_full_url', wp_get_attachment_url( $dup_id ) );

		$name = 'wpml-unused-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->enableAiRename( $name );
		$this->runAiFor( $id );

		clean_post_cache( $id );
		clean_post_cache( $dup_id );
		$this->assertStringContainsString( $name, basename( get_attached_file( $id ) ), 'The main language renamed the unused image.' );
		$this->assertSame( get_attached_file( $id ), get_attached_file( $dup_id ), 'The translation follows the rename.' );
		$this->assertFileExists( get_attached_file( $id ) );
		$this->assertFileDoesNotExist( $old_file, 'One file on disk: the old one is gone.' );
	}

	/**
	 * Upload flag with WPML: WPML creates the translations of a new upload
	 * while it is being inserted (add_attachment), so the AI job fans out to
	 * every language at upload time. The main item must carry the "recent
	 * upload" flag through the queue: a new image is renamed even when a
	 * translated post already uses it by the time the AI answers, and every
	 * language follows to the same file.
	 */
	public function test_new_upload_with_wpml_translations_is_renamed_for_all_languages() {
		$settings                   = \wpSPIO()->settings();
		$settings->autoMediaLibrary = 0;
		$settings->autoAI           = 1;
		$name = 'wpml-upload-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->enableAiRename( $name );

		$admin  = \ShortPixel\Controller\AdminController::getInstance();
		$recent = ( new ReflectionClass( \ShortPixel\Controller\AdminController::class ) )->getProperty( 'recentUploads' );
		$recent->setAccessible( true );
		$recent->setValue( null, array() );

		$saved = array();
		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			$saved[ $hook_name ] = clone $hook;
		}
		$dup_id = 0;
		try {
			// The hooks an AI-only site registers at boot.
			remove_all_actions( 'add_attachment' );
			remove_all_filters( 'wp_generate_attachment_metadata' );
			add_action( 'add_attachment', array( $admin, 'addAttachmentHook' ) );
			add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleAiImageUploadHook' ), 4, 2 );

			// WPML's media duplication, replicated: on insert of the original,
			// create the DE translation pointing at the same file.
			add_action(
				'add_attachment',
				function ( $post_id ) use ( &$dup_id ) {
					if ( 0 !== $dup_id ) {
						return; // the duplicate's own insert
					}
					$dup_id = -1;
					$dup_id = $this->createDuplicateAttachment( $post_id );
					$this->insertTranslationRow( $post_id, 9208, 'en' );
					$this->insertTranslationRow( $dup_id, 9208, 'de', 'en' );
				},
				20
			);

			$id = $this->uploadFixture( 'fixture-small.jpg' );
		} finally {
			$GLOBALS['wp_filter'] = $saved;
		}
		$old_file = get_attached_file( $id );

		// SENTINELS: WPML duplicated the upload, and the upload queued AI for both languages.
		$this->assertGreaterThan( 0, $dup_id, 'Sentinel: the translation was created during the upload.' );
		$this->assertContains( $id, $this->queuedItemIds(), 'Sentinel: the original is queued for AI.' );
		$this->assertContains( $dup_id, $this->queuedItemIds(), 'Sentinel: the translation is queued for AI (fan-out).' );

		// The DE post uses the image before the AI answers.
		$this->createPostInLanguage( $this->imgTag( $dup_id ), 9209, 'de' );

		$this->runQueueUntilEmpty();

		clean_post_cache( $id );
		clean_post_cache( $dup_id );
		$this->assertStringContainsString( $name, basename( get_attached_file( $id ) ), 'The new upload is renamed although a post already uses it.' );
		$this->assertSame( get_attached_file( $id ), get_attached_file( $dup_id ), 'The translation follows the rename.' );
		$this->assertFileDoesNotExist( $old_file, 'One file on disk.' );
	}

	// -------------------------------------------------------------------
	// Real-site case: image uploaded into a RO draft,
	// EN + ES translations of the draft reuse it, AI run from the EN
	// Media Library (bulk action "Generate image SEO data").
	// -------------------------------------------------------------------

	/**
	 * Build the site: RO is the image's ORIGINAL (uploaded into
	 * the RO draft), EN and ES are WPML media copies of the same file. Each
	 * language has a DRAFT post showing the image with an alt the user typed.
	 * wpml_post_language_details answers from this map (the test install's
	 * WPML has no language setup of its own).
	 *
	 * @return array{att: array<string,int>, post: array<string,int>, file: string}
	 */
	private function buildRoOriginalWithEnEsDrafts(): array {
		$ro = $this->uploadFixture( 'fixture-small.jpg' );
		$en = $this->createDuplicateAttachment( $ro );
		$es = $this->createDuplicateAttachment( $ro );
		$this->insertTranslationRow( $ro, 9301, 'ro' );
		$this->insertTranslationRow( $en, 9301, 'en', 'ro' );
		$this->insertTranslationRow( $es, 9301, 'es', 'ro' );

		$posts = array();
		foreach ( array( 'ro' => $ro, 'en' => $en, 'es' => $es ) as $lang => $att ) {
			$posts[ $lang ] = self::factory()->post->create(
				array(
					'post_status'  => 'draft',
					'post_content' => '<!-- wp:image {"id":' . $att . '} --><figure class="wp-block-image"><img src="' . esc_url( wp_get_attachment_url( $att ) ) . '" alt="typed ' . $lang . ' alt" class="wp-image-' . $att . '"/></figure><!-- /wp:image -->',
				)
			);
		}

		$languages = array(
			$ro => 'ro', $en => 'en', $es => 'es',
			$posts['ro'] => 'ro', $posts['en'] => 'en', $posts['es'] => 'es',
		);
		remove_all_filters( 'wpml_post_language_details' );
		add_filter(
			'wpml_post_language_details',
			function ( $details, $lookup_id ) use ( $languages ) {
				$lang = $languages[ (int) $lookup_id ] ?? null;
				return $lang ? array( 'language_code' => $lang, 'locale' => $lang ) : $details;
			},
			10,
			2
		);

		return array(
			'att'  => array( 'ro' => $ro, 'en' => $en, 'es' => $es ),
			'post' => $posts,
			'file' => get_attached_file( $ro ),
		);
	}

	/** Run the AI job the way the Media Library bulk action does (AjaxController::requestAlt → addItemToQueue). */
	private function runAiFromMediaLibrary( int $attachment_id ): void {
		$settings            = \wpSPIO()->settings();
		$settings->enable_ai = 1;
		$settings->ai_gen_alt = 1;
		$this->enableAiRename( 'unused' );
		$this->api->aiFields = array(); // no filename: this case is about the text
		$this->purgeQueueTable();
		( new QueueController() )->addItemToQueue( $this->freshImageModel( $attachment_id ), array( 'action' => 'requestAlt' ) );
		$this->runQueueUntilEmpty();
	}

	/**
	 * Every language gets its own AI data: the bulk action on the EN copy
	 * fans out to RO (the original) and ES too.
	 */
	public function test_ai_from_a_translation_generates_data_for_every_language_including_the_original() {
		$site = $this->buildRoOriginalWithEnEsDrafts();

		$this->runAiFromMediaLibrary( $site['att']['en'] );

		foreach ( $site['att'] as $lang => $att ) {
			$this->assertSame( 'A mock ai alt text.', get_post_meta( $att, '_wp_attachment_image_alt', true ), "[$lang] The attachment got AI alt text." );
		}
	}

	/**
	 * Default "Alt text in existing posts and pages" = "Add alt text only where
	 * it's missing": the alts the user typed in the drafts are kept, in every
	 * language. (Drafts ARE searched — the default statuses include draft.)
	 */
	public function test_missing_mode_keeps_the_alts_typed_in_translated_drafts() {
		\wpSPIO()->settings()->ai_content_replace = 'missing';
		$site = $this->buildRoOriginalWithEnEsDrafts();

		$this->runAiFromMediaLibrary( $site['att']['en'] );

		foreach ( $site['post'] as $lang => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;
			$this->assertStringContainsString( 'alt="typed ' . $lang . ' alt"', $content, "[$lang] The typed alt is kept in 'missing' mode." );
			$this->assertStringNotContainsString( 'A mock ai alt text.', $content, "[$lang] No AI alt in 'missing' mode." );
		}
	}

	/**
	 * "Replace existing alt text": every language's DRAFT gets the AI alt of
	 * ITS OWN language's attachment (each item only writes into posts of its
	 * own language).
	 */
	public function test_overwrite_mode_replaces_the_alt_in_every_translated_draft() {
		\wpSPIO()->settings()->ai_content_replace = 'overwrite';
		$site = $this->buildRoOriginalWithEnEsDrafts();

		$this->runAiFromMediaLibrary( $site['att']['en'] );

		foreach ( $site['post'] as $lang => $post_id ) {
			clean_post_cache( $post_id );
			$this->assertSame( 'draft', get_post_status( $post_id ), "Sentinel: the $lang post is a draft." );
			$this->assertStringContainsString( 'alt="A mock ai alt text."', get_post( $post_id )->post_content, "[$lang] The draft got the AI alt." );
		}
	}

	/**
	 * Same site with AI filenames ON, AI started from the EN copy. Only the
	 * image's ORIGINAL-language item (RO here) may rename the shared file;
	 * the EN and ES items skip the rename. Draft posts do not count as "used"
	 * (the usage check only counts published content), so the RO item renames
	 * the file once, every language follows, and the drafts are rewritten to
	 * the new URL.
	 */
	public function test_ai_filename_from_a_translation_renames_via_the_original_for_all_languages_and_drafts() {
		$site = $this->buildRoOriginalWithEnEsDrafts();
		$name = 'wpml-drafts-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->enableAiRename( $name );

		$this->purgeQueueTable();
		( new QueueController() )->addItemToQueue( $this->freshImageModel( $site['att']['en'] ), array( 'action' => 'requestAlt' ) );
		$this->runQueueUntilEmpty();

		// SENTINEL: the RO original was processed (only it may rename).
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $site['att']['ro'], '_wp_attachment_image_alt', true ), 'Sentinel: the RO original got AI data.' );

		$new_file = get_attached_file( $site['att']['ro'] );
		$this->assertStringContainsString( $name, basename( $new_file ), 'The shared file got the AI filename.' );
		foreach ( $site['att'] as $lang => $att ) {
			clean_post_cache( $att );
			$this->assertSame( $new_file, get_attached_file( $att ), "[$lang] The attachment follows the rename." );
		}
		$this->assertFileDoesNotExist( $site['file'], 'One file on disk.' );
		foreach ( $site['post'] as $lang => $post_id ) {
			clean_post_cache( $post_id );
			$this->assertStringContainsString( basename( $new_file ), get_post( $post_id )->post_content, "[$lang] The draft points at the renamed file." );
		}
	}

	/**
	 * When the ORIGINAL-language item is not processed (only EN and ES got AI
	 * data), nothing renames the file: the translations never rename the
	 * shared file themselves (RO gets no AI data → all three keep the old
	 * name).
	 */
	public function test_without_the_original_language_item_the_translations_never_rename_the_file() {
		$site = $this->buildRoOriginalWithEnEsDrafts();
		$this->enableAiRename( 'wpml-no-original-' . strtolower( wp_generate_password( 4, false, false ) ) );

		// Queue ONLY the translations (no fan-out): the RO original is left out.
		$this->purgeQueueTable();
		foreach ( array( 'en', 'es' ) as $lang ) {
			$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $this->freshImageModel( $site['att'][ $lang ] ) );
			$qItem->requestAltAction( array() );
			( new QueueController() )->getQueue( 'media' )->addQueueItem( $qItem );
		}
		$this->runQueueUntilEmpty();

		// SENTINELS: EN and ES were processed, RO was not.
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $site['att']['en'], '_wp_attachment_image_alt', true ), 'Sentinel: EN got AI data.' );
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $site['att']['es'], '_wp_attachment_image_alt', true ), 'Sentinel: ES got AI data.' );
		$this->assertEmpty( get_post_meta( $site['att']['ro'], '_wp_attachment_image_alt', true ), 'Sentinel: RO got no AI data.' );

		foreach ( $site['att'] as $lang => $att ) {
			clean_post_cache( $att );
			$this->assertSame( $site['file'], get_attached_file( $att ), "[$lang] The file keeps its name." );
		}
		$this->assertFileExists( $site['file'] );
	}

	/**
	 * A BIG camera image ("IMG_1234.jpg",
	 * above the 2560px threshold, so WordPress serves "IMG_1234-scaled.jpg"),
	 * uploaded into a RO draft; EN and ES drafts reuse it. Image blocks show
	 * the "large" size (the block editor default) and the full "-scaled" file.
	 * The AI rename runs on the RO original: every draft must point at the
	 * renamed files afterwards.
	 */
	public function test_ai_rename_of_a_big_scaled_image_rewrites_every_translated_draft() {
		$tmp = trailingslashit( get_temp_dir() ) . 'IMG_' . wp_rand( 1000, 9999 ) . strtolower( wp_generate_password( 3, false, false ) ) . '.jpg';
		copy( $this->fixturePath( 'fixture-large.jpg' ), $tmp ); // 3200px wide
		$ro = $this->uploadFile( $tmp );
		$en = $this->createDuplicateAttachment( $ro );
		$es = $this->createDuplicateAttachment( $ro );
		$this->insertTranslationRow( $ro, 9401, 'ro' );
		$this->insertTranslationRow( $en, 9401, 'en', 'ro' );
		$this->insertTranslationRow( $es, 9401, 'es', 'ro' );

		$this->assertStringEndsWith( '-scaled.jpg', get_attached_file( $ro ), 'Sentinel: WordPress serves the -scaled file.' );
		$large = wp_get_attachment_image_url( $ro, 'large' );
		$full  = wp_get_attachment_url( $ro );
		$this->assertMatchesRegularExpression( '/-\d+x\d+\.jpg$/', $large, 'Sentinel: the large size is a resized file.' );

		$posts = array();
		foreach ( array( 'ro' => $ro, 'en' => $en, 'es' => $es ) as $lang => $att ) {
			$posts[ $lang ] = self::factory()->post->create(
				array(
					'post_status'  => 'draft',
					'post_content' => '<!-- wp:image {"id":' . $att . ',"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="' . esc_url( $large ) . '" alt="typed ' . $lang . ' alt" class="wp-image-' . $att . '"/></figure><!-- /wp:image -->'
						. '<!-- wp:image {"id":' . $att . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="' . esc_url( $full ) . '" alt="" class="wp-image-' . $att . '"/></figure><!-- /wp:image -->',
				)
			);
		}

		$name = 'wpml-big-' . strtolower( wp_generate_password( 4, false, false ) );
		$this->enableAiRename( $name );
		$this->purgeQueueTable();
		( new QueueController() )->addItemToQueue( $this->freshImageModel( $ro ), array( 'action' => 'requestAlt' ) );
		$this->runQueueUntilEmpty();

		clean_post_cache( $ro );
		$this->assertStringContainsString( $name, basename( get_attached_file( $ro ) ), 'Sentinel: the file was renamed.' );
		$new_large = basename( wp_get_attachment_image_url( $ro, 'large' ) );
		$new_full  = basename( wp_get_attachment_url( $ro ) );

		foreach ( $posts as $lang => $post_id ) {
			clean_post_cache( $post_id );
			$content = get_post( $post_id )->post_content;
			$this->assertStringContainsString( $new_large, $content, "[$lang] The large-size block points at the renamed file." );
			$this->assertStringContainsString( $new_full, $content, "[$lang] The full-size block points at the renamed -scaled file." );
			$this->assertStringNotContainsString( basename( $large ), $content, "[$lang] No old large URL is left." );
			$this->assertStringNotContainsString( basename( $full ), $content, "[$lang] No old -scaled URL is left." );
		}
	}

	/**
	 * Rename + webp/avif meta with WPML — WPML copies carry
	 * their OWN ShortPixel meta (optimize propagates it to every same-file
	 * translation). After the main language renames the shared file, the
	 * translation's webp/avif names must follow too.
	 */
	public function test_rename_updates_the_webp_avif_meta_of_wpml_translations() {
		\wpSPIO()->settings()->createWebp = 1;
		\wpSPIO()->settings()->createAvif = 1;
		$id     = $this->uploadFixture( 'fixture-small.jpg' );
		$dup_id = $this->createDuplicateAttachment( $id );
		$this->insertTranslationRow( $id, 9501, 'en' );
		$this->insertTranslationRow( $dup_id, 9501, 'de', 'en' );
		$this->optimizeAttachment( $id );
		$this->purgeQueueTable();

		$dup_before = $this->freshImageModel( $dup_id );
		$this->assertNotEmpty( $dup_before->getMeta( 'webp' ), 'Sentinel: the translation has its own webp meta.' );

		$new_base = 'wpml-webp-' . strtolower( wp_generate_password( 5, false, false ) );
		$this->assertTrue( $this->renameAttachment( $id, $new_base ), 'Sentinel: the main-language rename succeeded.' );

		$main = $this->freshImageModel( $id );
		$dup  = $this->freshImageModel( $dup_id );
		$this->assertStringStartsWith( $new_base, (string) $main->getMeta( 'webp' ), 'Sentinel: the main item\'s webp meta follows.' );
		$this->assertStringStartsWith( $new_base, (string) $dup->getMeta( 'webp' ), 'The translation\'s webp meta follows the rename.' );
		$this->assertStringStartsWith( $new_base, (string) $dup->getMeta( 'avif' ), 'The translation\'s avif meta follows the rename.' );
		$this->assertTrue( $dup->getWebp()->exists(), 'The translation\'s webp points at an existing file.' );
	}
}
