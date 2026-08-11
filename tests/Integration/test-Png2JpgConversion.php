<?php
/**
 * Integration tests: PNG-to-JPG conversion (Wave 2).
 *
 * png2jpg is a LOCAL (GD/Imagick) conversion. For FRESH uploads it runs
 * inside the wp_generate_attachment_metadata hook (AdminController::
 * handleImageUploadHook → PNGConverter::convert), i.e. during
 * uploadFixture()/uploadFile() — BEFORE any queue work. The 'png2jpg'
 * queue action (filterQueue) only applies to pre-existing images.
 *
 * Setting values: 0 = off, 1 = convert opaque PNGs only, 2 = force
 * (composite transparent PNGs on white).
 *
 * Rollback rule: if the JPG would not be meaningfully smaller than the
 * PNG (checkFileSizeMargin), the conversion is reverted — so transparent
 * test fixtures are generated NOISY (JPEG-friendly, PNG-hostile) to keep
 * the size check from rejecting the conversion we want to observe.
 *
 * @package Shortpixel_Image_Optimiser
 */

class Png2JpgConversionTest extends SPIO_IntegrationTestCase {

	/** Copy fixture-small.png to a uniquely-named temp file, return its path. */
	private function makeUniquePngCopy(): string {
		$path = get_temp_dir() . 'png2jpg-' . wp_generate_password( 8, false ) . '.png';
		copy( $this->fixturePath( 'fixture-small.png' ), $path );
		return $path;
	}

	/**
	 * Generate a PNG with real alpha transparency AND noisy opaque content,
	 * so the JPEG rendition is clearly smaller and the size-margin rollback
	 * doesn't kick in.
	 */
	private function makeTransparentPng(): string {
		$path = get_temp_dir() . 'png2jpg-alpha-' . wp_generate_password( 8, false ) . '.png';

		mt_srand( 42 );
		$img = imagecreatetruecolor( 400, 300 );
		imagesavealpha( $img, true );
		imagealphablending( $img, false );
		$transparent = imagecolorallocatealpha( $img, 0, 0, 0, 127 );
		imagefill( $img, 0, 0, $transparent );
		for ( $x = 50; $x < 350; $x++ ) {
			for ( $y = 50; $y < 250; $y++ ) {
				$c = imagecolorallocatealpha( $img, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ), 0 );
				imagesetpixel( $img, $x, $y, $c );
			}
		}
		imagepng( $img, $path );

