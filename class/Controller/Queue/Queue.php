<?php
namespace ShortPixel\Controller\Queue;

use ShortPixel\Model\Image\ImageModel as ImageModel;

abstract class Queue
{
    protected $q;
    protected static $instance;
    protected static $results;

    const PLUGIN_SLUG = 'SPIO';

    // Result status for Run function
    const RESULT_ITEMS = 1;
    const RESULT_PREPARING = 2;
    const RESULT_EMPTY = 3;
    const RESULT_ERROR = -1;
    const RESULT_UNKNOWN = -10;

    /* Result status (per item) to communicate back to frontend */
/*    const FILE_NOTEXISTS = -1;
    const FILE_ALREADYOPTIMIZED = -2;
    const FILE_OK = 1;
    const FILE_SUCCESS = 2;
    const FILE_WAIT = 3; */

    abstract protected function createNewBulk($args);
    abstract protected function prepare();

    public static function getInstance()
    {
       if (is_null(self::$instance))
       {
          $class = get_called_class();
          self::$instance = new $class();
       }

       return self::$instance;
    }

    /** Enqueues a single items into the urgent queue list
    *   - Should not be used for bulk images
    * @param ImageModel $mediaItem An ImageModel (CustomImageModel or MediaLibraryModel) object
    * @return mixed
    */
    public function addSingleItem(ImageModel $mediaItem)
    {
       //if (! $mediaItem->isProcessable())
      //  return false;
       $preparing = $this->getStatus('preparing');

       $qItem = $this->mediaItemToQueue($mediaItem);
       $item = array('id' => $mediaItem->get('id'), 'qItem' => $qItem);
       $numitems = $this->q->withOrder(array($item), 5)->enqueue(); // enqueue returns numitems

       $this->q->setStatus('preparing', $preparing); // add single should not influence preparing status.
       return $numitems;
    }


    public function run()
    {

       $result = new \stdClass();
       $result->status = self::RESULT_UNKNOWN;
       $result->items = null;

       if ( $this->getStatus('preparing'))
       {
            $prepared = $this->prepare();
            $result->status = self::STATUS_PREPARING;
            $result->items = $prepared; // number of items.
       }
       elseif ($this->getStatus('bulk_running'))
       {
            $items = $this->deQueue();
       }
       else
       {
            $items = $this->deQueuePriority();
       }

       if (isset($items)) // did a dequeue.
       {
         if (count($items) == 0)
         {
           $result->status = self::RESULT_EMPTY;
         }
         else
         {
           $result->status = self::RESULT_ITEMS;
         }
          $result->items = $items;

       }

       return $result;
    }

    protected function getStatus($name = false)
    {
        return $this->q->getStatus($name);
    }

    protected function deQueue()
    {
       $items = $this->q->deQueue();
       return $items;
    }

    protected function deQueuePriority()
    {
      $items = $this->q->deQueue(array('onlypriority' => true));
      return $items;
    }

    // This might be a general implementation
    protected function mediaItemToQueue($mediaItem)
    {

        $item = new \stdClass;
        $item->compressionType = false;

        $urls = $mediaItem->getOptimizeUrls();

        if ($mediaItem->getMeta('compressionType'))
          $item->compressionType = $mediaItem->getMeta('compressionType');

        $item->urls = apply_filters('shortpixel_image_urls', $urls, $mediaItem->get('id'));

        return $item;
    }

    public function getShortQ()
    {
        return $this->q;
    }


} // class
