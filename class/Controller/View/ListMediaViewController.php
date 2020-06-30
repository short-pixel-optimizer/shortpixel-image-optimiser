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

  //   $is_processable = $mediaItem->isProcessable();

    $this->view->text = UiHelper::getStatusText($mediaItem);
    $this->view->list_actions = UiHelper::getListActions($mediaItem);
    if ( count($this->view->list_actions) > 0)
      $this->view->list_actions = UiHelper::renderBurgerList($this->view->list_actions, $mediaItem);
    else
      $this->view->list_actions = '';

    $this->view->actions = UiHelper::getActions($mediaItem);
    //$this->view->actions = $actions;

    if (! $this->userIsAllowed)
    {
      $this->view->actions = array();
      $this->view->list_actions = '';
    }
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





}
