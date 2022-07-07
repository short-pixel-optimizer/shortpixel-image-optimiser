<?php

namespace ShortPixel\Controller;

use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\ViewController as ViewController;

use ShortPixel\Model\AccessModel as AccessModel;

// Use ShortPixel\Model\ApiKeyModel as ApiKeyModel

/**
 * Controller for automatic Notices about status of the plugin.
 * This controller is bound for automatic fire. Regular procedural notices should just be queued using the Notices modules.
 * Called in admin_notices.
 */
class AdminNoticesController extends \ShortPixel\Controller
{
    protected static $instance;

    const MSG_COMPAT = 'Error100';  // Plugin Compatility, warn for the ones that disturb functions.
    const MSG_FILEPERMS = 'Error101'; // File Permission check, if Queue is file-based.
    const MSG_UNLISTED_FOUND = 'Error102'; // SPIO found unlisted images, but this setting is not on
		const MSG_AVIF_ERROR = 'Error103'; // Detected unexpected or wrong AVIF headers when avif is on.

    //const MSG_NO_
    const MSG_QUOTA_REACHED = 'QuotaReached100';
    const MSG_UPGRADE_MONTH = 'UpgradeNotice200';  // When processing more than the subscription allows on average..
		// @todo This one has been removed for now. Cleanup later on the line 
    const MSG_UPGRADE_BULK = 'UpgradeNotice201'; // when there is no enough for a bulk run.

    const MSG_NO_APIKEY = 'ApiNotice300'; // API Key not found
    const MSG_NO_APIKEY_REPEAT = 'ApiNotice301';  // First Repeat.
    const MSG_NO_APIKEY_REPEAT_LONG = 'ApiNotice302'; // Last Repeat.

    const MSG_INTEGRATION_NGGALLERY = 'IntNotice400';

		const MSG_CONVERT_LEGACY = 'LegNotice100';

		const MSG_LISTVIEW_ACTIVE = 'UxNotice100';

    private $remote_message_endpoint = 'https://api.shortpixel.com/v2/notices.php';
    private $remote_readme_endpoint = 'https://plugins.svn.wordpress.org/shortpixel-image-optimiser/trunk/readme.txt';

    public function __construct()
    {
      add_action('admin_notices', array($this, 'displayNotices'), 50); // notices occured before page load
        add_action('admin_footer', array($this, 'displayNotices'));  // called in views.

      add_action('in_plugin_update_message-' . plugin_basename(SHORTPIXEL_PLUGIN_FILE), array($this, 'pluginUpdateMessage') , 50, 2 );

      // no persistent notifications with this flag set.
      if (defined('SHORTPIXEL_SILENT_MODE') && SHORTPIXEL_SILENT_MODE === true)
        return;

      add_action('admin_notices', array($this, 'check_admin_notices'), 5); // run before the plugin admin notices

    }

    public static function getInstance()
    {
      if (is_null(self::$instance))
            self::$instance = new AdminNoticesController();

      return self::$instance;
    }

    public static function resetAllNotices()
    {
       Notices::resetNotices();
    }

    /** Triggered when plugin is activated */
    public static function resetCompatNotice()
    {
        Notices::removeNoticeByID(self::MSG_COMPAT);
    }

    public static function resetAPINotices()
    {
      Notices::removeNoticeByID(self::MSG_NO_APIKEY);
      Notices::removeNoticeByID(self::MSG_NO_APIKEY_REPEAT);
      Notices::removeNoticeByID(self::MSG_NO_APIKEY_REPEAT_LONG);
    }

    public static function resetQuotaNotices()
    {
      Notices::removeNoticeByID(self::MSG_UPGRADE_MONTH);
      Notices::removeNoticeByID(self::MSG_UPGRADE_BULK);
      Notices::removeNoticeBYID(self::MSG_QUOTA_REACHED);
    }

    public static function resetIntegrationNotices()
    {
      Notices::removeNoticeByID(self::MSG_INTEGRATION_NGGALLERY);
    }

		public static function resetLegacyNotice()
		{
			Notices::removeNoticeByID(self::MSG_CONVERT_LEGACY);
		}

    /** ReInstates A Persistent Notice manually */
    public static function reInstateQuotaExceeded()
    {
      //$noticeControl = Notices::getInstance();
      //$notice = $noticeControl->getNoticeByID(self::MSG_QUOTA_REACHED);
      Notices::removeNoticeByID(self::MSG_QUOTA_REACHED);
      //$notice->unDismiss();
      //$noticeControl->update();
    }

