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
//var_dump($result);
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
var_dump($result);
var_dump(ResponseController::getAll());
      if ($result->status == 1)
        \WP_CLI::Success($result->message);
      elseif ($result->status == 0)
        \WP_CLI::Error(sprintf(__("Restored Item: %s", 'shortpixel_image_optimiser'), $result->message) );
  }

    /**
   * Runs the queue.
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
          $ticks = $assoc['ticks'];

        $controller = OptimizeController::getInstance();
        $results = $controller->processQueue();

      //  echo "** RUNQUEUE RESULT: **"; var_dump($results);

        $mediaResult = $results['media'];

        if (! is_null($mediaResult->message))
        {
          \WP_CLI::Log($mediaResult->message);
        }

      /*  switch($mediaResult->status)
        {
           case Queue::
        } */

        //var_dump(ResponseController::getAll());

        if (isset($mediaResult->results))
        {
           foreach($mediaResult->results as $item)
           {
               $result = $item->result;
            //  if ($item->result->status == ApiController::STATUS_ENQUEUED)
                 \WP_CLI::Log($result->message);
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
              \WP_CLI::Log($response->message);
        }

      //  if ($mediaResult->status !==)
    }


}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
   WPCliController::getInstance();
}
