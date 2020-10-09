<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;

use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\ApiController as ApiController;
use ShortPixel\Controller\ResponseController as ResponseController;

class WpCliController
{
    public static $instance;


    protected static $ticks = 0;
    protected static $emptyq = 0;

    public function __construct()
    {
        $this->initCommands();
    }


    public static function getInstance()
    {
        if (is_null(self::$instance))
          self::$instance = new WpCliController();

        return self::$instance;
    }


    protected function initCommands()
    {
        \WP_CLI::add_command('spio', '\ShortPixel\SpioCommand');
    }

    /*
    public function main()
    {

    } */

}


/**
* ShortPixel Image Optimizer : Enqueue and Run Queue Commands
*/
class SpioCommand
{

     protected static $runs = 0;
      /**
     * Adds item to the queue.
     *
     * ## OPTIONS
     *
     * <id>
     * : MediaLibrary ID
     *
     *
     * ---
     * default: success
     * options:
     *   - success
     *   - error
     * ---
     *
     * ## EXAMPLES
     *
     *   wp spio enqueue 1
     *
     * @when after_wp_load
     */
    public function enqueue($args)
    {
        $controller = OptimizeController::getInstance();
        if (! isset($args[0]))
        {
          \WP_CLI::Error(__('Specify an Media Library Item ID', 'shortpixel_image_optimiser'));
          return;
        }
        $id = intval($args[0]);

        $result = $controller->addItemtoQueue($id);

        if ($result->status == 1)
          \WP_CLI::Success($result->message);
        elseif ($result->status == 0)
          \WP_CLI::Error(sprintf(__("Adding this item: %s", 'shortpixel_image_optimiser'), $result->message) );
    }


    /**
   * Restores optimized item to original ( if backups are active )
   *
   * ## OPTIONS
   *
   * <id>
   * : MediaLibrary ID
   *
   *
   * ---
   * default: success
   * options:
   *   - success
   *   - error
   * ---
   *
   * ## EXAMPLES
   *
   *   wp spio restore 1
   *
   * @when after_wp_load
   */
  public function restore($args)
  {
      $controller = OptimizeController::getInstance();
      if (! isset($args[0]))
      {
        \WP_CLI::Error(__('Specify an Media Library Item ID', 'shortpixel_image_optimiser'));
        return;
      }
      $id = intval($args[0]);

      $result = $controller->restoreItem($id);

      if ($result->status == 1)
        \WP_CLI::Success($result->message);
      elseif ($result->status == 0)
        \WP_CLI::Error(sprintf(__("Restored Item: %s", 'shortpixel_image_optimiser'), $result->message) );
  }

 /**
 * Enqueues the batch for bulk optimizing the media library
 *
 * ## OPTIONS
 *
 *   [--ticks=<20>]
 *   : How much times the queue runs
 *
 *   [--wait=<3>]
 *   : How much seconds to wait for next tick.
 *
 *   [--run=<true>]
 *   : Directly start running bulk process after preparing
 *
 * ---
 * default: success
 * options:
 *   - success
 *   - error
 * ---
 *
 * ## EXAMPLES
 *
 *   wp spio createbulk
 *
 * @when after_wp_load
 */
  public function createbulk($args, $assoc)
  {
      $controller = OptimizeController::getInstance();
      $controller->createBulk();
      $this->runqueue($args, $assoc);
  }

  public function startbulk($args, $assoc)
  {
      $controller = OptimizeController::getInstance();
      $controller->startBulk();

  }

    /**
   * Runs the current queue.
   *
   * ## OPTIONS
   *
   * [--ticks=<20>]
   * : How much times the queue runs
   *
   * [--wait=<3>]
   * : How much seconds to wait for next tick.
   *
   * ---
   * default: success
   * options:
   *   - success
   *   - error
   * ---
   *
   * ## EXAMPLES
   *
   *   wp spio runqueue <ticks=20> <wait=3>
   *
   *
   * @when after_wp_load
   */
    public function runqueue($args, $assoc)
    {
        if ( isset($assoc['ticks']))
          $ticks = intval($assoc['ticks']);
        else
          $ticks = 20;

        if (isset($assoc['wait']))
          $wait = intval($assoc['wait']);
        else
          $wait = 3;

        $progress = \WP_CLI\Utils\make_progress_bar( 'This run (ticks) ', $ticks );

        while($ticks > 0)
        {
           $bool = $this->runClick();
           if ($bool === false)
           {
             break;
           }
           $ticks--;
           $progress->tick();
           sleep($wait);
        }

        $progress->finish();
    }

    protected function runClick()
    {

        $controller = OptimizeController::getInstance();
        $results = $controller->processQueue();

        $mediaResult = $results['media'];

        if (! is_null($mediaResult->message))
        {
          \WP_CLI::line($mediaResult->message); // Single Response ( ie prepared, enqueued etc )
        }

        // Result after optimizing items and such.
        if (isset($mediaResult->results))
        {
           foreach($mediaResult->results as $item)
           {
               $result = $item->result;
            //  if ($item->result->status == ApiController::STATUS_ENQUEUED)
                 \WP_CLI::line($result->message);
                 if (property_exists($result, 'improvements'))
                 {
                    $improvements = $result->improvements;
                    if (isset($improvements['main']))
                       \WP_CLI::Success( sprintf(__('Image optimized by %d %% ', 'shortpixel-image-optimiser'), $improvements['main'][0]));

                    if (isset($improvements['thumbnails']))
                    {
                      foreach($improvements['thumbnails'] as $thumbName => $optData)
                      {
                         \WP_CLI::Success( sprintf(__('%s optimized by %d %% ', 'shortpixel-image-optimiser'), $thumbName, $optData[0]));
                      }
                    }
                 }

           }
        }


        $responses = ResponseController::getAll();

        foreach ($responses as $response)
        {
            if ($response->is('error'))
               \WP_CLI::Error($response->message);
            elseif ($response->is('success'))
               \WP_CLI::Success($response->message);
            else
              \WP_CLI::line($response->message);
        }

        if ($mediaResult->status == Queue::RESULT_QUEUE_EMPTY)
        {
           \WP_CLI::line('Queue reports processing has finished');
           return false;
        }
        elseif($mediaResult->status == Queue::RESULT_PREPARING_DONE && $mediaResult->getStatus('bulk_running') == false)
        {
           \WP_CLI::line('Bulk Preparing is done. Bulk can be run by running startbulk command');
        }

      //  if ($mediaResult->status !==)
      return true;
    }

} // Class

if ( defined( 'WP_CLI' ) && WP_CLI ) {
   WPCliController::getInstance();
}