    public function displayNotices()
    {
      if (! \wpSPIO()->env()->is_screen_to_use)
      {
          if(get_current_screen()->base !== 'dashboard') // ugly exception for dashboard.
            return; // suppress all when not our screen.
      }

			$access = AccessModel::getInstance();
			$screen = get_current_screen();

      $noticeControl = Notices::getInstance();
      $noticeControl->loadIcons(array(
          'normal' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/slider.png', SHORTPIXEL_PLUGIN_FILE) . '">',
          'success' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-cool.png', SHORTPIXEL_PLUGIN_FILE) . '">',
          'warning' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-scared.png', SHORTPIXEL_PLUGIN_FILE) . '">',
          'error' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-scared.png', SHORTPIXEL_PLUGIN_FILE) . '">',
      ));

      if ($noticeControl->countNotices() > 0)
      {
        $notices = $noticeControl->getNoticesForDisplay();

        if (count($notices) > 0)
        {
          \wpSPIO()->load_style('shortpixel-notices');

          foreach($notices as $notice)
          {
							// Ugly exception for listView Notice. If happens more, notices should extended to include screen check.
						if ($notice->getID() == AdminNoticesController::MSG_LISTVIEW_ACTIVE && \wpSPIO()->env()->screen_id !== 'upload' )
						{
							 continue;
						}
						elseif ($access->noticeIsAllowed($notice))
						{
            		echo $notice->getForDisplay();
						}
						else
						{
							 continue;
						}

            if ($notice->getID() == AdminNoticesController::MSG_QUOTA_REACHED || $notice->getID() == AdminNoticesController::MSG_UPGRADE_MONTH
            || $notice->getID() == AdminNoticesController::MSG_UPGRADE_BULK)
            {
              wp_enqueue_script('jquery.knob.min.js');
              wp_enqueue_script('jquery.tooltip.min.js');
              wp_enqueue_script('shortpixel');
            //  \wpSPIO()->load_style('shortpixel-modal');
            }
          }
        }
      }
      $noticeControl->update(); // puts views, and updates
    }

    /* General function to check on Hook for admin notices if there is something to show globally */
    public function check_admin_notices()
    {
      if (! \wpSPIO()->env()->is_screen_to_use)
      {
          if(get_current_screen()->base !== 'dashboard') // ugly exception for dashboard.
            return; // suppress all when not our screen.
      }

       $this->doAPINotices();
       $this->doCompatNotices();
       $this->doUnlistedNotices();
       $this->doQuotaNotices();
       $this->doIntegrationNotices();
       $this->doRemoteNotices();

			 $this->doListViewNotice();
    }


    protected function doIntegrationNotices()
    {
        $settings= \wpSPIO()->settings();
        if (! \wpSPIO()->settings()->verifiedKey)
        {
          return; // no key, no integrations.
        }

        if (\wpSPIO()->env()->has_nextgen && ! $settings->includeNextGen)
        {
            $url = esc_url(admin_url('options-general.php?page=wp-shortpixel-settings&part=adv-settings'));
            $message = sprintf(__('It seems you are using NextGen Gallery. You can optimize your galleries with ShortPixel, but this is currently not enabled. To enable, %sgo to settings and enable%s it!', 'shortpixel_image_optimiser'), '<a href="' . $url . '">', '</a>');
            $notice = Notices::addNormal($message);
            Notices::makePersistent($notice, self::MSG_INTEGRATION_NGGALLERY, YEAR_IN_SECONDS);
        }

    }

