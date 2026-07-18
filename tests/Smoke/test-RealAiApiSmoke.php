<?php
/**
 * Real-AI-API smoke tests — talk to the LIVE ShortPixel AI backend
 * (capi-gpt.shortpixel.com).
 *
 * The integration suite mocks add-url.php/get-url.php; this suite removes
 * the mock and runs the REAL two-phase AI flow (submit → poll → store),
 * catching contract drift: changed response fields, new status sentinels,
 * JWT issuing behaviour.
 *
 * Endpoint note (Pedro, 2026-07-18): production uses
 * capi-gpt.shortpixel.com. AiController currently points at
 * devapigpt.shortpixel.com because Bas temporarily needs the dev API for
 * filename-generation work — so this suite reflection-overrides
 * AiController::$main_url to the production endpoint. Once the code flips
 * back to capi-gpt, pointAiApiAtProduction() becomes a no-op and can be
 * removed.
 *
 * Requirements + costs:
 *   - SHORTPIXEL_SMOKE_KEY env var must hold a valid 20-char API key with
 *     AI credits; without it every test SKIPS. If the account is out of
 *     AI credits (add-url answers status 3) the end-to-end test SKIPS
 *     rather than fails.
 *   - The end-to-end test consumes 1 AI credit; the wrong-key test none.
 *   - The AI backend fetches the image by URL and cannot reach the local
 *     test install, so wp_get_attachment_url is remapped to the committed
 *     fixture's public raw.githubusercontent.com URL.
 *
 * Run: bin/test.sh --smoke
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\QueueController;
use ShortPixel\Model\AiDataModel;

class RealAiApiSmokeTest extends SPIO_IntegrationTestCase {

	private const FIXTURE_RAW_BASE = 'https://raw.githubusercontent.com/short-pixel-optimizer/shortpixel-image-optimiser/integration-tests/tests/fixtures/';

	private const PRODUCTION_AI_URL = 'https://capi-gpt.shortpixel.com/';

	/** @var array Recorded live HTTP exchanges with the ShortPixel AI API. */
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
		// Filename generation is Bas's in-flight dev-API work — out of scope.
		$settings->ai_gen_filename   = 0;
		$settings->processThumbnails = 0;

		// A fresh JWT must be negotiated per test run.
		delete_transient( 'spio_ai_jwt_token' );

		add_filter( 'wp_get_attachment_url', array( $this, 'remapToPublicFixtureUrl' ) );

		$this->apiExchanges = array();
		add_filter( 'http_response', array( $this, 'recordApiExchange' ), 10, 3 );
	}

	public function recordApiExchange( $response, $parsed_args, $url ) {
		if ( false !== strpos( $url, 'shortpixel.com' ) ) {
			$body                 = wp_remote_retrieve_body( $response );
			$this->apiExchanges[] = array(
				'endpoint' => basename( (string) parse_url( $url, PHP_URL_PATH ) ),
				'response' => is_string( $body ) ? substr( $body, 0, 2000 ) : $body,
			);
		}
		return $response;
	}

	/** The AI backend fetches by URL — hand it the committed fixture bytes. */
	public function remapToPublicFixtureUrl( $url ) {
		$name = basename( parse_url( $url, PHP_URL_PATH ) );
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

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$result     = ( new QueueController() )->addItemToQueue( $imageModel, array( 'action' => 'requestAlt' ) );
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

		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		( new QueueController() )->addItemToQueue( $imageModel, array( 'action' => 'requestAlt' ) );

		$this->runQueueAgainstRealAiApi( 10 );

		$this->assertNotEmpty( $this->apiExchanges, 'The submission must have reached the live AI endpoint.' );
		$this->assertSame(
			'',
			(string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'A wrong key must never yield generated alt text.' . $this->explainAiState()
		);
	}
}
