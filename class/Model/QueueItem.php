<?php
namespace ShortPixel\Model;

if (!defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}
// Attempt to standardize what goes around in the queue and keep some overview.

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Controller\Api\ApiController as ApiController;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Model\Converter\Converter as Converter;

use ShortPixel\Controller\Optimizer\OptimizeController as OptimizeController;
use ShortPixel\Controller\Optimizer\OptimizeAiController as OptimizeAiController;
use ShortPixel\Controller\Optimizer\ActionController as ActionController;

class QueueItem
{

   protected $imageModel;
   protected $item_id;
   //  protected $action = 'optimize'; // This must be in data!
   protected $queueItem; // Object coming from WPQ

   protected $result;

   protected $data; // something savable to dbase, for now object. This is the only thing persistent!

   protected $item_count; // counted images for the table.


   protected $debug_active = false; // prevent operations when it's debug view in edit media

   public function __construct($args = [])
   {

      if (isset($args['imageModel']) && !is_null($args['imageModel']) && is_object($args['imageModel'])) {
         $this->setModel($args['imageModel']);
      } elseif (isset($args['item_id']) && is_numeric($args['item_id'])) {
         $this->item_id = intval($args['item_id']);
      }


      // Init defaults
      $this->data = new \stdClass; // init
   }

   public function setModel(ImageModel $imageModel)
   {
      $this->imageModel = $imageModel;
      $this->item_id = $imageModel->get('id');
   }

   public function setFromData($data)
   {
      if (is_array($data)) {
         $this->data = (object) $data;
      } elseif (is_object($data)) {
         $this->data = $data;
      }

   }

   public function setData($name, $value)
   {
      $this->data->$name = $value;
   }

   public function block($block = null)
   {
      if (is_null($block)) {
         if (property_exists($this->data, 'block')) {
            return $this->data->block;
         } else {
            return false;
         }
      } else {
         $this->data->block = (bool) $block;
      }
   }

   public function data()
   {
      return $this->data;
   }

   public function result()
   {
      // check / set defaults.
      if (false === property_exists($this->result, 'item_id')) {
         $this->result->item_id = $this->item_id;
      }

      if (false === property_exists($this->result, 'is_error')) {
         $this->result->is_error = false;
      }

      if (false === property_exists($this->result, 'is_done')) {
         $this->result->is_done = false;
      }


      $result = $this->result;

      $result->_debug_is_qItemResult = true;

      return $result;
   }

