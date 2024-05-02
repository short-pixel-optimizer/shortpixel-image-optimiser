<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;


use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Notices\NoticeController as Notice;
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

			$this->checkAction(); // bulk action checkboxes, y'all
      $this->loadHooks();
  }

	/** Check if a bulk action (checkboxes) was requested
	*/
	protected function checkAction()
	{
	   $wp_list_table = _get_list_table('WP_Media_List_Table');
     $action = $wp_list_table->current_action();

		 if (! $action)
		 		return;

		if(strpos($action, 'shortpixel') === 0 ) {
		 		check_admin_referer('bulk-media');
		}

    // Nothing selected, nothing doin'
    if (! isset($_GET['media']) || ! is_array($_GET['media']))
      return;

		// In general this code superceded by javascript handling if that works properly. @todo remove if so
		return;

		 $fs = \wpSPIO()->filesystem();
		 $optimizeController = new OptimizeController();
		 $items = array_filter($_GET['media'], 'intval');

		 $numItems = count($items);
	   $plugin_action = str_replace('shortpixel-', '', $action);

		 $targetCompressionType = $targetCrop = null;

		 switch ($plugin_action)
		 {
			  case "glossy":
					 $targetCompressionType = ImageModel::COMPRESSION_GLOSSY;
				break;
				case "lossy":
					 $targetCompressionType = ImageModel::COMPRESSION_LOSSY;
				break;
				case "lossless":
					  $targetCompressionType = ImageModel::COMPRESSION_LOSSLESS;
				break;
				case 'smartcrop':
						$targetCrop = ImageModel::ACTION_SMARTCROP;
				break;
				case 'smartcropless':
						$targetCrop = ImageModel::ACTION_SMARTCROPLESS;
				break;
		 }

		 foreach($items as $item_id)
		 {
			 	 $mediaItem = $fs->getMediaImage($item_id);

			   switch($plugin_action)
				 {
					 	case 'optimize':
							 if ($mediaItem->isProcessable())
							 	$res = $optimizeController->addItemToQueue($mediaItem);
						break;
						case 'smartcrop':
						case 'smartcropless':
								if ($mediaItem->isOptimized())
								{
										$targetCompressionType = $mediaItem->getMeta('compressionType');
								}
								else {
									$targetCompressionType = \wpSPIO()->settings()->compressionType;
								}
						case 'glossy':
						case 'lossy':
						case 'lossless':

								if ($mediaItem->isOptimized() && $mediaItem->getMeta('compressionType') == $targetCompressionType && is_null($targetCrop)  )
								{
									// do nothing if already done w/ this compression.
								}
								elseif(! $mediaItem->isOptimized())
								{
									$mediaItem->setMeta('compressionType', $targetCompressionType);
									if (! is_null($targetCrop))
									{
										 $mediaItem->doSetting('smartcrop', $targetCrop);
									}
									$res = $optimizeController->addItemToQueue($mediaItem);
								}
								else
								{
									$args = array();
									if (! is_null($targetCrop))
									{
										 $args = array('smartcrop' => $targetCrop);
									}

							 		$res = $optimizeController->reOptimizeItem($mediaItem, $targetCompressionType, $args);
								}
						break;
						case 'restore';
								if ($mediaItem->isOptimized())
									$res = $optimizeController->restoreItem($mediaItem);
						break;
            case 'mark-completed':
                 if ($mediaItem->isProcessable())
                 {
                   $mediaItem->markCompleted(__('This item has been manually marked as completed', 'shortpixel-image-optimiser'), ImageModel::FILE_STATUS_MARKED_DONE);
                 }
            break;
				 }

		 }

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

  public function loadItem($id)
  {
     $fs = \wpSPIO()->filesystem();
     $mediaItem = $fs->getMediaImage($id);
     $keyControl = ApiKeyController::getInstance();
     $quotaControl = QuotaController::getInstance();

		 // Asking for something non-existing.
		 if ($mediaItem === false)
     {
       $this->view->text = __('File Error. This could be not an image or the file is missing', 'shortpixel-image-optimiser');
		 	 return;
     }
     $this->view->mediaItem = $mediaItem;

     $actions = array();
     $list_actions = array();

    $this->view->text = UiHelper::getStatusText($mediaItem);

		$list_actions = UiHelper::getListActions($mediaItem);
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
		if (array_key_exists('optimize', $allActions))
		{
				$checkBoxActions[] = 'is-optimizable';
		}
		if (array_key_exists('restore', $allActions))
		{
				$checkBoxActions[] = 'is-restorable';
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
