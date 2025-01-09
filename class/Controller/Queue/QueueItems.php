<?php
namespace ShortPixel\Controller\Queue;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
// Attempt to standardize what goes around in the queue and keep some overview.

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\QueueItem as QueueItem;

class QueueItems
{

    protected static $items = [
        'media' => [],
        'custom' => [],
    ];

    public static function getImageItem(ImageModel $imageModel)
    {
        $type = $imageModel->get('type');
        $id = $imageModel->get('id');

        if (! isset(self::$items[$type][$id]))
        {
            $item = new QueueItem(['imageModel' => $imageModel]);
            self::$items[$type][$id] = $item;
        }

        return self::$items[$type][$id];
    }

    public static function getEmptyItem($id, $type)
    {

      if (! isset(self::$items[$type][$id]))
      {
          $item = new QueueItem(['item_id' => $id, 'type' => $type]);
          self::$items[$type][$id] = $item;
      }

      return self::$items[$type][$id];
    }


    /*
      @param int $id of the item
      @param string $type Custom / Media
     */
    public static function getImageItemByID($id, $type)
    {
         $image = $fs->getMediaImage($id, $type);
         if (false !== $image)
         {
            return self::getItem($image);
         }
         else {
            return false;
         }

    }



} // class
