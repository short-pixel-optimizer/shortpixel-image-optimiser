<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;

class WpCliController
{
    public static $instance;

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
   * Runs the queue.
   *
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
   *   wp spio runqueue
   *
   * @when after_wp_load
   */
    public function runqueue()
    {
        $controller = OptimizeController::getInstance();
        $result = $controller->processQueue();

        var_dump($result);
    }


}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
   WPCliController::getInstance();
}
