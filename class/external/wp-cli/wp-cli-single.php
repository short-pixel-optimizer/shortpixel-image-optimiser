<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;

use ShortPixel\Controller\Queue\Queue as Queue;
use ShortPixel\Controller\ApiController as ApiController;
use ShortPixel\Controller\ResponseController as ResponseController;

class SpioSingle extends SpioCommandBase
{

    /**
   * Restores optimized item to original ( if backups are active )
   *
   * ## OPTIONS
   *
   * <id>
   * : MediaLibrary ID
	 *
   * [--type=<type>]
   * : Media | Custom
   * ---
   * default: media
   * options:
   *   - media
   *   - custom
   * ---
   *
   * ## EXAMPLES
   *
   *   wp spio restore 1
	 *   wp spio restore 1 --type=custom
   *
   * @when after_wp_load
   */
  public function restore($args, $assoc_args)
  {
      $controller = new OptimizeController();
      $fs = \wpSPIO()->filesystem();

      if (! isset($args[0]))
      {
        \WP_CLI::Error(__('Specify an (Media Library) Item ID', 'shortpixel_image_optimiser'));
        return;
      }
			if (! is_numeric($args[0]))
			{
				 \WP_CLI::Error(__('Item ID needs to be a number', 'shortpixel-image-optimiser'));
				 return;
			}

      $id = intval($args[0]);
			$type = $assoc_args['type'];

			$image = $fs->getImage($id, $type);

			if ($image === false)
			{
				 \WP_CLI::Error(__('No Image returned. Please check if the number and type are correct and the image exists', 'shortpixel-image-optimiser'));
				 return;
			}

      $result = $controller->restoreItem($image);

			$this->showResponses();

	 		if (property_exists($result,'message' && strlen($result->message) > 0))
				 $message = $result->message;
			elseif (property_exists($result, 'result') && property_exists($result->result, 'message'))
				 $message = $result->result->message;

      if ($result->status == 1)
			{
        \WP_CLI::Success($message);
			}
      elseif ($result->status == 0)
			{
        \WP_CLI::Error(sprintf(__("Restoring Item: %s", 'shortpixel_image_optimiser'), $message) );
			}
  }




} // CLASS
