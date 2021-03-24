<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\QuotaController as QuotaController;
use Shortpixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Helper\UiHelper as UiHelper;

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


    $this->view->quotaData = $quota->getQuota();

    $this->view->stats = $optimizeController->getStartupData();

    $this->view->logHeaders = array(__('Processed', 'shortpixel_image_optimizer'), __('Errors', 'shortpixel_image_optimizer'), __('Date', 'shortpixel_image_optimizer'));
    $this->view->logs = $this->getLogs();

    $this->loadView();

  }

  public function getLogs()
  {
      $bulkController = BulkController::getInstance();
      $logs = $bulkController->getLogs();
      $fs = \wpSPIO()->filesystem();
      $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

      $view = array();

      foreach($logs as $logData)
      {
          $logFile = $fs->getFile($backupDir->getPath() . 'bulk_' . $logData['date'] . '.log');
          $errors = $logData['fatal_errors'];

          if ($logFile->exists())
            $errors = '<a href="' . $fs->pathToUrl($logFile) . '">' . $errors . '</a>';

          $view[] = array($logData['processed'], $errors,  UiHelper::formatTS($logData['date']));

      }

      return $view;
  }




} // class
