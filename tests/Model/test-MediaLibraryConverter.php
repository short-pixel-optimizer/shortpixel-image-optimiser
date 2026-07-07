<?php
/**
 * Tests for ShortPixel\Model\Converter\MediaLibraryConverter.
 *
 * MediaLibraryConverter is an abstract intermediate layer between the
 * plain Converter base and the four concrete converters (PNGConverter,
 * ApiConverter, BMPConverter, plus a stub used for tests here). It owns
 * the WordPress-media-library-specific glue: Replacer setup, URL
 * rewriting after a rename, and attachment-metadata updates.
 *
 * Almost every method touches the Replacer class, the filesystem
 * controller, or wp_update_post/wp_update_attachment_metadata — well
 * outside the unit-test scope. This file exists to formally close the
 * folder's coverage with the small pure-logic surface that does exist:
 * the class-shape sanity, property defaults, and getUpdatedMeta's thin
 * pass-through to wp_get_attachment_metadata.
 *
 * Skipped at the unit level (integration territory):
 *   - setupReplacer → constructs a Replacer + fs->pathToUrl + reads
 *     wp attachment metadata
 *   - setTarget → depends on a live Replacer to call setTarget() on
 *   - updateMetaData → update_attached_file / wp_update_post /
 *     wp_generate_attachment_metadata / wp_update_attachment_metadata
 *     across WPML duplicates
 *
 * @package Shortpixel_Image_Optimiser
 */

use ShortPixel\Model\Converter\MediaLibraryConverter;
use ShortPixel\Model\Converter\Converter;
use ShortPixel\Model\Image\ImageMeta;
use ShortPixel\Model\Queue\QueueItem;
use ShortPixel\Model\File\FileModel;

/**
 * Concrete stub extending MediaLibraryConverter. All the remaining
 * abstracts from the Converter grandparent are no-ops so we can
 * instantiate the class.
 */
class SPIO_TestMediaLibraryConverter extends MediaLibraryConverter {
	public function convert( $args = array() ) { return false; }
	public function isConvertable() { return false; }
	public function restore() { return false; }
	public function getCheckSum() { return 0; }
	public function filterQueue( QueueItem $item, $args = array() ) {}
}

class MediaLibraryConverterTest extends WP_UnitTestCase {

	private function makeImageStub( int $attachId, string $extension = 'png' ) {
		$imageMeta = new ImageMeta();

		return new class( $imageMeta, $attachId, $extension ) {
			public $meta;
			public $stub_id;
			public $stub_ext;
			public function __construct( $meta, $id, $ext ) {
				$this->meta     = $meta;
				$this->stub_id  = $id;
				$this->stub_ext = $ext;
			}
			public function getExtension() { return $this->stub_ext; }
			public function getMeta( $name = false ) {
				if ( $name === false ) return $this->meta;
				return null;
			}
			public function get( $name ) {
				if ( $name === 'id' ) return $this->stub_id;
				return null;
			}
		};
	}

	private function getPrivate( $instance, string $prop ) {
		$ref = new ReflectionClass( get_class( $instance ) );
		while ( $ref && ! $ref->hasProperty( $prop ) ) {
			$ref = $ref->getParentClass();
		}
		$p = $ref->getProperty( $prop );
		$p->setAccessible( true );
		return $p->getValue( $instance );
	}

	/*
	 * Class shape — the intermediate layer between Converter and the concrete converters
	 */

	public function test_MediaLibraryConverter_is_abstract() {
		$ref = new ReflectionClass( MediaLibraryConverter::class );
		$this->assertTrue( $ref->isAbstract() );
	}

	public function test_MediaLibraryConverter_extends_the_Converter_base() {
		$this->assertTrue( is_subclass_of( MediaLibraryConverter::class, Converter::class ) );
	}

	/*
	 * Property defaults — everything null before setupReplacer / setTarget run
	 */

	public function test_source_url_defaults_to_null() {
		$stub = new SPIO_TestMediaLibraryConverter( $this->makeImageStub( 1 ) );
		$this->assertNull( $this->getPrivate( $stub, 'source_url' ) );
	}

	public function test_replacer_defaults_to_null() {
		$stub = new SPIO_TestMediaLibraryConverter( $this->makeImageStub( 1 ) );
		$this->assertNull( $this->getPrivate( $stub, 'replacer' ) );
	}

	public function test_newFile_defaults_to_null() {
		$stub = new SPIO_TestMediaLibraryConverter( $this->makeImageStub( 1 ) );
		$this->assertNull( $this->getPrivate( $stub, 'newFile' ) );
	}

	/*
	 * getUpdatedMeta — thin pass-through to wp_get_attachment_metadata
	 */

	public function test_getUpdatedMeta_returns_the_stored_wp_attachment_metadata_for_the_bound_id() {
		$attach_id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );

		$expected = array(
			'width'  => 640,
			'height' => 480,
			'file'   => '2024/01/example.png',
			'sizes'  => array(
				'thumbnail' => array(
					'file'   => 'example-150x150.png',
					'width'  => 150,
					'height' => 150,
				),
			),
		);
		wp_update_attachment_metadata( $attach_id, $expected );

		$stub = new SPIO_TestMediaLibraryConverter( $this->makeImageStub( $attach_id ) );

		$this->assertEquals( $expected, $stub->getUpdatedMeta() );
	}

	public function test_getUpdatedMeta_returns_false_for_an_attachment_with_no_metadata() {
		$attach_id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );

		$stub = new SPIO_TestMediaLibraryConverter( $this->makeImageStub( $attach_id ) );

		// WordPress returns false (not [] or null) when no _wp_attachment_metadata is stored.
		$this->assertFalse( $stub->getUpdatedMeta() );
	}
}
