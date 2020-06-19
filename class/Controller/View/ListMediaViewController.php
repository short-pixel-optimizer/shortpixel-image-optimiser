<?php

namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Model\Image\ImageModel as ImageModel;

// Controller for the MediaLibraryView
class ListMediaViewController extends \ShortPixel\Controller
{

  protected $template = 'view-list-media';
//  protected $model = 'image';

  protected $hooked = false;

  public function __construct()
  {
    parent::__construct();

    if (! $this->hooked)
      $this->loadHooks();

  }

  protected function loadHooks()
  {

    add_filter( 'manage_media_columns', array( $this, 'headerColumns' ) );//add media library column header
    add_action( 'manage_media_custom_column', array( $this, 'doColumn' ), 10, 2 );//generate the media library column
    //Sort and filter on ShortPixel Compression column
    add_filter( 'manage_upload_sortable_columns', array( $this, 'registerSortable') );
    add_filter( 'request', array( $this, 'filterBy') );
  }

  public function headerColumns($defaults)
  {
    $defaults['wp-shortPixel'] = __('ShortPixel Compression', 'shortpixel-image-optimiser');
    if(current_user_can( 'manage_options' )) {
        $defaults['wp-shortPixel'] .=
                  '&nbsp;<a href="options-general.php?page=wp-shortpixel-settings&part=stats" title="'
                . __('ShortPixel Statistics','shortpixel-image-optimiser')
                . '"><span class="dashicons dashicons-dashboard"></span></a>';
    }
    return $defaults;
  }

  public function doColumn($column_name, $id)
  {
     if($column_name == 'wp-shortPixel')
     {
       $this->view = new \stdClass; // reset every row
       $this->loadItem($id);
       $this->loadView();
     }
  }



  public function loadItem($id)
  {
     $fs = \wpSPIO()->filesystem();
     $mediaItem = $fs->getMediaImage($id);
     $keyControl = ApiKeyController::getInstance();
     $quotaControl = QuotaController::getInstance();

     $this->view->mediaItem = $mediaItem;

     $actions = array();
     $list_actions = array();

     $is_processable = $mediaItem->isProcessable();

     if (! $keyControl->keyIsVerified())
     {
       $this->view->text = __('Invalid API Key. <a href="options-general.php?page=wp-shortpixel-settings">Check your Settings</a>','shortpixel-image-optimiser');
     }
     elseif(! $quotaControl->hasQuota())
     {
        $actions['extendquota'] = $this->getAction('extendquota', $id);
        $actions['checkquota'] = $this->getAction('checkquota', $id);
     }
     elseif (! $is_processable)
     {
        $this->view->text = __('n/a','shortpixel_image_optimiser');
     }
     elseif (! $mediaItem->exists())
     {
        $this->view->text = __('Image does not exist.','shortpixel-image-optimiser');
     }
     elseif ($mediaItem->isOptimized())
     {
        $this->view->text = UiHelper::renderSuccessText($mediaItem);

        if ($mediaItem->hasBackup())
        {
            $optimizable = $mediaItem->getOptimizePaths();
          //  var_dump($optimizable);
            if (count($optimizable) > 0)
            {
              $action = $this->getAction('optimizethumbs', $id);
              $action['text']  = sprintf(__('Optimize %s  thumbnails','shortpixel-image-optimiser'),count($optimizable));
              $list_actions['optimizethumbs'] = $action;

            }
            $list_actions[] = $this->getAction('compare', $id);

            switch($mediaItem->getMeta('type'))
            {
                case ImageModel::COMPRESSION_LOSSLESS:
                  $list_actions['reoptimize-lossy'] = $this->getAction('reoptimize-lossy', $id);
                  $list_actions['reoptimize-glossy'] = $this->getAction('reoptimize-glossy', $id);
                break;
                case ImageModel::COMPRESSION_LOSSY:
                  $list_actions['reoptimize-lossless'] = $this->getAction('reoptimize-lossless', $id);
                  $list_actions['reoptimize-glossy'] = $this->getAction('reoptimize-glossy', $id);
                break;
                case ImageModel::COMPRESSION_GLOSSY:
                  $list_actions['reoptimize-lossy'] = $this->getAction('reoptimize-lossy', $id);
                  $list_actions['reoptimize-lossless'] = $this->getAction('reoptimize-lossless', $id);
                break;
            }

           $list_actions['restore'] = $this->getAction('restore', $id);
        }
     }
     elseif($is_processable)
     {
        $this->actions['optimize'] = $this->getAction('optimize', $id);
     }


     if (count($list_actions) > 0)
     {
       $this->view->list_actions = UiHelper::renderBurgerList($list_actions, $mediaItem);
     }

     $this->view->actions = $actions;
  }

