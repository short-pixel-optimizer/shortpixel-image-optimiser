<?php
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use \ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use \ShortPixel\Model\Image\ImageModel as ImageModel;


class MediaLibraryModelTest extends  WP_UnitTestCase
{

  private static $fs;

  protected static $image;
  protected static $id;

  private $className = '\ShortPixel\Model\Image\MediaLibraryModel';


  public function setUp()
  {

    WPShortPixelSettings::resetOptions();
  //  $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
    // Need an function to empty uploads
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

  public static function wpSetUpBeforeClass($factory)
  {
    $upload_dir = wp_upload_dir();

    //$factory = self::factory();
    self::$fs = \wpSPIO()->filesystem();
    $post = $factory->post->create_and_get();
    $attachment_id = $factory->attachment->create_upload_object( __DIR__ . '/assets/image1.jpg', $post->ID ); // this one scales

    $imageObj = self::$fs->getMediaImage($attachment_id);
    self::$id = $attachment_id;
    self::$image = $imageObj; // for testing more specific functions.

    add_filter( 'upload_mimes', function ( $mime_types ) {
        $mime_types['json'] = 'application/json'; // Adding .json extension
        return $mime_types;
      });

      Log::getInstance()->setLogPath('/tmp/wordpress/shortpixel_log');

      //@todo same as in test conversion. This needs streamlining
      $upload_dir = wp_upload_dir('2020/11', true);

      $zip = new ZipArchive;
      $res = $zip->open( dirname(__FILE__) . '/assets/test-conversion.zip');

      if ($res === TRUE) {
        $zip->extractTo($upload_dir['path']);
      }
      $zip->close();

  }

  public static function wpTearDownAfterClass()
  {
    $path = (string) self::$image->getFileDir();
    // wipe the dir.
    foreach (new DirectoryIterator($path) as $fileInfo) {
    if(!$fileInfo->isDot()) {
        unlink($fileInfo->getPathname());
    }
      wp_delete_attachment(self::$id);

    }
    //self::$image->delete();
  }

  public function testRegularImage()
  {
    $imageObj = self::$image;

    $this->assertTrue($imageObj->exists());
    $this->assertTrue($imageObj->isProcessable());
    $this->assertTrue($imageObj->isScaled());


  }

/* Not needed atm, first example is already scaled / big.
  public function LargeImage()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/scaled.jpg', $post->ID );

    $imageObj = $this->fs->getMediaImage($attachment_id);

    $this->assertTrue($imageObj->exists());
    $this->assertTrue($imageObj->isProcessable());
    $this->assertTrue($imageObj->is_scaled());
    $this->assertFalse($imageObj->isOptimized());
  }
*/
  public function testPDF()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/pdf.pdf', $post->ID );

    $imageObj = self::$fs->getMediaImage($attachment_id);

    $this->assertTrue($imageObj->isProcessable());

    $imageObj->delete();

  }

  public function testNonImage()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/credits.json', $post->ID );

    $imageObj = self::$fs->getMediaImage($attachment_id);

    $this->assertFalse($imageObj->isProcessable());
    $this->assertFalse($imageObj->isOptimized());

