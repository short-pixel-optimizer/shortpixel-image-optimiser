<?php
namespace ShortPixel\Model\Queue;

if (!defined('ABSPATH')) {
   exit; // Exit if accessed directly.
}
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Model\Converter\Converter as Converter;

use ShortPixel\Controller\Optimizer\OptimizeController as OptimizeController;
use ShortPixel\Controller\Optimizer\OptimizeAiController as OptimizeAiController;
use ShortPixel\Controller\Optimizer\ActionController as ActionController;
use ShortPixel\Helper\UiHelper;
use ShortPixel\Model\AiDataModel;
use stdClass;

/**
 * One slot in the ShortPixel queue — binds an image (ImageModel) to a pending
 * action, the input data used to process it (QueueItemData), and the result
 * produced by that processing (QueueItemResult).
 *
 * Only the `data` object is persistent: `toObject()` on it is what
 * returnEnqueue() serialises to the underlying shortq storage. The image
 * model and result are rehydrated / rebuilt on each pull from the queue.
 *
 * The `new*Action()` methods are the entry points for scheduling work — each
 * one calls `newAction()` first to wipe the current result and reset the
 * data object (while carefully preserving any queued next-actions plus
 * fields listed on next_keepdata), then populates the fields that action
 * specifically needs. `getAPIController()` routes on the current action to
 * pick the right Optimizer/Ai/Action controller when a worker pulls the item.
 *
 * @package ShortPixel\Model\Queue
 */
class QueueItem
{

   /** @var ImageModel|null The image this queue slot is optimizing / acting on. */
   protected $imageModel;
   /** @var int|null Attachment or custom-image ID; also the queue slot's identity. */
   protected $item_id;
   /** @var object|null Raw envelope object handed back from the underlying shortq layer. */
   protected $queueItem;

   /** @var QueueItemResult|null Result payload for the current action; created lazily by result(). */
   protected $result;

   /** @var QueueItemData The persistent per-item data (URLs, params, action, counters). This is the ONLY field written back to the queue. */
   protected $data;

   /** @var int|null "Credit" count for the item — used by the bulk table to show progress. */
   protected $item_count;

   /** @var bool True when the item was constructed from the edit-media debug view so mutating side-effects are suppressed. */
   protected $debug_active = false;

   /**
    * Constructor.
    *
    * Accepts either a bound ImageModel or a bare item_id — the two shapes
    * are used interchangeably at different points in the pipeline. Also
    * unconditionally seeds `$data` with an empty QueueItemData so callers
    * can always do `$item->data()->foo = 'bar'` without a null check.
    *
    * @param array{imageModel?: ImageModel, item_id?: int} $args Initialisation payload.
    */
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

   /** Sets required ImageModel to the QueueItem
    * 
    * @param ImageModel $imageModel 
    * @return void 
    */
   public function setModel(ImageModel $imageModel)
   {
      $this->imageModel = $imageModel;
      $this->item_id = $imageModel->get('id');
   }

   /** Sets the data in QueueItem from data coming from the Shortpixel Queue 
    * 
    * @param mixed $data 
    * @return void 
    */
   public function setFromQueueData($data)
   {
      foreach($data as $name => $value)
      {
          $this->setData($name, $value);
      }
   }

   /** Sets information to the (persistent) data object, which is saved in ShortPixel Queue
    * 
    * @param string $name 
    * @param mixed $value 
    * @return void 
    */
   public function setData($name, $value)
   {
      $this->data->$name = $value;
   }

   /** Without parameters returns the current block status of this item, otherwise applied required block ( true / false )
    * 
    * @param boolean $block 
    * @return boolean|void     
    */
   public function block($block = null)
   {
      if (is_null($block)) {
            return $this->data->block;
      } else {
         $this->setData('block', (bool) $block);
      }
   }

   /** Returns QueueItemData object for functions requiring this information 
    * 
    * @return QueueItemData 
    */
   public function data() : QueueItemData
   {
      return $this->data;
   }

