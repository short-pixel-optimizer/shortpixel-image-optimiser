<?php
/**
 * Tests for ShortPixel\Model\Image\Image.
 *
 * Covers the parts of the class that don't require GD or Imagick to actually
 * decode / encode an image on disk: constructor state, library detection,
 * the loaded/not-loaded check, the replacement-path getter, and the finish()
 * resource-release logic.
 *
 * Skipped at the unit level (integration territory — needs GD or Imagick +
 * a real PNG on disk):
 *   - loadImageResource(), loadImagickImage(), loadGDImage()
 *   - convertPNG(), convertImagick(), convertGD()
 *   - getWidth(), getHeight()
 *   - isTransparent()
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Image\Image;

class ImageTest extends WP_UnitTestCase {

	/**
	 * Saved is_gd_installed / is_imagick_installed values from wpSPIO()->env()
	 * so tests can override them and restore between runs.
	 * @var bool
	 */
	private $savedGdInstalled;
	private $savedImagickInstalled;

	public function set_up() {
		parent::set_up();
		$env                          = \wpSPIO()->env();
		$this->savedGdInstalled       = $env->is_gd_installed;
		$this->savedImagickInstalled  = $env->is_imagick_installed;
	}

	public function tear_down() {
		$env                          = \wpSPIO()->env();
		$env->is_gd_installed         = $this->savedGdInstalled;
		$env->is_imagick_installed    = $this->savedImagickInstalled;
		parent::tear_down();
	}

	/*
	 * Reflection helpers
	 */

	private function freshImage(): Image {
		$ref = new ReflectionClass( Image::class );
		return $ref->newInstanceWithoutConstructor();
	}

	private function setPrivate( Image $img, string $prop, $value ): void {
		$ref = new ReflectionClass( Image::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( $img, $value );
	}

	private function getPrivate( Image $img, string $prop ) {
		$ref = new ReflectionClass( Image::class );
		$p   = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $img );
	}

	private function invokePrivate( Image $img, string $method, array $args = array() ) {
		$ref = new ReflectionClass( Image::class );
		$m   = $ref->getMethod( $method );
		$m->setAccessible( true );
		return $m->invoke( $img, ...$args );
	}

	/*
	 * checkLibrary — GD-first when both are available, then Imagick fallback,
	 * otherwise useLib is left unset.
	 */

	public function test_checkLibrary_selects_gd_when_both_are_available() {
		$env                        = \wpSPIO()->env();
		$env->is_gd_installed       = true;
		$env->is_imagick_installed  = true;

		$img = $this->freshImage();
		$this->invokePrivate( $img, 'checkLibrary' );

		$this->assertSame( 'gd', $this->getPrivate( $img, 'useLib' ) );
	}

	public function test_checkLibrary_selects_gd_when_only_gd_is_available() {
		$env                        = \wpSPIO()->env();
		$env->is_gd_installed       = true;
		$env->is_imagick_installed  = false;

		$img = $this->freshImage();
		$this->invokePrivate( $img, 'checkLibrary' );

		$this->assertSame( 'gd', $this->getPrivate( $img, 'useLib' ) );
	}

	public function test_checkLibrary_falls_back_to_imagick_when_gd_is_unavailable() {
		$env                        = \wpSPIO()->env();
		$env->is_gd_installed       = false;
		$env->is_imagick_installed  = true;

		$img = $this->freshImage();
		$this->invokePrivate( $img, 'checkLibrary' );

		$this->assertSame( 'imagick', $this->getPrivate( $img, 'useLib' ) );
	}

	public function test_checkLibrary_leaves_useLib_null_when_neither_is_available() {
		$env                        = \wpSPIO()->env();
		$env->is_gd_installed       = false;
		$env->is_imagick_installed  = false;

		$img = $this->freshImage();
		$this->invokePrivate( $img, 'checkLibrary' );

		$this->assertNull( $this->getPrivate( $img, 'useLib' ) );
	}

	/*
	 * checkImageLoaded
	 */

	public function test_checkImageLoaded_false_when_image_is_null() {
		$img = $this->freshImage();
		$this->setPrivate( $img, 'image', null );
		$this->assertFalse( $img->checkImageLoaded() );
	}

	public function test_checkImageLoaded_false_when_image_is_false() {
		$img = $this->freshImage();
		$this->setPrivate( $img, 'image', false );
		$this->assertFalse( $img->checkImageLoaded() );
	}

	public function test_checkImageLoaded_true_when_image_is_a_resource_or_object() {
		$img = $this->freshImage();
		$this->setPrivate( $img, 'image', new stdClass() );
		$this->assertTrue( $img->checkImageLoaded() );
	}

	/*
	 * getReplacementPath
	 */

	public function test_getReplacementPath_returns_the_stored_path() {
		$img = $this->freshImage();
		$this->setPrivate( $img, 'replacementPath', '/tmp/replacement.jpg' );
		$this->assertSame( '/tmp/replacement.jpg', $img->getReplacementPath() );
	}

	/*
	 * finish — GD branch nulls the image, Imagick branch calls ->clear() then
	 * nulls it. A useLib of neither leaves the image untouched.
	 */

	public function test_finish_nulls_image_when_useLib_is_gd() {
		$img = $this->freshImage();
		$this->setPrivate( $img, 'useLib', 'gd' );
		$this->setPrivate( $img, 'image', new stdClass() );

		$this->invokePrivate( $img, 'finish' );

		$this->assertNull( $this->getPrivate( $img, 'image' ) );
	}

	public function test_finish_calls_clear_and_nulls_image_when_useLib_is_imagick() {
		$fake = new class() {
			public $cleared = false;
			public function clear() {
				$this->cleared = true;
			}
		};

		$img = $this->freshImage();
		$this->setPrivate( $img, 'useLib', 'imagick' );
		$this->setPrivate( $img, 'image', $fake );

		$this->invokePrivate( $img, 'finish' );

		$this->assertTrue( $fake->cleared );
		$this->assertNull( $this->getPrivate( $img, 'image' ) );
	}

	public function test_finish_is_a_noop_when_useLib_is_unset() {
		$img = $this->freshImage();
		$this->setPrivate( $img, 'useLib', null );
		$sentinel = new stdClass();
		$this->setPrivate( $img, 'image', $sentinel );

		$this->invokePrivate( $img, 'finish' );

		$this->assertSame( $sentinel, $this->getPrivate( $img, 'image' ) );
	}
}
