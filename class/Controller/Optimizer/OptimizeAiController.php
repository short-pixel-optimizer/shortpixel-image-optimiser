<?php
namespace ShortPixel\Controller\Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}


use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Replacer\Replacer;
use ShortPixel\ViewController as ViewController;


// Class for AI Operations.  In time split off OptimizeController / Optimize actions to a main queue runner seperately.
class OptimizeAiController extends OptimizerBase
{
   
  public function __construct()
  {
     parent::__construct();
     $this->api = AiController::getInstance();
     $this->apiName = 'ai';
  }
  
  /** Handle Item errors . Adds to result object
   * 
   * @param QueueItem $qItem 
   * @return void 
   */
  protected function HandleItemError(QueueItem $qItem) { 

      // Change to chance the result / message with specific errors. 
      switch($qItem->result()->apiStatus)
      {
          case '422' :  // Unprocessable Item 
              // No different message than API 
          break; 
        }

      return;
  }


  public function sendToProcessing(QueueItem $qItem) { 

/*    if (false == $this->isSupported($qItem))
    {
        // For now only fail here is GIF support, so message is a backstop for now that later should be updated. 
        $qItem->addResult([
            'is_error' => true, 
            'is_done' => true,
            'message' => __('AI data cannot be generated for GIF files by ShortPixel AI, for now', 'shortpixel-image-optimiser'), 
            'apiStatus' => AiController::AI_STATUS_INVALID_URL,
        ]); 

    }
    else
    { */
        $this->api->processMediaItem($qItem, $qItem->imageModel);
    //}
 
  }

// @todo Probably here should check if Alt item is already generated . 
  public function checkItem(QueueItem $qItem) 
  {     

      $aiDataModel = AiDataModel::getModelByAttachment($qItem->item_id); 
      $is_processable = $aiDataModel->isProcessable();

      if (false === $is_processable) {
         $message = $aiDataModel->getProcessableReason(); 
        $qItem->addResult([
          'message' => $message,
          'is_error' => true,
          'is_done' => true,
          'fileStatus' => ImageModel::FILE_STATUS_ERROR,
        ]);
      }

      return $is_processable;
  }

  public function enqueueItem(QueueItem $qItem, $args = [])
  {

    $action = $args['action']; 
    $queue = $this->getCurrentQueue($qItem);

    // For loading AI Preview on settings page.
    $preview_only = isset($args['preview_only']) ? $args['preview_only'] : false; 

    switch($action)
    {
        case 'requestAlt': 
           $qItem->requestAltAction($args);
          // $this->parseJSONForQItem($qItem, $args); 
           $directAction = false; 
        break;
        case 'retrieveAlt':  // This might be deprecated, since retrieve will be called via next_action. 
            $qItem->retrieveAltAction($args);
            $directAction = false; 
        break; 
        default: 
            Log::addError('no AI controller action found!');
            $qItem->addResult([
                'message' => 'Wrong action in AiController!', 
                'is_error' => true, 
                'is_done' => true, 
            ]);
            return $qItem->result();
        break; 
    }

    if (true === $preview_only)
    {
        $directAction = true;
    }

    // @todo This is probably out of use for good, already. 
    if (true === $directAction)
    {
       // The directActions give back booleans, but the whole function must return an queue result object with qstatus and numitems
       $this->sendToProcessing($qItem);
       $this->handleAPIResult($qItem);
      
       $result = $qItem->result();
 
        // Probably not as is should be, but functional
       if ($result->is_error === false)
       {
          //  $result = new \stdClass; 
            $result->qstatus = Queue::RESULT_ITEMS;
            $result->numitems = 1;
            if ($qItem->result()->message == '')
            {
                $qItem->addResult([
                'message' => __('Request for image SEO data sent to Shortpixel AI', 'shortpixel-image-optimiser')]);
            }
        }
        else
        {
            $result->numitems = 0;
        }

    }
    else
    {
      if (isset($args['queue_list_order']))
      {
         $qItem->setData('queue_list_order', $args['queue_list_order']);
         $qItem->data()->addKeepDataArgs('queue_list_order');
      }
      $result = $queue->addQueueItem($qItem);
    }

    return $result;
  }


