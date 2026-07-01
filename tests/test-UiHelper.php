<?php
/**
 * Tests for ShortPixel\Helper\UiHelper.
 *
 * Focuses on the pure formatting/mapping helpers. The renderers and action
 * builders (renderBurgerList, renderSuccessText, getListActions, getActions,
 * getStatusText, getAction, findBestPreview) require MediaLibraryModel /
 * ThumbnailModel objects and several controller singletons, so they belong in
 * integration tests rather than unit tests.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Helper\UiHelper;
use ShortPixel\Model\Image\ImageModel;

class UiHelperTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// UiHelper::getAction() calls ApiKeyController::getInstance() which
		// triggers ApiKeyModel::loadKey() → wp_redirect() on first init. The
		// WP test bootstrap has already produced output at that point, so
		// header() throws "headers already sent". Short-circuiting the
		// wp_redirect filter prevents the header() call entirely.
		add_filter( 'wp_redirect', '__return_false' );
	}

	/*
	 * setOutputHandler
	 */

	public function test_setOutputHandler_updates_static_state() {
		UiHelper::setOutputHandler( 'cli' );

		$ref  = new ReflectionClass( UiHelper::class );
		$prop = $ref->getProperty( 'outputMode' );
		$prop->setAccessible( true );
		$this->assertSame( 'cli', $prop->getValue() );

		// Restore default so other tests are not affected.
		UiHelper::setOutputHandler( 'admin' );
	}

	/*
	 * compressionTypeToText
	 */

	public function test_compressionTypeToText_lossless() {
		$this->assertSame( 'Lossless', UiHelper::compressionTypeToText( ImageModel::COMPRESSION_LOSSLESS ) );
	}

	public function test_compressionTypeToText_lossy() {
		$this->assertSame( 'Lossy', UiHelper::compressionTypeToText( ImageModel::COMPRESSION_LOSSY ) );
	}

	public function test_compressionTypeToText_glossy() {
		$this->assertSame( 'Glossy', UiHelper::compressionTypeToText( ImageModel::COMPRESSION_GLOSSY ) );
	}

	public function test_compressionTypeToText_unknown_returns_no_compression() {
		// Note: PHP's switch uses loose comparison, so passing null here would
		// match case 0 (COMPRESSION_LOSSLESS) via `null == 0`. That's a PHP
		// quirk rather than a plugin bug, so we only assert the true "unknown"
		// integer path.
		$this->assertSame( 'No compression', UiHelper::compressionTypeToText( 999 ) );
	}

	/*
	 * getExifDisplayValues
	 *
	 * NOTE: The current implementation swaps the 'ai' and 'seo' keys in the
	 * returned array (see UiHelper.php:719-721). These assertions describe the
	 * actual behaviour — if the swap is later fixed, these tests will fail and
	 * should be updated together with the caller sites.
	 */

	public function test_getExifDisplayValues_rejects_invalid_input() {
		$this->assertFalse( UiHelper::getExifDisplayValues( 'nope' ) );
		$this->assertFalse( UiHelper::getExifDisplayValues( -1 ) );
		$this->assertFalse( UiHelper::getExifDisplayValues( 8 ) );
	}

	public function test_getExifDisplayValues_zero_marks_removed_without_ai_seo() {
		$out = UiHelper::getExifDisplayValues( 0 );
		$this->assertIsArray( $out );
		$this->assertTrue( $out['removed'] );
		$this->assertNull( $out['ai'] );
		$this->assertNull( $out['seo'] );
		$this->assertArrayHasKey( 'line', $out );
	}

	public function test_getExifDisplayValues_one_marks_kept_without_ai_seo() {
		$out = UiHelper::getExifDisplayValues( 1 );
		$this->assertFalse( $out['removed'] );
		$this->assertNull( $out['ai'] );
		$this->assertNull( $out['seo'] );
	}

	public function test_getExifDisplayValues_line_reflects_removed_state() {
		$this->assertStringContainsString( 'Removed', UiHelper::getExifDisplayValues( 0 )['line'] );
		$this->assertStringContainsString( 'Kept',    UiHelper::getExifDisplayValues( 1 )['line'] );
	}

	public function test_getExifDisplayValues_returns_array_shape_for_all_valid_inputs() {
		for ( $i = 0; $i <= 7; $i++ ) {
			$out = UiHelper::getExifDisplayValues( $i );
			$this->assertIsArray( $out, "Expected array for exif={$i}" );
			$this->assertArrayHasKey( 'removed', $out );
			$this->assertArrayHasKey( 'ai',      $out );
			$this->assertArrayHasKey( 'seo',     $out );
			$this->assertArrayHasKey( 'line',    $out );
		}
	}

	/*
	 * getConvertErrorReason
	 */

	public function test_getConvertErrorReason_known_codes() {
		$this->assertStringContainsString( 'PNG Library is not present',   UiHelper::getConvertErrorReason( -1 ) );
		$this->assertStringContainsString( 'Could not create path',        UiHelper::getConvertErrorReason( -2 ) );
		$this->assertStringContainsString( 'Result file is larger',        UiHelper::getConvertErrorReason( -3 ) );
		$this->assertStringContainsString( 'Could not write result file',  UiHelper::getConvertErrorReason( -4 ) );
		$this->assertStringContainsString( 'Could not create backup',      UiHelper::getConvertErrorReason( -5 ) );
		$this->assertStringContainsString( 'Image is transparent',         UiHelper::getConvertErrorReason( -6 ) );
	}

	public function test_getConvertErrorReason_prefixed_with_not_converted() {
		$this->assertStringStartsWith( 'Not converted:', UiHelper::getConvertErrorReason( -1 ) );
	}

	public function test_getConvertErrorReason_unknown_code_includes_the_code() {
		$msg = UiHelper::getConvertErrorReason( -99 );
		$this->assertStringContainsString( 'Unknown error', $msg );
		$this->assertStringContainsString( '-99', $msg );
	}

	/*
	 * getKBSearchLink
	 */

	public function test_getKBSearchLink_returns_escaped_knowledge_base_url() {
		$link = UiHelper::getKBSearchLink( 'anything' );
		$this->assertStringContainsString( 'shortpixel.com/knowledge-base', $link );
		$this->assertSame( esc_url( $link ), $link, 'Returned URL should already be esc_url()-safe.' );
	}

	/*
	 * formatBytes
	 */

	public function test_formatBytes_zero() {
		$this->assertStringEndsWith( 'B', UiHelper::formatBytes( 0 ) );
	}

	public function test_formatBytes_kilobytes() {
		$this->assertStringEndsWith( 'KB', UiHelper::formatBytes( 2048 ) );
	}

	public function test_formatBytes_megabytes() {
		$this->assertStringEndsWith( 'MB', UiHelper::formatBytes( 5 * 1024 * 1024 ) );
	}

	public function test_formatBytes_gigabytes() {
		$this->assertStringEndsWith( 'GB', UiHelper::formatBytes( 3 * 1024 * 1024 * 1024 ) );
	}

	public function test_formatBytes_negative_input_clamped_to_zero() {
		// max($bytes, 0) means negative values collapse to "0 B" style output.
		$this->assertStringEndsWith( 'B', UiHelper::formatBytes( -500 ) );
	}

	public function test_formatBytes_precision_controls_decimals() {
		$out = UiHelper::formatBytes( 1536, 3 );
		// 1536 / 1024 = 1.5 → formatted with 3 decimals gives "1.500 KB"
		$this->assertStringContainsString( '1.500', $out );
	}

	/*
	 * formatNumber
	 */

	public function test_formatNumber_strips_trailing_double_zero() {
		// 1234 with 2 decimals -> "1,234.00" -> stripped to "1,234".
		$this->assertSame( '1,234', UiHelper::formatNumber( 1234, 2 ) );
	}

	public function test_formatNumber_preserves_non_zero_decimals() {
		$this->assertSame( '12.57', UiHelper::formatNumber( 12.5678, 2 ) );
	}

	public function test_formatNumber_keeps_single_trailing_zero() {
		// Only exact "00" is stripped; "50" is kept.
		$this->assertSame( '12.50', UiHelper::formatNumber( 12.5, 2 ) );
	}

	public function test_formatNumber_zero_precision() {
		$this->assertSame( '1,235', UiHelper::formatNumber( 1234.6, 0 ) );
	}

	/*
	 * formatDate
	 */

	public function test_formatDate_recent_past_returns_ago_phrase() {
		$date = new DateTime( '@' . ( time() - 300 ) ); // 5 minutes ago
		$out  = UiHelper::formatDate( $date );
		$this->assertStringContainsString( 'ago', $out );
	}

	public function test_formatDate_near_future_returns_from_now_phrase() {
		$date = new DateTime( '@' . ( time() + 300 ) ); // 5 minutes ahead
		$out  = UiHelper::formatDate( $date );
		$this->assertStringContainsString( 'from now', $out );
	}

	public function test_formatDate_old_date_returns_ymd_format() {
		$date = new DateTime( '2020-05-17 10:00:00' );
		$this->assertSame( '2020/05/17', UiHelper::formatDate( $date ) );
	}

	/*
	 * formatTS
	 */

	public function test_formatTS_uses_at_separator() {
		$out = UiHelper::formatTS( strtotime( '2024-03-15 10:00:00' ) );
		$this->assertNotEmpty( $out );
		$this->assertStringContainsString( ' @ ', $out );
	}

	/*
	 * getSettingsStrings
	 */

	public function test_getSettingsStrings_returns_all_expected_groups() {
		$strings = UiHelper::getSettingsStrings();

		foreach ( array( 'exclusion_types', 'exclusion_apply', 'dashboard_strings', 'ai_strings' ) as $key ) {
			$this->assertArrayHasKey( $key, $strings );
			$this->assertIsArray( $strings[ $key ] );
			$this->assertNotEmpty( $strings[ $key ] );
		}
	}

	public function test_getSettingsStrings_named_returns_only_that_group() {
		$out = UiHelper::getSettingsStrings( 'exclusion_apply' );
		$this->assertArrayHasKey( 'all', $out );
		$this->assertArrayHasKey( 'only-thumbs', $out );
		$this->assertArrayNotHasKey( 'exclusion_apply', $out );
	}

	public function test_getSettingsStrings_unknown_name_returns_all_groups() {
		$out = UiHelper::getSettingsStrings( 'does-not-exist' );
		$this->assertArrayHasKey( 'exclusion_types', $out );
		$this->assertArrayHasKey( 'ai_strings',      $out );
	}

	/*
	 * getIcon
	 */

	public function test_getIcon_returns_img_tag_with_src() {
		$html = UiHelper::getIcon( 'res/img/logo.png' );
		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'src=', $html );
		$this->assertStringContainsString( 'res/img/logo.png', $html );
		$this->assertStringContainsString( 'class="icon"', $html );
	}

	public function test_getIcon_includes_width_attribute_when_provided() {
		$html = UiHelper::getIcon( 'res/img/logo.png', array( 'width' => 32 ) );
		$this->assertStringContainsString( 'width="32"', $html );
	}

	/*
	 * getAction
	 */

	public function test_getAction_optimize_produces_js_call_with_id() {
		$action = UiHelper::getAction( 'optimize', 42 );
		$this->assertSame( 'js', $action['type'] );
		$this->assertSame( 'Optimize Now', $action['text'] );
		$this->assertSame( 'button', $action['display'] );
		$this->assertTrue( $action['is-optimizable'] );
		$this->assertStringContainsString( 'ShortPixelProcessor.screen.Optimize(42)', $action['function'] );
	}

	public function test_getAction_forceOptimize_passes_override_flag() {
		$action = UiHelper::getAction( 'forceOptimize', 7 );
		$this->assertStringContainsString( 'Optimize(7, true)', $action['function'] );
		$this->assertTrue( $action['is-optimizable'] );
	}

	public function test_getAction_restore_returns_inline_js() {
		$action = UiHelper::getAction( 'restore', 99 );
		$this->assertSame( 'js', $action['type'] );
		$this->assertSame( 'inline', $action['display'] );
		$this->assertSame( 'Restore backup', $action['text'] );
		$this->assertStringContainsString( 'RestoreItem(99)', $action['function'] );
	}

	public function test_getAction_compare_and_compare_custom_use_different_js() {
		$compare       = UiHelper::getAction( 'compare',        11 );
		$compareCustom = UiHelper::getAction( 'compare-custom', 11 );

		$this->assertStringContainsString( 'ShortPixel.loadComparer(11)',        $compare['function'] );
		$this->assertStringContainsString( 'ShortPixel.loadComparer(11,"custom"', $compareCustom['function'] );
	}

	public function test_getAction_reoptimize_variants_embed_compression_constant() {
		$lossy    = UiHelper::getAction( 'reoptimize-lossy',    5 );
		$lossless = UiHelper::getAction( 'reoptimize-lossless', 5 );
		$glossy   = UiHelper::getAction( 'reoptimize-glossy',   5 );

		$this->assertStringContainsString( ',' . ImageModel::COMPRESSION_LOSSY,    $lossy['function'] );
		$this->assertStringContainsString( ',' . ImageModel::COMPRESSION_LOSSLESS, $lossless['function'] );
		$this->assertStringContainsString( ',' . ImageModel::COMPRESSION_GLOSSY,   $glossy['function'] );
	}

	public function test_getAction_reoptimize_smartcrop_variants_embed_action_constants() {
		$sc   = UiHelper::getAction( 'reoptimize-smartcrop',     8, array( 'compressionType' => ImageModel::COMPRESSION_LOSSY ) );
		$ncsc = UiHelper::getAction( 'reoptimize-smartcropless', 8, array( 'compressionType' => ImageModel::COMPRESSION_LOSSY ) );

		$this->assertStringContainsString( ',' . ImageModel::ACTION_SMARTCROP,     $sc['function'] );
		$this->assertStringContainsString( ',' . ImageModel::ACTION_SMARTCROPLESS, $ncsc['function'] );
	}

	public function test_getAction_optimizethumbs_without_compressionType() {
		$action = UiHelper::getAction( 'optimizethumbs', 21 );
		$this->assertStringContainsString( 'Optimize(21)', $action['function'] );
		$this->assertStringNotContainsString( 'null', $action['function'] );
	}

	public function test_getAction_optimizethumbs_with_compressionType_passes_third_argument() {
		$action = UiHelper::getAction( 'optimizethumbs', 21, array( 'compressionType' => ImageModel::COMPRESSION_LOSSY ) );
		$this->assertStringContainsString( 'Optimize(21, null, ' . ImageModel::COMPRESSION_LOSSY, $action['function'] );
	}

	public function test_getAction_checkquota_and_extendquota_are_distinct() {
		$check  = UiHelper::getAction( 'checkquota',  0 );
		$extend = UiHelper::getAction( 'extendquota', 0 );

		$this->assertSame( 'js',     $check['type'] );
		$this->assertSame( 'button', $extend['type'] );
		$this->assertStringContainsString( 'ShortPixel.checkQuota', $check['function'] );
		$this->assertStringContainsString( 'shortpixel.com/login', $extend['function'] );
	}

	public function test_getAction_unknown_name_returns_empty_defaults() {
		$action = UiHelper::getAction( 'not-a-real-action', 123 );
		$this->assertSame( '', $action['function'] );
		$this->assertSame( '', $action['type'] );
		$this->assertSame( '', $action['text'] );
		$this->assertSame( '', $action['display'] );
	}

	/*
	 * renderBurgerList
	 */

	private function makeImageStub( int $id ) {
		return new class( $id ) {
			private $id;
			public function __construct( $id ) {
				$this->id = $id;
			}
			public function get( $key ) {
				return 'id' === $key ? $this->id : null;
			}
		};
	}

	public function test_renderBurgerList_wraps_dropdown_with_id_in_container() {
		$actions = array(
			'optimize' => array( 'type' => 'js', 'function' => 'ShortPixel.doIt()', 'text' => 'Do it' ),
		);

		$html = UiHelper::renderBurgerList( $actions, $this->makeImageStub( 42 ) );

		$this->assertStringContainsString( "id='sp-dd-42'", $html );
		$this->assertStringContainsString( 'sp-dropdown-content', $html );
		$this->assertStringContainsString( 'sp-dropbtn',          $html );
	}

	public function test_renderBurgerList_prefixes_js_actions_with_javascript_scheme() {
		$actions = array(
			'optimize' => array( 'type' => 'js', 'function' => 'ShortPixel.doIt()', 'text' => 'Do it' ),
			'view'     => array( 'type' => 'button', 'function' => 'https://example.com/x', 'text' => 'Visit' ),
		);

		$html = UiHelper::renderBurgerList( $actions, $this->makeImageStub( 1 ) );

		$this->assertStringContainsString( "href='javascript:ShortPixel.doIt()'", $html );
		$this->assertStringContainsString( "href='https://example.com/x'",        $html );
		$this->assertStringNotContainsString( 'javascript:https://', $html );
	}

	public function test_renderBurgerList_marks_button_primary_when_optimizethumbs_present() {
		$actionsWith = array(
			'optimizethumbs' => array( 'type' => 'js', 'function' => 'x()', 'text' => 'Optimise' ),
		);
		$actionsWithout = array(
			'restore' => array( 'type' => 'js', 'function' => 'y()', 'text' => 'Restore' ),
		);

		$this->assertStringContainsString( 'button-primary', UiHelper::renderBurgerList( $actionsWith,    $this->makeImageStub( 1 ) ) );
		$this->assertStringNotContainsString( 'button-primary', UiHelper::renderBurgerList( $actionsWithout, $this->makeImageStub( 1 ) ) );
	}

	/*
	 * convertImageTypeName (protected — invoked via reflection)
	 *
	 * Assumes SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION and SHORTPIXEL_USE_DOUBLE_AVIF_EXTENSION
	 * are false (their default in wp-shortpixel.php), so the method replaces the
	 * extension rather than appending.
	 */

	private function invokeConvertImageTypeName( string $name, string $type ): string {
		$ref    = new ReflectionClass( UiHelper::class );
		$method = $ref->getMethod( 'convertImageTypeName' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $name, $type );
	}

	public function test_convertImageTypeName_replaces_extension_for_webp() {
		$this->assertSame( 'photo.webp', $this->invokeConvertImageTypeName( 'photo.jpg', 'webp' ) );
	}

	public function test_convertImageTypeName_replaces_extension_for_avif() {
		$this->assertSame( 'photo.avif', $this->invokeConvertImageTypeName( 'photo.png', 'avif' ) );
	}
}
