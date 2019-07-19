<?php
class QueriesTest extends WP_UnitTestCase
{
    public static function setUpBeforeClass()
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
    }

    public function testPostMetaSlice()
    {
      $args = array(
  'numberposts' => 10
    );
      $result = WpShortPixelMediaLbraryAdapter::getPostMetaJoinLess(1000, 1, 30);
      $this->assertCount(30, $result);

    }

}
