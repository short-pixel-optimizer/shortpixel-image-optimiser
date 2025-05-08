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

  protected $background_is_active = false;

  public function __construct()
  {
     $this->checkActive();
     // Important that the schedules filter always goes for unscheduling, even when non-active.
     add_filter( 'cron_schedules', array($this,'cron_schedules') );

     $this->init();
     if (false === wp_doing_ajax())
     {
       // No need to load anything
       if (false === $this->background_is_active)
       {
          $this->bulkRemoveAll();
       }
       else {
          $this->bulk_scheduler();
       }

       $this->custom_scheduler();
     }

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
          'display' => __('ShortPixel cron interval', 'shortpixel-image-optimiser')
        );

        $schedules['spio_interval_30min'] = array(
          'interval' => apply_filters('shortpixel/cron/interval', 30 * MINUTE_IN_SECONDS),
          'display' => __('ShortPixel 30 min interval', 'shortpixel-image-optimiser')
        );

        return $schedules;
  }

  protected function init()
  {

      // Defaults
      $background_crons = array(
          'single' => array(
              'cron_name' => 'spio-single-cron',
              'bulk' => false,

          ),
          'bulk' => array(
            'cron_name' => 'spio-bulk-cron',
            'bulk' => true,
          ),
      );

      $custom_crons = array(
          'directory' => array(
              'cron_name' => 'spio-refresh-dir',
          )
      );

      foreach($background_crons as $name => $options)
      {
         add_action($options['cron_name'], array(AdminController::getInstance(), 'processCronHook'));
      }

      foreach ($custom_crons as $name => $options)
      {
         add_action($options['cron_name'], array(AdminController::getInstance(), 'scanCustomFoldersHook'));
      }

      $this->cron_options = $background_crons;
  }

  protected function checkActive()
  {
      $settings = \wpSPIO()->settings();
      $this->background_is_active = ($settings->doBackgroundProcess) ? true : false;
  }


  public function checkNewJobs()
  {
       if ( true === $this->background_is_active)
       {
          $this->bulk_scheduler();
       }
  }

  public function onDeactivate()
  {
      $this->bulkRemoveAll();
      $this->custom_scheduler(true);
      $this->removeLegacyCron();
  }

  protected function bulk_scheduler()
  {
         foreach($this->cron_options as $type => $options)
         {
            $name = $options['cron_name'];
            $args = [0 => [
                  'bulk' => $options['bulk']]
              ];

            if ( false === wp_next_scheduled($name, $args))
            {
              $this->bulkScheduleEvent($type, $options, $args);
            }
            else  {
              // check if still items, or how do we do this (@todo)
              $this->bulkCheckevent($type, $options, $args);
            }
         }
  }

  protected function custom_scheduler($unschedule = false)
  {
      $name = 'spio-refresh-dir';
      $args = [0 => [
          'amount' => 10]
      ];

      $scheduled = wp_next_scheduled($name, $args);

			$add_cron = (false == \wpSPIO()->settings()->showCustomMedia) ? false : true;
			$add_cron = apply_filters('shortpixel/othermedia/add_cron', $add_cron);

      if (false == $scheduled && true === $add_cron && false === $unschedule)
      {
                wp_schedule_event(time(), 'spio_interval_30min', $name, $args);
      }
      elseif(false !== $scheduled && (false === $add_cron || true == $unschedule) )
      {
           wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);
      }

  }

  protected function removeLegacyCron()
  {
      $name = 'spio-refresh-dir';
      $args = ['args' => [
        'amount' => 10]
      ];

      wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);

      $name = 'spio-single-cron';
      $args = array('bulk' => false);

      wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);


      $name = 'spio-bulk-cron';
      $args = array('bulk' => true);

      wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);


  }

  protected function bulkScheduleEvent($queue_type, $options, $args)
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

  protected function bulkRemoveAll()
  {
    foreach($this->cron_options as $type => $options)
    {
       $name = $options['cron_name'];
       $args = [0 => [
             'bulk' => $options['bulk']]
         ];

       if (false !== wp_next_scheduled ($name, $args))
       {
         $bool = wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);
       }
    }
  }

  protected function bulkCheckEvent($queue_type, $options, $args)
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
      if ('bulk' === $queue_type)
      {
         $args['is_bulk'] = true; 
      }
      else
      {
        $args['is_bulk'] = false;
      }


      $queueController = new QueueController($args);
      return $queueController->getStartUpData();


  }


} // class
