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
  //  $refWPQ = new ReflectionClass('\ShortPixel\ShortPixelPng2Jpg');
  //  $convertMethod = $refWPQ->getMethod('convert');
  //  $convertMethod->setAccessible(true);
    $fs = \wpSPIO()->filesystem();

    $settings = \wpSPIO()->settings();
    $settings->png2jpg = 0; // off

    $this->assertFalse($png2jpg->convert(self::$image));

    $settings->png2jpg = 1;
    $settings->backupImages = 0; // test one without backup.

    // First one should fail.  (transparency)
    $result = $png2jpg->convert(self::$image_trans);
    //$this->assertIsArray($result);
    $this->assertFalse($result);
  //  $file = $result['file'];
  /*  $this->assertIsObject($file);
    $this->assertEquals('jpg', $file->getExtension());
    $this->assertEquals('png-transparant.png', $file->getFileName()); */

    // Second one should work.  Without Backup.
    $result = $png2jpg->convert(self::$image);

    $this->assertIsObject($result);
    //$this->assertTrue($result['success']);
    //$this->assertIsObject($result['file']);

    //$file = $result['file'];

    $this->assertEquals('jpg', $result->getExtension());
    $this->assertEquals('png-test.jpg', $result->getFileName());
    $this->assertEquals('png-test', $result->getFileBase());

    $this->assertEquals($result->getFullPath(), get_attached_file(self::$id));
    $post = get_post(self::$id);
    $this->assertEquals('image/jpeg', $post->post_mime_type);

    // @todo Test thumbnails, see if generated
    $imageObj = $fs->getMediaItem(self::$id);


    // Third test. With Force transparency, with backup.
    $settings->png2jpg = 2;

    $result = $png2jpg->convert(self::$image_trans);

    $this->assertIsObject($result);
    //$this->assertTrue($result['success']);
    //$this->assertIsObject($result['file']);

    //$file = $result['file'];
    $this->assertEquals('jpg', $result->getExtension());
    $this->assertEquals('png-transparant.jpg', $result->getFileName());
    $this->assertEquals('png-transparant', $result->getFileBase());

    $this->assertEquals($result->getFullPath(), get_attached_file(self::$id_trans));
    $post = get_post(self::$id_trans);
    $this->assertEquals('image/jpeg', $post->post_mime_type);

    // @todo Check the backups
    $this->assertTrue($result->hasBackup() );
    

  }





}  // class