  public function handleAPIResult(QueueItem $qItem)
  {
      $queue = $this->currentQueue;

      $qItem->addResult(['apiName' => $this->apiName]);
      $apiStatus = $qItem->result()->apiStatus;

      if ($qItem->result()->is_error)  {
       
        if (true === $qItem->result()->is_done )
        {
            Log::addDebug('Item failed, has error on done ', $qItem->result());
            $queue->itemFailed($qItem, true);
            $this->HandleItemError($qItem);
        }
        else // Do nothing for now / retry (?)
        {

        }

        return; 
      }
      
      // Result for requestAlt 
      if ($apiStatus == RequestManager::STATUS_WAITING)
      {
        return; 
      }
      elseif (property_exists($qItem->result(), 'remote_id'))
      {
          $remote_id = $qItem->result()->remote_id;
          Log::addTemp('Remote ID fetched: ' . $remote_id);
          
          $this->finishItemProcess($qItem, ['remote_id' => $remote_id]);
      }
      else
      {
          if ($qItem->data()->action == 'requestAlt')
          {
              Log::addError('RequestAlt result without remote_id', $qItem->result() );
              $queue->itemFailed($qItem, true);
              $this->HandleItemError($qItem);
              return; 
          }
      }

      // Result for retrieveAlt
      if (property_exists($qItem->result(), 'aiData'))
      {
            return $this->HandleSuccess($qItem);
      }


  }

  public function formatResultData($aiData, $qItem)
  {
    // Always save the original filename
    $aiData['original_filebase'] = $qItem->imageModel->getFileBase();
    $returnDataList = $qItem->data()->returndatalist; 

    if (! isset($aiData['filebase']))
    {
         $aiData['filebase'] = $aiData['original_filebase']; 
    }
    

    $textItems = ['alt', 'caption', 'description'];
    foreach($textItems as $textItem)
    {
      
         if (isset($aiData[$textItem]) && false !== $aiData[$textItem] && false === is_numeric($aiData[$textItem]))
         {
             $aiData[$textItem] = $this->processTextResult($aiData[$textItem]);
         }
         // If 1 is returned as data, this means for some reason the API didn't create a text for this field, while it is allowed to do so. Defer to empty string better than '1' 
         if (true === is_numeric($aiData[$textItem]) && 1 == $aiData[$textItem])
         {
            $aiData[$textItem] = ''; 
         }
    }   

    // Re-add Result after formatting so it passed back
    //$qItem->addResult(['aiData' => $aiData]);


    return $aiData; 
  }

  protected function HandleSuccess(QueueItem $qItem)
  {
        $aiData = $qItem->result->aiData;  
        $settings = \wpSPIO()->settings();

        $checks = ['alt' => 'ai_gen_alt', 
        'caption' => 'ai_gen_caption', 
        'description' => 'ai_gen_description',
        'filename' => 'ai_gen_filename',
        ];

        $aiData = $this->formatResultData($aiData, $qItem);

        // Description : From POST CONTENT 
        // Caption : From POST EXCERPT 
        // Alt  : Own Metadata field 
        $item_id = $qItem->item_id; 
        
        $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');
        $aiModel->handleNewData($aiData);

        $qItem->addResult([
//          'retrievedText' => $text,
          'apiStatus' => RequestManager::STATUS_SUCCESS,
          'fileStatus' => ImageModel::FILE_STATUS_SUCCESS
        ]);

        $aiData['replace_filebase'] = $aiData['original_filebase'];

        $this->replaceImageAttributes($qItem, $aiData); 

       /* Feature off for now - This DOES NOT YET work 
        if ($qItem->result()->filename)
        {
            $this->replaceFiles($qItem, $qItem->result()->filename);
        }
        */

        $imageModel = $qItem->imageModel;
        $qItem->addResult(['improvements' => $imageModel->getImprovements()]); // Improvements for bulk UX. 

        $this->addPreview($qItem); // Preview ( image ) for bulk UX 

        AiDataModel::flushModelCache($item_id);

        // Get generated data which is the final result for the action including exclusions etc. 
        $data = $this->getAltData($qItem); 
        $qItem->addResult(['aiData' => $data['generated']]); // But the generated data in the result.

        $this->finishItemProcess($qItem);
        return;
  }

