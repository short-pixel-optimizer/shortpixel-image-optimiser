<?php
namespace ShortPixel\Controller\View;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use \ShortPixel\Controller\AdminNoticesController as AdminNoticesController;


class BulkViewController extends \ShortPixel\Controller
{

  protected $form_action = 'sp-bulk';

  protected $quotaData;
  protected $pendingMeta;
protected $selected_folders = array();

  public function load()
  {
    $this->checkPost();
    if ($this->is_form_submit)
    {
      $this->doBulkAction();
    }

    $this->quotaData = \wpSPIO()->getShortPixel()->checkQuotaAndAlert(null, isset($_GET['checkquota']), 0);

    if ($this->checkDoingBulk())
    {
        $this->loadViewProgress();
    }
    else
    {
      $this->loadView();
    }

  }

  public function checkDoingBulk()
  {
    $prioQ = \wpSPIO()->getShortPixel()->getPrioQ();
    $settings = \wpSPIO()->settings();
    $spMetaDao = \wpSPIO()->getShortPixel()->getSPMetaDao();

    global $wpdb;

    $qry_left = "SELECT count(meta_id) FilesLeftToBeProcessed FROM " . $wpdb->prefix . "postmeta
    WHERE meta_key = '_wp_attached_file' AND post_id <= " . (0 + $prioQ->getStartBulkId());
    $filesLeft = $wpdb->get_results($qry_left);

    //check the custom bulk
    $pendingMeta = $settings->hasCustomFolders ? $spMetaDao->getPendingMetaCount() : 0;

    $this->pendingMeta = $pendingMeta;


    return ( ($filesLeft[0]->FilesLeftToBeProcessed > 0 && $prioQ->bulkRunning())
          || (0 + $pendingMeta > 0 && !$settings->customBulkPaused && $prioQ->bulkRan() )//bulk processing was started
              && (!$prioQ->bulkPaused() || $settings->skipToCustom));
  }

  public function doBulkAction()
  {
      $spMetaDao = \wpSPIO()->getShortPixel()->getSPMetaDao();
      $prioQ = \wpSPIO()->getShortPixel()->getPrioQ();
      $settings = \wpSPIO()->settings();

      if(isset($_POST['bulkProcessPause']))
      {//pause an ongoing bulk processing, it might be needed sometimes
          $prioQ->pauseBulk();
          if($settings->hasCustomFolders && $spMetaDao->getPendingMetaCount()) {
              $settings->customBulkPaused = 1;
          }
      }

      if(isset($_POST['bulkProcessStop']))
      {//stop an ongoing bulk processing
          $prioQ->stopBulk();
          if($settings->hasCustomFolders && $spMetaDao->getPendingMetaCount()) {
              $settings->customBulkPaused = 1;
          }
          $settings->cancelPointer = NULL;
      }

      if(isset($_POST["bulkProcess"]))
      {
          //set the thumbnails option
          if ( isset($_POST['thumbnails']) ) {
              $settings->processThumbnails = 1;
          } else {
              $settings->processThumbnails = 0;
          }

          if (isset($_POST['createWebp']) )
            $settings->createWebp = 1;
          else
            $settings->createWebp = 0;

          if (isset($_POST['createAvif']))
            $settings->createAvif = 1;
          else
            $settings->createAvif = 0;

          //clean the custom files errors in order to process them again
          if($settings->hasCustomFolders) {
              $spMetaDao->resetFailed();
              $spMetaDao->resetRestored();
              $spMetaDao->setPending();
          }

          $prioQ->startBulk(\ShortPixelQueue::BULK_TYPE_OPTIMIZE);
          $settings->customBulkPaused = 0;
          Log::addInfo("BULK:  Start:  " . $prioQ->getStartBulkId() . ", stop: " . $prioQ->getStopBulkId() . " PrioQ: "
               .json_encode($prioQ->get()));
      }//end bulk process  was clicked

      if(isset($_POST["bulkRestore"]))
      {
          Log::addInfo('Bulk Process - Bulk Restore');
          $bulkRestore = new BulkRestoreAll(); // controller
          $bulkRestore->setupBulk();

          $prioQ->startBulk(\ShortPixelQueue::BULK_TYPE_RESTORE);
          $settings->customBulkPaused = 0;
      }//end bulk restore  was clicked

      if(isset($_POST["bulkCleanup"]))
      {
          Log::addInfo('Bulk Process - Bulk Cleanup ');
          $prioQ->startBulk(\ShortPixelQueue::BULK_TYPE_CLEANUP);
          $settings->customBulkPaused = 0;
      }//end bulk restore  was clicked

      if(isset($_POST["bulkCleanupPending"]))
      {
          Log::addInfo('Bulk Process - Clean Pending');
          $prioQ->startBulk(\ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING);
          $settings->customBulkPaused = 0;
      }//end bulk restore  was clicked

      if(isset($_POST["bulkProcessResume"]))
      {
          Log::addInfo('Bulk Process - Bulk Resume');
          $prioQ->resumeBulk();
          $settings->customBulkPaused = 0;
      }//resume was clicked

      if(isset($_POST["skipToCustom"]))
      {
          Log::addInfo('Bulk Process - Skipping to Custom Media Process');
          $settings->skipToCustom = true;
          $settings->customBulkPaused = 0;

      }//resume was clicked

  }

