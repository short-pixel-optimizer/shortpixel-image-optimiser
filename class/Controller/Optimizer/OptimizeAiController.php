<?php

namespace ShortPixel\Controller\Optimizer;

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Queue\QueueItem as QueueItem;
use ShortPixel\Controller\Api\RequestManager as RequestManager;
use ShortPixel\Controller\Api\AiController;
use ShortPixel\Controller\Api\ApiController;
use ShortPixel\Controller\Queue\Queue;
use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Queue\QueueItemResult;
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
    protected function HandleItemError(QueueItem $qItem)
    {

        // Change to chance the result / message with specific errors. 
        switch ($qItem->result()->apiStatus) {
            case '422':  // Unprocessable Item 
                // No different message than API 
                break;
        }

        return;
    }


    public function sendToProcessing(QueueItem $qItem)
    {


        $action = $qItem->data()->action;

        switch ($action) {
            case 'undoAI':
                return $this->undoAltData($qItem);
                break;
            default:
                $this->api->processMediaItem($qItem);
                break;
        }
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

        //}

    }

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

        switch ($action) {
            case 'requestAlt':
                $qItem->requestAltAction($args);
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

        if (true === $preview_only) {
            $directAction = true;
        }

        if (true === $directAction) {
            // The directActions give back booleans, but the whole function must return an queue result object with qstatus and numitems
            $this->sendToProcessing($qItem);
            $this->handleAPIResult($qItem);

            $result = $qItem->result();

            // Probably not as is should be, but functional
            if ($result->is_error === false) {
                //  $result = new \stdClass; 
                $result->qstatus = Queue::RESULT_ITEMS;
                $result->numitems = 1;
                if ($qItem->result()->message == '') {
                    $qItem->addResult([
                        'message' => __('Request for image SEO data sent to Shortpixel AI', 'shortpixel-image-optimiser')
                    ]);
                }
            } else {
                $result->numitems = 0;
            }
        } else {
            if (isset($args['queue_list_order'])) {
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

        if (true === $qItem->result()->is_error) {

            if (true === $qItem->result()->is_done) {
                Log::addDebug('Item failed, has error on done ', $qItem->result());
                $queue->itemFailed($qItem, true);
                $this->HandleItemError($qItem);
                $this->finishItemProcess($qItem);
            } else // Do nothing for now / retry (?)
            {
            }

            return;
        }

        // Result for requestAlt 
        if ($apiStatus == RequestManager::STATUS_WAITING) {
            return;
        } elseif (property_exists($qItem->result(), 'remote_id') && is_numeric($qItem->result()->remote_id) && $qItem->result()->remote_id > 0) {
            $remote_id = $qItem->result()->remote_id;

            $this->finishItemProcess($qItem, ['remote_id' => $remote_id]);
        } else {
            if ($qItem->data()->action == 'requestAlt') {
                Log::addError('RequestAlt result without remote_id', $qItem->result());
                $queue->itemFailed($qItem, true);
                $this->HandleItemError($qItem);
                return;
            }
        }

        // Result for retrieveAlt
        if (property_exists($qItem->result(), 'aiData') && false === is_null($qItem->result()->aiData)) {
             $this->HandleSuccess($qItem);
        }
    }

    public function formatResultData($aiData, $qItem)
    {
        // Always save the original filename
        $aiData['original_filebase'] = $qItem->imageModel->getFileBase();
        $returnDataList = $qItem->data()->returndatalist;

        if (! isset($aiData['filebase'])) {
            $aiData['filebase'] = $aiData['original_filebase'];
        }

        $settings = \wpSPIO()->settings();

        // removed  'post_title' here because in image title doens't look good. 
        $textItems = ['alt', 'caption', 'description', 'filename'];
        foreach ($textItems as $textItem) {

            if (isset($aiData[$textItem]) && false !== $aiData[$textItem] && false === is_numeric($aiData[$textItem])) {
                $aiData[$textItem] = $this->processTextResult($aiData[$textItem]);
            }
            // If 1 is returned as data, this means for some reason the API didn't create a text for this field, while it is allowed to do so. Defer to empty string better than '1' 
            if (true === is_numeric($aiData[$textItem]) && 1 == $aiData[$textItem]) {
                $aiData[$textItem] = '';
            }
        }

        // Apply prefix and postfix to each field
        $prefixPostfixMap = [
            'alt' => ['prefix' => 'ai_alt_prefix', 'postfix' => 'ai_alt_postfix'],
            'caption' => ['prefix' => 'ai_caption_prefix', 'postfix' => 'ai_caption_postfix'],
            'description' => ['prefix' => 'ai_description_prefix', 'postfix' => 'ai_description_postfix'],
            'post_title' => ['prefix' => 'ai_post_title_prefix', 'postfix' => 'ai_post_title_postfix'],
            'filename' => ['prefix' => 'ai_filename_prefix', 'postfix' => 'ai_filename_postfix'],
        ];

        foreach ($prefixPostfixMap as $field => $affixes) {
            if (isset($aiData[$field]) && !empty($aiData[$field]) && $aiData[$field] !== -3) {

                $prefix = $settings->{$affixes['prefix']};
                $postfix = $settings->{$affixes['postfix']};
                $spacer = ($field === 'filename') ? '' : ' ';

                if (!empty($prefix)) {
                    $aiData[$field] = $prefix . $spacer . $aiData[$field];
                }
                if (!empty($postfix)) {
                    $aiData[$field] = $aiData[$field] . $spacer . $postfix;
                }
            }
        }

        // Re-add Result after formatting so it passed back
        //$qItem->addResult(['aiData' => $aiData]);


        return $aiData;
    }

    private function getDataLabels()
    {
        $labels = [
            'alt' => __('Alt', 'shortpixel-image-optimiser'),
            'caption' => __('Caption', 'shortpixel-image-optimiser'),
            'description' => __('Description', 'shortpixel-image-optimiser'),
            'post_title' =>  __('Image Title', 'shortpixel-image-optimiser'),
            'filebase' => __('Filename', 'shortpixel-image-optimiser'),
        ];

        return $labels;
    }

    protected function HandleSuccess(QueueItem $qItem)
    {
        $aiData = $qItem->result()->aiData;
        $aiData = apply_filters('shortpixel/ai/success', $aiData, $qItem);
        $aiData = $this->formatResultData($aiData, $qItem);

        // Description : From POST CONTENT 
        // Caption : From POST EXCERPT 
        // Alt  : Own Metadata field 
        $item_id = $qItem->item_id;

        $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');
        $aiModel->handleNewData($aiData);

        $qItem->addResult([
            'apiStatus' => RequestManager::STATUS_SUCCESS,
            'fileStatus' => ImageModel::FILE_STATUS_SUCCESS
        ]);

        $aiData['replace_filebase'] = $aiData['original_filebase'];

        // Block this item to prevent a double process on this. 
        $this->blockItem($qItem);

        $results = $this->replaceImageAttributes($qItem, $aiData);
        $imageModel = $qItem->imageModel;

        // If the file was just uploaded, assume it's not already widely linked and doesn't need replacing / symlinking 
        // ( Maybe just replacing? )
        // Generic number of strlen here. Disallow filename not to be very short, because because. 
        if (isset($aiData['filename']) && is_string($aiData['filename']) && strlen($aiData['filename']) > 5) // ?? 
        {
            // @todo This and the ReplaceAtttributes is similar code + Replacer2 doesn't reset at all due to getINstance implementation
            $currentFileBase = ($imageModel->isScaled()) ? $imageModel->getOriginalFile()->getFileBase() : $imageModel->getFileBase();

            $urls = $qItem->data()->urls;
            if (is_null($urls)) // can be empty on restore action 
            {
                $imageModel = $qItem->imageModel;
                if (true === $imageModel->isScaled()) {
                    $url = $imageModel->getOriginalFile()->getURL();
                } else {
                    $url = $qItem->imageModel->getUrl();
                }
            } else {
                $url = $urls[0];
            }

            if ($currentFileBase !== $aiData['filename']) {
                $args = [
                    'dry_run' => false,  
                    'recent_upload' => $qItem->data()->recent_upload,
                    'url' => $url, 
                ];

                $this->replaceFiles($qItem, $aiData['filename'], $args);
            }

            // Reset when files change.
            $fs = \wpSPIO()->filesystem();
            $qItem->setModel($fs->getMediaImage($item_id, false));
            // Reflect new filename here. 
            $qItem->addResult(['filename' => $qItem->imageModel->getFileName()]);
        }

        $qItem->addResult(['improvements' => $imageModel->getImprovements()]); // Improvements for bulk UX. 

        // This will add URL (optimized) to result
        $this->addPreview($qItem); // Preview ( image ) for bulk UX 

        AiDataModel::flushModelCache($item_id);

        // Get generated data which is the final result for the action including exclusions etc. 
        $data = $this->getAltData($qItem);
        $qItem->addResult(['aiData' => $data['generated']]); // But the generated data in the result.

        // For Bulk, add labels to display in the result set. Default is same as data, can be overridden . Used in Bulk JS
        $qItem->addResult(['aiDataLabels' => $this->getDataLabels()]);

        $this->unBlockItem($qItem);

        $this->finishItemProcess($qItem);

    }

    /**
     * Get post IDs for the same WPML language as the given attachment.
     *
     * @param int $item_id
     * @return int[]
     */
    protected function getWpmlLanguagePostIds($item_id)
    {
        if (!\wpSPIO()->env()->plugin_active('wpml')) {
            return [];
        }

        $language = apply_filters('wpml_post_language_details', null, $item_id);
        if (!is_array($language) || empty($language['language_code'])) {
            return [];
        }

        global $wpdb;
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT element_id FROM {$wpdb->prefix}icl_translations WHERE language_code = %s AND element_type LIKE %s AND element_type <> %s",
                $language['language_code'],
                'post_%',
                'post_attachment'
            )
        );

        return array_map('intval', $post_ids);
    }


    /** Replace Image Attributes ( others? ) on images via BaseURL 
     * 
     * The finder is passed a callback to which the results will be returned.  
     * 
     * @param QueueItem $qItem 
     * @param mixed $new_text The new text 
     * @return array 
     */
    protected function replaceImageAttributes(QueueItem $qItem, $aiData)
    {
        if (is_int($aiData['alt']) && is_int($aiData['caption'])) {
            Log::addInfo('Alt/Caption returned integer/status, not replace');
            return;
        }

        // Replacer Part 
        $urls = $qItem->data()->urls;
        if (is_null($urls)) // can be empty on restore action 
        {
            $imageModel = $qItem->imageModel;
            if (true === $imageModel->isScaled()) {
                $url = $imageModel->getOriginalFile()->getURL();
            } else {
                $url = $qItem->imageModel->getUrl();
            }
        } else {
            $url = $urls[0];
        }

        $replacer2 = \ShortPixel\Replacer\Replacer::getInstance();
        $setup = $replacer2->Setup();
        $setup->forSearch()->URL()->addData($url);

        $base_url = $setup->forSearch()->URL()->getBaseURL();
        $post_ids = $this->getWpmlLanguagePostIds($qItem->item_id);

        $finder = $replacer2->Finder(['base_url' => $base_url, 'callback' => [$this, 'handleReplace'], 'return_data' => [
            'aiData' => $aiData,
            'qItem' => $qItem,
        ]]);

        $results = $finder->posts(['post_ids' => $post_ids]);
        return $results;
    }

    /* @todo  The file mover should: 
  *  - If Offloaded! download the files, move them, re-upload them.
  *  - Move the files
  *  - Update Metadata
  *  - Move Backups 
  ( This might warrant a class of it's own due to complexity, or hooking into converter Models )
  */
    protected function replaceFiles($qItem, $newFileName, $args = [])
    {
        $defaults = [
            'dry_run' => false,
            'imageThreshold' => 1, // How much references before not replacing this image.
            'url' => false, 
        ];

        $args = wp_parse_args($args, $defaults);

        $url = $args['url'];       

        $replacer2 = \ShortPixel\Replacer\Replacer::getInstance();
        $setup = $replacer2->Setup();
        $setup->forSearch()->URL()->addData($url);

        $base_url = $setup->forSearch()->URL()->getBaseURL();
        //$post_ids = $this->getWpmlLanguagePostIds($qItem->item_id);

        $finder = $replacer2->Finder(['base_url' => $base_url]);

        $results = $finder->posts(['post_status' => ['publish'], 'post_fields' => ['ID']]);
        // Check postmeta. This is broader than the attached_file and designed to find pagebuilders and the like.
        $meta_results = $finder->postmeta(['post_status' => ['publish', 'inherit'], 'post_fields' => ['post_id']]);


        $imagePostCount = count($results) + count($meta_results);

        if (intval($imagePostCount) >= $args['imageThreshold']) {
            Log::addInfo('AI Replace File: Image is mentioned - ' . $qItem->item_id);
            return false;
        }


        $imageModel = $qItem->imageModel;
        $item_id = $qItem->item_id;

        $files = $imageModel->getAllFiles();

        // Ditch duplicate thumbs etc. 
        $files['files'] = array_unique($files['files']);
        $files['webp'] = array_unique($files['webp']);
        $files['avif'] = array_unique($files['avif']);


        $fs = \wpSPIO()->filesystem();

        if (isset($files['files'][$imageModel->getImageKey('original')])) {
            $baseFileObj = $files['files'][$imageModel->getImageKey('original')];
        } else {
            $baseFileObj = $files['files'][$imageModel->getImageKey('main')];
        }

        $source_url = $url = $baseFileObj->getURL();
        $base_filename = $baseFileObj->getFileBase();

        $base_url = parse_url($url, PHP_URL_PATH);
        $base_url = str_replace('.' . pathinfo($base_url, PATHINFO_EXTENSION), '', $base_url);
        $base_url = str_replace($base_filename, '', $base_url);

        $target_url = str_replace($base_filename, $newFileName, $source_url);


        $searchArray = $replaceArray = $sourceFiles = $targetFiles = [];

        foreach ($files['files'] as $key => $fileObj) {
            $searchArray[$key] = $base_url . $fileObj->getFilename();

            $sourceFiles[$key] = $fileObj;

            // The Str replace leaves the extension intact here.
            $filename = str_replace($base_filename, $newFileName, $fileObj->getFileName());
            $replaceArray[$key] = $base_url . $filename;
            $targetFiles[$key] = $fileObj->getFileDir() . $filename;
        }

        if (count($files['webp']) > 0) {
            foreach ($files['webp'] as $key => $fileObj) {
                $searchArray['webp_' . $key] = $base_url . $fileObj->getFileName();
                $sourceFiles['webp_' . $key] = $fileObj;

                $webp_filename = str_replace($base_filename, $newFileName, $fileObj->getFileName());
                $replaceArray['webp_' . $key] = $base_url . $webp_filename;

                $targetFiles['webp_' . $key] =  $fileObj->getFileDir() . $webp_filename;
            }
        }

        if (count($files['avif']) > 0) {
            foreach ($files['avif'] as $key => $fileObj) {
                $searchArray['avif_' . $key] = $base_url . $fileObj->getFileName();
                $sourceFiles['avif_' . $key] = $fileObj;

                $avif_filename = str_replace($base_filename, $newFileName, $fileObj->getFileName());
                $replaceArray['avif_' . $key] = $base_url . $avif_filename;
                $targetFiles['avif_' . $key] =  $fileObj->getFileDir() . $avif_filename;
            }
        }

        $targetFileObjs = []; // if we have to check them all anyhow, store it for moving / deleting. 
        foreach ($targetFiles as $key => $target_path) {
            $targetFileObj = $fs->getFile($target_path);
            if ($targetFileObj->exists()) {
                Log::addWarn('Replace files found filename conflict and didnt run', $targetFileObj->getFullPath());
                return false;
            }

            $targetFileObjs[$key] = $targetFileObj;
        }

        foreach ($sourceFiles as $key => $sourceFile) {
            $targetFileObj = isset($targetFileObjs[$key]) ? $targetFileObjs[$key] : null;
            if (is_null($targetFileObj)) {
                Log::addError('Source/Target mismatch in replacements. This should not happen!');
                continue;
            }

            if (false === $args['dry_run']) {
                $result = $sourceFile->move($targetFileObj);
            } else {
                Log::addInfo('[Dry-run] Would have moved file : ' . $sourceFile->getFullPath() . ' to ' . $targetFileObj->getFullPath());
            }

        /*    if (false === $args['recent_upload']) {
                if (false === $args['dry_run']) {
                    $this->createSymlink($sourceFile, $targetFileObj);
                } else {
                    Log::addInfo('[Dry-run] Would have symlinked ' . $sourceFile->getFullPath()  . ' to ' . $targetFileObj->getFullpath());
                }
            } */
        }

        // @Todo  Here probably we should check the backup and move that as well.
        $backupController = BackupController::getBackupController();
        $backupModel = $backupController->getModel($imageModel);
        if (false === $args['dry_run']) {
            $backupModel->renameBackup($newFileName);
        } else {
            Log::addInfo('[Dry-run] Would have renamed backup files to: ' . $newFileName);
        }

        $replacer = new Replacer();
        $replacer->setSource($source_url);
        $replacer->setTarget($target_url);
        $replacer->addURLArray($searchArray, $replaceArray);


        if (false === $args['dry_run']) {
            $replacer->replace();
        } else {
            Log::addInfo('Dry-Run Replacer', $searchArray);
            Log::addInfo('ReplaceArray ', $replaceArray);
        }

        $this->replaceMetaData($item_id, $base_filename, $newFileName, $args['dry_run']);

        return false;
    }

    /*
    private function createSymlink($sourceObj, $targetObj): bool
    {
        $settings = \wpSPIO()->settings();

        if (true === $settings->ai_filename_addsymlink && true === $settings->ai_symlink_checked) {
            $res = symlink($sourceObj->getFullPath(), $targetObj->getFullPath());
            if (false === $res) {
                Log::addError('Symlink failed : ' . $targetObj->getFullPath());
            }
            return $res;
        }

        return false;
    }
        */

    protected function replaceMetaData($item_id, $old_file, $new_file, $dry_run = false)
    {
        $metadata = wp_get_attachment_metadata($item_id);
        if (isset($metadata['file']) && strpos($metadata['file'], $old_file) !== false) {
            $metadata['file'] = str_replace($old_file, $new_file, $metadata['file']);
            if (true === $dry_run) {
                Log::addInfo('Dry Run, would update metadata', $metadata['file']);
            } else {
            }
        }

        if (true === $dry_run) {
            Log::addInfo('Dry Run - would update attached file with ' . $new_file);
        } else {
            $attached_file = get_attached_file($item_id);
            if (false === $attached_file && isset($metadata['file'])) {
                $attached_file = $metadata['file'];
            }

            $new_attached_file = str_replace($old_file, $new_file, $attached_file);
            update_attached_file($item_id, $new_attached_file);
        }

        if (isset($metadata['original_image']) && strpos($metadata['original_image'], $old_file) !== false) {
            $metadata['original_image'] = str_replace($old_file, $new_file, $metadata['original_image']);
        }

        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeName => $sizeData) {
                if (isset($sizeData['file']) && strpos($sizeData['file'], $old_file) !== false) {
                    $metadata['sizes'][$sizeName]['file'] = str_replace($old_file, $new_file, $sizeData['file']);
                }
            }
        }

        if (true === $dry_run) {
            Log::addInfo('Dry Run - Would have updated attachment metadata', $metadata);
        } else {
            wp_update_attachment_metadata($item_id, $metadata);
        }
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

        foreach ($results as $result) {
            $post_id = $result['post_id'];
            $content = $result['content'];

            $matches = $this->fetchImageMatches($content);
            $sources = [];
            $replaces = [];

            $image_filebase = ($imageModel->isScaled()) ? $imageModel->getOriginalFile()->getFileBase() : $imageModel->getFileBase();

            foreach ($matches as $match) {

                $frontImage = new \ShortPixel\Model\FrontImage($match);
                $src = $frontImage->src;

                // Only replace in post content the image we did
                $pattern = '/' . preg_quote($image_filebase, '/') . '(-\d+x\d+\.|\.|-scaled\.)' . $imageModel->getExtension() . '/i';
                if (preg_match($pattern, $src) !== 1) {
                    continue;
                }

                /*   if (strpos($src, $aiData['replace_filebase']) === false)
             {
                continue; 
             } */

                $do_replace = false;

                if (isset($aiData['alt']) && false === is_int($aiData['alt'])) {
                    $frontImage->alt = $aiData['alt'];
                    $do_replace = true;
                }
                if (isset($aiData['caption']) && false === is_int($aiData['caption'])) {
                    $frontImage->caption = $aiData['caption'];
                    $do_replace = true;
                }

                if (true === $do_replace) {
                    $sources[] = $match;
                    $replaces[] = $frontImage->buildImage();
                }
            }

            if (count($sources) > 0 && count($replaces) > 0) {
                Log::addInfo('Running Ai Replace : ', [$aiData, $sources, $replaces]);
                $content = $replacer2->replaceContent($content, $sources, $replaces, false, true);
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
        if (false === $bool) {
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
        if (substr($text, -1) !== '.' && true === apply_filters('shortpixel/ai/check_period', true)) {
            $text .= '.';
        }

        return $text;
    }

    // @todo Should be moved to protected / called via sendToProcessing in future ( now also called via ajaxControl )
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
            'post_title' => $original['post_title'],
            'replace_filebase' => $generated['filebase'],
        ];

        $aiModel->revert();
        AiDataModel::flushModelCache($item_id);

        // The results is what the system finds on used images in the database for this base url. 
        $this->replaceImageAttributes($qItem, $aiData);


        // @todo This probably needs to reverse file renaming as well? 

        $aiData = $aiModel->getCurrentData();

        $qItem->addResult([
            'is_done' => true,
            'is_error' => false,
            'message' => __('AI Data reverted ', 'shortpixel-image-optimiser'),
            'apiStatus' => ApiController::STATUS_NOT_API,
        ]);
        $this->finishItemProcess($qItem);


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
            if (false !== $metacheck && is_array($metacheck)) {
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
        // If the generated data included file rename, pass the URL for UI update 
        if (isset($generated['filebase'])) {
            $generated['url'] = $image_url;
        }

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


        $metadata['snippet'] = $view->returnView('snippets/part-aitext');

        $metadata['generated'] = $generated;
        $metadata['original'] = $original;
        $metadata['current'] = $current;
        $metadata['action'] = $qItem->data()->action;
        $metadata['item_id'] = $item_id;
        $metadata['labels']  = $dataItems; // Used in bulk JS 

        return $metadata;
    }

    /**
     * Generate the AI Data so that it can be shown public-facing. 
     * 
     * @param mixed $generated 
     * @param mixed $current 
     * @param mixed $original 
     * @param bool $isPreview 
     * @return (string[]|mixed)[] 
     */
    public function formatGenerated($generated, $current, $original, $isPreview = false)
    {

        $fields = ['alt', 'caption', 'description', 'post_title', 'filebase'];
        $dataItems = [];

        $labels = $this->getDataLabels();

        // Statii from AiDataModel which means generated is not available (replace for original/current?) 
        $statii = [AiDataModel::F_STATUS_PREVENTOVERRIDE, AiDataModel::F_STATUS_EXCLUDESETTING];

        foreach ($fields as $name) {
            if (false === isset($generated[$name])) {
                continue;
            }
            $value = $generated[$name];


            if (false === is_null($value) && false === is_int($value) && strlen($value) > 1) {

                $dataItems[] = isset($labels[$name]) ? $labels[$name] : ucfirst($name);
            }
            if (is_int($value) && in_array($value, $statii)) {

                // Preview needs to know if generated or excluded. -3 should be capture in the UX!
                $value = -3;
                $generated[$name] = $value;
            }
        }

        return [$dataItems, $generated];
    }
} // class 