    /** Load the various messages about the lack of API-keys in the plugin */
    protected function doAPINotices()
    {
        if (\wpSPIO()->settings()->verifiedKey)
        {
            return; // all fine.
        }

        $activationDate = \wpSPIO()->settings()->activationDate;
        $noticeController = Notices::getInstance();
        $now = time();

        if (! $activationDate)
        {
           $activationDate = $now;
           \wpSPIO()->settings()->activationDate = $activationDate;
        }

        $notice = $noticeController->getNoticeByID(self::MSG_NO_APIKEY);
        $notice_repeat = $noticeController->getNoticeByID(self::MSG_NO_APIKEY_REPEAT);
        $notice_long = $noticeController->getNoticeByID(self::MSG_NO_APIKEY_REPEAT_LONG);

        $notice_dismissed = ($notice && $notice->isDismissed()) ? true : false;
        $notice_dismissed_repeat = ($notice_repeat && $notice_repeat->isDismissed()) ? true : false;
        $notice_dismissed_long = ($notice_long && $notice_long->isDismissed()) ? true : false;

        if (! $notice)
        {
          // If no key is activated, load the general one.
          $message = $this->getActivationNotice();
          $notice = Notices::addNormal($message);
          Notices::makePersistent($notice, self::MSG_NO_APIKEY, YEAR_IN_SECONDS);
        }

        // The trick is that after X amount of time, the first message is replaced by one of those.
        if ($notice_dismissed && ! $notice_dismissed_repeat && $now > $activationDate + (6 * HOUR_IN_SECONDS)) // after 6 hours.
        {
          //$notice->messageType = Notices::NOTICE_WARNING;
        //  $notice->
           //Notices::removeNoticeByID(self::MSG_NO_APIKEY); // remove the previous one.
           $message = __("Action needed. Please <a href='https://shortpixel.com/wp-apikey' target='_blank'>get your API key</a> to activate your ShortPixel plugin.",'shortpixel-image-optimiser');

           $notice = Notices::addWarning($message);
           Notices::makePersistent($notice, self::MSG_NO_APIKEY_REPEAT, YEAR_IN_SECONDS);
        }
        elseif ($notice_dismissed_repeat && $notice_dismissed && ! $notice_dismissed_long && $now > $activationDate + (3 * DAY_IN_SECONDS) ) // after 3 days
        {
        //  Notices::removeNoticeByID(self::MSG_NO_APIKEY); // remove the previous one.
          $message = __("Your image gallery is not optimized. It takes 2 minutes to <a href='https://shortpixel.com/wp-apikey' target='_blank'>get your API key</a> and activate your ShortPixel plugin.",'shortpixel-image-optimiser') . "<BR><BR>";

          $notice = Notices::addWarning($message);
          Notices::makePersistent($notice, self::MSG_NO_APIKEY_REPEAT_LONG, YEAR_IN_SECONDS);

        }

    }

    protected function doCompatNotices()
    {
      $noticeController = Notices::getInstance();

      $notice = $noticeController->getNoticeByID(self::MSG_COMPAT);
      $conflictPlugins = \ShortPixelTools::getConflictingPlugins();

      if ($notice)
      {
        if (count($conflictPlugins) == 0)
          Notices::removeNoticeByID(self::MSG_COMPAT); // remove when not actual anymore.
        if ($notice->isDismissed() )
          return;  // notice not wanted, don't bother.
      }

      // If this notice is not already out there, and there are conflicting plugins, go for display.
      if (count($conflictPlugins) > 0)
      {
          $notice = Notices::addWarning($this->getConflictMessage($conflictPlugins));
          Notices::makePersistent($notice, self::MSG_COMPAT, YEAR_IN_SECONDS);
      }
    }

		// Called by MediaLibraryModel
		public function invokeLegacyNotice()
		{
			  	$message = '<p><strong>' .  __('ShortPixel found items in media library with a legacy optimization format!', 'shortpixel-image-optimiser') . '</strong></p>';

					$message .= '<p>' . __('Prior to version 5.0, a different format was used to store ShortPixel optimization information. ShortPixel automatically migrates the media library items to the new format when they are opened. %s Please check if your images contain the optimization information after the migration. %s Read more %s', 'shortpixel-image-optimiser') . '</p>';

					$message .=  '<p>' . __('It is recommended to migrate all items to the modern format by clicking on the button below.', 'shortpixekl-image-optimser') . '</p>';
					$message .= '<p><a href="%s" class="button button-primary">%s</a></p>';

					$read_link = esc_url('https://shortpixel.com/knowledge-base/article/539-spio-5-tells-me-to-convert-legacy-data-what-is-this');
					$action_link = esc_url(admin_url('upload.php?page=wp-short-pixel-bulk&panel=bulk-migrate'));
					$action_name = __('Migrate legacy data', 'shortpixel-image-optimiser');

					$message = sprintf($message, '<br>', '<a href="' . $read_link . '" target="_blank">', '</a>', $action_link, $action_name);

					$notice = Notices::addNormal($message);
					Notices::makePersistent($notice, self::MSG_CONVERT_LEGACY, YEAR_IN_SECONDS);

		}

