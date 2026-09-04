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
		$this->assertSame( $original_url, $payload['url'] );

		// Bug #31 FIXED (af5794d8): 'filebase' was removed from $textItems in
		// formatResultData(), so the original_filebase fallback is no longer
		// sentence-formatted (ucfirst + trailing dot) and replaceFiles() no
		// longer renames the real file when the API returns no filebase.
		$this->assertSame(
			$original_url,
			wp_get_attachment_url( $attachment_id ),
			'Since af5794d8 (bug #31 fix) an AI run without an API-generated filebase must leave the attachment URL untouched.'
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

	/**
	 * Regression for 2e3b43c0: `prefer_keep_filename_if_relevant` must be sent
	 * INSIDE the `file` object of the AI payload — the API expects it there.
	 * Before the fix it sat at the payload root (and only when ai_gen_filename
	 * was on, where the API ignored it).
	 */
	public function test_filename_preference_flag_is_sent_inside_the_file_object() {
		$settings                             = \wpSPIO()->settings();
		$settings->ai_gen_filename            = 1;
		$settings->ai_filename_prefercurrent  = 1;

		$attachment_id = $this->freshAttachment();

		$this->enqueueAi( $attachment_id );
		$this->runQueueUntilEmpty();

		$addRequests = array_values( array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'add-url.php' );
		} ) );
		$this->assertNotEmpty( $addRequests, 'The AI job must reach add-url.php' );

		$payload = $addRequests[0]['request'];
		$this->assertArrayHasKey( 'file', $payload, 'ai_gen_filename=1 must put the filename job in the paramlist' );
		$this->assertArrayHasKey(
			'prefer_keep_filename_if_relevant',
			(array) $payload['file'],
			'The flag must live inside the file object (2e3b43c0), where the API reads it.'
		);
		$this->assertTrue( (bool) ( (array) $payload['file'] )['prefer_keep_filename_if_relevant'] );
		$this->assertArrayNotHasKey(
			'prefer_keep_filename_if_relevant',
			$payload,
			'The flag must no longer appear at the payload root.'
		);
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

	/**
	 * Regression test for the customer-reported bulk AI SEO bug (fixed in
	 * 97f2c1f4): when SEVERAL images are AI-processed in the same PHP
	 * request, every image's in-content <img alt> must be updated in the
	 * post that embeds it — not just the first image's post.
	 *
	 * Root cause of the bug: replacer2's Setup::getInstance() was a
	 * request-lifetime singleton whose Url data accumulated via addData(),
	 * while Url::getBaseURL() always returned data[0]. Every AI item after
	 * the first therefore searched post_content for the FIRST item's URL;
	 * handleReplace()'s filename filter then silently discarded the found
	 * posts, so images 2..n kept alt="" in content forever (the aipostmeta
	 * row made isProcessable() false on re-runs). The fix makes
	 * Setup::getInstance() return a FRESH instance per call, so each
	 * replaceImageAttributes() run searches with its own image's base URL.
	 *
	 * FLIP-alert: if this fails with only the first post updated, the
	 * Setup singleton (or equivalent shared Url state) has been
	 * reintroduced in replacer2.
	 */
	public function test_bulk_ai_updates_in_content_alt_for_every_image_not_just_the_first() {
		// Three attachments in one request — wp_unique_filename gives them
		// distinct file bases (fixture-small, fixture-small-1, fixture-small-2).
		$ids = array(
			$this->uploadFixture( 'fixture-small.jpg' ),
			$this->uploadFixture( 'fixture-small.jpg' ),
			$this->uploadFixture( 'fixture-small.jpg' ),
		);
		$this->purgeQueueTable();

		// Each image is embedded in its OWN post, with an empty alt.
		$posts = array();
		foreach ( $ids as $id ) {
			$img_tag      = '<img src="' . esc_url( wp_get_attachment_url( $id ) ) . '" alt="" />';
			$posts[ $id ] = self::factory()->post->create( array( 'post_content' => $img_tag ) );
		}

		foreach ( $ids as $id ) {
			$result = $this->enqueueAi( $id );
			$this->assertFalse( $result->is_error, 'AI enqueue must succeed for attachment ' . $id );
		}

		$this->runQueueUntilEmpty();

		foreach ( $ids as $id ) {
			$this->assertSame(
				'A mock ai alt text.',
				get_post_meta( $id, '_wp_attachment_image_alt', true ),
				"Attachment $id must get its WP alt meta (sanity — this part worked even with the bug)"
			);

			// Replacer's Updater writes post_content via direct SQL.
			clean_post_cache( $posts[ $id ] );
			$content = get_post( $posts[ $id ] )->post_content;
			$this->assertStringContainsString(
				'alt="A mock ai alt text."',
				$content,
				"Post embedding attachment $id must have its in-content alt filled. If only the first post got it, the replacer2 Setup singleton bug is back."
			);
		}
	}

	/**
	 * aiPreserve=true (af2414cc, default false): the in-content replacement
	 * (handleReplace) must only FILL empty alt attributes and leave
	 * human-written alts alone.
	 */
	public function test_aipreserve_only_fills_empty_in_content_alts() {
		$id         = $this->freshAttachment();
		$imageModel = $this->freshImageModel( $id );
		$src        = esc_url( wp_get_attachment_url( $id ) );

		// AFTER freshImageModel(): resetPluginSingletons() recreates the
		// SettingsModel, which would discard an unsaved in-memory value.
		\wpSPIO()->settings()->aiPreserve = 1;

		$post_human = self::factory()->post->create( array( 'post_content' => '<img src="' . $src . '" alt="human written alt" />' ) );
		$post_empty = self::factory()->post->create( array( 'post_content' => '<img src="' . $src . '" alt="" />' ) );

		$qItem   = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		$results = array(
			array( 'post_id' => $post_human, 'content' => get_post( $post_human )->post_content ),
			array( 'post_id' => $post_empty, 'content' => get_post( $post_empty )->post_content ),
		);
		$args    = array(
			'aiData' => array( 'alt' => 'A mock ai alt text.', 'caption' => 0 ),
			'qItem'  => $qItem,
		);

		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace( $results, $args );

		// Replacer's Updater writes post_content via direct SQL.
		clean_post_cache( $post_human );
		clean_post_cache( $post_empty );

		$this->assertStringContainsString(
			'alt="human written alt"',
			get_post( $post_human )->post_content,
			'With aiPreserve on, an existing alt must NOT be overwritten.'
		);
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_empty )->post_content,
			'With aiPreserve on, an empty alt must still be filled.'
		);
	}

	/**
	 * ai_content_replace='none' (efbd5ac9): replaceImageAttributes() must
	 * early-return, so a post whose content embeds the image goes BYTE-FOR-BYTE
	 * unchanged through the AI run. The Media Library alt meta must still be
	 * written (that path is independent of the content-replace toggle).
	 */
	public function test_ai_content_replace_none_leaves_post_content_untouched() {
		$id  = $this->freshAttachment();
		$src = esc_url( wp_get_attachment_url( $id ) );

		$original_content = '<p>Before</p><img src="' . $src . '" alt="editor wrote this" /><p>After</p>';
		$post_id          = self::factory()->post->create( array( 'post_content' => $original_content ) );

		// GOTCHA: any freshImageModel()/resetPluginSingletons() recreates
		// SettingsModel — flip the switch AFTER that would happen. Here we
		// have not called freshImageModel(), so this is safe.
		\wpSPIO()->settings()->ai_content_replace = 'none';

		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		// Media Library alt is written regardless of the content mode.
		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $id, '_wp_attachment_image_alt', true ),
			"'none' still writes the Media Library alt meta"
		);

		clean_post_cache( $post_id );
		$this->assertSame(
			$original_content,
			get_post( $post_id )->post_content,
			"'none' mode must not touch post_content — byte-for-byte match required"
		);
	}

	/**
	 * ai_content_replace='overwrite' (efbd5ac9): existing in-content alt IS
	 * replaced regardless of aiPreserve. This is the DOCUMENTED behavior of
	 * the overwrite mode — mirror of aiPreserve=off but explicit-opt-in.
	 */
	public function test_ai_content_replace_overwrite_replaces_existing_in_content_alt() {
		$id         = $this->freshAttachment();
		$imageModel = $this->freshImageModel( $id );
		$src        = esc_url( wp_get_attachment_url( $id ) );

		// AFTER freshImageModel(): resetPluginSingletons() recreated SettingsModel.
		\wpSPIO()->settings()->ai_content_replace = 'overwrite';
		\wpSPIO()->settings()->aiPreserve         = 1; // even with preserve on, overwrite wins

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $src . '" alt="human written alt" />' )
		);

		$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		$args  = array(
			'aiData' => array( 'alt' => 'A mock ai alt text.', 'caption' => 0 ),
			'qItem'  => $qItem,
		);

		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace(
			array( array( 'post_id' => $post_id, 'content' => get_post( $post_id )->post_content ) ),
			$args
		);

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_id )->post_content,
			"'overwrite' must replace an existing in-content alt even with aiPreserve=1"
		);
	}

	/**
	 * BUG-5 regression (efbd5ac9): the in-content filter now anchors against
	 * the URL basename with a regex like `^photo(-\d+x\d+|-scaled)?\.jpg$`.
	 * Before the fix, a plain substring test on the URL confused `photo.jpg`
	 * with `my-photo.jpg` — running AI on `photo.jpg` would rewrite BOTH tags.
	 *
	 * Sets up a post embedding both filenames and asserts that only the
	 * photo.jpg <img> receives the AI alt; the my-photo.jpg <img> keeps its
	 * original alt.
	 */
	public function test_in_content_replace_does_not_leak_across_substring_filenames_regression_bug5() {
		// Upload the "real" AI image as photo.jpg (or whatever wp_unique_filename gives us).
		$id_photo    = $this->uploadFixture( 'fixture-small.jpg' );
		$src_photo   = wp_get_attachment_url( $id_photo );
		$base_photo  = pathinfo( $src_photo, PATHINFO_FILENAME ); // e.g. fixture-small

		// Craft an intruder URL whose basename starts with "my-" + the real
		// base + ext. Its src doesn't need to point at an existing attachment;
		// handleReplace() only inspects the URL structure and post content.
		$intruder_src = str_replace(
			basename( $src_photo ),
			'my-' . basename( $src_photo ),
			$src_photo
		);

		$this->purgeQueueTable();

		$post_id = self::factory()->post->create(
			array(
				'post_content' =>
					'<img src="' . esc_url( $src_photo )    . '" alt="" />' .
					'<img src="' . esc_url( $intruder_src ) . '" alt="original intruder alt" />',
			)
		);

		$this->enqueueAi( $id_photo );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			$content,
			'The real image (' . $base_photo . '.jpg) must receive its AI alt.'
		);
		$this->assertStringContainsString(
			'alt="original intruder alt"',
			$content,
			'BUG-5 regression: my-' . $base_photo . '.jpg must NOT be swept up by the base-name filter.'
		);
	}

	/**
	 * BUG-3 regression (efbd5ac9): FrontImage's rebuild loop now preserves
	 * value-less attributes as bare booleans and passes src through esc_attr
	 * so `&amp;` in a URL survives. End-to-end check via the real replacement
	 * pipeline: after an AI run, the tag must carry both custom flags AND the
	 * escaped ampersand, AND the AI alt.
	 *
	 * Only data-* attributes are used here because WP's default kses img
	 * allowed-attrs list strips vendor tags like `nopin` on post insert (the
	 * bug in FrontImage was upstream of kses; the customer bug was reported
	 * on themes/plugins that bypass kses, but the FrontImage fix is verified
	 * one-attribute-family-per-test).
	 */
	public function test_in_content_replace_preserves_bare_attrs_and_amp_entity_regression_bug3() {
		$id  = $this->freshAttachment();
		$src = wp_get_attachment_url( $id );

		// Build a URL variant with an &amp; query string — the tag literal
		// includes bare-boolean data-* attrs (kses preserves data-*).
		$src_with_query = $src . '?w=100&amp;h=50';

		$post_id = self::factory()->post->create(
			array(
				'post_content' =>
					'<img src="' . $src_with_query . '" alt="" data-no-lazy data-nopin />',
			)
		);

		// Sanity: kses may still touch bare data-* — capture the actual
		// baseline so a rebuild-only regression is what we measure, not
		// kses-vs-not.
		$baseline = get_post( $post_id )->post_content;
		$this->assertMatchesRegularExpression(
			'/data-no-lazy/',
			$baseline,
			'Precondition: baseline post_content must contain the bare boolean attrs — otherwise this test cannot observe the FrontImage rebuild.'
		);

		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			$content,
			'Precondition: alt must have been updated by the AI pipeline (i.e. rebuild ran)'
		);
		$this->assertStringContainsString(
			'&amp;h=50',
			$content,
			'BUG-3 regression: &amp; entity in src must survive the rebuild'
		);
		$this->assertMatchesRegularExpression(
			'/<img[^>]*\sdata-no-lazy(?![=\w-])/',
			$content,
			'BUG-3 regression: bare boolean attr data-no-lazy must survive rebuild'
		);
		$this->assertMatchesRegularExpression(
			'/<img[^>]*\sdata-nopin(?![=\w-])/',
			$content,
			'BUG-3 regression: bare boolean attr data-nopin must survive rebuild'
		);
	}

	/**
	 * BUG-6 regression (16149a3c): when AI returns an INT status code for alt
	 * (e.g. F_STATUS_PREVENTOVERRIDE = a numeric int) and a text caption,
	 * handleReplace() must NOT trigger a tag rebuild for the post — the
	 * caption is not written into <img>, so a caption-only replacement would
	 * cause a lossy DOM parse+rebuild that changes byte content without any
	 * user-visible payload. Guard: caption branch runs only when $do_replace
	 * is already true (alt was replaced).
	 */
	public function test_caption_only_ai_data_does_not_rebuild_post_content_regression_bug6() {
		$id         = $this->freshAttachment();
		$imageModel = $this->freshImageModel( $id );
		$src        = esc_url( wp_get_attachment_url( $id ) );

		// Reset settings AFTER freshImageModel() (which recreates SettingsModel).
		\wpSPIO()->settings()->ai_content_replace = 'missing';
		\wpSPIO()->settings()->aiPreserve         = 0;

		// Include a unique whitespace/attribute shape so we can detect a
		// rebuild-and-normalize. Capture what's actually stored (WP may
		// normalize on insert) as the byte-for-byte baseline.
		$post_id  = self::factory()->post->create(
			array(
				'post_content' =>
					'<div><img src="' . $src . '" alt="" data-baseline="marker-9" /></div>',
			)
		);
		$baseline = get_post( $post_id )->post_content;

		$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		$args  = array(
			// alt is an int (status), caption is a string.
			'aiData' => array( 'alt' => \ShortPixel\Model\AiDataModel::F_STATUS_PREVENTOVERRIDE, 'caption' => 'a caption' ),
			'qItem'  => $qItem,
		);

		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace(
			array( array( 'post_id' => $post_id, 'content' => $baseline ) ),
			$args
		);

		clean_post_cache( $post_id );
		$this->assertSame(
			$baseline,
			get_post( $post_id )->post_content,
			'Caption-only AI data must NOT trigger a rebuild that alters post_content bytes. ' .
			'Before 16149a3c the caption branch could invoke buildImage() and rewrite the tag.'
		);
		// Sentinel: prove our marker attribute is still there — if the tag
		// were dropped entirely (e.g. handleReplace bailed on the whole post)
		// this baseline-match would false-pass.
		$this->assertStringContainsString(
			'data-baseline="marker-9"',
			get_post( $post_id )->post_content,
			'Sentinel: the post still contains our img — the equality above is not vacuous'
		);
	}

	/**
	 * BUG-4 regression (efbd5ac9 Updater.php rework): Updater::updatePost()
	 * now uses wp_update_post() so hooks fire and revisions are created,
	 * then restores original post_modified via direct SQL + clean_post_cache.
	 * Assertions:
	 *   (a) a revision row exists for the post
	 *   (b) post_modified is UNCHANGED from before the AI run
	 *   (c) the save_post hook fired
	 */
	public function test_updater_fires_hooks_creates_revision_and_preserves_post_modified_regression_bug4() {
		$id  = $this->freshAttachment();
		$src = esc_url( wp_get_attachment_url( $id ) );

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $src . '" alt="" />' )
		);

		// Backdate post_modified to a distinct value so we can prove it's untouched.
		global $wpdb;
		$original_modified     = '2020-01-01 00:00:00';
		$original_modified_gmt = '2020-01-01 00:00:00';
		$wpdb->update(
			$wpdb->posts,
			array( 'post_modified' => $original_modified, 'post_modified_gmt' => $original_modified_gmt ),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		// Baseline revision count (WP may or may not have any yet).
		$revisions_before = wp_get_post_revisions( $post_id );

		// Hook counter — count save_post fires for our post.
		$save_post_fires = 0;
		$counter         = function ( $post_id_hook ) use ( $post_id, &$save_post_fires ) {
			if ( (int) $post_id_hook === (int) $post_id ) {
				$save_post_fires++;
			}
		};
		add_action( 'save_post', $counter, 10, 1 );

		try {
			$this->enqueueAi( $id );
			$this->runQueueUntilEmpty();
		} finally {
			remove_action( 'save_post', $counter, 10 );
		}

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_id )->post_content,
			'Precondition: the AI pipeline must have written the alt into post_content'
		);

		// (b) post_modified must survive the update.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
		$this->assertSame(
			$original_modified,
			$row->post_modified,
			'post_modified must remain unchanged after Updater::updatePost restores the raw timestamp'
		);
		$this->assertSame(
			$original_modified_gmt,
			$row->post_modified_gmt,
			'post_modified_gmt must remain unchanged after Updater::updatePost restores the raw timestamp'
		);

		// (a) at least one new revision row exists.
		$revisions_after = wp_get_post_revisions( $post_id );
		$this->assertGreaterThan(
			count( $revisions_before ),
			count( $revisions_after ),
			'wp_update_post() through Updater must produce a revision row (BUG-4 regression)'
		);

		// (c) save_post must have fired at least once for our post.
		$this->assertGreaterThanOrEqual(
			1,
			$save_post_fires,
			'save_post hook must fire when Updater uses wp_update_post (BUG-4 regression)'
		);
	}

	/**
	 * Regression for BUG #56 (fixed in dc65f17e): with defaults —
	 * ai_content_replace='missing' and aiPreserve=false — an editor-written
	 * in-content alt was OVERWRITTEN because the 'missing' branch kept
	 * `false === $aiPreserve` as an OR leg in its guard. The fix dropped
	 * the aiPreserve leg, so 'missing' now always respects a non-empty alt,
	 * matching the UI label "Fill only where alt is missing (safe default)".
	 */
	public function test_missing_mode_preserves_existing_in_content_alt() {
		$id         = $this->freshAttachment();
		$imageModel = $this->freshImageModel( $id );
		$src        = esc_url( wp_get_attachment_url( $id ) );

		// AFTER freshImageModel(): SettingsModel was recreated.
		\wpSPIO()->settings()->ai_content_replace = 'missing';
		\wpSPIO()->settings()->aiPreserve         = 0;

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $src . '" alt="editor wrote this" />' )
		);

		$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		$args  = array(
			'aiData' => array( 'alt' => 'A mock ai alt text.', 'caption' => 0 ),
			'qItem'  => $qItem,
		);

		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace(
			array( array( 'post_id' => $post_id, 'content' => get_post( $post_id )->post_content ) ),
			$args
		);

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		$this->assertStringContainsString(
			'alt="editor wrote this"',
			$content,
			'#56 regression: missing-mode must preserve an existing editor-written in-content alt'
		);
		$this->assertStringNotContainsString(
			'alt="A mock ai alt text."',
			$content,
			'#56 regression: the AI alt must NOT be written over a non-empty in-content alt in missing mode'
		);
	}

	/**
	 * Regression for BUG #57 (fixed in dc65f17e): Updater::updatePost()
	 * used to pass UNSLASHED $content to wp_update_post(), which unslashes
	 * again — stripping the backslashes that serialize_block_attributes()
	 * stores in Gutenberg block-attribute JSON (\u0022, \u002d\u002d etc.)
	 * and corrupting every image-block post on AI replacement. The fix
	 * wraps the content with wp_slash() before wp_update_post().
	 */
	public function test_updatePost_preserves_backslashes_in_content() {
		$id  = $this->freshAttachment();
		$src = esc_url( wp_get_attachment_url( $id ) );

		// Build post content that mimics a Gutenberg image block: attribute
		// JSON with a backslash-escaped double quote (\u0022) in the block
		// comment. This is exactly the pattern serialize_block_attributes()
		// emits, and real posts store it with literal backslashes.
		$backslash_marker = 'foo \\u0022 bar'; // stored as: foo \u0022 bar
		$original_content =
			'<!-- wp:image {"caption":"' . $backslash_marker . '"} -->' .
			'<img src="' . $src . '" alt="" />' .
			'<!-- /wp:image -->';

		// wp_insert_post double-unslashes factory-injected content; go
		// straight to wpdb to make sure the literal backslash lands in DB.
		$post_id = self::factory()->post->create( array( 'post_content' => '' ) );
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => $original_content ),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			$backslash_marker,
			get_post( $post_id )->post_content,
			'Precondition: the literal backslash must be present in post_content before AI runs'
		);

		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$after = get_post( $post_id )->post_content;

		// SENTINEL: prove the AI pipeline actually rewrote the post — a
		// "backslash preserved" claim would false-pass on an untouched post.
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			$after,
			'#57 sentinel: the AI pipeline must have rewritten the <img> tag'
		);

		$this->assertStringContainsString(
			$backslash_marker,
			$after,
			'#57 regression: block-attribute backslash escapes must survive Updater::updatePost (wp_slash fix)'
		);
	}

	/**
	 * PIN #58 (MEDIUM, pinned_for_deferred_fix): replaceImageAttributes()
	 * early-returns when ai_content_replace='none', and undoAltData()
	 * routes through the SAME replaceImageAttributes() call — so a user who
	 * switches to 'none' AFTER generating AI data finds Undo silently no
	 * longer restores post content (though the Media Library alt IS reverted).
	 *
	 * FLIP-when-fixed: when the 'none' early-return is scoped away from
	 * undoAltData() (e.g. a separate reason parameter), flip the
	 * "assertStringContainsString('A mock ai alt text.')" to
	 * "assertStringNotContainsString(...)" and update the docstring.
	 */
	public function test_pin58_none_mode_silently_disables_undo_content_revert_pinned_for_deferred_fix() {
		$id  = $this->freshAttachment();
		$src = esc_url( wp_get_attachment_url( $id ) );

		// AiDataModel captures "original" from _wp_attachment_image_alt post
		// meta on first AI run — pre-populate it so revert() has something to
		// restore. The in-content alt starts EMPTY so default 'missing' mode
		// fills it (since dc65f17e missing-mode no longer overwrites non-empty
		// alts — see the #56 regression test above).
		update_post_meta( $id, '_wp_attachment_image_alt', 'original human alt' );

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $src . '" alt="" />' )
		);

		// Step 1: run AI under DEFAULT settings so the alt is written into content.
		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_id )->post_content,
			'Precondition: default settings must have rewritten in-content alt'
		);

		// Step 2: user switches ai_content_replace='none' after the fact.
		\wpSPIO()->settings()->ai_content_replace = 'none';

		// Step 3: trigger the undo path. Use undoAltData directly the same
		// way the ajax "undoAI" screen action does.
		$imageModel = $this->freshImageModel( $id );
		// freshImageModel() resets SettingsModel — re-apply after.
		\wpSPIO()->settings()->ai_content_replace = 'none';
		$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->undoAltData( $qItem );

		// Media Library alt IS reverted (that path is not gated by 'none').
		$this->assertSame(
			'original human alt',
			get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'PIN #58 sentinel: Media Library alt IS still reverted under none-mode undo'
		);

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		// PIN: the AI alt survives in post content because 'none' short-circuited undo.
		$this->assertStringContainsString(
			'A mock ai alt text.',
			$content,
			'PIN #58: switching to none-mode silently disables the undo content revert (bug). ' .
			'Flip to assertStringNotContainsString when undo is scoped away from the none early-return.'
		);
	}

	/**
	 * PIN #60 (MEDIUM, pinned_for_deferred_fix): since dc65f17e the
	 * 'missing' branch in handleReplace() only writes when the in-content
	 * alt is EMPTY — but undoAltData() routes its restore through the very
	 * same branch. After an AI run the in-content alt IS the (non-empty)
	 * AI text, so under default settings the undo content revert is now
	 * silently blocked: only the Media Library alt meta reverts. Before
	 * dc65f17e undo worked by accident (via the buggy #56 aiPreserve leg).
	 * Same root cause as PIN #58: undo must not be gated by the
	 * ai_content_replace content-write guards at all.
	 *
	 * FLIP-when-fixed: when undo bypasses the mode guards (e.g. a reason
	 * parameter forcing overwrite semantics), this test fails on the
	 * "A mock ai alt text." assertion — flip to
	 * assertStringContainsString('alt="original human alt"') and drop the
	 * _pinned_for_deferred_fix suffix.
	 */
	public function test_pin60_undo_under_default_settings_no_longer_restores_in_content_alt_pinned_for_deferred_fix() {
		$id  = $this->freshAttachment();
		$src = esc_url( wp_get_attachment_url( $id ) );

		// AiDataModel captures "original" alt from the _wp_attachment_image_alt
		// post meta on first run. Pre-populate so revert() has something to
		// restore. In-content alt starts empty so 'missing' mode fills it.
		update_post_meta( $id, '_wp_attachment_image_alt', 'original human alt' );

		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $src . '" alt="" />' )
		);

		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_id )->post_content,
			'Precondition: the AI run filled the empty in-content alt'
		);

		$imageModel = $this->freshImageModel( $id );
		// Default settings: ai_content_replace stays at 'missing'.
		$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->undoAltData( $qItem );

		// SENTINEL: the Media Library alt meta revert is NOT mode-gated and
		// proves undoAltData actually ran.
		$this->assertSame(
			'original human alt',
			get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'PIN #60 sentinel: Media Library alt must still revert under default-settings undo'
		);

		clean_post_cache( $post_id );
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			get_post( $post_id )->post_content,
			'PIN #60: default-mode undo no longer restores the in-content alt — the missing-only guard ' .
			'blocks the restore because the AI alt is non-empty. Flip to assertStringContainsString' .
			'(\'alt="original human alt"\') when undo bypasses the mode guards.'
		);
	}

	/**
	 * PIN #59 (MEDIUM, pinned_for_deferred_fix): replaceImageAttributes() (and
	 * its handleReplace() callback) rewrites post_content with NO effective
	 * regard for an active Gutenberg edit lock — a post open in an editor is
	 * rewritten behind the editor's back, and the next editor save silently
	 * wins with no conflict warning. Customer report EBUG-3b
	 * (tests/partner-plugins/bug-editor-ai-corruption.md). 3f86b55b added a
	 * wp_check_post_lock() guard but it checks the ATTACHMENT id, not the
	 * containing posts, so this pin still holds.
	 *
	 * FLIP-when-fixed: when handleReplace() skips posts with a fresh
	 * _edit_lock (regardless of lock owner — see the docblock on
	 * replaceImageAttributes() for why 3f86b55b's guard misses), the AI alt
	 * will NOT land in post_content while the lock is fresh — flip
	 *   assertStringContainsString('A mock ai alt text.', $content)
	 * to
	 *   assertStringNotContainsString('A mock ai alt text.', $content)
	 * and drop the _pinned_for_deferred_fix suffix.
	 *
	 * SENTINEL: enqueueAi + runQueueUntilEmpty is the same path that PIN #58
	 * and other AI-run tests rely on; if the pipeline silently no-ops, the
	 * "AI alt landed" assertion will fail — the pin cannot false-pass on an
	 * untouched post.
	 */
	public function test_pin59_replace_rewrites_post_content_despite_active_edit_lock_pinned_for_deferred_fix() {
		$id  = $this->freshAttachment();
		$src = esc_url( wp_get_attachment_url( $id ) );

		// In-content alt starts EMPTY: since dc65f17e default 'missing' mode
		// only fills empty alts, so this is the shape that still gets written
		// — the bug under test is that the write happens DESPITE the lock.
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<img src="' . $src . '" alt="" />' )
		);

		// Simulate an active Gutenberg editing session: create a real user
		// and write a FRESH _edit_lock meta in the same format wp_set_post_lock
		// uses ("<timestamp>:<user_id>"). Fresh timestamp = well under the
		// 150-second edit-lock window (wp_check_post_lock TTL), so
		// wp_check_post_lock($post_id) would return the user id if the SPIO
		// code ever consulted it.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );

		// Confirm the lock is live from WP's own perspective.
		$this->assertNotFalse(
			wp_check_post_lock( $post_id ),
			'Precondition: wp_check_post_lock must report a live lock for the post before the AI run.'
		);

		// Run the full AI pipeline against this attachment — replaceImageAttributes
		// is invoked from HandleSuccess() under the queue tick.
		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		clean_post_cache( $post_id );
		$content = get_post( $post_id )->post_content;

		// PIN: current behaviour — the AI alt landed in post_content even though
		// the post was under an active edit lock. When Bas adds the lock skip,
		// this assertion will fail and must be flipped (see docblock).
		$this->assertStringContainsString(
			'alt="A mock ai alt text."',
			$content,
			'PIN #59: post_content was rewritten by AI despite an active _edit_lock (bug). ' .
			'Flip to assertStringNotContainsString when replaceImageAttributes() skips posts ' .
			'with a fresh _edit_lock (EBUG-3b).'
		);

		// Sentinel companion: the lock was still live when the write happened.
		$this->assertNotFalse(
			wp_check_post_lock( $post_id ),
			'PIN #59 sentinel: the edit lock must still be live after the AI run — the rewrite happened under lock.'
		);
	}

	/**
	 * EBUG-1 (customer report tests/partner-plugins/bug-editor-ai-corruption.md):
	 * when a generated field is disabled in settings, the stored AiDataModel
	 * generated payload holds an integer status for that field (F_STATUS_EXCLUDESETTING
	 * = -3). That integer is what OptimizeAiController::formatGenerated() then
	 * normalises and hands to the ajax response — see the payload-contract
	 * tests in tests/Controller/test-OptimizeAiController.php.
	 *
	 * Together with those unit tests this pins the end-to-end contract that
	 * "gen_caption off → generated['caption'] is int (never a string, never
	 * null)", so the client-side allowlist guard has something to filter.
	 */
	public function test_gen_caption_disabled_stores_integer_status_for_caption() {
		$id = $this->freshAttachment();

		// Alt on, caption OFF — the API will not be asked for caption, so
		// AiController::handleSuccess() will backfill F_STATUS_EXCLUDESETTING
		// from the returndatalist status.
		\wpSPIO()->settings()->ai_gen_alt     = 1;
		\wpSPIO()->settings()->ai_gen_caption = 0;

		$this->enqueueAi( $id );
		$this->runQueueUntilEmpty();

		AiDataModel::flushModelCache( $id );
		$aiModel   = AiDataModel::getModelByAttachment( $id, 'media' );
		$generated = $aiModel->getGeneratedData();

		$this->assertArrayHasKey( 'caption', $generated, 'A disabled-in-settings field must still occupy the generated key.' );
		$this->assertIsInt(
			$generated['caption'],
			'gen_caption=off must leave caption as an integer status (F_STATUS_EXCLUDESETTING) in the generated payload — '
			. 'the client-side allowlist in screen-media.js UpdateGutenBerg (ea764111) is the corruption guard.'
		);
	}

	/**
	 * Contrast case, updated for dc65f17e (#56 fix): aiPreserve no longer
	 * has any effect on the alt branch — in 'missing' mode an existing
	 * in-content alt is preserved even with aiPreserve OFF. (aiPreserve
	 * still gates the caption-overwrite branch only.)
	 */
	public function test_missing_mode_preserves_existing_alt_even_with_aipreserve_off() {
		$id         = $this->freshAttachment();
		$imageModel = $this->freshImageModel( $id );
		$src        = esc_url( wp_get_attachment_url( $id ) );

		\wpSPIO()->settings()->aiPreserve = 0;

		$post_human = self::factory()->post->create( array( 'post_content' => '<img src="' . $src . '" alt="human written alt" />' ) );

		$qItem = \ShortPixel\Controller\Queue\QueueItems::getImageItem( $imageModel );
		$args  = array(
			'aiData' => array( 'alt' => 'A mock ai alt text.', 'caption' => 0 ),
			'qItem'  => $qItem,
		);

		\ShortPixel\Controller\Optimizer\OptimizeAiController::getInstance()->handleReplace(
			array( array( 'post_id' => $post_human, 'content' => get_post( $post_human )->post_content ) ),
			$args
		);

		clean_post_cache( $post_human );
		$this->assertStringContainsString(
			'alt="human written alt"',
			get_post( $post_human )->post_content,
			'#56 regression: missing mode must preserve the existing in-content alt even with aiPreserve off.'
		);
	}
}
