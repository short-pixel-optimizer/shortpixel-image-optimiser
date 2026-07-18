<?php
/**
 * Integration tests: legacy → modern data migration (Wave 3).
 *
 * Three migration surfaces exist in the current code:
 *
 *  1. API key: ApiKeyModel::loadKey() consolidates the old per-option
 *     storage (wp-short-pixel-apiKey / -verifiedKey / -apiKeyTried) into
 *     the single 'spio_key' option on first load, deleting the old rows.
 *  2. Settings: SettingsModel::check() renames the legacy 'keepExif'
 *     setting to 'exif' inside the 'spio_settings' row on load.
 *  3. Optimization data: MediaLibraryModel::checkLegacy() migrates the
 *     pre-5.0 'ShortPixel' block stored inside _wp_attachment_metadata
 *     into shortpixel_postmeta rows when an image without a DB record is
 *     loaded. Triggered automatically from loadMeta()'s no-metadata branch.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\ApiKeyController;
use ShortPixel\Model\Image\ImageModel;

class LegacyMigrationTest extends SPIO_IntegrationTestCase {

	// -------------------------------------------------------------------
	// 1. API key migration (per-option → spio_key)
	// -------------------------------------------------------------------

	public function test_legacy_api_key_options_migrate_to_consolidated_option() {
		$legacyKey = str_repeat( 'b', 20 );

		// A pre-migration install: no consolidated option, only the old rows.
		delete_option( 'spio_key' );
		update_option( 'wp-short-pixel-apiKey', $legacyKey );
		update_option( 'wp-short-pixel-verifiedKey', true );
		update_option( 'wp-short-pixel-apiKeyTried', '' );

		$this->resetPluginSingletons();
		$controller = ApiKeyController::getInstance(); // constructor runs loadKey()

		$row = get_option( 'spio_key' );
		$this->assertIsArray( $row, 'loadKey() must write the consolidated spio_key option on first run.' );
		$this->assertSame( $legacyKey, $row['apiKey'], 'The legacy key value must carry over into spio_key.' );
		$this->assertTrue( (bool) $row['verifiedKey'], 'The legacy verified state must carry over.' );

		$this->assertSame( 'GONE', get_option( 'wp-short-pixel-apiKey', 'GONE' ), 'The legacy apiKey option must be deleted after migration.' );
		$this->assertSame( 'GONE', get_option( 'wp-short-pixel-verifiedKey', 'GONE' ), 'The legacy verifiedKey option must be deleted after migration.' );
		$this->assertSame( 'GONE', get_option( 'wp-short-pixel-apiKeyTried', 'GONE' ), 'The legacy apiKeyTried option must be deleted after migration.' );

		$this->assertTrue( $controller->keyIsVerified(), 'A verified legacy key must stay verified through the migration.' );
	}

	// -------------------------------------------------------------------
	// 2. Settings rename: keepExif → exif
	// -------------------------------------------------------------------

	public function test_legacy_keepexif_setting_is_renamed_on_load() {
		$row = get_option( 'spio_settings', array() );
		unset( $row['exif'] );
		$row['keepExif'] = 0; // "remove EXIF" — differs from the new default (1).
		update_option( 'spio_settings', $row );

		$this->resetPluginSingletons();
		$settings = \wpSPIO()->settings();

		// PINNED BUG: SettingsModel::check() calls set('exif', …), which
		// writes into $this->settings — but load() then overwrites
		// $this->settings with check()'s RETURN value, which only had
		// keepExif unset. The renamed value is lost, so a user's
		// keepExif=0 choice silently falls back to the default exif=1.
		$this->assertSame( 1, (int) $settings->exif, 'PINNED: the migrated keepExif=0 value is dropped and the default wins (should be 0).' );

		// The removal half of the rename does work: after the shutdown
		// save, the legacy key is gone from the persisted row.
		$settings->onShutdown();
		$saved = get_option( 'spio_settings' );
		$this->assertArrayNotHasKey( 'keepExif', $saved, 'The legacy keepExif key must be removed from the persisted settings.' );
		$this->assertArrayNotHasKey( 'exif', $saved, 'PINNED: the renamed exif value never reaches the persisted settings (should be present with value 0).' );
	}

	// -------------------------------------------------------------------
	// 3. Legacy optimization data (checkLegacy)
	// -------------------------------------------------------------------

	/** Seed a legacy 'ShortPixel' block into an attachment's WP metadata. */
	private function seedLegacyBlock( int $attachment_id, array $block, $improvement = null ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata['ShortPixel'] = $block;
		if ( null !== $improvement ) {
			$metadata['ShortPixelImprovement'] = $improvement;
		}
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return $metadata;
	}

	/** status values of all shortpixel_postmeta rows for an attachment. */
	private function postmetaStatuses( int $attachment_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}shortpixel_postmeta WHERE attach_id = %d", $attachment_id )
			)
		);
	}

	public function test_legacy_optimized_attachment_migrates_into_modern_meta() {
		$id       = $this->uploadFixture( 'fixture-small.jpg' );
		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'], 'The fixture must generate thumbnails for the thumbsOptList part of the migration.' );

		$thumbFiles = array();
		foreach ( $metadata['sizes'] as $size ) {
			$thumbFiles[] = $size['file'];
		}

		$legacyDate = '2021-05-04 10:00:00';
		$this->seedLegacyBlock(
			$id,
			array(
				'type'          => 'lossy',
				'date'          => $legacyDate,
				'exifKept'      => 1,
				'thumbsOptList' => $thumbFiles,
			),
			'25' // legacy stored improvement as a string percentage.
		);

		$fileSize = filesize( get_attached_file( $id ) );

		// Loading the model with no DB record triggers checkLegacy() + saveMeta().
		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );

		$this->assertTrue( $image->isOptimized(), 'A legacy-optimized attachment must load as optimized after migration.' );
		$this->assertSame( ImageModel::COMPRESSION_LOSSY, (int) $image->getMeta( 'compressionType' ), "Legacy type 'lossy' must map to COMPRESSION_LOSSY." );
		$this->assertSame( 25, (int) $image->getMeta( 'improvement' ), 'The legacy improvement percentage must carry over.' );
		$this->assertTrue( (bool) $image->getMeta( 'wasConverted' ), 'The migrated record must be flagged wasConverted.' );

		// No backup exists, so originalSize is back-calculated from the improvement.
		$this->assertEqualsWithDelta(
			( $fileSize / 75 ) * 100,
			(float) $image->getMeta( 'originalSize' ),
			2.0,
			'originalSize must be back-calculated from the improvement percentage when no backup exists.'
		);

		$this->assertSame(
			strtotime( $legacyDate ),
			(int) $image->getMeta( 'tsOptimized' ),
			'The legacy optimization date must become tsOptimized.'
		);

		// Main + thumbnails must all have migrated DB rows in SUCCESS state.
		$statuses = $this->postmetaStatuses( $id );
		$this->assertGreaterThanOrEqual( 2, count( $statuses ), 'Migration must write rows for the main image and its thumbnails.' );
		foreach ( $statuses as $status ) {
			$this->assertSame( ImageModel::FILE_STATUS_SUCCESS, $status, 'Every migrated family member must be in SUCCESS state.' );
		}

		// The re-migration guard must be stamped.
		$this->assertIsNumeric( get_post_meta( $id, '_shortpixel_was_converted', true ), 'Migration must stamp the _shortpixel_was_converted guard.' );
	}

	public function test_waiting_only_legacy_block_is_not_migrated() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->seedLegacyBlock( $id, array( 'WaitingProcessing' => true ) );

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );

		$this->assertFalse( $image->isOptimized(), 'A waiting-only legacy block holds no result and must not migrate to optimized.' );
		$this->assertSame( '', (string) get_post_meta( $id, '_shortpixel_was_converted', true ), 'No conversion guard may be stamped when nothing was migrated.' );
	}

	public function test_legacy_error_state_migrates_as_error_not_optimized() {
		$id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->seedLegacyBlock(
			$id,
			array(
				'type'    => 'lossy',
				'ErrCode' => -202,
			),
			'Optimization error' // non-numeric improvement = legacy error message.
		);

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );

		$this->assertFalse( $image->isOptimized(), 'A legacy error state must never migrate into an optimized state.' );
		$this->assertSame( 'Optimization error', $image->getMeta( 'errorMessage' ), 'The legacy error message must carry over.' );
		$this->assertIsNumeric( get_post_meta( $id, '_shortpixel_was_converted', true ), 'The error migration still counts as converted (guard stamped).' );
	}
}
