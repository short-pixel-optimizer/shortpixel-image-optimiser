<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


use ShortPixel\Controller\View\ListMediaViewController as ListMediaViewController;
use ShortPixel\Controller\View\OtherMediaViewController as OtherMediaViewController;
use ShortPixel\Controller\View\OtherMediaFolderViewController as OtherMediaFolderViewController;

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

//use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\InstallHelper as InstallHelper;

use ShortPixel\Model\Image\ImageModel as ImageModel;


// Class for containing all Ajax Related Actions.
class AjaxController
{
    const PROCESSOR_ACTIVE = -1;
    const NONCE_FAILED = -2;
    const NO_ACTION = -3;
    const APIKEY_FAILED = -4;
    const NOQUOTA = -5;
    const SERVER_ERROR = -6;

    private static $instance;

    public static function getInstance()
    {
       if (is_null(self::$instance))
         self::$instance = new AjaxController();

      return self::$instance;
    }

    // Support for JS Processor
    public function getProcessorKey()
    {
      // Get a Secret Key.
      $cacheControl = new CacheController();
      $bulkSecret = $cacheControl->getItem('bulk-secret');

      $secretKey = $bulkSecret->getValue();
      if (is_null($secretKey) || strlen($secretKey) == 0 || $secretKey === 'null')
      {
        $secretKey = false;
      }
      return $secretKey;
    }

