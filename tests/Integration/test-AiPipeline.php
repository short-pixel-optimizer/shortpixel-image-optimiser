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
 *    — in single-pass AND multi-pass (thumbnails) optimizations. Caveat:
 *    when enqueue + processing share one PHP request, Queue's stale
 *    $isInQueue cache loses the chain (pinned test below).
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

	/**
	 * Clear Queue's static $isInQueue status cache.
	 *
	 * The cache is populated by the isItemInQueue() call of the SECOND
	 * (appending) addItemToQueue() and never invalidated by itemDone() —
	 * see the pinned stale-cache test below. Whole-process test runs need
	 * this flush between the append and the queue run so the chained
	 * re-enqueue sees the real (done) row state, like a fresh cron-tick
	 * request would.
	 */
	private function flushQueueStatusCache(): void {
		$prop = new ReflectionProperty( \ShortPixel\Controller\Queue\Queue::class, 'isInQueue' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
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
		$this->assertSame( wp_get_attachment_url( $attachment_id ), $payload['url'] );
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

		$this->flushQueueStatusCache();
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

		$this->flushQueueStatusCache();
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

		$this->flushQueueStatusCache();
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
	 * PINNED (bug, found 2026-07-19): Queue::$isInQueue (Queue.php:40) is a
	 * static per-request status cache populated by isItemInQueue() when an
	 * action is APPENDED to a queued item. dropItem() invalidates it
	 * (Queue.php:230-233) but itemDone() (Queue.php:1130-1134) does NOT —
	 * so when the enqueue, the append and the processing all happen inside
	 * ONE request (ajax "process now" flows, WP-CLI, tests), the chained
	 * re-enqueue from finishItemProcess() reads the stale WAITING status,
	 * lands in the isItemInQueue append/skip branch against the already-done
	 * row, and the chained action is silently lost. One-line fix in
	 * Queue::itemDone(): `unset(self::$isInQueue[$item->item_id]);`
	 * (mirroring dropItem). Multi-request flows (cron ticks) are unaffected
	 * because the cache dies with the request.
	 *
	 * This pins the BUGGY behaviour so the suite stays green. When the fix
	 * lands this test FAILS — then flip it to expect the generated alt (and
	 * drop the flushQueueStatusCache() workaround from the two chaining
	 * tests above).
	 */
	public function test_same_request_chain_is_lost_to_stale_queue_cache_pinned() {
		\wpSPIO()->settings()->processThumbnails = 0;
		$attachment_id                           = $this->freshAttachment();

		$this->enqueueOptimize( $attachment_id );
		$aiResult = $this->enqueueAi( $attachment_id ); // populates the stale cache entry
		$this->assertFalse( $aiResult->is_done, 'Precondition: the AI action WAS appended to the queued item' );

		// NO flushQueueStatusCache() here — that is the point.
		$this->runQueueUntilEmpty( 40 );

		$this->assertTrue( $this->freshImageModel( $attachment_id )->isOptimized(), 'The optimization leg completes' );
		$this->assertSame(
			'',
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'itemDone() appears to now invalidate the isInQueue cache — bug FIXED, flip this pinned test and drop the flush workaround.'
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
}
