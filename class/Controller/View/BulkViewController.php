<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;
use Shortpixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Controller\StatsController as StatsController;
use ShortPixel\Helper\UiHelper as UiHelper;

class BulkViewController extends \ShortPixel\ViewController
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
    $this->view->approx = $this->getApproxData();

    $this->view->logHeaders = array(__('Processed', 'shortpixel_image_optimiser'), __('Errors', 'shortpixel_image_optimizer'), __('Date', 'shortpixel_image_optimizer'));
    $this->view->logs = $this->getLogs();

    $keyControl = ApiKeyController::getInstance();

    $this->view->error = false;

    if ( ! $keyControl->keyIsVerified() )
    {
        $adminNoticesController = AdminNoticesController::getInstance();

        $this->view->error = true;
        $this->view->errorTitle = __('Missing API Key', 'shortpixel_image_optimiser');
        $this->view->errorContent = $adminNoticesController->getActivationNotice();
        $this->view->showError = 'key';
    }
    elseif ( ! $quota->hasQuota())
    {
        $this->view->error = true;
        $this->view->errorTitle = __('Quota Exceeded','shortpixel-image-optimiser');
        $this->view->errorContent = __('Can\'t start the Bulk Process due to lack of credits.', 'shortpixel-image-optimiser');
        $this->view->errorText = __('Please check or add quota and refresh the page', 'shortpixel-image-optimiser');
        $this->view->showError = 'quota';

    }

    if ($this->view->error)
      $this->unload();


    $this->loadView();

  }

  protected function getApproxData()
  {
    $approx = new \stdClass; // guesses on basis of the statsController SQL.
    $approx->media = new \stdClass;
    $approx->custom = new \stdClass;
    $approx->total = new \stdClass;

    $sc = StatsController::getInstance();
    $sc->reset(); // Get a fresh stat.

    $excludeSizes = \wpSPIO()->settings()->excludeSizes;


    $approx->media->items = $sc->find('media', 'itemsTotal') - $sc->find('media', 'items');

    // ThumbsTotal - Approx thumbs in installation - Approx optimized thumbs (same query)
    $approx->media->thumbs = $sc->find('media', 'thumbsTotal') - $sc->find('media', 'thumbs');

    // If sizes are excluded, remove this count from the approx.
    if (is_array($excludeSizes) && count($excludeSizes) > 0)
      $approx->media->thumbs = $approx->media->thumbs - ($approx->media->items * count($excludeSizes));

    // Total optimized items + Total optimized (approx) thumbnails
    $approx->media->total = $approx->media->items + $approx->media->thumbs;


    $approx->custom->images = $sc->find('custom', 'itemsTotal') - $sc->find('custom', 'items');

    $approx->total->images = $approx->media->total + $approx->custom->images; // $sc->totalImagesToOptimize();
    return $approx;

  }

  private function unload()
  {
    //wp_dequeue_script('shortpixel-screen-bulk');
  //   wp_dequeue_script('shortpixel-bulk');
    // wp_dequeue_script('shortpixel');
    // wp_dequeue_script('shortpixel-processor');


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
					{
            $errors = '<a data-action="OpenLog" data-file="' . $logFile->getFileName() . '" href="' . $fs->pathToUrl($logFile) . '">' . $errors . '</a>';
					}

          $view[$logData['date']] = array('type' => $logData['type'], 'images' => $logData['processed'], 'errors' => $errors, 'date' => UiHelper::formatTS($logData['date']) );

      }

      krsort($view);

      return $view;
  }




} // class
