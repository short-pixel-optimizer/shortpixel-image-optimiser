<?php
namespace ShortPixel\Queue;

abstract class Queue
{

    protected $q;
    protected $instance;
    const PLUGIN_SLUG = 'SPIO';

    public static function getInstance()
    {
       if (is_null(self::$instance))
       {
          $class = get_class(self);
          self::$instance = new $class();
       }

       return self::$instance;
    }

    abstract public function bulkisRunning();

    abstract public function hasItems();
    abstract public function addSingleItem($mediaItem);

    //abstract protected function queuetoMediaItem();

    public function deQueue()
    {
       $items = $this->q->deQueue();
       return $items;
    }

    // This might be a general implementation
    protected function mediaItemToQueue($mediaItem)
    {
        $item = new \stdClass;
        $item->urls = $mediaItem->getOptimizeUrls();

        return $item;
    }

    public function getShortQ()
    {
        return $this->q;
    }







} // class
