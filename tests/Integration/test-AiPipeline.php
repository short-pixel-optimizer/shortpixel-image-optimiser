<?php
/**
 * AI Image SEO pipeline integration tests (mocked AI API).
 *
 * Exercises the REAL two-phase AI flow through the queue:
 * QueueController::addItemToQueue(action=requestAlt) →
 * OptimizeAiController::enqueueItem() → queue tick → AiController::
 * processMediaItem() → add-url.php (remote id + JWT) → next_action
 * retrieveAlt → get-url.php poll → HandleSuccess() → formatResultData()
 * (ucfirst + trailing period) → AiDataModel::handleNewData() (aipostmeta
 * row + _wp_attachment_image_alt + post excerpt/content/title).
 *
 * Credit-combination matrix (Pedro, 2026-07-18): AI credits and
 * optimization credits are SEPARATE accounts. Verified here:
 *  - AI over-quota (add-url status 3) fails only the AI item; the global
 *    quotaExceeded flag stays off and optimization still runs.
 *  - Optimization over-quota (quotaExceeded=1) blocks the WHOLE
 *    processQueue() tick — INCLUDING pending AI work (pinned; design
 *    question for Bas: hasQuota() is one boolean with no AI split).
 *  - optimize→AI and AI→optimize on the same item chain via next_action
 *    (QueueController::isItemInQueue IN_QUEUE_ACTION_ADDED) and both land
 *    — in single-pass AND multi-pass (thumbnails) optimizations, including
 *    when enqueue + processing share one PHP request (Queue::itemDone()
 *    invalidates the $isInQueue cache since 806c658a, bug #14 fix).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\AjaxController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Model\AiDataModel;

class AiPipelineTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		parent::set_up();

		$settings                 = \wpSPIO()->settings();
		$settings->ai_gen_alt     = 1;
		$settings->ai_gen_caption = 1;
		// Filename generation off: the rename path (Replacer2, file moves)
		// is out of scope for these tests.
		$settings->ai_gen_filename = 0;

		$this->purgeAiData();
	}

	public function tear_down() {
		$this->purgeAiData();
		parent::tear_down();
	}

	/** Drop aipostmeta rows and the AiDataModel in-memory model cache. */
	private function purgeAiData(): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// No SHOW TABLES guard — the table may be a session TEMPORARY table
		// (WP test framework rewrites dbDelta DDL), invisible to SHOW TABLES.
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}shortpixel_aipostmeta`" );
		$wpdb->suppress_errors( $suppress );

		$ref  = new ReflectionClass( AiDataModel::class );
		$prop = $ref->getProperty( 'models' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );

		delete_transient( 'spio_ai_jwt_token' );
	}

	/** Upload a fixture and clear the auto-enqueued optimize item. */
	private function freshAttachment(): int {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		return $attachment_id;
	}

	private function enqueueAi( int $attachment_id ): object {
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		return ( new QueueController() )->addItemToQueue( $imageModel, array( 'action' => 'requestAlt' ) );
	}

	private function enqueueOptimize( int $attachment_id ): object {
		$imageModel = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		return ( new QueueController() )->addItemToQueue( $imageModel );
	}

	private function freshImageModel( int $attachment_id ) {
		$this->resetPluginSingletons();
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
	}

	public function test_request_alt_roundtrip_stores_generated_data() {
		$attachment_id = $this->freshAttachment();

		$result = $this->enqueueAi( $attachment_id );
		$this->assertFalse( $result->is_error, 'AI enqueue must succeed: ' . print_r( $result->message, true ) );

		$this->runQueueUntilEmpty();

		// processTextResult(): ucfirst + trailing period on text fields.
		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Generated alt must land in the WP alt meta, capitalised with a period'
		);

		$post = get_post( $attachment_id );
		$this->assertSame( 'A mock ai caption.', $post->post_excerpt, 'Caption must land in post_excerpt' );
		$this->assertSame( 'A mock ai description.', $post->post_content, 'Description must land in post_content' );
		$this->assertSame( 'a mock ai title', $post->post_title, 'post_title is written verbatim (no text formatting)' );

		// The aipostmeta record must exist and be marked generated.
		$aiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, $aiModel->getStatus(), 'aipostmeta row must be status GENERATED' );
		$generated = $aiModel->getGeneratedData();
		$this->assertSame( 'A mock ai alt text.', $generated['alt'] );
	}

	public function test_request_alt_sends_expected_payload_and_polls() {
		$attachment_id = $this->freshAttachment();
		$original_url  = wp_get_attachment_url( $attachment_id );

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		$addRequests = array_values( array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'add-url.php' );
		} ) );
		$getRequests = array_values( array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'get-url.php' );
		} ) );

		$this->assertCount( 1, $addRequests, 'Exactly one add-url submission' );
		$this->assertGreaterThanOrEqual( 1, count( $getRequests ), 'At least one get-url poll' );

		$payload = $addRequests[0]['request'];
		// Compare against the PRE-run URL: since 12603b56 ('filebase' joined
		// $textItems) the AI apply step renames the attachment (see pin below),
		// so wp_get_attachment_url() after the run no longer matches the
		// payload that was sent.
		$this->assertSame( $original_url, $payload['url'] );

		// PINNED — production bug introduced by the #16 fix (12603b56):
		// formatResultData() falls back to original_filebase when the API
		// returns no 'filebase', then runs it through processTextResult()
		// (ucfirst + trailing period). replaceFiles() then renames the file
		// to e.g. 'Fixture-small-1..jpg' — every AI request without an
		// API-generated filebase mangles the real filename. When fixed, the
		// URL stays unchanged — flip this to assertSame($original_url, ...).
		$this->assertNotSame(
			$original_url,
			wp_get_attachment_url( $attachment_id ),
			'Pinned: AI apply mangles the filebase (ucfirst + trailing dot) when the API returns none. If URLs match, the bug was fixed — assert equality instead and drop this pin.'
		);
		$this->assertSame( '1', $payload['retry'] );
		$this->assertSame( 'v_2', $payload['version'] );
		$this->assertArrayHasKey( 'alt', $payload, 'ai_gen_alt=1 must put the alt job in the paramlist' );
		$this->assertArrayHasKey( 'caption', $payload );
		$this->assertArrayNotHasKey( 'file', $payload, 'ai_gen_filename=0 must keep the filename job out' );

		// The poll must reference the remote id issued by add-url (mock ids start at 5000).
		$this->assertGreaterThanOrEqual( 5000, (int) $getRequests[0]['request']['id'] );

		// The JWT from the response must be cached for the next request.
		$this->assertSame( 'mock-ai-jwt-token', get_transient( 'spio_ai_jwt_token' ) );
	}

	public function test_processing_status_is_polled_until_ready() {
		$attachment_id = $this->freshAttachment();

		$this->api->aiWaitingRounds = 1;

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty( 40 );

		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'A status-1 (processing) round must be retried until the result arrives'
		);
	}

	/**
	 * MATRIX: missing AI credits + available optimization credits.
	 * The AI over-quota answer (status 3) must fail ONLY the AI item —
	 * never flip the global quotaExceeded flag — so optimization of the
	 * very same item still works.
	 */
	public function test_ai_over_quota_fails_item_but_optimization_still_runs() {
		$attachment_id = $this->freshAttachment();

		$this->api->aiAddStatus = 3; // AI_STATUS_OVERQUOTA

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		$this->assertSame( '', (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 'No alt data on AI over-quota' );
		$this->assertEmpty( \wpSPIO()->settings()->quotaExceeded, 'AI over-quota must NOT set the global optimization quotaExceeded flag' );

		// Optimization credits are a separate account — same item must optimize.
		$this->api->aiAddStatus = null;
		$this->optimizeAttachment( $attachment_id );
		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'Optimization must succeed after an AI over-quota failure' );
	}

	/**
	 * MATRIX: missing optimization credits + available AI credits.
	 *
	 * PINNED (design question for Bas, found 2026-07-19): hasQuota() is a
	 * single boolean (settings->quotaExceeded) with no AI/optimization
	 * split, and processQueue() gates the WHOLE tick on it. So when
	 * optimization credits run out, queued AI work — paid from a separate
	 * credit account — is blocked too and answers NOQUOTA. If AI is meant
	 * to keep working (Pedro: "all of this should work flawlessly"), the
	 * gate needs an AI-aware split. This pins the CURRENT behaviour; when
	 * the split lands, flip the expectations.
	 */
	public function test_optimization_over_quota_blocks_ai_processing_pinned() {
		$attachment_id = $this->freshAttachment();

		$this->enqueueAi( $attachment_id );
		$this->assertTrue( $this->queueHasWork(), 'Precondition: AI item queued' );

		\wpSPIO()->settings()->quotaExceeded = 1;

		$result = ( new QueueController() )->processQueue( array( 'media', 'custom' ) );

		$this->assertSame( AjaxController::NOQUOTA, $result->error, 'Optimization over-quota blocks the whole tick — flip when the AI-aware quota split lands' );
		$this->assertSame(
			'',
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'AI work must not have been processed while the optimization quota gate is closed'
		);

		\wpSPIO()->settings()->quotaExceeded = 0;
	}

	/**
	 * MATRIX: optimization request followed by an AI request for the SAME
	 * item. The AI action must chain onto the queued item as a next_action
	 * and both results must land.
	 *
	 * Thumbnails off = single-pass optimization; the multi-pass (thumbnails
	 * on) variant is covered separately below.
	 */
	public function test_optimize_then_ai_on_same_item_completes_both() {
		\wpSPIO()->settings()->processThumbnails = 0;
		$attachment_id                           = $this->freshAttachment();

		$optResult = $this->enqueueOptimize( $attachment_id );
		$this->assertFalse( $optResult->is_error );

		$aiResult = $this->enqueueAi( $attachment_id );
		$this->assertFalse( $aiResult->is_error );
		$this->assertFalse( $aiResult->is_done, 'The AI action must be APPENDED to the queued optimize item (IN_QUEUE_ACTION_ADDED), not dropped' );

		$this->runQueueUntilEmpty( 40 );

		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'The optimization leg must complete' );
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 'The chained AI leg must complete too' );
	}

	/**
	 * MATRIX: the reverse order — AI request first, then an optimization
	 * request for the same item. Thumbnails off for the same single-pass
	 * reason as above.
	 */
	public function test_ai_then_optimize_on_same_item_completes_both() {
		\wpSPIO()->settings()->processThumbnails = 0;
		$attachment_id                           = $this->freshAttachment();

		$aiResult = $this->enqueueAi( $attachment_id );
		$this->assertFalse( $aiResult->is_error );

		$optResult = $this->enqueueOptimize( $attachment_id );
		$this->assertFalse( $optResult->is_error );
		$this->assertFalse( $optResult->is_done, 'The optimize action must be APPENDED to the queued AI item' );

		$this->runQueueUntilEmpty( 40 );

		$this->assertSame( 'A mock ai alt text.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 'The AI leg must complete' );
		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'The chained optimization leg must complete too' );
	}

	/**
	 * MATRIX: a MULTI-PASS optimization (thumbnails on — the default!) must
	 * still run the chained AI action. The "RESEND TO PROCESS MORE" branch
	 * (OptimizeController.php:379-398) re-enqueues a fresh QueueItem, but
	 * that item re-reads the queue row (which still carries next_actions)
	 * and newAction() preserves them across the data reset — verified here:
	 * the chain survives the resend.
	 */
	public function test_multipass_optimize_still_runs_chained_ai_action() {
		\wpSPIO()->settings()->processThumbnails = 1;
		$attachment_id                           = $this->freshAttachment();

		$optResult = $this->enqueueOptimize( $attachment_id );
		$this->assertFalse( $optResult->is_error );

		$aiResult = $this->enqueueAi( $attachment_id );
		$this->assertFalse( $aiResult->is_error );
		$this->assertFalse( $aiResult->is_done, 'Precondition: the AI action WAS appended to the queued item' );

		$this->runQueueUntilEmpty( 40 );

		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'The optimization leg completes' );

		$aiRequests = array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'add-url.php' );
			}
		);
		$this->assertNotCount( 0, $aiRequests, 'The chained AI request must reach the AI API despite the multi-pass resend' );
		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'The chained AI leg must complete after a multi-pass optimization'
		);
	}

	/**
	 * Bug #14 FIXED (806c658a): Queue::itemDone() now invalidates the static
	 * Queue::$isInQueue cache entry (mirroring dropItem), so when enqueue,
	 * append and processing all happen inside ONE request (ajax "process now",
	 * WP-CLI, tests) the chained re-enqueue no longer reads a stale WAITING
	 * status and the chained action survives. Flipped from the pinned
	 * lost-chain assertion; the flushQueueStatusCache() workarounds were
	 * dropped from the chaining tests above.
	 */
	public function test_same_request_chain_survives_queue_cache() {
		\wpSPIO()->settings()->processThumbnails = 0;
		$attachment_id                           = $this->freshAttachment();

		$this->enqueueOptimize( $attachment_id );
		$aiResult = $this->enqueueAi( $attachment_id ); // populates the cache entry itemDone must clear
		$this->assertFalse( $aiResult->is_done, 'Precondition: the AI action WAS appended to the queued item' );

		// NO flushQueueStatusCache() here — that is the point.
		$this->runQueueUntilEmpty( 40 );

		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'The optimization leg completes' );
		$this->assertSame(
			'A mock ai alt text.',
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Since 806c658a (bug #14 fix) the chained AI action must complete within the same request.'
		);
	}

	/** A second AI request for an item with generated data must be refused (P_ALREADYDONE). */
	public function test_second_ai_request_is_refused() {
		$attachment_id = $this->freshAttachment();

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();
		$this->assertSame( 'A mock ai alt text.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), 'Precondition: first round generated' );

		AiDataModel::flushModelCache( $attachment_id );
		$result = $this->enqueueAi( $attachment_id );

		$this->assertTrue( $result->is_error, 'An item that already has AI data must not be re-queued' );
		$this->assertTrue( $result->is_done );
	}

	/**
	 * Manually updating the alt text after AI generation must reset the AI
	 * status so the item shows as "different" (user-edited) rather than
	 * clean-generated.
	 *
	 * Verified behaviour: AiDataModel::currentIsDifferent() returns true when
	 * the live _wp_attachment_image_alt deviates from the stored generated alt.
	 *
	 * Manual-plan row: 32.5
	 */
	public function test_updating_alt_text_after_ai_generation_resets_ai_status() {
		$attachment_id = $this->freshAttachment();

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		$generated_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$this->assertSame( 'A mock ai alt text.', $generated_alt, 'Precondition: AI alt was generated' );

		// Simulate the user manually editing the alt text via WP (e.g. media
		// library edit screen). Update post meta directly as WP itself does.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'My custom hand-typed alt' );

		// Re-load the model from DB so it reads fresh current data.
		AiDataModel::flushModelCache( $attachment_id );
		$aiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );

		// The model must still report AI_STATUS_GENERATED (the record is not
		// wiped just because the user edited) — but currentIsDifferent() must
		// now return true because the live value diverges from the generated one.
		$this->assertSame(
			AiDataModel::AI_STATUS_GENERATED,
			$aiModel->getStatus(),
			'aipostmeta row must still be GENERATED after manual edit'
		);
		$this->assertTrue(
			$aiModel->currentIsDifferent(),
			'currentIsDifferent() must be true when the user overwrote the AI alt'
		);
	}

	/**
	 * Undoing AI data must restore the pre-AI alt text that was saved as the
	 * original value when AI first ran, remove the aipostmeta row, and leave
	 * the item processable again.
	 *
	 * Verified behaviour: AiDataModel::revert() writes original data back to
	 * WP, deletes the DB row, and flushes the model cache so isProcessable()
	 * returns true for a fresh model.
	 *
	 * Manual-plan rows: 32.6 / 33.06
	 */
	public function test_undo_ai_data_restores_previous_alt_text() {
		$attachment_id = $this->freshAttachment();

		// Give the attachment a pre-existing alt before AI runs.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'original human alt' );

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Precondition: AI must have overwritten the original alt'
		);

		// Undo via the model's revert() method — the same path OptimizeAiController
		// calls for the 'undoAI' action (undoAltData()).
		AiDataModel::flushModelCache( $attachment_id );
		$aiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$aiModel->revert();

		// After revert the original alt must be back.
		$this->assertSame(
			'original human alt',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'revert() must restore the pre-AI alt text'
		);

		// The aipostmeta row must be gone so the item is processable again.
		AiDataModel::flushModelCache( $attachment_id );
		$freshModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$this->assertSame(
			AiDataModel::AI_STATUS_NOTHING,
			$freshModel->getStatus(),
			'Status must be AI_STATUS_NOTHING after revert'
		);
		$this->assertTrue(
			$freshModel->isProcessable(),
			'Item must be processable again after undo'
		);
	}

	/**
	 * A requestAlt action submitted when no valid API key is configured must
	 * not reach the AI API; processQueue() must return APIKEY_FAILED and the
	 * alt text must remain empty.
	 *
	 * Verified behaviour: QueueController::processQueue() gates the whole tick
	 * on ApiKeyController::keyIsVerified(); with a missing/unverified key it
	 * returns AjaxController::APIKEY_FAILED without sending any HTTP request.
	 *
	 * Manual-plan row: 32.10
	 */
	public function test_ai_request_with_no_api_key_fails_with_no_key_error() {
		$attachment_id = $this->freshAttachment();

		// Remove the verified key so the system has no valid key at all.
		update_option(
			'spio_key',
			array(
				'apiKey'      => '',
				'verifiedKey' => false,
				'apiKeyTried' => '',
			)
		);
		// Reset singletons so ApiKeyController re-reads the option above.
		$this->resetPluginSingletons();

		// Without this, ApiKeyModel::checkRedirect() (ApiKeyModel.php:505) sees
		// an unverified key that never redirected and wp_safe_redirect()+exit()s,
		// killing the whole PHPUnit process mid-suite.
		\wpSPIO()->settings()->redirectedSettings = 1;

		$this->enqueueAi( $attachment_id );
		$this->assertTrue( $this->queueHasWork(), 'Precondition: AI item must be in the queue' );

		$result = ( new QueueController() )->processQueue( array( 'media', 'custom' ) );

		$this->assertSame(
			\ShortPixel\Controller\AjaxController::APIKEY_FAILED,
			$result->error,
			'processQueue() must return APIKEY_FAILED when no API key is set'
		);
		$this->assertSame(
			'',
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'No alt must be written when the API key gate blocks the tick'
		);

		$aiRequests = array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'add-url.php' );
		} );
		$this->assertCount( 0, $aiRequests, 'No add-url.php request must be sent when key is missing' );
	}

	/**
	 * With aiPreserve=true, running AI on an item that already has alt text
	 * must not overwrite the existing alt (F_STATUS_PREVENTOVERRIDE), while
	 * fields that were empty are still filled.
	 *
	 * Verified behaviour: AiDataModel::getOptimizeData() excludes non-empty
	 * fields from the API paramlist when aiPreserve is on, and the API result
	 * for excluded fields carries the F_STATUS_PREVENTOVERRIDE integer status
	 * rather than a text string.
	 *
	 * Manual-plan row: 33.04
	 */
	public function test_preserve_existing_ai_data_setting_prevents_overwrite() {
		$attachment_id = $this->freshAttachment();

		// Pre-fill only alt — caption and description stay empty.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'pre-existing human alt' );

		\wpSPIO()->settings()->aiPreserve      = 1;
		\wpSPIO()->settings()->ai_gen_alt      = 1;
		\wpSPIO()->settings()->ai_gen_caption  = 1;

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		// The pre-existing alt must NOT have been replaced.
		$this->assertSame(
			'pre-existing human alt',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'aiPreserve=true must protect a non-empty alt from AI overwrite'
		);

		// Caption (was empty) must have been filled.
		$post = get_post( $attachment_id );
		$this->assertNotEmpty(
			$post->post_excerpt,
			'aiPreserve=true must still fill empty caption'
		);

		// The AiDataModel record must reflect F_STATUS_PREVENTOVERRIDE for alt.
		AiDataModel::flushModelCache( $attachment_id );
		$aiModel    = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$generated  = $aiModel->getGeneratedData();
		$this->assertSame(
			AiDataModel::F_STATUS_PREVENTOVERRIDE,
			$generated['alt'],
			'generated[alt] must be F_STATUS_PREVENTOVERRIDE when aiPreserve blocked the field'
		);
	}

	/**
	 * Without aiPreserve, running AI on an item that already has alt text
	 * must overwrite the existing alt with the AI-generated value.
	 *
	 * Verified behaviour: when aiPreserve is false, no fields are excluded
	 * from the API paramlist and AiDataModel::handleNewData() writes every
	 * generated value to WordPress.
	 *
	 * Manual-plan row: 33.05
	 */
	public function test_without_preserve_flag_ai_overwrites_existing_alt_text() {
		$attachment_id = $this->freshAttachment();

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'pre-existing human alt' );

		\wpSPIO()->settings()->aiPreserve = 0;
		\wpSPIO()->settings()->ai_gen_alt = 1;

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Without aiPreserve the AI alt must overwrite the existing value'
		);
	}

	/**
	 * After undoing AI data, the item must be processable again, and a new
	 * AI generation pass (e.g. triggered by bulk) must succeed and write fresh
	 * generated values.
	 *
	 * Verified behaviour: revert() deletes the aipostmeta row, isProcessable()
	 * returns true, and a subsequent enqueue + queue run re-generates the data.
	 *
	 * Manual-plan row: 32.17
	 */
	public function test_undo_then_bulk_regenerates_ai_data() {
		$attachment_id = $this->freshAttachment();

		// First generation.
		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();
		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Precondition: first generation must succeed'
		);

		// Undo.
		AiDataModel::flushModelCache( $attachment_id );
		$aiModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$aiModel->revert();
		AiDataModel::flushModelCache( $attachment_id );

		// Verify item is processable after undo.
		$freshModel = AiDataModel::getModelByAttachment( $attachment_id, 'media' );
		$this->assertTrue( $freshModel->isProcessable(), 'Precondition: item must be processable after undo' );

		// Simulate "bulk regenerate" by re-enqueueing the item.
		// Reset mock request log so we can assert a new add-url call happens.
		$this->api->reset();
		$result = $this->enqueueAi( $attachment_id );
		$this->assertFalse( $result->is_error, 'Re-enqueue after undo must succeed: ' . print_r( $result->message ?? '', true ) );

		$this->runQueueUntilEmpty();

		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Regeneration after undo must produce fresh AI alt text'
		);

		$addRequests = array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'add-url.php' );
		} );
		$this->assertGreaterThanOrEqual( 1, count( $addRequests ), 'A new add-url.php request must be sent during regeneration' );
	}
}
