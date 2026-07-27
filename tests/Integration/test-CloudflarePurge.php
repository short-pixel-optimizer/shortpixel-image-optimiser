<?php
/**
 * Integration tests: Cloudflare edge-cache purge (cross-plugin Wave 3).
 *
 * class/external/cloudflare.php listens on `shortpixel/image/optimised`
 * and `shortpixel/image/before_restore` and POSTs a purge_cache request
 * for every URL variant (main, WebP, AVIF, thumbnails) of the image.
 *
 * The purge uses RAW cURL — not the WP HTTP API — so the usual
 * `pre_http_request` mock cannot intercept it. Instead these tests boot
 * a real `php -S` capture server on localhost, point a CloudFlareAPI
 * instance at it via reflection, and assert on the requests that
 * actually arrive: endpoint path (zone id), Bearer token header, and
 * the JSON `files` payload.
 *
 * The plugin's own self-booted CloudFlareAPI instance stays unconfigured
 * (empty settings → config_ok=false → silent no-op), so nothing ever
 * reaches the real api.cloudflare.com.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\CloudFlareAPI;
use ShortPixel\Controller\OtherMediaController;
use ShortPixel\Controller\QueueController;

class CloudflarePurgeTest extends SPIO_IntegrationTestCase {

	private const PORT  = 8437;
	private const ZONE  = 'TESTZONE123';
	private const TOKEN = 'TESTTOKEN456';

	/** @var int|null PID of the php -S capture server. */
	private static $serverPid = null;

	/** @var string Directory holding the router script + request log. */
	private static $captureDir;

	public static function set_up_before_class() {
		parent::set_up_before_class();

		self::$captureDir = sys_get_temp_dir() . '/spio-cf-capture';
		if ( ! is_dir( self::$captureDir ) ) {
			mkdir( self::$captureDir, 0777, true );
		}

		$router = <<<'PHP'
<?php
$entry = json_encode( array(
	'uri'    => $_SERVER['REQUEST_URI'],
	'method' => $_SERVER['REQUEST_METHOD'],
	'auth'   => isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '',
	'body'   => file_get_contents( 'php://input' ),
) );
file_put_contents( __DIR__ . '/requests.log', $entry . "\n", FILE_APPEND );
header( 'Content-Type: application/json' );
echo json_encode( array( 'success' => true, 'errors' => array(), 'messages' => array(), 'result' => array( 'id' => 'test' ) ) );
PHP;
		file_put_contents( self::$captureDir . '/router.php', $router );

		$cmd = sprintf(
			'php -S 127.0.0.1:%d %s >/dev/null 2>&1 & echo $!',
			self::PORT,
			escapeshellarg( self::$captureDir . '/router.php' )
		);
		self::$serverPid = (int) trim( (string) shell_exec( $cmd ) );

		// Wait (max ~5s) for the server socket to accept connections.
		for ( $i = 0; $i < 50; $i++ ) {
			$sock = @fsockopen( '127.0.0.1', self::PORT, $errno, $errstr, 0.1 );
			if ( false !== $sock ) {
				fclose( $sock );
				return;
			}
			usleep( 100000 );
		}
		self::fail( 'Local Cloudflare capture server did not come up on port ' . self::PORT );
	}

	public static function tear_down_after_class() {
		if ( self::$serverPid ) {
			shell_exec( 'kill ' . (int) self::$serverPid . ' 2>/dev/null' );
			self::$serverPid = null;
		}
		parent::tear_down_after_class();
	}

	public function set_up() {
		parent::set_up();
		@unlink( self::$captureDir . '/requests.log' );
	}

	// -------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------

	/**
	 * A CloudFlareAPI instance wired to the local capture server.
	 * Reflection injects the credentials directly (setup_done=true) so
	 * the plugin's own instance — which reads the (empty) settings —
	 * stays a no-op and nothing hits the real Cloudflare API.
	 */
	private function configuredPurger(): CloudFlareAPI {
		$purger     = new CloudFlareAPI();
		$reflection = new ReflectionClass( $purger );

		foreach ( array(
			'zone_id'    => self::ZONE,
			'token'      => self::TOKEN,
			'config_ok'  => true,
			'setup_done' => true,
			'api_url'    => 'http://127.0.0.1:' . self::PORT . '/zones/',
		) as $property => $value ) {
			$prop = $reflection->getProperty( $property );
			$prop->setAccessible( true );
			$prop->setValue( $purger, $value );
		}

		return $purger;
	}

	/** Requests captured by the local server, oldest first. */
	private function capturedRequests(): array {
		$log = self::$captureDir . '/requests.log';
		if ( ! file_exists( $log ) ) {
			return array();
		}
		$requests = array();
		foreach ( file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$requests[] = json_decode( $line, true );
		}
		return $requests;
	}

	private function purgedFiles( array $request ): array {
		$body = json_decode( $request['body'], true );
		return isset( $body['files'] ) ? $body['files'] : array();
	}

	// -------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------

	public function test_optimize_fires_purge_with_all_variant_urls() {
		\wpSPIO()->settings()->createWebp        = 1;
		\wpSPIO()->settings()->processThumbnails = 1;

		$this->configuredPurger();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$requests = $this->capturedRequests();
		$this->assertNotEmpty( $requests, 'Optimizing must fire a Cloudflare purge request.' );
		$request = $requests[0];

		$this->assertSame( '/zones/' . self::ZONE . '/purge_cache', $request['uri'], 'Purge must target the configured zone.' );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'Bearer ' . self::TOKEN, $request['auth'], 'Purge must authenticate with the Bearer token.' );

		$files   = $this->purgedFiles( $request );
		$mainUrl = wp_get_attachment_url( $id );
		$this->assertContains( $mainUrl, $files, 'Purge list must contain the main file URL.' );

		$webpUrls = array_filter(
			$files,
			function ( $url ) {
				return false !== strpos( $url, '.webp' );
			}
		);
		$this->assertNotEmpty( $webpUrls, 'Purge list must contain WebP variant URLs.' );

		// At least one thumbnail (…-WxH.jpg) variant must be present.
		$thumbUrls = array_filter(
			$files,
			function ( $url ) {
				return 1 === preg_match( '/-\d+x\d+\.jpg$/', $url );
			}
		);
		$this->assertNotEmpty( $thumbUrls, 'Purge list must contain thumbnail URLs.' );
	}

	public function test_restore_fires_purge_before_reverting() {
		\wpSPIO()->settings()->processThumbnails = 1;

		$this->configuredPurger();

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		// Only interested in the restore-triggered purge.
		@unlink( self::$captureDir . '/requests.log' );

		$this->purgeQueueTable();
		$queueController = new QueueController();
		$queueController->addItemToQueue(
			\wpSPIO()->filesystem()->getImage( $id, 'media', false ),
			array( 'action' => 'restore' )
		);
		$this->runQueueUntilEmpty();

		$requests = $this->capturedRequests();
		$this->assertNotEmpty( $requests, 'Restoring must fire a Cloudflare purge request (before_restore hook).' );
		$this->assertSame( '/zones/' . self::ZONE . '/purge_cache', $requests[0]['uri'] );
		$this->assertContains( wp_get_attachment_url( $id ), $this->purgedFiles( $requests[0] ), 'Restore purge must include the main file URL.' );
	}

	public function test_no_purge_without_cloudflare_configuration() {
		// No configured purger: the plugin's own instance reads empty
		// settings → config_ok=false → the hook handler must no-op.
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$this->assertTrue( \wpSPIO()->filesystem()->getImage( $id, 'media', false )->isOptimized() );
		$this->assertSame( array(), $this->capturedRequests(), 'Without zone/token configuration no purge request may be sent.' );
	}

	// -------------------------------------------------------------------
	// Custom media helpers (shared between 15.2a and 15.2b)
	// -------------------------------------------------------------------

	/**
	 * Create a temp folder with a single image registered in the custom-media
	 * pipeline, return (customDir, customId).
	 *
	 * @return array{string, int}  [absolute folder path with trailing slash, shortpixel_meta id]
	 */
	private function addCustomMediaImage(): array {
		global $wpdb;
		// Purge stale shortpixel_folders / shortpixel_meta rows so the folder
		// and image are the only ones present (mirrors CustomMediaFoldersTest).
		foreach ( array( 'shortpixel_folders', 'shortpixel_meta' ) as $name ) {
			$table = $wpdb->prefix . $name;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "DELETE FROM `$table`" );
			}
		}

		$customDir = trailingslashit( WP_CONTENT_DIR ) . 'spio-cf-custom-' . wp_generate_password( 8, false ) . '/';
		mkdir( $customDir, 0777, true );
		$imagePath = $customDir . 'cf-custom.jpg';
		copy( $this->fixturePath( 'fixture-small.jpg' ), $imagePath );

		$otherMedia = \ShortPixel\Controller\OtherMediaController::getInstance();
		$folder     = $otherMedia->addDirectory( $customDir );
		$this->assertNotFalse( $folder, 'Custom folder registration must succeed.' );

		// Scan finds one image; resolve its shortpixel_meta id.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}shortpixel_meta WHERE folder_id = %d",
				(int) $folder->get( 'id' )
			)
		);
		$this->assertCount( 1, $rows, 'Exactly one image must be found in the custom folder.' );

		return array( $customDir, (int) $rows[0]->id );
	}

	/** Remove the custom-media folder created by addCustomMediaImage(). */
	private function removeCustomMediaDir( string $dir ): void {
		foreach ( glob( $dir . '*' ) ?: array() as $file ) {
			@unlink( $file );
		}
		@rmdir( $dir );
	}

	// -------------------------------------------------------------------
	// 15.2a — custom-media optimize fires Cloudflare purge
	// -------------------------------------------------------------------

	/**
	 * Optimizing a Custom Media image must fire a Cloudflare cache-purge
	 * request containing that image's URL — exactly the same
	 * shortpixel/image/optimised hook that Media Library items use.
	 *
	 * The CloudFlareAPI constructor wires check_cloudflare() onto the hook;
	 * reflection then injects the capture-server credentials into that
	 * instance so the purge hits the local server rather than the real
	 * api.cloudflare.com.  The plugin's own (unconfigured) instance stays
	 * a no-op throughout.
	 *
	 * Manual plan row 15.2a.
	 *
	 * @return void
	 */
	public function test_custom_media_optimize_purges_cloudflare_cache() {
		// configuredPurger() calls new CloudFlareAPI() which registers
		// check_cloudflare on shortpixel/image/optimised via the constructor,
		// then injects the capture-server credentials via reflection.
		$this->configuredPurger();

		list( $customDir, $customId ) = $this->addCustomMediaImage();

		try {
			$customImage     = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$queueController = new QueueController();
			$queueController->addItemToQueue( $customImage );
			$this->runQueueUntilEmpty();

			$customImage = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$this->assertTrue( $customImage->isOptimized(), 'Custom image must be optimized before checking the purge.' );

			$requests = $this->capturedRequests();
			$this->assertNotEmpty( $requests, 'Optimizing a custom-media image must fire a Cloudflare purge request.' );

			$this->assertSame(
				'/zones/' . self::ZONE . '/purge_cache',
				$requests[0]['uri'],
				'Purge must target the configured Cloudflare zone.'
			);
			$this->assertSame( 'Bearer ' . self::TOKEN, $requests[0]['auth'], 'Purge must carry the Bearer token.' );

			// The purge files list must include the URL of the custom image.
			$files     = $this->purgedFiles( $requests[0] );
			$customUrl = $customImage->getURL();
			$this->assertContains(
				$customUrl,
				$files,
				'Cloudflare purge files list must include the custom-media image URL.'
			);
		} finally {
			$this->removeCustomMediaDir( $customDir );
		}
	}

	// -------------------------------------------------------------------
	// 15.2b — custom-media restore fires Cloudflare purge
	// -------------------------------------------------------------------

	/**
	 * Restoring a Custom Media image must fire a Cloudflare cache-purge
	 * request via the shortpixel/image/before_restore hook before the
	 * file reverts — the same hook fired for Media Library restores.
	 *
	 * Manual plan row 15.2b.
	 *
	 * @return void
	 */
	public function test_custom_media_restore_purges_cloudflare_cache() {
		$this->configuredPurger();

		list( $customDir, $customId ) = $this->addCustomMediaImage();

		try {
			$customImage     = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$queueController = new QueueController();
			$queueController->addItemToQueue( $customImage );
			$this->runQueueUntilEmpty();

			$customImage = \wpSPIO()->filesystem()->getImage( $customId, 'custom', false );
			$this->assertTrue( $customImage->isOptimized(), 'Custom image must be optimized before restore test.' );

			// Clear the log so we only see the restore-triggered purge.
			@unlink( self::$captureDir . '/requests.log' );

			// Restore — same ShortQ-gotcha workaround as the ML restore test.
			$this->purgeQueueTable();
			$queueController = new QueueController();
			$queueController->addItemToQueue(
				\wpSPIO()->filesystem()->getImage( $customId, 'custom', false ),
				array( 'action' => 'restore' )
			);
			$this->runQueueUntilEmpty();

			$requests = $this->capturedRequests();
			$this->assertNotEmpty( $requests, 'Restoring a custom-media image must fire a Cloudflare purge request (before_restore hook).' );

			$this->assertSame(
				'/zones/' . self::ZONE . '/purge_cache',
				$requests[0]['uri'],
				'Restore purge must target the configured Cloudflare zone.'
			);

			$files = $this->purgedFiles( $requests[0] );
			$this->assertNotEmpty( $files, 'Restore purge files list must not be empty.' );
		} finally {
			$this->removeCustomMediaDir( $customDir );
		}
	}
}
