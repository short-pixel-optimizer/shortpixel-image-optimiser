<?php
/**
 * Ability execute-callback unit tests: input validation, guard clauses,
 * and the settings read/write whitelists.
 *
 * The execute callbacks are plain static methods, so they are testable
 * without the Abilities API being present (WP < 6.9 included). This suite
 * runs in the unit environment, whose baseline is an EMPTY, UNVERIFIED API
 * key — which is exactly the state the key/quota guard clauses protect
 * against. End-to-end behaviour (mock API, queue processing) lives in
 * tests/Integration/test-AbilitiesIntegration.php.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Abilities\BulkGenerateAiSeoAbility;
use ShortPixel\Controller\Abilities\BulkOptimizeAbility;
use ShortPixel\Controller\Abilities\BulkRestoreAbility;
use ShortPixel\Controller\Abilities\BulkUndoAiSeoAbility;
use ShortPixel\Controller\Abilities\GenerateAiSeoAbility;
use ShortPixel\Controller\Abilities\GetAiSeoStatusAbility;
use ShortPixel\Controller\Abilities\GetMediaStatusAbility;
use ShortPixel\Controller\Abilities\GetSettingsAbility;
use ShortPixel\Controller\Abilities\GetStatsAbility;
use ShortPixel\Controller\Abilities\OptimizeMediaAbility;
use ShortPixel\Controller\Abilities\RestoreMediaAbility;
use ShortPixel\Controller\Abilities\UndoAiSeoAbility;
use ShortPixel\Controller\Abilities\UpdateSettingsAbility;
use ShortPixel\Model\Image\ImageModel;

class AbilitiesExecuteTest extends WP_UnitTestCase {

	/** @var array Settings values touched by tests, restored in tear_down. */
	private $settingsBackup = array();

	public function set_up() {
		parent::set_up();
		$this->settingsBackup = array();
	}

	public function tear_down() {
		$settings = \wpSPIO()->settings();
		foreach ( $this->settingsBackup as $key => $value ) {
			$settings->$key = $value;
		}
		parent::tear_down();
	}

	/** Remember a setting's current value and set a new one for this test. */
	private function setSetting( string $key, $value ): void {
		$settings = \wpSPIO()->settings();
		if ( ! array_key_exists( $key, $this->settingsBackup ) ) {
			$this->settingsBackup[ $key ] = $settings->$key;
		}
		$settings->$key = $value;
	}

	// ------------------------------------------------------------------
	// get-media-status
	// ------------------------------------------------------------------

	public function test_get_media_status_requires_a_valid_id() {
		foreach ( array( array(), array( 'id' => 0 ), array( 'id' => -5 ), null ) as $args ) {
			$result = GetMediaStatusAbility::execute( $args );
			$this->assertTrue( $result['error'], 'Missing/invalid id must return an error payload' );
		}
	}

	public function test_get_media_status_rejects_unknown_type() {
		$result = GetMediaStatusAbility::execute( array( 'id' => 1, 'type' => 'gallery' ) );
		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( '"media" or "custom"', $result['message'] );
	}

	public function test_get_media_status_reports_missing_image() {
		$result = GetMediaStatusAbility::execute( array( 'id' => 999999, 'type' => 'media' ) );
		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'not found', $result['message'] );
	}

	public function test_get_media_status_returns_status_for_a_real_attachment() {
		$attachment_id = self::factory()->attachment->create_upload_object(
			dirname( __DIR__ ) . '/fixtures/fixture-small.jpg'
		);

		$result = GetMediaStatusAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertArrayNotHasKey( 'error', $result, 'A successful status lookup has no error key' );
		$this->assertSame( $attachment_id, $result['id'] );
		$this->assertSame( 'media', $result['type'] );
		$this->assertFalse( $result['is_optimized'], 'A fresh upload must not report as optimized' );
		$this->assertSame( 'unprocessed', $result['status'], 'A fresh upload must report status unprocessed' );

		wp_delete_attachment( $attachment_id, true );
	}

	// ------------------------------------------------------------------
	// optimize-media
	// ------------------------------------------------------------------

	public function test_optimize_media_requires_valid_id_and_type() {
		$result = OptimizeMediaAbility::execute( array() );
		$this->assertTrue( $result['error'] );

		$result = OptimizeMediaAbility::execute( array( 'id' => 5, 'type' => 'nextgen' ) );
		$this->assertTrue( $result['error'] );
	}

	public function test_optimize_media_refuses_to_run_without_a_verified_key() {
		// Unit baseline: empty, unverified key.
		$result = OptimizeMediaAbility::execute( array( 'id' => 5 ) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'API key is not verified', $result['message'] );
	}

	// ------------------------------------------------------------------
	// restore-media
	// ------------------------------------------------------------------

	public function test_restore_media_requires_valid_id_and_type() {
		$this->assertTrue( RestoreMediaAbility::execute( array() )['error'] );
		$this->assertTrue( RestoreMediaAbility::execute( array( 'id' => 3, 'type' => 'bogus' ) )['error'] );
	}

	public function test_restore_media_errors_when_image_is_not_optimized() {
		$attachment_id = self::factory()->attachment->create_upload_object(
			dirname( __DIR__ ) . '/fixtures/fixture-small.jpg'
		);

		$result = RestoreMediaAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'not optimized', $result['message'] );

		wp_delete_attachment( $attachment_id, true );
	}

	// ------------------------------------------------------------------
	// Bulk confirm gates
	// ------------------------------------------------------------------

	public function test_bulk_abilities_refuse_to_run_without_confirm_true() {
		$cases = array(
			'bulk-restore'         => BulkRestoreAbility::execute( array() ),
			'bulk-optimize'        => BulkOptimizeAbility::execute( array( 'confirm' => false ) ),
			'bulk-generate-ai-seo' => BulkGenerateAiSeoAbility::execute( array( 'confirm' => 0 ) ),
			'bulk-undo-ai-seo'     => BulkUndoAiSeoAbility::execute( array() ),
		);

		foreach ( $cases as $name => $result ) {
			$this->assertTrue( $result['error'], "$name must refuse to run without confirm=true" );
			$this->assertStringContainsString( 'confirm=true', $result['message'], "$name must tell the agent how to confirm" );
		}
	}

	public function test_bulk_restore_rejects_invalid_queue_names_before_touching_queues() {
		$result = BulkRestoreAbility::execute( array( 'confirm' => true, 'queues' => 'gallery' ) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'Queues must be', $result['message'] );
	}

	public function test_bulk_optimize_requires_verified_key_before_any_queue_work() {
		$result = BulkOptimizeAbility::execute( array( 'confirm' => true ) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'API key is not verified', $result['message'] );
	}

	// ------------------------------------------------------------------
	// AI SEO abilities
	// ------------------------------------------------------------------

	public function test_ai_seo_abilities_require_a_valid_media_id() {
		$this->assertTrue( GetAiSeoStatusAbility::execute( array() )['error'] );
		$this->assertTrue( GenerateAiSeoAbility::execute( array( 'id' => 0 ) )['error'] );
		$this->assertTrue( UndoAiSeoAbility::execute( array() )['error'] );
	}

	public function test_ai_seo_abilities_reject_custom_media_type() {
		foreach ( array( GetAiSeoStatusAbility::class, GenerateAiSeoAbility::class, UndoAiSeoAbility::class ) as $ability ) {
			$result = $ability::execute( array( 'id' => 7, 'type' => 'custom' ) );
			$this->assertTrue( $result['error'], "$ability must reject type=custom" );
			$this->assertStringContainsString( 'Media Library', $result['message'] );
		}
	}

	public function test_generate_ai_seo_requires_verified_key() {
		$result = GenerateAiSeoAbility::execute( array( 'id' => 7 ) );
		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'API key is not verified', $result['message'] );
	}

	public function test_undo_ai_seo_errors_when_nothing_was_generated() {
		$attachment_id = self::factory()->attachment->create_upload_object(
			dirname( __DIR__ ) . '/fixtures/fixture-small.jpg'
		);

		$result = UndoAiSeoAbility::execute( array( 'id' => $attachment_id ) );

		$this->assertTrue( $result['error'] );
		$this->assertStringContainsString( 'No AI SEO data', $result['message'] );

		wp_delete_attachment( $attachment_id, true );
	}

	// ------------------------------------------------------------------
	// get-settings
	// ------------------------------------------------------------------

	public function test_get_settings_returns_whitelisted_keys_and_never_the_api_key() {
		$result = GetSettingsAbility::execute();

		foreach ( GetSettingsAbility::WHITELISTED_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $result, "Whitelisted setting $key must be present" );
		}

		$this->assertArrayNotHasKey( 'apiKey', $result, 'The API key must NEVER be exposed' );
		$this->assertIsBool( $result['api_key_verified'] );
		$this->assertFalse( $result['api_key_verified'], 'Unit baseline has an unverified key' );
	}

	public function test_get_settings_labels_the_compression_type() {
		$this->setSetting( 'compressionType', ImageModel::COMPRESSION_GLOSSY );
		$this->assertSame( 'glossy', GetSettingsAbility::execute()['compression_type_label'] );

		$this->setSetting( 'compressionType', ImageModel::COMPRESSION_LOSSLESS );
		$this->assertSame( 'lossless', GetSettingsAbility::execute()['compression_type_label'] );
	}

	// ------------------------------------------------------------------
	// update-settings
	// ------------------------------------------------------------------

	public function test_update_settings_requires_a_settings_object() {
		$this->assertTrue( UpdateSettingsAbility::execute( array() )['error'] );
		$this->assertTrue( UpdateSettingsAbility::execute( array( 'settings' => array() ) )['error'] );
	}

	public function test_update_settings_skips_non_whitelisted_keys() {
		$result = UpdateSettingsAbility::execute( array(
			'settings' => array(
				'apiKey'    => 'evil-key',
				'CDNDomain' => 'https://evil.example',
				'excludePatterns' => 'name:secret',
			),
		) );

		$this->assertFalse( $result['error'] );
		$this->assertSame( array(), $result['updated'], 'No non-whitelisted key may ever be written' );
		$this->assertCount( 3, $result['skipped'] );
	}

	public function test_update_settings_validates_and_writes_booleans() {
		$this->setSetting( 'processThumbnails', 0 );

		$result = UpdateSettingsAbility::execute( array(
			'settings' => array( 'processThumbnails' => 'true' ),
		) );

		$this->assertSame( array( 'processThumbnails' => true ), $result['updated'] );
		$this->assertEquals( 1, \wpSPIO()->settings()->processThumbnails, 'The validated value must land in SettingsModel' );

		$bad = UpdateSettingsAbility::execute( array(
			'settings' => array( 'processThumbnails' => 'maybe' ),
		) );
		$this->assertArrayHasKey( 'processThumbnails', $bad['skipped'] );
	}

	public function test_update_settings_validates_integer_ranges() {
		$this->setSetting( 'png2jpg', 0 );
		$this->setSetting( 'exif', 1 );

		$result = UpdateSettingsAbility::execute( array(
			'settings' => array(
				'png2jpg' => 3,   // max 2
				'exif'    => -1,  // min 0
			),
		) );

		$this->assertSame( array(), $result['updated'] );
		$this->assertArrayHasKey( 'png2jpg', $result['skipped'] );
		$this->assertArrayHasKey( 'exif', $result['skipped'] );
	}

	public function test_update_settings_maps_compression_enum_case_insensitively() {
		$this->setSetting( 'compressionType', ImageModel::COMPRESSION_LOSSY );

		$result = UpdateSettingsAbility::execute( array(
			'settings' => array( 'compressionType' => 'GLOSSY' ),
		) );

		$this->assertSame( ImageModel::COMPRESSION_GLOSSY, $result['updated']['compressionType'] );
		$this->assertEquals( ImageModel::COMPRESSION_GLOSSY, \wpSPIO()->settings()->compressionType );

		$bad = UpdateSettingsAbility::execute( array(
			'settings' => array( 'compressionType' => 'ultra' ),
		) );
		$this->assertArrayHasKey( 'compressionType', $bad['skipped'] );
	}

	public function test_update_settings_accepts_resize_type_enum() {
		$this->setSetting( 'resizeType', 'outer' );

		$result = UpdateSettingsAbility::execute( array(
			'settings' => array( 'resizeType' => 'inner' ),
		) );

		$this->assertSame( 'inner', $result['updated']['resizeType'] );
	}

	public function test_update_settings_reports_mixed_valid_and_invalid_keys() {
		$this->setSetting( 'createWebp', 0 );

		$result = UpdateSettingsAbility::execute( array(
			'settings' => array(
				'createWebp' => 1,
				'nonsense'   => 'x',
			),
		) );

		$this->assertFalse( $result['error'] );
		$this->assertArrayHasKey( 'createWebp', $result['updated'] );
		$this->assertArrayHasKey( 'nonsense', $result['skipped'] );
		$this->assertStringContainsString( '1 setting(s) updated, 1 skipped', $result['message'] );
	}

	// ------------------------------------------------------------------
	// get-stats
	// ------------------------------------------------------------------

	public function test_get_stats_returns_the_documented_shape() {
		$result = GetStatsAbility::execute();

		$this->assertArrayHasKey( 'media_library', $result );
		$this->assertArrayHasKey( 'custom_media', $result );
		$this->assertArrayHasKey( 'totals', $result );
		$this->assertIsInt( $result['media_library']['items_optimized'] );
		$this->assertIsInt( $result['totals']['to_optimize'] );
		$this->assertGreaterThanOrEqual( 0, $result['totals']['to_optimize'] );
		$this->assertIsFloat( $result['average_compression_percent'] );
	}
}
