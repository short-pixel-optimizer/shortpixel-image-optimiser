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

     $this->q->setOption('numitems', 1);
     $this->q->setOption('mode', 'wait');
     $this->q->setOption('process_timeout', 7000);
     $this->q->setOption('retry_limit', 20);
     

   }

   public function addSingleItem($id)
   {
      $mediaItem = $fs->getMediaItem($id);
      $qItem = $this->mediaItemToQueue($mediaItem);

      $item = array('id' => $id, 'qItem' => $qItem);

      return $this->q->withOrder(array($item), 5)->enqueue();
   }

  /* public function queueToMediaItem($queueItem)
   {
      $id = $queueItem->id;
      return $fs->getMediaImage($id);
   } */

}
