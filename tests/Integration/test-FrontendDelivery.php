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

	/**
	 * When a srcset img has a WebP companion for every size but an AVIF
	 * companion exists only for some sizes, the picture tag must still emit a
	 * WebP <source> element in addition to the partial AVIF <source>.
	 *
	 * Scenario (plan rows 25.3 / 25.7):
	 *  - Two srcset entries pointing at the same upload URL (mimics a real
	 *    srcset such as "image-300x200.jpg 300w, image-large.jpg 1024w").
	 *  - A WebP companion exists for the main (large) URL but NOT for the
	 *    thumbnail-sized entry (simulated here by using two distinct upload
	 *    attachments, one with WebP, one without, and building the srcset from
	 *    their URLs).
	 *  - An AVIF companion exists for only ONE of the two srcset entries.
	 *
	 * Verified behaviour (PictureController::convertImage()):
	 *  - $avifCount > 0 because at least one AVIF was found → the AVIF
	 *    <source> block IS emitted with the AVIF URL for the size that has it.
	 *  - The missing-AVIF entry falls back to the WebP URL (the $lastwebp
	 *    path at PictureController.php lines 390-392) in the AVIF srcset.
	 *  - $webpCount > 0 → the WebP <source> block is also emitted.
	 *  - The resulting <picture> offers both a WebP source and an AVIF source.
	 *
	 * Manual-plan rows: 25.3 / 25.7
	 */
	public function test_missing_large_avif_falls_back_to_webp_source_in_picture_tag() {
		// Two attachments: the first will have both WebP and AVIF companions;
		// the second will have only WebP (no AVIF) — simulates a srcset where
		// the large size has AVIF but a thumbnail/secondary size does not.
		$attach_with_avif = $this->uploadFixture( 'fixture-small.jpg' );
		$attach_webp_only = $this->uploadFixture( 'fixture-small.jpg' );

		// Create WebP companions for both.
		$this->makeCompanion( $attach_with_avif, 'webp' );
		$this->makeCompanion( $attach_webp_only, 'webp' );

		// Create an AVIF companion only for the first attachment.
		$this->makeCompanion( $attach_with_avif, 'avif' );
		// Intentionally NO AVIF companion for $attach_webp_only.

		$url_avif = wp_get_attachment_url( $attach_with_avif );
		$url_webp = wp_get_attachment_url( $attach_webp_only );

		// Build an img with a srcset that references both URLs.
		$html = '<img src="' . $url_avif . '" srcset="' . $url_avif . ' 1024w, ' . $url_webp . ' 300w">';

		$output = $this->convertHtml( $html );

		// The picture tag must be present.
		$this->assertStringContainsString( '<picture>', $output, 'Output must be wrapped in a <picture> element' );

		// A WebP source must be present (webpCount >= 1).
		$this->assertStringContainsString(
			'type="image/webp"',
			$output,
			'A WebP <source> must be emitted when at least one srcset entry has a WebP companion'
		);
		$this->assertStringContainsString( '.webp', $output, 'WebP companion URL must appear in the output' );

		// An AVIF source must also be present (avifCount >= 1 from the first entry).
		$this->assertStringContainsString(
			'type="image/avif"',
			$output,
			'An AVIF <source> must be emitted when at least one srcset entry has an AVIF companion'
		);
		$this->assertStringContainsString( '.avif', $output, 'AVIF companion URL must appear in the output' );

		// The AVIF srcset for the second entry (no AVIF on disk) must fall
		// back to the WebP companion URL, not the raw JPEG — verify a .webp
		// URL appears inside the avif-typed source block.
		// We do this by checking the AVIF source line contains a .webp reference
		// (the fallback path in PictureController lines 390-392).
		preg_match( '/<source[^>]+type="image\/avif"[^>]*>/i', $output, $avif_source_matches );
		$this->assertNotEmpty( $avif_source_matches, 'There must be an image/avif <source> element in the output' );
		$this->assertStringContainsString(
			'.webp',
			$avif_source_matches[0],
			'The AVIF <source> srcset must fall back to the WebP URL for sizes without an AVIF companion'
		);

		// The original img must still appear as the fallback.
		$this->assertStringContainsString( $url_avif, $output, 'The original img src must survive as the <img> fallback' );
	}
}
