<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;


/**
 * View controller for the "Scan for new files" sub-page within Custom Media.
 *
 * Renders the `view-other-media-scan` template, which provides a UI for
 * triggering a re-scan of all active monitored folders to discover newly
 * added files. The scan itself is driven via AJAX calls; this controller
 * only prepares the initial page view.
 *
 * Wired up by AdminController as part of the Custom Media page group.
 *
 * @package ShortPixel\Controller\View
 */
class OtherMediaScanViewController extends \ShortPixel\ViewController
{

  protected $template = 'view-other-media-scan';

  protected static $instance;

  /** @var array<int, mixed>|null All registered folder objects, cached for potential reuse. */
  protected static $allFolders;

  /** @var \ShortPixel\Controller\OtherMediaController OtherMediaController singleton. */
  private $controller;

  /**
   * Initialises the OtherMediaController singleton for use in load().
   */
  public function __construct()
  {
    parent::__construct();

    $this->controller = OtherMediaController::getInstance();
  }

  /**
   * Populates view data for the scan page and renders the template.
   *
   * Sets view->title, view->pagination (false — no pagination on scan page),
   * view->show_search (false), view->has_filters (false), and view->totalFolders
   * (count of active monitored directory IDs). Then includes the
   * `view-other-media-scan` template via loadView().
   *
   * @return void
   */
  public function load()
  {

      $this->view->title = __('Scan for new files', 'shortpixel-image-optimiser');
      $this->view->pagination = false;

      $this->view->show_search = false;
      $this->view->has_filters = false;

			$this->view->totalFolders = count($this->controller->getActiveDirectoryIDS());

      $this->loadView();
  }
} // class
