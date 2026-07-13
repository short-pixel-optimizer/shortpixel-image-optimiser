<?php
/**
 * Tests for ShortPixel\ImageGalleries — Envira / Soliloquy compat.
 *
 * Focus:
 *   - add_screen_loads appends the four Envira/Soliloquy admin slugs
 *   - envira_suffixes merges the six extra suffix patterns
 *
 * Skipped: addConstants() (`plugin_active` check requires the real
 * plugin loaded; also contains the `'soliquy'` slug typo flagged in
 * the deferred-bugs memo).
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\ImageGalleries;

class ImageGalleriesTest extends WP_UnitTestCase {

	public function test_add_screen_loads_appends_the_four_gallery_admin_screen_slugs() {
		$c      = new ImageGalleries();
		$result = $c->add_screen_loads( array( 'upload' ), null );

		// Sentinel: assertSame catches BOTH ordering AND count. The
		// screen slugs must land in the SPIO filter's expected order
		// (existing entries first, appended entries at the end).
		$this->assertSame(
			array( 'upload', 'edit-envira', 'envira', 'edit-soliloquy', 'soliloquy' ),
			$result
		);
	}

	public function test_add_screen_loads_appends_slugs_even_when_starting_from_empty() {
		$c      = new ImageGalleries();
		$result = $c->add_screen_loads( array(), null );

		// Sentinel: proves the method doesn't require pre-existing
		// entries; a regression that only ran when the input array
		// was non-empty would fail here.
		$this->assertSame(
			array( 'edit-envira', 'envira', 'edit-soliloquy', 'soliloquy' ),
			$result
		);
	}

	public function test_envira_suffixes_merges_the_six_extra_suffix_patterns() {
		$c      = new ImageGalleries();
		$result = $c->envira_suffixes( array( '-scaled' ) );

		// All six Envira suffixes must be present alongside the input.
		$this->assertSame(
			array( '-scaled', '_c', '_tl', '_tr', '_br', '_bl', '-\d+x\d+' ),
			$result
		);
	}
}