  /** Replace Image Attributes ( others? ) on images via BaseURL 
   * 
   * The finder is passed a callback to which the results will be returned.  
   * 
   * @param QueueItem $qItem 
   * @param mixed $new_text The new text 
   * @return void 
   */
  protected function replaceImageAttributes(QueueItem $qItem, $aiData)
  {
            if (is_int($aiData['alt']) && is_int($aiData['caption']))
            {
                Log::addInfo('Alt/Caption returned integer/status, not replace'); 
                return; 
            }

             // Replacer Part 
             $url = $qItem->data()->url; 
             if (is_null($url)) // can be empty on restore action 
             {
                 $url = $qItem->imageModel->getUrl(); 
             }

             $replacer2 = \ShortPixel\Replacer\Replacer::getInstance(); 
             $setup = $replacer2->Setup(); 
             $setup->forSearch()->URL()->addData($url);
             
             $base_url = $setup->forSearch()->URL()->getBaseURL();
     
             $finder = $replacer2->Finder(['base_url' => $base_url, 'callback' => [$this, 'handleReplace'], 'return_data' => [
                 'aiData' => $aiData, 
                 'qItem' => $qItem,
             ]]);
     
             $finder->posts();

  }

  protected function replaceFiles($qItem, $newFileName)
  {
      $imageModel = $qItem->imageModel; 
      $item_id = $qItem->item_id; 

      $files = $imageModel->getAllFiles();
      $fs = \wpSPIO()->filesystem();

      if (isset($files['files'][$imageModel->getImageKey('original')]))
      {
         $baseFileObj = $files['files'][$imageModel->getImageKey('original')];
      }
      else
      {
        $baseFileObj = $files['files'][$imageModel->getImageKey('main')];
      }

      $source_url = $url = $baseFileObj->getURL();
      $base_filename = $baseFileObj->getFileBase();

      $base_url = parse_url($url, PHP_URL_PATH);
      $base_url = str_replace('.' . pathinfo($base_url, PATHINFO_EXTENSION), '', $base_url);
      $base_url = str_replace($base_filename, '', $base_url);

      $target_url = str_replace($base_filename, $newFileName, $source_url);


      $searchArray = $replaceArray = $sourceFiles = $targetFiles = []; 
    
      foreach($files['files'] as $key => $fileObj)
      {
          $searchArray[$key] = $base_url . $fileObj->getFilename(); 
          $replaceArray[$key] = $base_url . $newFileName . '.' . $fileObj->getExtension(); 
          $sourceFiles[$key] = $fileObj; 
          
          $filename = str_replace($base_filename, $newFileName, $fileObj->getFileName());
          $targetFiles[$key] = $fileObj->getFileDir() . $filename . '.' . $fileObj->getExtension(); 

      }

      if (count($files['webp']) > 0)
      {
         foreach($files['webp'] as $key => $fileObj)
         {
            $searchArray['webp_' . $key] = $base_url . $fileObj->getFileName(); 
            $replaceArray['webp_' . $key] = $base_url . $newFileName . $fileObj->getExtension(); 
            $sourceFiles['webp_' . $key] = $fileObj; 
            $targetFiles['webp_' . $key] =  $fileObj->getFileDir() . $newFileName . '.' . $fileObj->getExtension();
          }
      }


      $targetFileObjs = []; // if we have to check them all anyhow, store it for moving / deleting. 
      foreach($targetFiles as $key => $target_path)
      {
        $targetFileObj = $fs->getFile($target_path); 
        if ($targetFileObj->exists())
        {
          Log::addWarn('Replace files found filename conflict and didnt run', $targetFileObj->getFullPath());
          return false; 
        }

        $targetFileObjs[$key] = $targetFileObj; 
        
      }

      foreach($sourceFiles as $key => $sourceFile)
      { 
            $targetFileObj = isset($targetFileObjs[$key]) ? $targetFileObjs[$key] : null; 
            if (is_null($targetFileObj))
            {
                 Log::addError('Source/Target mismatch in replacements. This should not happen!'); 
                continue;      
            }

            $result = $sourceFile->move($targetFileObj);
      }

      $replacer = new Replacer(); 
      $replacer->setSource($source_url);
      $replacer->setTarget($target_url); 
      $replacer->setSourceMeta($searchArray); 
      $replacer->setTargetMeta($replaceArray);
      
      $replacer->replace();

      $this->replaceMetaData($item_id, $base_filename, $newFileName );
      return false; 

  }

