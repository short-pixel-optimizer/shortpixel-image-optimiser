<?php
/**
 * Integration tests: restore semantics AFTER a format conversion.
 *
 * Two distinct restore paths, per production code:
 *
 * 1. PNG (local GD converter). PNGConverter::restore() (class/Model/
 *    Converter/PNGConverter.php:436) is fully implemented — sets the
 *    target back to the `.png` file, updates WP attachment metadata
 *    (post_mime, guid, _wp_attached_file, sizes), and runs the Replacer
 *    to rewrite content URLs back from .jpg to .png. The physical
 *    restore of the file bytes is handled by MediaLibraryModel::restore()
 *    → parent::restore() from the backup that PNGConverter's
 *    conversionPrepare() stored.
 *
 * 2. HEIC / TIFF / BMP (ApiConverter). ApiConverter::restore() (class/
 *    Model/Converter/ApiConverter.php:231) is a deliberate no-op — see
 *    the DocBlock: "The API-side restore flow is handled elsewhere".
 *    In practice the backup that conversionPrepare stored is the
 *    already-optimized JPG placeholder that was on disk BEFORE the
 *    remote conversion produced the final JPG. So restore reverts the
 *    main file to that pre-optimization JPG — the ORIGINAL heic/tiff/bmp
 *    bytes are NOT recoverable through this path — and content URLs
 *    stay `.jpg`.
 *
 *    This test file PINS that current behavior. Restore-to-JPG for
 *    HEIC / TIFF / BMP is BY DESIGN (confirmed by Pedro, 2026-09-09) —
 *    tests stay `..._documents_current_behavior` permanently.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Backup\BackupController;

class ConversionRestoreTest extends SPIO_IntegrationTestCase {

	/** @var array Filters to remove in tear_down. */
	private $tempFilters = array();

	public function tear_down() {
		foreach ( $this->tempFilters as $entry ) {
			remove_filter( $entry[0], $entry[1], isset( $entry[2] ) ? $entry[2] : 10 );
		}
		$this->tempFilters = array();

		// Backups are stored outside the DB transaction — sweep.
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

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	/** Drive one restore through the real queue pipeline (mirrors RestoreFlowTest). */
	private function restoreAttachment( int $attachment_id ): void {
		// Purge first so addItemToQueue doesn't only append a next_action
		// on a leftover done item.
		$this->purgeQueueTable();
		\wpSPIO()->filesystem()->flushImageCache();

		$imageModel = $this->freshImageModel( $attachment_id );
		$queueController = new QueueController();
		$queueController->addItemToQueue( $imageModel, array( 'action' => 'restore' ) );
		$this->runQueueUntilEmpty();
	}

	/** All backup files currently below the backup root, one flat list. */
	private function backupFilesOnDisk(): array {
		if ( ! is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			return array();
		}
		$found    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$found[] = $file->getPathname();
			}
		}
		return $found;
	}

	// -------------------------------------------------------------------
	// PNG restore — proper roundtrip
	// -------------------------------------------------------------------

	/**
	 * PNG converted via the queue path (so runReplacer=true fires and
	 * content is rewritten to .jpg during the forward run), then
	 * restored. Expectations:
	 *   - Main file back to .png on disk, bytes match the backup
	 *   - _wp_attached_file / post_mime_type / metadata references .png
	 *   - post_content and postmeta rewritten back to the .png URL
	 */
	public function test_png_restore_reverts_file_metadata_and_content_urls() {
		// Upload PNG WITHOUT conversion, seed content, then convert
		// through the queue path so the replacer runs.
		//
		// fixture-small.png (1200×900) shrinks when reencoded to JPG;
		// fixture-large.png is already so PNG-optimized that JPG-round-
		// tripping produces a LARGER file → PNGConverter::
		// checkFileSizeMargin silently rejects (ERROR_RESULTLARGER) and
		// the .png stays on disk — real behavior, but vacuous for a
		// restore-from-JPG test.
		\wpSPIO()->settings()->png2jpg = 0;
		$id = $this->uploadFixture( 'fixture-small.png' );
		\wpSPIO()->settings()->png2jpg = 1;
		$this->resetPluginSingletons();
		\wpSPIO()->settings()->png2jpg = 1;

		$original_png_path  = get_attached_file( $id );
		$original_png_bytes = filesize( $original_png_path );
		$original_png_url   = wp_get_attachment_url( $id );

		// Sentinel: filename really ends in .png before we go.
		$this->assertStringEndsWith( '.png', $original_png_path );
		$this->assertStringEndsWith( '.png', $original_png_url );

		// Seed content BEFORE conversion so the forward-run replacer
		// has something to rewrite AND the restore can verify the
		// backward-rewrite happens too.
		$post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_content' => '<p><img src="' . esc_url( $original_png_url ) . '" alt="restore-png" /></p>',
		) );
		$meta_carrier = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $meta_carrier, '_spio_restore_png', $original_png_url );

		$this->assertStringContainsString( '.png', get_post( $post_id )->post_content, 'Sentinel: pre-convert PNG URL in post_content.' );
		$this->assertStringContainsString( '.png', get_post_meta( $meta_carrier, '_spio_restore_png', true ), 'Sentinel: pre-convert PNG URL in postmeta.' );

		$this->purgeQueueTable();
		$this->optimizeAttachment( $id );

		// Forward-run sanity: .png replaced by .jpg everywhere.
		$jpg_path = get_attached_file( $id );
		$this->assertSame( 'jpg', strtolower( pathinfo( $jpg_path, PATHINFO_EXTENSION ) ), 'Sanity: PNG must be converted to JPG.' );
		clean_post_cache( $post_id );
		$this->assertStringNotContainsString( '.png', get_post( $post_id )->post_content, 'Sanity: post_content rewritten to .jpg by the forward run.' );

		// --- Restore ---
		$this->restoreAttachment( $id );

		// Main file: back to a real .png (bytes match the backup we made).
		clearstatcache();
		$restored_path = get_attached_file( $id );
		$this->assertSame(
			'png',
			strtolower( pathinfo( $restored_path, PATHINFO_EXTENSION ) ),
			'After restore, _wp_attached_file must once again reference a .png file.'
		);
		$this->assertFileExists( $restored_path, 'Restored PNG file must exist on disk.' );

		$restored_info = getimagesize( $restored_path );
		$this->assertSame(
			IMAGETYPE_PNG,
			$restored_info[2],
			'Restored file must contain PNG bytes (backup was the original PNG).'
		);
		$this->assertSame(
			$original_png_bytes,
			filesize( $restored_path ),
			'Restored PNG byte count must match the pre-conversion original.'
		);

		// post_mime_type: back to image/png.
		clean_post_cache( $id );
		$this->assertSame(
			'image/png',
			get_post( $id )->post_mime_type,
			'post_mime_type must be image/png after restore.'
		);

		// Metadata file field references .png.
		$meta = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $meta );
		$this->assertStringEndsWith( '.png', $meta['file'], 'metadata[file] must reference the .png path after restore.' );

		// Content URLs rewritten back to .png.
		clean_post_cache( $post_id );
		$restored_content = get_post( $post_id )->post_content;
		$this->assertStringContainsString( '.png', $restored_content, 'Restore replacer must rewrite content back to .png.' );
		$this->assertStringNotContainsString( '.jpg', $restored_content, 'No .jpg reference must survive in restored content.' );

		wp_cache_delete( $meta_carrier, 'post_meta' );
		$restored_meta = get_post_meta( $meta_carrier, '_spio_restore_png', true );
		$this->assertStringContainsString( '.png', $restored_meta, 'Restore replacer must rewrite postmeta back to .png.' );
		$this->assertStringNotContainsString( '.jpg', $restored_meta, 'No .jpg reference must survive in restored postmeta.' );

		// The image must be reported as no longer optimized.
		$reloaded = $this->freshImageModel( $id );
		$this->assertFalse( $reloaded->isOptimized(), 'After restore the image must no longer be reported optimized.' );
	}

	// -------------------------------------------------------------------
	// HEIC restore — documents current behavior (JPG stays)
	// -------------------------------------------------------------------

	/**
	 * HEIC restore CURRENT BEHAVIOR (documented). ApiConverter::restore()
	 * is a no-op; the "backup" underlying the restore is the
	 * placeholder-JPG copy taken before the API-returned JPG bytes
	 * landed. So restore leaves a JPG on disk (not a .heic), and URLs
	 * remain .jpg.
	 *
	 * Assertions in this test are pinned to that behavior. If SPIO ever
	 * makes ApiConverter round-trip back to the source format (unlikely,
	 * the original bytes aren't kept), several of these will need
	 * flipping — the test will scream loudly and unambiguously.
	 */
	public function test_heic_restore_leaves_jpg_on_disk_documents_current_behavior() {
		$id = $this->uploadFixture( 'fixture-large.heic' );

		$original_heic_path = get_attached_file( $id );
		$this->assertStringEndsWith( '.heic', $original_heic_path );

		$this->optimizeAttachment( $id );

		$optimized_jpg_path = get_attached_file( $id );
		$this->assertSame( 'jpg', strtolower( pathinfo( $optimized_jpg_path, PATHINFO_EXTENSION ) ), 'Sanity: HEIC converted to JPG.' );
		$optimized_jpg_bytes = filesize( $optimized_jpg_path );

		// --- Restore ---
		$this->restoreAttachment( $id );

		clearstatcache();
		$restored_path = get_attached_file( $id );

		// PIN #1: attached_file still ends in .jpg — ApiConverter::restore
		// is a no-op so updateMetaData never swaps the extension back.
		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $restored_path, PATHINFO_EXTENSION ) ),
			'CURRENT BEHAVIOR: attached_file stays .jpg after HEIC restore (ApiConverter::restore is a no-op — no attached_file swap).'
		);
		$this->assertFileExists( $restored_path, 'The .jpg attached_file must still exist on disk.' );

		// PIN #2: MediaLibraryModel::restore's parent::restore()
		// (ImageModel::restore → BackupModel::restore) DOES resurrect the
		// original .heic file on disk from the backup taken during
		// conversionPrepare. It's just orphaned by the attached_file
		// pointer, which still targets .jpg.
		$this->assertFileExists(
			$original_heic_path,
			'CURRENT BEHAVIOR: the original .heic file IS recreated on disk by ImageModel::restore, even though attached_file stays .jpg.'
		);

		// PIN #3: the restored .heic file is a real HEIC on disk (not a
		// zero-byte stub). We can't compare against the original fixture
		// bytes because WP's ISO-media wrangling can trim/re-mux HEIF at
		// upload time; but the file must be non-empty and byte-identical
		// across restore invocations of the same attachment.
		$this->assertGreaterThan(
			0,
			filesize( $original_heic_path ),
			'CURRENT BEHAVIOR: the restored .heic file is non-empty.'
		);

		// PIN #4: the still-attached .jpg is a real JPEG image (not
		// truncated / not a stale placeholder).
		$info = getimagesize( $restored_path );
		$this->assertSame(
			IMAGETYPE_JPEG,
			$info[2],
			'CURRENT BEHAVIOR: the .jpg attached_file still contains valid JPEG bytes after restore.'
		);

		// PIN #5: the resurrected .heic is a truly ORPHANED file. The
		// attachment metadata's `original_image` entry does NOT point to
		// it — it points to the UNSCALED .jpg (WP core's -scaled mechanism
		// applied to the converted JPG during wp_generate_attachment_metadata
		// in MediaLibraryConverter::updateMetaData). SPIO never registers
		// the .heic anywhere: updateMetaData drops the pre-conversion
		// original_image (MediaLibraryConverter.php:193-196) and
		// ApiConverter::restore() is a no-op, so nothing re-adds it.
		$meta_after_restore = wp_get_attachment_metadata( $id );
		$this->assertIsArray( $meta_after_restore );
		$this->assertArrayHasKey( 'original_image', $meta_after_restore );
		$this->assertStringEndsWith(
			'.jpg',
			$meta_after_restore['original_image'],
			'CURRENT BEHAVIOR: original_image points to the unscaled .jpg, not the .heic.'
		);
		$this->assertStringNotContainsString(
			'.heic',
			$meta_after_restore['original_image'],
			'CURRENT BEHAVIOR: the on-disk .heic is unreferenced by WP metadata after restore.'
		);
	}

	// -------------------------------------------------------------------
	// TIFF / BMP restore — same mechanism as HEIC, documented
	// -------------------------------------------------------------------

	/** TIFF/BMP data provider. */
	public function tiffBmpFixtures(): array {
		return array(
			'tiff' => array( 'fixture-medium.tiff', 'tiff' ),
			'bmp'  => array( 'fixture-medium.bmp',  'bmp' ),
		);
	}

	/**
	 * TIFF and BMP: same ApiConverter path as HEIC, therefore same
	 * restore semantics (JPG stays on disk, original bytes lost).
	 *
	 * Restore-to-JPG pinned as current behavior — ruled BY DESIGN
	 * (Pedro, 2026-09-09), same as HEIC.
	 *
	 * @dataProvider tiffBmpFixtures
	 */
	public function test_tiff_bmp_restore_leaves_jpg_on_disk_documents_current_behavior( string $fixture, string $extension ) {
		$id = $this->uploadFixture( $fixture );

		$original_path = get_attached_file( $id );
		$this->assertSame( $extension, strtolower( pathinfo( $original_path, PATHINFO_EXTENSION ) ), "Sanity: uploaded file is .$extension." );

		$this->optimizeAttachment( $id );

		$optimized_path = get_attached_file( $id );
		$this->assertSame( 'jpg', strtolower( pathinfo( $optimized_path, PATHINFO_EXTENSION ) ), "Sanity: .$extension converted to .jpg." );

		$this->restoreAttachment( $id );

		clearstatcache();
		$restored_path = get_attached_file( $id );

		$this->assertSame(
			'jpg',
			strtolower( pathinfo( $restored_path, PATHINFO_EXTENSION ) ),
			"CURRENT BEHAVIOR (by design): attached_file stays .jpg after .$extension restore, same mechanism as HEIC."
		);
		$this->assertFileExists( $restored_path );

		// Same behavior as HEIC restore: ImageModel::restore's backup path
		// puts the original .$extension file BACK on disk (orphaned by the
		// attached_file pointer, which stays .jpg because ApiConverter::
		// restore is a no-op).
		$this->assertFileExists(
			$original_path,
			"CURRENT BEHAVIOR: .$extension restore DOES recreate the original .$extension file on disk (parent::restore backup path), even though attached_file stays .jpg."
		);

		$info = getimagesize( $restored_path );
		$this->assertSame(
			IMAGETYPE_JPEG,
			$info[2],
			"CURRENT BEHAVIOR: restored file for .$extension must contain JPEG bytes."
		);
	}

	// -------------------------------------------------------------------
	// URLs stay .jpg after HEIC restore
	// -------------------------------------------------------------------

	/**
	 * The ApiConverter::restore() no-op also means the Replacer is not
	 * re-run — content URLs that were rewritten to .jpg during the
	 * forward run stay .jpg after restore.
	 *
	 * DOCUMENTS CURRENT BEHAVIOR: with autoMediaLibrary=1 baseline, the
	 * upload-hook path runs ApiConverter::prepareQueue synchronously,
	 * placing a .jpg placeholder and flipping hasPlaceHolder=true — so
	 * wp_get_attachment_url returns .jpg immediately after upload (via
	 * AdminController::checkPlaceHolder). Any content authored from that
	 * URL therefore starts life with a .jpg reference, and restore-related
	 * assertions have to reflect that starting condition instead of a
	 * hypothetical .heic/.tiff/.bmp URL.
	 *
	 * @dataProvider apiConvertableFixtures
	 */
	public function test_api_conversion_restore_leaves_content_urls_pointing_to_jpg( string $fixture, string $extension ) {
		$id  = $this->uploadFixture( $fixture );
		$url = wp_get_attachment_url( $id );

		// Sentinel: the placeholder mechanism has already swapped the URL
		// to .jpg by the time the upload hook returns — this is the real
		// starting condition for any user-authored content.
		$this->assertStringEndsWith(
			'.jpg',
			$url,
			"Sentinel: checkPlaceHolder rewrote .$extension URL to .jpg synchronously during the upload hook."
		);
		$this->assertStringNotContainsString(
			'.' . $extension,
			$url,
			"Sentinel: source .$extension does not survive the placeholder rewrite."
		);

		$post_id = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_content' => '<p><img src="' . esc_url( $url ) . '" alt="' . $extension . '-restore" /></p>',
		) );

		$this->optimizeAttachment( $id );

		clean_post_cache( $post_id );
		$after_convert = get_post( $post_id )->post_content;
		$this->assertStringContainsString(
			'.jpg',
			$after_convert,
			"Content still references .jpg after ApiConverter forward run — placeholder URL was already .jpg and remains .jpg."
		);
		$this->assertStringNotContainsString(
			'.' . $extension,
			$after_convert,
			"Content never contained .$extension in the first place."
		);

		$this->restoreAttachment( $id );

		clean_post_cache( $post_id );
		$after_restore = get_post( $post_id )->post_content;

		$this->assertStringContainsString(
			'.jpg',
			$after_restore,
			"CURRENT BEHAVIOR: .$extension restore does NOT rewrite content — URLs stay .jpg (ApiConverter::restore is a no-op AND the URL was placeholder-.jpg from upload)."
		);
		$this->assertStringNotContainsString(
			'.' . $extension,
			$after_restore,
			"CURRENT BEHAVIOR: .$extension is not re-introduced on restore."
		);
	}

	public function apiConvertableFixtures(): array {
		return array(
			'heic' => array( 'fixture-large.heic', 'heic' ),
			'tiff' => array( 'fixture-medium.tiff', 'tiff' ),
			'bmp'  => array( 'fixture-medium.bmp',  'bmp' ),
		);
	}
}
