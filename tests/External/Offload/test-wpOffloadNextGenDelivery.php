<?php
/**
 * Tests for next-gen (WebP/AVIF) delivery of images offloaded by
 * WP Offload Media: wpOffload::checkIfOffloaded() / getSourceIDByURL()
 * and wpOffload::fixWebpRemotePath(), driven through the real
 * PictureController::convertImage().
 *
 * as3cf is replaced by a stub item class (injected through the
 * `useHandlers` / `itemClassName` properties that getMediaClass() reads).
 * Its get_item_source_by_remote_url() resolves only the files the item
 * records, which is what the real plugin indexes.
 *
 * The test image has a WebP for every size but an AVIF only for the
 * 400x225 size and the full size: ShortPixel drops an AVIF that is not
 * smaller than the WebP, so small sizes often have none.
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\External\Offload\wpOffload;
use ShortPixel\Controller\Front\PictureController;
use ShortPixel\Model\File\FileModel;

class wpOffloadNextGenDeliveryTest extends WP_UnitTestCase {

	const BASE = 'https://storage.googleapis.com/spio-bucket/wp-content/uploads/2026/10/';

	/** Objects recorded by the as3cf item for the AVIF-partial test image. */
	private function partialAvifObjects(): array {
		return array(
			'__as3cf_primary'       => array( 'source_file' => 'name.jpg' ),
			'thumbnail'             => array( 'source_file' => 'name-120x68.jpg' ),
			'small'                 => array( 'source_file' => 'name-256x144.jpg' ),
			'medium'                => array( 'source_file' => 'name-400x225.jpg' ),
			'__as3cf_primary_webp2' => array( 'source_file' => 'name.webp' ),
			'thumbnail_webp2'       => array( 'source_file' => 'name-120x68.webp' ),
			'small_webp2'           => array( 'source_file' => 'name-256x144.webp' ),
			'medium_webp2'          => array( 'source_file' => 'name-400x225.webp' ),
			'__as3cf_primary_avif2' => array( 'source_file' => 'name.avif' ),
			'medium_avif2'          => array( 'source_file' => 'name-400x225.avif' ),
		);
	}

	public function set_up() {
		parent::set_up();
		$this->setStatic( 'sources', array() );
		$this->setStatic( 'paths', array() );
		SPIO_Test_As3cf_NextGen_Item_Stub::$lookups = array();
		SPIO_Test_As3cf_NextGen_Item_Stub::$objects = $this->partialAvifObjects();
	}

	public function tear_down() {
		remove_all_filters( 'shortpixel/image/urltopath' );
		remove_all_filters( 'shortpixel/front/webp_notfound' );
		$this->setStatic( 'sources', array() );
		$this->setStatic( 'paths', array() );
		parent::tear_down();
	}

	/*
	 * Helpers
	 */

	private function setStatic( string $prop, $value ): void {
		$p = ( new ReflectionClass( wpOffload::class ) )->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( null, $value );
	}

	/** wpOffload backed by the stub item class, wired to the two front-end filters. */
	private function offload(): wpOffload {
		$ref = new ReflectionClass( wpOffload::class );
		$o   = $ref->newInstanceWithoutConstructor();
		foreach ( array( 'useHandlers' => false, 'itemClassName' => SPIO_Test_As3cf_NextGen_Item_Stub::class ) as $prop => $value ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( $o, $value );
		}
		add_filter( 'shortpixel/image/urltopath', array( $o, 'checkIfOffloaded' ), 10, 3 );
		add_filter( 'shortpixel/front/webp_notfound', array( $o, 'fixWebpRemotePath' ), 10, 4 );
		return $o;
	}

	private function convert( string $html ): string {
		$pc = ( new ReflectionClass( PictureController::class ) )->newInstanceWithoutConstructor();
		$m  = new ReflectionMethod( PictureController::class, 'convertImage' );
		$m->setAccessible( true );
		return $m->invoke( $pc, array( $html ) );
	}

	/** The test image's <img> tag, as delivered from the bucket. */
	private function offloadedImgTag(): string {
		$b = self::BASE;
		return '<img src="' . $b . 'name-400x225.jpg" srcset="' . $b . 'name-120x68.jpg 120w, ' . $b . 'name-256x144.jpg 256w, '
			. $b . 'name-400x225.jpg 400w, ' . $b . 'name.jpg 1200w" sizes="100vw">';
	}

	/** srcset attribute of the <source> of the given type, or null when there is none. */
	private function sourceSrcset( string $html, string $type ): ?string {
		if ( ! preg_match( '/<source[^>]*srcset="([^"]*)"[^>]*type="image\/' . $type . '"/', $html, $m ) ) {
			return null;
		}
		return $m[1];
	}

	/*
	 * Companion existence after offload
	 */

	/**
	 * Pins a known defect: an AVIF the item never recorded is reported as
	 * offloaded. checkIfOffloaded() → getSourceIDByURL() strips the `-WxH`
	 * suffix, so `name-120x68.avif` becomes `name.avif`; that URL is in the
	 * source cache (warmed from the item's recorded objects), so the
	 * thumbnail companion resolves to the item and the virtual file reports
	 * exists() === true. Any size works, including one that was never
	 * generated.
	 *
	 * Flip when: a companion resolved only through the stripped URL is
	 * checked against the item's recorded objects, and exists() is false.
	 */
	public function test_unrecorded_thumbnail_avif_is_reported_as_existing_pinned_for_deferred_fix() {
		$this->offload();
		$fs = \wpSPIO()->filesystem();

		$main = $fs->getFile( self::BASE . 'name-120x68.jpg' );
		$main->getExtension(); // resolves the main image, warming the source cache like convertImage() does.

		// SENTINELS: the item really lacks the thumbnail AVIF, while it has the full-size AVIF the strip lands on.
		$recorded = array_column( SPIO_Test_As3cf_NextGen_Item_Stub::$objects, 'source_file' );
		$this->assertNotContains( 'name-120x68.avif', $recorded, 'Sentinel: the thumbnail AVIF is not recorded.' );
		$this->assertContains( 'name.avif', $recorded, 'Sentinel: the full-size AVIF is recorded.' );
		$this->assertTrue( $main->exists(), 'Sentinel: the offloaded main thumbnail resolves.' );
		$this->assertFalse( $fs->getFile( self::BASE . 'name-120x68.jpg.avif' )->exists(), 'Sentinel: an unrelated, unrecorded name does not resolve.' );

		$this->assertTrue(
			$fs->getFile( self::BASE . 'name-120x68.avif' )->exists(),
			'Fixed? An unrecorded thumbnail AVIF no longer counts as offloaded — flip this pin.'
		);
		$this->assertTrue(
			$fs->getFile( self::BASE . 'name-999x999.avif' )->exists(),
			'Fixed? A never-generated size no longer counts as offloaded — flip this pin.'
		);
	}

	/**
	 * Pins a known defect, as visitors see it: after offload, the AVIF
	 * srcset lists bucket URLs for sizes that have no AVIF (they return
	 * 403/404, a broken image wherever that size is picked). Before
	 * offload the same sizes fall back to their WebP URL.
	 *
	 * Flip when: the AVIF srcset uses name-120x68.webp / name-256x144.webp
	 * for those two sizes (or omits them).
	 */
	public function test_offloaded_avif_srcset_lists_avifs_that_do_not_exist_pinned_for_deferred_fix() {
		$this->offload();

		$out  = $this->convert( $this->offloadedImgTag() );
		$avif = $this->sourceSrcset( $out, 'avif' );
		$webp = $this->sourceSrcset( $out, 'webp' );

		// SENTINELS: a <picture> was built, the sizes that do have an AVIF are listed, and every WebP is there.
		$this->assertNotNull( $avif, 'Sentinel: an AVIF <source> is emitted (two sizes really have one).' );
		$this->assertNotNull( $webp, 'Sentinel: a WebP <source> is emitted.' );
		$this->assertStringContainsString( self::BASE . 'name-400x225.avif 400w', $avif, 'Sentinel: the recorded 400x225 AVIF is listed.' );
		$this->assertStringContainsString( self::BASE . 'name.avif 1200w', $avif, 'Sentinel: the recorded full-size AVIF is listed.' );
		$this->assertStringContainsString( self::BASE . 'name-120x68.webp 120w', $webp, 'Sentinel: the thumbnail WebP is listed.' );

		$this->assertStringContainsString(
			self::BASE . 'name-120x68.avif 120w',
			$avif,
			'Fixed? The AVIF srcset no longer lists the missing 120x68 AVIF — flip this pin (expect name-120x68.webp there).'
		);
		$this->assertStringContainsString(
			self::BASE . 'name-256x144.avif 256w',
			$avif,
			'Fixed? The AVIF srcset no longer lists the missing 256x144 AVIF — flip this pin (expect name-256x144.webp there).'
		);
	}

	/**
	 * An offloaded image with WebP only (no AVIF for any size, so no
	 * `name.avif` for the suffix strip to land on) gets no AVIF <source>.
	 * Guards the fix for the pins above against over-correcting into the
	 * WebP path.
	 */
	public function test_offloaded_webp_only_image_gets_no_avif_source() {
		SPIO_Test_As3cf_NextGen_Item_Stub::$objects = array_filter(
			$this->partialAvifObjects(),
			function ( $object ) {
				return '.avif' !== substr( $object['source_file'], -5 );
			}
		);
		$this->offload();

		$out = $this->convert( $this->offloadedImgTag() );

		$this->assertNull( $this->sourceSrcset( $out, 'avif' ), 'No AVIF <source> may be emitted when no AVIF was offloaded.' );
		$webp = $this->sourceSrcset( $out, 'webp' );
		$this->assertNotNull( $webp, 'The WebP <source> is still emitted.' );
		foreach ( array( 'name-120x68.webp 120w', 'name-256x144.webp 256w', 'name-400x225.webp 400w', 'name.webp 1200w' ) as $entry ) {
			$this->assertStringContainsString( self::BASE . $entry, $webp );
		}
	}

	/*
	 * fixWebpRemotePath() — the `shortpixel/front/webp_notfound` filter
	 */

	/**
	 * Pins a known defect: for a companion given as a local path, the
	 * filter returns the companion as soon as the MAIN URL resolves to an
	 * offloaded item; it never checks that this companion is one of the
	 * item's recorded objects. Reached when the page delivers local URLs
	 * for an offloaded item whose local companion is missing (e.g. "Remove
	 * Local Media" on).
	 *
	 * Flip when: an unrecorded companion makes the filter return false.
	 */
	public function test_fixWebpRemotePath_returns_an_unrecorded_local_companion_pinned_for_deferred_fix() {
		$o         = $this->offload();
		$companion = new FileModel( '/tmp/spio-offload-nextgen/name-120x68.avif' );

		// SENTINELS: the companion is local, missing on disk, and not recorded by the item.
		$this->assertFalse( \wpSPIO()->filesystem()->pathIsUrl( $companion->getFullPath() ), 'Sentinel: the companion is given as a local path.' );
		$this->assertFalse( $companion->exists(), 'Sentinel: the local companion does not exist.' );
		$this->assertNotContains( 'name-120x68.avif', array_column( SPIO_Test_As3cf_NextGen_Item_Stub::$objects, 'source_file' ), 'Sentinel: the companion is not recorded.' );

		$this->assertSame(
			$companion,
			$o->fixWebpRemotePath( false, $companion, self::BASE . 'name-120x68.jpg', '/tmp/spio-offload-nextgen/' ),
			'Fixed? fixWebpRemotePath() no longer returns an unrecorded companion — flip this pin.'
		);
	}

	/*
	 * getSourceIDByURL() — double-extension fallback
	 */

	/**
	 * Pins a known defect: the double-extension fallback can never resolve
	 * anything. It builds its retry URL with
	 * `substr($url, 0, strpos($url, '.'))`, which cuts at the first dot of
	 * the HOSTNAME (`https://storage.avif`), and then queries as3cf with
	 * the original `$url` instead of that retry URL. So a `.jpg.webp` URL
	 * is looked up three times, unchanged.
	 *
	 * Flip when: the fallback queries the single-extension URL (or is
	 * removed, leaving two lookups).
	 */
	public function test_double_extension_fallback_only_requeries_the_original_url_pinned_for_deferred_fix() {
		$o   = $this->offload();
		$url = self::BASE . 'other.jpg.webp';

		$get = new ReflectionMethod( wpOffload::class, 'getSourceIDByURL' );
		$get->setAccessible( true );

		// SENTINEL: nothing about this URL is cached or recorded, so every step reaches as3cf.
		$this->assertSame( array(), SPIO_Test_As3cf_NextGen_Item_Stub::$lookups, 'Sentinel: no lookups before the call.' );

		$this->assertFalse( $get->invoke( $o, $url ), 'Sentinel: an unknown URL is not offloaded.' );

		$this->assertSame(
			array( $url, $url, $url ),
			SPIO_Test_As3cf_NextGen_Item_Stub::$lookups,
			'Fixed? The double-extension fallback no longer re-queries the unchanged URL — flip this pin.'
		);
	}
}

/**
 * Stand-in for the as3cf media-library item class that
 * wpOffload::getMediaClass() returns when handlers are off. Resolves a
 * remote URL only when its basename is one of the recorded objects, and
 * hands out an item whose extra_info() lists those objects.
 */
if ( ! class_exists( 'SPIO_Test_As3cf_NextGen_Item_Stub', false ) ) {
	final class SPIO_Test_As3cf_NextGen_Item_Stub {
		/** @var string[] URLs passed to get_item_source_by_remote_url(), in order. */
		public static $lookups = array();
		/** @var array<string, array{source_file: string}> The item's recorded objects. */
		public static $objects = array();

		public static function get_item_source_by_remote_url( $url ) {
			self::$lookups[] = $url;
			$basename        = basename( (string) parse_url( $url, PHP_URL_PATH ) );
			foreach ( self::$objects as $object ) {
				if ( $object['source_file'] === $basename ) {
					return array( 'id' => 77, 'source_type' => 'media-library' );
				}
			}
			return false;
		}

		public static function get_by_source_id( $id ) {
			return new class() {
				public function extra_info() {
					return array( 'objects' => SPIO_Test_As3cf_NextGen_Item_Stub::$objects );
				}
			};
		}
	}
}
