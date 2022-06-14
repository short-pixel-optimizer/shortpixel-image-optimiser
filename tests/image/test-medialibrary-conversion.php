<?php
//use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use \ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use \ShortPixel\Model\Image\ImageModel as ImageModel;

class  MediaLibraryModelConversionTest extends WP_UnitTestCase
{
  private static $LMethod;  // legacy method

  private $className = '\ShortPixel\Model\Image\MediaLibraryModel';
  protected static $path;
  //private $className = 'MediaLibraryModel';

  public static function wpSetUpBeforeClass($factory)
  {
    $upload_dir = wp_upload_dir('2020/11', true);

    $zip = new ZipArchive;
    $res = $zip->open( dirname(__FILE__) . '/assets/test-conversion.zip');

    if ($res === TRUE) {
      $zip->extractTo($upload_dir['path']);
    }
    $zip->close();

    self::$path = trailingslashit($upload_dir['path']);
    //mkdir($upload_dir . '/2020/11');

  /*  $refWPQ = new ReflectionClass();
    $LMethod = $refWPQ->getMethod('checkLegacy');
    $LMethod->setAccessible(true);

    self::$LMethod = $LMethod; */

  }

  public function getPrivateMethod( $className, $methodName ) {
    $reflector = new ReflectionClass( $className );
    $method = $reflector->getMethod( $methodName );
    $method->setAccessible( true );
    return $method;
  }

  public function getPrivateProperty( $className, $property ) {
    $reflector = new ReflectionClass( $className );
    $prop = $reflector->getProperty( $property );
    $prop->setAccessible( true );
    return $prop;
  }

