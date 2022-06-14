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
    $queue = new MediaLibraryQueue();
    self::$q = $queue;

    //$factory = self::factory();
    self::$fs = \wpSPIO()->filesystem();
    $post = $factory->post->create_and_get();
    $attachment_id = $factory->attachment->create_upload_object( __DIR__ . '/assets/image1.jpg', $post->ID ); // this one scales

    $imageObj = self::$fs->getImage($attachment_id, 'media');
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

      $this->assertEquals(Queue::RESULT_QUEUE_EMPTY, $result->qstatus);
  }



  //public function test


} // class
