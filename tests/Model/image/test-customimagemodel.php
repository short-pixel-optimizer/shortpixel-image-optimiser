<?php
use ShortPixel\Controller\ResponseController as ResponseController;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use \ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use \ShortPixel\Model\Image\ImageModel as ImageModel;


class CustomImageModelTest extends  WP_UnitTestCase
{

  static $fs;
  static $imagePath;

  public function setUp() :void
  {

    WPShortPixelSettings::resetOptions();
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

      $source = self::$fs->getFile( dirname(__FILE__)  . '/assets/image1.jpg');
      $target = self::$fs->getFile($upload_dir['path'] . '/image1.jpg');
      $source->copy($target);

      self::$imagePath = $target->getFullPath();

      global $wpdb;
      $table = $wpdb->prefix . 'shortpixel_meta';
      $wpdb->query('DELETE FROM ' . $table);
/*      $zip = new ZipArchive;
      $res = $zip->open( dirname(__FILE__) . '/assets/test-conversion.zip');

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

       $now = time();
       $added = time() - 3600;
       $tsAdded = date("Y-m-d H:i:s", $added);
       $tsOptimized = date("Y-m-d H:i:s", $now);

       $data = array(
            'compressed_size' => 500,
            'compression_type' => 1,
            'path' => self::$imagePath,
            'path_md5' => md5(self::$imagePath),
            'keep_exif' =>  1,
            'cmyk2rgb' =>  0,
            'resize' =>  1,
            'resize_width' => 1024,
            'resize_height' => 700,
            'backup' => 1,
            'status' => ImageModel::FILE_STATUS_SUCCESS, // FILE_STATUS_SUCCESS,
            'message' => 1.25,
            'ts_added' => $tsAdded,
            'ts_optimized' => $tsOptimized,
       );

       $format = array(
          '%d', '%d', '%s', '%s', '%d','%d','%d','%d','%d','%d','%s','%s', '%s', '%s',
       );

       $res = $wpdb->insert($table, $data, $format);
       $id =  $wpdb->insert_id;

       $this->assertGreaterThan(0, $id);

       $customObj = self::$fs->getImage($id, 'custom');

       $this->assertIsObject($customObj);

       $this->assertEquals('image1.jpg', $customObj->getFileName());
       $this->assertTrue($customObj->exists());

       $this->assertEquals(ImageModel::FILE_STATUS_SUCCESS, $customObj->getMeta('status'));

       $this->assertTrue($customObj->isOptimized());
       $this->assertFalse($customObj->hasBackup());
       $this->assertTrue($customObj->getMeta('did_keepExif'));
       $this->assertFalse($customObj->getMeta('did_cmyk2rgb'));
       $this->assertEquals(1.25, $customObj->getMeta('customImprovement'));
       $this->assertNull($customObj->getMeta('error_message'));
       $this->assertEquals($added, $customObj->getMeta('tsAdded'));
       $this->assertEquals($now, $customObj->getMeta('tsOptimized'));


       $customObj->setMeta('status', ImageModel::FILE_STATUS_UNPROCESSED );
       $customObj->setMeta('errorMessage', 'StringIsError');
       $customObj->setMeta('customImprovement', null);

       $customObj->saveMeta();

       $this->assertEquals(ImageModel::FILE_STATUS_UNPROCESSED, $customObj->getMeta('status'));
       $this->assertEquals('StringIsError', $customObj->getMeta('errorMessage'));
       $this->assertNull($customObj->getMeta('customImprovement'));
       $this->assertGreaterThan(0, $customObj->getMeta('tsOptimized'));

       $newObj = self::$fs->getImage($id, 'custom');

       $this->assertEquals(ImageModel::FILE_STATUS_UNPROCESSED, $newObj->getMeta('status'));
       $this->assertEquals('StringIsError', $newObj->getMeta('errorMessage'));
       $this->assertNull($newObj->getMeta('customImprovement'));
       $this->assertEquals($added, $customObj->getMeta('tsAdded'));
       $this->assertEquals($now, $customObj->getMeta('tsOptimized'));

  }

  // @todo Custom Model for adding files




}