		$this->assertFileExists( $path );
		return $path;
	}

	private function freshImageModel( int $attachment_id ) {
		return \wpSPIO()->filesystem()->getImage( $attachment_id, 'media', false );
	}

	public function test_opaque_png_is_converted_to_jpg_at_upload_and_optimized() {
		\wpSPIO()->settings()->png2jpg = 1;

		$source = $this->makeUniquePngCopy();
		$stem   = pathinfo( $source, PATHINFO_FILENAME );
		$id     = $this->uploadFile( $source );
		unlink( $source );

		// Conversion already happened inside the upload hook.
		$mainPath = get_attached_file( $id );
		$this->assertSame( 'jpg', strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ), 'Opaque PNG must become a .jpg attachment at upload.' );
		$this->assertFileExists( $mainPath );

		$pngPath = trailingslashit( dirname( $mainPath ) ) . $stem . '.png';
		$this->assertFileDoesNotExist( $pngPath, 'The original .png main file must be removed after conversion.' );

		$info = getimagesize( $mainPath );
		$this->assertSame( IMAGETYPE_JPEG, $info[2], 'The converted main file must contain JPEG bytes.' );

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'The converted jpg must be optimized afterwards.' );
	}

	public function test_converted_png_thumbnails_are_jpg_too() {
		\wpSPIO()->settings()->png2jpg = 1;

		$id = $this->uploadFixture( 'fixture-small.png' );
		$this->optimizeAttachment( $id );

		$metadata = wp_get_attachment_metadata( $id );
		$this->assertNotEmpty( $metadata['sizes'] );
		foreach ( $metadata['sizes'] as $sizeName => $size ) {
			$this->assertSame(
				'jpg',
				strtolower( pathinfo( $size['file'], PATHINFO_EXTENSION ) ),
				"Thumbnail '$sizeName' must be a .jpg after conversion."
			);
		}
	}

	public function test_transparent_png_is_not_converted_but_still_optimized() {
		\wpSPIO()->settings()->png2jpg = 1;

		$source = $this->makeTransparentPng();
		$id     = $this->uploadFile( $source );
		unlink( $source );

		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame( 'png', strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ), 'Transparent PNG must stay a .png when png2jpg=1.' );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized(), 'The unconverted PNG must still be optimized normally.' );
	}

	public function test_force_setting_converts_transparent_png() {
		\wpSPIO()->settings()->png2jpg = 2;

		$source = $this->makeTransparentPng();
		$id     = $this->uploadFile( $source );
		unlink( $source );

		$mainPath = get_attached_file( $id );
		$this->assertSame( 'jpg', strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ), 'png2jpg=2 must convert transparent PNGs too.' );

		$this->optimizeAttachment( $id );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );
	}

	public function test_conversion_keeps_backup_of_original_png() {
		\wpSPIO()->settings()->png2jpg = 1;

		$source  = $this->makeUniquePngCopy();
		$pngName = basename( $source );
		$id      = $this->uploadFile( $source );
		unlink( $source );

		$this->assertSame( 'jpg', strtolower( pathinfo( get_attached_file( $id ), PATHINFO_EXTENSION ) ) );

		$backupPngs = array();
		if ( is_dir( SHORTPIXEL_BACKUP_FOLDER ) ) {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SHORTPIXEL_BACKUP_FOLDER, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && $file->getFilename() === $pngName ) {
					$backupPngs[] = $file->getPathname();
				}
			}
		}

		$this->assertNotEmpty( $backupPngs, 'The original .png must be backed up before conversion (conversionPrepare).' );
	}

	public function test_png_stays_png_when_setting_is_off() {
		$id = $this->uploadFixture( 'fixture-small.png' );

		$this->optimizeAttachment( $id );

		$mainPath = get_attached_file( $id );
		$this->assertSame( 'png', strtolower( pathinfo( $mainPath, PATHINFO_EXTENSION ) ), 'png2jpg=0 must leave PNGs untouched.' );

		$image = $this->freshImageModel( $id );
		$this->assertTrue( $image->isOptimized() );
	}

	/**
	 * Regression for bug #6 (fixed in 1dbdf638): PNGConverter::convertFile()
	 * hardcoded `return true` even when Image::convertPNG() (the actual
	 * GD/Imagick conversion) returned false — a silent false-success that
	 * left updateMetaData() bailing later with "NewFile not properly set".
	 *
	 * The failing-library branch cannot be provoked through a real upload
	 * (GD is present in the test container and PNG fixtures convert fine),
	 * so the loaded-image object is replaced via the converter's own
	 * $current_image cache slot with a stub whose convertPNG() fails —
	 * exercising the exact `$bool = $image->convertPNG()` path.
	 *
	 * Sentinel: with the bug present convertFile() returns true and the
	 * assertFalse fails.
	 */
	public function test_convertfile_returns_false_when_library_conversion_fails() {
		\wpSPIO()->settings()->png2jpg = 0; // keep the upload hook from converting

		$id    = $this->uploadFixture( 'fixture-small.png' );
		$image = $this->freshImageModel( $id );

		$converter = new \ShortPixel\Model\Converter\PNGConverter( $image );

		// A "loaded" image whose conversion fails.
		$stub = new class() {
			public function getWidth() {
				return 100;
			}
			public function getHeight() {
				return 100;
			}
			public function convertPNG() {
				return false;
			}
		};

		// getPNGImage() returns $current_image when it is already an object.
		$prop = new ReflectionProperty( \ShortPixel\Model\Converter\PNGConverter::class, 'current_image' );
		$prop->setAccessible( true );
		$prop->setValue( $converter, $stub );

		$m = new ReflectionMethod( \ShortPixel\Model\Converter\PNGConverter::class, 'convertFile' );
		$m->setAccessible( true );

		$this->assertFalse(
			$m->invoke( $converter ),
			'convertFile() must return false when convertPNG() fails (bug #6: hardcoded `return true`, fixed 1dbdf638).'
		);
	}
}
