<?php
namespace ShortPixel\Controller\View;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Controller\AdminNoticesController as AdminNoticesController;
use ShortPixel\Controller\ApiKeyController as ApiKeyController;
use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Controller\BulkController as BulkController;
use ShortPixel\Controller\StatsController as StatsController;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;
use ShortPixel\Helper\UiHelper as UiHelper;

use ShortPixel\Model\AccessModel as AccessModel;


/**
 * View controller for the Bulk Optimization admin screen.
 *
 * Renders the bulk processing page (upload.php?page=wp-short-pixel-bulk) using
 * the `view-bulk` template. Prepares quota status, approximate unoptimized image
 * counts, past bulk-run logs, custom bulk operation labels, and dashboard offer
 * banners for the template.
 *
 * Wired up by AdminController on the `admin_menu` hook.
 *
 * @package ShortPixel\Controller\View
 */
class BulkViewController extends \ShortPixel\ViewController
{

  /** @var string Nonce action name for the bulk form. */
  protected $form_action = 'sp-bulk';
  /** @var string Template file name (without .php) for the bulk page. */
  protected $template = 'view-bulk';

  /** @var object|null Quota data object from QuotaController. */
  protected $quotaData;
  /** @var mixed|null Reserved for future use. */
  protected $pendingMeta;
  /** @var array<int, mixed> Reserved for future folder selection support. */
  protected $selected_folders = array();

	protected static $instance;


