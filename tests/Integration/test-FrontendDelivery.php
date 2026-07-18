<?php
/**
 * Frontend WebP/AVIF delivery tests (PictureController pipeline).
 *
 * Exercises the REAL front-end transformation used when "Serve WebP/AVIF
 * from locally hosted files" is enabled: PictureController::
 * convertImgToPictureAddWebp() → convert() → testPictures() (double-wrap
 * protection) → convertImage() per <img> (on-disk companion lookup, both
 * base-name image.webp and compat image.jpg.webp forms, plus .avif) →
 * FrontImage::parseReplacement() (<picture>/<source> build) →
 * testInlineStyle() (CSS url() rewriting).
 *
 * Companion files are created on disk by copying the uploaded fixture —
 * the delivery layer only checks EXISTENCE, never content, so this keeps
 * the tests hermetic without depending on API-side webp generation.
 *
 * NOT tested here: WEBP_GLOBAL mode (mode 1) — initWebpHooks() would
 * ob_start() an output buffer owned by the controller mid-test; the
 * callback it registers is the same convertImgToPictureAddWebp() tested
 * directly below, so mode 1 adds no uncovered logic.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Controller\Front\PictureController;

class FrontendDeliveryTest extends SPIO_IntegrationTestCase {

	/** @var string[] Companion files created on disk, removed in tear_down. */
	private $companionFiles = array();

	public function tear_down() {
		foreach ( $this->companionFiles as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->companionFiles = array();
		parent::tear_down();
	}

	/**
	 * Create an on-disk companion next to the attachment's main file.
	 *
	 * @param int    $attachment_id    The attachment.
	 * @param string $extension        'webp' or 'avif'.
	 * @param bool   $double_extension True for the compat form image.jpg.webp.
	 * @return string Path of the created companion.
	 */
	private function makeCompanion( int $attachment_id, string $extension, bool $double_extension = false ): string {
		$file = get_attached_file( $attachment_id );
		$base = $double_extension
			? $file
			: pathinfo( $file, PATHINFO_DIRNAME ) . '/' . pathinfo( $file, PATHINFO_FILENAME );

		$target = $base . '.' . $extension;
		copy( $file, $target );
		$this->assertFileExists( $target, 'Companion fixture must exist on disk' );
		$this->companionFiles[] = $target;
		return $target;
	}

	/** Run HTML through the real conversion entry point. */
	private function convertHtml( string $html ): string {
		$controller = new PictureController();
		return $controller->convertImgToPictureAddWebp( $html );
	}

	public function test_img_with_webp_companion_is_wrapped_in_picture() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<img class="alignnone" src="' . $url . '" width="100">';

		$output = $this->convertHtml( $html );

		$this->assertStringContainsString( '<picture>', $output );
		$this->assertStringContainsString( 'type="image/webp"', $output );
		$this->assertStringContainsString( '.webp', $output );
		$this->assertStringContainsString( $url, $output, 'The original src must survive as the <img> fallback' );
		$this->assertStringContainsString( 'sp-no-webp', $output, 'The wrapped <img> must be marked against re-wrapping' );
	}

	public function test_img_without_companions_is_left_untouched() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<img src="' . $url . '">';

		$this->assertSame( $html, $this->convertHtml( $html ), 'No companions on disk = no rewrite' );
	}

	public function test_avif_companion_produces_avif_source() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'avif' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<img src="' . $url . '">';

		$output = $this->convertHtml( $html );

		$this->assertStringContainsString( '<picture>', $output );
		$this->assertStringContainsString( 'type="image/avif"', $output );
		$this->assertStringContainsString( '.avif', $output );
		$this->assertStringNotContainsString( 'type="image/webp"', $output, 'No webp companion = no webp source' );
	}

	/**
	 * Legacy double-extension companions (image.jpg.webp) must be found via
	 * the compat lookup when the base-name form (image.webp) is absent.
	 */
	public function test_compat_double_extension_webp_is_found() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp', true );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<img src="' . $url . '">';

		$output = $this->convertHtml( $html );

		$this->assertStringContainsString( '<picture>', $output );
		$this->assertStringContainsString( basename( $url ) . '.webp', $output, 'The compat companion name must be used in the srcset' );
	}

	public function test_img_already_inside_picture_is_not_rewrapped() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<picture><source srcset="' . $url . '" type="image/jpeg"><img src="' . $url . '"></picture>';

		$output = $this->convertHtml( $html );

		$this->assertSame( 1, substr_count( $output, '<picture' ), 'An <img> already inside <picture> must not be wrapped again' );
		$this->assertStringContainsString( 'sp-no-webp', $output, 'The protected <img> must carry the opt-out marker' );
	}

	public function test_srcset_definitions_are_converted_per_entry() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<img src="' . $url . '" srcset="' . $url . ' 300w, ' . $url . ' 1024w">';

		$output = $this->convertHtml( $html );

		$this->assertStringContainsString( 'type="image/webp"', $output );
		$this->assertStringContainsString( '.webp 300w', $output, 'Webp srcset must keep the 300w descriptor' );
		$this->assertStringContainsString( '.webp 1024w', $output, 'Webp srcset must keep the 1024w descriptor' );
	}

	public function test_inline_css_background_is_rewritten_to_webp() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<div style="background-image: url(\'' . $url . '\');">content</div>';

		$output = $this->convertHtml( $html );

		$this->assertMatchesRegularExpression( '/url\([^)]*\.webp/', $output, 'CSS url() must point to the webp companion' );
	}

	public function test_inline_css_background_without_companion_is_untouched() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<div style="background-image: url(\'' . $url . '\');">content</div>';

		$this->assertSame( $html, $this->convertHtml( $html ) );
	}

	/**
	 * A captured 404 status must suppress conversion so error pages are
	 * never corrupted (checkPreProcess gate).
	 */
	public function test_404_response_suppresses_conversion() {
		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp' );

		$url  = wp_get_attachment_url( $attachment_id );
		$html = '<img src="' . $url . '">';

		$controller = new PictureController();
		$controller->status_header_sent( 'HTTP/1.1 404 Not Found', 404 );

		$this->assertSame( $html, $controller->convertImgToPictureAddWebp( $html ), 'No rewriting on 404 pages' );
	}

	/**
	 * WEBP_WP mode (deliverWebp = 2) must hook the converter onto the WP
	 * content filters — and the filter chain must then really convert.
	 */
	public function test_webp_wp_mode_hooks_content_filters_and_converts() {
		\wpSPIO()->settings()->deliverWebp = PictureController::WEBP_WP;

		$controller = new PictureController();
		$controller->initWebpHooks();

		$hook = array( $controller, 'convertImgToPictureAddWebp' );
		$this->assertSame( 10000, has_filter( 'the_content', $hook ), 'the_content must be hooked at 10000' );
		$this->assertSame( 10000, has_filter( 'the_excerpt', $hook ) );
		$this->assertSame( 10, has_filter( 'post_thumbnail_html', $hook ) );
		$this->assertSame( 10, has_filter( 'wp_get_attachment_image', $hook ) );

		$attachment_id = $this->uploadFixture( 'fixture-small.jpg' );
		$this->makeCompanion( $attachment_id, 'webp' );

		$url    = wp_get_attachment_url( $attachment_id );
		$output = apply_filters( 'the_content', '<img src="' . $url . '">' );

		$this->assertStringContainsString( '<picture>', $output, 'the_content filter chain must deliver the <picture> markup' );
		$this->assertStringContainsString( 'type="image/webp"', $output );
	}

	public function test_webp_delivery_off_hooks_nothing() {
		\wpSPIO()->settings()->deliverWebp = 0;

		$controller = new PictureController();
		$controller->initWebpHooks();

		$hook = array( $controller, 'convertImgToPictureAddWebp' );
		$this->assertFalse( has_filter( 'the_content', $hook ), 'deliverWebp=0 must not hook content filters' );
	}
}
