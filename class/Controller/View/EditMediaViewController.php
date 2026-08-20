<?php
namespace ShortPixel\Controller\View;

use ShortPixel\Controller\Backup\BackupController;
use ShortPixel\Controller\Front\CDNController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\QueueController;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;

use ShortPixel\Controller\Queue\QueueItems as QueueItems;
use ShortPixel\Helper\DownloadHelper;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Converter\Converter;
use ShortPixel\Model\File\DirectoryModel;
use ShortPixel\Model\File\FileModel as FileModel;


/**
 * View controller for the ShortPixel meta box on the attachment edit screen.
 *
 * Adds a 'ShortPixel Info' meta box (side panel) to the WordPress attachment
 * edit page via the `add_meta_boxes_attachment` hook. The meta box renders the
 * `view-edit-media` template, which displays compression status, action buttons,
 * image dimensions, conversion and resize statistics, and — when debug mode is
 * active — a detailed diagnostic dump.
 *
 * Also provides addAIAlter() to inject an AI-data button into the attachment
 * fields (hooked to `attachment_fields_to_edit`, currently commented out at
 * the call site).
 *
 * @package ShortPixel\Controller\View
 */
class EditMediaViewController extends \ShortPixel\ViewController
{
      protected $template = 'view-edit-media';

      /** @var int|null WordPress post ID of the attachment being edited. */
      protected $post_id;
      /** @var mixed|null Retained for backward compatibility; not used in the current render path. */
      protected $legacyViewObj;

      /** @var \ShortPixel\Model\Image\MediaLibraryModel|false Image model for the current attachment, or false when not found. */
      protected $imageModel;
      /** @var bool Whether loadHooks() has already been called to avoid double-registration. */
      protected $hooked;

			protected static $instance;

      /**
       * Registers the add_meta_boxes_attachment action hook.
       *
       * Called once from load() via the $this->hooked guard. Sets $this->hooked
       * to true so subsequent load() calls are no-ops.
       *
       * @return void
       */
      protected function loadHooks()
      {
            add_action( 'add_meta_boxes_attachment', array( $this, 'addMetaBox') );
          //  add_action( 'attachment_fields_to_edit', [ $this, 'addAIAlter'], 10, 2);
            $this->hooked = true;
      
      }

      /**
       * Default controller action: registers hooks and enables trusted filesystem mode.
       *
       * Calls loadHooks() if not yet done. Starts trusted mode so the filesystem
       * helper can access attachment files without capability gating during the
       * meta-box render.
       *
       * @return void
       */
      public function load()
      {
        if (! $this->hooked)
          $this->loadHooks();

					$fs = \wpSPIO()->filesystem();
					$fs->startTrustedMode();

      }

      /**
       * Registers the 'ShortPixel Info' side meta box on the attachment edit screen.
       *
       * Callback for the `add_meta_boxes_attachment` action. The meta box calls
       * doMetaBox() to render its content.
       *
       * @return void
       */
      public function addMetaBox()
      {
          add_meta_box(
              'shortpixel_info_box',          // this is HTML id of the box on edit screen
              __('ShortPixel Info', 'shortpixel-image-optimiser'),    // title of the box
              array( $this, 'doMetaBox'),   // function to be called to display the info
              null,//,        // on which edit screen the box should appear
              'side'//'normal',      // part of page where the box should appear
              //'default'      // priority of the box
          );
      }

      /** Wordpress Filter to ( temp ) add a alt button for AI to the interface.
       * 
       * @param array $fields 
       * @param object $post 
       * @return array 
       */
      public function addAIAlter($fields, $post)
      { 
          $post_id = intval($post->ID);
          $fields['aibutton'] = [
              'label' => __('ShortPixel AI Data', 'shortpixel-image-optimiser'), 
              'input' => 'html', 
              'html' => "<a href='javascript:window.ShortPixelProcessor.screen.RequestAlt($post_id)' class='button button-secondary'>" . __('Generate', 'shortpixel-image-optimiser') . "</a>
                 <div class='shortpixel-alt-messagebox' id='shortpixel-ai-messagebox-$post_id'>&nbsp;</div>
               ",
          ];
         
          return $fields;
      }

