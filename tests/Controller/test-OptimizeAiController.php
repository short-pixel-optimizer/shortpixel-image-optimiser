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
 *   - replaceFiles() — returns false on conflict-abort; the old pinned bug
 *     (success path also returned false) was FIXED in 1fc98025 (`return true`
 *     at ~line 765). Conflict path covered in
 *     test_replaceFiles_returns_false_on_conflict.
 *   - ajax_replaceFile() — bug #45 (c44f0369 dropped `return $result;`)
 *     FIXED in 370fb5db; regression-tested in
 *     test_ajax_replaceFile_returns_the_replaceFiles_result.
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
 *   - WPMLCheckReplace() (replaced getWpmlLanguagePostIds in f232c607): requires
 *     WPML active — covered in tests/Compat/test-CompatWPML.php.
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

	/**
	 * EBUG-4 (customer report tests/partner-plugins/bug-editor-ai-corruption.md):
	 * formatGenerated() normalises F_STATUS_PREVENTOVERRIDE (-4, aiPreserve-skipped)
	 * to -3 in the returned generated array — the SAME int the browser payload
	 * shows for F_STATUS_EXCLUDESETTING (-3, field disabled in settings). After
	 * this normalisation the client CANNOT distinguish "preserve-skipped" from
	 * "excluded by settings" — both fields look identical downstream.
	 *
	 * Consequence for any future SERVER-SIDE defense-in-depth fix (see
	 * AiController::handleSuccess() docblock): stripping int statuses out of the
	 * ajax payload MUST gate on is_int($value), NOT on the specific -3 sentinel.
	 * A filter that only strips -3 would still let a pre-normalisation -4 slip
	 * through if the code path skips formatGenerated(), and a caption field set to
	 * some other status int (e.g. F_STATUS_OK = 1) would also survive.
	 */
	public function test_formatGenerated_normalises_preventoverride_to_minus_three_and_caption_too() {
		$ctrl      = new OptimizeAiController();
		$generated = [
			'alt'         => 'A generated alt.',
			'caption'     => AiDataModel::F_STATUS_PREVENTOVERRIDE, // -4, aiPreserve blocked
			'description' => null,
			'post_title'  => null,
			'filebase'    => null,
		];

		[ , $out ] = $ctrl->formatGenerated( $generated, [], [] );

		// The -4 becomes -3 — indistinguishable from EXCLUDESETTING for the browser.
		$this->assertSame( -3, $out['caption'], 'F_STATUS_PREVENTOVERRIDE (-4) must be normalised to -3 in the returned generated array (EBUG-4).' );
		$this->assertIsInt( $out['caption'], 'Normalised value is still an int (any is_int() filter downstream must still catch it).' );

		// Sanity: the alt string was left untouched.
		$this->assertSame( 'A generated alt.', $out['alt'] );
	}

	/**
	 * formatGenerated() must leave STRING values untouched, and their labels
	 * must appear in $dataItems while integer statuses produce no label
	 * entry (labels drive the user-visible "AI generated: Alt, Caption, …"
	 * summary — an int status means "nothing was generated for this field",
	 * so it must NOT be advertised as generated).
	 */
	public function test_formatGenerated_string_values_survive_and_int_statuses_produce_no_label() {
		$ctrl      = new OptimizeAiController();
		$generated = [
			'alt'         => 'A dog runs.',
			'caption'     => AiDataModel::F_STATUS_EXCLUDESETTING, // -3, setting-disabled
			'description' => 'A dog running across the field.',
			'post_title'  => null,
			'filebase'    => null,
		];

		[ $dataItems, $out ] = $ctrl->formatGenerated( $generated, [], [] );

		// Strings untouched.
		$this->assertSame( 'A dog runs.', $out['alt'] );
		$this->assertSame( 'A dog running across the field.', $out['description'] );

		// Labels present for the two string fields, absent for the int-status field.
		$this->assertContains( __( 'Alt', 'shortpixel-image-optimiser' ), $dataItems );
		$this->assertContains( __( 'Description', 'shortpixel-image-optimiser' ), $dataItems );
		$this->assertNotContains( __( 'Caption', 'shortpixel-image-optimiser' ), $dataItems, 'Int-status field must produce no label entry.' );
	}

	/**
	 * PAYLOAD CONTRACT (EBUG-1, tests/partner-plugins/bug-editor-ai-corruption.md):
	 * integers CAN appear in the generated data that OptimizeAiController hands
	 * to the browser via the ajax result. formatGenerated() does NOT strip them
	 * out — it only normalises -4 to -3. The Gutenberg image-block corruption
	 * described in the customer report is guarded ONLY by the CLIENT-SIDE
	 * string-only allowlist in res/js/screens/screen-media.js UpdateGutenBerg
	 * (commit ea764111). If a future defense-in-depth fix ever strips int
	 * statuses on the server (in AiController::handleSuccess() or here in
	 * formatGenerated()), this test WILL and MUST fail — flip the assertion
	 * from assertIsInt to assertArrayNotHasKey (or equivalent) so it locks in
	 * the new server-side contract, and note that the client-side allowlist
	 * becomes redundant belt-and-braces.
	 */
	public function test_formatGenerated_leaks_int_status_into_browser_payload_contract() {
		$ctrl      = new OptimizeAiController();
		$generated = [
			'alt'         => AiDataModel::F_STATUS_EXCLUDESETTING, // -3, setting-disabled
			'caption'     => 'A dog runs.',
			'description' => null,
			'post_title'  => null,
			'filebase'    => null,
		];

		[ , $out ] = $ctrl->formatGenerated( $generated, [], [] );

		// CONTRACT: the -3 int survives into $out and is still an int (payload leak).
		$this->assertArrayHasKey( 'alt', $out );
		$this->assertIsInt(
			$out['alt'],
			'Server-side leak of int status into the aiData payload is the current contract; ' .
			'client-side screen-media.js UpdateGutenBerg (ea764111) is the ONLY guard. ' .
			'FLIP this test when the server side ever strips ints (defense-in-depth fix).'
		);
		$this->assertSame( -3, $out['alt'] );
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
	 * replaceFiles() conflict-abort contract: returns false when the target
	 * filename already exists on disk.
	 *
	 * History: this used to pin a bug where the SUCCESS path also returned
	 * false (copy-paste of the conflict guard). FIXED in 1fc98025 — the
	 * success path now ends with `return true;` (~line 765) and HandleSuccess
	 * (~line 435) consumes it for the reload redirect. The conflict path
	 * correctly stayed false, so this test's assertions were already the
	 * post-fix contract; only the docs changed. The success path still can't
	 * be exercised in a clean unit test (see below) — it is covered
	 * indirectly by the integration AI pipeline.
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
	public function test_replaceFiles_returns_false_on_conflict() {
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

		$this->assertFalse(
			$conflict_result,
			'replaceFiles() must return false when the target filename already exists (conflict abort).'
		);
	}

	/*
	 * Regression test for bug #45 (FIXED in 370fb5db, flipped from pin45):
	 * c44f0369 "Fixes - Reload when renaming" had removed `return $result;`
	 * from ajax_replaceFile(), so it always returned null and its caller,
	 * AjaxController::replaceFileName(), showed "Files were not replaced"
	 * even on success. The fix restored the return; this test stubs the
	 * replaceFiles() chain to return true and asserts the value is passed
	 * through by ajax_replaceFile.
	 */
	public function test_ajax_replaceFile_returns_the_replaceFiles_result() {
		$ctrl = new class() extends OptimizeAiController {
			protected function replaceFiles( $qItem, $newFileBase, $args = [] ): bool {
				return true; // simulate a fully successful replace
			}
		};

		$model = new class() extends ImageModel {
			public function __construct() {}
			public function get( $name ) { return null; }
			public function getOptimizeUrls() { return []; }
			protected function saveMeta() {}
			protected function loadMeta() {}
			protected function getImprovements() { return false; }
			protected function getExcludePatterns() { return []; }
			protected function preventNextTry( $reason = '' ) {}
			public function isOptimizePrevented() { return false; }
			public function resetPrevent() {}
			public function isScaled() { return false; }
			public function getUrl() { return 'http://example.org/wp-content/uploads/spio-pin45.jpg'; }
		};

		$qItem  = new QueueItem( [ 'imageModel' => $model ] );
		$result = $ctrl->ajax_replaceFile( $qItem, 'spio-pin45-new.jpg' );

		$this->assertTrue(
			$result,
			'Regression #45: ajax_replaceFile() must return the replaceFiles() result — it used to drop it (always null), making every rename report "Files were not replaced".'
		);
	}

	/**
	 * PINNED BUG #52 (MEDIUM): Partial-failure blindness.
	 *
	 * class/Controller/Optimizer/OptimizeAiController.php replaceFiles():
	 *   line 736 : `$result = $sourceFile->move($targetFileObj);`   // return dropped
	 *   line 754 : `$backupModel->renameBackup($newFileBase);`      // return dropped
	 *   line 766 : `$replacer->replace();`                          // return dropped
	 *   line 774 : `return true;`
	 *
	 * So replaceFiles() returns true — and the user sees "Files were
	 * replaced" — even when every single physical move failed. There is
	 * also no rollback: if some moves succeed and others fail, the
	 * attachment is left in an inconsistent on-disk state while the
	 * database is rewritten as if everything succeeded.
	 *
	 * We construct a fixture where the SOURCE file's move() ALWAYS
	 * returns false (by stubbing FileModel::move() to a no-op false).
	 * The conflict guard passes because we do NOT pre-create the target.
	 * Under the buggy contract replaceFiles() then reaches the
	 * `return true` at :774 despite the failed move.
	 *
	 * SENTINEL principles:
	 *  - Principle 2: `assertIsBool` before the value assertion — the
	 *    method's `: bool` return type could otherwise mask a shape drift.
	 *  - Principle 5: verify move() *did* return false (via the spy
	 *    counter) and that the source file *is* still on disk after
	 *    the buggy run — so a fix that silently starts respecting the
	 *    return value cannot slip past as a coincidental green.
	 *
	 * FLIP INSTRUCTIONS when SPIO fixes #52 (e.g. accumulating move
	 * results and returning false on any failure, with or without
	 * rollback): change `assertTrue($result)` to `assertFalse($result)`
	 * and update the sentinel that asserts the source file is still on
	 * disk (a rollback fix would also restore any partially moved files).
	 */
	public function test_pin52_replaceFiles_returns_true_when_move_fails_pinned_for_deferred_fix() {
		// Create a real attachment (so BackupController + replaceMetaData
		// don't blow up on a naked ImageModel stub), then wrap the loaded
		// ImageModel in a spy that returns a spy FileModel whose move()
		// ALWAYS returns false and touches no disk. The conflict guard
		// passes because we do NOT pre-create the target file.
		$fixture_dir = ABSPATH . 'wp-content/uploads/pin52-fixtures';
		wp_mkdir_p( $fixture_dir );
		$src_path = $fixture_dir . '/pin52-src-' . uniqid() . '.jpg';
		// A tiny valid JPEG so wp_generate_attachment_metadata() has something to read.
		if ( function_exists( 'imagecreatetruecolor' ) ) {
			$im = imagecreatetruecolor( 4, 4 );
			imagejpeg( $im, $src_path );
			imagedestroy( $im );
		} else {
			// GD unavailable — the test relies on it; skip cleanly.
			$this->markTestSkipped( 'GD not available; cannot build pin52 fixture without it.' );
		}

		$attach_id = wp_insert_attachment(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'pin52',
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$src_path
		);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attach_id, $src_path );
		wp_update_attachment_metadata( $attach_id, $metadata );

		$realImage = \wpSPIO()->filesystem()->getImage( $attach_id, 'media' );
		$this->assertNotFalse( $realImage, 'Precondition: image model must load for the fresh attachment' );

		$src_filename = basename( $src_path );
		$src_base     = pathinfo( $src_filename, PATHINFO_FILENAME );
		$upload_dir   = wp_upload_dir();
		$base_url_dir = trailingslashit( $upload_dir['baseurl'] ) . 'pin52-fixtures/';

		// Spy FileModel:
		//  - returns a controllable URL,
		//  - stubs move() to always return false and record the call,
		//  - never touches disk.
		$srcFileStub = new class( $src_path, $base_url_dir . $src_filename ) extends \ShortPixel\Model\File\FileModel {
			public $moveCalls = 0;
			public $lastMoveTo = null;
			private $spyUrl;
			public function __construct( string $path, string $url ) {
				parent::__construct( $path );
				$this->spyUrl = $url;
			}
			public function getURL(): string { return $this->spyUrl; }
			public function move( \ShortPixel\Model\File\FileModel $destination ) {
				$this->moveCalls++;
				$this->lastMoveTo = $destination;
				return false;
			}
		};

		// ImageModel wrapper: delegates to $realImage for everything except
		// getAllFiles(), which we short-circuit to a single spy file so the
		// move-failure blindness is the only thing being tested.
		$model = new class( $realImage, $srcFileStub, $src_base ) extends ImageModel {
			private $inner;
			private $fileObj;
			private $fileBase;
			public function __construct( $inner, $fileObj, $fileBase ) {
				$this->inner    = $inner;
				$this->fileObj  = $fileObj;
				$this->fileBase = $fileBase;
			}
			public function get( $name ) { return $this->inner->get( $name ); }
			public function getMeta( $name = false ) { return $this->inner->getMeta( $name ); }
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

		// Suppress the replaceMetaData step so the pin does not spuriously
		// mutate WP core metadata — replaceMetaData() would rewrite
		// _wp_attached_file with the new base even though no move happened.
		$ctrl = new class() extends OptimizeAiController {
			protected function replaceMetaData( $item_id, $old_file, $new_file, $dry_run = false ) {
				// intentional no-op — out of scope for pin #52 (return-value blindness).
			}
		};

		// Inject a stub BackupModel into BackupController::$models cache so
		// getBackupController()->getModel($imageModel) does not try to build
		// a LocalBackupModel around our wrapper (which would trip on the
		// spy FileModel not being an ImageModel). This keeps the pin
		// focused strictly on the move()-return-value blindness.
		$bcSingleton = \ShortPixel\Controller\Backup\BackupController::getBackupController();
		$stubBackup  = new class( $bcSingleton, $realImage ) extends \ShortPixel\Model\Backup\LocalBackupModel {
			public $renameBackupCalls = 0;
			public function renameBackup( $newBaseFileName ) : bool {
				$this->renameBackupCalls++;
				return false; // simulate a failed backup rename — return dropped by replaceFiles()
			}
		};
		$bcRef = new ReflectionClass( \ShortPixel\Controller\Backup\BackupController::class );
		$modelsProp = $bcRef->getProperty( 'models' );
		$modelsProp->setAccessible( true );
		$modelsProp->setValue( null, [ 'media' => [ $attach_id => $stubBackup ] ] );

		$ref = new ReflectionClass( OptimizeAiController::class );
		$m   = $ref->getMethod( 'replaceFiles' );
		$m->setAccessible( true );

		$tgt_base = 'pin52-tgt-' . uniqid();
		$result   = $m->invoke(
			$ctrl,
			$qItem,
			$tgt_base,
			[ 'dry_run' => false, 'recent_upload' => true ] // bypass usage guard
		);

		// Sentinel principle 5: move() must have been called at least once —
		// otherwise the "move return dropped" bug is not exercised.
		$this->assertGreaterThanOrEqual(
			1,
			$srcFileStub->moveCalls,
			'Sentinel: replaceFiles() must have invoked move() at least once for #52 to apply.'
		);
		// Sentinel principle 5: source file must still be on disk (the stub
		// short-circuits, doing no actual move).
		$this->assertFileExists(
			$src_path,
			'Sentinel: the stubbed move() did not touch disk, so the source must still be there.'
		);
		// Sentinel principle 2: value + type — the : bool return could mask
		// a null-vs-false drift if we only asserted a truthy value.
		$this->assertIsBool( $result );
		$this->assertTrue(
			$result,
			'PINNED BUG #52: replaceFiles() returns true even when move() failed on every source — ' .
			'the move() return value at OptimizeAiController.php:736 is DISCARDED. ' .
			'FLIP INSTRUCTIONS when fixed: expect false here (and, if a rollback path is added, ' .
			'update the sentinel accordingly).'
		);

		// Clean up.
		wp_delete_attachment( $attach_id, true );
		@unlink( $src_path );
		@rmdir( $fixture_dir );
	}
}
