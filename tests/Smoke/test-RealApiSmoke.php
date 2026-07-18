<?php
/**
 * Real-API smoke tests (Wave 3) — talk to the LIVE ShortPixel API.
 *
 * Everything else in the integration suite mocks the HTTP layer; this
 * suite removes the mock and runs the pipeline against the real reducer
 * endpoint, catching contract drift a mock can never see (changed
 * response fields, new sentinel values, download URL behaviour).
 *
 * Requirements + costs:
 *   - SHORTPIXEL_SMOKE_KEY env var must hold a valid 20-char API key;
 *     without it every test SKIPS (never fails) so default runs stay green.
 *   - Each test consumes real quota credits (1 image per test, except the
 *     compression-level comparison which uses 3; the wrong-key test uses 0).
 *   - The API fetches images by URL and cannot reach the local test
 *     install, so the `shortpixel_image_urls` filter remaps the urllist
 *     to the committed fixtures' public raw.githubusercontent.com URLs.
 *     Thumbnail processing is disabled — only the main file has a public
 *     counterpart.
 *
 * Run: bin/test.sh --smoke   (never part of --integration/--all or CI)
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;

class RealApiSmokeTest extends SPIO_IntegrationTestCase {

	/** Public URL of the committed fixtures on the integration-tests branch. */
	private const FIXTURE_RAW_BASE = 'https://raw.githubusercontent.com/short-pixel-optimizer/shortpixel-image-optimiser/integration-tests/tests/fixtures/';

	/** @var array Recorded live HTTP exchanges with the ShortPixel API, for failure diagnostics. */
	private $apiExchanges = array();

	public function set_up() {
		$key = getenv( 'SHORTPIXEL_SMOKE_KEY' );
		if ( false === $key || 20 !== strlen( trim( $key ) ) ) {
			$this->markTestSkipped( 'SHORTPIXEL_SMOKE_KEY not set to a 20-char ShortPixel API key — skipping real-API smoke test.' );
		}

		parent::set_up();

		// Real HTTP from here on.
		MockShortPixelApi::unregister();

		// verifiedKey=true makes checkKey() short-circuit, so no validation
		// round-trip is spent — the reducer call itself proves the key.
		update_option(
			'spio_key',
			array(
				'apiKey'      => trim( $key ),
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);
		$this->resetPluginSingletons();

		$settings                    = \wpSPIO()->settings();
		$settings->quotaExceeded     = 0;
		$settings->backupImages      = 1;
		// Thumbnails have no public URL counterpart — main file only.
		$settings->processThumbnails = 0;

		add_filter( 'shortpixel_image_urls', array( $this, 'remapToPublicFixtureUrls' ) );

		// Record every live exchange with the API so a failing assertion can
		// show WHAT the real API answered instead of a bare false-is-not-true.
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

	/** What the live API answered, for fail messages. */
	private function explainPipelineState( int $attachment_id ): string {
		if ( empty( $this->apiExchanges ) ) {
			return "\n--- No API responses recorded — the request never reached shortpixel.com. ---";
		}

		$out = "\n--- API responses ---";
		foreach ( $this->apiExchanges as $exchange ) {
			$out .= "\n[" . $exchange['endpoint'] . '] ' . trim( (string) $exchange['response'] );
		}
		return $out;
	}

	/**
	 * Replace local (unreachable) attachment URLs with the public GitHub
	 * raw URLs of the same committed fixture bytes. wp_unique_filename()
	 * may have suffixed the local copy (fixture-small-1.jpg) — strip that
	 * so the remote name always matches the committed fixture.
	 */
	public function remapToPublicFixtureUrls( $urls ) {
		return array_map(
			function ( $url ) {
				$name = basename( parse_url( $url, PHP_URL_PATH ) );
				$name = preg_replace( '/-\d+(\.\w+)$/', '$1', $name );
				return self::FIXTURE_RAW_BASE . $name;
			},
			(array) $urls
		);
	}

	/**
	 * Drive the queue against the real (async) API: real seconds between
	 * ticks, because Code-1 "queued" responses only resolve server-side.
	 */
	private function runQueueAgainstRealApi( int $maxTicks = 30 ): void {
		$queueController = new QueueController();

		for ( $tick = 0; $tick < $maxTicks; $tick++ ) {
			$queueController->processQueue( array( 'media', 'custom' ) );

			if ( ! $this->queueHasWork() ) {
				return;
			}

			$this->backdateQueueItems();
			sleep( 2 );
		}

		$this->fail( "Queue still has work after $maxTicks real-API ticks — item stuck or API unreachable." );
	}

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	// -------------------------------------------------------------------
	// Smoke
	// -------------------------------------------------------------------

	public function test_real_api_optimizes_jpg_end_to_end() {
		$id           = $this->uploadFixture( 'fixture-small.jpg' );
		$path         = get_attached_file( $id );
		$originalSize = filesize( $path );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->runQueueAgainstRealApi();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must be optimized by the real API.' . $this->explainPipelineState( $id )
		);
		$this->assertGreaterThan(
			0,
			(float) $image->getImprovement(),
			'Real API optimization must yield a positive improvement percentage.' . $this->explainPipelineState( $id )
		);

		clearstatcache();
		$this->assertLessThan(
			$originalSize,
			filesize( $path ),
			'The real optimized download must be smaller than the original file.' . $this->explainPipelineState( $id )
		);
	}

	public function test_real_api_creates_webp_companion() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->runQueueAgainstRealApi();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must be optimized before checking the WebP companion.' . $this->explainPipelineState( $id )
		);

		$webp = $image->getWebp();
		$this->assertNotFalse( $webp, 'Real API run must produce a WebP companion.' );
		$this->assertTrue( $webp->exists(), 'WebP companion must exist on disk: ' . ( $webp ? $webp->getFullPath() : '' ) );
	}

	public function test_real_api_creates_avif_companion() {
		\wpSPIO()->settings()->createAvif = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->runQueueAgainstRealApi();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must be optimized before checking the AVIF companion.' . $this->explainPipelineState( $id )
		);

		$avif = $image->getAvif();
		$this->assertNotFalse( $avif, 'Real API run must produce an AVIF companion.' );
		$this->assertTrue( $avif->exists(), 'AVIF companion must exist on disk: ' . ( $avif ? $avif->getFullPath() : '' ) );
	}

	/**
	 * fixture-small.jpg is 1200x900; a 800x800 outer (COVER — result >= box,
	 * confirmed live 2026-07-18) box must come back from the real API as
	 * 1067x800. Resize happens server-side — this proves the
	 * resize/resize_width/resize_height request params still work.
	 */
	public function test_real_api_resizes_main_image() {
		$settings               = \wpSPIO()->settings();
		$settings->resizeImages = 1;
		$settings->resizeWidth  = 800;
		$settings->resizeHeight = 800;
		$settings->resizeType   = 'outer';

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->runQueueAgainstRealApi();

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must be optimized before checking resize.' . $this->explainPipelineState( $id )
		);

		clearstatcache();
		$size = getimagesize( get_attached_file( $id ) );
		$this->assertSame( 1067, $size[0], 'Real API outer resize must cover the 800x800 box (1200x900 => 1067x800).' . $this->explainPipelineState( $id ) );
		$this->assertSame( 800, $size[1], 'Real API outer resize must scale the shortest side to the box height.' . $this->explainPipelineState( $id ) );
	}

	/**
	 * Optimize the same fixture at all three compression levels and compare
	 * the resulting file sizes: lossy < glossy < lossless < original.
	 * Costs 3 credits (one optimization per level).
	 */
	public function test_real_api_compression_levels_order_by_size() {
		$sizes = array();

		// 1 = lossy, 2 = glossy, 0 = lossless (API `lossy` param).
		foreach ( array( 'lossy' => 1, 'glossy' => 2, 'lossless' => 0 ) as $label => $level ) {
			\wpSPIO()->settings()->compressionType = $level;

			$id = $this->uploadFixture( 'fixture-small.jpg' );

			$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
			$queueController = new QueueController();
			$queueController->addItemToQueue( $imageModel );

			$this->runQueueAgainstRealApi();

			$image = $this->freshImageModel( $id );
			$this->assertTrue(
				$image->isOptimized(),
				"Fixture must optimize at compression level '$label'." . $this->explainPipelineState( $id )
			);

			clearstatcache();
			$sizes[ $label ]    = filesize( get_attached_file( $id ) );
			$sizes['original'] = $sizes['original'] ?? (int) $image->getMeta( 'originalSize' );
		}

		$this->assertGreaterThan(
			$sizes['lossy'],
			$sizes['glossy'],
			'Glossy output must be (slightly) bigger than lossy. Sizes: ' . json_encode( $sizes )
		);
		$this->assertGreaterThan(
			$sizes['glossy'],
			$sizes['lossless'],
			'Lossless output must be bigger than glossy. Sizes: ' . json_encode( $sizes )
		);
		$this->assertLessThan(
			$sizes['original'],
			$sizes['lossless'],
			'Even lossless must shrink the original. Sizes: ' . json_encode( $sizes )
		);
	}

	/**
	 * After a REAL optimization, restore (a purely local backup move-back)
	 * must bring the original bytes back and clear the optimized state.
	 */
	public function test_restore_after_real_api_optimize_reverts_original_bytes() {
		$id           = $this->uploadFixture( 'fixture-small.jpg' );
		$path         = get_attached_file( $id );
		$originalSize = filesize( $path );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->runQueueAgainstRealApi();

		clearstatcache();
		$this->assertLessThan(
			$originalSize,
			filesize( $path ),
			'Sanity: the real API must have shrunk the file before we restore.' . $this->explainPipelineState( $id )
		);

		// Restore does not talk to the API; the done optimize item must be
		// purged first or addItemToQueue() only appends a next_action to it.
		$this->purgeQueueTable();
		$queueController = new QueueController();
		$queueController->addItemToQueue( $this->freshImageModel( $id ), array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();

		clearstatcache();
		$this->assertSame( $originalSize, filesize( $path ), 'Restore must bring back the original file bytes.' );
		$this->assertFalse( $this->freshImageModel( $id )->isOptimized(), 'Image must no longer be marked optimized after restore.' );
	}

	/**
	 * With a syntactically valid but WRONG key the real API answers -402;
	 * the item must error out (queue drains, nothing stuck) and the image
	 * must stay unoptimized. Costs no credits.
	 */
	public function test_real_api_rejects_wrong_key_without_stalling_queue() {
		update_option(
			'spio_key',
			array(
				'apiKey'      => 'SPIOWRONGKEY00000000',
				'verifiedKey' => true,
				'apiKeyTried' => '',
			)
		);
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->quotaExceeded     = 0;
		\wpSPIO()->settings()->processThumbnails = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$imageModel      = \wpSPIO()->filesystem()->getImage( $id, 'media' );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel );

		$this->runQueueAgainstRealApi( 10 );

		$this->assertFalse(
			$this->freshImageModel( $id )->isOptimized(),
			'A wrong key must never yield an optimized image.' . $this->explainPipelineState( $id )
		);

		$sawKeyError = false;
		foreach ( $this->apiExchanges as $exchange ) {
			if ( false !== strpos( (string) $exchange['response'], '-402' ) ) {
				$sawKeyError = true;
				break;
			}
		}
		$this->assertTrue( $sawKeyError, 'The real API must answer with the -402 wrong-key code.' . $this->explainPipelineState( $id ) );
	}
}
