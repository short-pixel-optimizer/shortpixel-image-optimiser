<?php
namespace ShortPixel\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;

class CustomQueue extends Queue
{

   const QUEUE_NAME = 'Media';

   public function __construct()
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue(self::QUEUE_NAME);

     $this->q->setOption('numitems', 5);
     $this->q->setOption('mode', 'wait');
     $this->q->setOption('process_timeout', 7000);
     $this->q->setOption('retry_limit', 20);
   }





}
