<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
// Attempt to standardize what goes around in the queue and keep some overview.

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Queue\QueueItem as QueueItem;

/**
 * Factory helper that creates and wraps QueueItem instances from image models or raw IDs.
 *
 * Provides a single point of access for obtaining QueueItem objects so that
 * the rest of the queue layer does not construct them directly.
 *
 * @package ShortPixel\Controller\Queue
 */
class QueueItems
{

    /** @var array<string, array<int, QueueItem>> In-memory cache of items keyed by type and ID (currently unused). */
    protected static $items = [
        'media' => [],
        'custom' => [],
    ];

    /**
     * GetImageItem
     *
     * @param ImageModel $imageModel
     * @return QueueItem QueueItem
     */

    public static function getImageItem(ImageModel $imageModel)
    {
        $type = $imageModel->get('type');
        $id = $imageModel->get('id');

        /*
        if (! isset(self::$items[$type][$id]))
        {
            $item = new QueueItem(['imageModel' => $imageModel]);
            self::$items[$type][$id] = $item;
        }

        return self::$items[$type][$id];
        */
        $item = new QueueItem(['imageModel' => $imageModel]);
        return $item;
    }

    /**
     * Creates a lightweight QueueItem containing only an ID and type, without loading
     * the underlying image model — used for migrate/removeLegacy operations.
     *
     * @param int    $id   Item identifier.
     * @param string $type Queue type ('media' or 'custom').
     * @return QueueItem
     */
    public static function getEmptyItem($id, $type)
    {

      $item = new QueueItem(['item_id' => $id, 'type' => $type]);
      return $item;
      /*
      if (! isset(self::$items[$type][$id]))
      {
          $item = new QueueItem(['item_id' => $id, 'type' => $type]);
          self::$items[$type][$id] = $item;
      }

      return self::$items[$type][$id]; */
    }

    /**
     * Loads an image from the filesystem by ID and type and wraps it in a QueueItem.
     *
     * @param int    $id   Item ID (attachment or custom-media ID).
     * @param string $type Queue type — 'media' (WordPress Media Library) or 'custom'.
     * @return QueueItem|false QueueItem on success, false when the image cannot be loaded.
     */
    public static function getImageItemByID($id, $type)
    {
        $fs = \wpSPIO()->filesystem();
        $image = $fs->getMediaImage($id, $type);
         if (false !== $image)
         {
            return self::getImageItem($image);
         }
         else {
            return false;
         }

    }

} // class