    public function checkProcessorKey()
    {
      $processKey = $this->getProcessorKey();
			// phpcs:ignore -- Nonce is checked
      $bulkSecret = isset($_POST['bulk-secret']) ? sanitize_text_field(wp_unslash($_POST['bulk-secret'])) : false;
			// phpcs:ignore -- Nonce is checked
      $isBulk = isset($_POST['isBulk']) ? filter_var(sanitize_text_field(wp_unslash($_POST['isBulk'])), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : false;

      $is_processor = false;
      if ($processKey == false && $bulkSecret !== false)
      {
          $is_processor = true;
      }
      elseif ($processKey == $bulkSecret)
      {
         $is_processor = true;
      }
      elseif ($isBulk)
      {
         $is_processor = true;
      }

      // Save new ProcessorKey
      if ($is_processor && $bulkSecret !== $processKey)
      {
        $cacheControl = new CacheController();
        $cachedObj = $cacheControl->getItem('bulk-secret');

        $cachedObj->setValue($bulkSecret);
        $cachedObj->setExpires(2 * MINUTE_IN_SECONDS);
        $cachedObj->save();
      }

      if (false === $is_processor)
      {
        $json = new \stdClass;
        $json->message = __('Processor is active in another window', 'shortpixel-image-optimiser');
        $json->status = false;
        $json->error = self::PROCESSOR_ACTIVE; // processor active
        $this->send($json);
      }
    }

    public function ajax_getItemView()
    {
        $this->checkNonce('item_view');
				// phpcs:ignore -- Nonce is checked
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
				// phpcs:ignore -- Nonce is checked
          $id = isset($_POST['id']) ? intval($_POST['id']) : false;
					$result = '';


					$item = \wpSPIO()->filesystem()->getImage($id, $type);

          if ($id > 0)
          {
             if ($type == 'media')
             {
               ob_start();
               $control = ListMediaViewController::getInstance();
               $control->doColumn('wp-shortPixel', $id);
               $result = ob_get_contents();
               ob_end_clean();
             }
             if ($type == 'custom')
             {
                ob_start();
                $control = new OtherMediaViewController();
                  $control->doActionColumn($item);
                $result = ob_get_contents();
                ob_end_clean();
             }
          }

          $json = new \stdClass;
          $json->$type = new \stdClass;
          $json->$type->itemView = $result;
					$json->$type->is_optimizable = (false !== $item) ? $item->isProcessable() : false;
					$json->$type->is_restorable = (false !== $item)  ? $item->isRestorable() : false;
          $json->$type->id = $id;
          $json->$type->results = null;
          $json->$type->is_error = false;
          $json->status = true;

          $this->send($json);
    }

    public function ajax_processQueue()
    {
        $this->checkNonce('processing');
        $this->checkProcessorKey();


				ErrorController::start(); // Capture fatal errors for us.

        // Notice that POST variables are always string, so 'true', not true.
				// phpcs:ignore -- Nonce is checked
        $isBulk = (isset($_POST['isBulk']) && $_POST['isBulk'] === 'true') ? true : false;
				// phpcs:ignore -- Nonce is checked
        $queue = (isset($_POST['queues'])) ? sanitize_text_field($_POST['queues']) : 'media,custom';

        $queues = array_filter(explode(',', $queue), 'trim');

        $control = new OptimizeController();
        $control->setBulk($isBulk);
        $result = $control->processQueue($queues);

        $this->send($result);
    }

		/** Ajax function to recheck if something can be active. If client is doens't have the processor key, it will check later if the other client is 'done' or went away. */
		protected function recheckActive()
		{
			// If not processor, this ends the processing and sends JSON.
			$this->checkProcessorKey();

			$json = new \stdClass;
			$json->message = __('Became processor', 'shortpixel-image-optimiser');
			$json->status = true;
			$this->send($json);

		}

    public function ajaxRequest()
    {
        $this->checkNonce('ajax_request');
				ErrorController::start(); // Capture fatal errors for us.

			  // phpcs:ignore -- Nonce is checked
        $action = isset($_POST['screen_action']) ? sanitize_text_field($_POST['screen_action']) : false;
				// phpcs:ignore -- Nonce is checked
        $typeArray = isset($_POST['type'])  ? array(sanitize_text_field($_POST['type'])) : array('media', 'custom');
				// phpcs:ignore -- Nonce is checked
        $id = isset($_POST['id']) ? intval($_POST['id']) : false;

        $json = new \stdClass;
        foreach($typeArray as $type)
        {
          $json->$type = new \stdClass;
          $json->$type->id = $id;
          $json->$type->results = null;
          $json->$type->is_error = false;
          $json->status = false;
        }

        $data = array('id' => $id, 'typeArray' => $typeArray, 'action' => $action);

        if (count($typeArray) == 1) // Actions which need specific type like optimize / restore.
        {
          $data['type'] = $typeArray[0];
          unset($data['typeArray']);
        }

        switch($action)
        {
           case 'restoreItem':
              $json = $this->restoreItem($json, $data);
           break;
           case 'reOptimizeItem':
             $json = $this->reOptimizeItem($json, $data);
           break;
           case 'optimizeItem':
             $json = $this->optimizeItem($json, $data);
           break;
					 case 'markCompleted':
					 	 $json = $this->markCompleted($json, $data);
					 break;
					 case 'unMarkCompleted':
						 $json = $this->unMarkCompleted($json, $data);
					 break;
					 case 'cancelOptimize':
					 		$json = $this->cancelOptimize($json, $data);
					 break;
					 case 'getItemEditWarning':
					 	  $json = $this->getItemEditWarning($json, $data);
					 break;
           case 'createBulk':
             $json = $this->createBulk($json, $data);
           break;
           case 'applyBulkSelection':
             $json = $this->applyBulkSelection($json, $data);
           break;
           case 'startBulk':
             $json = $this->startBulk($json, $data);
           break;
           case 'finishBulk':
             $json = $this->finishBulk($json, $data);
           break;
           case 'startRestoreAll':
              $json = $this->startRestoreAll($json,$data);
           break;
           case 'startMigrateAll':
              $json = $this->startMigrateAll($json, $data);
           break;
					 case 'startRemoveLegacy':
					 		$json = $this->startRemoveLegacy($json, $data);
					 break;
					 case "toolsRemoveAll":
					 		 $json = $this->removeAllData($json, $data);
					 break;
					 case "toolsRemoveBackup":
					 		 $json = $this->removeBackup($json, $data);
					 break;
					 case 'request_new_api_key': // @todo Dunnoo why empty, should go if not here.

					 break;
					 case "loadLogFile":
					  	$data['logFile'] = isset($_POST['loadFile']) ? sanitize_text_field($_POST['loadFile']) : null;
					 		$json = $this->loadLogFile($json, $data);
					 break;
					 case "redoLegacy":
					 	  $this->redoLegacy($json, $data);
					 break;
					 case 'refreshFolder':
					 		$json = $this->refreshFolder($json,$data);
					 break;
					 // CUSTOM FOLDERS
					 case 'removeCustomFolder':
					 	 	 $json = $this->removeCustomFolder($json, $data);
					 break;
					 case 'browseFolders':
					 		$json = $this->browseFolders($json, $data);
					 break ;
					 case 'addCustomFolder':
					 		$json = $this->addCustomFolder($json, $data);
					 break;
					 case 'scanNextFolder':
					 		$json = $this->scanNextFolder($json, $data);
					 break;
					 case 'resetScanFolderChecked';
					 		$json = $this->resetScanFolderChecked($json, $data);
					 break;
					 case 'recheckActive':
					 		$json = $this->recheckActive($json, $data);
					 break;
           default:
              $json->$type->message = __('Ajaxrequest - no action found', 'shorpixel-image-optimiser');
              $json->error = self::NO_ACTION;
           break;

        }
        $this->send($json);
    }

    public function getMediaItem($id, $type)
    {
      $fs = \wpSPIO()->filesystem();
      return $fs->getImage($id, $type);
    }

		public function getItemEditWarning($json, $data)
		{
			  $id = intval($_POST['id']);
				$mediaItem = $this->getMediaItem($id, 'media');
				if (is_object($mediaItem))
				{
					$json = new \stdClass;
					$json->id = $id;
					$json->is_restorable = ($mediaItem->isRestorable() ) ? 'true' : 'false';
					$json->is_optimized = ($mediaItem->isOptimized()) ? 'true' : 'false';
				}
				else {
				}
				return $json;
		}

    /** Adds  a single Items to the Single queue */
    public function optimizeItem()
    {
          $id = intval($_POST['id']);
          $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
					$flags = isset($_POST['flags']) ? sanitize_text_field($_POST['flags']) : false;

          $mediaItem = $this->getMediaItem($id, $type);

          // if order is given, remove barrier and file away.
          if ($mediaItem->isOptimizePrevented() !== false)
            $mediaItem->resetPrevent();

          $control = new OptimizeController();
          $json = new \stdClass;
          $json->$type = new \stdClass;

					$args = array();
					if ('force' === $flags)
					{
						 $args['forceExclusion'] =  true;
					}

          $json->$type = $control->addItemToQueue($mediaItem, $args);
					return $json;
    }

		public function markCompleted()
		{
				$id = intval($_POST['id']);
				$type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

				$mediaItem = $this->getMediaItem($id, $type);

				$mediaItem->markCompleted(__('This item has been manually marked as completed', 'shortpixel-image-optimiser'), ImageModel::FILE_STATUS_MARKED_DONE);

				$json = new \stdClass;
				$json->$type = new \stdClass;

				$json->$type->status = 1;
				$json->$type->fileStatus = ImageModel::FILE_STATUS_SUCCESS; // great success!

				$json->$type->item_id = $id;
				$json->$type->result = new \stdClass;
				$json->$type->result->message = __('Item marked as completed', 'shortpixel-image-optimiser');
				$json->$type->result->is_done = true;

				$json->status = true;

				return $json;
		}

		public function unMarkCompleted()
		{
			$id = intval($_POST['id']);
			$type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

			$mediaItem = $this->getMediaItem($id, $type);

			$mediaItem->resetPrevent();

			$json = new \stdClass;
			$json->$type = new \stdClass;

			$json->$type->status = 1;
			$json->$type->fileStatus = ImageModel::FILE_STATUS_SUCCESS; // great success!

			$json->$type->item_id = $id;
			$json->$type->result = new \stdClass;
			$json->$type->result->message = __('Item unmarked', 'shortpixel-image-optimiser');
			$json->$type->result->is_done = true;

			$json->status = true;

			return $json;

		}

		public function cancelOptimize($json, $data)
		{
			$id = intval($_POST['id']);
			$type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';

			$mediaItem = $this->getMediaItem($id, $type);

			$mediaItem->dropFromQueue();

			$json->$type->status = 1;
			$json->$type->fileStatus = ImageModel::FILE_STATUS_SUCCESS; // great success!

			$json->$type->item_id = $id;
			$json->$type->result = new \stdClass;
			$json->$type->result->message = __('Item removed from queue', 'shortpixel-image-optimiser');
			$json->$type->result->is_done = true;

			$json->status = true;
			return $json;
		}

    /* Integration for WP /LR Sync plugin  - https://meowapps.com/plugin/wplr-sync/
		* @integration WP / LR Sync
    * @todo Test if it works with plugin intergration
    *
    */
    public function onWpLrUpdateMedia($imageId)
    {

      // Get and remove Meta
      $mediaItem = \wpSPIO()->filesystem()->getImage($imageId, 'media');

      $mediaItem->onDelete();

			// Flush and reaquire image to make sure it doesn't stay previous state.
			\wpSPIO()->filesystem()->flushImage($mediaItem);
		  $mediaItem = \wpSPIO()->filesystem()->getImage($imageId, 'media', false);

      // Optimize
      $control = new OptimizeController();
      $json = $control->addItemToQueue($mediaItem);

    }

		// @param Row of something in llr_sync table. This changed
		public function onWpLrSyncMedia($row)
		{
			$attachment_id = $row->wp_id;
			return $this->onWpLrUpdateMedia($attachment_id);
		}

    protected function restoreItem($json, $data)
    {
      $id = $data['id'];
      $type =$data['type'];

      $mediaItem = $this->getMediaItem($id, $type);
      $control = new OptimizeController();

      $json->$type = $control->restoreItem($mediaItem);
			$json->status = true;

      return $json;
    }

    protected function reOptimizeItem($json, $data)
    {
       $id = $data['id'];
       $type = $data['type'];
       $compressionType = isset($_POST['compressionType']) ? intval($_POST['compressionType']) : 0;
			 $actionType = isset($_POST['actionType']) ? intval($_POST['actionType']) : null;

       $mediaItem = $this->getMediaItem($id, $type);
			 $args = array();

				if ($actionType == ImageModel::ACTION_SMARTCROP || $actionType == ImageModel::ACTION_SMARTCROPLESS)
				{
						$args = array('smartcrop' => $actionType);
				}

       $control = new OptimizeController();

       $json->$type = $control->reOptimizeItem($mediaItem, $compressionType, $args);
			 $json->status = true;
       return $json;
    }

    protected function finishBulk($json, $data)
    {
       $bulkControl = BulkController::getInstance();

       $bulkControl->finishBulk('media');
       $bulkControl->finishBulk('custom');

       $json->status = 1;

       return $json;
    }


    protected function createBulk($json, $data)
    {
        $bulkControl = BulkController::getInstance();
        $stats = $bulkControl->createNewBulk('media');
        $json->media->stats = $stats;

        $stats = $bulkControl->createNewBulk('custom');
        $json->custom->stats = $stats;

        $json = $this->applyBulkSelection($json, $data);
        return $json;
    }

    protected function applyBulkSelection($json, $data)
    {
        // These values should always be given!
        $doMedia = filter_var(sanitize_text_field($_POST['mediaActive']), FILTER_VALIDATE_BOOLEAN);
        $doCustom = filter_var(sanitize_text_field($_POST['customActive']), FILTER_VALIDATE_BOOLEAN);
        $doWebp = filter_var(sanitize_text_field($_POST['webpActive']), FILTER_VALIDATE_BOOLEAN);
        $doAvif = filter_var(sanitize_text_field($_POST['avifActive']), FILTER_VALIDATE_BOOLEAN);
				$backgroundProcess = filter_var(sanitize_text_field($_POST['backgroundProcess']), FILTER_VALIDATE_BOOLEAN);


				// Can be hidden
				if (isset($_POST['thumbsActive']))
				{
					$doThumbs = filter_var(sanitize_text_field($_POST['thumbsActive']), FILTER_VALIDATE_BOOLEAN);
					\wpSPIO()->settings()->processThumbnails = $doThumbs;
				}

        \wpSPIO()->settings()->createWebp = $doWebp;
				\wpSPIO()->settings()->createAvif = $doAvif;
				\wpSPIO()->settings()->doBackgroundProcess = $backgroundProcess;

        $bulkControl = BulkController::getInstance();

        if (! $doMedia)
				{
          $bulkControl->finishBulk('media');
				}
        if (! $doCustom)
				{
          $bulkControl->finishBulk('custom');
				}

				if ($doCustom)
				{
					$otherMediaController = OtherMediaController::getInstance();
				}

        $optimizeController = new OptimizeController();
        $optimizeController->setBulk(true);

        $data = $optimizeController->getStartupData();

        $json->media->stats = $data->media->stats;
        $json->custom->stats = $data->custom->stats;
        $json->total = $data->total;

        $json->status = true;

        return $json;
    }



    protected function startBulk($json, $data)
    {
        $bulkControl = BulkController::getInstance();

				$types = array('media', 'custom');
        $result = $bulkControl->startBulk($types);

        $this->send($result);
    }

    protected function startRestoreAll($json, $data)
    {
       $bulkControl = BulkController::getInstance();
			 OptimizeController::resetQueues(); // prevent any weirdness

			 $queue = (isset($_POST['queues'])) ? sanitize_text_field($_POST['queues']) : false;
			 if ($queue === false) // safety first.
			 	return $json;

			 $queues = array_filter(explode(',', $queue), 'trim');

			 if (in_array('media', $queues))
			 {
       	$stats = $bulkControl->createNewBulk('media', 'bulk-restore');
       	$json->media->stats = $stats;
			 }

			 if (in_array('custom', $queues))
			 {
	       $stats = $bulkControl->createNewBulk('custom', 'bulk-restore');
	       $json->custom->stats = $stats;
			 }

       return $json;
    }

    protected function startMigrateAll($json, $data)
    {
       $bulkControl = BulkController::getInstance();
			 OptimizeController::resetQueues(); // prevent any weirdness


       $stats = $bulkControl->createNewBulk('media', 'migrate');
       $json->media->stats = $stats;

       return $json;
    }

		protected function startRemoveLegacy($json, $data)
    {
       $bulkControl = BulkController::getInstance();
			 OptimizeController::resetQueues(); // prevent any weirdness


       $stats = $bulkControl->createNewBulk('media', 'removeLegacy');
       $json->media->stats = $stats;

       return $json;
    }

		protected function redoLegacy($json, $data)
		{
			$id = $data['id'];
			$type = $data['type'];
			$mediaItem = $this->getMediaItem($id, $type);

		// Changed since updated function should detect what is what.
			$mediaItem->migrate();

			$json->status = true;
			$json->media->id = $id;
			$json->media->type = 'media';
			$this->send($json);
		}

    /** Data for the compare function */
    public function ajax_getComparerData() {

        $this->checkNonce('ajax_request');

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'media';
        $id = isset($_POST['id']) ? intval($_POST['id']) : false;

        if ( $id === false || !current_user_can( 'upload_files' ) && !current_user_can( 'edit_posts' ) )  {

            $json->status = false;
            $json->id = $id;
            $json->message = __('Error - item to compare could not be found or no access', 'shortpixel-image-optimiser');
            $this->send($json);
        }

        $ret = array();
        $fs = \wpSPIO()->filesystem();
        $imageObj = $fs->getImage($id, $type);

        // With PDF, the thumbnail called 'full' is the image, the main is the PDF file
        if ($imageObj->getExtension() == 'pdf')
        {
           $thumbImg = $imageObj->getThumbnail('full');
           if ($thumbImg !== false)
           {
              $imageObj = $thumbImg;
           }
        }

        $backupFile = $imageObj->getBackupFile();
        if (is_object($backupFile))
          $backup_url = $fs->pathToUrl($backupFile);
        else
          $backup_url = '';

        	$ret['origUrl'] = $backup_url; // $backupUrl . $urlBkPath . $meta->getName();

          $ret['optUrl'] = $imageObj->getURL();
          $ret['width'] = $imageObj->getMeta('originalWidth');
          $ret['height'] = $imageObj->getMeta('originalHeight');

          if (is_null($ret['width']) || $ret['width'] == false)
          {

              if (! $imageObj->is_virtual())
              {
                $ret['width'] = $imageObj->get('width');
                $ret['height']= $imageObj->get('height');
              }
              else
              {
                  $size = getimagesize($backupFile->getFullPath());
                  if (is_array($size))
                  {
                     $ret['width'] = $size[0];
                     $ret['height'] = $size[1];
                  }
              }
          }

        $this->send( (object) $ret);
    }

		protected function refreshFolder($json, $data)
		{
			$otherMediaController = OtherMediaController::getInstance();

			$folder_id = isset($_POST['id']) ? intval($_POST['id']) : false;
			$json->folder->message = '';


			if (false === $folder_id)
			{
				$json->folder->is_error = true;
				$json->folder->message = __('An error has occured: no folder id', 'shortpixel-image-optimiser');
			}

			$folderObj = $otherMediaController->getFolderByID($folder_id);

			if (false === $folderObj)
			{
				 $json->folder->is_error = true;
				 $json->folder->message = __('An error has occured: no folder object', 'shortpixel-image-optimiser');
			}

			$result = $folderObj->refreshFolder(true);

			if (false === $result)
			{
				 $json->folder->message = $folderObj->get('last_message');
			}
			else { // result is stats
					$stats = $result;
				 	if ($stats['new'] > 0)
					{
						 $message = sprintf(__('%s new files found ( %s waiting %s optimized)', 'shortpixel-image-optimiser'), $stats['new'], $stats['waiting'], $stats['optimized']);
					}
					else
					{
						 $message = sprintf(__('No new files found ( %s waiting %s optimized)', 'shortpixel-image-optimiser'), $stats['waiting'], $stats['optimized']);
					}

					$json->folder->message = $message;
			}


			$json->status = true;
			$json->folder->fileCount = $folderObj->get('fileCount');
			$json->folder->action = 'refresh';

			return $json;
		}

		protected function removeCustomFolder($json, $data)
		{
			$folder_id = isset($_POST['id']) ? intval($_POST['id']) : false;

			$otherMedia = OtherMediaController::getInstance();
			$dirObj = $otherMedia->getFolderByID($folder_id);

			if ($dirObj === false)
			{
				$json->folder->is_error = true;
				$json->folder->message = __('An error has occured: no folder object', 'shortpixel-image-optimiser');
				return;
			}

			$dirObj->delete();

			$json->status = true;
			$json->folder->message = __('Folder has been removed', 'shortpixel-image-optimiser');
			$json->folder->is_done = true;
			$json->folder->action = 'remove';

			return $json;
		}

		protected function addCustomFolder($json, $data)
		{
			  $relpath = isset($_POST['relpath']) ? sanitize_text_field($_POST['relpath']) : null;

				$fs = \wpSPIO()->filesystem();

				$customFolderBase = $fs->getWPFileBase();
				$basePath = $customFolderBase->getPath();

				$path = trailingslashit($basePath) . $relpath;

				$otherMedia = OtherMediaController::getInstance();

				// Result is a folder object
				$result = $otherMedia->addDirectory($path);


				// @todo Formulate some response here that can be used on the row thingie.
				if (false === $result)
				{
					 $json->folder->is_error = true;
					 $json->folder->message = __('Failed to add Folder', 'shortpixel-image-optimiser');

				}
				else {
					$control = new OtherMediaFolderViewController();
					$itemView = $control->singleItemView($result);

					$json->folder->result = new \stdClass;
					$json->folder->result->id = $result->get('id');
					$json->folder->result->itemView = $itemView;

				}

				return $json;
		}

		protected function browseFolders($json, $data)
		{
				$relpath = isset($_POST['relPath']) ? sanitize_text_field($_POST['relPath']) : '';

				$otherMediaController = OtherMediaController::getInstance();

				$folders = $otherMediaController->browseFolder($relpath);

				if (isset($folders['is_error']) && true == $folders['is_error'])
				{
					 $json->folder->is_error = true;
					 $json->folder->message = $folders['message'];
					 $folders = array();
				}

				$json->folder->folders = $folders;
				$json->folder->relpath = $relpath;
				$json->status = true;

				return $json;

		}

		protected function resetScanFolderChecked($json, $data)
		{
			$otherMediaController = OtherMediaController::getInstance();

			$otherMediaController->resetCheckedTimestamps();
			return $json;
		}

		protected function scanNextFolder($json, $data)
		{
			$otherMediaController = OtherMediaController::getInstance();
			$force = isset($_POST['force']) ? sanitize_text_field($_POST['force']) : null;

			$args = array();
			$args['force'] = $force;

			$result = $otherMediaController->doNextRefreshableFolder($args);

			if ($result === false)
			{
				 $json->folder->is_done = true;
				 $json->folder->result = new \stdClass;
				 $json->folder->result->message = __('All Folders have been scanned!', 'shortpixel_image_optimiser');
			}
			else {

					$json->folder->result = $result;
			}

			return $json;

		}

    public function ajax_getBackupFolderSize()
    {
        $this->checkNonce('ajax_request');

        $dirObj = \wpSPIO()->filesystem()->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

        $size = $dirObj->getFolderSize();
        echo UiHelper::formatBytes($size);
        exit();
    }

    public function ajax_proposeQuotaUpgrade()
    {
         $this->checkNonce('ajax_request');

         $notices = AdminNoticesController::getInstance();
         $notices->proposeUpgradeRemote();
         exit();
    }

    public function ajax_checkquota()
    {
         $this->checkNonce('ajax_request');

         $quotaController = QuotaController::getInstance();
         $quotaController->forceCheckRemoteQuota();

         $quota = $quotaController->getQuota();

         $settings = \wpSPIO()->settings();

         $sendback = wp_get_referer();
         // sanitize the referring webpage location
         $sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);

         $result = array('status' => 'no-quota', 'redirect' => $sendback);
         if (! $settings->quotaExceeded)
         {
            $result['status'] = 'has-quota';
         }
         else
         {
            Notices::addWarning( __('You have no available image credits. If you just bought a package, please note that sometimes it takes a few minutes for the payment processor to send us the payment confirmation.','shortpixel-image-optimiser') );
         }

         wp_send_json($result);

    }