  public function loadView($template = null)
  {
    $settings = \wpSPIO()->settings();
    $prioQ = \wpSPIO()->getShortPixel()->getPrioQ();

    $averageCompression = \wpSPIO()->getShortPixel()->getAverageCompression();
    $thumbsProcessedCount = $settings->thumbsCount;//amount of optimized thumbnails
    $under5PercentCount =  $settings->under5Percent;//amount of under 5% optimized imgs.
    $quotaData = $this->quotaData;
    $percent = $prioQ->bulkPaused() ? \wpSPIO()->getShortPixel()->getPercent($quotaData) : false;

    $view = new \ShortPixelView(\wpSPIO()->getShortPixel());
    $view->displayBulkProcessingForm($quotaData, $thumbsProcessedCount, $under5PercentCount,
          $prioQ->bulkRan(), $averageCompression, $settings->fileCount,
          \ShortPixelTools::formatBytes($settings->savedSpace), $percent, $this->pendingMeta);
  }

  public function loadViewProgress()
  {
    $settings = \wpSPIO()->settings();
    $prioQ = \wpSPIO()->getShortPixel()->getPrioQ();

    if($settings->quotaExceeded == 1) {
        AdminNoticesController::reInstateQuotaExceeded();
        $this->loadView();
        return false;
    }

    if( $settings->verifiedKey == false ) {//invalid API Key
        return;
    }

    $quotaData = $this->quotaData;
    $prioQ = \wpSPIO()->getShortPixel()->getPrioQ();

    //average compression
    $averageCompression = \wpSPIO()->getShortPixel()->getAverageCompression();

    $msg = \wpSPIO()->getShortPixel()->bulkProgressMessage($prioQ->getDeltaBulkPercent(), $prioQ->getTimeRemaining());

    $view = new \ShortPixelView(\wpSPIO()->getShortPixel());
    $view->displayBulkProcessingRunning(\wpSPIO()->getShortPixel()->getPercent($quotaData), $msg, $quotaData['APICallsRemaining'], $averageCompression,
             $prioQ->getBulkType() == \ShortPixelQueue::BULK_TYPE_RESTORE ? 0 :
            (   $prioQ->getBulkType() == \ShortPixelQueue::BULK_TYPE_CLEANUP
             || $prioQ->getBulkType() == \ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING ? -1 : ($this->pendingMeta !== null ? ($prioQ->bulkRunning() ? 3 : 2) : 1)), $quotaData);

  }

} // class