      /**
       * Renders the ShortPixel meta box content for an attachment.
       *
       * Loads the image model for $post->ID. When the model cannot be found (not an
       * image or file missing), renders the template with an error status message.
       * Otherwise populates $this->view with status text, action buttons, burger-menu
       * list, image dimensions, per-image statistics, and (in debug mode) a full
       * diagnostic dump, then includes the `view-edit-media` template.
       *
       * Action buttons are suppressed when the current user does not have the
       * required ShortPixel capability ($this->userIsAllowed).
       *
       * @param \WP_Post $post The attachment post object.
       * @return bool|void False when the image model cannot be loaded; void otherwise.
       */
       public function dometaBox($post)
      {
          $this->post_id = $post->ID;
					$this->view->debugInfo = array();
					$this->view->id = $this->post_id;
					$this->view->list_actions = '';

          $fs = \wpSPIO()->filesystem();
          $this->imageModel = $fs->getMediaImage($this->post_id);

					// Asking for something non-existing.
					if ($this->imageModel === false)
					{
						$this->view->status_message = __('File Error. This could be not an image or the file is missing', 'shortpixel-image-optimiser');

						$this->loadView();
						return false;
					}

          $this->view->status_message = null;

         	$this->view->text = UiHelper::getStatusText($this->imageModel);
          $this->view->list_actions = UiHelper::getListActions($this->imageModel);
          $this->view->image = [ 'width' => $this->imageModel->get('width'), 'height' => $this->imageModel->get('height'), 'extension' => $this->imageModel->getExtension() ];

          if ( count($this->view->list_actions) > 0)
            $this->view->list_actions = UiHelper::renderBurgerList($this->view->list_actions, $this->imageModel);
          else
            $this->view->list_actions = '';

          $this->view->actions = UiHelper::getActions($this->imageModel);
          $this->view->stats = $this->getStatistics();

          if (! $this->userIsAllowed)
          {
            $this->view->actions = array();
            $this->view->list_actions = '';
          }

          if(true === \wpSPIO()->env()->is_debug )
          {
            $this->view->debugInfo = $this->getDebugInfo();
          }

          $this->loadView();
      }

      /**
       * Returns a success/status text string for the current image model.
       *
       * Delegates to UIHelper::renderSuccessText(). Not currently called from the
       * main render path (doMetaBox() uses UiHelper::getStatusText() instead).
       *
       * @return string HTML status string.
       */
      protected function getStatusMessage()
      {
          return UIHelper::renderSuccessText($this->imageModel);
      }

