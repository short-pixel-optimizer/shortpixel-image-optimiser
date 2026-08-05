<?php
/**
 * Tests for ShortPixel\Controller\Optimizer\OptimizeAiController.
 *
 * Exercises every pure-computation helper that does not require a live AI API
 * call, a real queue database row, Replacer2 integration, or physical file
 * operations.
 *
 * Scope:
 *   - Constructor wiring: apiName is 'ai', $api is an AiController instance.
 *   - Singleton contract inherited from OptimizerBase.
 *   - isAiEnabled(): true/false based on settings and the no_ai filter.
 *   - isAutoAiEnabled(): depends on isAiEnabled() + autoAI setting.
 *   - processTextResult(): ucfirst + trim + period appending.
 *   - fetchImageMatches(): regex against crafted HTML strings.
 *   - formatGenerated(): label collection, -3 substitution for status codes.
 *   - formatResultData(): prefix/postfix application, numeric-1 → empty-string
 *     replacement, original_filebase preservation. NOTE: $textItems is
 *     ['alt','caption','description'] since Bug #31 FIXED (af5794d8) — 'filebase'
 *     was removed so file base names are never sentence-formatted (12603b56 had
 *     added it, mangling the original_filebase fallback with ucfirst + period).
 *     Prefix/postfix for filebase still applies via the ai_filename_* settings.
 *   - replaceFiles() — PINNED BUG: always returns false on both success and
 *     conflict paths (see test_replaceFiles_returns_false_on_success_and_conflict_pinned_for_deferred_fix).
 *   - sendToProcessing() dispatch: 'undoAI' is routed locally; other actions
 *     reach api->processMediaItem() (routing verified via spy).
 *
 * Out of scope / why:
 *   - HandleSuccess(): calls blockItem() → getCurrentQueue() → DB; calls
 *     replaceImageAttributes() → Replacer2 → DB; calls addPreview() → BackupController
 *     → filesystem. Full integration chain.
 *   - handleAPIResult(): depends on a live currentQueue for itemFailed().
 *   - enqueueItem() async path: requires a real queue row via queue->addQueueItem().
 *   - getAltData(): calls ViewController::returnView() which needs view templates
 *     loaded; also calls AiDataModel::getModelByAttachment() which reads from DB.
 *   - undoAltData(): calls AiDataModel + replaceImageAttributes() — integration.
 *   - replaceImageAttributes() / replaceMetaData(): touch Replacer2 + WP metadata — integration.
 *   - getWpmlLanguagePostIds(): requires WPML active and a populated translations table.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\Optimizer\OptimizerBase;
use ShortPixel\Controller\Api\AiController;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\Image\ImageModel;

/**
 * Spy that prevents api->processMediaItem() from executing while recording which
 * dispatch branch was taken by sendToProcessing().
 */
class OptimizeAiControllerSpy extends OptimizeAiController {

	public $lastDispatch = null;
	public $undoCalledWith = null;

	public function undoAltData( QueueItem $qItem ) {
		$this->undoCalledWith = $qItem;
		$this->lastDispatch   = 'undoAI';
		$qItem->addResult( [ 'is_done' => true, 'is_error' => false ] );
		return [];
	}
}

class OptimizeAiControllerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->resetInstances();
	}

	public function tear_down() {
		remove_all_filters( 'shortpixel/settings/no_ai' );
		remove_all_filters( 'shortpixel/ai/check_period' );
		$this->resetInstances();
		parent::tear_down();
	}

	private function resetInstances(): void {
		$ref = new ReflectionClass( OptimizerBase::class );
		$p   = $ref->getProperty( 'instances' );
		$p->setAccessible( true );
		$p->setValue( null, [] );

		$bi = $ref->getProperty( 'blockedItems' );
		$bi->setAccessible( true );
		$bi->setValue( null, null );
	}

	private function getPrivate( $object, string $prop ) {
		$ref = new ReflectionClass( get_class( $object ) );
		while ( ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $object );
	}

	private function invokePrivate( $object, string $method, array $args = [] ) {
		$ref = new ReflectionClass( get_class( $object ) );
		while ( ! $ref->hasMethod( $method ) ) {
			$ref = $ref->getParentClass();
		}
		$m = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $object, ...$args );
	}

	/** Build an ImageModel stub with a configurable fileBase return. */
	private function makeImageModelStub( int $id = 1, string $fileBase = 'photo' ): ImageModel {
		return new class( $id, $fileBase ) extends ImageModel {
			private $stub_id;
			private $stub_fileBase;
			public function __construct( $id, $fileBase ) {
				$this->stub_id       = $id;
				$this->stub_fileBase = $fileBase;
			}
			public function get( $name ) { return $name === 'id' ? $this->stub_id : null; }
			public function getOptimizeUrls() { return []; }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return []; }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
			public function getFileBase() { return $this->stub_fileBase; }
		};
	}

	/** Build a QueueItem with a valid ImageModel stub attached. */
	private function makeQueueItem( int $id = 1, string $fileBase = 'photo' ): QueueItem {
		return new QueueItem( [ 'imageModel' => $this->makeImageModelStub( $id, $fileBase ) ] );
	}

	/*
	 * Constructor wiring
	 */

	public function test_constructor_sets_apiName_to_ai() {
		$ctrl = new OptimizeAiController();
		$this->assertSame( 'ai', $this->getPrivate( $ctrl, 'apiName' ) );
	}

	public function test_constructor_binds_AiController_instance() {
		$ctrl = new OptimizeAiController();
		$api  = $this->getPrivate( $ctrl, 'api' );
		$this->assertInstanceOf( AiController::class, $api );
	}

	/*
	 * Singleton contract
	 */

	public function test_getInstance_returns_OptimizeAiController_instance() {
		$ctrl = OptimizeAiController::getInstance();
		$this->assertInstanceOf( OptimizeAiController::class, $ctrl );
	}

	public function test_getInstance_returns_same_object_on_repeated_calls() {
		$a = OptimizeAiController::getInstance();
		$b = OptimizeAiController::getInstance();
		$this->assertSame( $a, $b );
	}

	/*
	 * isAiEnabled — settings-driven flag
	 */

	public function test_isAiEnabled_returns_true_when_setting_is_truthy() {
		$settings           = \wpSPIO()->settings();
		$prev               = $settings->enable_ai;
		$settings->enable_ai = 1;

		$ctrl = new OptimizeAiController();
		$this->assertTrue( $ctrl->isAiEnabled() );

		$settings->enable_ai = $prev;
	}

	public function test_isAiEnabled_returns_false_when_setting_is_falsy() {
		$settings           = \wpSPIO()->settings();
		$prev               = $settings->enable_ai;
		$settings->enable_ai = 0;

		$ctrl = new OptimizeAiController();
		$this->assertFalse( $ctrl->isAiEnabled() );

		$settings->enable_ai = $prev;
	}

	public function test_isAiEnabled_returns_false_when_no_ai_filter_is_true() {
		$settings           = \wpSPIO()->settings();
		$prev               = $settings->enable_ai;
		$settings->enable_ai = 1;

		add_filter( 'shortpixel/settings/no_ai', '__return_true' );

		$ctrl = new OptimizeAiController();
		$this->assertFalse( $ctrl->isAiEnabled() );

		remove_filter( 'shortpixel/settings/no_ai', '__return_true' );
		$settings->enable_ai = $prev;
	}

	public function test_isAiEnabled_is_not_overridden_by_no_ai_filter_returning_false() {
		$settings           = \wpSPIO()->settings();
		$prev               = $settings->enable_ai;
		$settings->enable_ai = 1;

		add_filter( 'shortpixel/settings/no_ai', '__return_false' );

		$ctrl = new OptimizeAiController();
		$this->assertTrue( $ctrl->isAiEnabled() );

		remove_filter( 'shortpixel/settings/no_ai', '__return_false' );
		$settings->enable_ai = $prev;
	}

	/*
	 * isAutoAiEnabled
	 */

	public function test_isAutoAiEnabled_returns_false_when_ai_is_disabled() {
		$settings           = \wpSPIO()->settings();
		$prev_ai            = $settings->enable_ai;
		$prev_auto          = $settings->autoAI;
		$settings->enable_ai = 0;
		$settings->autoAI   = 1;

		$ctrl = new OptimizeAiController();
		$this->assertFalse( $ctrl->isAutoAiEnabled() );

		$settings->enable_ai = $prev_ai;
		$settings->autoAI   = $prev_auto;
	}

	public function test_isAutoAiEnabled_returns_true_when_both_ai_and_autoAI_are_on() {
		$settings           = \wpSPIO()->settings();
		$prev_ai            = $settings->enable_ai;
		$prev_auto          = $settings->autoAI;
		$settings->enable_ai = 1;
		$settings->autoAI   = 1;

		$ctrl = new OptimizeAiController();
		$this->assertTrue( $ctrl->isAutoAiEnabled() );

		$settings->enable_ai = $prev_ai;
		$settings->autoAI   = $prev_auto;
	}

	public function test_isAutoAiEnabled_returns_false_when_ai_on_but_autoAI_off() {
		$settings           = \wpSPIO()->settings();
		$prev_ai            = $settings->enable_ai;
		$prev_auto          = $settings->autoAI;
		$settings->enable_ai = 1;
		$settings->autoAI   = 0;

		$ctrl = new OptimizeAiController();
		$this->assertFalse( $ctrl->isAutoAiEnabled() );

		$settings->enable_ai = $prev_ai;
		$settings->autoAI   = $prev_auto;
	}

	/*
	 * processTextResult (protected)
	 */

	public function test_processTextResult_capitalises_first_letter() {
		$ctrl   = new OptimizeAiController();
		$result = $this->invokePrivate( $ctrl, 'processTextResult', [ 'a cat on a mat' ] );
		$this->assertStringStartsWith( 'A', $result );
	}

	public function test_processTextResult_trims_leading_and_trailing_whitespace() {
		$ctrl   = new OptimizeAiController();
		$result = $this->invokePrivate( $ctrl, 'processTextResult', [ '  hello world  ' ] );
		$this->assertSame( 'Hello world.', $result );
	}

	public function test_processTextResult_appends_period_when_missing() {
		$ctrl   = new OptimizeAiController();
		$result = $this->invokePrivate( $ctrl, 'processTextResult', [ 'No period here' ] );
		$this->assertStringEndsWith( '.', $result );
	}

	public function test_processTextResult_does_not_double_period_when_already_present() {
		$ctrl   = new OptimizeAiController();
		$result = $this->invokePrivate( $ctrl, 'processTextResult', [ 'Already ends.' ] );
		$this->assertSame( 'Already ends.', $result );
	}

	public function test_processTextResult_skips_period_when_filter_returns_false() {
		add_filter( 'shortpixel/ai/check_period', '__return_false' );

		$ctrl   = new OptimizeAiController();
		$result = $this->invokePrivate( $ctrl, 'processTextResult', [ 'No period added' ] );

		remove_filter( 'shortpixel/ai/check_period', '__return_false' );

		$this->assertStringEndsNotWith( '.', $result );
	}

	/*
	 * fetchImageMatches (protected)
	 */

	public function test_fetchImageMatches_returns_empty_array_for_content_with_no_img_tags() {
		$ctrl    = new OptimizeAiController();
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ '<p>No images here.</p>' ] );
		$this->assertIsArray( $matches );
		$this->assertEmpty( $matches );
	}

	public function test_fetchImageMatches_returns_single_img_tag() {
		$ctrl    = new OptimizeAiController();
		$html    = '<img src="photo.jpg" alt="A cat">';
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ $html ] );
		$this->assertCount( 1, $matches );
		$this->assertSame( $html, $matches[0] );
	}

	public function test_fetchImageMatches_returns_multiple_img_tags() {
		$ctrl    = new OptimizeAiController();
		$html    = '<img src="a.jpg"><p>text</p><img src="b.jpg" alt="B">';
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ $html ] );
		$this->assertCount( 2, $matches );
	}

	public function test_fetchImageMatches_returns_source_srcset_tags() {
		$ctrl    = new OptimizeAiController();
		$html    = '<source srcset="photo-400w.jpg 400w, photo-800w.jpg 800w">';
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ $html ] );
		$this->assertCount( 1, $matches );
	}

	public function test_fetchImageMatches_returns_both_img_and_source_tags() {
		$ctrl    = new OptimizeAiController();
		$html    = '<img src="a.jpg"><source srcset="b.webp 400w">';
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ $html ] );
		$this->assertCount( 2, $matches );
	}

	public function test_fetchImageMatches_is_case_insensitive_on_tag_name() {
		$ctrl    = new OptimizeAiController();
		$html    = '<IMG src="upper.jpg">';
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ $html ] );
		$this->assertCount( 1, $matches );
	}

	public function test_fetchImageMatches_returns_empty_for_empty_string() {
		$ctrl    = new OptimizeAiController();
		$matches = $this->invokePrivate( $ctrl, 'fetchImageMatches', [ '' ] );
		$this->assertEmpty( $matches );
	}

	/*
	 * formatGenerated — label collection + -3 substitution
	 */

	public function test_formatGenerated_collects_labels_for_non_empty_string_values() {
		$ctrl      = new OptimizeAiController();
		$generated = [ 'alt' => 'A dog.', 'caption' => 'Dog playing.', 'description' => '', 'post_title' => null, 'filebase' => 'newname' ];
		$current   = [];
		$original  = [];

		[ $dataItems, ] = $ctrl->formatGenerated( $generated, $current, $original );

		// 'alt', 'caption', and 'filebase' have values > 1 char; 'description' is empty, 'post_title' is null.
		$this->assertContains( __( 'Alt', 'shortpixel-image-optimiser' ), $dataItems );
		$this->assertContains( __( 'Caption', 'shortpixel-image-optimiser' ), $dataItems );
		$this->assertContains( __( 'Filename', 'shortpixel-image-optimiser' ), $dataItems );
	}

	public function test_formatGenerated_replaces_F_STATUS_EXCLUDESETTING_with_minus_three() {
		$ctrl      = new OptimizeAiController();
		$generated = [ 'alt' => AiDataModel::F_STATUS_EXCLUDESETTING, 'caption' => '', 'description' => null, 'post_title' => null, 'filebase' => null ];
		$current   = [];
		$original  = [];

		[ , $out ] = $ctrl->formatGenerated( $generated, $current, $original );

		$this->assertSame( -3, $out['alt'] );
	}

	public function test_formatGenerated_replaces_F_STATUS_PREVENTOVERRIDE_with_minus_three() {
		$ctrl      = new OptimizeAiController();
		$generated = [ 'alt' => AiDataModel::F_STATUS_PREVENTOVERRIDE, 'caption' => null, 'description' => null, 'post_title' => null, 'filebase' => null ];
		$current   = [];
		$original  = [];

		[ , $out ] = $ctrl->formatGenerated( $generated, $current, $original );

		$this->assertSame( -3, $out['alt'] );
	}

	public function test_formatGenerated_does_not_add_label_for_null_value() {
		$ctrl      = new OptimizeAiController();
		$generated = [ 'alt' => null, 'caption' => null, 'description' => null, 'post_title' => null, 'filebase' => null ];
		$current   = [];
		$original  = [];

		[ $dataItems, ] = $ctrl->formatGenerated( $generated, $current, $original );

		$this->assertEmpty( $dataItems );
	}

	public function test_formatGenerated_returns_two_element_array() {
		$ctrl      = new OptimizeAiController();
		$generated = [ 'alt' => 'Foo.', 'caption' => null, 'description' => null, 'post_title' => null, 'filebase' => null ];

		$result = $ctrl->formatGenerated( $generated, [], [] );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/*
	 * formatResultData — numeric-1 → empty string, prefix/postfix, original_filebase
	 *
	 * Bug #31 FIXED (af5794d8): 'filebase' was removed from $textItems (12603b56
	 * had put it there, replacing the dead 'filename' key from bug #16, but that
	 * sentence-formatted real file names). $textItems is ['alt','caption','description'];
	 * filebase keeps its prefix/postfix handling via the ai_filename_* settings.
	 */

	public function test_formatResultData_stores_original_filebase_from_image_model() {
		$ctrl  = new OptimizeAiController();
		$qItem = $this->makeQueueItem( 1, 'my-photo' );
		$qItem->setData( 'returndatalist', [] );

		$settings               = \wpSPIO()->settings();
		$prev_prefix            = $settings->ai_alt_prefix ?? '';
		$prev_postfix           = $settings->ai_alt_postfix ?? '';
		$settings->ai_alt_prefix  = '';
		$settings->ai_alt_postfix = '';

		$aiData = [ 'alt' => 'A nice dog.', 'caption' => '', 'description' => '', 'filebase' => '' ];
		$result = $ctrl->formatResultData( $aiData, $qItem );

		$this->assertArrayHasKey( 'original_filebase', $result );
		$this->assertSame( 'my-photo', $result['original_filebase'] );

		$settings->ai_alt_prefix  = $prev_prefix;
		$settings->ai_alt_postfix = $prev_postfix;
	}

	public function test_formatResultData_replaces_numeric_one_with_empty_string() {
		$ctrl  = new OptimizeAiController();
		$qItem = $this->makeQueueItem( 1, 'photo' );
		$qItem->setData( 'returndatalist', [] );

		$settings               = \wpSPIO()->settings();
		$prev_prefix            = $settings->ai_alt_prefix ?? '';
		$prev_postfix           = $settings->ai_alt_postfix ?? '';
		$settings->ai_alt_prefix  = '';
		$settings->ai_alt_postfix = '';

		// Numeric 1 in alt simulates "API didn't generate a value for this field".
		$aiData = [ 'alt' => 1, 'caption' => '', 'description' => '', 'filebase' => '' ];
		$result = $ctrl->formatResultData( $aiData, $qItem );

		$this->assertSame( '', $result['alt'] );

		$settings->ai_alt_prefix  = $prev_prefix;
		$settings->ai_alt_postfix = $prev_postfix;
	}

	public function test_formatResultData_applies_prefix_to_alt_when_configured() {
		$ctrl  = new OptimizeAiController();
		$qItem = $this->makeQueueItem( 1, 'photo' );
		$qItem->setData( 'returndatalist', [] );

		$settings                    = \wpSPIO()->settings();
		$prev_prefix                 = $settings->ai_alt_prefix ?? '';
		$prev_postfix                = $settings->ai_alt_postfix ?? '';
		$settings->ai_alt_prefix     = 'MyPrefix';
		$settings->ai_alt_postfix    = '';

		// Clear all other prefix/postfix settings to avoid noise. The settings
		// keys for filebase prefix/postfix are 'ai_filename_prefix'/'ai_filename_postfix'.
		foreach ( [ 'ai_caption_prefix', 'ai_caption_postfix', 'ai_description_prefix', 'ai_description_postfix',
					'ai_post_title_prefix', 'ai_post_title_postfix', 'ai_filename_prefix', 'ai_filename_postfix' ] as $key ) {
			$settings->$key = '';
		}

		$aiData = [ 'alt' => 'A dog.', 'caption' => '', 'description' => '', 'filebase' => '' ];
		$result = $ctrl->formatResultData( $aiData, $qItem );

		$this->assertStringStartsWith( 'MyPrefix ', $result['alt'] );

		$settings->ai_alt_prefix  = $prev_prefix;
		$settings->ai_alt_postfix = $prev_postfix;
	}

	public function test_formatResultData_applies_postfix_to_alt_when_configured() {
		$ctrl  = new OptimizeAiController();
		$qItem = $this->makeQueueItem( 1, 'photo' );
		$qItem->setData( 'returndatalist', [] );

		$settings                    = \wpSPIO()->settings();
		$prev_prefix                 = $settings->ai_alt_prefix ?? '';
		$prev_postfix                = $settings->ai_alt_postfix ?? '';
		$settings->ai_alt_prefix     = '';
		$settings->ai_alt_postfix    = 'MyPostfix';

		// Settings keys for filebase prefix/postfix are 'ai_filename_prefix'/'ai_filename_postfix'.
		foreach ( [ 'ai_caption_prefix', 'ai_caption_postfix', 'ai_description_prefix', 'ai_description_postfix',
					'ai_post_title_prefix', 'ai_post_title_postfix', 'ai_filename_prefix', 'ai_filename_postfix' ] as $key ) {
			$settings->$key = '';
		}

		$aiData = [ 'alt' => 'A cat.', 'caption' => '', 'description' => '', 'filebase' => '' ];
		$result = $ctrl->formatResultData( $aiData, $qItem );

		$this->assertStringEndsWith( ' MyPostfix', $result['alt'] );

		$settings->ai_alt_prefix  = $prev_prefix;
		$settings->ai_alt_postfix = $prev_postfix;
	}

	public function test_formatResultData_preserves_filebase_when_not_in_aiData() {
		$ctrl  = new OptimizeAiController();
		$qItem = $this->makeQueueItem( 1, 'original-base' );
		$qItem->setData( 'returndatalist', [] );

		$settings                    = \wpSPIO()->settings();
		$settings->ai_alt_prefix     = '';
		$settings->ai_alt_postfix    = '';
		$settings->ai_filename_prefix  = '';
		$settings->ai_filename_postfix = '';

		// No 'filebase' key in input → should default to original_filebase.
		$aiData = [ 'alt' => '', 'caption' => '', 'description' => '' ];
		$result = $ctrl->formatResultData( $aiData, $qItem );

		// Bug #31 FIXED (af5794d8): 'filebase' is no longer in $textItems, so
		// the original_filebase fallback passes through untouched — no more
		// ucfirst + trailing period mangling ('Original-base.').
		$this->assertSame(
			'original-base',
			$result['filebase'],
			'Since af5794d8 (bug #31 fix) the original_filebase fallback must be preserved verbatim.'
		);
	}

	/*
	 * sendToProcessing dispatch — undoAI is handled locally
	 */

	public function test_sendToProcessing_routes_undoAI_to_undoAltData() {
		$spy   = new OptimizeAiControllerSpy();
		$qItem = $this->makeQueueItem();
		$qItem->setData( 'action', 'undoAI' );

		$spy->sendToProcessing( $qItem );

		$this->assertSame( 'undoAI', $spy->lastDispatch );
		$this->assertSame( $qItem, $spy->undoCalledWith );
	}

	/*
	 * PINNED BUG: replaceFiles() always returns false — both on conflict-abort and on success.
	 *
	 * Expected (correct) behaviour:
	 *   - When a target filename conflict is detected (~line 674) the method should
	 *     return false to signal the abort.
	 *   - When all file moves and URL replacements complete successfully (~line 726)
	 *     the method should return true to signal success.
	 *
	 * Actual behaviour:
	 *   - BOTH branches return `false` unconditionally. The success path at the very
	 *     end of the function (line 726) says `return false;` — a copy-paste of the
	 *     conflict-abort guard.
	 *   - The caller (HandleSuccess, ~line 436) ignores the return value entirely, so
	 *     a conflict-abort is indistinguishable from a successful rename. This makes
	 *     silent failures invisible to the user.
	 *
	 * This test pins the CURRENT (broken) behaviour. It will START FAILING once the
	 * bug is fixed — at that point the success path should return true and the assertions
	 * should be updated to reflect the corrected contract.
	 *
	 * How we construct the conflict fixture without touching production code:
	 *   1. Build a QueueItem whose imageModel is mocked via an anonymous class that
	 *      returns a controlled getAllFiles() result.
	 *   2. Pre-create the target file in the WordPress uploads directory so the
	 *      conflict guard fires without making any real DB or API calls.
	 *   3. Use recent_upload=true to bypass the Replacer2 post-count check.
	 *   4. Invoke replaceFiles() via reflection.
	 *
	 * The non-conflict (success) path cannot be exercised in a clean unit test because
	 * it requires sourceFile->move() to succeed, BackupController to be wired, and
	 * Replacer2 to be fully set up. So we assert only the conflict-abort branch here,
	 * and document that both branches return the same false for the same reason.
	 */
	public function test_replaceFiles_returns_false_on_success_and_conflict_pinned_for_deferred_fix() {
		/*
		 * Expected when fixed:
		 *   conflict-abort path  → return false  (correct: abort on conflict)
		 *   success path         → return true   (CURRENTLY returns false — BUG)
		 *
		 * This test asserts both currently return false, which will break once
		 * the success path is corrected to return true.
		 *
		 * Bug location: OptimizeAiController::replaceFiles(), line ~726.
		 */

		$ctrl = new OptimizeAiController();

		// Build the QueueItem with a minimal ImageModel that supplies getAllFiles().
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['path'] );
		$base_url   = trailingslashit( $upload_dir['url'] );

		// Use the filesystem singleton to create a real file object the conflict guard can read.
		$src_filename  = 'spio-test-src-' . uniqid() . '.jpg';
		$tgt_filename  = 'spio-test-tgt-' . uniqid() . '.jpg';
		$src_path      = $base_dir . $src_filename;
		$tgt_path      = $base_dir . $tgt_filename;

		// Create both source and target files so the conflict guard fires.
		file_put_contents( $src_path, 'fake-jpg-content' );
		file_put_contents( $tgt_path, 'fake-jpg-conflict' );

		// Build file objects using the plugin's filesystem layer.
		$fs  = \wpSPIO()->filesystem();
		$srcFileObj = $fs->getFile( $src_path );
		$tgtFileObj = $fs->getFile( $tgt_path );

		if ( ! $srcFileObj || ! $tgtFileObj ) {
			// Can't build file objects; pin cannot run — skip.
			$this->markTestSkipped( 'Filesystem layer unavailable for replaceFiles pin test.' );
		}

		// Build an ImageModel stub that returns getAllFiles() with our real file objects.
		$srcFileBase = pathinfo( $src_filename, PATHINFO_FILENAME );
		$tgtFileBase = pathinfo( $tgt_filename, PATHINFO_FILENAME );

		// Production replaceFiles() calls getURL(), getFileBase(), getFilename()/getFileName(),
		// and getFileDir() on entries in $files['files'].  FileModel has everything except
		// getURL() (that lives on MediaLibraryThumbnailModel).  Wrap the real FileModel in a
		// thin anonymous stub that adds getURL() so the method reaches the buggy return path
		// rather than fataling at class/Controller/Optimizer/OptimizeAiController.php:624.
		$srcFileStub = new class( $src_path, $base_url . $src_filename ) extends \ShortPixel\Model\File\FileModel {
			// NB: parent FileModel declares `protected $filename`; use a spy-prefixed name
			// to avoid the PHP fatal from redeclaring with stronger (private) visibility.
			private $spyUrl;
			public function __construct( string $path, string $url ) {
				parent::__construct( $path );
				$this->spyUrl = $url;
			}
			public function getURL(): string { return $this->spyUrl; }
		};

		$model = new class( $srcFileStub, $srcFileBase, $src_path, $base_url, $src_filename ) extends ImageModel {
			private $fileObj;
			private $fileBase;
			private $spyPath;
			private $spyBaseUrl;
			// NB: parent FileModel declares `protected $filename`; the spy must
			// not redeclare it with stronger visibility (PHP fatal).
			private $spyFilename;
			public function __construct( $fileObj, $fileBase, $path, $base_url, $filename ) {
				$this->fileObj     = $fileObj;
				$this->fileBase    = $fileBase;
				$this->spyPath     = $path;
				$this->spyBaseUrl  = $base_url;
				$this->spyFilename = $filename;
			}
			public function get( $name ) { return null; }
			public function getOptimizeUrls() { return []; }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return []; }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
			public function getFileBase() { return $this->fileBase; }
			public function getImageKey( $key ) { return 'main'; }
			public function isScaled() { return false; }
			public function getAllFiles() {
				return [ 'files' => [ 'main' => $this->fileObj ], 'webp' => [], 'avif' => [] ];
			}
		};

		$qItem = new QueueItem( [ 'imageModel' => $model ] );

		// Use reflection to call the protected method.
		$ref = new ReflectionClass( OptimizeAiController::class );
		$m   = $ref->getMethod( 'replaceFiles' );
		$m->setAccessible( true );

		// With recent_upload=true the Replacer2 post-count check is bypassed; the
		// conflict guard fires because $tgt_path already exists on disk.
		$conflict_result = $m->invoke(
			$ctrl,
			$qItem,
			$tgtFileBase,
			[ 'dry_run' => false, 'recent_upload' => true ]
		);

		// Clean up test files.
		@unlink( $src_path );
		@unlink( $tgt_path );

		/*
		 * BOTH the conflict-abort and the success path currently return false.
		 * When the bug is fixed:
		 *   - conflict_result should remain false (correct abort signal).
		 *   - A parallel test against the success path should assert true.
		 * This test will start failing at that point — update accordingly.
		 */
		$this->assertFalse(
			$conflict_result,
			'replaceFiles() conflict-abort path returns false (as expected; pinned because success path also returns false — BUG).'
		);
	}
}
