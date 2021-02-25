<?php
namespace ShortPixel\Controller;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\StatsModel as StatsModel;
use ShortPixel\Controller\Queue\StatsQueue as StatsQueue;

class StatsController extends \ShortPixel\Controller
{

    protected $model;
    protected $queue;
    protected static $instance;

    protected $stats =  array(
        //  'processed'
    );



    public function __construct()
    {
         $this->model = new StatsModel();
         $this->queue = new StatsQueue();

    }

    public static function getInstance()
    {
         if (is_null(self::$instance))
           self::$instance = new StatsController();

         return self::$instance;
    }

    public function find(... $params)
    {
        if (count($params) == 1)
        {
           $stat = $this->model->get($params[0]); // check if stat is simple property
           if (! is_null($stat) )
           {
              return $stat;
           }

        }

        $stat = $this->model->getStat(array_shift($params));

        for($i =0 ; $i < count($params); $i++)
        {
            $stat = $stat->grab($params[$i]);
        }

        if (is_object($stat)) // failed to get statistic.
        {
            Log::addWarn('Statistics for this path failed', $params );
            return __('n/a', 'shortpixel-image-optimizer');
        }
        else
          return $stat;
    }

    public function getAverageCompression()
    {
      $totalOptimized = $this->model->get('totalOptimized');
      $totalOriginal = $this->model->get('totalOriginal');

      return $totalOptimized > 0
             ? round(( 1 -  ( $totalOptimized / $totalOriginal ) ) * 100, 2)
             : 0;
    }

    public function addImage($stats)
    {
       $stats->type = 'media';
       $stats->compression = 'lossy';
       $stats->images = 6;
       $stats->items = 1;
       $stats->timestamp = 0;

       $this->model->add($stats);
    }



    protected function startCount()
    {

    }

} // class
