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
        $json = $control->addItemToQueue($attachment_id, 'media');

        $this->assertIsObject($json);
        $this->assertEquals(1, $json->items);
        $this->assertEquals(1, $json->status); // status ok
        $this->assertFalse($json->has_error);

        // Same, result should be 0 addded.
        $json = $control->addItemToQueue($attachment_id, 'media');

        $this->assertIsObject($json);
        $this->assertEquals(0, $json->items);
        $this->assertEquals(0, $json->status); // status ok
        $this->assertFalse($json->has_error);


    }

    // @todo
    public function testRestoreItem()
    {

    }

    // @todo
    public function testProcessQueue()
    {

    }

    public function testConvertPNG()
    {

    }



}