    protected function doUnlistedNotices()
    {
      $settings = \wpSPIO()->settings();
      if ($settings->optimizeUnlisted)
        return;

      if(isset($settings->currentStats['foundUnlistedThumbs']) && is_array($settings->currentStats['foundUnlistedThumbs'])) {
          $notice = Notices::addNormal($this->getUnlistedMessage($settings->currentStats['foundUnlistedThumbs']));
          Notices::makePersistent($notice, self::MSG_UNLISTED_FOUND, YEAR_IN_SECONDS);
      }
    }

    protected function doQuotaNotices()
    {
      $settings = \wpSPIO()->settings();

      $quotaController = QuotaController::getInstance();
			$noticeController = Notices::getInstance();
			$statsControl = StatsController::getInstance(); // @todo Implement this. (Figure out what this was )


      if (! \wpSPIO()->settings()->verifiedKey)
      {
        return; // no key, no quota.
      }

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended  -- This is not a form
      if(isset($_GET['checkquota'])) {
          //$shortpixel->getQuotaInformation();
          $quota = $quotaController->getQuota();
      }


      /**  Comment for historical reasons, this seems strange in the original, excluding.
      * isset($this->_settings->currentStats['optimizePdfs'])
      * && $this->_settings->currentStats['optimizePdfs'] == $this->_settings->optimizePdfs )
      */
      if($quotaController->hasQuota() === true)
      {
          $env = \wpSPIO()->env();

          $quotaController = QuotaController::getInstance();
          $quotaData = $quotaController->getQuota();

          $month_notice = $noticeController->getNoticeByID(self::MSG_UPGRADE_MONTH);

          //consider the monthly plus 1/6 of the available one-time credits.
          if( $this->monthlyUpgradeNeeded($quotaData)) {

							if ($month_notice === false)
							{
								//looks like the user hasn't got enough credits to process the monthly images, display a notice telling this
	              $message = $this->getMonthlyUpgradeMessage(array('monthAvg' => $this->getMonthAvg(), 'monthlyQuota' => $quotaData->monthly->total ));
	              $notice = Notices::addNormal($message);
	              Notices::makePersistent($notice, self::MSG_UPGRADE_MONTH, YEAR_IN_SECONDS);
							}
          }
      }
      elseif ($quotaController->hasQuota() === false)
      {

				 $notice = $noticeController->getNoticeByID(self::MSG_QUOTA_REACHED);
				 if ($notice === false)
				 {
					$message = $this->getQuotaExceededMessage();
         	$notice = Notices::addError($message);
         	Notices::makePersistent($notice, self::MSG_QUOTA_REACHED, WEEK_IN_SECONDS);
				 }

         Notices::removeNoticeByID(self::MSG_UPGRADE_MONTH); // get rid of doubles. reset
         Notices::removeNoticeByID(self::MSG_UPGRADE_BULK);
      }

    }


    protected function doRemoteNotices()
    {
         $notices = $this->get_remote_notices();

         if ($notices == false)
           return;

         foreach($notices as $remoteNotice)
         {
           if (! isset($remoteNotice->id) && ! isset($remoteNotice->message))
             return;

           if (! isset($remoteNotice->type))
             $remoteNotice->type = 'notice';

           $message = esc_html($remoteNotice->message);
           $id = sanitize_text_field($remoteNotice->id);

           $noticeController = Notices::getInstance();
           $noticeObj = $noticeController->getNoticeByID($id);

           // not added to system yet
            if ($noticeObj === false)
            {
              switch ($remoteNotice->type)
              {
                 case 'warning':
                    $new_notice = Notices::addWarning($message);
                 break;
                 case 'error':
                    $new_notice = Notices::addError($message);
                 break;
                 case 'notice':
                 default:
                     $new_notice = Notices::addNormal($message);
                 break;
              }

               Notices::makePersistent($new_notice, $id, MONTH_IN_SECONDS);
            }


        }

   }

