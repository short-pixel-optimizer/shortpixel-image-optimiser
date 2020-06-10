<?php
namespace ShortPixel\Queue;

use ShortPixel\ShortQ\ShortQ as ShortQ;

class MediaLibraryQueue extends Queue
{

   const QUEUE_NAME = 'Media';

   public function __construct()
   {
     $shortQ = new ShortQ(self::PLUGIN_SLUG);
     $this->q = $shortQ->getQueue(self::QUEUE_NAME);
   }
   


}
