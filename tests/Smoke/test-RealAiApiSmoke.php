<?php
/**
 * Real-AI-API smoke tests — talk to the LIVE ShortPixel AI backend
 * (capi-gpt.shortpixel.com) and the live api-status endpoint.
 *
 * The integration suite mocks add-url.php/get-url.php; this suite removes
 * the mock and runs the REAL two-phase AI flow (submit → poll → store),
 * catching contract drift: changed response fields, new status sentinels,
 * JWT issuing behaviour.
 *
 * Request bodies and headers are recorded as well as responses, so the
 * suite asserts BOTH halves of the contract: what the plugin sends and what
 * the backend answers. A field the plugin stops sending fails here just
 * like a field the backend stops returning.
 *
 * Endpoint note: production uses capi-gpt.shortpixel.com. This suite
 * reflection-overrides AiController::$main_url to the production endpoint
 * (pointAiApiAtProduction()), so it never runs against a dev endpoint.
 * It is a no-op while the code already points at capi-gpt.
 *
 * Requirements + costs:
 *   - SHORTPIXEL_SMOKE_KEY env var must hold a valid 20-char API key with
 *     AI credits; without it every test SKIPS. If the account is out of
 *     AI credits (add-url answers status 3) the generating tests SKIP
 *     rather than fail.
 *   - AI credits per run: alt end-to-end 1, every generator 1, language 1,
 *     context/limits/affixes 1, second request 2 (two generations) = 6.
 *     The wrong-key, api-status and unreachable-image tests cost none.
 *   - The AI backend fetches the image by URL and cannot reach the local
 *     test install, so wp_get_attachment_url is remapped to the committed
 *     fixture's public raw.githubusercontent.com URL on the `master`
 *     branch. It must be a long-lived public branch: a feature branch that
 *     is later deleted turns into a 404 and the backend answers "Failed to
 *     download and save image".
 *
 * Run: bin/test.sh --smoke
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\QuotaController;
use ShortPixel\Model\AiDataModel;

class RealAiApiSmokeTest extends SPIO_IntegrationTestCase {

	private const FIXTURE_RAW_BASE = 'https://raw.githubusercontent.com/short-pixel-optimizer/shortpixel-image-optimiser/master/tests/fixtures/';

	private const PRODUCTION_AI_URL = 'https://capi-gpt.shortpixel.com/';

	/** @var array Recorded live HTTP exchanges with the ShortPixel APIs (request + response). */
	private $apiExchanges = array();

	public function set_up() {
		$key = getenv( 'SHORTPIXEL_SMOKE_KEY' );
		if ( false === $key || 20 !== strlen( trim( $key ) ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_SMOKE_KEY not set to a 20-char ShortPixel API key — skipping real-AI-API smoke test.' );
		}

		parent::set_up();

		MockShortPixelApi::unregister();

		update_option(
			'spio_key',
			array(
				'apiKey'      => trim( $key ),
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);
		$this->resetPluginSingletons();

		$settings                 = \wpSPIO()->settings();
		$settings->quotaExceeded  = 0;
		$settings->ai_gen_alt     = 1;
		$settings->ai_gen_caption = 1;
		// Filename generation is out of scope (except where a test opts in).
		$settings->ai_gen_filename   = 0;
		$settings->processThumbnails = 0;

		// A fresh JWT must be negotiated per test run.
		delete_transient( 'spio_ai_jwt_token' );

		add_filter( 'wp_get_attachment_url', array( $this, 'remapToPublicFixtureUrl' ) );

		$this->apiExchanges = array();
		add_filter( 'http_response', array( $this, 'recordApiExchange' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'wp_get_attachment_url', array( $this, 'remapToPublicFixtureUrl' ) );
		remove_filter( 'http_response', array( $this, 'recordApiExchange' ), 10 );
		parent::tear_down();
	}

	/**
	 * Records both halves of every exchange with shortpixel.com: the request
	 * body (JSON-decoded when it is JSON) and headers, and the response body.
	 */
	public function recordApiExchange( $response, $parsed_args, $url ) {
		if ( false !== strpos( $url, 'shortpixel.com' ) ) {
			$body                 = wp_remote_retrieve_body( $response );
			$sent                 = isset( $parsed_args['body'] ) ? $parsed_args['body'] : null;
			$this->apiExchanges[] = array(
				'endpoint' => basename( (string) parse_url( $url, PHP_URL_PATH ) ),
				'url'      => (string) $url,
				'headers'  => isset( $parsed_args['headers'] ) ? (array) $parsed_args['headers'] : array(),
				'request'  => is_string( $sent ) ? ( json_decode( $sent, true ) ?? $sent ) : $sent,
				'response' => is_string( $body ) ? substr( $body, 0, 2000 ) : $body,
			);
		}
		return $response;
	}

	/** Decoded JSON of the LAST response recorded for an endpoint, or null. */
	private function lastResponse( string $endpoint ) {
		foreach ( array_reverse( $this->apiExchanges ) as $exchange ) {
			if ( $endpoint === $exchange['endpoint'] ) {
				return json_decode( (string) $exchange['response'], true );
			}
		}
		return null;
	}

	/** Every recorded exchange for an endpoint, oldest first. */
	private function exchangesFor( string $endpoint ): array {
		return array_values(
			array_filter(
				$this->apiExchanges,
				function ( $exchange ) use ( $endpoint ) {
					return $endpoint === $exchange['endpoint'];
				}
			)
		);
	}

	/** The AI backend fetches by URL — hand it the committed fixture bytes. */
	public function remapToPublicFixtureUrl( $url ) {
		$name = basename( (string) parse_url( $url, PHP_URL_PATH ) );
		$name = preg_replace( '/-\d+(\.\w+)$/', '$1', $name );
		return self::FIXTURE_RAW_BASE . $name;
	}

	private function explainAiState(): string {
		if ( empty( $this->apiExchanges ) ) {
			return "\n--- No API responses recorded — the request never reached shortpixel.com. ---";
		}
		$out = "\n--- AI API responses ---";
		foreach ( $this->apiExchanges as $exchange ) {
			$out .= "\n[" . $exchange['endpoint'] . '] ' . trim( (string) $exchange['response'] );
		}
		return $out;
	}

	/**
	 * Point the AiController singleton at the production endpoint (see the
	 * file docblock). Must run AFTER the last resetPluginSingletons() call,
	 * which drops the RequestManager instance map.
	 */
	private function pointAiApiAtProduction(): void {
		$controller = AiController::getInstance();
		$prop       = new ReflectionProperty( AiController::class, 'main_url' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, self::PRODUCTION_AI_URL );
	}

	/**
	 * Queue's static $isInQueue cache is not invalidated by itemDone() —
	 * within this single PHP process that would strand the chained
	 * retrieveAlt action (see the pinned test in test-AiPipeline.php).
	 */
	private function flushQueueStatusCache(): void {
		$prop = new ReflectionProperty( \ShortPixel\Controller\Queue\Queue::class, 'isInQueue' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	/** Real seconds between ticks: the AI result is generated server-side. */
	private function runQueueAgainstRealAiApi( int $maxTicks = 30 ): void {
		$queueController = new QueueController();

		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$this->flushQueueStatusCache();
			$queueController->processQueue( array( 'media', 'custom' ) );

			if ( ! $this->queueHasWork() ) {
				return;
			}

			$this->backdateQueueItems();
			sleep( 3 );
		}

		$this->fail( "Queue still has work after $maxTicks real-AI-API ticks — item stuck or API unreachable." . $this->explainAiState() );
	}

	private function sawAiOverQuota(): bool {
		foreach ( $this->apiExchanges as $exchange ) {
			if ( 'add-url.php' === $exchange['endpoint'] ) {
				$decoded = json_decode( (string) $exchange['response'] );
				if ( is_object( $decoded ) && isset( $decoded->status ) && 3 === (int) $decoded->status ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Enqueue a manual requestAlt (no recent_upload: the usage guard runs). */
	private function enqueueAlt( int $id ) {
		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		return ( new QueueController() )->addItemToQueue( $imageModel, array( 'action' => 'requestAlt' ) );
	}

	/** Enqueue, drain against the live API, skip when out of AI credits. */
	private function generateLive( int $id ): void {
		// Drop the auto-enqueued optimize item: these tests are AI-only.
		$this->purgeQueueTable();
		$this->pointAiApiAtProduction();

		$result = $this->enqueueAlt( $id );
		$this->assertFalse( $result->is_error, 'AI enqueue must succeed: ' . print_r( $result->message ?? '', true ) );

		$this->runQueueAgainstRealAiApi();

		if ( $this->sawAiOverQuota() ) {
			$this->markTestSkipped( 'The smoke account is out of AI credits (add-url status 3) — cannot verify generation.' . $this->explainAiState() );
		}
	}

	// -------------------------------------------------------------------
	// Smoke
	// -------------------------------------------------------------------

	/**
	 * Full two-phase roundtrip against the live AI backend: add-url must
	 * answer a remote id + JWT, get-url must eventually deliver the result,
	 * and the generated alt/caption must land in WordPress. Costs 1 AI
	 * credit.
	 */
	public function test_real_ai_api_generates_alt_end_to_end() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		// Drop the auto-enqueued optimize item: this test is AI-only.
		$this->purgeQueueTable();

		$this->pointAiApiAtProduction();

		$result = $this->enqueueAlt( $id );
		$this->assertFalse( $result->is_error, 'AI enqueue must succeed: ' . print_r( $result->message ?? '', true ) );

		$this->runQueueAgainstRealAiApi();

		if ( $this->sawAiOverQuota() ) {
			$this->markTestSkipped( 'The smoke account is out of AI credits (add-url status 3) — cannot verify generation.' . $this->explainAiState() );
		}

		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$this->assertNotSame( '', (string) $alt, 'The live AI backend must produce a non-empty alt text.' . $this->explainAiState() );

		$post = get_post( $id );
		$this->assertNotSame( '', (string) $post->post_excerpt, 'ai_gen_caption=1 must yield a caption in post_excerpt.' . $this->explainAiState() );

		$aiModel = AiDataModel::getModelByAttachment( $id, 'media' );
		$this->assertSame( AiDataModel::AI_STATUS_GENERATED, $aiModel->getStatus(), 'aipostmeta row must be marked GENERATED.' . $this->explainAiState() );

		$this->assertNotEmpty( get_transient( 'spio_ai_jwt_token' ), 'The live add-url exchange must have issued a JWT (cached in the spio_ai_jwt_token transient).' );
	}

	/**
	 * With a syntactically valid but WRONG key the AI backend must refuse
	 * the submission and never generate anything; the queue must drain
	 * (item errors out, nothing stuck). Costs no credits.
	 */
	public function test_real_ai_api_rejects_wrong_key_without_generating() {
		update_option(
			'spio_key',
			array(
				'apiKey'      => 'SPIOWRONGKEY00000000',
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->quotaExceeded = 0;
		\wpSPIO()->settings()->ai_gen_alt    = 1;
		delete_transient( 'spio_ai_jwt_token' );

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$this->pointAiApiAtProduction();

		$this->enqueueAlt( $id );

		$this->runQueueAgainstRealAiApi( 10 );

		$this->assertNotEmpty( $this->apiExchanges, 'The submission must have reached the live AI endpoint.' );
		$this->assertSame(
			'',
			(string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'A wrong key must never yield generated alt text.' . $this->explainAiState()
		);
	}

	/**
	 * Quota / key contract (api-status.php), no credits. SPIO maps three
	 * credit pools out of this answer: the optimisation credits (monthly +
	 * one-time), which also decide quotaExceeded, and the AI credits
	 * (CaptionsCalls*, a separate pool). PlanType decides AIUnlimited (the
	 * bulk summary's AI banner) and DateSubscription the days-to-reset
	 * counter. A renamed or retyped field would break the plugin without
	 * any optimisation or AI request failing.
	 */
	public function test_live_api_status_reports_the_quota_fields_the_plugin_reads() {
		$quotaController = QuotaController::getInstance();
		$quotaController->forceCheckRemoteQuota();
		$quota = $quotaController->getQuota();

		$raw = $this->lastResponse( 'api-status.php' );
		$this->assertIsArray( $raw, 'api-status.php must answer JSON.' . $this->explainAiState() );
		$this->assertSame( 2, (int) ( $raw['Status']['Code'] ?? 0 ), 'A valid key must validate with Status.Code 2.' . $this->explainAiState() );

		$this->assertArrayHasKey( 'PlanType', $raw, 'PlanType decides AIUnlimited (value "Unlimited AI").' . $this->explainAiState() );
		$aiUnlimitedPlan = ( 'Unlimited AI' === $raw['PlanType'] );

		$numericFields = array(
			'APICallsQuota',
			'APICallsMade',
			'APICallsQuotaOneTime',
			'APICallsMadeOneTime',
			'CaptionsCallsQuota',
			'CaptionsCallsMade',
			'CaptionsCallsRemaining',
		);
		// An Unlimited AI plan has no AI quota to report: the API answers
		// null for these two. The plugin maps null to 0 and every consumer
		// checks AIUnlimited before reading the AI numbers.
		$nullOnUnlimitedAi = array( 'CaptionsCallsQuota', 'CaptionsCallsRemaining' );
		foreach ( $numericFields as $field ) {
			$this->assertArrayHasKey( $field, $raw, "$field must be present (credit accounting)." . $this->explainAiState() );
			if ( $aiUnlimitedPlan && in_array( $field, $nullOnUnlimitedAi, true ) && null === $raw[ $field ] ) {
				continue;
			}
			$this->assertTrue( is_numeric( $raw[ $field ] ), "$field must be numeric" . ( in_array( $field, $nullOnUnlimitedAi, true ) ? ' (null is accepted only on the Unlimited AI plan)' : '' ) . '.' . $this->explainAiState() );
		}
		$this->assertArrayHasKey( 'DateSubscription', $raw, 'DateSubscription feeds the days-to-reset counter.' . $this->explainAiState() );

		// The plugin-side mapping of the same answer: each numeric field is
		// normalised with (int) max($value, 0).
		$n = function ( string $field ) use ( $raw ): int {
			return (int) max( $raw[ $field ], 0 );
		};
		$this->assertIsObject( $quota );
		$this->assertSame( $n( 'APICallsQuota' ), (int) $quota->monthly->total, 'monthly.total must mirror APICallsQuota.' );
		$this->assertSame( $n( 'APICallsMade' ), (int) $quota->monthly->consumed, 'monthly.consumed must mirror APICallsMade.' );
		$this->assertSame( $n( 'APICallsQuotaOneTime' ), (int) $quota->onetime->total, 'onetime.total must mirror APICallsQuotaOneTime.' );
		$this->assertSame( $n( 'APICallsMadeOneTime' ), (int) $quota->onetime->consumed, 'onetime.consumed must mirror APICallsMadeOneTime.' );
		$this->assertSame( $n( 'CaptionsCallsQuota' ), (int) $quota->ai->total, 'ai.total must mirror CaptionsCallsQuota.' );
		$this->assertSame( $n( 'CaptionsCallsMade' ), (int) $quota->ai->consumed, 'ai.consumed must mirror CaptionsCallsMade.' );
		$this->assertSame( $n( 'CaptionsCallsRemaining' ), (int) $quota->ai->remaining, 'ai.remaining must be the API\'s own CaptionsCallsRemaining.' );

		// Loose on purpose: mirrors QuotaController's own `$data->Unlimited == 'true'`.
		$unlimited = array_key_exists( 'Unlimited', $raw ) && 'true' == $raw['Unlimited'];
		$this->assertSame( $unlimited, (bool) $quota->unlimited, 'unlimited must follow the Unlimited field.' );
		$this->assertSame( $aiUnlimitedPlan, (bool) $quota->AIUnlimited, 'AIUnlimited must follow PlanType.' );

		// quotaExceeded follows the OPTIMISATION credits only (not the AI pool).
		$remaining = max( $n( 'APICallsQuota' ) - $n( 'APICallsMade' ), 0 ) + max( $n( 'APICallsQuotaOneTime' ) - $n( 'APICallsMadeOneTime' ), 0 );
		$this->assertSame(
			( $remaining > 0 || $unlimited ) ? 0 : 1,
			(int) \wpSPIO()->settings()->quotaExceeded,
			"quotaExceeded must be set exactly when no optimisation credits remain (remaining: $remaining)."
		);
	}

	/**
	 * Error contract for an image the backend cannot fetch, no credits
	 * (the backend does not charge for a failed download). The submission is
	 * accepted (add-url answers an id); get-url then answers `status` -1
	 * plus an `error` string, and the plugin ends the item as an error
	 * without writing anything.
	 */
	public function test_live_api_reports_an_unreachable_image_as_a_get_url_error() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		// A URL under the public fixture base that does not exist (404).
		remove_filter( 'wp_get_attachment_url', array( $this, 'remapToPublicFixtureUrl' ) );
		$missing = function () {
			return self::FIXTURE_RAW_BASE . 'this-fixture-does-not-exist.jpg';
		};
		add_filter( 'wp_get_attachment_url', $missing );

		$this->purgeQueueTable();
		$this->pointAiApiAtProduction();
		$this->enqueueAlt( $id );
		$this->runQueueAgainstRealAiApi();
		remove_filter( 'wp_get_attachment_url', $missing );

		$add = $this->lastResponse( 'add-url.php' );
		$this->assertIsArray( $add, 'add-url.php must answer JSON.' . $this->explainAiState() );
		if ( 3 === (int) ( $add['status'] ?? 0 ) ) {
			$this->markTestSkipped( 'The smoke account is out of AI credits (add-url status 3).' . $this->explainAiState() );
		}
		$this->assertArrayHasKey( 'id', $add, 'The submission itself is accepted; the download fails later.' . $this->explainAiState() );

		$get = $this->lastResponse( 'get-url.php' );
		$this->assertIsArray( $get, 'get-url.php must have been polled.' . $this->explainAiState() );
		$this->assertSame( -1, (int) ( $get['status'] ?? 0 ), 'An unreachable image must come back as status -1 (get-url error contract).' . $this->explainAiState() );
		$this->assertNotSame( '', (string) ( $get['error'] ?? '' ), 'The error contract carries a message.' . $this->explainAiState() );

		$this->assertSame( '', (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 'Nothing must be written for a failed download.' );
		$this->assertFalse( $this->queueHasWork(), 'The failed item must leave the queue.' );
	}

	/**
	 * Every generator on, including the AI file name: the request must carry
	 * one object per field (`file` with prefer_keep_filename_if_relevant
	 * inside it), the answer must return every requested field, and the
	 * rename must land on disk. The fixture is not used in any post, so the
	 * manual request (no recent_upload) passes the usage guard. Costs 1 AI
	 * credit.
	 */
	public function test_live_api_returns_every_requested_field_and_renames_the_file() {
		$settings                            = \wpSPIO()->settings();
		$settings->aiPreserve                = 0;
		$settings->ai_gen_alt                = 1;
		$settings->ai_gen_caption            = 1;
		$settings->ai_gen_description        = 1;
		$settings->ai_gen_post_title         = 1;
		$settings->ai_gen_filename           = 1;
		$settings->ai_filename_prefercurrent = 0;

		$id      = $this->uploadFixture( 'fixture-small.jpg' );
		$oldFile = get_attached_file( $id );
		$this->generateLive( $id );

		$sent = $this->exchangesFor( 'add-url.php' )[0]['request'] ?? null;
		$this->assertIsArray( $sent, 'The add-url request body must be JSON.' . $this->explainAiState() );
		foreach ( array( 'alt', 'caption', 'image_description', 'title', 'file' ) as $field ) {
			$this->assertArrayHasKey( $field, $sent, "The request must ask for '$field'." );
		}
		$this->assertArrayHasKey( 'prefer_keep_filename_if_relevant', $sent['file'], 'The keep-filename preference travels inside the file object.' );
		$this->assertFalse( $sent['file']['prefer_keep_filename_if_relevant'], 'ai_filename_prefercurrent=0 must be sent as false.' );

		$get = $this->lastResponse( 'get-url.php' );
		$this->assertSame( 2, (int) ( $get['status'] ?? 0 ), 'Generation must succeed (get-url status 2).' . $this->explainAiState() );
		foreach ( array( 'alt', 'caption', 'image_description', 'title', 'generated_file_name' ) as $field ) {
			$this->assertNotSame( '', (string) ( $get[ $field ] ?? '' ), "The backend must return '$field' when it was requested." . $this->explainAiState() );
		}

		// Plugin side: the record holds every field and the file was renamed.
		$generated = AiDataModel::getModelByAttachment( $id, 'media' )->getGeneratedData();
		foreach ( array( 'alt', 'caption', 'description', 'post_title', 'filebase' ) as $field ) {
			$this->assertNotSame( '', (string) ( $generated[ $field ] ?? '' ), "Generated '$field' must be stored." . $this->explainAiState() );
		}
		$this->assertNotSame( '', (string) get_post( $id )->post_content, 'The description must land in the attachment post_content.' );

		// SPIO keeps the generated name as sanitize_text_field() returns it
		// (AiController) and renames to exactly that base (no affixes set).
		$newBase = pathinfo( (string) get_post_meta( $id, '_wp_attached_file', true ), PATHINFO_FILENAME );
		$this->assertStringStartsNotWith( 'fixture-small', $newBase, 'An unused image must be renamed to the AI file name.' . $this->explainAiState() );
		$this->assertSame( sanitize_text_field( $get['generated_file_name'] ), $newBase, 'The new file base must be the generated_file_name the backend returned.' );
		$this->assertSame( (string) $generated['filebase'], $newBase, 'The stored filebase and the file on disk must agree.' );
		$this->assertFileExists( get_attached_file( $id ), 'The renamed file must exist on disk.' );
		$this->assertFileDoesNotExist( $oldFile, 'The old file must be moved, not copied.' );
	}

	/**
	 * The `languages` request field is honoured: a Romanian request must
	 * produce a different alt than the English one for the same image.
	 * Costs 1 AI credit (the English text is known from earlier runs, not
	 * requested again).
	 */
	public function test_live_api_honours_the_language_field() {
		\wpSPIO()->settings()->ai_language = 'ro';

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->generateLive( $id );

		$sent = $this->exchangesFor( 'add-url.php' )[0]['request'] ?? array();
		$this->assertSame( 'ro', $sent['languages'] ?? null, 'The request must carry the configured language.' . $this->explainAiState() );

		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$this->assertNotSame( '', $alt, 'A Romanian alt must be generated.' . $this->explainAiState() );
		// The English answer for this fixture starts with "Minimalist landscape";
		// any Romanian answer differs from that and from the generic English stems.
		$this->assertDoesNotMatchRegularExpression( '/^(A |An |The )?(minimalist|simple|serene|peaceful) landscape/i', $alt, 'The alt must not be the English answer.' . $this->explainAiState() );
	}

	/**
	 * Context, character limits and affixes: the request must carry the
	 * site context, the per-field context and the per-field `chars` limit,
	 * and the stored alt must carry the plugin's own prefix and postfix
	 * (formatResultData() joins them with a space). The backend treats
	 * `chars` as a target, not a hard cap, so only a generous ceiling is
	 * asserted on the raw answer. Costs 1 AI credit.
	 */
	public function test_live_api_receives_context_and_limits_and_plugin_applies_affixes() {
		$settings                     = \wpSPIO()->settings();
		$settings->ai_general_context = 'Product photos for an online pottery shop';
		$settings->ai_alt_context     = 'Mention the colours';
		$settings->ai_limit_alt_chars = 60;
		$settings->ai_alt_prefix      = 'Pottery:';
		$settings->ai_alt_postfix     = '(shop photo)';

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->generateLive( $id );

		$sent = $this->exchangesFor( 'add-url.php' )[0]['request'] ?? array();
		$this->assertSame( 'Product photos for an online pottery shop', $sent['context'] ?? null, 'The general context must be sent.' . $this->explainAiState() );
		$this->assertSame( 'Mention the colours', $sent['alt']['context'] ?? null, 'The per-field context must be sent inside the field object.' . $this->explainAiState() );
		$this->assertSame( 60, (int) ( $sent['alt']['chars'] ?? 0 ), 'The per-field character limit must be sent inside the field object.' . $this->explainAiState() );

		$rawAlt = (string) ( $this->lastResponse( 'get-url.php' )['alt'] ?? '' );
		$this->assertNotSame( '', $rawAlt, 'An alt must be generated.' . $this->explainAiState() );
		$this->assertLessThanOrEqual( 60 * 2, mb_strlen( $rawAlt ), 'The backend must keep the alt in the neighbourhood of the requested limit.' . $this->explainAiState() );

		$stored = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$this->assertStringStartsWith( 'Pottery:', $stored, 'The alt prefix must be applied.' );
		$this->assertStringContainsString( '(shop photo)', $stored, 'The alt postfix must be applied.' );
	}

	/**
	 * A second request in the same process, after the first cached a JWT,
	 * must authenticate with it: AiController sends `Bearer <jwt>` while the
	 * spio_ai_jwt_token transient exists and falls back to `ApiKey <key>`
	 * only without it. The second generation must succeed and the backend
	 * must keep the token valid (or refresh it). Costs 2 AI credits (two
	 * generations).
	 */
	public function test_live_api_second_request_authenticates_with_the_cached_jwt() {
		$first = $this->uploadFixture( 'fixture-small.jpg' );
		$this->generateLive( $first );

		$firstAdd = $this->exchangesFor( 'add-url.php' )[0] ?? null;
		$this->assertNotNull( $firstAdd, 'The first request must reach add-url.php.' . $this->explainAiState() );
		$this->assertStringStartsWith( 'ApiKey ', (string) ( $firstAdd['headers']['Authorization'] ?? '' ), 'Without a cached token the first request authenticates with the API key.' );

		$firstToken = get_transient( 'spio_ai_jwt_token' );
		$this->assertNotEmpty( $firstToken, 'The first exchange must cache a JWT.' . $this->explainAiState() );

		$second             = $this->uploadFixture( 'fixture-small.jpg' );
		$this->apiExchanges = array();
		$this->generateLive( $second );

		$secondAdd = $this->exchangesFor( 'add-url.php' )[0] ?? null;
		$this->assertNotNull( $secondAdd, 'The second request must reach add-url.php.' . $this->explainAiState() );
		$this->assertSame( 'Bearer ' . $firstToken, (string) ( $secondAdd['headers']['Authorization'] ?? '' ), 'The second request must authenticate with the cached JWT.' );
		$this->assertNotSame( '', (string) get_post_meta( $second, '_wp_attachment_image_alt', true ), 'The second generation (Bearer-authenticated) must succeed.' . $this->explainAiState() );
		$this->assertNotEmpty( get_transient( 'spio_ai_jwt_token' ), 'The token must still be cached (valid or refreshed) after the second exchange.' );
	}
}
