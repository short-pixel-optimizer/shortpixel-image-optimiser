<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;

//use ShortPixel\Model\ImageModel as ImageModel;

// Future contoller for the edit media metabox view.
class EditMediaViewController extends \ShortPixel\Controller
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

          $fs = \wpSPIO()->filesystem();
          $this->imageModel = $fs->getMediaImage($this->post_id);
        //  $this->imageModel->setByPostID($post_id);
          $this->imageModel->reAcquire(); // single display mode - reset things.

          $this->view->id = $this->post_id;
          $this->view->status_message = null;


          $this->view->text = UiHelper::getStatusText($this->imageModel);
          $this->view->list_actions = UiHelper::getListActions($this->imageModel);
          if ( count($this->view->list_actions) > 0)
            $this->view->list_actions = UiHelper::renderBurgerList($this->view->list_actions, $this->imageModel);
          else
            $this->view->list_actions = '';


          $this->view->actions = UiHelper::getActions($this->imageModel);
          // $this->view->actions = $actions;
          $this->view->stats = $this->getStatistics();

          if (! $this->userIsAllowed)
          {
            $this->view->actions = array();
            $this->view->list_actions = '';
          }

          $this->view->debugInfo = $this->getDebugInfo();

        //  $this->view->message = isset($this->data['message']) ? $this->data['message'] : '';
          //$this->view->r
          $this->loadView();

      }

      protected function getStatusMessage()
      {
          return UIHelper::renderSuccessText($this->imageModel);

        /*  if (! isset($this->data['status']))
            return;

          switch($this->data['status'])
          {
            case 'n/a':
                return _e('Optimization N/A','shortpixel-image-optimiser');
            break;
            case 'notFound':
                return  _e('Image does not exist.','shortpixel-image-optimiser');
                break;
            case 'invalidKey':
                return _e('Invalid API Key. <a href="options-general.php?page=wp-shortpixel-settings">Check your Settings</a>','shortpixel-image-optimiser');
            break;
            case 'quotaExceeded':
                return __('Quota Exceeded','shortpixel-image-optimiser');
            break;
          } */
      }

    /*  protected function getActions()
      {
          $actions = array();
          if (! $this->userIsAllowed)
            return $actions;

          switch($this->data['status'])
          {
            case 'optimizeNow':
              $actions[] =  '<a class="button button-smaller button-primary" href="javascript:manualOptimization(' . $this->post_id . ',false)">
                                          ' .  __('Optimize now','shortpixel-image-optimiser') . '
                            </a>';
            break;
            case 'retry':
            case 'waiting':
              if (isset($this->data['cleanup']))
              {
                  $actions[] = '<a class="button button-smaller button-primary" href="javascript:manualOptimization(' . $this->post_id . ', true)">' .
                       __('Cleanup&Retry','shortpixel-image-optimiser') . '</a>';
              }
              else {
                if($this->data['status'] == 'retry' && (isset($this->data['backup']) && $this->data['backup']) ) {
                  $actions[] =  '<a class="button button-smaller sp-action-restore"
                                href="admin.php?action=shortpixel_restore_backup&attachment_ID=' . $this->post_id . '" style="margin-left:5px;"
                        title="' . __('Restore Image from Backup', 'shortpixel-image-optimiser') . '">
                         ' . __('Cleanup','shortpixel-image-optimiser') . '</a>';

                    }
                  $actions[] = '<a class="button button-smaller button-primary" href="javascript:manualOptimization(' . $this->post_id . ', false)">' .
                         __('Retry','shortpixel-image-optimiser')  . '</a>';
              }
            break;
          }

          return $actions;
      } */

      protected function getStatistics()
      {
        //$data = $this->data;
        $stats = array();
        $imageObj = $this->imageModel;
        $did_keepExif = $imageObj->getMeta('did_keepExif');
        $did_png2jpg = $imageObj->getMeta('did_png2jpg');
        $resize = $imageObj->getMeta('resize');

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

      /*  $successText .= ($data['webpCount'] ? "<br>+" . $data['webpCount'] . __(" WebP images", 'shortpixel-image-optimiser') : "")
                . "<br>EXIF: " . ($data['exifKept'] ? __('kept','shortpixel-image-optimiser') :  __('removed','shortpixel-image-optimiser'))
                . ($data['png2jpg'] ? '<br>' . __('Converted from PNG','shortpixel-image-optimiser'): '')
                . "<br>" . __("Optimized on", 'shortpixel-image-optimiser') . ": " . $data['date']
                . $todoSizes . $excludeSizes . $missingThumbs;
*/

        return $stats;
      }