  /**
   * Default action: populates view data and renders the bulk processing page.
   *
   * Loads quota status, queue startup data, approximate unoptimized image counts,
   * past bulk-run logs, error/quota notices, buy-more link, custom operation labels
   * (from the panel GET arg or an active bulk operation), a remote offer banner,
   * and the dashboard promo block. Renders the `view-bulk` template.
   *
   * @return void
   */
  public function load()
  {
    $quota = QuotaController::getInstance();
    $queueController = new QueueController();
    $bulkController = BulkController::getInstance();

    $this->view->quotaData = $quota->getQuota();

    $this->view->stats = $queueController->getStartupData();
    $this->view->approx = $this->getApproxData();

    $this->view->logHeaders = array(__('Images', 'shortpixel-image-optimiser'), __('Errors', 'shortpixel_image_optimizer'), __('Date', 'shortpixel_image_optimizer'), '');
    $this->view->logs = $this->getLogs();

    $keyControl = ApiKeyController::getInstance();

    $this->view->error = false;

    if ( ! $keyControl->keyIsVerified() )
    {
        $this->view->error = true;
        $this->view->errorTitle = __('Missing API Key', 'shortpixel-image-optimiser');
        $this->view->errorContent = $this->getActivationNotice();
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

		$this->view->mediaErrorLog = $this->loadCurrentLog('media');
		$this->view->customErrorLog = $this->loadCurrentLog('custom');

		$this->view->buyMoreHref = 'https://shortpixel.com/' . ($keyControl->getKeyForDisplay() ? 'login/' . $keyControl->getKeyForDisplay() . '/spio-unlimited' : 'pricing');


    $custom_operation_media = $bulkController->getCustomOperation('media');
    $custom_operation_custom = $bulkController->getCustomOperation('custom');

    $custom_operation_media = (false === $custom_operation_media) ? $this->checkBulkViaPanelArg() : $custom_operation_media; 
    $custom_operation_custom = (false === $custom_operation_custom) ? $this->checkBulkViaPanelArg() : $custom_operation_custom;

    $this->view->customOperationMedia = (false !== $custom_operation_media) ? $this->getCustomLabel($custom_operation_media) : false;
    $this->view->customOperationCustom = (false !== $custom_operation_custom) ? $this->getCustomLabel($custom_operation_custom) : false;
    // Not in use : 
    //$this->view->customOperationMediaName = $custom_operation_media; 
    //$this->view->customerOperationCustomName = $custom_operation_custom;
    

    $noticesController = AdminNoticesController::getInstance(); 

    $this->view->remoteOffer = $noticesController->getRemoteOffer(); 

    $this->loadDashboard();

    $this->loadView();

  }

  /**
   * Populates $this->view with dashboard icon, link, title, and message.
   *
   * Defaults to the ShortPixel plugin icon with no link or title. When a remote
   * promotional offer is available from AdminNoticesController, overrides all four
   * fields with offer data.
   *
   * @return void
   */
  private function loadDashboard()
  {
      $noticesController = AdminNoticesController::getInstance();
      $offer = $noticesController->getRemoteOffer(); 

          $this->view->dashboard_icon = plugins_url('res/images/icon/shortpixel.svg', SHORTPIXEL_PLUGIN_FILE); 
          $this->view->dashboard_link = false; 
          $this->view->dashboard_title = false; 
          $this->view->dashboard_message = ''; 
      if (is_array($offer))
      {
         $this->view->dashboard_icon = $offer['icon']; 
         $this->view->dashboard_link = $offer['link']; 
         $this->view->dashboard_title = $offer['title'];
         $this->view->dashboard_message = $offer['message'];

      } 
  }

  /**
   * Returns a human-readable label for a custom bulk operation identifier.
   *
   * Recognised identifiers: 'bulk-restore', 'migrate', 'removeLegacy', 'bulk-undoAI'.
   * Note: no default case is defined — an unrecognised identifier will return an
   * uninitialised $label variable (see Suspected bugs in report).
   *
   * @param string $operation Internal bulk operation identifier.
   * @return string Translated label for the operation.
   */
  private function getCustomLabel($operation)
  {
      switch($operation)
      {
          case 'bulk-restore':
            $label = __('Bulk Restore', 'shortpixel-image-optimiser');
          break;
          case 'migrate':
            $label = __('Bulk Migrate Optimization Data', 'shortpixel-image-optimiser');
          break;
          case 'removeLegacy':
            $label = __('Bulk Remove Legacy Data', 'shortpixel-image-optimiser');
          break;
          case 'bulk-undoAI':
            $label = __('Bulk Remove AI Data', 'shortpixel-image-optimiser');           
          break; 
          case 'redoAiReplacement': 
            $label = __('Bulk Redo AI Replacement', 'shortpixel-image-optimiser');                     
          break; 
      }

      return $label;
  }
  
  /** This function has no other purpose than the map the Panel get argument to the proper bulk action. Reason this exists is because at the time the bulk screen is loaded, the bulk hasn't started, thus the specialOPeration is not in place, not showing the text in process / finished
   * @todo Harmonize the panel name, bulk action name etc so this function is not needed to display string
   * @return false|string 
   */
  private function checkBulkViaPanelArg()
  {
      $panel = isset($_GET['panel']) ? sanitize_text_field($_GET['panel']) : null;

      if (is_null($panel))
      {
         return false; 
      }

      $action = false; 

      switch($panel)
      {
         case 'bulk-migrate': 
            $action = 'migrate'; 
         break;
         case 'bulk-restore':
            $action = 'bulk-restore'; 
         break; 
         case 'bulk-restoreAI':
            $action = 'bulk-undoAI';
         break; 
         case 'bulk-removeLegacy': 
            $action = 'removeLegacy'; 
         break; 
         case 'bulk-redoAiReplacement':
            $action = 'redoAiReplacement';
         break; 
      }

      return $action;

  }

  /**
   * Returns the HTML message shown when no API key is set on the bulk page.
   *
   * Directs the user to the settings page to validate their key or sign up.
   * Duplicates similar logic in the ApiNotice admin notice class.
   *
   * @return string HTML message string (not escaped — contains intentional anchor tags).
   */
	protected function getActivationNotice()
	{
		$message = "<p>" . __('In order to start the optimization process, you need to validate your API Key in the '
						. '<a href="options-general.php?page=wp-shortpixel-settings">ShortPixel Settings</a> page in your WordPress Admin.','shortpixel-image-optimiser') . "
		</p>
		<p>" .  __('If you don’t have an API Key, just fill out the form and a key will be created.','shortpixel-image-optimiser') . "</p>";
		return $message;
	}

  /**
   * Calculates approximate counts of unoptimized media and custom images.
   *
   * Uses StatsController to compute total-minus-optimized deltas for media items,
   * thumbnails, and custom images. Thumbnail counts are further reduced by the
   * number of excluded sizes. All returned counts are clamped to zero to prevent
   * negative display values. Also reports whether the media query result is limited
   * (isLimited flag from StatsController).
   *
   * @return object stdClass with media, custom, and total sub-objects containing
   *                unoptimized image count estimates.
   */
  protected function getApproxData()
  {
		$otherMediaController = OtherMediaController::getInstance();

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
    {
      $approx->media->thumbs = $approx->media->thumbs - ($approx->media->items * count($excludeSizes));
    }

    // Total optimized items + Total optimized (approx) thumbnails
    $approx->media->total = $approx->media->items + $approx->media->thumbs;


    $approx->custom->images = $sc->find('custom', 'itemsTotal') - $sc->find('custom', 'items');
		$approx->custom->has_custom = $otherMediaController->hasCustomImages();

    $approx->total->images = $approx->media->total + $approx->custom->images; // $sc->totalImagesToOptimize();

		$approx->media->isLimited = $sc->find('media', 'isLimited');

		// Prevent any guesses to go below zero.
		foreach($approx->media as $item => $value)
		{
				if (is_numeric($value))
			  	$approx->media->$item = max($value, 0);
		}
		foreach($approx->total as $item => $value)
		{
				if (is_numeric($value))
					$approx->total->$item = max($value, 0);
		}
    return $approx;

  }

  /**
   * Loads and formats the current in-progress bulk log file for display.
   *
   * Reads the active log file (current_bulk_{type}.log) from the backup directory
   * via BulkController::getLog(). Returns false when no log file exists. When
   * present, parses semicolon-delimited entries with pipe-separated fields
   * (date|filename|item_id|message) and renders each as a styled 'fatal' div.
   * Single-cell entries (empty lines) are skipped.
   *
   * @param string $type 'media' or 'custom'. Default 'media'.
   * @return string|false Formatted HTML log output, or false when no log is present.
   */
	protected function loadCurrentLog($type = 'media')
	{
		$bulkController = BulkController::getInstance();

		$log = $bulkController->getLog('current_bulk_' . $type . '.log');

		if ($log == false)
			return false;

		 $content = $log->getContents();
		 $lines = array_filter(explode(';', $content));

		 $output = '';

		 foreach ($lines as $line)
		 {
			 	$cells = array_filter(explode('|', $line));

				if (count($cells) == 1)
					continue; // empty line.

				$date = $filename = $message = $item_id = false;

				$date = $cells[0];
				$filename = isset($cells[1]) ? $cells[1] : false;
				$item_id = isset($cells[2]) ? $cells[2] : false;
				$message = isset($cells[3]) ? $cells[3] : false;

				$kblink = UIHelper::getKBSearchLink($message);
				$kbinfo = '<span class="kbinfo"><a href="' . esc_url($kblink) . '" target="_blank" ><span class="dashicons dashicons-editor-help">&nbsp;</span></a></span>';

				$output .= '<div class="fatal">';
				$output .= $date . ': ';
				if ($message)
					$output .= $message;
				if ($filename)
					$output .= ' ( '. __('in file ','shortpixel-image-optimiser') . ' ' . $filename . ' ) ' . $kbinfo;

				$output .= '</div>';
		 }


		 return $output;
	}

  /**
   * Returns formatted log data for all past bulk runs.
   *
   * Retrieves raw log entries from BulkController::getLogs() and enriches each
   * with a human-readable bulkName (combining queue type and operation), a
   * formatted date, and — when the matching log file exists — a linked error count
   * anchor. Results are reverse-sorted by index (most recent first).
   *
   * @return array<int, array<string, mixed>> Enriched log entry arrays with keys:
   *   type, images, errors, date, operation, bulkName.
   */
  public function getLogs()
  {
      $bulkController = BulkController::getInstance();
      $logs = $bulkController->getLogs();
      $fs = \wpSPIO()->filesystem();
      $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

      $view = array();

      foreach($logs as $logData)
      {
          $logFile = $fs->getFile($backupDir->getPath() . 'bulk_' . $logData['type'] . '_' . $logData['date'] . '.log');
          $errors = $logData['fatal_errors'];

          if ($logFile->exists())
					{
            $errors = '<a data-action="OpenLog" data-file="' . $logFile->getFileBase() . '" href="' . $fs->pathToUrl($logFile) . '">' . $errors . '</a>';
					}

					$op = (isset($logData['operation'])) ? $logData['operation'] : false;

					// BulkName is just to compile a user-friendly name for the operation log.
					$bulkName = '';

					switch($logData['type'])
					{
						 case 'custom':
						 	$bulkName = __('Custom Media Bulk', 'shortpixel-image-optimiser');
						 break;
						 case 'media':
						 	$bulkName = __('Media Library Bulk', 'shortpixel-image-optimiser');
						 break;

					}

					$bulkName  .= ' '; // add a space.

					switch($op)
					{
							 case 'bulk-restore':
							 	$bulkName .= __('Restore', 'shortpixel-image-optimiser');
							 break;
							 case 'migrate':
							 	$bulkName .= __('Migrate old Metadata', 'shortpixel-image-optimiser');
							 break;
							 case 'removeLegacy':
								$bulkName = __('Remove Legacy Data', 'shortpixel-image-optimiser');
							 break;
							 case 'bulk-undoAI':
								$bulkName  = __('Bulk Remove AI Data', 'shortpixel-image-optimiser');
							 break;
							 default:
							 	$bulkName .= __('Optimization', 'shortpixel-image-optimiser');
							 break;
					}

					$images = isset($logData['total_images']) ? $logData['total_images'] : $logData['processed'];

          $view[] = array('type' => $logData['type'], 'images' => $images, 'errors' => $errors, 'date' => UiHelper::formatTS($logData['date']), 'operation' => $op, 'bulkName' => $bulkName);
      }

      krsort($view);

      return $view;
  }

} // class
