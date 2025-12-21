<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;


use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\Optimizer\OptimizeAiController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Model\AiDataModel;
use ShortPixel\Model\Image\ImageModel as ImageModel;
use ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;


// Controller for the MediaLibraryView
class ListMediaViewController extends \ShortPixel\ViewController
{

	protected static $instance;

  protected $template = 'view-list-media';
//  protected $model = 'image';

  public function __construct()
  {
    parent::__construct();
  }

  public function load()
  {
			$fs = \wpSPIO()->filesystem();
			$fs->startTrustedMode();

      $this->loadHooks();
  }

	
  /** Hooks for the MediaLibrary View */
  protected function loadHooks()
  {
    add_filter( 'manage_media_columns', array( $this, 'headerColumns' ) );//add media library column header
    add_action( 'manage_media_custom_column', array( $this, 'doColumn' ), 10, 2 );//generate the media library column
    //Sort and filter on ShortPixel Compression column
    //add_filter( 'manage_upload_sortable_columns', array( $this, 'registerSortable') );

    add_action('restrict_manage_posts', array( $this, 'mediaAddFilterDropdown'));

    add_action('loop_end', array($this, 'loadComparer'));

  }

  public function headerColumns($defaults)
  {
    $defaults['wp-shortPixel'] = __('ShortPixel Compression', 'shortpixel-image-optimiser');


    return $defaults;
  }

  public function doColumn($column_name, $id)
  {
     if($column_name == 'wp-shortPixel')
     {
       $this->view = new \stdClass; // reset every row
       $this->view->id = $id;
       $this->loadItem($id);
       $this->loadView(null, false);
      
     }



  }

  protected function loadItem($id)
  {
     $fs = \wpSPIO()->filesystem();
     $mediaItem = $fs->getMediaImage($id);

		 // Asking for something non-existing.
	 if ($mediaItem === false)
     {
       $this->view->text = __('File Error. This could be not an image or the file is missing', 'shortpixel-image-optimiser');
		 	 return;
     }
     $this->view->mediaItem = $mediaItem;

     $actions = array();
     $list_actions = array();

     $optimizeAiController = OptimizeAiController::getInstance(); 


     if (true === $optimizeAiController->isAiEnabled())
     {
        $aiDataModel = $this->loadAiItem($id);
     }
     else
     {
        $aiDataModel = null; 
     }

    $this->view->text = UiHelper::getStatusText($mediaItem);

		$list_actions = UiHelper::getListActions($mediaItem, $aiDataModel);
    $this->view->list_actions = $list_actions;

    if ( count($this->view->list_actions) > 0)
		{
      $this->view->list_actions = UiHelper::renderBurgerList($this->view->list_actions, $mediaItem);
		}
    else
		{
      $this->view->list_actions = '';
		}

		$actions = UiHelper::getActions($mediaItem);
    $this->view->actions = $actions;

		$allActions = array_merge($list_actions, $actions);

  	$checkBoxActions = array();
    foreach($allActions as $action => $data)
    {
        if (isset($data['is-optimizable']))
        {
           $checkBoxActions[] = 'is-optimizable';
        }
    }


		if (array_key_exists('restore', $allActions))
		{
				$checkBoxActions[] = 'is-restorable';
		}

    if (array_key_exists('shortpixel-generateai', $allActions))
    {
       $checkBoxActions[] = 'ai-action'; 
    }

		$infoData  = array(); // stuff to write as data-tag.

		if ($mediaItem->isOptimized())
		{
				$compressionType = $mediaItem->getMeta('compressionType');
		}
		else {
				$compressionType = \wpSPIO()->settings()->compressionType;
		}


		$infoData['compression'] = $compressionType;

		$this->view->infoClass = implode(' ', $checkBoxActions);
		$this->view->infoData = $infoData;
    //$this->view->actions = $actions;

    if (! $this->userIsAllowed)
    {
      $this->view->actions = array();
      $this->view->list_actions = '';
    }

  }

  protected function loadAiItem($item_id)
  {
     $AiDataModel = AiDataModel::getModelByAttachment($item_id); 
     $this->view->item_id = $item_id;

     $generated_data = $AiDataModel->getGeneratedData(); 
     if ($AiDataModel->isSomeThingGenerated())
     {
        if (isset($generated_data['filebase']))
        {
           unset($generated_data['filebase']);
        }
        $generated_fields = implode(',', array_keys(array_filter($generated_data)));
        $this->view->ai_icon = 'ai'; 
        $this->view->ai_title = sprintf(__('AI-generated image SEO data: %s', 'shortpixel-image-optimiser'), $generated_fields); 

     }
     else
     {
       $this->view->ai_icon = 'no-ai'; 
       $this->view->ai_title = __('No AI-generated SEO data for this image', 'shortpixel-image-optimiser'); 

     }

     return $AiDataModel;


  }

  public function loadComparer()
  {
    $this->loadView('snippets/part-comparer');
  }

  /*
  * @hook restrict_manage_posts
  */
  public function mediaAddFilterDropdown() {
      $scr = get_current_screen();
      if ( $scr->base !== 'upload' ) return;

      $status   = filter_input(INPUT_GET, 'shortpixel_status', FILTER_UNSAFE_RAW );

      $options = array(
          'all' => __('Any ShortPixel State', 'shortpixel-image-optimiser'),
          'optimized' => __('Optimized', 'shortpixel-image-optimiser'),
          'unoptimized' => __('Unoptimized', 'shortpixel-image-optimiser'),
					'prevented' => __('Optimization Error', 'shortpixer-image-optimiser'),
      );

      echo  "<select name='shortpixel_status' id='shortpixel_status'>\n";
      foreach($options as $optname => $optval)
      {
          $selected = ($status == $optname) ? esc_attr('selected') : '';
          echo "<option value='". esc_attr($optname) . "' $selected >" . esc_html($optval) . "</option>\n";
      }
      echo "</select>";

  }

}