    $imageObj->delete(); // remove after use

  }



  public function testExcludedSizes()
  {

    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/image-small-500x625.jpg', $post->ID );

    // testing via pattern
    $imageObj = self::$fs->getMediaImage($attachment_id);
    $settings = \wpSPIO()->settings();

    $refWPQ = new ReflectionClass('\ShortPixel\Model\Image\MediaLibraryModel');
    $sizeMethod = $refWPQ->getMethod('isSizeExcluded');
    $sizeMethod->setAccessible(true);

    $this->assertFalse($sizeMethod->invoke($imageObj));

    $pattern = array(0 => array('type' => 'size', 'value' => '500x625'));
    // Exact dimensions, exclude
    $settings->excludePatterns = $pattern;

    // Test if settings work
    $this->assertEquals($settings->excludePatterns, $pattern);
    // Check if file is correct size, when changing the file these tests will fail!
    $this->assertEquals(500, $imageObj->get('width'));
    $this->assertEquals(625, $imageObj->get('height'));
    $this->assertTrue($sizeMethod->invoke($imageObj));

    // Exact dimensions, allowed
    $pattern[0]['value'] = '1500x1500';
    $settings->excludePatterns = $pattern;

    $this->assertFalse($sizeMethod->invoke($imageObj));

    // MinMaX, exclude
    $pattern[0]['value'] = '500-1500X500-1500';
    $settings->excludePatterns = $pattern;

    $this->assertTrue($sizeMethod->invoke($imageObj));

    // MinMaX, allow
    $pattern[0]['value'] = '1000-5500x1000-5500';
    $settings->excludePatterns = $pattern;

    $this->assertFalse($sizeMethod->invoke($imageObj));


    // Testing via 'Exclude Thumbnail sizes' setting
    $urls_all = $imageObj->getOptimizeUrls();
    $url_fullcount = count($urls_all); // all

    $settings->excludePatterns = '';
    $settings->excludeSizes = array('thumbnail');

    $urls = $imageObj->getOptimizeUrls();
    $url_count = count($urls);

    $this->assertGreaterThan($url_count, $url_fullcount);

  }

  public function testExcludedPatterns()
  {
      $imageObj = self::$image;

      $settings = \wpSPIO()->settings();

      $refWPQ = new ReflectionClass('\ShortPixel\Model\Image\MediaLibraryModel');
      $pathMethod = $refWPQ->getMethod('isPathExcluded');
      $pathMethod->setAccessible(true);

      $pattern = array();
      $settings->excludePatterns = false;
      $settings->excludePatterns = array();

      $this->assertEquals(array(), $settings->excludePatterns);
      $this->assertFalse($pathMethod->invoke($imageObj));

      // Test Path Exclude
      $pattern = array(0 => array('type' => 'path', 'value' => 'uploads'));
      $settings->excludePatterns = $pattern;

      $this->assertEquals($pattern, $settings->excludePatterns);
      $this->assertTrue($pathMethod->invoke($imageObj), $imageObj->getFullPath());

      // Test Path Not Exclude
      $pattern = array(0 => array('type' => 'path', 'value' => 'nomatch'));
      $settings->excludePatterns = $pattern;
      $this->assertFalse($pathMethod->invoke($imageObj), $imageObj->getFullPath());

      // Test FileName exclude
      $pattern = array(0 => array('type' => 'name', 'value' => 'image'));
      $settings->excludePatterns = $pattern;
      $this->assertTrue($pathMethod->invoke($imageObj),  $imageObj->getFullPath());

      // Test Filename not exclude
      $pattern = array(0 => array('type' => 'name', 'value' => 'uploads'));
      $settings->excludePatterns = $pattern;
      $this->assertFalse($pathMethod->invoke($imageObj),  $imageObj->getFullPath());
  }

  public function testWebp()
  {
    // @todo
    $this->markTestIncomplete('This test has not been implemented yet.');

  }

  public function testRetina()
  {
     // @todo
     // Files are installed in other test. Also needs streamlining this.
     $upload_dir = wp_upload_dir();
     $path = trailingslashit($upload_dir['path']);
     $file = $path. '9OE_n601RyA-150x150.jpg';

     $mm = new MediaLibraryModel(0, $file);

     $retinaMethod = $this->getPrivateMethod($this->className, 'getRetinas' );

     $result = $retinaMethod->invoke($mm);

     $this->assertCount(1, $result);
     $retina = $result[0];
     $this->assertTrue($retina->exists());
     $this->assertEquals('9OE_n601RyA-150x150@2x.jpg', $retina->getFileName());

     // @todo Add thumbnails to object and test w/ thumbnail retina.


  }

  public function testMetaData()
  {
      $imageObj = self::$image;
      $id = self::$id;

      // Verify a few defaults.
      $this->assertEquals(0, $imageObj->getMeta('status'));
      $this->assertNull($imageObj->getMeta('compressionType'));
      $this->assertNull($imageObj->getMeta('did_png2Jpg'));
      //$this->assertFalse($imageObj->getMeta('did_png2Jpg'));

      $imageObj->setMeta('status', 1);
      $imageObj->setMeta('compressionType', 'lossy');

      $imageObj->saveMeta();

      // Reload
      $imageObj = self::$fs->getMediaImage(self::$id);

      $this->assertEquals(1, $imageObj->getMeta('status'));
      $this->assertEquals('lossy', $imageObj->getMeta('compressionType'));

      $meta = get_post_meta($id, '_shortpixel_meta', true);

      $this->assertIsObject($meta);
      $this->assertEquals($meta->image_meta->status, $imageObj->getMeta('status'));

      // Deleting
      $bool = $imageObj->deleteMeta();
      $this->assertTrue($bool);

      $meta = get_post_meta($id, '_shortpixel_meta', true);
      $this->assertEquals('', $meta);


  }

  public function testHandleOptimize()
  {
        $tempDir = get_temp_dir();
        $settings = \wpSPIO()->settings();
        $fs = \wpSPIO()->filesystem();

        $optfile = $tempDir . '/dfsklkldsklfs.tmp';
        copy(__DIR__ . '/assets/image1_optimized.jpg', $optfile); // simulate Tempfile
        $optfilesize = filesize($optfile);

        $image = self::$image;

        $tempFiles = array('image1-scaled.jpg' => $fs->getFile($optfile), 'image1.jpg' => $fs->getFile($optfile));

        $result = $image->handleOptimized($tempFiles);

        $this->assertTrue($result);

        $this->assertEquals(2, $image->getMeta('status'));
        $this->assertEquals($settings->compressionType, $image->getMeta('compressionType'));
        $this->assertEquals($optfilesize, filesize($image->getFullPath()));
        $this->assertFalse(file_exists($optfile));


  }

  public function testConvertPNG()
  {
      $post = $this->factory->post->create_and_get();
      $attachment_id = $factory->attachment->create_upload_object( __DIR__ . '/assets/png-test.png', $post->ID ); // this one scales

      $this->markTestIncomplete('This test has not been implemented yet.');
  }


} // class
