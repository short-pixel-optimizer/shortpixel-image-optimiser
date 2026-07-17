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


/**
 * View controller for the WordPress Media Library list view.
 *
 * Injects a 'ShortPixel Compression' column into the Media Library table
 * (upload.php list mode) by hooking into `manage_media_columns` and
 * `manage_media_custom_column`. Each cell is rendered using the `view-list-media`
 * template, which shows compression status, action buttons, AI data indicators,
 * and a burger-menu of secondary actions.
 *
 * Also adds a filter dropdown for filtering by ShortPixel compression state
 * (`restrict_manage_posts`) and appends the before/after image comparer widget
 * to the page via `loop_end`.
 *
 * Wired up by AdminController.
 *
 * @package ShortPixel\Controller\View
 */
class ListMediaViewController extends \ShortPixel\ViewController
{

	protected static $instance;

  protected $template = 'view-list-media';

  /**
   * Default controller action: enables trusted filesystem mode and registers Media Library hooks.
   *
   * Starts trusted mode so attachment files can be accessed during column rendering,
   * then calls loadHooks() to attach all filter and action callbacks.
   *
   * @return void
   */
  public function load()
  {
			$fs = \wpSPIO()->filesystem();
			$fs->startTrustedMode();

      $this->loadHooks();
  }

	
  /**
   * Registers all WordPress hooks for the Media Library column integration.
   *
   * Hooks registered:
   *   - `manage_media_columns`       → headerColumns() (adds column header).
   *   - `manage_media_custom_column` → doColumn() (renders each cell).
   *   - `restrict_manage_posts`      → mediaAddFilterDropdown() (adds filter dropdown).
   *   - `loop_end`                   → loadComparer() (appends comparer widget).
   *
   * @return void
   */
  protected function loadHooks()
  {
    add_filter( 'manage_media_columns', array( $this, 'headerColumns' ) );//add media library column header
    add_action( 'manage_media_custom_column', array( $this, 'doColumn' ), 10, 2 );//generate the media library column
    //Sort and filter on ShortPixel Compression column
    //add_filter( 'manage_upload_sortable_columns', array( $this, 'registerSortable') );

    add_action('restrict_manage_posts', array( $this, 'mediaAddFilterDropdown'));

    add_action('loop_end', array($this, 'loadComparer'));

  }

  /**
   * Adds the 'ShortPixel Compression' column to the Media Library list table.
   *
   * Filter callback for `manage_media_columns`. Appends the column to the $defaults
   * array provided by WordPress and returns the modified array.
   *
   * @param array<string, string> $defaults Existing column definitions.
   * @return array<string, string> Modified column definitions with the ShortPixel column added.
   */
  public function headerColumns($defaults)
  {
    $defaults['wp-shortPixel'] = __('ShortPixel Compression', 'shortpixel-image-optimiser');


    return $defaults;
  }

  /**
   * Renders the ShortPixel column cell for a single media library row.
   *
   * Action callback for `manage_media_custom_column`. Resets $this->view to a
   * fresh stdClass for each row (preventing carry-over between rows), then calls
   * loadItem() to populate view data before including the template (unique=false
   * so the template is re-included for every row).
   *
   * @param string $column_name The name of the current column being rendered.
   * @param int    $id          The attachment post ID.
   * @return void
   */
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

  /**
   * Populates $this->view with data for a single media library attachment.
   *
   * Loads the MediaLibraryModel for $id. When not found (not an image or file
   * missing), sets view->text to an error string and returns. Otherwise loads AI
   * data if AI is enabled, sets view->text (status text), view->list_actions
   * (burger-menu HTML), view->actions, view->infoClass (space-separated capability
   * flags for JS), and view->infoData (compression type). Actions and burger-menu
   * are suppressed when the current user does not have the ShortPixel capability.
   *
   * @param int $id WordPress attachment post ID.
   * @return void
   */
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

  /**
   * Loads AI data for an attachment and populates AI-related view properties.
   *
   * Sets view->item_id, view->ai_icon ('ai' or 'no-ai'), and view->ai_title
   * describing which AI-generated fields are present. Returns the AiDataModel
   * for further use by the template or loadItem().
   *
   * @param int $item_id WordPress attachment post ID.
   * @return \ShortPixel\Model\AiDataModel The loaded AI data model.
   */
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

  /**
   * Appends the before/after image comparer widget to the Media Library page.
   *
   * Callback for the `loop_end` action. Includes the `snippets/part-comparer`
   * template once (unique dedup is the default).
   *
   * @return void
   */
  public function loadComparer()
  {
    $this->loadView('snippets/part-comparer');
  }

  /**
   * Outputs the ShortPixel status filter <select> element in the Media Library toolbar.
   *
   * Callback for the `restrict_manage_posts` action. Returns immediately when
   * not on the upload screen. Options: 'all', 'optimized', 'unoptimized', 'prevented'.
   * The current selection is read from INPUT_GET 'shortpixel_status'.
   *
   * @return void
   */
  public function mediaAddFilterDropdown() {
      $scr = get_current_screen();
      if ( $scr->base !== 'upload' ) return;

      $status   = filter_input(INPUT_GET, 'shortpixel_status', FILTER_UNSAFE_RAW );

      $options = array(
          'all' => __('Any ShortPixel State', 'shortpixel-image-optimiser'),
          'optimized' => __('Optimized', 'shortpixel-image-optimiser'),
          'unoptimized' => __('Unoptimized', 'shortpixel-image-optimiser'),
					'prevented' => __('Optimization Error', 'shortpixel-image-optimiser'),
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