  protected function replaceMetaData($item_id, $old_file, $new_file)
  {
        $metadata = wp_get_attachment_metadata($item_id); 
        if (isset($metadata['file']) && strpos($metadata['file'], $old_file) !== false)
        {
             $metadata['file'] = str_replace($old_file, $new_file, $metadata['file']); 
             update_attached_file($item_id, $metadata['file']);
        }

        if (isset($metadata['original_image']) && strpos($metadata['original_image'], $old_file) !== false)
        {
            $metadata['original_image'] = str_replace($old_file, $new_file, $metadata['original_image']); 
        }

        if (isset($metadata['sizes']) && is_array($metadata['sizes']))
        {
             foreach($metadata['sizes'] as $sizeName => $sizeData)
             {
                 if (isset($sizeData['file']) && strpos($sizeData['file'], $old_file) !== false)
                 {
                    $metadata['sizes'][$sizeName]['file'] = str_replace($old_file, $new_file, $sizeData['file']);
                 }
             } 
        }

        Log::addTemp('New Metadata after replace: ', $metadata);
        wp_update_attachment_metadata($item_id, $metadata);
        
  }


  // @todo This might be returned in multiple formats / post data / postmeta data?  Public because of callback
  /** This is the callback for Finder results for replacing attributes on the Images  
   * 
   * This function also saves the results!
   * 
   * @param mixed $results 
   * @param mixed $args 
   * @return void 
   */
  public function handleReplace($results, $args)
  {

    $replacer2 = \ShortPixel\Replacer\Replacer::getInstance();
    $aiData = $args['aiData'];
    $qItem = $args['qItem'];

    $imageModel = $qItem->imageModel;

        foreach($results as $result)
        {
            $post_id = $result['post_id']; 
            $content = $result['content'];

            $matches = $this->fetchImageMatches($content); 
            $sources = []; 
            $replaces = []; 

            $image_filebase = ($imageModel->isScaled()) ? $imageModel->getOriginalFile()->getFileBase() : $imageModel->getFileBase(); 

            foreach($matches as $match)
            {

            // @todo The result of the post, should parse the content somehow via regex, then load.
             $frontImage = new \ShortPixel\Model\FrontImage($match); 

             $src = $frontImage->src; 
             // Only replace in post content the image we did

             $pattern = '/' . preg_quote($image_filebase, '/') . '(-\d+x\d+\.|\.|-scaled\.)' . $imageModel->getExtension() . '/i';
             if (preg_match($pattern, $src ) !== 1)
             {
                continue;
             }
             
          /*   if (strpos($src, $aiData['replace_filebase']) === false)
             {
                continue; 
             } */

             $do_replace = false; 

             if (isset($aiData['alt']) && false === is_int($aiData['alt']))
             {
                $frontImage->alt = $aiData['alt']; 
                $do_replace = true; 
             }
             if (isset($aiData['caption']) && false === is_int($aiData['caption']))
             {
                $frontImage->caption = $aiData['caption'];
                $do_replace = true; 
             }

             if (true === $do_replace)
             {
                $sources[] = $match; 
                $replaces[] = $frontImage->buildImage();
             }

            }

            if (count($sources) > 0 && count($replaces) > 0)
            {
                Log::addInfo('Running Ai Replace : ', [$aiData, $sources, $replaces]); 
                $content = $replacer2->replaceContent($content, $sources, $replaces);
                $replacer2->Updater()->updatePost($post_id, $content); 
            }
        }

  }



  // @todo Direct copy from CDNController. In future might be merged somewhere. 
  protected function fetchImageMatches($content, $args = [])
  {
      $number = preg_match_all('/<img[^>]*>|<source srcset="[^>]*">/i', $content, $matches);
      $matches = $matches[0];
      return $matches;
  }

  /*
  protected function fetchCaptionMatches($content, $qItem)
  {
       $pattern = '/' 
  }
*/
  /**
   * Check if setting AI is enabled in settings. 
   *
   * @return boolean
   */
  public function isAiEnabled()
  {
     $settings = \wpSPIO()->settings(); 

     $bool = (true == $settings->enable_ai) ? true : false; // make sure boolean is hard type. 
    
     $no_ai = apply_filters('shortpixel/settings/no_ai', false);
     if (true === $no_ai) // switch around negative filter
     {
         $bool = false; 
     } 
     
     return $bool; 
  }

