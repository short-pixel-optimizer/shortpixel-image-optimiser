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

   //   $qItem->addResult(['qStatus' => Queue::RESULT_ERROR]);
      return;
  }


  public function sendToProcessing(QueueItem $qItem) { 

    if (false == $this->isSupported($qItem))
    {
        // For now only fail here is GIF support, so message is a backstop for now that later should be updated. 
        $qItem->addResult([
            'is_error' => true, 
            'is_done' => true,
            'message' => __('ALT Tags cannot be generated for GIF files by ShortPixel AI, for now', 'shortpixel-image-optimiser'), 
            'apiStatus' => AiController::AI_STATUS_INVALID_URL,
        ]); 

    }
    else
    {
        $this->api->processMediaItem($qItem, $qItem->imageModel);
    }
 
  }

// @todo Probably here should check if Alt item is already generated . 
  public function checkItem(QueueItem $qItem) { 
      return true;
  }

  public function enqueueItem(QueueItem $qItem, $args = [])
  {

    $action = $args['action']; // $qItem->data()->action; 

    $queue = $this->getCurrentQueue($qItem);
   // $directAction = ; 

    switch($action)
    {
        case 'requestAlt': 
           $qItem->requestAltAction();
           $this->parseJSONForQItem($qItem); 
           $directAction = false; 
        break;
        case 'retrieveAlt':  // This might be deprecated, since retrieve will be called via next_action. 
            //$qItem->data()->remote_id =  
            $qItem->retrieveAltAction($args['remote_id']);
            $directAction = false; 
        break; 
        default: 
            Log::addError('no Ai controller action found!');
            $qItem->addResult([
                'message' => 'Wrong action in AiController!', 
                'is_error' => true, 
                'is_done' => true, 
            ]);
            return $qItem->result();
        break; 
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
            $result = new \stdClass; 
            $result->qstatus = Queue::RESULT_ITEMS;
            $result->numitems = 1;
            $qItem->addResult([
            'message' => __('Request for Alt text send to Shortpixel AI', 'shortpixel-image-optimiser')]);
        }
        else
        {
            $result->numitems = 0;
        }

    }
    else
    {
      $result = $queue->addQueueItem($qItem);
    }

    return $result;
  }


  public function handleAPIResult(QueueItem $qItem)
  {
      $queue = $this->currentQueue;

      $qItem->addResult(['apiName' => $this->apiName]);
      $apiStatus = $qItem->result()->apiStatus;


    // @TODO  TEST DATA 
 /*   $qItem->addResult([
        'apiStatus' => 2, 
        'is_error' => false, 
//        'retrievedText' => 'Regenerated text', 
        'message' => 'Hardcoded Text done',
    ]); 

    $qItem->data()->action = 'retrieveAlt';
    $qItem->data()->next_actions = []; 
    $apiStatus = 2; */
    // ***** END TEST -- REMOVE!!111!!11!! ****** 


      if ($qItem->result()->is_error)  {
       
        if (true === $qItem->result()->is_done )
        {
            Log::addDebug('Item failed, has error on done ', $qItem->result());
            $queue->itemFailed($qItem, true);
            $this->HandleItemError($qItem);
        }
        else // Do nothing for now / retry (?)
        {
            // timeout
            /*if ($apiStatus === RequestManager::STATUS_CONNECTION_ERROR)
            {

            } */
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

  protected function HandleSuccess(QueueItem $qItem)
  {
        // @todo Move success Handler here + replacer start.
        $aiData = $qItem->result->aiData;  

        if (! isset($aiData['filebase']))
        {
             $aiData['filebase'] = $qItem->imageModel->getFileBase();
        }
//        $text = $qItem->result()->retrievedText;

        $textItems = ['alt', 'caption'];
        foreach($textItems as $textItem)
        {
             if (isset($aiData[$textItem]) && false !== $aiData[$textItem])
             {
                 $aiData[$textItem] = $this->processTextResult($aiData[$textItem]);
             }
        }            

        // Description : From POST CONTENT 
        // Caption : From POST EXCERPT 
        // Alt  : Own Metadata field 


        $item_id = $qItem->item_id; 
        
        $aiModel = new AIDataModel($item_id, 'media');
        $aiModel->handleNewData($aiData);

       /* $current_alt = get_post_meta($item_id, '_wp_attachment_image_alt', true);

        $ai_metadata = get_post_meta($item_id, 'shortpixel_alt_requests', true); 

        if (false === is_array($ai_metadata))
        {
          $ai_metadata = []; 
        }

        if (! isset($ai_metadata['original_alt']))
        {
            $ai_metadata['original_alt'] = $current_alt;     
        }
        $ai_metadata['result_alt'] = $text;

        $bool = update_post_meta($item_id, 'shortpixel_alt_requests', $ai_metadata); 
        
        if (false === $bool)
        {
            Log::addWarn('Save alt requests failed? - ' . $item_id, $ai_metadata);
        }
        
        $bool = update_post_meta($item_id, '_wp_attachment_image_alt', $text);

         if (false === $bool)
         {
             Log::addWarn('Failed to add alt text to postmeta?' . $item_id, $text);
          }

*/
        $qItem->addResult([
//          'retrievedText' => $text,
          'apiStatus' => RequestManager::STATUS_SUCCESS,
          'fileStatus' => ImageModel::FILE_STATUS_SUCCESS
        ]);



        $this->replaceImageAttributes($qItem, $aiData); 

        if ($qItem->result()->filename)
        {
            
        }
        

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

        foreach($results as $result)
        {
            $post_id = $result['post_id']; 
            $content = $result['content'];

            $matches = $this->fetchImageMatches($content); 
            $sources = []; 
            $replaces = []; 

            foreach($matches as $match)
            {
                $sources[] = $match; 
            // @todo The result of the post, should parse the content somehow via regex, then load.
             $frontImage = new \ShortPixel\Model\FrontImage($match); 
             if (isset($args['alt']))
             {
                $frontImage->alt = $args['alt']; 
             }
             if (isset($args['caption']))
             {
                $frontImage->caption = $args['caption'];
             }

             $replaces[] = $frontImage->buildImage();


            }

            $content = $replacer2->replaceContent($match, $sources, $replaces);
           
            $replacer2->Updater()->updatePost($post_id, $content); 
        }


  }

  // @todo Direct copy from CDNController. In future might be merged somewhere. 
  protected function fetchImageMatches($content, $args = [])
  {
      $number = preg_match_all('/<img[^>]*>|<source srcset="[^>]*">/i', $content, $matches);
      $matches = $matches[0];
      return $matches;
  }

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

  protected function getRequestJSON($url, $item_id, $params = [])
  { 
     $settings = $this->getAISettings($params);

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

     if ($settings['ai_gen_filename'])
     {
         $json['file'] = [
                'context' => $settings['ai_filename_context'], 
                'chars' => $settings['ai_limit_filename_chars'], 
         ];
     }

     return $json; 
  }

  protected function parseJSONForQItem(QueueItem $qItem)
  {
        $url = $qItem->data()->url; 
        $item_id = $qItem->item_id;
        $json = $this->getRequestJSON($url, $item_id); 
        $qItem->data()->paramlist = $json;
  }

  protected function parseQuestionForQItem(QueueItem $qItem)
  {
        $url = $qItem->data()->url; 
        $item_id = $qItem->item_id;
        $question = $this->parseQuestion($url, $item_id); 
        $qItem->data()->url = $question;
  }

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
    ];

    $params = wp_parse_args($params, $defaults);

    return $params; 
  }

  public function parseQuestion($url, $item_id, $params = [])
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
    ];

    $params = wp_parse_args($params, $defaults);

    $question = [
            'main' => $params['ai_general_context'],
            'language' => $params['ai_language'], 
            'required_tags' => [],
     ]; 

     $question['page'] = $this->getPageQuestion($question, $item_id, $params);
    
     $question = $this->getPartQuestion($question, 'alt', $params);
     $question = $this->getPartQuestion($question, 'caption', $params);
     $question = $this->getPartQuestion($question, 'description', $params);
     $question = $this->getPartQuestion($question, 'filename', $params);

    if (true == $params['ai_use_exif'])
    {
         $question['exif'] = ' and take into account the image EXIF data when generating all the requested texts'; 
    }

   // $question['tags'] = implode($question['required_tags'], ',');

    $question = apply_filters('shortpixel/ai/parsed_questions', $question);

   /* $alt = isset($question['alt']) ? $question['alt'] : ''; 
    $caption = isset($question['caption']) ? $question['caption'] : ''; 
    $description =isset($question['description']) ? $question['description'] : ''; 
    $filename = isset($question['filename']) ? $question['filename'] : ''; 
*/
    $specs = [];
    foreach($question['required_tags'] as $tag)
    {
        $specs[] = $question[$tag]; 
    }
    $specs = implode(' ', $specs);

    $required_tags =  implode(',', $question['required_tags_ainame']); 
    
    if (strlen(trim($params['ai_language'])) <= 0)
    {   
        $params['ai_language'] = get_locale();
    }

    $final_question = sprintf("For the URL %s , with this context \" %s \" , write for %s SEO friendly texts with the following specifications: %s in %s language %s . Provide the answer in JSON format, seperating the %s output in seperate fields",
        $url, 
        $question['main'], 
        $required_tags,
        $specs, 
        $question['language'], 
        $question['exif'], 
        $required_tags


    ); 

    return $final_question;

  }
  
  protected function getPartQuestion($question, $name, $params)
  {
    
    $limit = 'ai_limit_' . $name . '_chars';
    $context = 'ai_' . $name . '_context'; 
    $to_use = 'ai_gen_' . $name;  

    switch($name)
    {
         case 'alt': 
            $aiName = 'alt tag'; 
         break; 
         case 'caption': 
            $aiName = 'caption tag'; 
         break;
         case 'description': 
            $aiName = 'description text'; 
         break; 
         case 'filename': 
            $aiName = 'the file name'; 
         break; 
    }

    if (true === $params[$to_use])
    {
        $limit = $params[$limit]; 
        $context = $params[$context]; 

        $string = ' For the ' . $aiName . ' limit your response to the most relevant ' . $limit . ' characters for SEO ';

        if ('filename' == $name)
        {
            $string .= ' leaving the filename extension intact '; 
            if (true === $params['ai_filename_prefercurrent'])
            {
                $string .=  ' and change filename only when the current filename is not relevant. Otherwise return false for this field '; 
            }
            
        }

        if (strlen(trim($context)) > 0)
        {
             $string .= ' and use this additional information when generating the ' . $aiName . ':' . $context . '. '; 
        }

        $question[$name] = $string; 
        $question['required_tags_ainame'][] = $aiName;
        $question['required_tags'][] = $name;
    }

    
    return $question;
  }

  protected function getPageQuestion($question, $item_id, $params)
  {
        if (false == $params['ai_use_post'])
        {
             return false; 
        }

        $post = get_post($item_id); 
        if (is_null($post) || false === $post)
        {
             return false; 
        }

        $parent = $post->post_parent; 

        if ($parent <= 0 || ! is_int($parent))
        {
             return false; 
        }

        $page_post = get_post($parent); 
        if (is_null($page_post) || false === $page_post)
        {
             return false; 
        }

        $title = $page_post->post_title; 
        $excerpt = get_the_excerpt(($page_post)); 

        $string = ' for the article with the title ' . $title; 

        if (strlen(trim($excerpt)) > 0 )
        {
            $string .= ' and excerpt ' . $excerpt; 
        } 

        
        return $string;
  }

  

  public function isSupported(queueItem $qItem)
  {
       $imageModel = $qItem->imageModel; 

        // @todo This should check for animated gifs in the future, for now blanket no. 
       if('gif' == $imageModel->getExtension())
       {
         return false; 
       }
       
       return true; 
  }

  public function undoAltData(QueueItem $qItem)
  {
       //$altData = $this->getAltData($qItem);
       $item_id = $qItem->item_id;

       //$original_text = $altData['original_alt'];
Log::addTemp('UNDO ALT DATA - OptimizeAIController');
       $aiModel = new AiDataModel($item_id, 'media');

      // $generated = $aiModel->getGeneratedData(); 
       $original = $aiModel->getOriginalData();

       $aiData = [
            'alt' => $original['alt'], 
            'caption' => $original['caption'], 
            'description' => $original['description'],
       ];
    
       //$bool = update_post_meta($item_id, '_wp_attachment_image_alt', $alt);

       $aiModel->revert();

       $this->replaceImageAttributes($qItem, $aiData); 

   //    $this->startReplace($qItem, $original_text);

       return $this->getAltData($qItem); 
  }

