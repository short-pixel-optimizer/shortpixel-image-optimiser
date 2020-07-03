<?php

class  MediaLibraryModelTest extends  WP_UnitTestCase
{

  private static $fs;

  protected static $image;
  protected static $id;

  public function setUp()
  {

    WPShortPixelSettings::debugResetOptions();
  //  $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
    // Need an function to empty uploads
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
  }

  public static function wpTearDownAfterClass()
  {
    wp_delete_attachment(self::$id); 
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
  }

  public function testExcludedPatterns()
  {
      $imageObj = $this->image;


  }

  //public function test


} // class