   /** Returns result object which can be interpreted by UI . Creates it if null 
    * 
    * @return QueueItemResult 
    */
   public function result() : QueueItemResult
   {
      if (is_null($this->result))
      {
         $this->result = new QueueItemResult($this->item_id);
      }

      return $this->result;
   }

   /** Sets value of a property. 
    * 
    * @param string $name 
    * @param mixed $value 
    * @return void 
    */
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

   /** Gets property value by name, null if does not exists. 
    * 
    * @param mixed $name 
    * @return mixed 
    */
   public function __get($name)
   {
      if (property_exists($this, $name)) {
         return $this->$name;
      }


      return null;
   }

   /** Return Array representation of this object, which is used to enqueue the Item.
    * 
    * @return array{id: int, value: object, item_count: mixed, order: mixed} 
    */
   public function returnEnqueue()
   {
      $value = $this->data->toObject();

      $item_id = $this->item_id; 

      // ImageModel could not be set i.e. migrate or other special actions.
      // @note This code doesn't do anything with media_id ? 
      /*
      if (is_object($this->imageModel) && $this->imageModel->getParent() !== false) {
         $media_id = $this->imageModel->getParent();
      } */

      $enqueue = ['id' => $item_id, 'value' => $value, 'item_count' => $this->item_count];
      
      if (! is_null($this->data->queue_list_order))
      {
         $enqueue['order'] = $this->data->queue_list_order;
      }

      return $enqueue; 
   }

   /** Set debug flag, used in edit-media debug info.
    * 
    * @return void 
    */
   public function setDebug()
   {
      $this->debug_active = true;
   }

   /** Initiate new migrate action
    * 
    * @return void 
    */
   public function newMigrateAction()
   {
      $this->newAction(); 

      $this->data->action = 'migrate';
      $this->item_count = 1;
   }

   /**
    * Schedule this slot to restore its image from backup.
    *
    * item_count is 1 — restore always operates on the whole family in one
    * shot, regardless of thumbnail count.
    *
    * @return void
    */
   public function newRestoreAction()
   {
      $this->newAction();

      $this->data->action = 'restore';
      $this->item_count = 1;
   }

   /**
    * Schedule this slot to fetch AI-generated alt data for the image.
    *
    * item_count is 0 because this operation is metadata-only — it does not
    * consume optimization credits.
    *
    * @return void
    */
   public function getAltDataAction()
   {
       $this->newAction();
       $this->data->action = 'getAltData';

       $this->item_count = 0;
   }

   /**
    * Schedule this slot for re-optimization with (optionally) a new
    * compression type and/or smartcrop policy.
    *
    * The action is a two-step: `reoptimize` first (which typically restores
    * the image so the fresh optimization has a clean source), followed by
    * `optimize` — chained via next_actions. Both `compressionType` and
    * `smartcrop` are added to keep_data so they survive newAction() when
    * the `optimize` step runs.
    *
    * @param array{compressionType?: int, smartcrop?: bool} $args Overrides for the re-optimization.
    * @return void
    */
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

   /**
    * Schedule this slot to strip legacy (pre-shortpixel_postmeta) SPIO
    * metadata from the attachment.
    *
    * @return void
    */
   public function newRemoveLegacyAction()
   {
      $this->newAction();

      $this->data->action = 'removeLegacy';
      $this->item_count = 1;
   }

