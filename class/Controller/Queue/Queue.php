<?php
namespace ShortPixel\Queue;

abstract class Queue
{
    protected $q;
    protected $instance;
    const PLUGIN_SLUG = 'SPIO';

    const RESULT_ITEMS = 1;
    const RESULT_PREPARING = 2;
    const RESULT_EMPTY = 3;
    const RESULT_ERROR = -1;
    const RESULT_UNKNOWN = -10;


    public static function getInstance()
    {
       if (is_null(self::$instance))
       {
          $class = get_class(self);
          self::$instance = new $class();
       }

       return self::$instance;
    }

    //abstract public function processingIsAllowed();

    //abstract public function hasItems();
    abstract public function addSingleItem(ImageModel $mediaItem);

    abstract protected function createNewBulk($args);

    abstract protected function prepare();

    public function run()
    {
       $items = array();

       $result = \stdClass;
       $result->status = RESULT_UNKNOWN;
       $result->message = '';
       $result->items = null;


       if ( $this->getStatus('preparing'))
       {
          $this->prepare();
       }
       elseif ($this->getStatus('bulk_running'))
       {
            $items = $this->dequeue();
       }
       elseif ($this->getStatus('run'))
       {
         if ($this->getStatus('items') > 0)
         {
            $this->q->dequeuePriority();
         }
       }

    }

    protected function getStatus($name = false)
    {
        return $this->q->getStatus($name);
    }
    //abstract function
  //  abstract function
    //abstract protected function queuetoMediaItem();
  /*  public function processingIsAllowed()
    {
        if ($this->q->getStatus('preparing') == false && )
    } */


    public function deQueue()
    {
       $items = $this->q->deQueue();
       return $items;
    }

    public function deQueuePriority()
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
