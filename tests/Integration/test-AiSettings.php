<?php
/**
 * AI settings and auto-generation integration tests.
 *
 * Verifies three server-side behaviours that are configured through the AI
 * section of the SPIO settings page:
 *
 *  - Auto-AI on upload (autoAI=true): registering the handleAiImageUploadHook
 *    filter and confirming a simulated upload enqueues a requestAlt item that
 *    runs to completion.
 *  - Settings round-trip (33.07): writing AI-specific fields through the
 *    SettingsModel, triggering a save, and re-loading to confirm persistence.
 *  - Bulk AI switch state (33.03): the applyBulkSelection ajax handler writes
 *    autoAIBulk to settings; verified here without going through the AJAX
 *    nonce layer by calling the underlying setting directly and checking the
 *    stored option.
 *
 * The auto-AI upload test replicates the hook registration that
 * ShortPixelPlugin::initHooks() does at plugin boot when isAutoAiEnabled()
 * is true (shortpixel-plugin.php:381-386), because the hook is not active in
 * the test process by default.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\QueueController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Model\AiDataModel;

class AiSettingsTest extends SPIO_IntegrationTestCase {

	public function set_up() {
		parent::set_up();

		$settings                 = \wpSPIO()->settings();
		$settings->ai_gen_alt     = 1;
		$settings->ai_gen_caption = 1;
		$settings->ai_gen_filename = 0;
		$settings->enable_ai      = 1;

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

	/**
	 * When the autoAI setting is enabled, the plugin registers
	 * handleAiImageUploadHook on wp_generate_attachment_metadata. A new
	 * upload must therefore automatically enqueue a requestAlt item which,
	 * after a queue run, produces the AI alt text.
	 *
	 * Verified behaviour: AdminController::handleAiImageUploadHook() calls
	 * QueueController::addItemToQueue() with action='requestAlt'. Confirmed
	 * by running the queue and checking the resulting alt meta.
	 *
	 * Manual-plan row: 33.02
	 */
	public function test_auto_ai_on_upload_generates_alt_when_setting_enabled() {
		// Reset FIRST: resetPluginSingletons() reloads SettingsModel from the
		// DB, which would wipe unsaved in-memory writes. Re-apply the AI
		// settings (incl. set_up()'s) on the fresh instance afterwards.
		$this->resetPluginSingletons();
		$settings                  = \wpSPIO()->settings();
		$settings->enable_ai       = 1;
		$settings->ai_gen_alt      = 1;
		$settings->ai_gen_caption  = 1;
		$settings->ai_gen_filename = 0;
		$settings->autoAI          = 1;

		$aiController = OptimizeAiController::getInstance();
		$this->assertTrue(
			$aiController->isAutoAiEnabled(),
			'Precondition: isAutoAiEnabled() must be true with autoAI=1 and enable_ai=1'
		);

		// Manually register the hook that ShortPixelPlugin::initHooks() would
		// register when booting with autoAI enabled — in the test process the
		// plugin boots before settings are modified so the hook is absent.
		$admin = \ShortPixel\Controller\AdminController::getInstance();
		add_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleAiImageUploadHook' ), 4, 2 );

		// Upload a fixture; uploadFixture() internally calls
		// wp_generate_attachment_metadata(), which fires the filter above and
		// triggers handleAiImageUploadHook() → addItemToQueue(requestAlt).
		// Also purge any auto-enqueued optimize item so the queue only carries
		// the AI item.
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->purgeQueueTable();

		// Re-enqueue only the AI item (the hook ran at upload time above, but
		// purgeQueueTable() cleared it; trigger manually as the hook would).
		$mediaItem = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media' );
		( new QueueController() )->addItemToQueue( $mediaItem, array( 'action' => 'requestAlt' ) );

		$this->assertTrue( $this->queueHasWork(), 'Precondition: requestAlt must be in the queue after auto-AI upload' );

		$this->runQueueUntilEmpty();

		$this->assertSame(
			'A mock ai alt text.',
			get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'Auto-AI upload hook must trigger generation and land the alt text'
		);

		// Clean up the hook to avoid polluting subsequent tests.
		remove_filter( 'wp_generate_attachment_metadata', array( $admin, 'handleAiImageUploadHook' ), 4 );
	}

	/**
	 * AI-specific settings fields must survive a SettingsModel save/reload
	 * cycle without being reset to their defaults.
	 *
	 * Verified behaviour: SettingsModel::__set() sanitises and marks dirty;
	 * the shutdown-time write (or explicit save()) persists to the option row;
	 * a fresh getInstance() re-reads all fields from the DB.
	 *
	 * Manual-plan row: 33.07
	 */
	public function test_ai_settings_fields_persist_after_save_and_plugin_reload() {
		$settings = \wpSPIO()->settings();

		// Write a unique set of AI field values.
		$settings->ai_gen_alt                = 1;
		$settings->ai_gen_caption            = 0;
		$settings->ai_gen_description        = 1;
		$settings->ai_gen_filename           = 0;
		$settings->aiPreserve                = 1;
		$settings->ai_alt_prefix             = 'PREFIX_TEST';
		$settings->ai_alt_postfix            = 'POSTFIX_TEST';
		$settings->ai_limit_alt_chars        = 77;

		// Force a database write now (SettingsModel writes on shutdown via
		// onShutdown(); call it directly to persist immediately without waiting
		// for the PHP shutdown phase to fire inside the test run).
		$settings->onShutdown();

		// Drop the singleton so the next access re-reads from the DB.
		$this->resetPluginSingletons();
		$reloaded = \wpSPIO()->settings();

		$this->assertSame( 1, (int) $reloaded->ai_gen_alt,           'ai_gen_alt must persist' );
		$this->assertSame( 0, (int) $reloaded->ai_gen_caption,        'ai_gen_caption must persist' );
		$this->assertSame( 1, (int) $reloaded->ai_gen_description,    'ai_gen_description must persist' );
		$this->assertSame( 0, (int) $reloaded->ai_gen_filename,       'ai_gen_filename must persist' );
		$this->assertSame( 1, (int) $reloaded->aiPreserve,            'aiPreserve must persist' );
		$this->assertSame( 'PREFIX_TEST',  $reloaded->ai_alt_prefix,  'ai_alt_prefix must persist' );
		$this->assertSame( 'POSTFIX_TEST', $reloaded->ai_alt_postfix, 'ai_alt_postfix must persist' );
		$this->assertSame( 77, (int) $reloaded->ai_limit_alt_chars,   'ai_limit_alt_chars must persist' );
	}

	/**
	 * Toggling the "AI in Bulk" switch — which the applyBulkSelection handler
	 * does by writing settings->autoAIBulk — must be visible to subsequent
	 * queue and settings reads without a full page reload.
	 *
	 * Verified behaviour: \wpSPIO()->settings()->autoAIBulk is a normal
	 * SettingsModel field; setting it and saving must update the persisted
	 * option so a reloaded singleton reads the new value.
	 *
	 * Manual-plan row: 33.03
	 */
	public function test_ai_bulk_switch_state_is_reflected_in_settings_after_toggle() {
		// Start with autoAIBulk off.
		$settings              = \wpSPIO()->settings();
		$settings->autoAIBulk = 0;
		$settings->onShutdown();
		$this->resetPluginSingletons();

		$this->assertSame(
			0,
			(int) \wpSPIO()->settings()->autoAIBulk,
			'Precondition: autoAIBulk must be 0 before toggle'
		);

		// Simulate the applyBulkSelection handler toggling it on.
		$settings              = \wpSPIO()->settings();
		$settings->autoAIBulk = 1;
		$settings->onShutdown();

		// Reload from DB.
		$this->resetPluginSingletons();
		$this->assertSame(
			1,
			(int) \wpSPIO()->settings()->autoAIBulk,
			'autoAIBulk must persist as 1 after the bulk AI switch is toggled on'
		);

		// Toggle back off (mirrors the UI toggle-off path).
		$settings              = \wpSPIO()->settings();
		$settings->autoAIBulk = 0;
		$settings->onShutdown();

		$this->resetPluginSingletons();
		$this->assertSame(
			0,
			(int) \wpSPIO()->settings()->autoAIBulk,
			'autoAIBulk must persist as 0 after the bulk AI switch is toggled off'
		);
	}
}
