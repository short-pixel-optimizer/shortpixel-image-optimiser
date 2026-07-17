<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

/**
 * Manages all WordPress cron schedules used by the plugin.
 *
 * Responsible for registering custom intervals, binding cron hook actions, and
 * scheduling or unscheduling events based on plugin settings:
 *
 *  - Bulk/single optimization crons (`spio-single-cron`, `spio-bulk-cron`) run
 *    on the `spio_interval` schedule (60 s by default) when background processing
 *    is active and items are awaiting optimization.
 *  - Custom-media directory scan cron (`spio-refresh-dir`) runs every 30 minutes
 *    when the Other Media feature is enabled.
 *  - Auto-remove-backups cron (`spio-remove-backups`) runs daily when the
 *    auto-remove-backups setting is active.
 *
 * Hooks registered:
 *  - filter `cron_schedules` — adds `spio_interval` and `spio_interval_30min` entries.
 *  - action `spio-single-cron` / `spio-bulk-cron` → AdminController::processCronHook
 *  - action `spio-refresh-dir` → AdminController::scanCustomFoldersHook
 *  - action `spio-remove-backups` → BackupController::cronRemoveBackups
 *
 * Legacy cron registrations (old argument formats) are cleaned up via
 * `removeLegacyCron()` on deactivation.
 *
 * @package ShortPixel\Controller
 */
class CronController
{

  /** @var CronController|null Singleton instance. */
  private static $instance;

  /** @var array<string, array<string, mixed>> Configuration map for bulk/single cron jobs. */
  protected $cron_options = [];

  /** @var array Reserved for future hook tracking. */
  protected $cron_hooks = [];

  /** @var bool Whether background processing is enabled in plugin settings. */
  protected $background_is_active = false;