	 protected function doListViewNotice()
	 {
	   	$screen_id = \wpSPIO()->env()->screen_id;
			if ($screen_id !== 'upload')
			{
				return;
			}

     $noticeController = Notices::getInstance();

      if ( function_exists('wp_get_current_user') ) {
            $current_user = wp_get_current_user();
            $currentUserID = $current_user->ID;
            $viewMode = get_user_meta($currentUserID, "wp_media_library_mode", true);

						if ($viewMode === "" || strlen($viewMode) == 0)
						{
								// If nothing is set, set it for them.
								update_user_meta($currentUserID, 'wp_media_library_mode', 'list');
						}
						elseif ($viewMode !== "list")
						{
								// @todo The notice is user-dependent but the notice is dismissed installation-wide.

							  $message = __('You can see ShortPixel Image Optimiser actions and data only via the list view. Switch to the list view to use the plugin via the media library', 'shortpixel-image-optimiser');
								$new_notice = Notices::addNormal($message);
								Notices::makePersistent($new_notice, self::MSG_LISTVIEW_ACTIVE, YEAR_IN_SECONDS);
						}
						else
						{
							$noticeObj = $noticeController->getNoticeByID(self::MSG_LISTVIEW_ACTIVE);
							if ($noticeObj !== false)
							{
									Notices::removeNoticeByID(self::MSG_LISTVIEW_ACTIVE);
							}
						}

        }
	 }

    // Callback to check if we are on the correct page.
    public function upgradeBulkCallback($notice)
    {
      if (! \wpSPIO()->env()->is_bulk_page)
			{
        return false;
			}
    }

  /*  protected function getPluginUpdateMessage($new_version)
    {
        $message = false;
        if (version_compare(SHORTPIXEL_IMAGE_OPTIMISER_VERSION, $new_version, '>=') ) // already installed 'new version'
        {
            return false;
        }
        elseif (version_compare($new_version, '5.0', '>=') && version_compare(SHORTPIXEL_IMAGE_OPTIMISER_VERSION, '5.0','<'))
        {
             $message = __('<h4>Version 5.0</h4> Warning, Version 5 is a major update. It\'s strongly recommend to backup your site and proceed with caution. Please report issues via our support channels', 'shortpixel-image-optimiser');
        }

        return $message;
    } */

    public function getActivationNotice()
    {
      $message = "<p>" . __('In order to start the optimization process, you need to validate your API Key in the '
              . '<a href="options-general.php?page=wp-shortpixel-settings">ShortPixel Settings</a> page in your WordPress Admin.','shortpixel-image-optimiser') . "
      </p>
      <p>" .  __('If you don’t have an API Key, you can get one delivered to your inbox, for free.','shortpixel-image-optimiser') . "</p>
      <p>" .  __('Please <a href="https://shortpixel.com/wp-apikey" target="_blank">sign up to get your API key.</a>','shortpixel-image-optimiser') . "</p>";

      return $message;
    }

    protected function getConflictMessage($conflicts)
    {
      $message = __("The following plugins are not compatible with ShortPixel and may lead to unexpected results: ",'shortpixel-image-optimiser');
      $message .= '<ul class="sp-conflict-plugins">';
      foreach($conflicts as $plugin) {
          //ShortPixelVDD($plugin);
          $action = $plugin['action'];
          $link = ( $action == 'Deactivate' )
              ? wp_nonce_url( admin_url( 'admin-post.php?action=shortpixel_deactivate_conflict_plugin&plugin=' . urlencode( $plugin['path'] ) ), 'sp_deactivate_plugin_nonce' )
              : $plugin['href'];
          $message .= '<li class="sp-conflict-plugins-list"><strong>' . $plugin['name'] . '</strong>';
          $message .= '<a href="' . $link . '" class="button button-primary">' . $action . '</a>';

          if($plugin['details']) $message .= '<br>';
          if($plugin['details']) $message .= '<span>' . $plugin['details'] . '</span>';
      }
      $message .= "</ul>";

      return $message;
    }

    protected function getUnlistedMessage($unlisted)
    {
      $message = __("<p>ShortPixel found thumbnails which are not registered in the metadata but present alongside the other thumbnails. These thumbnails could be created and needed by some plugin or by the theme. Let ShortPixel optimize them as well?</p>", 'shortpixel-image-optimiser');
      $message .= '<p>' . __("For example, the image", 'shortpixel-image-optimiser') . '
          <a href="post.php?post=' . $unlisted->id . '&action=edit" target="_blank">
              ' . $unlisted->name . '
          </a> has also these thumbs not listed in metadata: '  . (implode(', ', $unlisted->unlisted)) . '
          </p>';

        return $message;
    }

    protected function getBulkUpgradeMessage($extra)
    {
      $message = '<p>' . sprintf(__("You currently have <strong>%d images and thumbnails to optimize</strong> but you only have <strong>%d images</strong> available in your current plan."
            . " You might need to upgrade your plan in order to have all your images optimized.", 'shortpixel-image-optimiser'), $extra['filesTodo'], $extra['quotaAvailable']) . '</p>';
      $message .= '<p><button class="button button-primary" id="shortpixel-upgrade-advice" onclick="ShortPixel.proposeUpgrade()" style="margin-right:10px;"><strong>' .  __('Show me the best available options', 'shortpixel-image-optimiser') . '</strong></button></p>';
       $this->proposeUpgradePopup();
      return $message;
    }