/*
      protected function getTodo() // @todo Completely fix this.
      {
        $data = $this->data;
        if ( $data['status'] != 'pdfOptimized' && $data['status'] != 'imgOptimized')
          return array();

              $excluded = (isset($data['excludeSizes']) ? count($data['excludeSizes']) : 0);
              $todoSizes = $missingThumbs = $excludeSizes = '';

                if(isset($data['thumbsToOptimizeList']) && count($data['thumbsToOptimizeList'])) {
                    $todoSizes .= "<br><span style='word-break: break-all;'> <span style='font-weight: bold;'>" . __("To optimize:", 'shortpixel-image-optimiser') . "</span>";
                    foreach($data['thumbsToOptimizeList'] as $todoItem) {
                        $todoSizes .= "<br> &#8226;&nbsp;" . $todoItem;
                    }
                    $todoSizes .= '</span>';
                }
                if(isset($data['excludeSizes']) && count($data['excludeSizes']) > 0 ) {
                    $excludeSizes .= "<br><span style='word-break: break-all;'> <span style='font-weight: bold;'>" . __("Excluded thumbnails:", 'shortpixel-image-optimiser') . "</span>";
                    foreach($data['excludeSizes'] as $excludedItem) {
                        $excludeSizes .= "<br> &#8226;&nbsp;" . $excludedItem;
                    }
                    $excludeSizes .= '</span>';
                }
                if(count($data['thumbsMissing'])) {
                    $missingThumbs .= "<br><span style='word-break: break-all;'> <span style='font-weight: bold;'>" . __("Missing thumbnails:", 'shortpixel-image-optimiser') . "</span>";
                    foreach($data['thumbsMissing'] as $miss) {
                        $missingThumbs .= "<br> &#8226&nbsp;" . $miss;
                    }
                    $missingThumbs .= '</span>';
                }

              return array($todoSizes, $excludeSizes, $missingThumbs);
      }
*/
      protected function getDebugInfo()
      {
          if(! \wpSPIO()->env()->is_debug )
          {
            return array();
          }

          $meta = \wp_get_attachment_metadata($this->post_id);

          $fs = \wpSPIO()->filesystem();
          $imageObj = $fs->getMediaImage($this->post_id);
          /*$imageObj = new ImageModel();
          $imageObj->setByPostID($this->post_id); */
        //  $imageFile = //$imageObj->getFile();

          $sizes = isset($this->data['sizes']) ? $this->data['sizes'] : array();

          //$debugMeta = $imageObj->debugGetImageMeta();

          $debugInfo = array();
          $debugInfo[] = array(__('URL', 'shortpixel_image_optiser'), wp_get_attachment_url($this->post_id));
          $debugInfo[] = array(__('File'), get_attached_file($this->post_id));
          $debugInfo[] = array(__('Size and Mime'), $imageObj->get('width') . 'x' . $imageObj->get('height'). '(' . $imageObj->get('mime') . ')');
          $debugInfo[] = array(__('Status'), $this->imageModel->getMeta('status')  );

          $debugInfo[] = array(__('WPML Duplicates'), json_encode($imageObj->getWPMLDuplicates()) );
          $debugInfo['imagemetadata'] = array(__('ImageModel Metadata'), $imageObj);

          $debugInfo['shortpixeldata'] = array(__('Data'), $this->data);
          $debugInfo['wpmetadata'] = array(__('WP Get Attachment Metadata'), $meta );
          if ($imageObj->hasBackup())
          {
            $backupFile = $imageObj->getBackupFile();
            $debugInfo[] = array(__('Backup Folder'), (string) $backupFile->getFileDir() );
            $debugInfo[] = array(__('Backup File'), (string) $backupFile . '(' . \ShortPixelTools::formatBytes($backupFile->getFileSize()) . ')' );
          }
          else {
            $debugInfo[] =  array(__("No Backup Available"), '');
          }

          if ($or = $imageObj->hasOriginal())
          {
             $original = $imageObj->getOriginalFile();
             $debugInfo[] = array(__('Original File'), $original->getFullPath()  . '(' . \ShortPixelTools::formatBytes($original->getFileSize()) . ')');
             $orbackup = $original->getBackupFile();
             if ($orbackup)
              $debugInfo[] = array(__('Backup'), $orbackup->getFullPath() . '(' . \ShortPixelTools::formatBytes($orbackup->getFileSize()) . ')');
          }

          if (! isset($meta['sizes']) )
          {
             $debugInfo[] = array('',  __('Thumbnails were not generated', 'shortpixel-image-optimiser'));
          }
          else
          {
            foreach($meta['sizes'] as $size => $data)
            {
              $display_size = ucfirst(str_replace("_", " ", $size));
              $thumbObj = $imageObj->getThumbnail($size);

              if ($thumbObj === false)
              {
                $debugInfo[] =  array(__('Thumbnail not found / loaded: ', 'shortpixel-image-optimiser'), $size );
                continue;
              }

              $url = $fs->pathToURL($thumbObj); //wp_get_attachment_image_src($this->post_id, $size);
              $filename = $thumbObj->getFullPath();
            //  $debugMeta =// print_r($thumbObj->debugGetImageMeta(), true);
              $width = $thumbObj->get('width');
              $height = $thumbObj->get('height');

              $debugInfo[] = array('', "<div class='$size previewwrapper'><img src='" . $url . "'><p class='label'>$url ( $display_size - $width X $height ) <br> $filename</p><p>&nbsp;</p></div>");
            }
          }
          return $debugInfo;
      }

} // controller .
