<?php
use org\bovigo\vfs\vfsStream;

// Test for MediaLibraryAdapter and others.
class QueriesTest extends WP_UnitTestCase
{
    private $files_used;

    //public static function setUpBeforeClass()
    public function setupDB()
    {
      $mysqli = new mysqli("127.0.0.1", "shortpixel", "w76TZ#QUEJaf", "shortpixel_test");
      $sql = file_get_contents('tests/test_posts.sql');
      $result = $mysqli->multi_query($sql);

      $settings = new WPShortPixelSettings();
      $settings->backupImages = 0;
      $settings->autoMediaLibrary = false;
    }


    public function setUp()
    {
      $this->root = vfsStream::setup('root', null, $this->getTestFiles() );

    }

    private function getTestFiles()
    {
      $content = file_get_contents(__DIR__ . '/assets/test-image.jpg');
      $files = array(
          'images' => array(
            'mainfile.jpg' => $content,
            'mainfile-250x250.jpg' => $content,  //normal wp
            'mainfile-560x560.jpg' => $content,
            'mainfile-650x650-sufx.jpg' => $content,
            'mainfile-100x100-sufx.jpg' => $content,
            'mainfile-uai-750x500.jpg' => $content, //infix
            'mainfile-uai-500x500.jpg' => $content,
          ),

      );
      $this->files_used = $files;
      return $files;
    }

    public function testPostMetaSliceEmpty()
    {
      $result = WpShortPixelMediaLbraryAdapter::getPostMetaJoinLess(1000, 1, 30);
      $this->assertCount(0, $result);
    }

    public function testPostMetaSlice()
    {
      $this->setupDB();

      $args = array(
  'numberposts' => 10
    );
      $result = WpShortPixelMediaLbraryAdapter::getPostMetaJoinLess(1000, 1, 30);
      $this->assertCount(30, $result);

    }

    private function getExpected()
    {
      $rooturl = $this->root->url();
      $expected1 = array();
      $expected2 = array();
      $expected3 = array();

      $files_used = $this->files_used['images'];
      unset($files_used['mainfile.jpg']);

      $i = 0;
      foreach($files_used as $filename => $data)
      {
        $path = $rooturl . '/images/' . $filename;

        if ($i <= 1)
        {
            $expected1[] = $path;
            $expected2[] = $path;
            $expected3[] = $path;
        }
        elseif ($i >= 2 && $i <= 3)
        {
          $expected2[] = $path;
          $expected3[] = $path;
        }
        else {
          $expected3[] = $path;
        }

        $i++;
      }
      return array($expected1, $expected2, $expected3);
    }

    /**
    * @runInSeparateProcesses
    */
    public function testFindThumbs()
    {
        $rooturl = $this->root->url();
        $mainfile = $rooturl . '/images/mainfile.jpg';

        list ($expected1,$expected2,$expected3) = $this->getExpected();
        $this->assertTrue(file_exists($mainfile));

        $thumbs1 = WpShortPixelMediaLbraryAdapter::findThumbs($mainfile);

        $this->assertCount(2, $thumbs1);
        $this->assertEquals($expected1, $thumbs1);

        define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', '-sufx');

        $this->assertTrue(defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES'));

        $thumbs2 = WpShortPixelMediaLbraryAdapter::findThumbs($mainfile);

        $this->assertCount(4, $thumbs2);
        $this->assertEquals($expected2, $thumbs2);

        define('SHORTPIXEL_CUSTOM_THUMB_INFIXES', '-uai');

        $this->assertTrue(defined('SHORTPIXEL_CUSTOM_THUMB_INFIXES'));

        $thumbs3 = WpShortPixelMediaLbraryAdapter::findThumbs($mainfile);

        $this->assertCount(6, $thumbs3);
        $this->assertEquals($expected3, $thumbs3);


    //    $this->assertEquals()
    }

    /*
   Can't test this so far because of Constants already defined, sep. process doesn't work since kills the whole WP install
    public function testEmptyConstants()
    {
       $mainfile = 'nonexisting.jpg';

       $thumbs1 = WpShortPixelMediaLbraryAdapter::findThumbs($mainfile);

       $this->assertCount(0, $thumbs1);

       define('SHORTPIXEL_CUSTOM_THUMB_INFIXES', '');
       define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', '');

       $thumbs2 = WpShortPixelMediaLbraryAdapter::findThumbs($mainfile);

       $this->assertCount(0, $thumbs2);


    } */

}
