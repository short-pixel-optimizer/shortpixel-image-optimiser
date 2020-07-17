<?php
  use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
  use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
  use ShortPixel\Controller\Queue\Queue as Queue;

class MediaLibraryQueueTest extends  WP_UnitTestCase
{

  private static $q;
  private static $id;
  private static $image;
  private static $fs;


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

    $this->assertIsObject($q);
  }

  public function testEmptyQueue()
  {
      $q = $this->getQ();
      $result = $q->run();

      $this->assertEquals(Queue::RESULT_EMPTY, $result->status);
  }

  public function testAddSingleItem()
  {
      $refWPQ = new ReflectionClass('\ShortPixel\Controller\Queue\Queue');
      $getStatusMethod = $refWPQ->getMethod('getStatus');
      $getStatusMethod->setAccessible(true);

      $q = $this->getQ();

      // Test the start premise.
      $this->assertFalse($getStatusMethod->invoke($q, 'preparing'));
      $this->assertFalse($getStatusMethod->invoke($q, 'running'));

      $result = $q->addSingleItem(self::$image);

      $this->assertEquals(1, $result);
      $this->assertFalse($getStatusMethod->invoke($q, 'preparing'));
      $this->assertFalse($getStatusMethod->invoke($q, 'running'));

      $result = $q->run();
      $items = $result->items;
      $item = $items[0];

      $this->assertFalse($getStatusMethod->invoke($q, 'preparing'));
      $this->assertFalse($getStatusMethod->invoke($q, 'running'));
      $this->assertEquals(Queue::RESULT_ITEMS, $result->status);
      $this->assertCount(1, $items);


  }

  //public function test


} // class
