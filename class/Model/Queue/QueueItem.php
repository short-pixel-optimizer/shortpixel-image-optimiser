<?php
namespace ShortPixel\Model\Queue;

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
use ShortPixel\Model\AiDataModel;

class QueueItem
{

   protected $imageModel; // ImageModel 
   protected $item_id; // Item Id 
   protected $queueItem; // Object coming from WPQ

   protected $result; // Result object stores a viable customer response.

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
      $this->data = new QueueItemData(); // init
   }

   public function setModel(ImageModel $imageModel)
   {
      $this->imageModel = $imageModel;
      $this->item_id = $imageModel->get('id');
   }

   public function setFromData($data)
   {
      foreach($data as $name => $value)
      {
          $this->setData($name, $value);
      }
   }

   public function setData($name, $value)
   {
      $this->data->$name = $value;
   }

   public function block($block = null)
   {
      if (is_null($block)) {
            return $this->data->block;
      } else {
         $this->setData('block', (bool) $block);
      }
   }

   public function data()
   {
      return $this->data;
   }

   public function result()
   {

      if (is_null($this->result))
      {
         $this->result = new \stdClass;
      }

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

   /** Return Data that need's storing in Queue Table
    * 
    *
    * @return Object|boolean 
    */
   public function getQueueItem()
   {
      if (is_null($this->queueItem))
      {
          return false; 
      }

      $qItem = $this->queueItem;
      $qItem->value = $this->data->toObject();
      return $qItem;
   }

   public function __get($name)
   {
      if (property_exists($this, $name)) {
         return $this->$name;
      }


      return null;
   }

   public function returnArray()
   {
      $array = [
         'item_id' => $this->item_id,
         'result' => $this->result,
         'data' => $this->data->toObject(),
      ];

      return $array;
   }

   public function returnObject()
   {
      $object = new \stdClass;
      $object->item_id = $this->item_id;
      $object->result = $this->result;
      $object->data = $this->data->toObject();

      return $object;
   }

   public function returnEnqueue()
   {
      $value = $this->data->toObject();

      $item_id = $this->item_id; 

      // ImageModel could not be set i.e. migrate or other special actions.
      if (is_object($this->imageModel) && $this->imageModel->getParent() !== false) {
         $media_id = $this->imageModel->getParent();
      }

      $enqueue = ['id' => $item_id, 'value' => $value, 'item_count' => $this->item_count];
      
      if (! is_null($this->data->queue_list_order))
      {
         $enqueue['order'] = $this->data->queue_list_order;
      }

      return $enqueue; 
      
   }

   public function setDebug()
   {
      $this->debug_active = true;
   }

   public function newMigrateAction()
   {
      $this->newAction(); 

      $this->data->action = 'migrate';
      $this->item_count = 1;
   }

   public function newRestoreAction()
   {
      $this->newAction(); 

      $this->data->action = 'restore';
      $this->item_count = 1;
   }

   public function getAltDataAction()
   {
       $this->newAction(); 
       $this->data->action = 'getAltData'; 
       
       $this->item_count = 0; 
   }

   public function newReOptimizeAction($args = [])
   {
      $this->newAction(); 

       $this->data->action = 'reoptimize'; 
       $this->data->next_actions = ['optimize'];
       $this->data->addKeepDataArgs(['compressionType', 'smartcrop']); // Each action it's own set of keep data.
       $this->item_count = 1;

       // Smartcrop setting (?) 
       if (isset($args['smartcrop']))
       {
          $this->data()->smartcrop = $args['smartcrop']; 
       }

       // Then new compresion type to optimize to. 
       if (isset($args['compressionType'])) 
       {
          $this->data()->compressionType = $args['compressionType'];
       }

       
   }

   public function newRemoveLegacyAction()
   {
      $this->newAction(); 

      $this->data->action = 'removeLegacy';
      $this->item_count = 1;
   }

   public function addResult($data = [])
   {
      // Should list every possible item, arrayfilter out.
      $validation = [
         'apiStatus', 
         'message',
         'is_error',
         'is_done',
         'file',  // should probably be merged these two.
         'files',
         'fileStatus',
         'filename', // @todo figure out why this is here.
         'error',  // might in time better be called error_code or so
         'success', // new
         'improvements',
         'original',
         'optimized',
         'queueType', // OptimizeController but (?) usage
         'kblink',
         'data', // Is returnDataList returned by apiController. (array)
    //     'retrievedText', // Ai text returning from AIController  //  @todo Can probably be removed on release. 
         'apiName', // NAme of the handling api, for JS / Response to show different results.
         'remote_id', 
         'aiData',   // Returning AI Data

      ];


      if (is_null($this->result)) {
         $this->result = new \stdClass;
      }


      foreach ($data as $name => $value) {
         if (false === in_array($name, $validation)) {
            Log::addWarn("Result $name not in validation");
         }

         $this->result->$name = $value;
      }

   }


   /** Clean several aspects of this object ( result, other things ) before triggering a new action. 
    * 
    * Since QItem is mostly passed by reference 
    * @return void 
    */
   protected function newAction()
   {
       $this->result = new \stdClass; // new action, new results 

       if ($this->data()->hasNextAction()) // Keep this at all times / not optimal still
       {
          $nextActions = $this->data()->next_actions; 
       } 

       $keepDataArgs = $this->data()->getKeepDataArgs();
       $next_keepdata = $this->data()->next_keepdata; 


       $this->data = new QueueItemData(); // new action, new data(?)

       if (isset($nextActions))
       {
         $this->data()->next_actions = $nextActions;


       }

      // Always pass
      if (count($keepDataArgs) > 0)
      {
         $this->data()->next_keepdata = $next_keepdata;
         foreach($keepDataArgs as $name => $value)
         {
               $this->data()->$name = $value;
         }

      }

   }

   /** Action for dunping (removing from cache) for image URLS's so optimization will be redone.
    * 
    * @return void 
    */
   public function newDumpAction()
   {
      $this->newAction(); 

      $imageModel = $this->imageModel;
      $urls = $imageModel->getOptimizeUrls();
      $this->data->urls = $urls;
      $this->data->action = 'dumpItem';

   }

   /** Start optimize action 
    * 
    * @param array $args  Arguments and settings
    * @return void 
    */
   public function newOptimizeAction($args = [])
   {
      $this->newAction(); 

      $imageModel = $this->imageModel;
      $item_id = $imageModel->get('id');

      /*  $defaults = array(
            'debug_active' => false, // prevent write actions if called via debugger
        ); */
      
      if (isset($args['compressionType'])) 
      {
          $this->data()->compressionType = $args['compressionType'];
      }
      elseif (is_null($this->data()->compressionType))
      {
         $this->data()->compressionType = \wpSPIO()->settings()->compressionType;
      }

      if (isset($args['smartcrop'])) 
      {
         $imageModel->doSetting('smartcrop', $args['smartcrop']);
      }
      elseif (! is_null($this->data()->smartcrop))
      {
         $imageModel->doSetting('smartcrop', $this->data()->smartcrop);
      }

      $this->data->action = 'optimize'; 

      $optimizeData = $imageModel->getOptimizeData();
      $urls = $optimizeData['urls'];

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
      if (isset($optimizeData['params']['paths'])) {
         unset($optimizeData['params']['paths']);
      }

      foreach ($optimizeData['params'] as $sizeName => $param) {
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
               unset($optimizeData['params'][$sizeName][$key]);
            }
         }

         if (count($convertTo) > 0) {
            $convertTo = implode('|', $convertTo);
            $optimizeData['params'][$sizeName]['convertto'] = $convertTo;
         }

         if (isset($param['url']))
         {
            $url = $this->timestampURLS([$param['url']], $item_id);
            $optimizeData['params'][$sizeName]['url'] = $url[0];
         }
      }

      // CompressionType can be integer, but not empty string. In cases empty string might happen, causing lossless optimization, which is not correct.
      /*if (!is_null($imageModel->getMeta('compressionType')) && is_numeric($imageModel->getMeta('compressionType'))) {
         $this->data->compressionType = $imageModel->getMeta('compressionType');
      }*/

      // Former securi function, add timestamp to all URLS, for cache busting.
      $urls = $this->timestampURLS(array_values($urls), $imageModel->get('id'));
      $this->data->urls = apply_filters('shortpixel_image_urls', $urls, $item_id);

      if (count($optimizeData['params']) > 0) {
         $this->data->paramlist = array_values($optimizeData['params']);
      }

      if (count($optimizeData['returnParams']) > 0) {
         $this->data->returndatalist = $optimizeData['returnParams'];
      }

      $this->data->counts = $counts;

      // Converter can alter the data for this item, based on conversion needs
      $converter = Converter::getConverter($imageModel, true);
      if ($baseCount > 0 && is_object($converter) && $converter->isConvertable()) {
         $converter->filterQueue($this, ['debug_active' => $this->debug_active]);
      }

   }

   public function requestAltAction($args = [])
   {   
      $this->newAction(); 
      $this->data->url = $this->imageModel->getUrl();
      $this->data->tries = 0;
      $this->item_count = 1;

      $item_id = $this->imageModel->get('id');

      $paramlist = []; 

      $preview_only = false; 
      if (isset($args['preview_only']) && true == $args['preview_only'])
      {
         $paramlist['preview_only'] = true;
         $preview_only = true; 
      } 

      $aiDataModel = new AiDataModel($item_id);
      
      $data = $aiDataModel->getOptimizeData($args);

      if (isset($data['paramlist']))
      {
         $this->data()->paramlist = $data['paramlist'];
      }
      if (isset($data['returndatalist']))
      {
         $this->data()->returndatalist = $data['returndatalist'];
         $this->data()->addKeepDataArgs('returndatalist');
      }


      $this->data->action = 'requestAlt'; // For Queue

    //  $optimizer = $this->getAPIController($this->data->action); 
   //   $optimizer->parseJSONForQItem($this, $args);

      if ($this->data()->hasNextAction())
      {
          $next_actions = array_merge(['retrieveAlt'], $this->data()->next_actions);
      }
      else
      {
         $next_actions = ['retrieveAlt'];
      }
      
      if (false === $preview_only)
      {
         $this->data->next_actions = $next_actions;
      }


      
   }

   public function retrieveAltAction($args)
   {
      $this->newAction();

      $remote_id = $args['remote_id'];
      
      if (isset($args['returndatalist']))
      {
         $this->data()->returndatalist = $args['returndatalist'];
      }


      $this->data->remote_id = $remote_id;
      $this->data->tries = 0;
      $this->item_count = 1;
      $this->data->action = 'retrieveAlt';

   }

   /**
    * Get the ApiController associated to the action performed
    * 
    * In future probably should not take data()->action since newActions wipes all of this ( double ? )
    * @return OptimizeBase  optimizer or higher.
    */
   public function getAPIController($action = null) // @todo Move to QueueItem, or QUeueItems ?
   {
      $api = null;
      if (is_null($action))
      {
         $action = $this->data()->action;         
      }

      switch ($action) {
         case 'optimize':
         case 'dumpItem':
         case 'convert_api':
            $api = OptimizeController::getInstance();
         break;
         case 'requestAlt': // @todo Check if this is correct action name,
         case 'retrieveAlt':
         case 'getAltData': 
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
   
   /**
    * Add a timestamp to the URL for cache-prevention.
    *
    * @param array $urls  URL's to timestamp 
    * @param int $id  Item_id to get post time for this.
    * @return array
    */
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
