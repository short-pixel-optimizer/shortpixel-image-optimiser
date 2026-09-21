<?php
/**
 * Integration tests: trusted mode vs. WebP/AVIF companion detection.
 *
 * Verifies the fix for the Asana task "Trusted mode assumes permanentely
 * webp + avif" (commit f8406436): with SHORTPIXEL_TRUSTED_MODE active,
 * FileModel::exists() always reports true, which made the file-based
 * WebP/AVIF probes in ImageModel::getImageType() meaningless — the plugin
 * assumed every variant existed and persisted bogus webp/avif filenames
 * into image_meta. Since the fix, the trusted-mode branch of
 * getImageType() answers from the createWebp / createAvif settings
 * instead: a variant is only assumed to exist when its generation setting
 * is enabled.
 *
 * Trusted mode is driven here by flipping the FileModel / DirectoryModel
 * $TRUSTED_MODE statics directly — the same thing
 * FileSystemController::startTrustedMode() does on the media list/edit
 * screens. The SHORTPIXEL_TRUSTED_MODE constant → EnvironmentModel wiring
 * is covered separately in test-ConstantsAndFilters.php (the constant
 * cannot be defined here without poisoning the whole PHP process).
 *
 * BUG #43 (see the tests at the bottom): the trusted-mode branch returns
 * boolean true instead of the documented FileModel|false.
 *   - FIXED for the load path (d45e95ca, regression test below): setWebp()
 *     now checks is_object() before ->exists(), so loading an image model
 *     in trusted mode with createWebp on no longer fatals (Media Library
 *     list / edit screens). The unlisted-thumbnail search is also skipped
 *     in trusted mode.
 *   - FIXED for the AVIF half of the load path (4980c516, setAvif()) and
 *     for the delete path (dec06050, onDelete() — both regression-covered
 *     below; the delete crash was reproduced end to end before the fix).
 *   - STILL PINNED (root cause, no user-facing path today): getImageType()
 *     still returns boolean true. Callers that would break on it but only
 *     run with trusted mode OFF today (trusted mode is started solely by the
 *     Media Library list / edit views' load(), never in AJAX): the
 *     getWebps()/getAvifs() collectors feeding getAllUrls() / getAllFiles()
 *     (→ rename), the Media Library and custom-media restore loops,
 *     checkLegacyFileTypeFileName().
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\File\FileModel;
use ShortPixel\Model\File\DirectoryModel;
use ShortPixel\Model\Image\ImageModel;

class TrustedModeTest extends SPIO_IntegrationTestCase {

	public function tear_down() {
		FileModel::$TRUSTED_MODE      = false;
		DirectoryModel::$TRUSTED_MODE = false;
		parent::tear_down();
	}

	/** Flip the same statics FileSystemController::startTrustedMode() flips. */
	private function enableTrustedMode(): void {
		FileModel::$TRUSTED_MODE      = true;
		DirectoryModel::$TRUSTED_MODE = true;
	}

	/**
	 * Load the image model while trusted mode is OFF, so the getters can be
	 * probed in trusted mode afterwards on an image whose meta was built
	 * from real file checks. (Loading while ON used to fatal in setWebp() —
	 * fixed in d45e95ca, see the #43 regression test.)
	 */
	private function imageLoadedOutsideTrustedMode( int $attachment_id ) {
		FileModel::$TRUSTED_MODE      = false;
		DirectoryModel::$TRUSTED_MODE = false;
		$image = \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
		$this->assertNotFalse( $image );
		return $image;
	}

	// -------------------------------------------------------------------
	// Controls: trusted mode OFF — real file checks decide
	// -------------------------------------------------------------------

	public function test_without_trusted_mode_getWebp_is_false_when_no_file_exists() {
		\wpSPIO()->settings()->createWebp = true;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->imageLoadedOutsideTrustedMode( $id );

		$this->assertFalse(
			$image->getWebp(),
			'Without trusted mode and no .webp companion on disk, getWebp() must be false — createWebp being enabled alone is not enough.'
		);
		$this->assertFalse( $image->getAvif() );
	}

	public function test_without_trusted_mode_getWebp_returns_filemodel_when_file_exists() {
		$id   = $this->uploadFixture( 'fixture-small.jpg' );
		$file = get_attached_file( $id );

		// Single-extension convention (default: no DOUBLE_WEBP constant): foo.webp
		$webpPath = preg_replace( '/\.jpg$/', '.webp', $file );
		copy( $file, $webpPath );

		$image = $this->imageLoadedOutsideTrustedMode( $id );
		$webp  = $image->getWebp();

		$this->assertInstanceOf( FileModel::class, $webp );
		$this->assertSame( basename( $webpPath ), $webp->getFileName() );

		unlink( $webpPath );
	}

	// -------------------------------------------------------------------
	// The fix: trusted mode ON — settings decide, not (always-true) file checks
	// -------------------------------------------------------------------

	public function test_trusted_mode_does_not_assume_variants_when_settings_disabled() {
		\wpSPIO()->settings()->createWebp = false;
		\wpSPIO()->settings()->createAvif = false;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->imageLoadedOutsideTrustedMode( $id );

		$this->enableTrustedMode();

		$this->assertFalse(
			$image->getWebp(),
			'Trusted mode with createWebp disabled must NOT assume a webp exists (the original Asana bug: every variant was assumed present).'
		);
		$this->assertFalse(
			$image->getAvif(),
			'Trusted mode with createAvif disabled must NOT assume an avif exists.'
		);
	}

	public function test_trusted_mode_assumes_variant_only_for_enabled_setting() {
		\wpSPIO()->settings()->createWebp = true;
		\wpSPIO()->settings()->createAvif = false;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->imageLoadedOutsideTrustedMode( $id );

		$this->enableTrustedMode();

		$this->assertNotFalse(
			$image->getWebp(),
			'Trusted mode with createWebp enabled may assume the webp was generated.'
		);
		$this->assertFalse(
			$image->getAvif(),
			'createAvif is off — the avif must not be assumed, independently of the webp setting.'
		);
	}

	public function test_trusted_mode_filetype_bigger_meta_still_wins_over_setting() {
		\wpSPIO()->settings()->createWebp = true;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->imageLoadedOutsideTrustedMode( $id );

		// API answered "webp would be bigger than the original" for this image.
		$image->setMeta( 'webp', ImageModel::FILETYPE_BIGGER );

		$this->enableTrustedMode();

		$this->assertFalse(
			$image->getWebp(),
			'FILETYPE_BIGGER meta is checked before the trusted-mode branch and must keep winning: no webp exists for this image even though createWebp is on.'
		);
	}

	// -------------------------------------------------------------------
	// BUG #43 (pinned): trusted-mode branch returns boolean true, breaking
	// the FileModel|false contract of getImageType()/getWebp()/getAvif().
	// -------------------------------------------------------------------

	public function test_pin43_trusted_mode_getWebp_returns_boolean_true_not_a_filemodel() {
		\wpSPIO()->settings()->createWebp = true;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->imageLoadedOutsideTrustedMode( $id );

		$this->enableTrustedMode();

		$this->assertSame(
			true,
			$image->getWebp(),
			'PINNED BUG #43 (root cause, still open after d45e95ca): getImageType() returns boolean true in trusted mode, but its contract expects FileModel|false. '
			. 'The user-facing callers are guarded now (setWebp d45e95ca, setAvif 4980c516, onDelete dec06050), but getWebps()/getAvifs() → getAllUrls()/getAllFiles(), the restore loops, checkLegacyFileTypeFileName() and cloudflare pathToUrl(true) would still break if they ever ran in trusted mode. '
			. 'FLIP this test when fixed: it should then assert instanceOf FileModel (or whatever the corrected contract is).'
		);
	}

	/**
	 * REGRESSION #43 — load path (flipped from pin43b, 2026-09-18).
	 * d45e95ca added `is_object($webp)` to ImageModel::setWebp(), so
	 * loadMeta() → verifyImage() → setWebp() no longer calls
	 * (true)->exists(): the Media Library list / edit screens load again
	 * when SHORTPIXEL_TRUSTED_MODE is on and createWebp is enabled.
	 */
	public function test_regression43_loading_image_in_trusted_mode_no_longer_fatals() {
		\wpSPIO()->settings()->createWebp = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertInstanceOf( ImageModel::class, $image, 'REGRESSION #43: the image model must load in trusted mode.' );
		// SENTINEL: the trusted-mode branch that used to crash was really hit.
		$this->assertSame( true, $image->getWebp(), 'Sentinel: trusted mode still reports the webp as present (boolean true), so setWebp() did face the old crash input.' );
	}

	/**
	 * REGRESSION #43 — load path with AVIF on (4980c516, 2026-09-18).
	 * d45e95ca guarded setWebp() only; with createAvif enabled the load still
	 * fataled in setAvif() ((true)->exists()). 4980c516 added the same
	 * is_object() guard there.
	 */
	public function test_regression43_loading_image_with_avif_on_in_trusted_mode_no_longer_fatals() {
		\wpSPIO()->settings()->createWebp = true;
		\wpSPIO()->settings()->createAvif = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertInstanceOf( ImageModel::class, $image, 'REGRESSION #43: the image model must load in trusted mode with AVIF creation on.' );
		// SENTINEL: the AVIF branch really handed setAvif() the old crash input.
		$this->assertSame( true, $image->getAvif(), 'Sentinel: trusted mode still reports the avif as present (boolean true).' );
	}

	/**
	 * REGRESSION #43 — delete path (flipped from the residual pin, 2026-09-18;
	 * fixed in dec06050).
	 *
	 * ImageModel::onDelete() used to guard with `$webp !== false &&
	 * $webp->exists()`; boolean true (the trusted-mode answer) passed, so
	 * deleting an image in trusted mode with createWebp / createAvif on
	 * called (true)->exists() and fataled. dec06050 added is_object() to
	 * both checks. Before the fix this was reproduced end to end (E2E
	 * WordPress, SHORTPIXEL_TRUSTED_MODE true): "Delete permanently" on the
	 * attachment edit screen answered HTTP 500 and the image was NOT
	 * deleted — reachable because route() starts trusted mode through the
	 * view controllers' load() on load-post.php / load-upload.php, before
	 * WordPress runs the delete. After the fix the same flow redirects to
	 * the Media Library and the image is gone.
	 */
	public function test_regression43_deleting_an_image_in_trusted_mode_no_longer_fatals() {
		\wpSPIO()->settings()->createWebp = true;
		\wpSPIO()->settings()->createAvif = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertInstanceOf( ImageModel::class, $image, 'Sentinel: the image model loads in trusted mode.' );
		// SENTINEL: onDelete() really faces the old crash input for both types.
		$this->assertSame( true, $image->getWebp(), 'Sentinel: trusted mode hands out boolean true for the webp.' );
		$this->assertSame( true, $image->getAvif(), 'Sentinel: trusted mode hands out boolean true for the avif.' );

		$error = null;
		try {
			$image->onDelete();
		} catch ( \Error $e ) {
			$error = $e;
		}

		$this->assertNull(
			$error,
			'REGRESSION #43: onDelete() must complete in trusted mode — got: ' . ( $error ? $error->getMessage() : '' )
		);
	}
}