    protected function getMonthlyUpgradeMessage($extra)
    {
      $message = '<p>' . sprintf(__("You are adding an average of <strong>%d images and thumbnails every month</strong> to your Media Library and you have <strong>a plan of %d images/month</strong>."
            . " You might need to upgrade your plan in order to have all your images optimized.", 'shortpixel-image-optimiser'), $extra['monthAvg'], $extra['monthlyQuota']) . '</p>';
      $message .= '  <button class="button button-primary" id="shortpixel-upgrade-advice" onclick="ShortPixel.proposeUpgrade()" style="margin-right:10px;"><strong>' .  __('Show me the best available options', 'shortpixel-image-optimiser') . '</strong></button>';
      $this->proposeUpgradePopup();
      return $message;
    }

    protected function getQuotaExceededMessage()
    {
      $statsControl = StatsController::getInstance();
      $averageCompression = $statsControl->getAverageCompression();
      $quotaController = QuotaController::getInstance();

      $keyControl = ApiKeyController::getInstance();

      //$keyModel->loadKey();

      $login_url = 'https://shortpixel.com/login/';
      $friend_url = $login_url;

      if ($keyControl->getKeyForDisplay())
      {
        $login_url .= $keyControl->getKeyForDisplay() . '/';
        $friend_url = $login_url . 'tell-a-friend';
      }

     $message = '<div class="sp-quota-exceeded-alert"  id="short-pixel-notice-exceed">';

     if($averageCompression) {

          $message .= '<div style="float:right;">
              <div class="bulk-progress-indicator" style="height: 110px">
                  <div style="margin-bottom:5px">' . __('Average image<br>reduction so far:','shortpixel-image-optimiser') . '</div>
                  <div id="sp-avg-optimization"><input type="text" id="sp-avg-optimization-dial" value="' . round($averageCompression) . '" class="dial percentDial" data-dialsize="60"></div>
                  <script>
                      jQuery(function() {
													if (ShortPixel)
													{
                          	ShortPixel.percentDial("#sp-avg-optimization-dial", 60);
													}
                      });
                  </script>
              </div>
          </div>';

    }

        $message .= '<h3>' . __('Quota Exceeded','shortpixel-image-optimiser') . '</h3>';

        $quota = $quotaController->getQuota();

        $creditsUsed = number_format($quota->monthly->consumed + $quota->onetime->consumed);
        $totalOptimized = $statsControl->find('total', 'images');
        $totalImagesToOptimize = number_format($statsControl->totalImagesToOptimize());

        $message .= '<p>' . sprintf(__('The plugin has optimized <strong>%s images</strong> and stopped because it reached the available quota limit.','shortpixel-image-optimiser'),
              $creditsUsed);

        if($totalImagesToOptimize > 0) {

              $message .= sprintf(__('<strong> %s images and thumbnails</strong> are not yet optimized by ShortPixel.','shortpixel-image-optimiser'), $totalImagesToOptimize  );
          }

         $message .= '</p>
            <div>
              <button class="button button-primary" type="button" id="shortpixel-upgrade-advice" onclick="ShortPixel.proposeUpgrade()" style="margin-right:10px;"><strong>' .  __('Show me the best available options', 'shortpixel-image-optimiser') . '</strong></button>
              <a class="button button-primary" href="' . $login_url . '"
                 title="' . __('Go to my account and select a plan','shortpixel-image-optimiser') . '" target="_blank" style="margin-right:10px;">
                  <strong>' . __('Upgrade','shortpixel-image-optimiser') . '</strong>
              </a>
              <button type="button" name="checkQuota" class="button" onclick="ShortPixel.checkQuota()">'.  __('Confirm New Credits','shortpixel-image-optimiser') . '</button>
          </div>';

				$message .= '</div>'; /// closing div
        $this->proposeUpgradePopup();
        return $message;
    }

    protected function proposeUpgradePopup() {
      		// @todo LoadView Snippet here.
					$view = new ViewController();
					$view->loadView('snippets/part-upgrade-options');
    }

