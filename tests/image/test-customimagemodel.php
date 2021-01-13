<?php
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use \ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use \ShortPixel\Model\Image\ImageModel as ImageModel;


class CustomImageModelTest extends  WP_UnitTestCase
{

  static $fs;

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

    add_filter( 'upload_mimes', function ( $mime_types ) {
        $mime_types['json'] = 'application/json'; // Adding .json extension
        return $mime_types;
      });

      Log::getInstance()->setLogPath('/tmp/wordpress/shortpixel_log');

      //@todo same as in test conversion. This needs streamlining
      $upload_dir = wp_upload_dir('2020/11', true);

      $source = self::$fs->getFile('assets/image1.jpg');
      $target = self::$fs->getFile($upload_dir['path'] . '/');
      $source->copy($target);

/*      $zip = new ZipArchive;
      $res = $zip->open( dirname(__FILE__) . '/assets/test-conversion.zip');
      //var_dump(dirname(__FILE__) . '/assets/test-conversion.zip');
      //var_dump($upload_dir);
      if ($res === TRUE) {
        $zip->extractTo($upload_dir['path']);
      }
      $zip->close(); */

  }

  public function testSaveLoadMeta()
  {
       global $wpdb;

       $table = $wpdb->prefix . 'shortpixel_meta';

       $metaObj = $this->image_meta;

       $data = array(
            'compressed_size' => 500,
            'compressed_type' => 1,
            'keep_exif' =>  1,
            'cmyk2rgb' =>  0,
            'resize' =>  1,
            'resize_width' => 1024,
            'resize_height' => 700,
            'backup' => 1,
            'status' => 2, // FILE_STATUS_SUCCESS,
            'message' => 1.25,
            'tsOptimized' => 100,
       );

       $format = array(
          '%d', '%d', '%d','%d','%d','%d','%d','%d','%d','%s', '%d',
       );

       $res = $wpdb->insert($table, $data, $format);
       $id =  $wpdb->insert_id;

       $customObj = self::$fs->getImage($id, 'custom');

       $this->assertIsObject($customObj);

       $this->assertEquals('image1.jpg', $customObj->getFileName());
       $this->assertTrue($customObj->exists());

       $this->assertEquals(2, $customObj->getMeta('status'));

       $this->assertTrue($customObj->isOptimized());
       $this->assertFalse($customObj->hasBackup());
       $this->assertTrue($customObj->getMeta('did_keepExif'));
       $this->assertFals($customObj->getMeta('did_cmyk2rgb'));

  }

  // @todo Custom Model for adding files


}