      /**
       * Collects per-image optimization statistics for display in the meta box.
       *
       * Returns an empty array when the image has not been optimized. Otherwise
       * returns an array of [label, value] pairs covering: EXIF retention,
       * format conversion (or conversion failure reason), resize dimensions,
       * optimization timestamp, and a link to the stats knowledge base article.
       *
       * @return array<int, array{0: string, 1: string}> Rows of [label, value] pairs.
       */
      protected function getStatistics()
      {
        $stats = [];
        $imageObj = $this->imageModel;
        $did_keepExif = $imageObj->getMeta('did_keepExif');

				$did_convert = $imageObj->getMeta()->convertMeta()->isConverted();
        $resize = $imageObj->getMeta('resize');

				// Not optimized, not data.
				if (! $imageObj->isOptimized())
					return array();


        $exifData = UIHelper::getExifDisplayValues($did_keepExif);

        if (is_array($exifData) && isset($exifData['line']))
        {
           $stats[] = [$exifData['line'], ''];
        }


        if (true === $did_convert )
        {
					$ext = $imageObj->getMeta()->convertMeta()->getFileFormat();
          $stats[] = array(  sprintf(__('Converted from %s','shortpixel-image-optimiser'), $ext), '');
        }
				elseif (false !== $imageObj->getMeta()->convertMeta()->didTry()) {
					$ext = $imageObj->getMeta()->convertMeta()->getFileFormat();
					$error = $imageObj->getMeta()->convertMeta()->getError(); // error code.
					$stats[] = array(UiHelper::getConvertErrorReason($error), '');
				}

        if ($resize == true)
        {
            $from = $imageObj->getMeta('originalWidth') . 'x' . $imageObj->getMeta('originalHeight');
            $to  = $imageObj->getMeta('resizeWidth') . 'x' . $imageObj->getMeta('resizeHeight');
						$type = ($imageObj->getMeta('resizeType') !== null) ? '(' . $imageObj->getMeta('resizeType') . ')' : '';
            $stats[] = array(sprintf(__('Resized %s %s to %s'), $type, $from, $to), '');
        }

        $tsOptimized = $imageObj->getMeta('tsOptimized');
        if ($tsOptimized !== null)
          $stats[] = array(__("Optimized on :", 'shortpixel-image-optimiser') . "<br /> ", UiHelper::formatTS($tsOptimized) );

				if ($imageObj->isOptimized())
				{
					$stats[] = array( sprintf(__('%s %s Read more about theses stats %s ', 'shortpixel-image-optimiser'), '
					<p><img alt=' . esc_html('Info Icon', 'shortpixel-image-optimiser')  . ' src=' . esc_url( wpSPIO()->plugin_url('res/img/info-icon.png' )) . ' style="margin-bottom: -4px;"/>', '<a href="https://shortpixel.com/knowledge-base/article/the-stats-from-the-shortpixel-column-in-the-media-library-explained/" target="_blank">', '</a></p>'), '');
				}

        return $stats;
      }

      /**
       * Collects full diagnostic information for an attachment (debug mode only).
       *
       * Returns an empty array immediately when SPIO debug mode is off. Otherwise
       * builds an array of [label, value] pairs covering: attachment URL and file
       * path, virtual status, image dimensions and MIME type, ShortPixel status flags
       * (processable, optimized, restorable, DB record), conversion metadata, WPML
       * duplicates, queue enqueue data, AI processability (when AI is enabled),
       * backup file locations for the main image and all thumbnails, and the raw
       * WordPress attachment metadata array.
       *
       * @return array<int|string, array{0: string, 1: mixed}> Rows of [label, value] pairs.
       */
      protected function getDebugInfo()
      {
          if(! \wpSPIO()->env()->is_debug )
          {
            return [];
          }

          $meta = \wp_get_attachment_metadata($this->post_id);

          $fs = \wpSPIO()->filesystem();

					$imageObj = $this->imageModel;

					if ($imageObj->isProcessable())
					{
						 $optimizeData = $imageObj->getOptimizeData();
						 $urls = $optimizeData['urls'];
					}

          $optimizeAiController = OptimizeAiController::getInstance();

					$thumbnails = $imageObj->get('thumbnails');
					$processable = ($imageObj->isProcessable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $imageObj->getReason('processable') . ')';
          $optimized = ($imageObj->isOptimized()) ? '<span class="green">Yes</span>' : '<span class="red">No</span>';

					$anyFileType = ($imageObj->isProcessableAnyFileType()) ? '<span class="green">Yes</span>' : '<span class="red">No</span>';
					$restorable = ($imageObj->isRestorable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $imageObj->getReason('restorable') . ')';

					$hasrecord = ($imageObj->hasDBRecord()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> ';

          $debugInfo = [];
          $debugInfo[] = array(__('URL (get attachment URL)', 'shortpixel_image_optiser'), wp_get_attachment_url($this->post_id));
          $debugInfo[] = array(__('File (get attached)'), get_attached_file($this->post_id));

					if ($imageObj->is_virtual())
					{
            $virtual = $imageObj->get('virtual_status');
            if($virtual == FileModel::$VIRTUAL_REMOTE)
              $vtext = 'Remote';
            elseif($virtual == FileModel::$VIRTUAL_STATELESS)
              $vtext = 'Stateless';
            else
              $vtext = 'Not set';

						$debugInfo[] = array(__('Is Virtual: ') . $vtext, $imageObj->getFullPath() );
					}

          $debugInfo[] = array(__('Size and Mime (ImageObj)'), $imageObj->get('width') . 'x' . $imageObj->get('height'). ' (' . $imageObj->get('mime') . ')');
          $debugInfo[] = array(__('Status (ShortPixel)'), $imageObj->getMeta('status') . ' '   );

					$debugInfo[] = array(__('Processable'), $processable);
          $debugInfo[] = array(__('Optimized'), $optimized);
					$debugInfo[] = array(__('Avif/Webp needed'), $anyFileType);
					$debugInfo[] = array(__('Restorable'), $restorable);
					$debugInfo[] = array(__('Record'), $hasrecord);

					if ($imageObj->getMeta()->convertMeta()->didTry())
					{
						 $debugInfo[] = array(__('Converted'), ($imageObj->getMeta()->convertMeta()->isConverted() ?'<span class="green">Yes</span>' : '<span class="red">No</span> '));
						 $debugInfo[] = array(__('Checksum'), $imageObj->getMeta()->convertMeta()->didTry());
						 $debugInfo[] = array(__('Error'), $imageObj->getMeta()->convertMeta()->getError());
					}

          $debugInfo[] = array(__('WPML Duplicates'), json_encode($imageObj->getWPMLDuplicates()) );

					if ($imageObj->getParent() !== false)
					{
						 $debugInfo[] = array(__('WPML duplicate - Parent: '), $imageObj->getParent());
					}

					if (isset($urls))
					{
						 $debugInfo[] = array(__('To Optimize URLS'),  $urls);
					}

          $item = QueueItems::getImageItem($imageObj);

          if ($imageObj->isProcessable())
					{
             $item->setDebug();
             $item->newOptimizeAction();

             $counts = $item->data()->counts;

						 $returnEnqueue = $item->returnEnqueue();

						 $debugInfo[] = array(__('Image to Queue'), $returnEnqueue );
             $debugInfo[] = [__('Counts'), $counts];

					}

          if ( $optimizeAiController->isAIEnabled())
          {
            $aiDataModel = AiDataModel::getModelByAttachment($this->post_id);

            $aiProcessable = ($aiDataModel->isProcessable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> ';

            $debugInfo[] = ['AI - is Processable', $aiProcessable]; 

            if (true === $aiDataModel->isProcessable())
            {
              $debugInfo[] = ['Ai - Paramlist ', $aiDataModel->getOptimizeData() ];            
            }
            else
            {
               $debugInfo[] = ['Ai - Reason', $aiDataModel->getProcessableReason()];
            }
            if (true === $aiDataModel->isSomeThingGenerated())
            {
              $debugInfo[] = ['Ai -Generated ', $aiDataModel->getGeneratedData()];
            }

          }

          $backupController = BackupController::getBackupController(); 
          
          $backupModel = $backupController->getModel($imageObj);
          $backupData = $backupModel->getBackupData(); 

          $needs_regen = ($backupModel->needsRegenerate()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> ';

          $debugInfo[] = ['Backup thumbnails needs regenerate', $needs_regen]; 
          $debugInfo['backupData'] = ['BackupData', $backupData]; 


          $debugInfo['imagemetadata'] = array(__('ImageModel Metadata (ShortPixel)'), $imageObj);
					$debugInfo[] = array('', '<hr>');

          $debugInfo['wpmetadata'] = array(__('WordPress Get Attachment Metadata'), $meta );
					$debugInfo[] = array('', '<hr>');


						if ($backupModel->hasBackup($imageObj) )
            {
            	$backupFile = $backupModel->getBackupFile($imageObj);
              if (false === is_object($backupFile))
              {
                 $backupFile = $backupModel->getMainBackupFile();
              }
            }
						else {
							 $backupFile = $fs->getFile($fs->getBackupDirectory($imageObj) . $backupModel->getBackupFileName($imageObj));
						}

            $debugInfo[] = array(__('Backup Folder'), (string) $backupFile->getFileDir() );
						if ($backupModel->hasBackup($imageObj))
            {
							$backupText = __('Backup File :');
              $debugInfo[] = array( $backupText, (string) $backupFile . '(' . UiHelper::formatBytes($backupFile->getFileSize()) . ')' );

              $debugInfo[] = ['Main Backup:', (string) $backupModel->getMainBackupFile()];
            }
              else {
							$backupText = __('Target Backup File after optimization (no backup) ');
              $debugInfo[] = [$backupText, (string) $backupFile];
						}

            $debugInfo[] =  array(__("No Main File Backup Available"), '');

					if ($imageObj->getMeta()->convertMeta()->isConverted())
					{
							//$convertedBackup = ($imageObj->hasBackup(array('forceConverted' => true))) ? '<span class="green">Yes</span>' : '<span class="red">No</span>';
              $convertedBackup = ($backupModel->hasBackup($imageObj)) ? '<span class="green">Yes</span>' : '<span class="red">No</span>';
							$backup = $backupModel->getBackupFile($imageObj);
						 $debugInfo[] = array('Has converted backup', $convertedBackup);
						 if (is_object($backup))
						 	$debugInfo[] = array('Backup: ', $backup->getFullPath() );
				}

          if (true === $imageObj->hasOriginal())
          {
             $original = $imageObj->getOriginalFile();
             $debugInfo[] = array(__('Has Original File: '), $original->getFullPath()  . '(' . UiHelper::formatBytes($original->getFileSize()) . ')');
             $orbackup = $backupModel->getBackupFile($original);

             $processable = ($original->isProcessable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $original->getReason('processable') . ')';
             
             $restorable = ($original->isRestorable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . 		$original->getReason('restorable') . ')';

             $debugInfo[] = ['Original Processable:', $processable];
             $debugInfo[] = ['Original Restorable:', $restorable];


          if ($orbackup)
              $debugInfo[] = array(__('Has Backup Original Image'), $orbackup->getFullPath() . '(' . UiHelper::formatBytes($orbackup->getFileSize()) . ')');
						$debugInfo[] = array('', '<hr>');
          }


          if (! isset($meta['sizes']) )
          {
             $debugInfo[] = array('',  __('Thumbnails were not generated', 'shortpixel-image-optimiser'));
          }
          else
          {
            foreach($thumbnails as $thumbObj)
            {
							$size = $thumbObj->get('size');

              $display_size = ucfirst(str_replace("_", " ", $size));

              if ($thumbObj === false)
              {
                $debugInfo[] =  array(__('Thumbnail not found / loaded: ', 'shortpixel-image-optimiser'), $size );
                continue;
              }

            //  $url = $thumbObj->getURL(); 
              $url = $fs->pathToUrl($thumbObj);
              $filename = $thumbObj->getFullPath();
              $fileDir = $thumbObj->getFileDir();

							$backupFile = $backupModel->getBackupFile($thumbObj);
							if ($backupModel->hasBackup($thumbObj) && is_object($backupFile))
							{
								$backup = $backupFile->getFullPath();
								$backupText = __('Backup File :');
							}
							else {
								$backupFile = $fs->getFile($fs->getBackupDirectory($thumbObj) . $backupModel->getBackupFileName($thumbObj));
								$backup = $backupFile->getFullPath();
								$backupText = __('Target Backup File after optimization (no backup) ');
							}

              $width = $thumbObj->get('width');
              $height = $thumbObj->get('height');

					$processable = ($thumbObj->isProcessable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $thumbObj->getReason('processable') . ')';
					$restorable = ($thumbObj->isRestorable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . 		$thumbObj->getReason('restorable') . ')';
					$hasrecord = ($thumbObj->hasDBRecord()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> ';

					$dbid = $thumbObj->getMeta('databaseID');

              $debugInfo[] = array('', "<div class='$size previewwrapper'><img src='" . $url . "'><p class='label'>
							<b>URL:</b> $url ( $display_size - $width X $height ) <br><b>FileName:</b>  $filename <br>
              <b>FileDir:</b> $fileDir <br> <b> $backupText </b> $backup </p>
							<p><b>Processable: </b> $processable <br> <b>Restorable:</b>  $restorable <br> <b>Record:</b> $hasrecord ($dbid) </p>
							<hr></div>");
            }
          }
          return $debugInfo;
      }



} // controller .