    public function proposeUpgradeRemote()
    {
        //$stats = $this->countAllIfNeeded($this->_settings->currentStats, 300);
        $statsController = StatsController::getInstance();
        $apiKeyController = ApiKeyController::getInstance();
        $settings = \wpSPIO()->settings();

				$webpActive = ($settings->createWebp) ? true : false;
				$avifActive =  ($settings->createAvif) ? true : false;

        $args = array(
            'method' => 'POST',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => array("params" => json_encode(array(
                'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
                'key' => $apiKeyController->forceGetApiKey(),
                'm1' => $statsController->find('period', 'months', '1'),
                'm2' => $statsController->find('period', 'months', '2'),
                'm3' => $statsController->find('period', 'months', '3'),
                'm4' => $statsController->find('period', 'months', '4'),
                'filesTodo' => $statsController->totalImagesToOptimize(),
                'estimated' => $settings->optimizeUnlisted || $settings->optimizeRetina ? 'true' : 'false',
								'webp' => $webpActive,
								'avif' => $avifActive,
                /* */
                'iconsUrl' => base64_encode(wpSPIO()->plugin_url('res/img'))
              ))),
              'cookies' => array()

        );


        $proposal = wp_remote_post("https://shortpixel.com/propose-upgrade-frag", $args);

        if(is_wp_error( $proposal )) {
            $proposal = array('body' => __('Error. Could not contact ShortPixel server for proposal', 'shortpixel-image-optimiser'));
        }
        die( $proposal['body'] );

    }

   private function get_remote_notices()
   {
        $transient_name = 'shortpixel_remote_notice';
        $transient_duration = DAY_IN_SECONDS;

        if (\wpSPIO()->env()->is_debug)
         $transient_duration = 30;

        $keyControl = new apiKeyController();
        //$keyControl->loadKey();

        $notices = get_transient($transient_name);
        $url = $this->remote_message_endpoint;
        $url = add_query_arg(array(  // has url
           'key' => $keyControl->forceGetApiKey(),
           'version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
           'target' => 3,
        ), $url);


        if ( $notices === false  ) {
                $notices_response = wp_safe_remote_request( $url );
                $content = false;
                if (! is_wp_error( $notices_response ) )
                {
                  $notices = json_decode($notices_response['body']);

                   if (! is_array($notices))
                     $notices = false;

                   // Save transient anywhere to prevent over-asking when nothing good is there.
                   set_transient( $transient_name, $notices, $transient_duration );
                }
                else
                {
                   set_transient( $transient_name, false, $transient_duration );
                }
        }

        return $notices;
   }

    protected function monthlyUpgradeNeeded($quotaData) {

				if  (isset($quotaData->monthly->total))
				{
						$monthAvg = $this->getMonthAvg($quotaData);
						// +20 I suspect to not trigger on very low values of monthly use(?)
						$threshold = $quotaData->monthly->total + $quotaData->onetime->remaining/6+20;
						if ($monthAvg > $threshold)
						{
								return true;
						}
				}
				return false;
    }

    protected function bulkUpgradeNeeded() {

        $quotaController = QuotaController::getInstance(); //$stats;
        $stats = StatsController::getInstance();

        $to_process = $stats->totalImagesToOptimize(); // $stats->find('total', 'imagesTotal') - $stats->find('total', 'images');

        return $to_process > $quotaController->getAvailableQuota();

        //return $to_process > $quotaData->monthly->total +  + $quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'];
    }

    protected function getMonthAvg() {
        $stats = StatsController::getInstance();

				// Count how many months have some optimized images.
        for($i = 4, $count = 0; $i>=1; $i--) {
            if($count == 0 && $stats->find('period', 'months', $i) == 0)
						{
							continue;
						}
            $count++;

        }
				// Sum last 4 months, and divide by number of active months to get number of avg per active month.
        return ($stats->find('period', 'months', 1) + $stats->find('period', 'months', 2) + $stats->find('period', 'months', 3) + $stats->find('period', 'months', 4) / max(1,$count));
    }


    public function pluginUpdateMessage($data, $response)
    {
  //    $message = $this->getPluginUpdateMessage($plugin['new_version']);

      $message = $this->get_update_notice($data, $response);

      if( $message !== false && strlen(trim($message)) > 0) {
    		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
    		printf(
    			'<tr class="plugin-update-tr active"><td colspan="%s" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt">%s</div></td></tr>',
    			$wp_list_table->get_column_count(),
    			wpautop( $message )
    		);
    	}

    }