  public function isAutoAiEnabled()
  {
      $bool = $this->isAiEnabled(); 
      if (false === $bool)
      { 
         return $bool; 
      }

      $settings = \wpSPIO()->settings(); 

      $bool = (true == $settings->autoAI) ? true : false; 

      return $bool; 
  }

  /**
   * Process the resulting AI text
   *
   * @param string $text  The result text string from AI
   * @return string
   */
  protected function processTextResult($text)
  {
        $text = ucfirst(trim($text));

        // Add period to the end of the string.
        if (substr($text, -1) !== '.' && true === apply_filters('shortpixel/ai/check_period', true))
        {
            $text .= '.';
        }

        return $text;
  }

  /*
  protected function getRequestJSON($url, $params = [])
  { 
     $settings = $this->getAISettings($params);

     $ignore_fields = (isset($params['ignore_fields'])) ? $params['ignore_fields'] : []; 

     $json = [
        'url' => $url, 
        'languages' => $settings['ai_language'], 
        'context' => $settings['ai_general_context'], 

     ]; 

     // if ($settings['ai_use_post']) // not in API? 

     if ($settings['ai_gen_alt'])
     {
        $json['alt'] = [
                'context' => $settings['ai_alt_context'],
                'chars' => $settings['ai_limit_alt_chars'],
        ];
     }

     if ($settings['ai_gen_caption'])
     {
         $json['caption'] = [
                'context' => $settings['ai_caption_context'], 
                'chars' => $settings['ai_limit_caption_chars'], 
         ];
     }

     if ($settings['ai_gen_description'])
     {
         $json['image_description'] = [
                'context' => $settings['ai_description_context'], 
                'chars' => $settings['ai_limit_description_chars'],
         ];
     }

     if ($settings['ai_gen_filename'])
     {
         $json['file'] = [
                'context' => $settings['ai_filename_context'], 
                'chars' => $settings['ai_limit_filename_chars'], 
         ];
     }

     return $json; 
  }

  */

  /*
  public function parseJSONForQItem(QueueItem $qItem, $params = [])
  {
        $url = $qItem->data()->url; 
        $item_id = $qItem->item_id;
        $settings = \wpSPIO()->settings(); 

        // Note this is also checked in AiDataModel for checking processable.  Might need to sync upon adding fields
        if (true === $settings->aiPreserve) 
        { 
            $returnDataList = $qItem->data()->returndatalist; 

            $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');
            $current = $aiModel->getCurrentData();
            $filtered = array_filter($current); // filter out all empty variables

          //  $altdata = $this->getAltData($qItem);
            $params['ignore_fields'] = array_keys($filtered);
            
            foreach($filtered as $key => $filter)
            {
                 $returnDataList[$key] = AiDataModel::F_STATUS_EXCLUDE;
            }
            $qItem->data()->returndatalist = $returnDataList; 
        }
        
        $json = $this->getRequestJSON($url, $params); 


        $qItem->data()->paramlist = $json;
  }
        */

/*
  protected function parseQuestionForQItem(QueueItem $qItem)
  {
        $url = $qItem->data()->url; 
        $item_id = $qItem->item_id;
        $question = $this->parseQuestion($url, $item_id); 
        $qItem->data()->url = $question;
  }
*/
  /*
  private function getAISettings($params = [])
  {
    $settings = \wpSPIO()->settings(); 

    $defaults = [
    'ai_general_context' => $settings->ai_general_context, 
    'ai_use_post' => $settings->ai_use_post, 
    'ai_gen_alt' => $settings->ai_gen_alt, 
    'ai_gen_caption' => $settings->ai_gen_caption, 
    'ai_gen_description' => $settings->ai_gen_description, 
    'ai_filename_prefercurrent' => $settings->ai_filename_prefercurrent,
    'ai_limit_alt_chars' => $settings->ai_limit_alt_chars, 
    'ai_alt_context' => $settings->ai_alt_context, 
    'ai_limit_description_chars' => $settings->ai_limit_description_chars, 
    'ai_description_context' => $settings->ai_description_context, 
    'ai_limit_caption_chars' => $settings->ai_limit_caption_chars, 
    'ai_caption_context' => $settings->ai_caption_context, 
    'ai_gen_filename' => $settings->ai_gen_filename, 
    'ai_limit_filename_chars' => $settings->ai_limit_filename_chars, 
    'ai_filename_context' => $settings->ai_filename_context, 
    'ai_use_exif' => $settings->ai_use_exif, 
    'ai_language' => $settings->ai_language,
    'aiPreserve' => $settings->aiPreserve, 
    ];

    $params = wp_parse_args($params, $defaults);

    return $params; 
  }
 */
    

