<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;



class CronController
{

  private static $instance;

  protected $cron_options = array();
  protected $cron_hooks = array();

  protected $is_active = false;

  public function __construct()
  {
     $this->checkActive();
     // Important that the schedules filter always goes for unscheduling, even when non-active.
     add_filter( 'cron_schedules', array($this,'cron_schedules') );

     // No need to load anything
     if (false === $this->is_active)
     {
        $this->removeAll();
        return;
     }
     $this->init();
     $this->scheduler();
  }

  public static function getInstance()
  {
     if ( is_null(self::$instance))
        self::$instance = new static();

    return self::$instance;
  }

  public function cron_schedules($schedules)
  {
        $schedules['spio_interval'] = array(
          'interval' => apply_filters('shortpixel/cron/interval', 60),
          'display' => __('Shortpixel cron interval', 'shortpixel-image-optimiser')
        );
    /*    $schedules['spio_15min'] = array(
          'interval' => 60 * 15,
          'display' => __('Shortpixel 15 minutes', 'shortpixel-image-optimiser')
        ); */

        return $schedules;
  }

  protected function init()
  {

      // Defaults
      $crons = array(
          'single' => array(
              'cron_name' => 'spio-single-cron',
              'bulk' => false,

          ),
          'bulk' => array(
            'cron_name' => 'spio-bulk-cron',
            'bulk' => true,
          ),
      );

      foreach($crons as $name => $options)
      {
         add_action($options['cron_name'], array(AdminController::getInstance(), 'processCronHook'));
      }

      $this->cron_options = $crons;
  }

  protected function checkActive()
  {
      $settings = \wpSPIO()->settings();
      $this->is_active = ($settings->doBackgroundProcess) ? true : false;
  }


  public function checkNewJobs()
  {
       if ( true === $this->is_active)
       {
          $this->scheduler();
       }
  }

  protected function scheduler()
  {
         foreach($this->cron_options as $type => $options)
         {
            $name = $options['cron_name'];
            $args = array('bulk' => $options['bulk']);

            if ( false === wp_next_scheduled($name, $args))
            {
              $this->scheduleEvent($type, $options, $args);
            }
            else  {
              // check if still items, or how do we do this (@todo)
              $this->checkevent($type, $options, $args);
            }
         }
  }

  protected function scheduleEvent($queue_type, $options, $args)
  {
      $data = $this->getQueueData($queue_type);

      $items = $data->total->stats->awaiting;
      $is_running = $data->total->stats->is_running;


      // Only queue must have a run command, nothing else.
       if ('bulk' === $queue_type && false === $is_running)
       {
          return false; // no queues running
       }

       if ($items  > 0)
       {
          wp_schedule_event(time(), 'spio_interval', $options['cron_name'], $args);
       }

  }

  protected function removeAll()
  {
    foreach($this->cron_options as $type => $options)
    {
       $name = $options['cron_name'];
       $args = array('bulk' => $options['bulk']);

       if (false !== wp_next_scheduled ($name, $args))
       {
         $bool = wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);
       }
    }
  }

  protected function checkEvent($queue_type, $options, $args)
  {
      $data = $this->getQueueData($queue_type);


      if ($data->total->stats->awaiting == 0)
      {
         $name = $options['cron_name'];
         $bool = wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);
      }
  }

  // This could be transferred to getStartUpData instead.
  private function getQueueData($queue_type)
  {
      $optimizeController = new OptimizeController();
      if ('bulk' === $queue_type)
      {

         $optimizeController->setBulk(true);
      }
      else {
        $optimizeController->setBulk(false);
      }


      $data = $optimizeController->getStartUpData();
      return $data;

  }


} // class