		public function ajax_addImageEditorData($data)
		{

		}

		protected function loadLogFile($json, $data)
		{
			 $logFile = $data['logFile'];
			 $type = $data['type'];

			 if (is_null($logFile))
			 {
				  $json->$type->is_error = true;
					$json->$type->result = __('Could not load log file', 'shortpixel-image-optimiser');
					return $json;
			 }

       $bulkController = BulkController::getInstance();
			 $log = $bulkController->getLog($logFile);
			 $logData = $bulkController->getLogData($logFile);

			 $logType = $logData['type']; // custom or media.

			 $json->$type->logType = $logType;


			 if (! $log )
			 {
				  $json->$type->is_error = true;
					$json->$type->result = __('Log file does not exist', 'shortpixel-image-optimiser');
					return $json;
			 }

	 	 //	$date = UiHelper::formatTS($log->date);
		 	 //$logData = $bulkController->getLogData($logFile); // starts from options.
			 $date = (isset($logData['date'])) ? UiHelper::formatTS($logData['date']) : false;
			 $content = $log->getContents();
			 $lines = explode(';', $content);

			 $headers = array(
				 __('Time', 'shortpixel-image-optimiser'),
				 __('Filename', 'shortpixel-image-optimiser'),
			   __('Error', 'shortpixel-image-optimiser'));

			 foreach($lines as $index => $line)
			 {
				  $cells = explode('|', $line);
					if (isset($cells[2]) && $type !== 'custom')
					{
						 $id = $cells[2]; // replaces the image id with a link to image.
						 $cells[2] = esc_url(admin_url('post.php?post=' . trim($id) . '&action=edit'));
				//		 unset($cells[3]);
					}
					if (isset($cells[3]))
					{
						 $error_message = $cells[3];
						 $cells[4] = UiHelper::getKBSearchLink($error_message);
					}
					$lines[$index] = (array) $cells;
			 }
			 $lines = array_values(array_filter($lines));
			 array_unshift($lines, $headers);
			 $json->$type->title = sprintf(__('Bulk ran on %s', 'shortpixel-image-optimiser'), $date);
			 $json->$type->results = $lines;
			 return $json;


		}

