<?php
/**
 * Integration tests: trusted mode vs. WebP/AVIF companion detection.
 *
 * With SHORTPIXEL_TRUSTED_MODE active, FileModel::exists() always reports
 * true, which makes file-based WebP/AVIF probes in
 * ImageModel::getImageType() meaningless — the plugin would assume every
 * variant existed and persist bogus webp/avif filenames into image_meta.
 * The trusted-mode branch of getImageType() therefore answers from the
 * createWebp / createAvif settings: a variant is only assumed to exist when
 * its generation setting is enabled.
 *
 * Trusted mode is driven here by flipping the FileModel / DirectoryModel
 * $TRUSTED_MODE statics directly — the same thing
 * FileSystemController::startTrustedMode() does on the media list/edit
 * screens. The SHORTPIXEL_TRUSTED_MODE constant → EnvironmentModel wiring
 * is covered separately in test-ConstantsAndFilters.php (the constant
 * cannot be defined here without poisoning the whole PHP process).
 *
 * The trusted-mode branch returns boolean true instead of the documented
 * FileModel|false (see the tests at the bottom):
 *   - setWebp() / setAvif() check is_object() before ->exists(), so loading
 *     an image model in trusted mode (Media Library list / edit screens)
 *     does not fatal; the unlisted-thumbnail search is skipped in trusted
 *     mode. onDelete() has the same guard. All regression-covered below.
 *   - @todo getImageType() still returns boolean true (pinned below; no
 *     user-facing path today). Callers that would break on it but only run
 *     with trusted mode OFF today (trusted mode is started solely by the
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
	 * from real file checks.
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
			'Trusted mode with createWebp disabled must NOT assume a webp exists (every variant must not be assumed present).'
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
	// Pinned known defect: trusted-mode branch returns boolean true, breaking
	// the FileModel|false contract of getImageType()/getWebp()/getAvif().
	// -------------------------------------------------------------------

	public function test_trusted_mode_getWebp_returns_boolean_true_not_a_filemodel_pinned_for_deferred_fix() {
		\wpSPIO()->settings()->createWebp = true;

		$id    = $this->uploadFixture( 'fixture-small.jpg' );
		$image = $this->imageLoadedOutsideTrustedMode( $id );

		$this->enableTrustedMode();

		$this->assertSame(
			true,
			$image->getWebp(),
			'Pinned: getImageType() returns boolean true in trusted mode, but its contract expects FileModel|false. '
			. 'The user-facing callers are guarded (setWebp, setAvif, onDelete), but getWebps()/getAvifs() → getAllUrls()/getAllFiles(), the restore loops, checkLegacyFileTypeFileName() and cloudflare pathToUrl(true) would still break if they ever ran in trusted mode. '
			. 'FLIP this test when fixed: it should then assert instanceOf FileModel (or whatever the corrected contract is).'
		);
	}

	/**
	 * Regression — load path. ImageModel::setWebp() checks
	 * `is_object($webp)`, so loadMeta() → verifyImage() → setWebp() does not
	 * call (true)->exists(): the Media Library list / edit screens load when
	 * SHORTPIXEL_TRUSTED_MODE is on and createWebp is enabled.
	 */
	public function test_loading_image_in_trusted_mode_does_not_fatal() {
		\wpSPIO()->settings()->createWebp = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertInstanceOf( ImageModel::class, $image, 'The image model must load in trusted mode.' );
		// SENTINEL: the trusted-mode branch that returns boolean true was really hit.
		$this->assertSame( true, $image->getWebp(), 'Sentinel: trusted mode still reports the webp as present (boolean true), so setWebp() did face the crash input.' );
	}

	/**
	 * Regression — load path with AVIF on. setAvif() needs the same
	 * is_object() guard as setWebp(), or with createAvif enabled the load
	 * fatals in setAvif() ((true)->exists()).
	 */
	public function test_loading_image_with_avif_on_in_trusted_mode_does_not_fatal() {
		\wpSPIO()->settings()->createWebp = true;
		\wpSPIO()->settings()->createAvif = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertInstanceOf( ImageModel::class, $image, 'The image model must load in trusted mode with AVIF creation on.' );
		// SENTINEL: the AVIF branch really handed setAvif() the crash input.
		$this->assertSame( true, $image->getAvif(), 'Sentinel: trusted mode still reports the avif as present (boolean true).' );
	}

	/**
	 * Regression — delete path.
	 *
	 * ImageModel::onDelete() checks is_object() on the webp / avif answers:
	 * a `$webp !== false && $webp->exists()` guard lets boolean true (the
	 * trusted-mode answer) through, so deleting an image in trusted mode
	 * with createWebp / createAvif on would call (true)->exists() and fatal
	 * — "Delete permanently" on the attachment edit screen would answer
	 * HTTP 500 and not delete the image. This is reachable because route()
	 * starts trusted mode through the view controllers' load() on
	 * load-post.php / load-upload.php, before WordPress runs the delete.
	 */
	public function test_deleting_an_image_in_trusted_mode_does_not_fatal() {
		\wpSPIO()->settings()->createWebp = true;
		\wpSPIO()->settings()->createAvif = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		$image = \wpSPIO()->filesystem()->getImage( $id, 'media', false );
		$this->assertInstanceOf( ImageModel::class, $image, 'Sentinel: the image model loads in trusted mode.' );
		// SENTINEL: onDelete() really faces the crash input for both types.
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
			'onDelete() must complete in trusted mode — got: ' . ( $error ? $error->getMessage() : '' )
		);
	}
}
