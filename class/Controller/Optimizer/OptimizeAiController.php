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
use ShortPixel\External\Offload\Offloader;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Queue\QueueItemResult;
use ShortPixel\Replacer\Replacer;
use ShortPixel\ViewController as ViewController;

/**
 * Manages AI feature processing: alt-text generation, image upscaling, and background removal.
 *
 * Uses AiController (not ApiController) as its API backend. The two-phase flow is:
 *   1. enqueueItem() with action='requestAlt' submits the image; AiController returns a
 *      remote_id and the item is re-queued with action='retrieveAlt' via next_action.
 *   2. On the retrieve pass handleAPIResult() receives aiData and calls HandleSuccess(),
 *      which applies the 'shortpixel/ai/success' filter, formats the data, saves it to
 *      AiDataModel, replaces in-post image attributes via Replacer2, and optionally
 *      renames physical files when the AI generates a new filename.
 *
 * Direct (preview_only) calls skip the queue and run sendToProcessing() + handleAPIResult()
 * synchronously within enqueueItem().
 *
 * @package ShortPixel\Controller\Optimizer
 */
// Class for AI Operations.  In time split off OptimizeController / Optimize actions to a main queue runner seperately.
class OptimizeAiController extends OptimizerBase
{

    /** Binds the AI API controller and sets the API name for queue result handling. */
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


