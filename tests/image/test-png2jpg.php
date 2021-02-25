<?php
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

class  PNG2JPGTest extends  WP_UnitTestCase
{

  private static $fs;

  protected static $image;
  protected static $id;

  protected static $image_trans;
  protected static $id_trans;

  public function setUp()
  {

    WPShortPixelSettings::debugResetOptions();
  //  $this->root = vfsStream::setup('root', null, $this->getTestFiles() );
    // Need an function to empty uploads
  }

  public static function wpSetUpBeforeClass($factory)
  {
  //  $upload_dir = wp_upload_dir();
    Log::getInstance()->setLogPath('/tmp/wordpress/shortpixel_log');

    //$factory = self::factory();
    self::$fs = \wpSPIO()->filesystem();
    $post = $factory->post->create_and_get();
    $attachment_id = $factory->attachment->create_upload_object( __DIR__ . '/assets/png-test.png', $post->ID ); // this one scales

    $imageObj = self::$fs->getMediaImage($attachment_id);
    self::$id = $attachment_id;
    self::$image = $imageObj; // for testing more specific functions.

    $post = $factory->post->create_and_get();
    $attachment_id = $factory->attachment->create_upload_object( __DIR__ . '/assets/png-transparant.png', $post->ID ); // this one scales

    $imageObj = self::$fs->getMediaImage($attachment_id);
    self::$id_trans = $attachment_id;
    self::$image_trans = $imageObj; // for testing more specific functions.


  }

  public static function wpTearDownAfterClass()
  {
    // delete png
    $path = (string) self::$image->getFileDir();
    // wipe the dir.
    foreach (new DirectoryIterator($path) as $fileInfo) {
    if(!$fileInfo->isDot()) {
        unlink($fileInfo->getPathname());
    }
      wp_delete_attachment(self::$id);
    }

    // delete transparent
    $path = (string) self::$image_trans->getFileDir();
    // wipe the dir.
    foreach (new DirectoryIterator($path) as $fileInfo) {
    if(!$fileInfo->isDot()) {
        unlink($fileInfo->getPathname());
    }
      wp_delete_attachment(self::$id_trans);
    }
    //self::$image->delete();
  }

  public function testgetPNGImage()
  {
      $png2jpg = new \ShortPixel\ShortPixelPng2Jpg();
      $refWPQ = new ReflectionClass('\ShortPixel\ShortPixelPng2Jpg');
      $getMethod = $refWPQ->getMethod('getPNGImage');
      $getMethod->setAccessible(true);

      $this->assertIsResource($getMethod->invoke($png2jpg, self::$image));
      $this->assertIsResource($getMethod->invoke($png2jpg, self::$image_trans));

  }


  public function testPNGTransparent()
  {

    $png2jpg = new \ShortPixel\ShortPixelPng2Jpg();
    $refWPQ = new ReflectionClass('\ShortPixel\ShortPixelPng2Jpg');
    $transMethod = $refWPQ->getMethod('isTransParent');
    $transMethod->setAccessible(true);

    $this->assertFalse( $transMethod->invoke($png2jpg, self::$image));
    $this->assertTrue ( $transMethod->invoke($png2jpg, self::$image_trans));

  }

