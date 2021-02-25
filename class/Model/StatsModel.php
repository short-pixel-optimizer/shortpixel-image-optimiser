<?php
namespace ShortPixel\Model;

use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

class StatsModel
{

  protected $totalOptimized; // combined filesize of optimized images
  protected $totalOriginal;  // combined filesize of original images

  protected $lastUpdate;

  protected $currentStat;  // used for chaining it.

  protected $stats = array(
      'media' => array('items' => 0, // total media items found
                       'images' => 0, // total images (+thumbs) found
                       'lossy' => 0, // processed x compression
                       'lossy_thumbs' => 0, // main / thumbs
                       'lossless' => 0, // main /thumbs
                       'lossless_thumbs' => 0,
                       'glossy' => 0,
                       'glossy_thumbs' => 0,
      ),
      'custom' => array('items' => 0, // total custom items
                        'images' => 0, // total custom images
                        'lossy' => 0, // process x compression
                        'lossless' => 0,
                        'glossy' => 0,
      ),
      'period' => array('months' =>  // amount of images compressed in x month
                    array('1' => 0,  /// count x months ago what was done.
                        '2' => 0,
                        '3' => 0,
                        '4' => 0,
                    ),
      ),
      'total' => array('items' => 0,  // total items found
                       'images' => 0, // total images found




      ),



  );

  public function __construct()
  {
      $this->load();

  }

  public function load()
  {
    $settings = \wpSPIO()->settings();

    $this->totalOptimized = $settings->totalOptimized;
    $this->totalOriginal = $settings->totalOriginal;

    $stats = $settings->currentStats;

    $this->lastUpdate = $stats['time'];


    echo "<PRE> StatsModel CurrentSTATS "; print_r($stats); echo "</PRE>";

    //$this->stats = $stats;
  }

  public function save()
  {
     $settings = \wpSPIO()->settings();

     $settings->currentStats = $this->stats;

  }

  public function add($stat)
  {
     if (property_exists($stat, 'images'))
         $stats[$this->type][$images] += $stats->images;
     if (property_exists($stat, 'items'))
        $stats[$this->type][$images] += $stats->items;


  }

  public function get(string $name)
  {
      if (property_exists($this, $name))
         return $this->$name;
      else
        return null;
  }

  public function getStat(string $type)
  {
      $this->currentStat = null;

      if (isset($this->stats[$type]))
      {
         $this->currentStat = $this->stats[$type];
      }

      return $this;
  }

  public function grab(string $data)
  {
     if (is_null($this->currentStat))
       return null;

       if (isset($this->currentStat[$data]))
       {
          $this->currentStat = $this->currentStat[$data];
       }

       if (! is_array($this->currentStat))
        return $this->currentStat;
       else
        return $this;

  }



  //public function from





} // class