    /**
     * Dispatches the queue item to the correct AI handler based on its action.
     *
     * 'undoAltData' is handled locally via undoAltData(). All other actions
     * (requestAlt, retrieveAlt) are delegated to AiController::processMediaItem().
     *
     * The 'undoAltData' case name must match what Queue::prepareItems() /
     * QueueItem::undoAltDataAction() enqueue and what
     * QueueItem::getApiController() maps; otherwise bulk undo items are
     * mis-dispatched to the AI API.
     *
     * @param QueueItem $qItem The item to process.
     * @return mixed Return value of undoAltData() for the undoAltData action; void otherwise.
     */
    public function sendToProcessing(QueueItem $qItem)
    {

        $action = $qItem->data()->action;

        switch ($action) {
            case 'undoAltData': 
                return $this->undoAltData($qItem);
                break;
            case 'redoAiReplacement':
                $this->redoAiReplace($qItem);
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

    /**
     * Checks whether the AI data model for this attachment is in a processable state.
     *
     * Loads the AiDataModel for the attachment and queries its isProcessable() status.
     * On failure, adds an error result to the queue item with FILE_STATUS_ERROR and the
     * human-readable reason from the model.
     *
     * @param QueueItem $qItem The queue item to validate.
     * @return bool True if the AI model is processable; false otherwise.
     */
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

    /**
     * Enqueues an AI action item, either synchronously (preview_only) or via the async queue.
     *
     * 'requestAlt' and 'retrieveAlt' are the supported actions. When preview_only is true
     * the item is processed inline: sendToProcessing() and handleAPIResult() are called
     * immediately and the result is returned directly. Otherwise the item is added to the
     * async queue. An optional 'queue_list_order' argument is persisted on the item data
     * and propagated through subsequent actions.
     *
     * @param QueueItem $qItem The item to enqueue.
     * @param array     $args  Must include 'action'; optionally 'preview_only' and 'queue_list_order'.
     * @return \stdClass|QueueItemResult Queue status object, or the direct result on preview_only.
     */
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


    /**
     * Processes the AI API result stored on the queue item after sendToProcessing().
     *
     * Error path: if is_done, marks the item failed, calls HandleItemError(), and finishes
     * the process. Non-done errors are currently silently retried.
     *
     * requestAlt path: STATUS_WAITING means the request was accepted and the item should
     * wait for the next poll; a numeric remote_id triggers finishItemProcess() which chains
     * the retrieveAlt next_action.
     *
     * retrieveAlt path: when aiData is present on the result, delegates to HandleSuccess()
     * which applies the 'shortpixel/ai/success' filter and persists the data.
     *
     * undoAltData path: early-returns immediately —
     * undoAltData() already adds its result and finishes the item, and letting
     * the undo result (which carries aiData) fall through to HandleSuccess()
     * would resurrect the reverted aipostmeta record and double-extension
     * rename the undone files.
     *
     * @param QueueItem $qItem The queue item whose result should be evaluated.
     * @return void
     */
    public function handleAPIResult(QueueItem $qItem)
    {
        // No API handling needed for undo, it does everything already.  Might be solved more elegantly in the future perhaps.
        if ($qItem->data()->action === 'undoAltData')
        {
             return; 
        }
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

    /**
     * Formats raw AI result data before it is persisted to the database.
     *
     * Applies processTextResult() (capitalisation, period) to text fields (alt, caption,
     * description — never filebase). Any field that comes back as numeric 1 from the API is
     * treated as "not generated" and replaced with an empty string. Applies configured
     * prefix/postfix settings for each field. Always stores the original file base in
     * 'original_filebase' for later use in replaceFiles().
     *
     * @param array     $aiData Associative array of AI-generated field values.
     * @param QueueItem $qItem  The queue item providing the image model and data context.
     * @return array Formatted copy of $aiData.
     */
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
        $textItems = ['alt', 'caption', 'description'];
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
            'filebase' => ['prefix' => 'ai_filename_prefix', 'postfix' => 'ai_filename_postfix'],
        ];

        foreach ($prefixPostfixMap as $field => $affixes) {
            if (isset($aiData[$field]) && !empty($aiData[$field]) && $aiData[$field] !== -3) {

                $prefix = $settings->{$affixes['prefix']};
                $postfix = $settings->{$affixes['postfix']};
                $spacer = ($field === 'filebase') ? '' : ' ';

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

    /**
     * Returns a translated label map for the AI data fields used in bulk and settings UIs.
     *
     * @return string[] Associative array of field name → translated display label.
     */
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

    /**
     * Persists AI-generated data and performs all post-processing on a successful retrieve result.
     *
     * Applies the 'shortpixel/ai/success' filter to the raw aiData, formats it via
     * formatResultData() — SKIPPED for 'undoAltData' items, so
     * restored original values are saved verbatim instead of being re-formatted
     * (filename prefix/postfix etc.) — and saves it through
     * AiDataModel::handleNewData().
     *
     * handleAPIResult() early-returns for 'undoAltData' items, so bulk-undo
     * results never land here (they would resurrect the just-deleted
     * aipostmeta row, warn on the missing 'original_filebase' key, and
     * double-extension rename every undone file). The format-skip guard
     * below is therefore dead code in practice, kept as defense-in-depth.
     * Then:
     *   - Replaces in-post image attributes (alt, caption) via replaceImageAttributes().
     *   - Renames physical files and updates WordPress metadata via replaceFiles() when
     *     the AI returned a new filebase that differs from the current one, and only when
     *     the image is not already widely linked in published content.
     *   - Appends improvements, preview URLs, and the final aiData/aiDataLabels to the
     *     queue item result for bulk UI consumption.
     * The item is blocked during processing and unblocked before finishItemProcess() is called.
     *
     * @param QueueItem $qItem The queue item with aiData on its result object.
     * @return void
     */
    protected function HandleSuccess(QueueItem $qItem)
    {
        $action = $qItem->data()->action;
    
        $aiData = $qItem->result()->aiData;
        $aiData = apply_filters('shortpixel/ai/success', $aiData, $qItem);

        if ('undoAltData' !== $action) // Don't format things on undo.
        {
            $aiData = $this->formatResultData($aiData, $qItem);
        }
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

        $this->replaceImageAttributes($qItem, $aiData);
        $imageModel = $qItem->imageModel;

        // If the file was just uploaded, assume it's not already widely linked and doesn't need replacing / symlinking 
        // ( Maybe just replacing? )
        // Generic number of strlen here. Disallow filename not to be very short, because because. 
        if (isset($aiData['filebase']) && is_string($aiData['filebase']) && strlen($aiData['filebase']) > 5) // ?? 
        {
            // @todo This and the ReplaceAtttributes is similar code. (Replacer2's Setup::getInstance() returns a fresh instance per call, so URL data does not leak between items.)
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

            if ($currentFileBase !== $aiData['filebase']) {
                $args = [
                    'dry_run' => false,
                    'recent_upload' => is_bool($qItem->data()->recent_upload) ? $qItem->data()->recent_upload : false,
                    'url' => $url,
                ];

                // Every WPML language is queued for AI and each answer
                // carries its own filebase, so only the main language may rename the shared
                // file. See MediaLibraryModel::getWPMLDuplicates() for what this does NOT cover.
                $wpmlAllDuplicates = $imageModel->getWPMLDuplicates(true); 
                $do_replace_files = true; 
                if (count($wpmlAllDuplicates) > 0)
                {
                    $do_replace_files = false; 
                    if (isset($wpmlAllDuplicates[$item_id]) &&  true === $wpmlAllDuplicates[$item_id]['is_main_language'])
                    {
                        $do_replace_files = true;
                    }
                }
                        
                if (true === $do_replace_files)
                {
                    $files_replaced = $this->replaceFiles($qItem, $aiData['filebase'], $args);
                    if (true === $files_replaced) {
                        $qItem->addResult(['redirect' => 'reload']);
                    }
                }
                else 
                {
                    Log::addInfo('Replace files cancelled due to duplicate situation'); 
                }
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
     * Disabled — replaced by the per-result WPMLCheckReplace() guard below,
     * which filters at replace time instead of pre-filtering the finder query.
     *
     * @param int $item_id
     * @return int[]
     */
    /* protected function getWpmlLanguagePostIds($item_id)
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
    } */

    /**
     * Decide whether an AI text replacement may run on a given post (WPML guard).
     *
     * When WPML is active, both the target post and the queue item (attachment)
     * are resolved through the `wpml_post_language_details` filter; replacement
     * is only allowed when both languages are known and identical, so pages in
     * other languages are left untouched. Without WPML the check always passes.
     *
     * @param int $post_id       The post_id of the page / post to replace in.
     * @param int $queue_item_id The attachment ID of the queue item image.
     * @return bool True when replacing on this post is allowed.
     */
    protected function WPMLCheckReplace($post_id, $queue_item_id): bool
    {
        if (!\wpSPIO()->env()->plugin_active('wpml')) {
            return true;
        }

        $language = apply_filters('wpml_post_language_details', null, $post_id);
        $language_queue = apply_filters('wpml_post_language_details', null, $queue_item_id);

        if ((!is_array($language) || empty($language['language_code'])) || !is_array($language_queue) || empty($language_queue['language_code'])) {
            return false;
        }

        if ($language['language_code'] !== $language_queue['language_code']) {
            return false;
        }

        return true;
    }


    /** Replace Image Attributes ( others? ) on images via BaseURL
     *
     * Resolves this item's (original-file) URL, feeds it to a FRESH
     * replacer2 Setup (Setup::getInstance() is intentionally NOT a
     * singleton — a shared instance would accumulate URLs across items
     * and getBaseURL() would then search with the first item's URL,
     * leaving every later image's in-content alt untouched), and
     * runs the Finder over post_content. Matching posts are passed to
     * the handleReplace() callback, which does the actual attribute
     * replacement and save.
     *
     * THREE-STATE content-replacement toggle: reads
     * settings->ai_content_replace which is one of:
     *   - 'none'    : early-return here — no post_content is ever touched.
     *                 Media Library alt meta writes are unaffected (they
     *                 flow through AiDataModel, not this method).
     *   - 'missing' : delegate to handleReplace() which only fills empty
     *                 in-content alts.
     *   - 'overwrite': delegate to handleReplace() which always writes.
     *
     * Edit locks are checked per containing post inside handleReplace()
     * (wp_check_post_lock($post_id), function_exists()-guarded because it
     * is admin-only and undefined under WP-CLI / cron).
     *
     * UNDO support: when the queue item action is 'undoAltData',
     * $prevAiData carries the previously GENERATED data so handleReplace()
     * can apply the exact-match restore rule (see its docblock). Both the
     * single-item and the bulk undo paths reach here.
     *
     * @todo The 'none' early-return ALSO fires when called from undoAltData(),
     *       so after switching to 'none' Undo silently no longer restores post
     *       content (only the Media Library alt reverts). Needs an undo-aware
     *       bypass or a separate reason parameter.
     * @todo wp_check_post_lock() returns false for the CURRENT user's own lock,
     *       so a post open in the same admin's editor is not skipped. Fix: check
     *       _edit_lock freshness regardless of owner.
     *
     * @param QueueItem $qItem
     * @param array $aiData Generated AI data (alt / caption are strings, or int status codes when not generated); for undo, the ORIGINAL data to restore.
     * @param array $prevAiData For undo only: the previously generated AI data used for the exact-match comparison.
     * @return array|void Finder results; void when alt AND caption are int status codes, or when 'none' mode is active.
     */
    protected function replaceImageAttributes(QueueItem $qItem, $aiData, $prevAiData = [])
    {
        // New setting that allows skipping post-content modifications entirely.
        $contentReplace = \wpSPIO()->settings()->ai_content_replace ?? 'missing';
        if ($contentReplace === 'none') {
            Log::addDebug('ai_content_replace=none: skipping replaceImageAttributes for ' . $qItem->item_id);
            return;
        }
        if (is_int($aiData['alt']) && is_int($aiData['caption'])) {
            Log::addInfo('Alt and Caption returned integer/status, not replacing : ' . $qItem->item_id);
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

        $finder = $replacer2->Finder(['base_url' => $base_url, 'callback' => [$this, 'handleReplace'], 'return_data' => [
            'aiData' => $aiData,
            'qItem' => $qItem,
            'prevAiData' => $prevAiData,
        ]]);

        $results = $finder->posts();
        return $results;
    }

    /* @todo  The file mover should: 
  *  - If Offloaded! download the files, move them, re-upload them.
  *  - Move the files
  *  - Update Metadata
  *  - Move Backups 
  ( This might warrant a class of it's own due to complexity, or hooking into converter Models )
  */
    /**
     * Renames all physical files for an attachment to use a new AI-generated filename base.
     *
     * Unless recent_upload is true, first checks whether published content already uses this
     * image (see "Usage check" below); if the number of references meets or exceeds
     * imageThreshold (default 1) the rename is skipped. Otherwise:
     *   1. Collects all image file objects (main, thumbnails, WebP, AVIF) from the image model.
     *   2. Checks that no target filename already exists (conflict guard).
     *   3. COPIES each source file to its new name (successful copies are
     *      collected in $copySource; the sources are deleted only at the very
     *      end, after the metadata/content rewrite — some plugins (WPML) can
     *      deny the deletion otherwise). This local copy also runs when an
     *      offloader already renamed the remote objects, as long as the
     *      image is not virtual (i.e. the files are on disk too).
     *   4. Updates attachment metadata + attached-file postmeta for the item
     *      AND for every getWPMLDuplicates() sibling (see replaceMetaData()).
     *      The sibling list is collected BEFORE step 3, while every sibling
     *      still shares this item's attached file (the WPML lookup only
     *      accepts siblings with the SAME attached file); it is passed as
     *      $args['duplicates'] and the siblings get the item's NEW values
     *      (slug, _wp_attached_file, metadata).
     *   5. Renames backup files via BackupController.
     *   6. Replaces source/target URL pairs in post content via Replacer2.
     *   7. Deletes the successfully-copied source files (also when an
     *      offloader applied the rename).
     * Supports a dry_run mode that logs the planned operations and changes
     * nothing; it always returns false (dry_run is also passed on to the
     * offloader filter). Its bail-out logs "Copy failed to copy anything",
     * which is misleading for a dry-run.
     *
     * URL replacement is anchored to the basename portion of the URL, so a
     * filename base that also appears in a directory segment does not rename
     * the directory in the Replacer URLs.
     *
     * Offloaded / virtual media:
     *   - Unsupported virtual offloaders (S3-Uploads, InfiniteUploads,
     *     Bitpoke Stack — anything but WP Offload Media, see
     *     isVirtualSupported()) are refused up front: a virtual image
     *     returns false before anything is touched, and the "Change
     *     Filename" field is not rendered for them (getAltData() passes
     *     is_renameable=false to part-aitext.php).
     *   - The rename is first offered to the `shortpixel/image/replace_files`
     *     filter (false, $sourceFiles, $imageModel, $newFileBase, $dry_run).
     *     An offloader returns true when it renamed the remote files itself
     *     (wpOffload::replaceFiles() does this for provider-served items).
     *     The local copy loop is then skipped only for VIRTUAL images; when
     *     the files are on disk too it still runs, so the local copies follow
     *     the remote rename. When the filter declines and the image is
     *     virtual, the rename is refused ("Virtual system fails renaming files").
     *   - The "Copy failed to copy anything" bail-out only fires when the
     *     offloader did NOT apply the rename, so both local+remote and
     *     REMOTE-ONLY images carry on to replaceMetaData() / duplicates /
     *     backup / Replacer.
     *
     * When not a single copy succeeds the method returns false before
     * rewriting anything.
     *
     * WPML / Polylang: siblings are updated through $args['duplicates'] (see
     * step 4), which also covers renaming FROM A TRANSLATION (WPML core only
     * syncs _wp_attached_file from originals). Items queued as a non-main
     * WPML language (data()->is_duplicate) are never renamed here; the
     * main-language gate in HandleSuccess() enforces the same.
     *
     * @todo wpOffload::replaceFiles() returns true ("applied") when NO provider
     *       object matched, and this method trusts it: local+remote moves the
     *       local files while the offload item keeps the OLD key; remote-only
     *       reports success for a filename that exists nowhere. Fix: return
     *       false there. Provider exceptions also escape uncaught.
     * @todo A PARTIAL copy failure still returns true — the DB rewrite runs for
     *       ALL pairs, renameBackup() and $replacer->replace() results are
     *       discarded, and there is no rollback.
     *
     * Usage check: runs unless $args['recent_upload'] is exactly true, so a
     * missing or lost flag errs on the side of NOT renaming. It LIKE-matches
     * the extension-stripped URL path (so thumbnail sizes count too) against
     * post_content of published posts, and against postmeta of published
     * posts and attachments — excluding the item itself and its WPML/Polylang
     * siblings, whose own metadata is not a use. WP core stores RELATIVE paths
     * in _wp_attached_file / _wp_attachment_metadata, so what counts is a full
     * URL: an image placed in post content or in page-builder data. Drafts,
     * scheduled and private posts are not counted.
     *   - New uploads: the add_attachment hook records the id and
     *     handleAiImageUploadHook() queues it with recent_upload=true;
     *     requestAltAction() / retrieveAltAction() carry the flag to
     *     HandleSuccess(), so a new upload is renamed without the check.
     *   - Bulk and Media Library AI runs: no flag, so the check runs and an
     *     image already used in published content keeps its name.
     *   - Manual Change Filename: ajax_replaceFile() passes recent_upload=true
     *     — the user asked for this rename explicitly, so the check is skipped.
     *
     * Editor feedback: after a non-dry-run replace the new
     * file URL is stored on the queue result as
     * replaced_content['replaced_url'] (a string key next to the per-post
     * integer keys handleReplace() writes) so the Gutenberg consumer can
     * refresh the block's url. The consumer (screen-media.js UpdateGutenBerg)
     * reads alt/caption and replaced_url independently, so a rename-only
     * result also refreshes the block.
     *
     * @param QueueItem $qItem       The queue item providing the image model.
     * @param string    $newFileBase New filename base (without extension) from the AI.
     * @param array     $args        Optional: dry_run (bool), imageThreshold (int), url (string), recent_upload (bool).
     * @return bool True if it made it to the end of the replace functions; false on an unsupported virtual offloader, duplicate item, usage-guard block, filename conflict, a declined virtual rename, a dry-run, or when no file was copied (see the offloader @todo above for when true is returned although nothing was renamed).
     */
    protected function replaceFiles($qItem, $newFileBase, $args = []): bool
    {
        $defaults = [
            'dry_run' => false,
            'imageThreshold' => 1, // How much references before not replacing this image.
            'recent_upload' => false,
        ];

        $args = wp_parse_args($args, $defaults);
        $imageModel = $qItem->imageModel;
        $item_id = $qItem->item_id;

        if ($imageModel->is_virtual())
        {
            $supported = $this->isVirtualSupported(); 
            if (false === $supported)
            {
                 Log::addInfo('Offloaded item not supported for renaming -' . $item_id ); 
                 return false; 
            }
        }

        if (true === $qItem->data()->is_duplicate)
        {
            Log::addInfo('Duplicate image detected, not replacing the file' . $item_id);
            return false; 
        }

        if (true === $imageModel->isScaled()) {
            $url = $imageModel->getOriginalFile()->getURL();
        } else {
            $url = $imageModel->getUrl();
        }

        // S3 offload always puts URL to the S3.  Which causes issue replacing URls when timestamps are in use 
        // To prevent issues, just check the (translated / local-pointed filepath ) and create URL on basis of that. 
        if (\wpSPIO()->env()->plugin_active('s3-offload'))
        {
            $fs = \wpSPIO()->filesystem();  
            $urlFile = ($imageModel->isScaled()) ? $imageModel->getOriginalFile() : $imageModel;      
            $url = $fs->pathToUrl($urlFile); 
        }

        // Get duplicates before moving / copying files to otherwise it might not recognize duplicate files after renaming ( attached_file issue )
        if (method_exists($imageModel, 'getWPMLDuplicates')) {
            $duplicates = $imageModel->getWPMLDuplicates();
        }

        // If recent upload is true, bypass the check if the image is used. 
        if (true !== $args['recent_upload']) {
            //$url = $args['url'];

            $replacer2 = \ShortPixel\Replacer\Replacer::getInstance();
            $setup = $replacer2->Setup();
            $setup->forSearch()->URL()->addData($url);

            $base_url = $setup->forSearch()->URL()->getBaseURL();

            $finder = $replacer2->Finder(['base_url' => $base_url]);

            $results = $finder->posts(['post_status' => ['publish'], 'post_fields' => ['ID']]);
            // Check postmeta. This is broader than the attached_file and designed to find pagebuilders and the like.

            $exclude_post_ids = [$item_id]; 
            if (isset($duplicates) && is_array($duplicates) && count($duplicates) > 0)
            {
                 $exclude_post_ids = array_merge($exclude_post_ids, $duplicates);
            }

            $meta_results = $finder->postmeta(['post_status' => ['publish', 'inherit'], 'post_fields' => ['post_id'], 'exclude_post_ids' => $exclude_post_ids]);
            $imagePostCount = count($results) + count($meta_results);

            if (intval($imagePostCount) >= $args['imageThreshold']) {
                Log::addInfo('AI Replace File: Image is mentioned - ' . $qItem->item_id);
                return false;
            }
        }

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

        $source_url = $url;
        $base_filename = $baseFileObj->getFileBase();

        $base_url = parse_url($url, PHP_URL_PATH);
        $base_url = trailingslashit(dirname($base_url));

        $target_filename = str_replace(
            $base_filename,
            $newFileBase,
            basename($source_url)
        );
        $target_url = str_replace(basename($source_url), $target_filename, $source_url);

        $searchArray = $replaceArray = $sourceFiles = $targetFiles = [];

        foreach ($files['files'] as $key => $thumbObj) {
            $searchArray[$key] = $base_url . $thumbObj->getFilename();

            $sourceFiles[$key] = $thumbObj;

            // The Str replace leaves the extension intact here.
            $filename = str_replace($base_filename, $newFileBase, $thumbObj->getFileName());
            $replaceArray[$key] = $base_url . $filename;
            $targetFiles[$key] = $thumbObj->getFileDir() . $filename;
        }

        if (count($files['webp']) > 0) {
            foreach ($files['webp'] as $key => $fileObj) {
                $searchArray['webp_' . $key] = $base_url . $fileObj->getFileName();
                $sourceFiles['webp_' . $key] = $fileObj;

                $webp_filename = str_replace($base_filename, $newFileBase, $fileObj->getFileName());
                $replaceArray['webp_' . $key] = $base_url . $webp_filename;

                $targetFiles['webp_' . $key] =  $fileObj->getFileDir() . $webp_filename;

               // Update meta value in postmeta as well.  
               if (isset($files['files'][$key]))
               {
                     $files['files'][$key]->setMeta('webp', $webp_filename);
               }
            }
        }

        if (count($files['avif']) > 0) {
            foreach ($files['avif'] as $key => $fileObj) {
                $searchArray['avif_' . $key] = $base_url . $fileObj->getFileName();
                $sourceFiles['avif_' . $key] = $fileObj;

                $avif_filename = str_replace($base_filename, $newFileBase, $fileObj->getFileName());
                $replaceArray['avif_' . $key] = $base_url . $avif_filename;
                $targetFiles['avif_' . $key] =  $fileObj->getFileDir() . $avif_filename;

               if (isset($files['files'][$key]))
               {
                     $files['files'][$key]->setMeta('avif', $avif_filename);
               }
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

        $copySource = [];  // Copy now, delete the source files after metadata redo, because some plugins (WPML) can deny deletion otherwise
        $applied = apply_filters('shortpixel/image/replace_files', false, $sourceFiles, $imageModel, $newFileBase, $args['dry_run']);

        if (false === $applied && true === $imageModel->is_virtual())
        {
            Log::addError('Virtual system fails renaming files, bailing out' . $item_id, $sourceFiles);
            return false; 
        } 
        // Note here; both can be applied and not respond as is_virtual if the files are also on local disk, not just on remote!
        elseif (false === $applied || false === $imageModel->is_virtual()) 
        {
            foreach ($sourceFiles as $key => $sourceFile) {
                $targetFileObj = isset($targetFileObjs[$key]) ? $targetFileObjs[$key] : null;
                if (is_null($targetFileObj)) {
                    Log::addError('Source/Target mismatch in replacements. This should not happen!');
                    continue;
                }

                if (false === $args['dry_run']) {
                    $result = $sourceFile->copy($targetFileObj);
                    if (true === $result) {
                        $copySource[] = $sourceFile;
                    }
                }
                else {
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

            // Copies are done, so update meta as well by saving. 
            if (false === $args['dry_run'] && count($copySource) > 0)
            {
                $imageModel->saveMeta();
            }
        }
        elseif (true === $applied)
        {
            if (false === $args['dry_run'])
            {
                $imageModel->saveMeta();
            }
        }

        if ((count($copySource) === 0 || true === $args['dry_run']) && false === $applied  )
        {
             Log::addError('Copy failed to copy anything. Bailing out' . $item_id, $sourceFiles); 
             return false; 
        }

        if (isset($duplicates) && count($duplicates) > 0) 
        {
             $args['duplicates'] = $duplicates; 
        }

        $this->replaceMetaData($item_id, $base_filename, $newFileBase, $args);

        // Trigger updates 
        /*if (isset($duplicates) && count($duplicates) > 0) {
            foreach ($duplicates as $duplicate_id) {
                // Update the duplicates
                $args['is_duplicate'] = true;
                $this->replaceMetaData($duplicate_id, $base_filename, $newFileBase, $args);
            }
        } */

        // @Todo  Here probably we should check the backup and move that as well.
        $backupController = BackupController::getBackupController();
        $backupModel = $backupController->getModel($imageModel);
        if (false === $args['dry_run']) {
            $backupModel->renameBackup($newFileBase);
        } else {
            Log::addInfo('[Dry-run] Would have renamed backup files to: ' . $newFileBase);
        }

        $replacer = new Replacer();
        $replacer->setSource($source_url);
        $replacer->setTarget($target_url);
        $replacer->addURLArray($searchArray, $replaceArray);


        if (false === $args['dry_run']) {
            $replacer->replace();
            $result_replaced_content = $qItem->result()->replaced_content;

            // Doesn't have a post_id here but will piggyback on the alt / other results and hope.
            $result_replaced_content['replaced_url'] = $target_url;
            $result_replaced_content['target_filename'] = $target_filename;
                        
            $qItem->result()->replaced_content = $result_replaced_content;      
        } else {
            Log::addInfo('Dry-Run Replacer', $searchArray);
            Log::addInfo('ReplaceArray ', $replaceArray);
        }

        if (isset($copySource) && is_array($copySource)) {
            foreach ($copySource as $fileItem) {
                $fileItem->delete();
            }
        }

        return true;
    }

    /**
     * Entry point for the manual "Change Filename" AJAX action (media/replaceFileName).
     *
     * Derives the new file base via pathinfo(basename(), PATHINFO_FILENAME) —
     * this strips any directory prefix (neutralising path traversal) AND the
     * extension, so the rename can never change a file's extension. Calls
     * replaceFiles() with recent_upload=true, deliberately bypassing the
     * usage-count guard: the user explicitly asked for the rename, including
     * for images already referenced in content (see the guard NOTE on
     * replaceFiles()). Fully decoupled from AI state — works on attachments
     * that never had AI data.
     *
     * Callers may pass $args, merged over the defaults
     * (url, recent_upload=true, dry_run=false) with wp_parse_args() — so a
     * caller could also turn the usage guard back on with
     * recent_upload=false. AjaxController::replaceFileName() passes none.
     *
     * @param QueueItem $qItem       Queue item wrapping the image model.
     * @param string    $newFileName Sanitised filename from the request (may include extension).
     * @param array     $args        Optional overrides for replaceFiles(): url, recent_upload, dry_run.
     * @return bool Result of replaceFiles().
     */
    public function ajax_replaceFile($qItem, $newFileName, $args = [])
    {
        $imageModel = $qItem->imageModel;

        $baseReplace = pathinfo(basename($newFileName), PATHINFO_FILENAME);


        $defaults = [
            'recent_upload' => true,
            'dry_run' => false, 
        ];

        $args = wp_parse_args($args, $defaults);

        $result = $this->replaceFiles($qItem, $baseReplace, $args);

        return $result;
    }

    /**
     * Re-run the in-content attribute replacement from STORED AI data.
     *
     * Recovery path (Tools → "Redo Ai Replacement") for items whose
     * aipostmeta row is GENERATED but whose embedding posts missed the
     * replacement (e.g. when the replacer2 Setup was a shared singleton). No
     * API request is made: the generated data is read from AiDataModel and
     * pushed through replaceImageAttributes(). Queue dispatch reaches this
     * via sendToProcessing()'s `$this->redoAiReplace()` call — PHP method
     * names are case-insensitive, so that resolves here.
     *
     * @param QueueItem $qItem The redoAiReplacement queue item.
     * @return void Marks the item done (STATUS_NOT_API) via addResult().
     */
    public function redoAIReplace($qItem)
    {
        $imageModel = $qItem->imageModel;
        $item_id = $imageModel->get('id');

        $aiModel = AiDataModel::getModelByAttachment($item_id, 'media');
        $aiData = $aiModel->getGeneratedData();

        $this->replaceImageAttributes($qItem, $aiData);

        $this->finishItemProcess($qItem);


        $qItem->addResult([
            'is_done' => true,
            'is_error' => false,
            'message' => __('Item checked ', 'shortpixel-image-optimiser'),
            'apiStatus' => ApiController::STATUS_NOT_API,
        ]);
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

    /**
     * Updates WordPress attachment metadata and the attached-file postmeta to reflect a renamed file.
     *
     * Replaces occurrences of $old_file with $new_file in the 'file', 'original_image', and
     * per-size 'file' entries of the attachment metadata array, then calls
     * wp_update_attachment_metadata(). Also updates the _wp_attached_file postmeta via
     * update_attached_file() — for the item AND for the WPML/Polylang
     * siblings in $args['duplicates'] (they share the file, so each
     * receives the item's NEW post_name, _wp_attached_file and full metadata
     * array). In dry_run mode all changes are logged but not persisted.
     *
     * Note: when dry_run is true the metadata 'file' string replacement is computed but
     * wp_update_attachment_metadata() is not called; the replaced $metadata variable is
     * only logged and then silently discarded.
     *
     * The attached-file update reads `get_attached_file($item_id, true)`
     * (UNFILTERED): for a file missing locally WP Offload Media's filter
     * returns the remote location (a stream-wrapper path s3://…, or the
     * provider URL), which would otherwise be stored in _wp_attached_file
     * instead of the relative "YYYY/MM/name.jpg".
     *
     * Attachment POST fields: when the metadata 'file' still holds the old
     * name, the attachment post is updated through wp_update_post() —
     * `post_name` (the attachment slug, i.e. the attachment page URL) follows
     * the new file base. `post_title` is left alone (it may be a custom
     * title). The
     * `guid` assignment in the same block is a NO-OP on every setup:
     * wp_insert_post() re-reads the stored guid for an existing post
     * (wp-includes/post.php, the $update branch) and discards any passed
     * value. Leaving it
     * unchanged is what WordPress intends, and SPIO's own Polylang duplicate
     * lookup in getWPMLDuplicates() matches on guid. wp_update_post() also
     * fires the save_post / attachment_updated hooks for the attachment.
     *
     * The wp_update_attachment_metadata() call is wrapped in the
     * `shortpixel/converter/prevent-offload` / `-off` actions, so
     * WP Offload Media does not re-upload the files on the metadata update.
     *
     * @param int    $item_id  WordPress attachment post ID.
     * @param string $old_file Original filename base to replace.
     * @param string $new_file New filename base to substitute.
     * @param array  $args     Optional: dry_run (bool, log-only mode), duplicates (int[], WPML/Polylang siblings that receive the item's new values).
     * @return void
     */
    protected function replaceMetaData($item_id, $old_file, $new_file, $args = [])
    {
        $defaults = [
            'dry_run' => false,
       //     'is_duplicate' => false,
            'duplicates' => [], 
        ];

        $args = wp_parse_args($args, $defaults);

        $dry_run = $args['dry_run'];
       // $is_duplicate = $args['is_duplicate'];

        $metadata = wp_get_attachment_metadata($item_id);
        if (isset($metadata['file']) && strpos($metadata['file'], $old_file) !== false) {

            if (false === $dry_run)
            {
                $post = get_post($item_id); 
                $guid_replacement = $this->replaceFileBaseInPath($metadata['file'], $old_file, $new_file);
                $post->post_name = $new_file; 
                $post->guid = str_replace($metadata['file'], $guid_replacement, $post->guid);
                wp_update_post($post);

                foreach($args['duplicates'] as $duplicate_id)
                {
                   $dup_post = get_post($duplicate_id);
                   $dup_post->guid = $post->guid; 
                   $dup_post->post_name = $new_file; 
                   wp_update_post($dup_post);     
                }
            }
            // This fixes situation where dirname is similar to image name 
            $filebase = trailingslashit(pathinfo($metadata['file'], PATHINFO_DIRNAME));
            $metadata['file'] = $filebase . $this->replaceFileBaseInPath(basename($metadata['file']), $old_file, $new_file);
            if (true === $dry_run) {
                Log::addInfo('Dry Run, would update metadata', $metadata['file']);
            }


        }

      //  if (false === $is_duplicate) // Duplicate WPML items somehow update the attached_file but not the metadata
      //  {
            $attached_file = get_attached_file($item_id, true);
            if (false === $attached_file && isset($metadata['file'])) {
                $attached_file = $metadata['file'];
            }

            $filebase = trailingslashit(pathinfo($attached_file, PATHINFO_DIRNAME));
            $new_attached_file = $filebase . $this->replaceFileBaseInPath(basename($attached_file), $old_file, $new_file);

            if (true === $dry_run) {
                Log::addInfo('Dry Run - would update attached file with ' . $new_attached_file);
            } else {
                update_attached_file($item_id, $new_attached_file);
                foreach($args['duplicates'] as $duplicate_id)
                {
                    update_attached_file($duplicate_id, $new_attached_file);
                }
            }
      //  }

        if (isset($metadata['original_image']) && strpos($metadata['original_image'], $old_file) !== false) {
            $metadata['original_image'] = $this->replaceFileBaseInPath($metadata['original_image'], $old_file, $new_file);
        }

        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeName => $sizeData) {
                if (isset($sizeData['file']) && strpos($sizeData['file'], $old_file) !== false) {
                    $metadata['sizes'][$sizeName]['file'] = $this->replaceFileBaseInPath($sizeData['file'], $old_file, $new_file);
                }
            }
        }

        if (true === $dry_run) {
            Log::addInfo('Dry Run - Would have updated attachment metadata', $metadata);
        } else {
            do_action('shortpixel/converter/prevent-offload', $item_id); 
            wp_update_attachment_metadata($item_id, $metadata);
            do_action('shortpixel/converter/prevent-offload-off', $item_id); 

            foreach($args['duplicates'] as $duplicate_id)
            {
                do_action('shortpixel/converter/prevent-offload', $duplicate_id); 
                wp_update_attachment_metadata($duplicate_id, $metadata);
                do_action('shortpixel/converter/prevent-offload-off', $duplicate_id); 
            }
        }
    }

    /**
     * Replace a filename base in a path or basename.
     *
     * Only the basename is rewritten, so a directory segment that contains
     * the old base is left alone. WPML siblings receive the item's new values
     * directly (see replaceMetaData()), so no path is renamed twice even
     * though WPML core syncs the original's new _wp_attached_file to its
     * translations as soon as it is written.
     *
     * @param string $filename Path or basename to rename.
     * @param string $old_file Old file base (no extension).
     * @param string $new_file New file base (no extension).
     * @return string The path with the base replaced.
     */
    protected function replaceFileBaseInPath($filename, $old_file, $new_file)
    {
        $basename = basename($filename);
        $name = pathinfo($basename, PATHINFO_FILENAME);
        $new_name_pattern = '/^' . preg_quote($new_file, '/') . '(?:-scaled)?(?:-\\d+x\\d+)?$/';


        $renamed = str_replace($old_file, $new_file, $basename);
        if ($basename !== $filename) {
            return trailingslashit(pathinfo($filename, PATHINFO_DIRNAME)) . $renamed;
        }

        return $renamed;
    }

    // @todo This might be returned in multiple formats / post data / postmeta data?  Public because of callback
    /** This is the callback for Finder results for replacing attributes on the Images
     *
     * This function also saves the results! Each result is first passed through
     * WPMLCheckReplace(), so posts in a different WPML language are skipped.
     * Within each post only <img> tags whose src BASENAME matches THIS item's
     * file base with an anchored regex `^base(-\d+x\d+|-scaled)?\.ext$` are
     * touched, so e.g. photo.jpg is not confused with my-photo.jpg.
     *
     * Content-replacement mode is read from
     * settings->ai_content_replace, three states:
     *   - 'overwrite': always write alt (regardless of aiPreserve).
     *   - 'missing'  : write alt only when the in-content alt is null/empty
     *     (aiPreserve does not affect the alt branch; it only gates the
     *     caption branch below).
     *   - (any other, including 'none'): the alt branch does NOT run here;
     *     'none' is normally short-circuited earlier in
     *     replaceImageAttributes() but this method is defensively re-checked.
     *
     * Caption branch only runs when the alt was ALSO replaced
     * (`$do_replace` is already true) — a caption-only signal never triggers
     * a tag rebuild, avoiding lossy DOM roundtrips that alter surrounding
     * whitespace / order without any user-visible payload.
     *
     * Int values in aiData (F_STATUS_PREVENTOVERRIDE / EXCLUDESETTING) mean
     * "no generated text" and never replace anything.
     *
     * UNDO branch: when
     * the queue item action is 'undoAltData', the alt branch applies the
     * EXACT-MATCH restore rule instead — 'overwrite' restores always;
     * 'missing' restores only where the current in-content alt exactly
     * matches the previously generated AI text ($prevAiData, trim-compared),
     * so a manually edited alt counts as reviewed and is left alone.
     * Both the single-item and the bulk undo paths reach this branch.
     *
     * Post-lock guard: each
     * $post_id is skipped while wp_check_post_lock() reports a live edit
     * lock; the call is function_exists()-guarded (the function is
     * admin-only, WP-CLI/cron would fatal). The remaining own-lock caveat is
     * documented on replaceImageAttributes().
     *
     * replaced_content channel: the per-post map written below is
     * keyed by POST id. The Gutenberg consumer (screen-media.js
     * UpdateGutenBerg) reads it by wp.data's getCurrentPostId().
     * replaceFiles() adds a string key 'replaced_url' to the same
     * map after a rename so the editor can refresh the block's url.
     *
     * @param array $results Finder results: arrays with post_id + content.
     * @param array $args    'aiData' (generated data), 'qItem' (QueueItem), 'prevAiData' (previous AI data for undo matching).
     * @return void
     */
    public function handleReplace($results, $args)
    {

        $replacer2 = \ShortPixel\Replacer\Replacer::getInstance();
        $aiData = $args['aiData'];
        $qItem = $args['qItem'];
        $prevAiData = $args['prevAiData'];

        $imageModel = $qItem->imageModel;

        $aiPreserve = \wpSPIO()->settings()->aiPreserve;
        // Determine content-replacement mode: 'missing' or 'overwrite'.
        $contentReplace = \wpSPIO()->settings()->ai_content_replace ?? 'missing';

        $action = $qItem->data()->action;

        if (count($results) > 0)
        {
            $result_replaced_content = $qItem->result()->replaced_content;

            foreach ($results as $result) {
                $post_id = $result['post_id'];
                $content = $result['content'];

                if (function_exists('wp_check_post_lock') && false !== wp_check_post_lock($post_id)) {
                    Log::addDebug('Replace Image Attributes - Post lock is active, skipping');
                    continue;
                }


                // Check if language is correct in case of WPML.  Don't replace different language pages. 
                if (false === $this->WPMLCheckReplace($post_id, $qItem->item_id)) {
                    continue;
                }

                $matches = $this->fetchImageMatches($content);
                $sources = [];
                $replaces = [];

                $image_filebase = ($imageModel->isScaled()) ? $imageModel->getOriginalFile()->getFileBase() : $imageModel->getFileBase();

                foreach ($matches as $match) {

                    $frontImage = new \ShortPixel\Model\FrontImage($match);
                    $src = $frontImage->src;

                    if (is_null($src)) {
                        continue;
                    }
                    // Only replace in post content the image we did
                    // Only match against the filename portion to avoid substring
                    // collisions (e.g. my-photo vs photo). Parse the URL path and
                    // compare the basename with an anchored regex that allows
                    // typical thumbnail suffixes.
                    $path = parse_url($src, PHP_URL_PATH);
                    $basename = basename($path);
                    $ext = preg_quote($imageModel->getExtension(), '/');
                    $pattern = '/^' . preg_quote($image_filebase, '/') . '(-\d+x\d+|-scaled)?\.' . $ext . '$/i';
                    if (preg_match($pattern, $basename) !== 1) {
                        continue;
                    }

                    $replaced_content = [
                        'alt' => false,
                        'caption' => false,
                    ];

                    $do_replace = false;
                    $altIsSet = (isset($aiData['alt']) && false === is_int($aiData['alt'])) ? true : false;
                    $isUndo = ('undoAltData' === $action);

                    //undoAltData
                    if ($isUndo && $altIsSet) {
                        $prevAlt = isset($prevAiData['alt']) ? $prevAiData['alt'] : '';

                        if ($contentReplace === 'overwrite') {
                            $frontImage->alt = $aiData['alt'];
                            $do_replace = true;
                            $replaced_content['alt'] = $aiData['alt'];
                        } elseif ($contentReplace === 'missing') {
                            if (trim($frontImage->alt) == trim($prevAlt)) {
                                $frontImage->alt = $aiData['alt'];
                                $do_replace = true;
                                $replaced_content['alt'] = $aiData['alt'];
                            }
                        }
                    } elseif ($altIsSet) {
                        if ($contentReplace === 'overwrite') {
                            $frontImage->alt = $aiData['alt'];
                            $replaced_content['alt'] = $aiData['alt'];
                            $do_replace = true;
                        } elseif ($contentReplace === 'missing') {
                            if ((is_null($frontImage->alt) || strlen(trim($frontImage->alt)) == 0)) {
                                $frontImage->alt = $aiData['alt'];
                                $replaced_content['alt'] = $aiData['alt'];
                                $do_replace = true;
                            }
                        }
                    }
                    // Only perform a caption-only replacement when we're also
                    // changing the tag in a way that will actually be written
                    // into the post (for now, only when alt is also replaced).
                    // This avoids triggering a full parse+rebuild that would
                    // alter unrelated attributes while leaving the caption
                    // silently un-written.
                    if ($do_replace && isset($aiData['caption']) && false === is_int($aiData['caption'])) {
                        if (false === $aiPreserve || (is_null($frontImage->caption) || strlen(trim($frontImage->caption)) == 0)) {
                            $frontImage->caption = $aiData['caption'];
                            $replaced_content['caption'] = $aiData['caption'];

                            // $do_replace is already true here
                        }
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


                    $result_replaced_content[$post_id] = $replaced_content;

                }
            } // foreach 
            $qItem->result()->replaced_content = $result_replaced_content;
        }
        else 
        {
             Log::addDebug('Handlereplace - Results returned zero.');
        }
    }


    // @todo Direct copy from CDNController. In future might be merged somewhere. 
    /**
     * Extracts all img and source srcset tags from an HTML content string.
     *
     * @param string $content HTML content to search.
     * @param array  $args    Reserved for future use; currently unused.
     * @return string[] Array of matched HTML tag strings.
     */
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

    /**
     * Returns whether AI processing should run automatically on newly uploaded images.
     *
     * Requires both isAiEnabled() and the autoAI setting to be truthy.
     *
     * @return bool True when auto AI is enabled; false otherwise.
     */
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

    /**
     * Reverts AI-generated data for an attachment back to its original values.
     *
     * Reads the original (pre-AI) alt, caption, description, and post_title from the
     * AiDataModel, calls AiDataModel::revert() to clear the generated data, then calls
     * replaceImageAttributes() to write the original values back into post content.
     * Marks the queue item done with STATUS_NOT_API. Returns the result of getAltData()
     * for display in the media modal.
     *
     * Note: file renaming that may have occurred during HandleSuccess() is not reversed here.
     *
     * handleReplace() has an undo branch keyed on the 'undoAltData' action —
     * under 'missing' mode it restores the original alt where the in-content
     * alt exactly matches the previously generated text ($generated is passed
     * as $prevAiData below); a manually edited alt is treated as reviewed and
     * left alone. See the handleReplace() docblock. Callers MUST mark the
     * slot via QueueItem::undoAltDataAction() first (AjaxController does) or
     * the undo branch never engages. The bulk-undo queue path also reaches
     * this method.
     *
     * @todo The post-content restore goes through replaceImageAttributes(),
     *       which early-returns when settings->ai_content_replace='none', so
     *       after switching to 'none' Undo only reverts the Media Library alt.
     *       Needs a per-call override or an undo path that bypasses the toggle.
     *
     * @param QueueItem $qItem The queue item for the attachment to revert.
     * @return array Return value of getAltData() containing snippet, generated, original, and current data.
     */
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
        $this->replaceImageAttributes($qItem, $aiData, $generated);

        // @todo This probably needs to reverse file renaming as well? 

        $aiData = $aiModel->getCurrentData();

        $qItem->addResult([
            'is_done' => true,
            'is_error' => false,
            'message' => __('AI Data reverted ', 'shortpixel-image-optimiser'),
            'apiStatus' => ApiController::STATUS_NOT_API,
            'fileStatus' => ImageModel::FILE_STATUS_SUCCESS,
            'aiData' => $aiData,


        ]);
        $this->finishItemProcess($qItem);


        return $this->getAltData($qItem);
    }

    /**
     * Returns a structured data payload describing the current AI state for an attachment.
     *
     * Loads the AiDataModel and migrates legacy shortpixel_alt_requests postmeta when the
     * status is AI_STATUS_NOTHING and old data is found. Builds generated, original, and
     * current data arrays via formatGenerated(), renders the media-modal snippet via
     * ViewController, and returns a metadata array containing: snippet, generated, original,
     * current, action, item_id, and labels. Used both as the return value of undoAltData()
     * and as the final result payload added to the queue item in HandleSuccess().
     *
     * Also decides whether the snippet may offer a rename: the view
     * data carries `is_renameable`, which is true for local media and, for
     * offloaded (virtual) images, only when isVirtualSupported() says the active
     * offloader can handle it. part-aitext.php renders the "File Name" field and
     * its "Change Filename" button solely under that flag, so unsupported
     * offloaders never show a control whose rename replaceFiles() would refuse.
     *
     * @param QueueItem $qItem The queue item for the target attachment.
     * @return array Associative array with keys: snippet, generated, original, current, action, item_id, labels.
     */
    public function getAltData(QueueItem $qItem)
    {
        $item_id = $qItem->item_id;
        $imageModel = $qItem->imageModel;

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

        // If offloaded, prevent renaming for system we don't have support for atm.
        $is_renameable = true;
        if (true === $imageModel->is_virtual())
        {
            $is_renameable = $this->isVirtualSupported();
        }

        $view = new ViewController();
        $view->addData([
            'item_id' => $item_id,
            'orginal_alt' => $original['alt'],
            'result_alt' => $generated['alt'],
            'has_data' => ($status == AiDataModel::AI_STATUS_GENERATED) ? true : false,
            'is_processable' => $aiModel->isProcessable(),
            'is_renameable' => $is_renameable, 
            'processable_reason' => $aiModel->getProcessableReason(),
            'processable_status' => $aiModel->getProcessableReason(true),
            'image_url' => $image_url,
            // 'current_alt' => $current_alt, 
            'status' => $status,
            //      'isSupported' => $this->isSupported($qItem),
            'dataItems' => $dataItems,  // This seems not used(?)
            'isDifferent' =>  $aiModel->currentIsDifferent(),
            'filename' => ($imageModel->isScaled()) ? $imageModel->getOriginalFile()->getFileName() : $imageModel->getFileName(),
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
     * Iterates the standard fields (alt, caption, description, post_title,
     * filebase) present in $generated and:
     *   - Collects a human-readable label into $dataItems for each field
     *     whose value is a non-int, non-null string longer than 1 char
     *     (used by the UX to say "AI generated: Alt, Caption, Filename").
     *   - Normalises any integer status value that matches
     *     AiDataModel::F_STATUS_PREVENTOVERRIDE (-4) OR
     *     AiDataModel::F_STATUS_EXCLUDESETTING (-3) down to a single -3 in
     *     the returned generated array. Consequence: the browser payload
     *     CANNOT distinguish "field skipped by aiPreserve" from "field
     *     excluded in settings" — both look like -3. Any future server-side
     *     filter that strips these int status codes before dispatch MUST
     *     gate on is_int($value) rather than on the -3 sentinel specifically,
     *     or it will silently miss data that was already coalesced here.
     *
     * Note: ints in this payload corrupt core/image blocks if handed to
     * Gutenberg — see AiController::handleSuccess() docblock for the full
     * chain and the client-side allowlist guard
     * (res/js/screens/screen-media.js UpdateGutenBerg).
     *
     * @param mixed $generated
     * @param mixed $current
     * @param mixed $original
     * @param bool $isPreview
     * @return (string[]|mixed)[]  [$dataItems, $generated] — labels list + normalised generated array.
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

    /**
     * Whether renaming is supported for the active offloader.
     *
     * Only WP Offload Media (`wp-offload`) handles renames, through the
     * `shortpixel/image/replace_files` filter (wpOffload::replaceFiles()).
     * Every other detected offloader — S3-Uploads (`s3-uploads-human`),
     * InfiniteUploads (`infinite-uploads`), Bitpoke Stack (`stack`) — has no
     * rename handling, so virtual images on those installs are refused by
     * replaceFiles() and the "Change Filename" field is hidden
     * (is_renameable=false in getAltData()). No offloader detected at all
     * also counts as supported (plain local media).
     *
     * @return bool True when no offloader or WP Offload Media is active.
     */
    private function isVirtualSupported() : bool
    {
            $offloader = Offloader::getInstance(); 
            $offload_name = $offloader->getOffloadName(); 
            if (is_null($offload_name) || $offload_name === 'wp-offload')
            {
                $is_renameable = true; 
            }
            else
            {
                $is_renameable = false; 
            }

            return $is_renameable;
    }
} // class 