  public function testConversion()
  {
    $png2jpg = new \ShortPixel\ShortPixelPng2Jpg();

    $fs = \wpSPIO()->filesystem();

    $settings = \wpSPIO()->settings();
    $settings->png2jpg = 0; // off

    $this->assertFalse($png2jpg->convert(self::$image));

    $settings->png2jpg = 1;
    $settings->backupImages = 0; // test one without backup.

    // First one should fail.  (transparency)
    $result = $png2jpg->convert(self::$image_trans);
    $this->assertFalse($result);

    // Second one should work.  Without Backup.
    $result = $png2jpg->convert(self::$image);

    $this->assertTrue($result);
    //$this->assertTrue($result['success']);
    //$this->assertIsObject($result['file']);

    //$file = $result['file'];
    $image = self::$fs->getMediaImage(self::$id);

    $this->assertEquals('jpg', $image->getExtension());
    $this->assertEquals('png-test.jpg', $image->getFileName());
    $this->assertEquals('png-test', $image->getFileBase());

    $this->assertEquals($image->getFullPath(), get_attached_file(self::$id));
    $post = get_post(self::$id);
    $this->assertEquals('image/jpeg', $post->post_mime_type);

    // @todo Test thumbnails, see if generated
    $imageObj = $fs->getMediaImage(self::$id);


    // Third test. With Force transparency, with backup.
    $settings->png2jpg = 2;
    $settings->backupImages = 1; // test one with backup.

    $result = $png2jpg->convert(self::$image_trans);

    $this->assertTrue($result);

    $image = self::$fs->getMediaImage(self::$id_trans);

    $this->assertEquals('jpg', $image->getExtension());
    $this->assertEquals('png-transparant.jpg', $image->getFileName());
    $this->assertEquals('png-transparant', $image->getFileBase());

    $this->assertEquals($image->getFullPath(), get_attached_file(self::$id_trans));
    $post = get_post(self::$id_trans);
    $this->assertEquals('image/jpeg', $post->post_mime_type);

  }

  public function testMediaLibraryConversion()
  {
    $post = $this->factory->post->create_and_get();
    $attachment_id = $this->factory->attachment->create_upload_object( __DIR__ . '/assets/desk.png', $post->ID );

    $settings = \wpSPIO()->settings();
    $settings->png2jpg = 1;
    $settings->backupImages = 1;

    $mediaObj = \wpSPIO()->filesystem()->getImage($attachment_id, 'media');
    $oldfullpath = $mediaObj->getFullPath();

    $this->assertIsObject($mediaObj);
    $this->assertEquals('png', $mediaObj->getExtension() );

    $this->assertTrue($mediaObj->isProcessable());  // This set png2jpg is setting active.
    $this->assertTrue($mediaObj->get('do_png2jpg'));

    $bool = $mediaObj->convertPNG();
    $this->assertTrue($bool);

    $this->assertFileNotExists($oldfullpath);

    // basically the old object stops existing.
    //$mediaObj = \wpSPIO()->filesystem()->getImage($attachment_id, 'media');


    $this->assertEquals('jpg', $mediaObj->getExtension());
    $this->assertTrue($mediaObj->exists());
    $this->assertTrue($mediaObj->getMeta('did_png2jpg'));
    $this->assertFileNotExists($mediaObj->getFileDir() . $mediaObj->getFileBase() . '.png');

    $thumbnails = $mediaObj->get('thumbnails');

    foreach($thumbnails as $thumbObj)
    {
       $this->assertEquals('jpg', $thumbObj->getExtension());
       $this->assertTrue($thumbObj->exists());
       $this->assertTrue($thumbObj->getMeta('did_png2jpg'));
       $this->assertFileNotExists($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.png');
    }

    // Retry on already converted.
    $bool = $mediaObj->convertPNG();
    $this->assertFalse($bool);

    // Restore backup, check if it's jpg.
    $mediaObj->restore();

    $this->assertEquals('png', $mediaObj->getExtension());
    $this->assertTrue($mediaObj->exists());
    $this->assertFalse($mediaObj->getMeta('did_png2jpg'));
    $this->assertFileNotExists($mediaObj->getFileDir() . $mediaObj->getFileBase() . '.jpg');


    foreach($thumbnails as $thumbObj)
    {
       $this->assertEquals('png', $thumbObj->getExtension());
       $this->assertTrue($thumbObj->exists());
       $this->assertFalse($thumbObj->getMeta('did_png2jpg'));
       $this->assertFileNotExists($thumbObj->getFileDir() . $thumbObj->getFileBase() . '.jpg');
    }


  }


}  // class
