<?php
/**
 * Cross-plugin compatibility: WPML (Wave 3).
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
 *   - OptimizeAiController::WPMLCheckReplace() (f232c607) — the replace-time
 *     language guard for AI text replacement, incl. pinned bug #40 (compares
 *     the non-existent 'code' key instead of 'language_code') at both the
 *     guard level and end-to-end through handleReplace().
 *   - MediaLibraryModel::getWPMLDuplicates() — translation duplicates
 *     found via the real icl_translations table (same-trid siblings),
 *     restricted to attachments sharing the same physical file; on
 *     optimize, handleOptimized() propagates meta to every duplicate.
 *   - QueueController::addWpmlAiItemsToQueue() (f232c607) — the requestAlt
 *     per-language fan-out, incl. pinned bug #42 (the fan-out runs before
 *     the Queue::isDuplicateActive() check, so the ORIGINAL attachment is
 *     skipped as "duplicate active" and never gets its own alt text), and
 *     the isDuplicateActive() skip for same-file translations.
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
	// AI replace-time language guard (WPMLCheckReplace, f232c607)
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
	 * refuse the replacement (fail closed). This path correctly reads the
	 * `language_code` key, so it behaves the same before and after bug #40.
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
	 * PINNED bug #40: WPMLCheckReplace() validates the WPML lookup via the
	 * `language_code` key (correct — that is what `wpml_post_language_details`
	 * returns), but then COMPARES `$language['code']` vs
	 * `$language_queue['code']` — a key WPML never provides. Both sides are
	 * undefined, so the mismatch branch can never trigger: pages in a
	 * DIFFERENT language are still replaced, defeating the whole guard (and
	 * emitting two "Undefined array key" warnings per checked post on PHP 8).
	 *
	 * The pin accepts either manifestation of the bug — the undefined-key
	 * warning (converted to an exception by phpunit-integration.xml) or a
	 * `true` return for clearly different languages.
	 *
	 * FLIP when fixed (`code` → `language_code`): the call below returns
	 * false with no warning — then simply assertFalse the invocation.
	 */
	public function test_pin40_wpml_replace_guard_does_not_block_other_languages() {
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

		$warning = null;
		$allowed = null;
		try {
			$allowed = $this->invokeWpmlCheckReplace( $post_id, $queue_id );
		} catch ( \Throwable $e ) {
			$warning = $e;
		}

		if ( null !== $warning ) {
			$this->assertStringContainsString(
				'code',
				$warning->getMessage(),
				'PINNED bug #40 — the guard reads the non-existent \'code\' key. When fixed, no warning is raised: flip this test to assertFalse the invocation.'
			);
		} else {
			$this->assertTrue(
				$allowed,
				'PINNED bug #40 — comparing the undefined \'code\' keys makes different-language pages pass the guard. When fixed (language_code), flip this to assertFalse.'
			);
		}
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
	// 16.2 — later translation does not re-optimize
	// -------------------------------------------------------------------

	/**
	 * Adding a third WPML translation AFTER the image is already optimized
	 * must not enqueue a new API request.
	 *
	 * Manual plan row 16.2: optimize, then add another language row; the
	 * queue tick must produce no new API call.
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
	// 16.3 — bulk deduplicates WPML translations in API call count
	// -------------------------------------------------------------------

	/**
	 * When running bulk optimization with WPML translations present, each
	 * physical file must only be sent to the API once — not once per language.
	 *
	 * Manual plan row 16.3: seed two images each with one translation, run
	 * bulk, assert API call count equals the number of unique source files.
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
	// 16.4 — deleting a translation preserves backup until last copy gone
	// -------------------------------------------------------------------

	/**
	 * Deleting a WPML translation attachment must NOT remove the backup when
	 * other language versions of the same physical file still exist.  The
	 * backup may only be deleted when the last remaining attachment sharing
	 * the file is deleted.
	 *
	 * Manual plan row 16.4: optimize original + duplicate, delete translation
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
	// AI requestAlt fan-out (addWpmlAiItemsToQueue, f232c607)
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
	 * PINNED bug #42 (second half): the fan-out DOES queue the translation,
	 * but the ORIGINAL attachment never gets queued. addItemToQueue() runs
	 * addWpmlAiItemsToQueue() BEFORE the isDuplicateActive() check, so the
	 * just-queued language variants make the original count as
	 * "duplicate already active in queue" and it is skipped — its own alt
	 * text is never generated.
	 *
	 * FLIP when fixed (e.g. run the duplicate fan-out after/around the
	 * duplicate-active check, or exempt requestAlt): change the last
	 * assertion to assertContains.
	 */
	public function test_pin42_requestalt_fanout_drops_the_original_attachment() {
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
		$this->assertNotContains(
			$id,
			$queued,
			'PINNED bug #42 — the original is currently skipped as "duplicate active" right after its own fan-out. If it IS queued now, the bug is fixed: flip this to assertContains.'
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
	// handleReplace end-to-end — pinned against bug #40
	// -------------------------------------------------------------------

	/**
	 * PINNED bug #40, end-to-end leg: handleReplace() runs every result
	 * through WPMLCheckReplace(), which compares the non-existent 'code'
	 * key on both sides — so a post in a DIFFERENT language is replaced
	 * anyway (undefined === undefined passes the guard).
	 *
	 * Depending on the error-handler configuration the bug manifests as an
	 * "Undefined array key 'code'" warning/exception instead; the pin
	 * accepts either manifestation (same pattern as the unit-level pin40).
	 *
	 * FLIP when fixed (`code` → `language_code`): the same-language post is
	 * replaced, and the other-language post is NOT — change the second
	 * content assertion to assertStringNotContainsString and drop the
	 * warning branch.
	 */
	public function test_pin40_handlereplace_replaces_other_language_posts_end_to_end() {
		$id         = $this->uploadFixture( 'fixture-small.jpg' );
		$imageModel = $this->freshImageModel( $id );

		$img_tag    = '<img src="' . esc_url( wp_get_attachment_url( $id ) ) . '" alt="old alt" />';
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
			'aiData' => array( 'alt' => 'AI pinned alt', 'caption' => 0 ),
			'qItem'  => $qItem,
		);

		$warning = null;
		try {
			\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace( $results, $args );
		} catch ( \Throwable $e ) {
			$warning = $e;
		}

		if ( null !== $warning ) {
			$this->assertStringContainsString(
				'code',
				$warning->getMessage(),
				'PINNED bug #40 — handleReplace hits the undefined \'code\' key in the guard. When fixed, no warning is raised: assert the replace outcomes instead.'
			);
			return;
		}

		// Replacer's Updater writes post_content via direct SQL — invalidate
		// the WP post cache before re-reading.
		clean_post_cache( $post_same );
		clean_post_cache( $post_other );

		$this->assertStringContainsString(
			'AI pinned alt',
			get_post( $post_same )->post_content,
			'The same-language post must receive the AI alt text.'
		);
		$this->assertStringContainsString(
			'AI pinned alt',
			get_post( $post_other )->post_content,
			'PINNED bug #40 — the different-language post is currently replaced too because the guard compares undefined keys. When fixed, flip to assertStringNotContainsString.'
		);
	}
}
