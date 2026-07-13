<?php
namespace ShortPixel;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Helper\UiHelper as UiHelper;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;

/**
 * View / rendering side of the NextGen Gallery integration.
 *
 * NextGenController (in the same folder) wires hooks and DB queries;
 * this class owns the per-row rendering that NextGen's image-manager
 * table needs. Two rendering concerns:
 *
 *   1. **The "ShortPixel Compression" column** on NextGen's gallery
 *      image list — `nggColumns` / `nggCountColumns` / `nggColumnHeader`
 *      answer NextGen's `ngg_manage_images_column_*` filter family so
 *      an extra column appears. Actual per-row content is rendered by
 *      `NextGenController::loadNextGenItem()`, which delegates to
 *      `loadItem()` below.
 *   2. **The optimize/compare popup UI** — `loadComparer()` echoes the
 *      shared `snippets/part-comparer` partial so the before/after
 *      slider works on NextGen screens the same way it does on the
 *      Media Library.
 *
 * The controller extends `ShortPixel\ViewController`, so it inherits
 * `loadView()`, the `$view` render-model container, and the
 * `$userIsAllowed` capability gate.
 *
 * @package ShortPixel
 */
class NextGenViewController extends \ShortPixel\ViewController
{
  /**
   * Column index assigned to SPIO's column in the NextGen image list.
   *
   * NextGen's column filters are keyed by ordinal
   * (`ngg_manage_images_column_7_content`), so we record where NextGen
   * placed us during `nggColumns()`. Static because NextGen calls the
   * column filters as static-style callbacks.
   *
   * @var int
   */
  protected static $nggColumnIndex = 0;

  /**
   * Template slug rendered by `loadItem()` for each row of the NextGen
   * image list. Matches the media-library list template so the UI stays
   * consistent between screens.
   *
   * @var string
   */
  protected $template = 'view-list-media';

  /**
   * ViewController hook contract — intentionally empty.
   *
   * The parent `ViewController::__construct` invokes `hooks()` on
   * every instance; NextGen's hook wiring lives in NextGenController
   * (which owns the `add_filter('ngg_manage_images_columns', ...)`
   * calls), so this override just no-ops rather than let the base
   * class default run.
   *
   * @return void
   */
   protected function hooks()
   {

   }

  /**
   * `ngg_manage_images_columns` filter — record the index NextGen
   * assigns to SPIO's column so `nggColumnHeader()` can pick it up.
   *
   * The commented-out block below is the original column-registration
   * shape (adding a `wp-shortPixelNgg` entry to `$defaults`). The
   * current wiring registers the column at the fixed index 7 via
   * NextGenController — this method mostly just counts now.
   *
   * @param array<string, string> $defaults Column-name → label map from NextGen.
   * @return array<string, string> Unmodified `$defaults`.
   */
   public function nggColumns( $defaults ) {
       self::$nggColumnIndex = count($defaults) + 1;
  /*     add_filter( 'ngg_manage_images_column_' . self::$nggColumnIndex . '_header', array( '\ShortPixel\nextGenViewController', 'nggColumnHeader' ) );
       add_filter( 'ngg_manage_images_column_' . self::$nggColumnIndex . '_content', array( '\ShortPixel\nextGenViewController', 'nggColumnContent' ), 10, 2 );
       $defaults['wp-shortPixelNgg'] = 'ShortPixel Compression'; */
       return $defaults;
   }

  /**
   * `ngg_manage_images_number_of_columns` filter — bump NextGen's
   * total column count by one to account for SPIO's added column.
   *
   * @param int $count Column count so far.
   * @return int `$count + 1`.
   */
   public function nggCountColumns( $count ) {
       return $count + 1;
   }

  /**
   * `ngg_manage_images_column_7_header` filter — provide the header
   * label for SPIO's column, and enqueue dashicons so the per-row
   * status badges can use icon glyphs.
   *
   * @param string $default Default header label from NextGen (ignored).
   * @return string Localised "ShortPixel Compression" label.
   */
   public function nggColumnHeader( $default ) {

		 	 wp_enqueue_style('dashicons');


       return __('ShortPixel Compression','shortpixel-image-optimiser');
   }

  /**
   * `admin_footer` action — echo the before/after comparer snippet
   * so the compare-popup markup exists on NextGen screens.
   *
   * Registered on-demand by `NextGenController::checkCurrentScreen()`
   * when it detects the current screen is a NextGen one — that way
   * the markup only ships on screens where it's actually needed.
   *
   * @return void
   */
	 public function loadComparer()
	 {
		  $this->loadView('snippets/part-comparer');

	 }

  /**
   * Render a single NextGen image row using the shared media-list view.
   *
   * Called once per row by `NextGenController::loadNextGenItem()`.
   * Resets `$this->view` on entry so state from a previous row doesn't
   * leak. Fetches the SPIO `CustomImageModel` for the image path,
   * populates the render model with status text and per-row actions
   * (both the burger-menu variant and the inline variant), and asks
   * the parent to render `$template`.
   *
   * The `$this->userIsAllowed` capability gate (inherited from
   * `ViewController`) hides all actions when the current user lacks
   * the plugin's edit permission — the row still renders, just as
   * a read-only status display.
   *
   * @param object $nextGenObj NextGen image object; must expose an `imagePath` property pointing at the local file.
   * @return void
   */
   public function loadItem( $nextGenObj ) {

       $this->view = new \stdClass; // reset every row

       $otherMediaController = OtherMediaController::getInstance();
       $mediaItem = $otherMediaController->getCustomImageByPath($nextGenObj->imagePath);

       $this->view->mediaItem = $mediaItem;
       $this->view->id = $mediaItem->get('id');
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

       $this->loadView($this->template, false);
   }



} // class