  /*
  public function isSupported(queueItem $qItem)
  {
       $imageModel = $qItem->imageModel; 

        // @todo This should check for animated gifs in the future, for now blanket no. 
       if('gif' == $imageModel->getExtension())
       {
         return false; 
       }
       
       return true; 
  } */

  public function undoAltData(QueueItem $qItem)
  {
       $item_id = $qItem->item_id;
       $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');
       $original = $aiModel->getOriginalData();
       $generated = $aiModel->getGeneratedData();


       $aiData = [
            'alt' => $original['alt'], 
            'caption' => $original['caption'], 
            'description' => $original['description'],
            'replace_filebase' => $generated['filebase'],
       ];
    
       $aiModel->revert();
       AiDataModel::flushModelCache($item_id);

       $this->replaceImageAttributes($qItem, $aiData); 

       $aiData = $aiModel->getCurrentData();
    
       return $this->getAltData($qItem); 
  }

public function getAltData(QueueItem $qItem)
{
    $item_id = $qItem->item_id; 

    $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');

    $status = $aiModel->getStatus();
    
    // check for old data
    if (AiDataModel::AI_STATUS_NOTHING === $status) // old data 
    {
         $metacheck = get_post_meta($item_id, 'shortpixel_alt_requests', true); 
         if (false !== $metacheck && is_array($metacheck))
         {
                $aiModel->migrate($metacheck);
                delete_post_meta($item_id, 'shortpixel_alt_requests');
                $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');
                $status = $aiModel->getStatus();
         }
    }

    $generated = $aiModel->getGeneratedData(); 
    $original = $aiModel->getOriginalData();
    $current = $aiModel->getCurrentData();

    $image_url = $qItem->imageModel->getUrl();

    list($dataItems, $generated) = $this->formatGenerated($generated, $current, $original);


    $view = new ViewController();
    $view->addData([
            'item_id' => $item_id, 
            'orginal_alt' => $original['alt'], 
            'result_alt' => $generated['alt'], 
            'has_data' => ($status == AiDataModel::AI_STATUS_GENERATED) ? true : false,
            'is_processable' => $aiModel->isProcessable(), 
            'processable_reason' => $aiModel->getProcessableReason(), 
            'processable_status' => $aiModel->getProcessableReason(true), 
            'image_url' => $image_url, 
           // 'current_alt' => $current_alt, 
            'status' => $status, 
      //      'isSupported' => $this->isSupported($qItem),
            'dataItems' => $dataItems,  // This seems not used(?)
            'isDifferent' =>  $aiModel->currentIsDifferent(),
        ]);


    // *****!!! Temporary don't pass these back since we don't support it yet ** // 

    if (isset($generated['filebase']))
    {
       unset($generated['filebase']); 
    }
    if (isset($generated['filename']))
    {
       unset($generated['filename']);
    }

    $metadata['snippet'] = $view->returnView('snippets/part-aitext');

    $metadata['generated'] = $generated; 
    $metadata['original'] = $original; 
    $metadata['current'] = $current; 
    $metadata['action'] = $qItem->data()->action;
    $metadata['item_id'] = $item_id;

    return $metadata; 
}

public function formatGenerated($generated, $current, $original)
{
    
  $fields = ['alt', 'caption', 'description'];
  $dataItems = []; 

  // Statii from AiDataModel which means generated is not available (replace for original/current?) 
  $statii = [AiDataModel::F_STATUS_PREVENTOVERRIDE, AiDataModel::F_STATUS_EXCLUDESETTING];

  foreach($fields as $name)
  {
       if (false === isset($generated[$name]))
       {
          continue; 
       }
       $value = $generated[$name]; 
       

       if (false === is_null($value) && false === is_int($value) && strlen($value) > 1)
       {
          $dataItems[] = ucfirst($name); 
       }
       if (is_int($value) && in_array($value, $statii))
       {
          if (isset($current[$name]))
          {
               $value = $current[$name];
          }
          elseif(isset($original[$name]))
          {
               $value = $original[$name];
          }
          $generated[$name] = $value;
       }
  } 

  return [$dataItems, $generated];
}



} // class 