    protected function checkNonce($action)
    {
      if (! wp_verify_nonce($_POST['nonce'], $action))
      {

				$id = isset($_POST['id']) ? intval($_POST['id']) : false;
				$action = isset($_POST['screen_action']) ? sanitize_text_field($_POST['screen_action']) : false;

        $json = new \stdClass;
        $json->message = __('Nonce is missing or wrong - Try to refresh the page', 'shortpixel-image-optimiser');
				$json->item_id = $id;
				$json->action = $action;
        $json->status = false;
        $json->error = self::NONCE_FAILED;
        $this->send($json);
				exit();
      }

    }


    protected function send($json)
    {
        $callback = isset($_POST['callback']) ? sanitize_text_field($_POST['callback']) : false;
        if ($callback)
          $json->callback = $callback; // which type of request we just fullfilled ( response processing )

        $pKey = $this->getProcessorKey();
        if ($pKey !== false)
          $json->processorKey = $pKey;

        wp_send_json($json);

        exit();
    }


		private function removeAllData($json, $data)
		{
				if (wp_verify_nonce($_POST['tools-nonce'], 'remove-all'))
				{
			 		InstallHelper::hardUninstall();
					$json->settings->results = __('All Data has been removed. The plugin has been deactivated', 'shortpixel-image-optimiser');
				}
				else {
				}

				$json->settings->redirect = admin_url('plugins.php');

				return $json;
		}

		private function removeBackup($json, $data)
		{

			if (wp_verify_nonce($_POST['tools-nonce'], 'empty-backup'))
			{
				$dir = \wpSPIO()->filesystem()->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
			  $dir->recursiveDelete();
			 $json->settings->results = __('The backups have been removed. You can close the window', 'shortpixel-image-optimiser');
			}
			else {
				$json->settings->results = __('Error: Invalid Nonce in empty backups', 'shortpixel-image-optimiser');
			}

			return $json;
		}




}
