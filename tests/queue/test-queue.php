<?php

//  use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
  use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
  use ShortPixel\Controller\Queue\Queue as Queue;

class QueueTest extends WP_UnitTestCase
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
  }

  public function getQ()
  {
    return self::$q;
  }

  // @todo If this remains in Queue mainclass, move it.
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

  public function testImageModelToQueue()
  {
      $q = $this->getQ();
      $refWPQ = new ReflectionClass('\ShortPixel\Controller\Queue\Queue');
      $getMethod = $refWPQ->getMethod('imageModelToQueue');
      $getMethod->setAccessible(true);

      $qItem = $getMethod->invoke($q, self::$image);
      //$qItem = $q->imageModelToQueue(self::$image);

      $this->assertObjectHasAttribute('compressionType', $qItem);
      $this->assertObjectHasAttribute('urls', $qItem);
      $this->assertIsArray($qItem->urls);

  }

  public function testQueueToMediaItem()
  {
      $q = $this->getQ();
      $refWPQ = new ReflectionClass('\ShortPixel\Controller\Queue\Queue');

      $q->addSingleItem(self::$image);

      $result = $q->run();
      $items = $result->items;

      $this->assertCount(1, $items);
      $mediaItem = $items[0];
      $testMediaItem = clone $mediaItem;  // pass by reference in the reflected methods change the $mediaItem var here too.

      $this->assertObjectHasAttribute('compressionType', $mediaItem);
      $this->assertNull($mediaItem->compressionType);
      $this->assertObjectHasAttribute('urls', $mediaItem);
      $this->assertObjectHasAttribute('item_id', $mediaItem);
      $this->assertObjectHasAttribute('tries', $mediaItem);
      $this->assertObjectHasAttribute('_queueItem', $mediaItem);

      $this->assertCount(8, $mediaItem->urls); // not scientific, amount of thumbnails dependent.
      $this->assertEquals(0, $mediaItem->tries);


      $methodQToMedia = $refWPQ->getMethod('queueToMediaItem');
      $methodQToMedia->setAccessible(true);

      $methodMediaToQ = $refWPQ->getMethod('mediaItemToQueue');
      $methodMediaToQ->setAccessible(true);

//echo "MediaItem2 ";  var_dump($mediaItem);
      $qItem = $methodMediaToQ->invoke($q, $mediaItem);
//echo "MediaItem3 ";  var_dump($mediaItem);
      $mItem = $methodQToMedia->invoke($q, $qItem);

 //echo "mItem "; var_dump($mItem);
      $this->assertObjectNotHasAttribute('_queueItem', $qItem);
      $this->assertEquals($testMediaItem, $mItem);
      //$this->assertObjectNotHasAttribute('', $qItem);


  }

  /*public function testMediaItemToQueue()
  {

  } */

  public function testUpdateQueueItemViaItemFailed()
  {
    $q = $this->getQ();

    $q->addSingleItem(self::$image);

    $result = $q->run();
    $items = $result->items;

    $this->assertCount(1, $items);
    $mediaItem = $items[0];

    $mediaItem->errors = 'test';

    $q->itemFailed($mediaItem, false);

    $result = $q->run();
    $items = $result->items;

    $this->assertCount(1, $items);

    $mediaItem2 = $items[0];

    $this->assertObjectHasAttribute('errors', $mediaItem2);
    $this->assertEquals('test', $mediaItem2->errors);
    $this->assertEquals(1, $mediaItem2->tries);
  }

}