   public function set($name, $value)
   {
      if (property_exists($this, $name)) {
         $this->$name = $value;
      } else {
         // @todo Throw here some php error
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
      if (property_exists($this, $name)) {
         return $this->$name;
      }
      if (property_exists($this->data, $name)) {
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

   public function returnObject()
   {
      $object = new \stdClass;
      $object->item_id = $this->item_id;
      $object->result = $this->result;
      $object->data = $this->data;

      return $object;
   }

   public function returnEnqueue()
   {
      $value = $this->data;

      $media_id = $this->imageModel->get('id');
      if ($this->imageModel->getParent() !== false) {
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
      $this->data->action = 'migrate';
      $this->item_count = 1;
   }

   public function newRestoreAction()
   {
      $this->data->action = 'restore';
      $this->item_count = 1;
   }

   public function newReOptimizeAction($args = [])
   {


       $this->data->action = 'reoptimize'; 
       $this->item_count = 1;

       // Smartcrop setting (?) 
       if (isset($args['smartcrop']))
       {
          $this->data->smartcrop = $args['smartcrop']; 
       }

       // Then new compresion type to optimize to. 
       if (isset($args['compressiontype'])) 
       {
          $this->data->compressiontype = $args['compressiontype'];
       }
       
   }

   public function newRemoveLegacyAction()
   {
      $this->data->action = 'removeLegacy';
      $this->item_count = 1;
   }

   public function addResult($data = [])
   {
      // Should list every possible item, arrayfilter out.
      $validation = [
         'apiStatus' => RequestManager::STATUS_UNCHANGED,
         'message' => '',
         'is_error' => false,
         'is_done' => false,
         'file' => null,  // should probably be merged these two.
         'files' => null,
         'fileStatus' => null,
         'filename' => '', // @todo figure out why this is here.
         'error' => null,  // might in time better be called error_code or so
         'success' => false, // new
         'improvements' => null,
         'original' => null,
         'optimized' => null,
         'queueType' => null, // OptimizeController but (?) usage
         'kblink' => null,
         'data' => [], // Is returnDataList returned by apiController.

      ];

      //Log::addTemp('Qitem adding result: ', $data);

      if (is_null($this->result)) {
         $this->result = new \stdClass;
      }


      foreach ($data as $name => $value) {
         if (false === isset($validation[$name])) {
            Log::addWarn("Result $name not in validation");
         }

         $this->result->$name = $value;
      }

   }

   public function newDumpAction()
   {
      $imageModel = $this->imageModel;
      $urls = $imageModel->getOptimizeUrls();
      $this->data->urls = $urls;
      $this->data->action = 'dumpItem';

   }

   public function newOptimizeAction()
   {
      $imageModel = $this->imageModel;

      /*  $defaults = array(
            'debug_active' => false, // prevent write actions if called via debugger
        ); */

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
      if (isset($data['params']['paths'])) {
         unset($data['params']['paths']);
      }

      foreach ($data['params'] as $sizeName => $param) {
         $plus = false;
         $convertTo = array();
         if ($param['image'] === true) {
            $plus = true;
         }
         if ($param['webp'] === true) {
            $convertTo[] = ($plus === true) ? '+webp' : 'webp';
         }
         if ($param['avif'] === true) {
            $convertTo[] = ($plus === true) ? '+avif' : 'avif';
         }

         foreach ($removeKeys as $key) {
            if (isset($param[$key])) {
               unset($data['params'][$sizeName][$key]);
            }
         }

         if (count($convertTo) > 0) {
            $convertTo = implode('|', $convertTo);
            $data['params'][$sizeName]['convertto'] = $convertTo;
         }
      }

      // CompressionType can be integer, but not empty string. In cases empty string might happen, causing lossless optimization, which is not correct.
      if (!is_null($imageModel->getMeta('compressionType')) && is_numeric($imageModel->getMeta('compressionType'))) {
         $item->compressionType = $imageModel->getMeta('compressionType');
      }

      // Former securi function, add timestamp to all URLS, for cache busting.
      $urls = $this->timestampURLS(array_values($urls), $imageModel->get('id'));
      $item->urls = apply_filters('shortpixel_image_urls', $urls, $imageModel->get('id'));

      if (count($data['params']) > 0) {
         $item->paramlist = array_values($data['params']);
      }

      if (count($data['returnParams']) > 0) {
         $item->returndatalist = $data['returnParams'];
      }

      $item->counts = $counts;

      // Converter can alter the data for this item, based on conversion needs
      $converter = Converter::getConverter($imageModel, true);
      if ($baseCount > 0 && is_object($converter) && $converter->isConvertable()) {
         $converter->filterQueue($item, ['debug_active' => $this->debug_active]);
      }

      $this->data = $item;
      $this->data->action = 'optimize';

      //return $item;
   }

   public function requestAltAction()
   {
      $item = new \stdClass;
      $item->url = $this->imageModel->getUrl();
      $item->tries = 0;

      $this->item_count = 1;
      $this->data = $item;
      $this->data->action = 'requestAlt'; // For Queue
   }

   public function retrieveAltAction($remote_id)
   {
      $item = new \stdClass;
      $item->remote_id = $remote_id;
      $item->item_count = 1;
      $this->data = $item;
      $this->data->action = 'retrieveAlt';

   }

   public function getAPIController() // @todo Move to QueueItem, or QUeueItems ?
   {
      $api = false;
      $action = $this->data()->action;
      switch ($action) {
         case 'optimize':
         case 'dumpItem':
            $api = OptimizeController::getInstance();
         break;
         case 'requestAlt': // @todo Check if this is correct action name,
         case 'retrieveAlt':
            $api = OptimizeAiController::getInstance();
            break;
         case 'restore':
         case 'reoptimize': 
         case 'migrate':
         case 'png2jpg':
         case 'removeLegacy':
            $api = ActionController::getInstance();
         break;
      }

      return $api;
   }

   protected function timestampURLS($urls, $id)
   {
      // https://developer.wordpress.org/reference/functions/get_post_modified_time/
      $time = get_post_modified_time('U', false, $id);
      foreach ($urls as $index => $url) {
         $urls[$index] = add_query_arg('ver', $time, $url); //has url
      }

      return $urls;
   }


   public function checkImageModelExists()
   {
      if (is_null($this->imageModel) || false === is_object($this->imageModel)) {
         return false;
      }
      return true;
   }

} // class
