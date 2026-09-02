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
	 * Fix4 regression (2c8908d1, 2026-09-01): CSS breaks when url() contains
	 * literal space / paren / quote characters, because the URL is emitted
	 * without wrapping quotes since Fix3 ("url(" . $checkedFile . ") "). If
	 * the on-disk filename carries any of those characters, the emitted
	 * declaration becomes invalid CSS ("url(a b.webp)" is not a valid
	 * unquoted url() token) — or, worse, injects extra url()/style tokens if
	 * the filename ends with ')'. Fix4 pre-escapes space, (, ), ', " in the
	 * checkedFile URL to %20 / %28 / %29 / %27 / %22 immediately before the
	 * str_replace that inserts it back into $content.
	 *
	 * This test places a file with a literal space in its name in the
	 * uploads dir (bypassing sanitize_file_name), drops a matching .webp
	 * companion next to it, and feeds an inline style with the raw URL
	 * through convertHtml. Assertions:
	 *   - The emitted url() must carry the %20-encoded form (positive proof
	 *     the Fix4 escape ran).
	 *   - The emitted url() must NOT contain a raw space (regression: the
	 *     invalid CSS shape that broke background delivery).
	 *
	 * Companion file paths are tracked in $this->companionFiles for
	 * tear_down cleanup; the source jpg is unlinked in the finally block
	 * because it lives outside the attachment table.
	 *
	 * Coverage gap (reported, not fixed): Fix4 also maps ( → %28, ) → %29,
	 * ' → %27, " → %22, but those branches are unreachable through the
	 * current pipeline — the outer url() extractor at PictureController.php
	 * :495 uses '/url\((.*)\)/imU' (ungreedy, no newline), truncating on the
	 * first ')', and the URL sanitizer at :501 strips ' and " before the
	 * filesystem lookup. Only the space branch has a reachable code path
	 * end-to-end. Tracked as BUG #54 — pinned below in
	 * test_pin54_inline_css_background_with_parens_is_silently_skipped_pinned_for_deferred_fix.
	 */
	public function test_inline_css_background_escapes_space_in_webp_url_regression_fix4() {
		$fixture = $this->fixturePath( 'fixture-small.jpg' );
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['path'] );

		// Place source jpg with a raw space in the filename — bypass
		// sanitize_file_name / wp_unique_filename so the space survives.
		$jpg_name  = 'fix4 space.jpg';
		$webp_name = 'fix4 space.webp';
		$jpg_path  = $dir . $jpg_name;
		$webp_path = $dir . $webp_name;

		if ( ! copy( $fixture, $jpg_path ) ) {
			$this->markTestSkipped( 'Could not stage spaced-filename fixture at ' . $jpg_path );
		}
		copy( $fixture, $webp_path );
		$this->companionFiles[] = $webp_path;

		try {
			// Build the raw URL directly from the uploads baseurl so we
			// keep the literal space (wp_get_attachment_url would encode it
			// — and there is no attachment row for this file anyway).
			$raw_url = trailingslashit( $uploads['url'] ) . $jpg_name;
			$html    = '<div style="background-image: url(\'' . $raw_url . '\');">content</div>';

			$output = $this->convertHtml( $html );

			// Sentinel: prove the conversion actually ran (a scope regression
			// where testInlineStyle stopped matching the url() shape would
			// otherwise let the "no raw space" assertion below false-pass on
			// an unchanged input.
			$this->assertStringContainsString(
				'.webp',
				$output,
				'Fix4 sentinel: the converter must have swapped in the .webp companion — otherwise the escape assertions below have nothing to observe.'
			);

			// Positive proof the Fix4 str_replace ran on $checkedFile: the
			// emitted url() carries the %20-encoded filename.
			$this->assertStringContainsString(
				'fix4%20space.webp',
				$output,
				'Fix4 regression: the space in the webp filename must be emitted as %20 inside url(...) — without the pre-escape, url(fix4 space.webp) is invalid CSS.'
			);

			// Regression: the raw "space.webp" substring (with a literal
			// space) must not survive inside a url(...) declaration.
			$this->assertDoesNotMatchRegularExpression(
				'/url\([^)]*fix4 space\.webp[^)]*\)/',
				$output,
				'Fix4 regression: no url(...) declaration may contain the raw " " space in the .webp filename. Before 2c8908d1 the emitted "url(fix4 space.webp)" was invalid CSS and broke background delivery.'
			);
		} finally {
			if ( file_exists( $jpg_path ) ) {
				unlink( $jpg_path );
			}
			// $webp_path is handled by parent tear_down via $companionFiles.
		}
	}

	/**
	 * BUG #54 (pinned_for_deferred_fix): inline CSS backgrounds whose
	 * filename contains parentheses are SILENTLY SKIPPED by the WebP
	 * converter — no conversion, no error, no log.
	 *
	 * Valid CSS like  background-image: url('photo (1).jpg')  is common in
	 * the wild (browser download dedup produces "photo (1).jpg"; page
	 * builders embed such files verbatim). The url() extractor at
	 * class/Controller/Front/PictureController.php:495 uses the ungreedy
	 * '/url\((.*)\)/imU', which truncates the capture at the FIRST ')' —
	 * here yielding "'photo (1". After quote-stripping (:501) the string has
	 * no extension, fails the $allowed_exts check (:505) and the entry is
	 * skipped via continue. The Fix4 %28/%29/%27/%22 escape branches
	 * (:560-564) are therefore unreachable: no paren or quote ever survives
	 * to them. Quote-in-filename inputs die similarly (:501 strips the
	 * quote, the filesystem lookup misses, no conversion).
	 *
	 * Consequence: optimization silently not applied — nothing is
	 * corrupted, the original background keeps working, but these files
	 * never get WebP/AVIF delivery in inline styles.
	 *
	 * Proposed fix (extractor-level, per CSS url-token grammar):
	 *   preg_match('/url\(\s*(?:\'([^\']*)\'|"([^"]*)"|([^)\'"\s]+))\s*\)/i', ...)
	 * taking whichever group matched, without blanket quote-stripping —
	 * then Fix4's paren/quote escapes become reachable and load-bearing.
	 *
	 * FLIP INSTRUCTIONS: when the extractor handles quoted url() tokens,
	 * this test will fail on the "must NOT contain .webp" assertion —
	 * then flip it: assert the output DOES contain the %28/%29-encoded
	 * .webp url() (mirror the Fix4 space test above) and drop the
	 * _pinned_for_deferred_fix suffix.
	 */
	public function test_pin54_inline_css_background_with_parens_is_silently_skipped_pinned_for_deferred_fix() {
		$fixture = $this->fixturePath( 'fixture-small.jpg' );
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['path'] );

		// Stage a paren-named jpg + webp companion, bypassing
		// sanitize_file_name / wp_unique_filename so the parens survive
		// (FTP uploads / browser download-dedup names).
		$jpg_name  = 'pin54 photo (1).jpg';
		$webp_name = 'pin54 photo (1).webp';
		$jpg_path  = $dir . $jpg_name;
		$webp_path = $dir . $webp_name;

		if ( ! copy( $fixture, $jpg_path ) ) {
			$this->markTestSkipped( 'Could not stage paren-filename fixture at ' . $jpg_path );
		}
		copy( $fixture, $webp_path );
		$this->companionFiles[] = $webp_path;

		try {
			$raw_url = trailingslashit( $uploads['url'] ) . $jpg_name;
			// Quoted url() — the VALID CSS shape browsers accept for names
			// with spaces/parens, and the realistic page-builder output.
			$html = '<div style="background-image: url(\'' . $raw_url . '\');">content</div>';

			$output = $this->convertHtml( $html );

			// Current (buggy) behavior: the converter must NOT have touched
			// the declaration — no .webp anywhere in the output.
			$this->assertStringNotContainsString(
				'.webp',
				$output,
				'BUG #54 pin: a paren-named background must currently be SKIPPED (extractor truncates at the first ")"). If this fails, the url() extractor was fixed — flip this test to assert the %28/%29-encoded .webp url() is emitted and drop _pinned_for_deferred_fix.'
			);

			// And the original declaration survives byte-identical (silent
			// skip, not corruption).
			$this->assertStringContainsString(
				'url(\'' . $raw_url . '\')',
				$output,
				'BUG #54 pin: the original quoted url() declaration must pass through unchanged — the failure mode is a silent skip, never a mangled declaration.'
			);
		} finally {
			if ( file_exists( $jpg_path ) ) {
				unlink( $jpg_path );
			}
			// $webp_path is handled by parent tear_down via $companionFiles.
		}
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
