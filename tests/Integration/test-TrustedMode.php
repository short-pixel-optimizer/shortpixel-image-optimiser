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
 * Also pins BUG #43 (see the pinned tests at the bottom): the trusted-mode
 * branch returns boolean true instead of the documented FileModel|false,
 * which fatals downstream in setWebp()/setAvif() (true->exists()) on every
 * image model load while trusted mode is active with createWebp/createAvif
 * enabled — i.e. the Media Library list and edit screens on trusted setups.
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
	 * Load the image model while trusted mode is OFF (loading it while ON
	 * fatals in setWebp() — see pin43b), so trusted-mode behaviour can be
	 * probed on the getters afterwards.
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
			'PINNED BUG #43: getImageType() returns boolean true in trusted mode, but its contract (and every caller) expects FileModel|false. '
			. 'Callers like setWebp()/setAvif() (true->exists()), checkLegacyFileTypeFileName() (true->getFileName()) and cloudflare pathToUrl(true) break on it. '
			. 'FLIP this test when fixed: it should then assert instanceOf FileModel (or whatever the corrected contract is).'
		);
	}

	public function test_pin43_loading_image_in_trusted_mode_fatals_in_setWebp() {
		\wpSPIO()->settings()->createWebp = true;

		$id = $this->uploadFixture( 'fixture-small.jpg' );

		$this->enableTrustedMode();

		// loadMeta() → verifyImage() → setWebp() → (true)->exists()
		// This is what happens on the Media Library list / edit screens for
		// every image when SHORTPIXEL_TRUSTED_MODE is on and createWebp is
		// enabled: the whole page fatals.
		$this->expectException( \Error::class );
		\wpSPIO()->filesystem()->getImage( $id, 'media', false );
	}
}