public function getAltData(QueueItem $qItem)
{
    $item_id = $qItem->item_id; 
   // $metadata = get_post_meta($item_id, 'shortpixel_alt_requests', true);
   // $current_alt = get_post_meta($item_id, '_wp_attachment_image_alt', true);

    $aiModel = new AiDataModel($item_id, 'media');

    $generated = $aiModel->getGeneratedData(); 
    $original = $aiModel->getOriginalData();

    $status = $aiModel->getStatus();

    /*
    if (false === is_array($metadata))
    {
         $metadata = [
            'original_alt' => $current_alt, 
            'result_alt' => false, 
            'snippet' => false, 
         ];
    } */

    // Check for changes
    /*
    if ($metadata['result_alt'] !== false && $metadata['original_alt'] !== false)
    {
        // If both result / original are not the current, this indicates that the current alt has been manually changed and should replace our original alt. 
        if ($metadata['result_alt'] !== $current_alt && $metadata['original_alt'] !== $current_alt)
        {
            $metadata['original_alt'] = $current_alt; 
            $bool = update_post_meta($item_id, 'shortpixel_alt_requests', $metadata); 

        }

    }
*/
    $image_url = $qItem->imageModel->getUrl();

    $fields = ['alt', 'caption', 'description'];
    $dataItems = []; 
    foreach($fields as $name)
    {
         if (isset($generated[$name]) && false === is_null($generated[$name]) && strlen($generated[$name]) > 1)
         {
            $dataItems[] = ucfirst($name); 
         }
    } 

    // Check if it's our data. 
    /*
    $has_data = ($metadata['original_alt'] !== false && $metadata['result_alt'] !== false) ? true : false; 
    if ($current_alt !== $metadata['result_alt'])
    {
         $has_data = false; 
    }
*/
    $view = new ViewController();
    $view->addData([
            'item_id' => $item_id, 
            'orginal_alt' => $original['alt'], 
            'result_alt' => $generated['alt'], 
            'has_data' => ($status == AiDataModel::AI_STATUS_GENERATED) ? true : false,
            'image_url' => $image_url, 
           // 'current_alt' => $current_alt, 
            'status' => $status, 
            'isSupported' => $this->isSupported($qItem),
            'dataItems' => $dataItems, 
            'isDifferent' =>  $aiModel->currentIsDifferent(),
        ]);

    $metadata['snippet'] = $view->returnView('snippets/part-aitext');

    $metadata['generated'] = $generated; 
    $metadata['original'] = $original; 
    $metadata['action'] = $qItem->data()->action;
    $metadata['item_id'] = $item_id;
  //  $metadata['has_data'] = $has_data;

    return $metadata; 
}



} // class 