  public function registerSortable($columns)
  {
      $columns['wp-shortPixel'] = 'ShortPixel Compression';
      return $columns;
  }

  public function filterBy($vars)
  {
    if ( isset( $vars['orderby'] ) && 'ShortPixel Compression' == $vars['orderby'] ) {
        $vars = array_merge( $vars, array(
            'meta_key' => '_shortpixel_status',
            'orderby' => 'meta_value_num',
        ) );
    }
    if ( 'upload.php' == $GLOBALS['pagenow'] && isset( $_GET['shortpixel_status'] ) ) {

        $status       = sanitize_text_field($_GET['shortpixel_status']);
        $metaKey = '_shortpixel_status';
        //$metaCompare = $status == 0 ? 'NOT EXISTS' : ($status < 0 ? '<' : '=');

        if ($status == 'all')
          return $vars; // not for us

        switch($status)
        {
           case "opt":
              $status = ShortPixelMeta::FILE_STATUS_SUCCESS;
              $metaCompare = ">="; // somehow this meta stores optimization percentage.
            break;
            case "unopt":
              $status = ShortPixelMeta::FILE_STATUS_UNPROCESSED;
              $metaCompare = "NOT EXISTS";
            break;
            case "pending":
              $status = ShortPixelMeta::FILE_STATUS_PENDING;
              $metaCompare = "=";
            break;
            case "error":
              $status = -1;
              $metaCompare = "<=";
            break;

        }

        $vars = array_merge( $vars, array(
            'meta_query' => array(
                array(
                    'key'     => $metaKey,
                    'value'   => $status,
                    'compare' => $metaCompare,
                ),
            )
        ));
    }

    return $vars;
  }


  protected function getAction($name, $id)
  {
     $action = array('function' => '', 'type' => '', 'text' => '', 'display' => '');
     $keyControl = ApiKeyController::getInstance();

    // @todo Needs Nonces on Links
    switch($name)
    {
      case 'optimize':
         $action['function'] = 'manualOptimization(' . $id . ')';
         $action['type']  = 'js';
         $action['text'] = __('Optimize Now', 'shortpixel-image-optimiser');
         $action['display'] = 'button';
      break;
      case 'optimizethumbs':
          $action['function'] = 'optimizeThumbs(' . $id . ')';
          $action['type'] = 'js';
          $action['text']  = '';
          $action['display'] = 'inline';
      break;

      case 'retry':
         $action['function'] = 'manualOptimization(' . $id .', false)';
         $action['type']  = 'js';
         $action['text'] = __('Retry', 'shortpixel-image-optimiser') ;
         $action['display'] = 'button';
     break;

     case 'restore':
         $action['function'] = 'admin.php?action=shortpixel_restore_backup&attachment_ID=' . $id;
         $action['type'] = 'link';
         $action['text'] = __('Restore backup','shortpixel-image-optimiser');
         $action['display'] = 'inline';
     break;

     case 'compare':
        $action['function'] = 'ShortPixel.loadComparer(' . $id . ')';
        $action['type'] = 'js';
        $action['text'] = __('Compare', 'shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;
     case 'reoptimize-glossy':
        $action['function'] = 'reoptimize(' . $id . ', glossy)';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Glossy','shortpixel-image-optimiser') ;
        $action['display'] = 'inline';
     break;
     case 'reoptimize-lossy':
        $action['function'] = 'reoptimize(' . $id . ', lossy)';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Lossy','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;

     case 'reoptimize-lossless':
        $action['function'] = 'reoptimize(' . $id . ', lossless)';
        $action['type'] = 'js';
        $action['text'] = __('Re-optimize Lossless','shortpixel-image-optimiser');
        $action['display'] = 'inline';
     break;

     case 'extendquota':
        $action['function'] = 'https://shortpixel.com/login'. $keyControl->getKeyForDisplay();
        $action['type'] = 'button';
        $action['text'] = __('Extend Quota','shortpixel-image-optimiser');
        $action['display'] = 'button';
     break;
     case 'checkquota':
        $action['function'] = 'ShortPixel.checkQuota()';
        $action['type'] = 'js';
        $action['display'] = 'button';
        $action['text'] = __('Check&nbsp;&nbsp;Quota','shortpixel-image-optimiser');

     break;


   }

   return $action;
  }


}