   /**
    * Merge an associative array of result fields onto the current result
    * object, passing each through QueueItemResult's magic mutator so
    * unknown keys are logged and dropped.
    *
    * @param array<string, mixed> $data Result-shape entries keyed by field name.
    * @return void
    *
    * @todo Move this to QueueItemResult with proper per-field validation.
    */
   public function addResult($data = [])
   {
      // Should list every possible item, arrayfilter out.
/*      $validation = [
         'apiStatus', 
         'message',
         'is_error',
         'is_done',
         'file',  // should probably be merged these two.
         'files',
         'fileStatus',
         'filename', // @todo figure out why this is here.
         'error',  // might in time better be called error_code or so
         'new_attach_id', // new attach id for background remove.
         'success', // new
         'improvements',
         'original',
         'optimized',
         'redirect', // Redirection for background remove etc 
         'queueType', // OptimizeController but (?) usage
         'kblink',
         'data', // Is returnDataList returned by apiController. (array)
    //     'retrievedText', // Ai text returning from AIController  //  @todo Can probably be removed on release. 
         'apiName', // NAme of the handling api, for JS / Response to show different results.
         'remote_id', 
         'aiData',   // Returning AI Data

      ];
*/

      foreach ($data as $name => $value) {
         $this->result()->$name = $value;
      }

   }


   /** Clean several aspects of this object ( result, other things ) before triggering a new action. 
    * 
    * Since QItem is mostly passed by reference 
    * @return void 
    */
   protected function newAction()
   {
       $this->result = new QueueItemResult($this->item_id); // new action, new results 

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
      //$counts->thumbCount = 
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
      // @todo This filter name should be changed to the new standard. 
      $this->data->urls = apply_filters('shortpixel_image_urls', $urls, $item_id);

      if (count($optimizeData['params']) > 0) {
         $this->data->paramlist = array_values($optimizeData['params']);
      }

      if (count($optimizeData['returnParams']) > 0) {
         $this->data->returndatalist = $optimizeData['returnParams'];
      }

      $this->data()->addCount($counts);
     // $this->data->counts = $counts;

      // Converter can alter the data for this item, based on conversion needs
      $converter = Converter::getConverter($imageModel, true);
      if ($baseCount > 0 && is_object($converter) && $converter->isConvertable()) {
         $converter->filterQueue($this, ['debug_active' => $this->debug_active]);
      }

   }

