<?php
/*  use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
  use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;
  use ShortPixel\Controller\Queue\Queue as Queue;
*/
use ShortPixel\Controller\OptimizeController as OptimizeController;

class OptimizeControllerTest extends  WP_UnitTestCase
{

    public function testAddItemToQueue()
    {
        $attachment_id = $factory->attachment->create_object( __DIR__ . '/assets/image1.jpg', $post->ID ); // this one scales

        $control = OptimizeController::getInstance();

        // Test regular enqueue.
        $mediaItem = $fs->getMediaItem($attachment_id, 'media');
        $json = $control->addItemToQueue($mediaItem);

        $this->assertIsObject($json);
        $this->assertEquals(1, $json->items);
        $this->assertEquals(1, $json->status); // status ok
        $this->assertFalse($json->has_error);

        // Same, result should be 0 addded.
        $json = $control->addItemToQueue($mediaItem);

        $this->assertIsObject($json);
        $this->assertEquals(0, $json->items);
        $this->assertEquals(0, $json->status); // status ok
        $this->assertFalse($json->has_error);

    }

    // @todo
    public function testRestoreItem()
    {
      $this->markTestIncomplete('This test has not been implemented yet.');

    }

    // @todo
    public function testProcessQueue()
    {
      $this->markTestIncomplete('This test has not been implemented yet.');

    }

    public function testConvertPNG()
    {
      $this->markTestIncomplete('This test has not been implemented yet.');

    }


    public function testCalculateStatsTotals()
    {
      $control = new OptimizeController();

      $refWPQ = new ReflectionClass('\ShortPixel\Controller\OptimizeController');
      $statsMethod = $refWPQ->getMethod('CalculateStatsTotals');
      $statsMethod->setAccessible(true);

      $media = (object) [
          'stats' => (object) [
              'bulk' => (object) [
                  'images' => 0,
                  'items' => 0,
                  'optimizedCount' => 0,
                  'optimizedThumbnailCount' => 0,
              ],
              'done' => 0,
              'errors' => 0,
              'in_process' => 0,
              'in_queue' => 0,
              'is_finished' => false,
              'is_preparing' => false,
              'is_running' => false,
              'percentage_done' => 0,
              'total' => 0,
          ]
      ];
      $custom = (object) [
          'stats' => (object) [
              'bulk' => (object) [
                  'images' => 0,
                  'items' => 0,
                  'optimizedCount' => 0,
                  'optimizedThumbnailCount' => 0,
              ],
              'done' => 0,
              'errors' => 0,
              'in_process' => 0,
              'in_queue' => 0,
              'is_finished' => false,
              'is_preparing' => false,
              'is_running' => false,
              'percentage_done' => 0,
              'total' => 0,
          ]
      ];
  //    $custom = clone $media; // No clone since it overwrites the referenced values :/

      $media->stats->in_queue = 10;
      $media->stats->done = 10;
      $media->stats->total = 20;
      $media->stats->percentage_done = 25;
      $media->stats->bulk->images = 100;
      $media->stats->bulk->items = 10;
      $media->stats->in_process = true;

      $result = new \stdClass;
      $result->media = $media;

      $total = $statsMethod->invoke($control, $result);

      $this->assertIsInt($total->stats->total);
      $this->assertIsInt($total->stats->bulk->items);
      $this->assertIsInt($total->stats->in_queue);

      $this->assertEquals(20, $total->stats->total);
      $this->assertEquals(10, $total->stats->bulk->items);
      $this->assertEquals(10, $total->stats->in_queue);
      $this->assertEquals(25, $total->stats->percentage_done);
      $this->assertTrue($total->stats->in_process);
      $this->assertFalse($total->stats->is_preparing);

 // not equals to prevent mistakes there
      $custom->stats->in_queue = 20;
      $custom->stats->done = 50;
      $custom->stats->total = 70;
      $custom->stats->bulk->images = 50;
      $custom->stats->bulk->items = 5;
      $custom->stats->in_process = true;
      $custom->stats->percentage_done = 15;

      $custom->stats->is_preparing = true;  // true prevails over false.

      $result->custom = $custom;

      $total = $statsMethod->invoke($control, $result);

      $this->assertIsInt($total->stats->total);
      $this->assertIsInt($total->stats->bulk->items);
      $this->assertIsInt($total->stats->in_queue);


      $this->assertEquals(90, $total->stats->total);
      $this->assertEquals(15, $total->stats->bulk->items);
      $this->assertEquals(30, $total->stats->in_queue);
      $this->assertEquals(40, $total->stats->percentage_done);
      $this->assertTrue($total->stats->in_process);
      $this->assertTrue($total->stats->is_preparing);


    }


}
