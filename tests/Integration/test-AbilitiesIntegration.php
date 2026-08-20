<?php
/**
 * MCP Abilities end-to-end integration tests (mocked ShortPixel API).
 *
 * Drives the ability execute callbacks the way an MCP agent would — enqueue
 * through the ability, advance work with shortpixel/run-queue, verify final
 * image/DB state — against the REAL queue + optimizer pipeline and the
 * MockShortPixelApi HTTP interceptor.
 *
 * PINNED tests document bugs found on the mcp branch (Calin's list — these
 * assert today's BUGGY behaviour and FAIL when the bug is fixed, so the
 * assertion must then be flipped):
 *  - pin C1: get-queue-status casts locale-formatted stats to int —
 *    QueueController::getStartupData() runs numberFormatStats(), so any
 *    count >= 1000 becomes e.g. "1,201" and (int) truncates it to 1.
 *  - pin C2: bulk-generate-ai-seo permanently persists autoAIBulk=true as a
 *    side effect (records the previous value but never restores it).
 *    bulk-optimize with do_ai=true does the same.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Abilities\BulkGenerateAiSeoAbility;
use ShortPixel\Controller\Abilities\BulkOptimizeAbility;
use ShortPixel\Controller\Abilities\BulkRestoreAbility;
use ShortPixel\Controller\Abilities\GenerateAiSeoAbility;
use ShortPixel\Controller\Abilities\GetAiSeoStatusAbility;
use ShortPixel\Controller\Abilities\GetMediaStatusAbility;
use ShortPixel\Controller\Abilities\GetQueueStatusAbility;
use ShortPixel\Controller\Abilities\GetQuotaAbility;
use ShortPixel\Controller\Abilities\GetStatsAbility;
use ShortPixel\Controller\Abilities\OptimizeMediaAbility;
use ShortPixel\Controller\Abilities\RestoreMediaAbility;
use ShortPixel\Controller\Abilities\RunQueueAbility;
use ShortPixel\Controller\Abilities\UndoAiSeoAbility;
use ShortPixel\Controller\StatsController;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Image\ImageModel;

class AbilitiesIntegrationTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		parent::set_up();

		$settings                  = \wpSPIO()->settings();
		$settings->enable_ai       = 1;
		$settings->ai_gen_alt      = 1;
		$settings->ai_gen_caption  = 1;
		// The rename path (Replacer2, file moves) is out of scope here.
		$settings->ai_gen_filename = 0;
		$settings->autoAIBulk      = 0;

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

	/**
	 * Advance the queues the way an MCP agent would: repeated
	 * shortpixel/run-queue calls with ticks=1 (single-tick calls skip
	 * QueueRunner's inter-tick sleep, keeping the suite fast).
	 */
	private function runQueueViaAbility( bool $bulk = false, int $maxCalls = 30 ): array {
		$last = array();

		for ( $i = 0; $i < $maxCalls; $i++ ) {
			$last = RunQueueAbility::execute( array( 'ticks' => 1, 'bulk' => $bulk ) );

			if ( ! empty( $last['processing']['is_error'] ) ) {
				break;
			}
			if ( 'queues_empty' === $last['processing']['stopped_reason'] ) {
				break;
			}

			// ShortQ only retries IN_PROCESS items after process_timeout
			// (10s wall clock); backdate so the next call picks them up
			// immediately instead of the test sleeping.
			$this->backdateQueueItems();
		}

		return $last;
	}

	private function freshImageModel( int $attachment_id ) {
		$this->resetPluginSingletons();
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
	}

	// ------------------------------------------------------------------
	// optimize-media / run-queue / get-media-status
	// ------------------------------------------------------------------

	/** The full agent golden path in ONE call: enqueue + synchronous processing. */
	public function test_optimize_media_processes_synchronously_end_to_end() {
		$attachment_id = $this->freshAttachment();

		$result = OptimizeMediaAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertFalse( $result['error'], 'optimize-media must succeed: ' . print_r( $result, true ) );
		$this->assertTrue( $result['is_optimized'], 'process=true must leave the image optimized within the request' );
		$this->assertGreaterThan( 0, $result['improvement_percent'] );
		$this->assertGreaterThan( 0, $result['bytes_saved'] );

		$status = GetMediaStatusAbility::execute( array( 'id' => $attachment_id ) );
		$this->assertTrue( $status['is_optimized'] );
		$this->assertSame( 'success', $status['status'] );
		$this->assertGreaterThan( 0, $status['original_size'] );
		$this->assertGreaterThan( 0, $status['compressed_size'] );
		$this->assertTrue( $status['has_backup'], 'backupImages=1 baseline must produce a backup' );
	}

	public function test_optimize_media_honours_the_compression_override() {
		$attachment_id = $this->freshAttachment();

		$result = OptimizeMediaAbility::execute( array(
			'id'          => $attachment_id,
			'compression' => 'glossy',
		) );

		$this->assertFalse( $result['error'] );
		$this->assertTrue( $result['is_optimized'] );

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertEquals(
			ImageModel::COMPRESSION_GLOSSY,
			$imageModel->getMeta( 'compressionType' ),
			'The compression=glossy override must reach the item meta'
		);
	}

	public function test_optimize_media_rejects_an_invalid_compression_value() {
		$attachment_id = $this->freshAttachment();

		$result = OptimizeMediaAbility::execute( array(
			'id'          => $attachment_id,
			'compression' => 'ultra',
		) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( '"lossy", "glossy" or "lossless"', $result['message'] );
	}

	/** The two-step agent flow: enqueue-only, then drive with run-queue. */
	public function test_optimize_media_process_false_enqueues_and_run_queue_finishes() {
		$attachment_id = $this->freshAttachment();

		$result = OptimizeMediaAbility::execute( array(
			'id'      => $attachment_id,
			'process' => false,
		) );

		$this->assertFalse( $result['error'] );
		$this->assertSame( 'processing_disabled_by_caller', $result['processing']['stopped_reason'] );

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertFalse( $imageModel->isOptimized(), 'process=false must not optimize inside the call' );

		$run = $this->runQueueViaAbility();

		$this->assertFalse( $run['error'], 'run-queue must not error: ' . print_r( $run, true ) );
		$this->assertSame( 'queues_empty', $run['processing']['stopped_reason'] );
		$this->assertArrayHasKey( 'queue_status', $run, 'run-queue must attach a queue snapshot for the agent' );

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertTrue( $imageModel->isOptimized(), 'run-queue must finish the enqueued optimization' );
	}

	// ------------------------------------------------------------------
	// restore-media
	// ------------------------------------------------------------------

	public function test_restore_media_restores_an_optimized_image_end_to_end() {
		$attachment_id = $this->freshAttachment();
		$this->optimizeAttachment( $attachment_id );

		$result = RestoreMediaAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertFalse( $result['error'], 'restore-media must succeed: ' . print_r( $result, true ) );
		$this->assertTrue( $result['is_restored'] );
		$this->assertFalse( $result['is_optimized'] );

		$imageModel = $this->freshImageModel( $attachment_id );
		$this->assertFalse( $imageModel->isOptimized(), 'The image must be back to its unoptimized state' );
	}

	// ------------------------------------------------------------------
	// get-queue-status
	// ------------------------------------------------------------------

	public function test_get_queue_status_reports_waiting_items() {
		$id_one = $this->freshAttachment();
		$id_two = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		OptimizeMediaAbility::execute( array( 'id' => $id_one, 'process' => false ) );
		OptimizeMediaAbility::execute( array( 'id' => $id_two, 'process' => false ) );

		$status = GetQueueStatusAbility::execute();

		$this->assertFalse( $status['is_bulk'] );
		$this->assertArrayHasKey( 'media', $status['queues'] );
		$this->assertGreaterThanOrEqual( 2, $status['queues']['media']['in_queue'] );
		$this->assertFalse( $status['queues']['media']['is_finished'] );
	}

	/**
	 * PIN C1 (Calin #1 in the session report): queue counts >= 1000 collapse.
	 *
	 * QueueController::getStartupData() pipes every stat through
	 * numberFormatStats() → UiHelper::formatNumber() → number_format_i18n(),
	 * so 1201 waiting items arrive at the ability as the STRING "1,201" —
	 * and GetQueueStatusAbility's (int) cast truncates that to 1. An MCP
	 * agent watching a big bulk would believe the queue is nearly empty.
	 *
	 * FLIP when fixed (read raw stats before formatting, or strip the
	 * locale formatting before casting): in_queue must equal 1201.
	 */
	public function test_pinC1_get_queue_status_truncates_formatted_counts_at_1000() {
		global $wpdb;

		$attachment_id = $this->freshAttachment();
		OptimizeMediaAbility::execute( array( 'id' => $attachment_id, 'process' => false ) );

		$table    = $wpdb->prefix . 'shortpixel_queue';
		$template = $wpdb->get_row( "SELECT queue_name, plugin_slug, status, value FROM {$table} LIMIT 1", ARRAY_A );
		$this->assertNotEmpty( $template, 'The enqueued item must produce a queue row to clone' );

		// Clone the real waiting row up to 1201 total items.
		for ( $i = 0; $i < 1200; $i++ ) {
			$wpdb->insert( $table, array(
				'queue_name'  => $template['queue_name'],
				'plugin_slug' => $template['plugin_slug'],
				'status'      => $template['status'],
				'value'       => $template['value'],
				'item_id'     => 900000 + $i,
				'item_count'  => 1,
				'list_order'  => 100 + $i,
				'created'     => current_time( 'mysql' ),
				'updated'     => current_time( 'mysql' ),
			) );
		}

		$raw_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %d", (int) $template['status'] )
		);
		$this->assertSame( 1201, $raw_count, 'Seeding must yield 1201 waiting rows' );

		$status = GetQueueStatusAbility::execute();

		$this->assertSame(
			1,
			$status['queues']['media']['in_queue'],
			'PIN C1: (int) on the locale-formatted "1,201" truncates to 1. When getStartupData stats are read raw (bug fixed), FLIP this to assertSame(1201, ...).'
		);
	}

	// ------------------------------------------------------------------
	// bulk-optimize / bulk-restore
	// ------------------------------------------------------------------

	public function test_bulk_optimize_processes_all_pending_images() {
		$id_one = $this->freshAttachment();
		$id_two = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$result = BulkOptimizeAbility::execute( array( 'confirm' => true, 'process' => false ) );

		$this->assertFalse( $result['error'], 'bulk-optimize must start: ' . print_r( $result, true ) );
		$this->assertSame( array( 'media', 'custom' ), $result['queues'] );
		$this->assertArrayHasKey( 'media', $result['started'] );

		$run = $this->runQueueViaAbility( true );
		$this->assertSame( 'queues_empty', $run['processing']['stopped_reason'], 'Bulk must run to completion: ' . print_r( $run, true ) );

		foreach ( array( $id_one, $id_two ) as $attachment_id ) {
			$imageModel = $this->freshImageModel( $attachment_id );
			$this->assertTrue( $imageModel->isOptimized(), "Bulk optimize must process attachment $attachment_id" );
		}
	}

	public function test_bulk_restore_deoptimizes_all_optimized_images() {
		$id_one = $this->freshAttachment();
		$this->optimizeAttachment( $id_one );
		$id_two = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();
		$this->optimizeAttachment( $id_two );

		$result = BulkRestoreAbility::execute( array( 'confirm' => true, 'process' => false ) );
		$this->assertFalse( $result['error'] );

		$run = $this->runQueueViaAbility( true );
		$this->assertSame( 'queues_empty', $run['processing']['stopped_reason'], 'Bulk restore must run to completion: ' . print_r( $run, true ) );

		foreach ( array( $id_one, $id_two ) as $attachment_id ) {
			$imageModel = $this->freshImageModel( $attachment_id );
			$this->assertFalse( $imageModel->isOptimized(), "Bulk restore must de-optimize attachment $attachment_id" );
		}
	}

	// ------------------------------------------------------------------
	// AI SEO abilities
	// ------------------------------------------------------------------

	public function test_generate_ai_seo_lands_generated_metadata_end_to_end() {
		$attachment_id = $this->freshAttachment();

		$result = GenerateAiSeoAbility::execute( array( 'id' => $attachment_id, 'process' => false ) );
		$this->assertFalse( $result['error'], 'generate-ai-seo must enqueue: ' . print_r( $result, true ) );

		$run = $this->runQueueViaAbility();
		$this->assertSame( 'queues_empty', $run['processing']['stopped_reason'] );

		// Mock AI backend returns "a mock ai alt text"; formatting adds ucfirst + period.
		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'The generated alt must land in the WP alt meta'
		);

		AiDataModel::flushModelCache( $attachment_id );
		$status = GetAiSeoStatusAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertFalse( $status['error'] );
		$this->assertTrue( $status['has_generated'] );
		$this->assertSame( 'generated', $status['status'] );
		$this->assertSame( 'A mock ai alt text.', $status['generated']['alt'] );
	}

	public function test_undo_ai_seo_reverts_generated_metadata() {
		$attachment_id = $this->freshAttachment();

		GenerateAiSeoAbility::execute( array( 'id' => $attachment_id, 'process' => false ) );
		$this->runQueueViaAbility();

		$this->assertSame( 'A mock ai alt text.', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		$result = UndoAiSeoAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertFalse( $result['error'], 'undo-ai-seo must succeed: ' . print_r( $result, true ) );
		$this->assertSame(
			'',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Undo must revert the alt to its (empty) pre-generation value'
		);

		$post = get_post( $attachment_id );
		$this->assertSame( '', $post->post_excerpt, 'Undo must revert the caption (post_excerpt)' );
	}

	/**
	 * The gen_* field overrides advertised in the generate-ai-seo input
	 * schema really reach the AI request: GenerateAiSeoAbility maps them
	 * to ai_gen_* queue args, and AiDataModel::getOptimizeData() merges
	 * those over the saved settings (UtilHelper::getAiSettings($params)),
	 * so gen_caption=false drops the caption job from the add-url payload
	 * even while the ai_gen_caption SETTING is on.
	 */
	public function test_generate_ai_seo_field_overrides_reach_the_api_payload() {
		$attachment_id = $this->freshAttachment();

		$result = GenerateAiSeoAbility::execute( array(
			'id'          => $attachment_id,
			'gen_caption' => false,
			'process'     => false,
		) );
		$this->assertFalse( $result['error'] );

		$this->runQueueViaAbility();

		$addRequests = array_values( array_filter( $this->api->requests, function ( $r ) {
			return false !== strpos( $r['url'], 'add-url.php' );
		} ) );
		$this->assertCount( 1, $addRequests );

		$payload = $addRequests[0]['request'];
		$this->assertArrayHasKey( 'alt', $payload, 'ai_gen_alt=1 keeps the alt job in — sanity check' );
		$this->assertArrayNotHasKey(
			'caption',
			$payload,
			'gen_caption=false must drop the caption job from the payload despite ai_gen_caption=1 in settings'
		);
	}

	/**
	 * PIN C2 (Calin #2 in the session report): bulk-generate-ai-seo flips
	 * the autoAIBulk SETTING to true so Queue::prepare() picks up AI items,
	 * and never restores it — SettingsModel persists on shutdown, so a
	 * one-shot MCP call permanently changes site behaviour (every future
	 * MANUAL bulk will silently generate AI SEO and consume AI credits).
	 * The response's auto_ai_bulk_previous field suggests a restore was
	 * intended. BulkOptimizeAbility with do_ai=true has the same issue.
	 *
	 * FLIP when fixed (setting restored after the bulk, or the prepare
	 * filter scoped to the request): autoAIBulk must still be falsy here.
	 */
	public function test_pinC2_bulk_generate_ai_seo_permanently_persists_autoaibulk() {
		$this->freshAttachment();

		$this->assertEmpty( \wpSPIO()->settings()->autoAIBulk, 'Baseline: autoAIBulk off' );

		$result = BulkGenerateAiSeoAbility::execute( array( 'confirm' => true, 'process' => false ) );

		$this->assertFalse( $result['error'] );
		$this->assertFalse( $result['auto_ai_bulk_previous'], 'The ability itself records that the setting was off before' );

		$this->assertTrue(
			(bool) \wpSPIO()->settings()->autoAIBulk,
			'PIN C2: the one-shot ability call leaves autoAIBulk=true in SettingsModel (persisted on shutdown). When the setting is restored/scoped (bug fixed), FLIP this to assertEmpty.'
		);
	}

	// ------------------------------------------------------------------
	// get-stats / get-quota
	// ------------------------------------------------------------------

	public function test_get_stats_reflects_a_completed_optimization() {
		$attachment_id = $this->freshAttachment();
		$this->optimizeAttachment( $attachment_id );

		StatsController::getInstance()->reset();
		$stats = GetStatsAbility::execute();

		$this->assertGreaterThanOrEqual( 1, $stats['media_library']['items_optimized'] );
		$this->assertGreaterThanOrEqual( 1, $stats['totals']['images_optimized'] );
	}

	public function test_get_quota_reports_the_mock_account() {
		$quota = GetQuotaAbility::execute();

		$this->assertFalse( $quota['quota_exceeded'], 'The mock account has healthy quota' );
		$this->assertIsInt( $quota['monthly']['remaining'] );
		$this->assertIsInt( $quota['ai']['remaining'] );
		$this->assertGreaterThan( 0, $quota['total']['total'] );
	}

	// ------------------------------------------------------------------
	// run-queue guards
	// ------------------------------------------------------------------

	public function test_run_queue_requires_a_verified_key() {
		update_option( 'spio_key', array(
			'apiKey'      => '',
			'verifiedKey' => false,
			'apiKeyTried' => '',
		) );
		\wpSPIO()->settings()->redirectedSettings = 1;
		$this->resetPluginSingletons();

		$result = RunQueueAbility::execute( array( 'ticks' => 1 ) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'API key is not verified', $result['message'] );
	}
}