   /**
    * Schedule this slot to submit an AI alt-text request to the ShortPixel
    * AI backend.
    *
    * Reads its payload from AiDataModel::getOptimizeData($args) and stores
    * both the `paramlist` (arguments for the API call) and the
    * `returndatalist` (data echoed back verbatim). Chains a `retrieveAlt`
    * next-action so a subsequent worker pass can fetch the result — unless
    * `preview_only=true`, in which case no chained retrieval is scheduled.
    *
    * If `recent_upload=true` is passed, that flag is added to keep_data so
    * it propagates onto the chained retrieveAlt action.
    *
    * @param array{preview_only?: bool, recent_upload?: bool} $args Options; forwarded verbatim to AiDataModel::getOptimizeData().
    * @return void
    */
   public function requestAltAction($args = [])
   {
      $this->newAction();
      $this->data->urls = [$this->imageModel->getUrl()];
      $this->data->tries = 0;
      $this->item_count = 1;

      $item_id = $this->imageModel->get('id');

      $paramlist = []; 

      $preview_only = false; 
      if (isset($args['preview_only']) && true == $args['preview_only'])
      {
         $paramlist['preview_only'] = true;
         $preview_only = true; 
         $this->data()->addKeepDataArgs('preview_only');
      } 

      $aiDataModel = AiDataModel::getModelByAttachment($item_id, $this->imageModel->get('type'));
      
      $data = $aiDataModel->getOptimizeData($args);

      if (isset($data['paramlist']))
      {
         $this->data()->paramlist = $data['paramlist'];
      }
      if (isset($data['returndatalist']))
      {
         $this->data()->returndatalist = $data['returndatalist'];
         $this->data()->addKeepDataArgs(['returndatalist']);
      }

      if (isset($args['recent_upload']) && true === $args['recent_upload'])
      {
         $this->data()->addKeepDataArgs(['recent_upload']);
      }

      if (isset($data['paramlist']['languages']))
      {
          $this->data()->addKeepDataArgs('languages');
      }

      $this->data->addCount(['aiCount' => 1]); // @todo Check if this is really a one credito operation.

      $this->data->action = 'requestAlt'; // For Queue

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

   /**
    * Schedule this slot to retrieve a previously-submitted AI alt-text
    * result using the remote reference id captured during requestAltAction().
    *
    * Resets `tries` to 0 so the retry counter for this stage starts fresh.
    *
    * `remote_id` is optional at the argument level (missing keys default to
    * null instead of raising an undefined-index notice), but callers should
    * pass the value returned by the request stage — a null remote_id is
    * stored verbatim on `$this->data->remote_id`, and the API-side lookup
    * will fail without it.
    *
    * @param array{remote_id?: string|int, returndatalist?: array} $args Options; `remote_id` should normally be the value returned by the request stage.
    * @return void
    */
   public function retrieveAltAction($args)
   {
      $this->newAction();

      $remote_id = isset($args['remote_id']) ? $args['remote_id'] : null;
      
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
    * Schedule this slot for a background-removal (or background-replacement)
    * operation via the ShortPixel API.
    *
    * Picks the source image intelligently: previews route through
    * UiHelper::findBestPreview() with a 600px target for speed; real runs
    * use the unscaled original when the attachment is `-scaled`.
    *
    * Payload details:
    *   - `bg_remove=1` when `do_transparent=true`.
    *   - Otherwise `bg_remove=<hex-color>+<hex-alpha>` where alpha is
    *     zero-padded (00 through FF; 100 maps to FF).
    *   - `newFileName` defaults to `<base>_nobg<ext>` when not specified.
    *   - Compression is forced to LOSSLESS because the payload includes
    *     alpha data that must not be re-quantised.
    *
    * @param array{do_transparent?: bool, replace_color?: string|null, replace_transparency?: string|int, url?: string|null, is_preview?: bool, newFileName?: string|null, newPostTitle?: string, refresh?: bool, attached_post_id?: int|null} $args See defaults inside the method.
    * @return void
    */
   public function newRemoveBackgroundAction($args)
   {
       $this->newAction(); 

       $defaults = [
            'do_transparent' => true, 
            'replace_color' => null, 
            'replace_transparency' => '00', 
            'url' => null, 
            'is_preview' => false, 
            'newFileName' => null, 
            'newPostTitle' => '', 
            'refresh' => false, 
            'attached_post_id' => null,
       ]; 

       $paramlist = []; 
       $args = wp_parse_args($args, $defaults);

       $paramlist['preview_only'] = $args['is_preview'];

       if (true === $args['is_preview'])
       {
          $originalFile = UIHelper::findBestPreview($this->imageModel, 600); // Speed up previews by using small image (?) 
       }
       else
       {
         $originalFile = $this->imageModel; 
         if ($this->imageModel->isScaled())
         {
            $originalFile = $this->imageModel->getOriginalFile(); 
         }
      }
       $url = $originalFile->getUrl(); 

       if (true === $args['do_transparent'])
       { 
         $paramlist['bg_remove'] = 1; 
       }
       else
       {
         $color = $args['replace_color']; 
         $transparency = $args['replace_transparency']; 

         $paramlist['bg_remove'] = $color;
         if ($transparency >= 0 && $transparency < 100)
			{
				if ($transparency == 100)
					$transparency = 'FF';

			  // Strpad for lower than 10 should add 09, 08 etc.
				 $transparency = str_pad($transparency, 2, '0', STR_PAD_LEFT);
             $paramlist['bg_remove'] .= $transparency;
         }
         
       }

       if (false === is_null($args['newFileName']) && strlen($args['newFileName']) > 0)
       {
          $paramlist['newFileName'] = $args['newFileName']; 
       }
       else
       {
          $paramlist['newFileName'] = $originalFile->getFileBase() . '_nobg' . $originalFile->getExtension(); 
       }

       if (! is_null($args['attached_post_id']) && $args['attached_post_id'] > 0)
       {
          $paramlist['attached_post_id'] = $args['attached_post_id'];
       }

       $paramlist['newPostTitle'] = $args['newPostTitle'];

       $paramlist['refresh'] = $args['refresh']; // When sending item first, do the refresh. This is the mimc the tries = 0 refresh option we don't have here. 
       
       $returndatalist = [$this->imageModel->getImageKey() => $this->imageModel->getFileName()];
       
       $this->data->action = 'remove_background'; 
       $this->data->compressionType = ImageModel::COMPRESSION_LOSSLESS;
       $this->data->urls = [$url];
       $this->data->returndatalist = $returndatalist;
       
       $this->data->paramlist = $paramlist; 
       $this->data->tries = 0;
       $this->item_count = 1;

   }

   /**
    * Schedule this slot for an image-upscale operation via the ShortPixel
    * API.
    *
    * Sources the unscaled original when the attachment is `-scaled`,
    * mirroring newRemoveBackgroundAction's choice. Compression is forced to
    * LOSSLESS. Defaults `newFileName` to `<base>_noscale<ext>`.
    *
    * @param array{url?: string|null, is_preview?: bool, newFileName?: string|null, newPostTitle?: string, refresh?: bool, attached_post_id?: int|null, scale?: int|string|null} $args See defaults inside the method.
    * @return void
    */
   public function newScaleImageAction($args = [])
   {
      $this->newAction();

      $defaults = [
           'url' => null, 
           'is_preview' => false, 
           'newFileName' => null, 
           'newPostTitle' => '', 
           'refresh' => false, 
           'attached_post_id' => null,
           'scale' => null, 
      ]; 

      $paramlist = []; 
      $args = wp_parse_args($args, $defaults);

      $paramlist['preview_only'] = $args['is_preview'];

      $originalFile = $this->imageModel; 
      if ($this->imageModel->isScaled())
      {
         $originalFile = $this->imageModel->getOriginalFile(); 
      }
      
      $url = $originalFile->getUrl(); 

      if (false === is_null($args['newFileName']) && strlen($args['newFileName']) > 0)
      {
         $paramlist['newFileName'] = $args['newFileName']; 
      }
      else
      {
         $paramlist['newFileName'] = $originalFile->getFileBase() . '_noscale' . $originalFile->getExtension(); 
      }

      $paramlist['newPostTitle'] = $args['newPostTitle'];

      $paramlist['refresh'] = $args['refresh']; // When sending item first, do the refresh. This is the mimc the tries = 0 refresh option we don't have here. 
      $paramlist['upscale'] = $args['scale'];

      if (! is_null($args['attached_post_id']) && $args['attached_post_id'] > 0)
      {
         $paramlist['attached_post_id'] = $args['attached_post_id'];
      }

      $returndatalist = [$this->imageModel->getImageKey() => $this->imageModel->getFileName()];
      
      $this->data->action = 'scale_image'; 
      $this->data->compressionType = ImageModel::COMPRESSION_LOSSLESS;
      $this->data->urls = [$url];
      $this->data->returndatalist = $returndatalist;
      
      $this->data->paramlist = $paramlist; 
      $this->data->tries = 0;
      $this->item_count = 1;
      
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
         case 'remove_background': 
         case 'scale_image':
            $api = OptimizeController::getInstance();
         break;
         case 'requestAlt': // @todo Check if this is correct action name,
         case 'retrieveAlt':
         case 'getAltData': 
         case 'undoAI': 
         case 'redoAI': 
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


   /**
    * Whether this slot has an ImageModel bound to it.
    *
    * Queue slots can be constructed with just an item_id (see the
    * constructor), so callers that need to act on the image itself must
    * check this first.
    *
    * @return bool
    */
   public function checkImageModelExists()
   {
      if (is_null($this->imageModel) || false === is_object($this->imageModel)) {
         return false;
      }
      return true;
   }

} // class
