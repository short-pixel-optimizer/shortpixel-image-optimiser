<?php
namespace ShortPixel\Controller\Queue;

abstract class Queue
{
    protected $q;
    protected static $instance;
    const PLUGIN_SLUG = 'SPIO';

    const RESULT_ITEMS = 1;
    const RESULT_PREPARING = 2;
    const RESULT_EMPTY = 3;
    const RESULT_ERROR = -1;
    const RESULT_UNKNOWN = -10;

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

    public function addSingleItem($mediaItem)
    {
       if (! $mediaItem->isProcessable())
        return false;
       $preparing = $this->getStatus('preparing');


       $qItem = $this->mediaItemToQueue($mediaItem);
       $item = array('id' => $mediaItem->get('id'), 'qItem' => $qItem);
       $result = $this->q->withOrder(array($item), 5)->enqueue();

       $this->q->setStatus('preparing', $preparing); // add single should not influence preparing status. 
       return $result;
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
        $urls = $mediaItem->getOptimizeUrls();
        $item->urls = apply_filters('shortpixel_image_urls', $urls, $mediaItem->get('id'));

        return $item;
    }

    public function getShortQ()
    {
        return $this->q;
    }


} // class
