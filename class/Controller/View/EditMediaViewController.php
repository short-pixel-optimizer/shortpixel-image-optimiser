<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;


//use ShortPixel\Model\ImageModel as ImageModel;

// Future contoller for the edit media metabox view.
class EditMediaViewController extends \ShortPixel\ViewController
{
      protected $template = 'view-edit-media';
  //    protected $model = 'image';

      protected $post_id;
      protected $legacyViewObj;

      protected $imageModel;
      protected $hooked;

      public function __construct()
      {
        parent::__construct();
      }

      protected function loadHooks()
      {
            add_action( 'add_meta_boxes_attachment', array( $this, 'addMetaBox') );
            $this->hooked = true;
      }

      public function load()
      {
        if (! $this->hooked)
          $this->loadHooks();
      }

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

					// @todo remove this if not DEVMODE
          $this->view->debugInfo = $this->getDebugInfo();

          $this->loadView();
      }

      protected function getStatusMessage()
      {
          return UIHelper::renderSuccessText($this->imageModel);
      }

      protected function getStatistics()
      {
        //$data = $this->data;
        $stats = array();
        $imageObj = $this->imageModel;
        $did_keepExif = $imageObj->getMeta('did_keepExif');
        $did_png2jpg = $imageObj->getMeta('did_png2jpg');
        $resize = $imageObj->getMeta('resize');

				// Not optimized, not data.
				if (! $imageObj->isOptimized())
					return array();

        if ($did_keepExif)
          $stats[] = array(__('EXIF kept', 'shortpixel-image-optimiser'), '');
        elseif ( $did_keepExif === false) {
          $stats[] = array(__('EXIF removed', 'shortpixel-image-optimiser'), '');
        }

        if ($did_png2jpg == true)
        {
          $stats[] = array(  __('Converted from PNG','shortpixel-image-optimiser'), '');
        }

        if ($resize == true)
        {
            $from = $imageObj->getMeta('originalWidth') . 'x' . $imageObj->getMeta('originalHeight');
            $to  = $imageObj->getMeta('resizeWidth') . 'x' . $imageObj->getMeta('resizeHeight');
            $stats[] = array(sprintf(__('Resized %s to %s'), $from, $to), '');
        }

        $tsOptimized = $imageObj->getMeta('tsOptimized');
        if ($tsOptimized !== null)
          $stats[] = array(__("Optimized on :", 'shortpixel-image-optimiser') . "<br /> ", UiHelper::formatTS($tsOptimized) );

        return $stats;
      }

      protected function getDebugInfo()
      {
          if(! \wpSPIO()->env()->is_debug )
          {
            return array();
          }

          $meta = \wp_get_attachment_metadata($this->post_id);

          $fs = \wpSPIO()->filesystem();

					$imageObj = $this->imageModel;

					if ($imageObj->isProcessable())
					{
						 $urls = $imageObj->getOptimizeUrls();

						 if ($imageObj->isProcessableFileType('webp'))
						 {
							$diff = array_diff($imageObj->getOptimizeFileType('webp'), $urls); // diff from mains.
							foreach($diff as $i => $v)
							{
								$diff[$i] = " [webp] " . $v;
							}
						 	$urls = array_merge($urls, $diff);
						 }
						 if ($imageObj->isProcessableFileType('avif'))
						 {
						 	$diff = array_diff($imageObj->getOptimizeFileType('avif'), $urls); // diff from mains.
							foreach($diff as $i => $v)
							{
								$diff[$i] = " [avif] " . $v;
							}
						 	$urls = array_merge($urls, $diff);
						 }
					}

					$thumbnails = $imageObj->get('thumbnails');
					$processable = ($imageObj->isProcessable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $imageObj->getReason('processable') . ')';
					$restorable = ($imageObj->isRestorable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $imageObj->getReason('restorable') . ')';
        //  $sizes = isset($this->data['sizes']) ? $this->data['sizes'] : array();

          //$debugMeta = $imageObj->debugGetImageMeta();

          $debugInfo = array();
          $debugInfo[] = array(__('URL (get attachment URL)', 'shortpixel_image_optiser'), wp_get_attachment_url($this->post_id));
          $debugInfo[] = array(__('File (get attached)'), get_attached_file($this->post_id));

					if ($imageObj->is_virtual())
					{
						$debugInfo[] = array(__('Is Virtual'), $imageObj->getFullPath() );
					}

          $debugInfo[] = array(__('Size and Mime (ImageObj)'), $imageObj->get('width') . 'x' . $imageObj->get('height'). ' (' . $imageObj->get('mime') . ')');
          $debugInfo[] = array(__('Status (ShortPixel)'), $imageObj->getMeta('status') . ' '   );



					$debugInfo[] = array(__('Processable'), $processable);
					$debugInfo[] = array(__('Restorable'), $restorable);

          $debugInfo[] = array(__('WPML Duplicates'), json_encode($imageObj->getWPMLDuplicates()) );

					if (isset($urls))
					{
						 $debugInfo[] = array(__('To Optimize URLS'),  $urls);
					}

          $debugInfo['imagemetadata'] = array(__('ImageModel Metadata (ShortPixel)'), $imageObj);
					$debugInfo[] = array('', '<hr>');

          //$debugInfo['shortpixeldata'] = array(__('Data'), $this->data);
          $debugInfo['wpmetadata'] = array(__('WordPress Get Attachment Metadata'), $meta );
					$debugInfo[] = array('', '<hr>');

          if ($imageObj->hasBackup())
          {
            $backupFile = $imageObj->getBackupFile();
            $debugInfo[] = array(__('Backup Folder'), (string) $backupFile->getFileDir() );
            $debugInfo[] = array(__('Backup File'), (string) $backupFile . '(' . \ShortPixelTools::formatBytes($backupFile->getFileSize()) . ')' );
          }
          else {
            $debugInfo[] =  array(__("No Main File Backup Available"), '');
          }

          if ($or = $imageObj->hasOriginal())
          {
             $original = $imageObj->getOriginalFile();
             $debugInfo[] = array(__('Has Original File: '), $original->getFullPath()  . '(' . \ShortPixelTools::formatBytes($original->getFileSize()) . ')');
             $orbackup = $original->getBackupFile();
             if ($orbackup)
              $debugInfo[] = array(__('Has Backup Original Image'), $orbackup->getFullPath() . '(' . \ShortPixelTools::formatBytes($orbackup->getFileSize()) . ')');
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
              //$thumbObj = $imageObj->getThumbnail($size);

              if ($thumbObj === false)
              {
                $debugInfo[] =  array(__('Thumbnail not found / loaded: ', 'shortpixel-image-optimiser'), $size );
                continue;
              }

              $url = $thumbObj->getURL(); //$fs->pathToURL($thumbObj); //wp_get_attachment_image_src($this->post_id, $size);
              $filename = $thumbObj->getFullPath();
							$backup = $thumbObj->hasBackup() ? $thumbObj->getBackupFile()->getFullPath() : 'n/a';
            //  $debugMeta =// print_r($thumbObj->debugGetImageMeta(), true);
              $width = $thumbObj->get('width');
              $height = $thumbObj->get('height');

					$processable = ($thumbObj->isProcessable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . $thumbObj->getReason('processable') . ')';
					$restorable = ($thumbObj->isRestorable()) ? '<span class="green">Yes</span>' : '<span class="red">No</span> (' . 		$thumbObj->getReason('restorable') . ')';

              $debugInfo[] = array('', "<div class='$size previewwrapper'><img src='" . $url . "'><p class='label'>
							<b>URL:</b> $url ( $display_size - $width X $height ) <br><b>FileName:</b>  $filename <br> <b>Backup:</b> $backup </p>
							<p><b>Processable: </b> $processable <br> <b>Restorable:</b>  $restorable</p>
							<hr></div>");
            }
          }
          return $debugInfo;
      }



} // controller .