  /**
   * Initialise cron state, register custom schedules, bind actions, and
   * schedule or unschedule events as appropriate.
   *
   * The `cron_schedules` filter is always attached — even when background
   * processing is inactive — so that stale events can be unscheduled.
   * Scheduling calls are skipped during AJAX requests.
   */
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
       $this->tools_scheduler();
     }

  }

  /**
   * Return the singleton instance, creating it on first call.
   *
   * @return static
   */
  public static function getInstance()
  {
     if ( is_null(self::$instance))
        self::$instance = new static();

    return self::$instance;
  }

  /**
   * Register custom cron intervals with WordPress.
   *
   * Adds `spio_interval` (default 60 s, filterable via
   * `shortpixel/cron/interval`) and `spio_interval_30min` (default 1800 s,
   * also filterable) to the WP cron schedules array.
   *
   * Note: the same filter handle `shortpixel/cron/interval` is applied to
   * both intervals; passing a custom value will override both.
   *
   * @param array $schedules Existing WP cron schedules.
   * @return array Schedules array with plugin entries added.
   */
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

  /**
   * Register cron action hooks and populate the cron-options map.
   *
   * Binds `AdminController::processCronHook` to the bulk and single cron
   * actions, `AdminController::scanCustomFoldersHook` to the directory-refresh
   * action, and `BackupController::cronRemoveBackups` to the backup-removal
   * action. Stores the bulk/single configuration in `$cron_options` for use
   * by the scheduler methods.
   *
   * @return void
   */
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

      add_action('spio-remove-backups', [BackupController::getBackupController(), 'cronRemoveBackups']);

      $this->cron_options = $background_crons;
  }

  /**
   * Read the `doBackgroundProcess` setting and cache it in `$background_is_active`.
   *
   * @return void
   */
  protected function checkActive()
  {
      $settings = \wpSPIO()->settings();
      $this->background_is_active = ($settings->doBackgroundProcess) ? true : false;
  }


  /**
   * Trigger the bulk scheduler when background processing is active.
   *
   * Called by AdminController after new items are enqueued so that a cron
   * event is registered promptly without waiting for the next page load.
   *
   * @return void
   */
  public function checkNewJobs()
  {
       if ( true === $this->background_is_active)
       {
          $this->bulk_scheduler();
       }
  }

  /**
   * Clean up all cron events on plugin deactivation.
   *
   * Removes bulk/single cron events, unschedules the directory-refresh cron,
   * and clears legacy cron registrations from older plugin versions.
   *
   * @return void
   */
  public function onDeactivate()
  {
      $this->bulkRemoveAll();
      $this->custom_scheduler(true);
      $this->removeLegacyCron();
  }

  /**
   * Ensure bulk and single cron events are scheduled or checked for all queue types.
   *
   * Iterates `$cron_options`. If no event is currently scheduled it calls
   * `bulkScheduleEvent()` to conditionally add one; otherwise calls
   * `bulkCheckEvent()` to remove the event when the queue is empty.
   *
   * @return void
   */
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
              $this->bulkCheckEvent($type, $options, $args);
            }
         }
  }

  /**
   * Schedule or unschedule the custom-media directory-refresh cron.
   *
   * The `spio-refresh-dir` event runs on the `spio_interval_30min` schedule.
   * It is scheduled when the Other Media feature is enabled and `$unschedule`
   * is false. It is unscheduled when Other Media is disabled or `$unschedule`
   * is true (e.g. on plugin deactivation). The `shortpixel/othermedia/add_cron`
   * filter can override the scheduling decision.
   *
   * @param bool $unschedule Pass true to force removal of the event (e.g. on deactivation).
   * @return void
   */
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

  /**
   * Schedule or unschedule the daily backup auto-removal cron.
   *
   * The `spio-remove-backups` event runs on WordPress's built-in `daily`
   * schedule. Scheduled when `autoRemoveBackups` is active; unscheduled when
   * the setting is off or `$unschedule` is true.
   *
   * @param bool $unschedule Pass true to force removal (e.g. on deactivation).
   * @return void
   */
  protected function tools_scheduler($unschedule = false)
  {
     $name = 'spio-remove-backups';  

     $scheduled = wp_next_scheduled($name);

     $add_cron = (false == \wpSPIO()->settings()->autoRemoveBackups) ? false : true;
     
     if (false == $scheduled && true === $add_cron && false === $unschedule)
     {
               wp_schedule_event(time(), 'daily', $name);
     }
     elseif(false !== $scheduled && (false === $add_cron || true == $unschedule) )
     {
          wp_unschedule_event(wp_next_scheduled($name), $name);
     }

  }

  /**
   * Remove cron events registered with the old argument formats from earlier plugin versions.
   *
   * Targets the legacy arg structures for `spio-refresh-dir`, `spio-single-cron`,
   * and `spio-bulk-cron` that were replaced by the current nested-array format.
   *
   * @return void
   */
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

  /**
   * Schedule a single cron event for a given queue type if conditions are met.
   *
   * For `bulk` queue type the event is only added when the queue is currently
   * running (`is_running` is true). For any queue type the event is only added
   * when there are items awaiting processing (`awaiting > 0`).
   *
   * @param string $queue_type Queue type key (e.g. 'bulk', 'single').
   * @param array  $options    Cron configuration for this type (cron_name, bulk flag).
   * @param array  $args       Arguments array passed to wp_schedule_event.
   * @return bool|void Returns false when the bulk queue is not running; void otherwise.
   */
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

  /**
   * Remove all Cron Events.
   *
   * @return void
   */
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

  /**
   * Unschedule a cron event for a given queue type when the queue is empty.
   *
   * If `awaiting` is 0 the currently scheduled event is removed.
   *
   * @param string $queue_type Queue type key (e.g. 'bulk', 'single').
   * @param array  $options    Cron configuration for this type (cron_name, bulk flag).
   * @param array  $args       Arguments array identifying the scheduled event.
   * @return void
   */
  protected function bulkCheckEvent($queue_type, $options, $args)
  {
      $data = $this->getQueueData($queue_type);


      if ($data->total->stats->awaiting == 0)
      {
         $name = $options['cron_name'];
         $bool = wp_unschedule_event(wp_next_scheduled($name, $args), $name, $args);
      }
  }

  /**
   * Fetch startup/status data for a given queue type.
   *
   * Constructs a QueueController with the appropriate `is_bulk` flag and
   * returns its startup data object (containing `total->stats->awaiting` and
   * `total->stats->is_running`).
   *
   * @todo Could be transferred to QueueController::getStartUpData directly.
   *
   * @param string $queue_type Queue type key ('bulk' or 'single').
   * @return object Queue startup data object.
   */
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
