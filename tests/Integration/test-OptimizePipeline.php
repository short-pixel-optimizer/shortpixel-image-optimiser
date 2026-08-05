<?php
/**
 * Integration tests: upload → optimize → verify (Wave 1).
 *
 * Drives the REAL optimization pipeline — QueueController, ShortQ queue,
 * OptimizeController, ApiController request building, response parsing,
 * DownloadHelper file download, meta persistence — against a real
 * attachment in the WP test install. Only the HTTP layer is mocked
 * (MockShortPixelApi), so a change anywhere in the pipeline that breaks
 * the end-to-end flow fails here.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Model\Image\ImageModel;

class OptimizePipelineTest extends SPIO_IntegrationTestCase {

	public function tear_down() {
		// Remove any backup files left on disk by tests in this suite.
		// Backup files are created outside the DB transaction and survive
		// WP test-framework rollbacks.
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
		}
		parent::tear_down();
	}

	/** Reload a fresh image model straight from the DB (no cached state). */
	private function freshImageModel( int $attachment_id ) {
		$fs = \wpSPIO()->filesystem();
		return $fs->getImage( $attachment_id, 'media', false );
	}

	// -------------------------------------------------------------------
	// Main file optimization
	// -------------------------------------------------------------------

	public function test_optimize_small_jpg_marks_main_file_optimized() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue(
			$image->isOptimized(),
			'Main file must be marked optimized after the queue completes.'
		);
	}

	public function test_optimize_sends_reducer_request_with_urllist() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->optimizeAttachment( $id );

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $req ) {
				return false !== strpos( $req['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty( $reducerCalls, 'The pipeline must call the reducer endpoint.' );

		$call = array_values( $reducerCalls )[0];
		$this->assertIsArray( $call['request'], 'Reducer request body must be valid JSON.' );
		$this->assertArrayHasKey( 'urllist', $call['request'] );
		$this->assertNotEmpty( $call['request']['urllist'] );
	}

	public function test_optimize_writes_smaller_file_to_disk() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$originalPath = get_attached_file( $id );
		$originalSize = filesize( $originalPath );

		$this->optimizeAttachment( $id );

		clearstatcache();
		$this->assertFileExists( $originalPath );
		$this->assertLessThan(
			$originalSize,
			filesize( $originalPath ),
			'Optimized main file on disk must be smaller than the original.'
		);
	}

	// -------------------------------------------------------------------
	// Thumbnails
	// -------------------------------------------------------------------

	public function test_optimize_covers_all_generated_thumbnails() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'Fixture must be large enough to generate thumbnails.' );

		$this->optimizeAttachment( $id );

		$image      = $this->freshImageModel( $id );
		$thumbnails = $image->get( 'thumbnails' );
		$this->assertNotEmpty( $thumbnails, 'Image model must expose thumbnails.' );

		foreach ( $thumbnails as $sizeName => $thumbnail ) {
			$this->assertTrue(
				$thumbnail->isOptimized(),
				"Thumbnail '$sizeName' must be optimized."
			);
		}
	}

	// -------------------------------------------------------------------
	// WebP / AVIF companions
	// -------------------------------------------------------------------

	public function test_optimize_with_createWebp_writes_webp_companion() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		$webp = $image->getWebp();
		$this->assertNotFalse( $webp, 'Image model must expose a WebP companion.' );
		$this->assertTrue( $webp->exists(), 'WebP companion file must exist on disk: ' . $webp->getFullPath() );
	}

	public function test_optimize_with_createAvif_writes_avif_companion() {
		\wpSPIO()->settings()->createAvif = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		$avif = $image->getAvif();
		$this->assertNotFalse( $avif, 'Image model must expose an AVIF companion.' );
		$this->assertTrue( $avif->exists(), 'AVIF companion file must exist on disk: ' . $avif->getFullPath() );
	}

	public function test_optimize_without_conversion_flags_writes_no_companions() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );

		$webp = $image->getWebp();
		$avif = $image->getAvif();
		$this->assertFalse( $webp !== false && $webp->exists(), 'No WebP file expected when createWebp is off.' );
		$this->assertFalse( $avif !== false && $avif->exists(), 'No AVIF file expected when createAvif is off.' );
	}

	public function test_optimize_with_createWebp_covers_thumbnails() {
		\wpSPIO()->settings()->createWebp = 1;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image      = $this->freshImageModel( $id );
		$thumbnails = $image->get( 'thumbnails' );
		$this->assertNotEmpty( $thumbnails );

		foreach ( $thumbnails as $sizeName => $thumbnail ) {
			$webp = $thumbnail->getWebp();
			$this->assertNotFalse( $webp, "Thumbnail '$sizeName' must expose a WebP companion." );
			$this->assertTrue( $webp->exists(), "Thumbnail '$sizeName' WebP file must exist on disk." );
		}
	}

	// -------------------------------------------------------------------
	// -scaled handling (large fixture)
	// -------------------------------------------------------------------

	public function test_large_upload_produces_scaled_file_and_optimizes_it() {
		$id = $this->uploadFixture( 'fixture-large.jpg' );

		$mainPath = get_attached_file( $id );
		$this->assertStringContainsString(
			'-scaled',
			basename( $mainPath ),
			'A 3200px upload must produce a -scaled main file (big-image threshold).'
		);

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'The -scaled main file must be optimized.' );
	}

	// -------------------------------------------------------------------
	// Extended tests (Wave 3)
	// -------------------------------------------------------------------

	/**
	 * An image whose filename matches an excludePatterns entry must not be
	 * optimized when manually triggered; the queue item must report it is
	 * not processable rather than succeeding.
	 *
	 * Manual plan 2.25 / 2.52.
	 */
	public function test_excluded_image_is_not_optimized() {
		// Set the pattern BEFORE uploading: with autoMediaLibrary=1 the upload
		// itself auto-enqueues the image, and that item would be evaluated
		// without the exclusion in place.
		// Substring pattern WITHOUT the extension: uploads are uniquified to
		// fixture-small-<n>.jpg, so 'fixture-small.jpg' would never match.
		\wpSPIO()->settings()->excludePatterns = array(
			array(
				'type'  => 'name',
				'value' => 'fixture-small',
				'apply' => 'all',
			),
		);

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertFalse(
			$image->isOptimized(),
			'An image matching an exclusion pattern must not be optimized (plan 2.25/2.52).'
		);
	}

	/**
	 * With autoMediaLibrary=0 (is_autoprocess=false), uploading an attachment
	 * must NOT enqueue it for optimization.
	 *
	 * The wp_generate_attachment_metadata hook is wired in shortpixel-plugin.php
	 * only when env()->is_autoprocess is true. We manipulate that flag and the
	 * filter registration directly to test the off-state.
	 *
	 * Manual plan 3.4.
	 */
	public function test_autoMediaLibrary_off_upload_not_queued() {
		// Turn auto-process off and remove the upload hook.
		\wpSPIO()->settings()->autoMediaLibrary = 0;
		\wpSPIO()->env()->is_autoprocess        = false;

		$admin = \ShortPixel\Controller\AdminController::getInstance();
		remove_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5 );

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$hasWork = $this->queueHasWork();

		// Re-wire the hook so other tests are not affected.
		\wpSPIO()->settings()->autoMediaLibrary = 1;
		\wpSPIO()->env()->is_autoprocess        = true;
		add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleImageUploadHook' ), 5, 2 );

		$this->assertFalse(
			$hasWork,
			'With autoMediaLibrary=0, uploading an image must NOT enqueue it for optimization (plan 3.4).'
		);
	}

	/**
	 * When useSmartcrop=true, the per-URL paramlist entry for each image sent to
	 * the reducer must include resize=4 (the smartcrop code).
	 *
	 * Manual plan 2.10 / 2.37.
	 */
	public function test_smartcrop_parameter_sent_in_reducer_request() {
		\wpSPIO()->settings()->useSmartcrop = 1;
		// Disable the resize-on-upload setting to avoid conflict with smartcrop.
		\wpSPIO()->settings()->resizeImages = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$reducerCalls = array_values( array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'reducer' );
			}
		) );
		$this->assertNotEmpty( $reducerCalls, 'The pipeline must call the reducer.' );

		$found = false;
		foreach ( $reducerCalls as $call ) {
			$req       = $call['request'];
			$paramlist = isset( $req['paramlist'] ) ? array_values( (array) $req['paramlist'] ) : array();
			foreach ( $paramlist as $entry ) {
				$entry = (array) $entry;
				if ( isset( $entry['resize'] ) && (int) $entry['resize'] === 4 ) {
					$found = true;
					break 2;
				}
			}
			// Also accept the top-level resize field if paramlist is absent.
			if ( ! $found && isset( $req['resize'] ) && (int) $req['resize'] === 4 ) {
				$found = true;
			}
		}

		$this->assertTrue(
			$found,
			'With useSmartcrop=1, at least one reducer request entry must carry resize=4 (the smartcrop code). Plan 2.10/2.37.'
		);
	}

	/**
	 * When useSmartcrop=false, no reducer request entry must carry resize=4.
	 *
	 * Manual plan 2.11 / 2.38.
	 */
	public function test_no_smartcrop_parameter_when_smartcrop_disabled() {
		\wpSPIO()->settings()->useSmartcrop = 0;
		\wpSPIO()->settings()->resizeImages = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty( $reducerCalls, 'The pipeline must call the reducer.' );

		foreach ( $reducerCalls as $call ) {
			$req       = $call['request'];
			$paramlist = isset( $req['paramlist'] ) ? array_values( (array) $req['paramlist'] ) : array();
			foreach ( $paramlist as $entry ) {
				$entry = (array) $entry;
				$this->assertNotSame(
					4,
					isset( $entry['resize'] ) ? (int) $entry['resize'] : null,
					'With useSmartcrop=0 no paramlist entry must carry resize=4 (plan 2.11/2.38).'
				);
			}
			$this->assertNotSame(
				4,
				isset( $req['resize'] ) ? (int) $req['resize'] : null,
				'With useSmartcrop=0 the top-level resize field must not be 4 (plan 2.11/2.38).'
			);
		}
	}

	/**
	 * With optimizePdfs=false, uploading a PDF must NOT add it to any queue.
	 *
	 * This test covers only the "PDF setting OFF → not queued" part of the PDF
	 * flow. The actual download bug for PDFs (DownloadHelper rejects .tmp files)
	 * is already covered by the pinned test in test-BulkOptimization.php.
	 *
	 * Manual plan 8.8.
	 */
	public function test_pdf_not_optimized_when_pdf_setting_disabled() {
		\wpSPIO()->settings()->optimizePdfs = 0;

		$id = $this->uploadFixture( 'fixture-large.pdf' );

		$hasWork = $this->queueHasWork();

		$this->assertFalse(
			$hasWork,
			'With optimizePdfs=0 a newly uploaded PDF must not be enqueued for optimization (plan 8.8).'
		);

		// Also confirm that directly trying to optimize it via addItemToQueue
		// is blocked at the processable check.
		$imageModel = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertNotFalse( $imageModel, 'PDF image model must be retrievable.' );
		$this->assertFalse(
			$imageModel->isProcessable(),
			'isProcessable() must return false for a PDF when optimizePdfs=0 (plan 8.8).'
		);
	}

	/**
	 * For an already-optimized image that was originally processed with SmartCrop,
	 * requesting WebP companion generation must produce a WebP file with resize=4
	 * in the reducer request (so the SmartCropped version is used as the source).
	 *
	 * Manual plan 24.6.
	 */
	public function test_smartcrop_webp_companions_generated_for_already_optimized_image() {
		\wpSPIO()->settings()->useSmartcrop = 1;
		\wpSPIO()->settings()->resizeImages = 0;
		\wpSPIO()->settings()->createWebp   = 0;
		\wpSPIO()->settings()->createAvif   = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'Pre-condition: image must be optimized.' );

		// Now enable WebP and re-optimize (companion-only run).
		$this->api->reset();
		\wpSPIO()->settings()->createWebp = 1;

		// The pass-1 row stays in the queue table at QSTATUS_DONE ('wait' mode)
		// and isItemInQueue() treats it as active, blocking the re-add.
		$this->purgeQueueTable();

		$this->optimizeAttachment( $id );

		$reducerCalls = array_values( array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'reducer' );
			}
		) );

		$found = false;
		foreach ( $reducerCalls as $call ) {
			$req      = $call['request'];
			$converts = array( isset( $req['convertto'] ) ? (string) $req['convertto'] : '' );
			foreach ( (array) ( $req['paramlist'] ?? array() ) as $entry ) {
				$entry = (array) $entry;
				if ( isset( $entry['convertto'] ) ) {
					$converts[] = (string) $entry['convertto'];
				}
			}
			// Companion-only jobs use bare 'webp' (no '+') in the per-size
			// paramlist — '+' means "in addition to base optimization"
			// (QueueItem::newOptimizeData()).
			$this->assertStringContainsString(
				'webp',
				implode( '|', $converts ),
				'WebP-companion request must request webp conversion (plan 24.6).'
			);
			$paramlist = isset( $req['paramlist'] ) ? array_values( (array) $req['paramlist'] ) : array();
			foreach ( $paramlist as $entry ) {
				if ( isset( ((array) $entry)['resize'] ) && (int) ((array) $entry)['resize'] === 4 ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue(
			$found,
			'WebP companion request for a SmartCrop-optimized image must still carry resize=4 in paramlist (plan 24.6).'
		);

		$freshImage = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$webp       = $freshImage->getWebp();
		$this->assertNotFalse( $webp, 'Image must expose a WebP companion (plan 24.6).' );
		$this->assertTrue( $webp->exists(), 'WebP companion file must exist on disk (plan 24.6).' );
	}

	/**
	 * For an already-optimized SmartCrop image, requesting AVIF companion
	 * generation must produce an AVIF file.
	 *
	 * Manual plan 24.7.
	 */
	public function test_smartcrop_avif_companions_generated_for_already_optimized_image() {
		\wpSPIO()->settings()->useSmartcrop = 1;
		\wpSPIO()->settings()->resizeImages = 0;
		\wpSPIO()->settings()->createWebp   = 0;
		\wpSPIO()->settings()->createAvif   = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'Pre-condition: image must be optimized.' );

		$this->api->reset();
		\wpSPIO()->settings()->createAvif = 1;

		// The pass-1 row stays in the queue table at QSTATUS_DONE ('wait' mode)
		// and isItemInQueue() treats it as active, blocking the re-add.
		$this->purgeQueueTable();

		$this->optimizeAttachment( $id );

		$reducerCalls = array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'reducer' );
			}
		);
		$this->assertNotEmpty( $reducerCalls, 'AVIF companion run must call the reducer (plan 24.7).' );

		foreach ( $reducerCalls as $call ) {
			$req      = $call['request'];
			$converts = array( isset( $req['convertto'] ) ? (string) $req['convertto'] : '' );
			foreach ( (array) ( $req['paramlist'] ?? array() ) as $entry ) {
				$entry = (array) $entry;
				if ( isset( $entry['convertto'] ) ) {
					$converts[] = (string) $entry['convertto'];
				}
			}
			// Companion-only jobs use bare 'avif' (no '+') in the per-size
			// paramlist (QueueItem::newOptimizeData()).
			$this->assertStringContainsString(
				'avif',
				implode( '|', $converts ),
				'AVIF companion request must request avif conversion (plan 24.7).'
			);
		}

		$freshImage = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$avif       = $freshImage->getAvif();
		$this->assertNotFalse( $avif, 'Image must expose an AVIF companion after the AVIF companion run (plan 24.7).' );
		$this->assertTrue( $avif->exists(), 'AVIF companion file must exist on disk (plan 24.7).' );
	}

	/**
	 * Uploading the FIRST image in a month: the year/month subdirectory inside
	 * the WP uploads base must be (re)created, the upload and optimize pipeline
	 * must succeed, and the ShortPixel backup directory for that month must also
	 * be created on the first optimize call.
	 *
	 * The test simulates a fresh month by rewriting the uploads year/month via
	 * the `upload_dir` filter to a month subdir that does not exist yet. The
	 * real current-month dir must NOT be deleted: wp_upload_dir() caches its
	 * dir-exists check per process ($tested_paths), so a deleted dir would
	 * never be recreated and every later upload in the run would fail. A
	 * filtered, never-seen path goes through wp_mkdir_p() exactly like a real
	 * month rollover does.
	 *
	 * Manual plan 2.21.
	 */
	public function test_first_image_of_month_creates_folders_and_optimizes() {
		$uploads  = wp_upload_dir();
		$freshSub = '/2030/01';
		$monthDir = $uploads['basedir'] . $freshSub;

		// A previous run of this test may have left the dir behind (the WP
		// install persists across runs). Safe to remove here: this process has
		// not touched the path yet, so it is not in wp_upload_dir's cache.
		if ( is_dir( $monthDir ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $monthDir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $iterator as $entry ) {
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
			rmdir( $monthDir );
		}

		$freshMonthFilter = function ( $dirs ) use ( $freshSub ) {
			$dirs['subdir'] = $freshSub;
			$dirs['path']   = $dirs['basedir'] . $freshSub;
			$dirs['url']    = $dirs['baseurl'] . $freshSub;
			return $dirs;
		};
		add_filter( 'upload_dir', $freshMonthFilter );

		$this->assertDirectoryDoesNotExist(
			$monthDir,
			'Pre-condition: the fresh uploads month dir must not exist before the first upload.'
		);

		// Upload a fixture — WP's wp_mkdir_p() inside wp_upload_dir() must
		// create the new year/month directory as part of the upload.
		$id = $this->uploadFixture( 'fixture-small.jpg' );

		remove_filter( 'upload_dir', $freshMonthFilter );

		$this->assertDirectoryExists(
			$monthDir,
			'The uploads year/month directory must be (re)created when the first image of the month is uploaded (plan 2.21).'
		);

		// Confirm the uploaded file itself is on disk.
		$uploadedPath = get_attached_file( $id );
		$this->assertFileExists(
			$uploadedPath,
			'The uploaded file must exist on disk after upload (plan 2.21).'
		);

		// Optimize.
		$this->optimizeAttachment( $id );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertTrue(
			$image->isOptimized(),
			'The first image of the month must be optimized successfully (plan 2.21).'
		);

		// The ShortPixel backup directory for this image must have been created
		// by the optimization. Derive the expected path via BackupController rather
		// than hardcoding the directory structure, since the backup root mirrors the
		// uploads tree relative to the WP install root.
		$backup = BackupController::getBackupModel( $image );
		$this->assertNotFalse(
			$backup,
			'BackupController must return a backup model for the optimized attachment (plan 2.21).'
		);
		$this->assertTrue(
			$backup->hasBackup( $image ),
			'The backup model must report that a backup exists after the first optimization of the month (plan 2.21).'
		);

		$backupFile = $backup->getBackupFile( $image );
		$this->assertNotFalse(
			$backupFile,
			'A backup file must be retrievable via the backup model (plan 2.21).'
		);
		$this->assertTrue(
			$backupFile->exists(),
			'The backup file must physically exist on disk after first-of-month optimization (plan 2.21). Path: ' . ( $backupFile ? $backupFile->getFullPath() : 'unknown' )
		);
	}

	/**
	 * Re-optimizing an image without SmartCrop (ACTION_SMARTCROPLESS) after it
	 * was previously optimized with SmartCrop must result in a reducer request
	 * that does NOT carry resize=4, and the image must still be optimized at the
	 * end.
	 *
	 * NOTE — SmartCrop stores a thumbnail-level imageName that can differ from the
	 * default (the API renames the smartcropped file). The SPIO code reads that
	 * imageName from the `returndatalist` echo. The mock does not currently rename
	 * files in SmartCrop mode, so this test only verifies the param (resize≠4) and
	 * the end-optimized state; checking the clearance of a smartcrop-specific meta
	 * field would require knowing the field name (not yet located). This is a PINNED
	 * test for that meta aspect — flip when the imageName/smartcrop meta is exposed.
	 *
	 * Manual plan 24.8.
	 */
	public function test_reoptimize_without_smartcrop_clears_smartcrop_meta() {
		\wpSPIO()->settings()->useSmartcrop = 1;
		\wpSPIO()->settings()->resizeImages = 0;

		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'Pre-condition: image optimized with SmartCrop.' );

		// Re-optimize without smartcrop using ACTION_SMARTCROPLESS.
		$this->api->reset();
		// Clear the pass-1 QSTATUS_DONE row so the re-add is not blocked.
		$this->purgeQueueTable();
		$this->optimizeAttachment(
			$id,
			array(
				'action'    => 'reoptimize',
				'smartcrop' => \ShortPixel\Model\Image\ImageModel::ACTION_SMARTCROPLESS,
			)
		);

		$reducerCalls = array_values( array_filter(
			$this->api->requests,
			function ( $r ) {
				return false !== strpos( $r['url'], 'reducer' );
			}
		) );
		$this->assertNotEmpty( $reducerCalls, 'Re-optimize-without-smartcrop must call the reducer (plan 24.8).' );

		// Verify resize=4 is absent from every paramlist entry.
		foreach ( $reducerCalls as $call ) {
			$req       = $call['request'];
			$paramlist = isset( $req['paramlist'] ) ? array_values( (array) $req['paramlist'] ) : array();
			foreach ( $paramlist as $entry ) {
				$entry = (array) $entry;
				$this->assertNotSame(
					4,
					isset( $entry['resize'] ) ? (int) $entry['resize'] : null,
					'Re-optimize without SmartCrop must NOT send resize=4 in paramlist (plan 24.8).'
				);
			}
		}

		// PINNED: verifying that a specific "is_smartcropped" or imageName meta is
		// cleared would require knowing the DB column name. The field name could not
		// be located in class/Model/Image/ImageModel.php or the DB schema — no
		// dedicated smartcrop meta column was found. If such a field exists and is
		// cleared by ACTION_SMARTCROPLESS, add an assertion here and remove this comment.
		// Flip this assertion when fixed / field identified.
		$freshImage = $this->freshImageModel( $id );
		$this->assertTrue(
			$freshImage->isOptimized(),
			'Image must still be optimized after re-optimizing without SmartCrop (plan 24.8). Pinned: smartcrop meta clearance not verified — field not located.'
		);
	}
}
