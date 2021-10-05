<?php

namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Model\Image\ImageModel as ImageModel;

// Controller for the MediaLibraryView
class ListMediaViewController extends \ShortPixel\ViewController
{

  protected $template = 'view-list-media';
//  protected $model = 'image';

  protected $hooked = false;

  public function __construct()
  {
    parent::__construct();


  }

  public function load()
  {
    if (! $this->hooked)
      $this->loadHooks();
  }

  /** Hooks for the MediaLibrary View */
  protected function loadHooks()
  {

    add_filter( 'manage_media_columns', array( $this, 'headerColumns' ) );//add media library column header
    add_action( 'manage_media_custom_column', array( $this, 'doColumn' ), 10, 2 );//generate the media library column
    //Sort and filter on ShortPixel Compression column
    add_filter( 'manage_upload_sortable_columns', array( $this, 'registerSortable') );
    add_filter( 'request', array( $this, 'filterBy') );
    add_action('restrict_manage_posts', array( $this, 'mediaAddFilterDropdown'));

    add_action('loop_end', array($this, 'loadComparer'));

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
       $this->loadView(null, false);
     }
  }

  public function loadItem($id)
  {
     $fs = \wpSPIO()->filesystem();
     $mediaItem = $fs->getMediaImage($id);
     $keyControl = ApiKeyController::getInstance();
     $quotaControl = QuotaController::getInstance();

		 // Asking for something non-existing.
		 if ($mediaItem === false)
		 	 return;

     $this->view->mediaItem = $mediaItem;

     $actions = array();
     $list_actions = array();

    $this->view->text = UiHelper::getStatusText($mediaItem);
    $this->view->list_actions = UiHelper::getListActions($mediaItem);

    if ( count($this->view->list_actions) > 0)
		{
      $this->view->list_actions = UiHelper::renderBurgerList($this->view->list_actions, $mediaItem);
		}
    else
		{
      $this->view->list_actions = '';
		}

    $this->view->actions = UiHelper::getActions($mediaItem);
    //$this->view->actions = $actions;

    if (! $this->userIsAllowed)
    {
      $this->view->actions = array();
      $this->view->list_actions = '';
    }
  }

  public function loadComparer()
  {
    $this->loadView('snippets/part-comparer');
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
            'meta_key' => '_shortpixel_optimized',
            'orderby' => 'meta_value_num',
        ) );
    }
    if ( 'upload.php' == $GLOBALS['pagenow'] && isset( $_GET['shortpixel_status'] ) ) {

        $status       = sanitize_text_field($_GET['shortpixel_status']);
        $metaKey = '_shortpixel_optimized';
        //$metaCompare = $status == 0 ? 'NOT EXISTS' : ($status < 0 ? '<' : '=');

        if ($status == 'all')
          return $vars; // not for us

        switch($status)
        {
           case "opt":
            //  $status = ShortPixelMeta::FILE_STATUS_SUCCESS;
              $metaCompare = "EXISTS"; // somehow this meta stores optimization percentage.
            break;
            case "unopt":
            //  $status = ShortPixelMeta::FILE_STATUS_UNPROCESSED;
              $metaCompare = "NOT EXISTS";
            break;

        }

        $vars = array_merge( $vars, array(
            'meta_query' => array(
                array(
                    'key'     => $metaKey,
      //              'value'   => $status,
                    'compare' => $metaCompare,
                ),
            )
        ));
    }

    return $vars;
  }



  /*
  * @hook restrict_manage_posts
  */
  public function mediaAddFilterDropdown() {
      $scr = get_current_screen();
      if ( $scr->base !== 'upload' ) return;

      $status   = filter_input(INPUT_GET, 'shortpixel_status', FILTER_SANITIZE_STRING );
  //    $selected = (int)$status > 0 ? $status : 0;
    /*  $args = array(
          'show_option_none'   => 'ShortPixel',
          'name'               => 'shortpixel_status',
          'selected'           => $selected
      ); */
//        wp_dropdown_users( $args );
      $options = array(
          'all' => __('All Images', 'shortpixel-image-optimiser'),
          'opt' => __('Optimized', 'shortpixel-image-optimiser'),
          'unopt' => __('Unoptimized', 'shortpixel-image-optimiser'),
        //  'pending' => __('Pending', 'shortpixel-image-optimiser'),
        //  'error' => __('Errors', 'shortpixel-image-optimiser'),
      );

      echo "<select name='shortpixel_status' id='shortpixel_status'>\n";
      foreach($options as $optname => $optval)
      {
          $selected = ($status == $optname) ? 'selected' : '';
          echo "<option value='". $optname . "' $selected>" . $optval . "</option>\n";
      }
      echo "</select>";

  }

}
