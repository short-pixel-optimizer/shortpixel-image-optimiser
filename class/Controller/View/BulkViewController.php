<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\QuotaController as QuotaController;
use Shortpixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;

class BulkViewController extends \ShortPixel\Controller
{

  protected $form_action = 'sp-bulk';
  protected $template = 'view-bulk';

  protected $quotaData;
  protected $pendingMeta;
  protected $selected_folders = array();


  public function load()
  {
    $quota = QuotaController::getInstance();
    $optimizeController = new OptimizeController();
    $bulkController = BulkController::getInstance();

    $this->view->quotaData = $quota->getQuota();

    $this->view->stats = $optimizeController->getStartupData();

    $this->view->logs = $bulkController->getLogs();

    $this->loadView();

  }




} // class
