<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\StatsModel as StatsModel;
use ShortPixel\Controller\Queue\StatsQueue as StatsQueue;
use ShortPixel\Model\Image\ImageModel as ImageModel;

/**
 * Provides a unified read interface for plugin statistics.
 *
 * Wraps StatsModel to expose counts of optimized images, thumbnails, and
 * compression ratios. Also calculates derived metrics such as thumbnails
 * still to optimize and average compression percentage (the latter is cached
 * via CacheController to avoid repeated database queries).
 *
 * Follows the singleton pattern via `getInstance()`.
 *
 * @package ShortPixel\Controller
 */
class StatsController extends \ShortPixel\Controller
{

    /** @var StatsModel The underlying statistics model. */
    protected $model;

    /** @var StatsQueue|null Reserved for future queue-based stat collection. */
    protected $queue;

    /** @var StatsController|null Singleton instance. */
    protected static $instance;

    /** @var array Placeholder for in-memory stat accumulation (currently unused). */
    protected $stats =  array(
        //  'processed'
    );

    /**
     * Instantiate the controller and its StatsModel.
     */
    public function __construct()
    {
         $this->model = new StatsModel();
    }

    /**
     * Return the singleton instance, creating it on first call.
     *
     * @return StatsController
     */
    public static function getInstance()
    {
         if (is_null(self::$instance))
           self::$instance = new StatsController();

         return self::$instance;
    }

    /**
     * Retrieve a statistic by path using one or more keys.
     *
     * With a single argument, attempts a direct property lookup on the model
     * first; if that returns null, falls back to `getStat()`. With multiple
     * arguments the first is passed to `getStat()` and each subsequent key
     * drills further via `grab()`. Returns 0 and logs a warning when the
     * final resolved value is still an object (i.e. the path did not resolve).
     *
     * @param mixed ...$params One or more stat path segments.
     * @return mixed The resolved statistic value, or 0 on failure.
     */
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
        for($i = 0; $i < count($params); $i++)
        {
            $stat = $stat->grab($params[$i]);
        }

        if (is_object($stat)) // failed to get statistic.
        {
            Log::addWarn('Statistics for this path failed', $params );
            return 0;

        }
        else
          return $stat;
    }

    /**
     * Reset all statistics in the underlying model.
     *
     * @return void
     */
    public function reset()
    {
       $this->model->reset();
    }

    /**
     * Return the average image compression percentage across the last 1000
     * successfully optimized images.
     *
     * The result is cached for one hour via CacheController under the key
     * `average_compression`. Returns 0 when no qualifying rows exist.
     *
     * @return int|float Average compression percentage (0–100), or 0 on failure.
     */
    public function getAverageCompression()
    {
      $cacheControl = new CacheController();

      $item = $cacheControl->getItem('average_compression');
      if ($item->exists())
      {
         return $item->getValue();
      }
      else {

          global $wpdb;
          $sql = 'select round(AVG(100-(compressed_size / original_size * 100))) from ' . $wpdb->prefix  . 'shortpixel_postmeta 
                  where status = %d and compressed_size > 0 and original_size > 0 order by id desc limit 1000';
          $sql = $wpdb->prepare($sql, ImageModel::FILE_STATUS_SUCCESS);

          $result = $wpdb->get_var($sql);

          if (is_numeric($result) && $result > 0)
          {
             $cacheControl->storeItem('average_compression', $result);
             return $result;
          }
      }

      return 0;

    }

    /**
     * Add image statistics to the model.
     *
     * @todo This method is not functional yet; the stat values are hardcoded
     *       placeholders and $stats is mutated before being passed to the model.
     *
     * @param object $stats Stats object to populate and persist.
     * @return void
     */
    public function addImage($stats)
    {
       $stats->type = 'media';
       $stats->compression = 'lossy';
       $stats->images = 6;
       $stats->items = 1;
       $stats->timestamp = 0;

       $this->model->add($stats);
    }

    /** This is a different calculation since the thumbs and totals are products of a database query without taking into account optimizable, excluded thumbs etc. This is a performance thing */
    public function thumbNailsToOptimize()
    {
       $totalThumbs = $this->find('media',
               'thumbsTotal'); // according to database.
       $totalThumbsOptimized = $this->find('media', 'thumbs');

       $excludedThumbnails = \wpSPIO()->settings()->excludeSizes;
       $excludeCount = (is_array($excludedThumbnails)) ? count($excludedThumbnails) : 0;

        // Totalthumbs - thumbsOptimized - minus amount of excluded (guess)
       $toOptimize = $totalThumbs - $totalThumbsOptimized - ($this->find('media', 'items') * $excludeCount);


       return $toOptimize;

    }

    /** This count all possible optimizable images (approx). Not checking settings like excludesizes / webp / original images etc. More fine-grained approx in BulkViewController  */
    public function totalImagesToOptimize()
    {
        $totalImagesOptimized = $this->find('total', 'images');
        $totalImages = $this->find('total', 'itemsTotal') + $this->find('total', 'thumbsTotal');

        $toOpt = $totalImages - $totalImagesOptimized;

        return $toOpt;

    }





} // class