    /**
     *   Stolen from SPAI, Thanks.
    */
    private function get_update_notice($data, $response) {
            $transient_name = 'shortpixel_update_notice_' . $response->new_version;

            $transient_duration = DAY_IN_SECONDS;

            if (\wpSPIO()->env()->is_debug)
              $transient_duration = 30;

            $update_notice  = get_transient( $transient_name );
            $url = $this->remote_readme_endpoint;

            if ( $update_notice === false || strlen( $update_notice ) == 0 ) {
                    $readme_response = wp_safe_remote_request( $url );
                    $content = false;
                    if (! is_wp_error( $readme_response ) )
                    {
                       $content = $readme_response['body'];
                    }


                    if ( !empty( $readme_response ) ) {
                            $update_notice = $this->parse_update_notice( $content, $response );
                            set_transient( $transient_name, $update_notice, $transient_duration );
                    }
            }

            return $update_notice;
    }



    /**
	         * Parse update notice from readme file.
	         *
	         * @param string $content  ShortPixel AI readme file content
          * @param object $response WordPress response
	         *
	         * @return string
	         */
	        private function parse_update_notice( $content, $response ) {

                  $new_version = $response->new_version;

	                $update_notice = '';

	               // foreach ( $check_for_notices as $id => $check_version ) {

	                        if ( version_compare( SHORTPIXEL_IMAGE_OPTIMISER_VERSION, $new_version, '>' ) ) {
	                                return '';
	                        }

	                        $result = $this->parse_readme_content( $content, $new_version, $response );

	                        if ( !empty( $result ) ) {
	                                $update_notice = $result;
	                        }
	             //   }

	                return wp_kses_post( $update_notice );
	        }


  /*
     *
     * Parses readme file's content to find notice related to passed version
     *
     * @param string $content Readme file content
     * @param string $version Checked version
     * @param object $response WordPress response
     *
     * @return string
     */

      private function parse_readme_content( $content, $new_version, $response ) {

                $notices_pattern = '/==.*Upgrade Notice.*==(.*)$|==/Uis';

	                $notice = '';
	                $matches = null;

	                if ( preg_match( $notices_pattern, $content, $matches ) ) {

                  if (! isset($matches[1]))
                    return ''; // no update texts.

                  $match = str_replace('\n', '', $matches[1]);
                  $lines = str_split(trim($match));

                  $versions = array();
                  $inv = false;
                  foreach($lines as $char)
                  {
                     //if (count($versions) == 0)
                     if ($char == '=' && ! $inv) // = and not recording version, start one.
                     {
                        $curver = '';
                        $inv = true;
                     }
                     elseif ($char == ' ' && $inv) // don't record spaces in version
                         continue;
                     elseif ($char == '=' && $inv) // end of version line
                     {  $versions[trim($curver)] = '';
                        $inv = false;
                     }
                     elseif($inv) // record the version string
                     {
                        $curver .= $char;
                     }
                     elseif(! $inv)  // record the message
                     {
                        $versions[trim($curver)] .= $char;
                     }


                  }

                  foreach($versions as $version => $line)
                  {
                      if (version_compare(SHORTPIXEL_IMAGE_OPTIMISER_VERSION, $version, '<') && version_compare($version, $new_version, '<='))
                      {
                          $notice .= '<span>';
                          $notice .= $this->markdown2html( $line );
                          $notice .= '</span>';

                      }
                  }

	                }

	                return $notice;
	        }

	        /*private function replace_readme_constants( $content, $response ) {
	                $constants    = [ '{{ NEW VERSION }}', '{{ CURRENT VERSION }}', '{{ PHP VERSION }}', '{{ REQUIRED PHP VERSION }}' ];
	                $replacements = [ $response->new_version, SHORTPIXEL_IMAGE_OPTIMISER_VERSION, PHP_VERSION, $response->requires_php ];

	                return str_replace( $constants, $replacements, $content );
	        } */

	        private function markdown2html( $content ) {
	                $patterns = [
	                        '/\*\*(.+)\*\*/U', // bold
	                        '/__(.+)__/U', // italic
	                        '/\[([^\]]*)\]\(([^\)]*)\)/U', // link
	                ];

	                $replacements = [
	                        '<strong>${1}</strong>',
	                        '<em>${1}</em>',
	                        '<a href="${2}" target="_blank">${1}</a>',
	                ];

	                $prepared_content = preg_replace( $patterns, $replacements, $content );

	                return isset( $prepared_content ) ? $prepared_content : $content;
        }


} // class
