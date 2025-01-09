<?php
namespace ShortPixel\Model;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}
// Attempt to standardize what goes around in the queue and keep some overview.

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Model\Converter\Converter as Converter;

class QueueItem
{

    protected $imageModel;
    protected $item_id;
    protected $action = 'optimize';
    protected $queueItem; // Object coming from WPQ

    protected $result;

    protected $data; // something savable to dbase, for now object.

    protected $item_count; // counted images for the table.


    protected $debug_active = false; // prevent operations when it's debug view in edit media

    public function __construct($args = [])
    {

        if (isset($args['imageModel']) && ! is_null($args['imageModel']) && is_object($args['imageModel']))
        {
           $this->imageModel = $args['imageModel'];
           $this->item_id = $this->imageModel->get('id');
        }
        elseif(isset($args['item_id']) && is_numeric($args['item_id']))
        {
            $this->item_id = intval($args['item_id']);
        }

    }

    public function setModel(ImageModel $imageModel)
    {
      $this->imageModel = $imageModel;
    }

    public function setFromData($data)
    {
        if (is_array($data))
        {
           $this->data = (object) $data;
        }
        elseif (is_object($data))
        {
          $this->data = $data;
        }

    }

    public function setData($name, $value)
    {
           $this->data->$name = $value;
    }

    public function set($name, $value)
    {
        if (property_exists($this, $name))
        {
           $this->$name = $value;
        }
    }

    // Return Data that need's storing in Queue Table
    public function getQueueItem()
    {
        $qItem = $this->queueItem;
        $qItem->value = $this->data;
        return $qItem;
    }

    public function __get($name)
    {
       if (property_exists($this, $name))
       {
          return $this->$name;
       }
       if (property_exists($this->data, $name))
       {
          return $this->data->$name;
       }

       return null;
    }

    public function returnArray()
    {
        $array = [
            'item_id' => $this->item_id,
            'result' => $this->result,
            'data' => $this->data,
        ];

        return $array;
    }

    public function returnEnqueue()
    {
        $value = $this->data;

        $media_id = $this->imageModel->get('id');
        if ($this->imageModel->getParent() !== false)
        {
          $media_id = $this->imageModel->getParent();
        }

        return ['id' => $media_id, 'value' => $value, 'item_count' => $this->item_count];
    }

    public function setDebug()
    {
       $this->debug_active = true;
    }

    public function newMigrateAction()
    {
       $this->action = 'migrate';
       $item->item_count = 1;
    }

    public function newRemoveLegacyAction()
    {
        $this->action = 'removeLegacy';
        $this->item_count = 1;
    }

    public function setResult($args = [])
    {
        // Should list every possible item, arrayfilter out.
       $defaults = [
          'apiStatus' => RequestManager::STATUS_UNCHANGED,
          'message' => '',
          'is_error' => false,
          'is_done' => false,
          'file' => null,  // should probably be merged these two.
          'files' => null,

       ];

       $args = wp_parse_args($args, $defaults);

       $result = (object) $args;

       $this->result = $result;

    }

    public function newDumpAction()
    {
        $imageModel = $this->imageModel;
        $urls = $imageModel->getOptimizeUrls();
        $this->data->urls = $urls;

    }

    public function newOptimizeAction()
    {
      $imageModel =  $this->imageModel;

    /*  $defaults = array(
          'debug_active' => false, // prevent write actions if called via debugger
      ); */

      //$args = wp_parse_args($args, $defaults);

      $item = new \stdClass;

      $item->compressionType = \wpSPIO()->settings()->compressionType;

     $data = $imageModel->getOptimizeData();
     $urls = $data['urls'];
     $params = $data['params'];

     list($u, $baseCount) = $imageModel->getCountOptimizeData('thumbnails');
     list($u, $webpCount) = $imageModel->getCountOptimizeData('webp');
     list($u, $avifCount) = $imageModel->getCountOptimizeData('avif');

      $counts = new \stdClass;
      $counts->creditCount = $baseCount + $webpCount + $avifCount;  // count the used credits for this item.
      $counts->baseCount = $baseCount; // count the base images.
      $counts->avifCount = $avifCount;
      $counts->webpCount = $webpCount;

      $this->item_count = $counts->creditCount;

     $removeKeys = array('image', 'webp', 'avif'); // keys not native to API / need to be removed.

     // Is UI info, not for processing.
     if (isset($data['params']['paths']))
     {
        unset($data['params']['paths']);
     }

     foreach($data['params'] as $sizeName => $param)
     {
         $plus = false;
         $convertTo = array();
         if ($param['image'] === true)
         {
            $plus = true;
         }
         if ($param['webp'] === true)
         {
            $convertTo[] = ($plus === true) ? '+webp' : 'webp';
         }
         if ($param['avif'] === true)
         {
           $convertTo[] = ($plus === true) ? '+avif' : 'avif';
         }

         foreach($removeKeys as $key)
         {
            if (isset($param[$key]))
            {
               unset($data['params'][$sizeName][$key]);
            }
         }

         if (count($convertTo) > 0)
         {
           $convertTo = implode('|', $convertTo);
           $data['params'][$sizeName]['convertto'] = $convertTo;
         }
     }

     // CompressionType can be integer, but not empty string. In cases empty string might happen, causing lossless optimization, which is not correct.
      if (! is_null($imageModel->getMeta('compressionType')) && is_numeric($imageModel->getMeta('compressionType')))
     {
        $item->compressionType = $imageModel->getMeta('compressionType');
     }

      // Former securi function, add timestamp to all URLS, for cache busting.
      $urls = $this->timestampURLS( array_values($urls), $imageModel->get('id'));
      $item->urls = apply_filters('shortpixel_image_urls', $urls, $imageModel->get('id'));

     if (count($data['params']) > 0)
     {
       $item->paramlist= array_values($data['params']);
     }

     if (count($data['returnParams']) > 0)
     {
        $item->returndatalist = $data['returnParams'];
     }

      $item->counts = $counts;

      // Converter can alter the data for this item, based on conversion needs
      $converter = Converter::getConverter($imageModel, true);
      if ($baseCount > 0 && is_object($converter) && $converter->isConvertable())
      {
         $converter->filterQueue($item, ['debug_active' => $this->debug_active]);
      }

      $this->data = $item;
      $this->data->action = 'optimize';

      //return $item;
    }

    public function newAltAction()
    {
        $item = new \stdClass;
        $item->url = $this->imageModel->getUrl();
        $item->tries = 0;

        $this->item_count = 1;
        $this->data = $item;
    }

    protected function timestampURLS($urls, $id)
    {
      // https://developer.wordpress.org/reference/functions/get_post_modified_time/
      $time = get_post_modified_time('U', false, $id );
      foreach($urls as $index => $url)
      {
        $urls[$index] = add_query_arg('ver', $time, $url); //has url
      }

      return $urls;
    }

} // class