  // This dataset has retina's and a bunch of double image formats. Is also scaled.
  // The source images are also resized, somehow not registered (?)
  // File Archive includes webp files.
  // @todo Also missing is backup information
  public function getOldFormat()
  {
     $metadata = array ( 'width' => 924, 'height' => 924, 'file' => '2020/11/9OE_n601RyA-scaled.jpg', 'sizes' => array ( 'medium' => array ( 'file' => '9OE_n601RyA-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg', ), 'large' => array ( 'file' => '9OE_n601RyA-1024x1024.jpg', 'width' => 1024, 'height' => 1024, 'mime-type' => 'image/jpeg', ), 'thumbnail' => array ( 'file' => '9OE_n601RyA-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg', ), 'medium_large' => array ( 'file' => '9OE_n601RyA-768x768.jpg', 'width' => 768, 'height' => 768, 'mime-type' => 'image/jpeg', ), '1536x1536' => array ( 'file' => '9OE_n601RyA-1536x1536.jpg', 'width' => 1536, 'height' => 1536, 'mime-type' => 'image/jpeg', ), '2048x2048' => array ( 'file' => '9OE_n601RyA-2048x2048.jpg', 'width' => 2048, 'height' => 2048, 'mime-type' => 'image/jpeg', ), 'rta_thumb_no_cropped_6x6' => array ( 'file' => '9OE_n601RyA-6x6.jpg', 'width' => 6, 'height' => 6, 'mime-type' => 'image/jpeg', ), 'post-thumbnail' => array ( 'file' => '9OE_n601RyA-1000x288.jpg', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/jpeg', ),  'small-feature' => array ( 'file' => '9OE_n601RyA-300x300.jpg', 'width' => 300, 'height' => 300, 'mime-type' => 'image/jpeg', ), ), 'image_meta' => array ( 'aperture' => '0', 'credit' => '', 'camera' => '', 'caption' => '', 'created_timestamp' => '0', 'copyright' => '', 'focal_length' => '0', 'iso' => '0', 'shutter_speed' => '0', 'title' => '', 'orientation' => '0', 'keywords' => array ( ), ), 'original_image' => '9OE_n601RyA.jpg', 'ShortPixel' => array ( 'date' => '2020-11-04 15:36:26', 'type' => 'lossy', 'exifKept' => '0', 'thumbsOpt' => 9, 'thumbsOptList' => array ( 0 => '9OE_n601RyA.jpg', 1 => '9OE_n601RyA-300x300.jpg', 2 => '9OE_n601RyA-1024x1024.jpg', 3 => '9OE_n601RyA-150x150.jpg', 4 => '9OE_n601RyA-768x768.jpg', 5 => '9OE_n601RyA-1536x1536.jpg', 6 => '9OE_n601RyA-2048x2048.jpg', 7 => '9OE_n601RyA-6x6.jpg', 8 => '9OE_n601RyA-1000x288.jpg', ), 'excludeSizes' => array ( ), 'retinasOpt' => 5, ), 'ShortPixelImprovement' => '87.63', );
     return $metadata;
  }

  /**
    *Has a PNG2JPG converted success result
    *Also Kept Exif
  */
  public function getPng2JpgFormat()
  {
     $metadata = array ( 'width' => 1280, 'height' => 498, 'file' => '2020/11/02-new.jpg', 'sizes' => array ( 'medium' => array ( 'file' => '02-new-400x156.jpg', 'width' => 400, 'height' => 156, 'mime-type' => 'image/jpeg', ), 'large' => array ( 'file' => '02-new-1024x398.jpg', 'width' => 1024, 'height' => 398, 'mime-type' => 'image/jpeg', ), 'thumbnail' => array ( 'file' => '02-new-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg', ), 'medium_large' => array ( 'file' => '02-new-768x299.jpg', 'width' => 768, 'height' => 299, 'mime-type' => 'image/jpeg', ), 'rta_thumb_no_cropped_6x6' => array ( 'file' => '02-new-6x2.png', 'width' => 6, 'height' => 2, 'mime-type' => 'image/png', ), 'post-thumbnail' => array ( 'file' => '02-new-1000x288.jpg', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/jpeg', ), 'large-feature' => array ( 'file' => '02-new-1000x288.jpg', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/jpeg', ), 'small-feature' => array ( 'file' => '02-new-500x195.jpg', 'width' => 500, 'height' => 195, 'mime-type' => 'image/jpeg', ), ), 'image_meta' => array ( 'aperture' => '0', 'credit' => '', 'camera' => '', 'caption' => '', 'created_timestamp' => '0', 'copyright' => '', 'focal_length' => '0', 'iso' => '0', 'shutter_speed' => '0', 'title' => '', 'orientation' => '0', 'keywords' => array ( ), ), 'type' => 'image/jpeg', 'ShortPixel' => array ( 'date' => '2020-11-04 15:03:41', 'Retries' => 0, 'thumbsMissing' => array ( 'rta_thumb_no_cropped_6x6' => '02-new-6x2.png', ), 'type' => 'lossy', 'exifKept' => '1', 'thumbsOpt' => 6, 'thumbsOptList' => array ( 0 => '02-new-400x156.jpg', 1 => '02-new-1024x398.jpg', 2 => '02-new-150x150.jpg', 3 => '02-new-768x299.jpg', 4 => '02-new-1000x288.jpg', 5 => '02-new-500x195.jpg', ), 'excludeSizes' => array ( ), 'retinasOpt' => 0, ), 'ShortPixelPng2Jpg' => array ( 'originalFile' => '/var/www/shortpixel/wp-content/uploads/2020/11/02-new.png', 'originalSizes' => array ( 'medium' => array ( 'file' => '02-new-400x156.png', 'width' => 400, 'height' => 156, 'mime-type' => 'image/png', ), 'large' => array ( 'file' => '02-new-1024x398.png', 'width' => 1024, 'height' => 398, 'mime-type' => 'image/png', ), 'thumbnail' => array ( 'file' => '02-new-150x150.png', 'width' => 150, 'height' => 150, 'mime-type' => 'image/png', ), 'medium_large' => array ( 'file' => '02-new-768x299.png', 'width' => 768, 'height' => 299, 'mime-type' => 'image/png', ), 'rta_thumb_no_cropped_6x6' => array ( 'file' => '02-new-6x2.png', 'width' => 6, 'height' => 2, 'mime-type' => 'image/png', ), 'post-thumbnail' => array ( 'file' => '02-new-1000x288.png', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/png', ), 'large-feature' => array ( 'file' => '02-new-1000x288.png', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/png', ), 'small-feature' => array ( 'file' => '02-new-500x195.png', 'width' => 500, 'height' => 195, 'mime-type' => 'image/png', ), ), 'backup' => '1', 'optimizationPercent' => 60.0, ), 'ShortPixelImprovement' => '46.08', ) ;
     return $metadata;
  }

  // Has an unlisted image ( 1337x1337 )
  public function getExample()
  {
     return array ( 'width' => 1408, 'height' => 924, 'file' => '2020/11/NljdMbT3s30.jpg', 'sizes' => array ( 'medium' => array ( 'file' => 'NljdMbT3s30-400x263.jpg', 'width' => 400, 'height' => 263, 'mime-type' => 'image/jpeg', ), 'large' => array ( 'file' => 'NljdMbT3s30-1024x672.jpg', 'width' => 1024, 'height' => 672, 'mime-type' => 'image/jpeg', ), 'thumbnail' => array ( 'file' => 'NljdMbT3s30-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg', ), 'medium_large' => array ( 'file' => 'NljdMbT3s30-768x504.jpg', 'width' => 768, 'height' => 504, 'mime-type' => 'image/jpeg', ), '1536x1536' => array ( 'file' => 'NljdMbT3s30-1536x1008.jpg', 'width' => 1536, 'height' => 1008, 'mime-type' => 'image/jpeg', ), '2048x2048' => array ( 'file' => 'NljdMbT3s30-2048x1344.jpg', 'width' => 2048, 'height' => 1344, 'mime-type' => 'image/jpeg', ), 'rta_thumb_no_cropped_6x6' => array ( 'file' => 'NljdMbT3s30-6x4.jpg', 'width' => 6, 'height' => 4, 'mime-type' => 'image/jpeg', ), 'post-thumbnail' => array ( 'file' => 'NljdMbT3s30-1000x288.jpg', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/jpeg', ), 'large-feature' => array ( 'file' => 'NljdMbT3s30-1000x288.jpg', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/jpeg', ), 'small-feature' => array ( 'file' => 'NljdMbT3s30-457x300.jpg', 'width' => 457, 'height' => 300, 'mime-type' => 'image/jpeg', ), 'sp-found-01' => array ( 'file' => 'NljdMbT3s30-1337x1337.jpg', 'width' => 1000, 'height' => 288, 'mime-type' => 'image/jpeg', ), ), 'image_meta' => array ( 'aperture' => '0', 'credit' => '', 'camera' => '', 'caption' => '', 'created_timestamp' => '0', 'copyright' => '', 'focal_length' => '0', 'iso' => '0', 'shutter_speed' => '0', 'title' => '', 'orientation' => '0', 'keywords' => array ( ), ), 'ShortPixel' => array ( 'date' => '2020-11-04 15:34:56', 'type' => 'lossy', 'thumbsOpt' => 11, 'thumbsOptList' => array ( 0 => 'NljdMbT3s30-400x263.jpg', 1 => 'NljdMbT3s30-1024x672.jpg', 2 => 'NljdMbT3s30-150x150.jpg', 3 => 'NljdMbT3s30-768x504.jpg', 4 => 'NljdMbT3s30-1536x1008.jpg', 5 => 'NljdMbT3s30-2048x1344.jpg', 6 => 'NljdMbT3s30-6x4.jpg', 7 => 'NljdMbT3s30-1000x288.jpg', 8 => 'NljdMbT3s30-457x300.jpg', 9 => 'NljdMbT3s30-1337x1337.jpg', ), 'excludeSizes' => array ( ), 'retinasOpt' => 0, 'Retries' => 2, 'exifKept' => '0', ), 'ShortPixelImprovement' => '70.67', );

  }

  public function testConvertBasic()
  {
      $legacyMethod = $this->getPrivateMethod($this->className, 'checkLegacy');
      $saveMethod = $this->getPrivateMethod($this->className, 'createSave');
      $loadThumbMethod = $this->getPrivateMethod($this->className, 'loadThumbnailsFromWP');
      $metaProp = $this->getPrivateProperty($this->className, 'wp_metadata');
      $thumbnailProp = $this->getPrivateProperty($this->className, 'thumbnails');
      $retinaProp = $this->getPrivateProperty($this->className, 'retinas');
      $webpMethod = $this->getPrivateMethod($this->className, 'getWebps');

      $post = $this->factory->post->create_and_get();
      $mm = new MediaLibraryModel($post->ID, self::$path . '9OE_n601RyA-scaled.jpg');

      $metaProp->setValue($mm, $this->getOldFormat()); // set the metadata
      $thumbnails = $loadThumbMethod->invoke($mm); // init the thumbs ( happens on load_meta normally)
      $thumbnailProp->setValue($mm, $thumbnails); // set them, as in loadMeta.

      // Run the CheckLegacy function
      $legacyMethod->invoke($mm);

      $metadata = $saveMethod->invoke($mm);

      $this->assertIsObject($metadata);
      $this->assertTrue($mm->getMeta('wasConverted'));
      $this->assertEquals(ImageModel::COMPRESSION_LOSSY, $mm->getMeta('compressionType'));
      $this->assertEquals(2, $mm->getMeta('status')); // optimize success
      $this->assertEquals(0, $mm->getMeta('did_keepExif'));
      $this->assertCount(9, $thumbnailProp->getValue($mm));
      $this->assertCount(6, $retinaProp->getValue($mm)); // with thumbOpt 5 since one size is duplicate sizes.
  //    $this->assertCount(6, $mm->getMeta('webp'));

      $this->assertEquals('9OE_n601RyA-scaled.webp', $mm->getMeta('webp'));
      $this->assertEquals($mm->getMeta('webp'), $mm->getWebp()->getFileName());
      $this->assertCount(10, $webpMethod->invoke($mm)); // All thumbs + main image.

      $this->assertTrue(get_post_meta($post->ID, 'shortpixel_was_converted', true));

      $this->assertEquals('87.63', $mm->getImprovement()); // This can be fishy
  }

  public function testConvertpng2jpg()
  {
      $legacyMethod = $this->getPrivateMethod($this->className, 'checkLegacy');
      $saveMethod = $this->getPrivateMethod($this->className, 'createSave');
      $loadThumbMethod = $this->getPrivateMethod($this->className, 'loadThumbnailsFromWP');
      $metaProp = $this->getPrivateProperty($this->className, 'wp_metadata');
      $thumbnailProp = $this->getPrivateProperty($this->className, 'thumbnails');

      $post = $this->factory->post->create_and_get();
      $mm = new MediaLibraryModel($post->ID, self::$path . '9OE_n601RyA-scaled.jpg');

      $metaProp->setValue($mm, $this->getPng2JpgFormat()); // set the metadata
      $thumbnails = $loadThumbMethod->invoke($mm); // init the thumbs ( happens on load_meta normally)
      $thumbnailProp->setValue($mm, $thumbnails); // set them, as in loadMeta.

      $legacyMethod->invoke($mm);

      $metadata = $saveMethod->invoke($mm);

      $this->assertTrue($mm->getMeta('did_png2jpg'));
    //  $this->assertEquals('png', $mm->getExtension());
      $this->assertEquals(2, $mm->getMeta('status'));

      // Also Kept Exit
      $this->assertTrue($mm->getMeta('did_keepExif'));
  }


  public function testUnlisted()
  {
      $this->markTestIncomplete('This test has not been implemented yet.');

  }



  public function testConvertBackup()
  {
    $this->markTestIncomplete('This test has not been implemented yet.');
  }

} // class
