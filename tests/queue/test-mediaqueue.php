<?php


class MediaLibraryQueueTest extends  WP_UnitTestCase
{

  private static $q;
  private static $id;
  private static $image;

  public static function wpSetUpBeforeClass($factory)
  {
    $queue = MediaLibraryQueue::getInstance();
    self::$q = $queue;

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

  public function getQ()
  {
    return self::$q;
  }

  public function testInit()
  {
    $q = $this->getQ();

    $this->assertObject($q);

  }

  public function addSingleItem()
  {
      $result = $this->q->addSingleItem(self::$id);

      $this->assertEquals(1, $result);

      $items = $this->q->deQueue();

      $this->assertCount(1, $items);
      
  }

  //public function test


} // class
