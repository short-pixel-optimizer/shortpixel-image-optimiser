<?php
namespace ShortPixel\Controller;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\ApiKeyModel as ApiKeyModel;

/* Controller for automatic Notices about status of the plugin.
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
    const MSG_UPGRADE_BULK = 'UpgradeNotice201'; // when there is no enough for a bulk run.


    const MSG_NO_APIKEY = 'ApiNotice300'; // API Key not found
    const MSG_NO_APIKEY_REPEAT = 'ApiNotice301';  // First Repeat.
    const MSG_NO_APIKEY_REPEAT_LONG = 'ApiNotice302'; // Last Repeat.

    const MSG_INTEGRATION_NGGALLERY = 'IntNotice400';

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
          wp_enqueue_style('shortpixel-notices');

          foreach($notices as $notice)
          {
            echo $notice->getForDisplay();

            if ($notice->getID() == AdminNoticesController::MSG_QUOTA_REACHED || $notice->getID() == AdminNoticesController::MSG_UPGRADE_MONTH
            || $notice->getID() == AdminNoticesController::MSG_UPGRADE_BULK)
            {
              wp_enqueue_script('jquery.knob.min.js');
              wp_enqueue_script('jquery.tooltip.min.js');
              wp_enqueue_script('shortpixel');
              \wpSPIO()->load_style('shortpixel-modal');
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
       $this->doFilePermNotice();
       $this->doAPINotices();
       $this->doCompatNotices();
       $this->doUnlistedNotices();
       $this->doQuotaNotices();
       $this->doIntegrationNotices();
       $this->doHelpOptInNotices();
       $this->doRemoteNotices();

			 // Check if we are legacy, or if SPIO 5 was ever installed.
			 $this->checkLegacyNotices();
    }


    protected function doIntegrationNotices()
    {
        $settings= \wpSPIO()->settings();
        if (! \wpSPIO()->settings()->verifiedKey)
        {
          return; // no key, no integrations.
        }

        if (\wpSPIO()->env()->has_nextgen && ! $settings->includeNextGen  )
        {
            $url = admin_url('options-general.php?page=wp-shortpixel-settings&part=adv-settings');
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

    protected function doFilePermNotice()
    {
      $testQ = (! defined('SHORTPIXEL_NOFLOCK')) ? \ShortPixelQueue::testQ() : \ShortPixelQueueDB::testQ();

      if( $testQ) {
        return; // all fine.
      }

      // Keep this thing out of others screens.
      if (! \wpSPIO()->env()->is_our_screen)
        return;

       $message = sprintf(__("ShortPixel is not able to write to the uploads folder so it cannot optimize images, please check permissions (tried to create the file %s/.shortpixel-q-1).",'shortpixel-image-optimiser'),
                               SHORTPIXEL_UPLOADS_BASE);
       Notices::addError($message, true);

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
      //  var_dump($this->getConflictMessage($conflictPlugins));
          $notice = Notices::addWarning($this->getConflictMessage($conflictPlugins));
          Notices::makePersistent($notice, self::MSG_COMPAT, YEAR_IN_SECONDS);
      }
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
      $currentStats = $settings->currentStats;
      $shortpixel = \wpSPIO()->getShortPixel();

      if (! \wpSPIO()->settings()->verifiedKey)
      {
        return; // no key, no quota.
      }

      if(!is_array($currentStats) || isset($_GET['checkquota']) || isset($currentStats["quotaData"])) {
          $shortpixel->getQuotaInformation();
      }


      /**  Comment for historical reasons, this seems strange in the original, excluding.
      * isset($this->_settings->currentStats['optimizePdfs'])
      * && $this->_settings->currentStats['optimizePdfs'] == $this->_settings->optimizePdfs )
      */
      if(!$settings->quotaExceeded)
      {
      //    $screen = get_current_screen();
          $env = \wpSPIO()->env();

          $statsSetting = is_array($settings->currentStats) ? $settings->currentStats : array();
          $stats = $shortpixel->countAllIfNeeded($statsSetting, 86400);

          $quotaData = $stats;
          $noticeController = Notices::getInstance();

          $bulk_notice = $noticeController->getNoticeByID(self::MSG_UPGRADE_BULK);
          $bulk_is_dismissed = ($bulk_notice && $bulk_notice->isDismissed() ) ? true : false;

          $month_notice = $noticeController->getNoticeByID(self::MSG_UPGRADE_MONTH);

          //this is for bulk page - alert on the total credits for total images
          if( ! $bulk_is_dismissed && $env->is_bulk_page && $this->bulkUpgradeNeeded($stats)) {
              //looks like the user hasn't got enough credits to bulk process all media library
              $message = $this->getBulkUpgradeMessage(array('filesTodo' => $stats['totalFiles'] - $stats['totalProcessedFiles'],
                                                      'quotaAvailable' => max(0, $quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'])));
              $notice = Notices::addNormal($message);
              Notices::makePersistent($notice, self::MSG_UPGRADE_BULK, YEAR_IN_SECONDS, array($this, 'upgradeBulkCallback'));
              //ShortPixelView::displayActivationNotice('upgbulk', );
          }
          //consider the monthly plus 1/6 of the available one-time credits.
          elseif( $this->monthlyUpgradeNeeded($stats)) {
              //looks like the user hasn't got enough credits to process the monthly images, display a notice telling this
              $message = $this->getMonthlyUpgradeMessage(array('monthAvg' => $this->getMonthAvg($stats), 'monthlyQuota' => $quotaData['APICallsQuotaNumeric']));
              //ShortPixelView::displayActivationNotice('upgmonth', );
              $notice = Notices::addNormal($message);
              Notices::makePersistent($notice, self::MSG_UPGRADE_MONTH, YEAR_IN_SECONDS);
          }
      }
      elseif ($settings->quotaExceeded)
      {
         $stats = $shortpixel->countAllIfNeeded($settings->currentStats, 86400);
         $quotaData = $stats;

         $message = $this->getQuotaExceededMessage($quotaData);

         $notice = Notices::addError($message);
         Notices::makePersistent($notice, self::MSG_QUOTA_REACHED, WEEK_IN_SECONDS);

         Notices::removeNoticeByID(self::MSG_UPGRADE_MONTH); // get rid of doubles. reset
         Notices::removeNoticeByID(self::MSG_UPGRADE_BULK);
      }

    }



    protected function doHelpOptInNotices()
    {
       return; // this is disabled pending review.
        $settings = \wpSPIO()->settings();
        $optin = $settings->helpscoutOptin;

        if ($optin == -1)
        {
            $message = $this->getHelpOptinMessage();
            Notices::addNormal($message);
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
             if ($noticeObj == false)
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

		protected function checkLegacyNotices()
		{
			 global $wpdb;

			 $table_name = $wpdb->prefix . 'shortpixel_postmeta';
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name) ) == $table_name)
			{
				 $message = $this->getAlreadyUpgradedMessage();
				   Notices::addError($message);
			}


		}


    // Callback to check if we are on the correct page.
    public function upgradeBulkCallback($notice)
    {
      if (! \wpSPIO()->env()->is_bulk_page)
        return false;
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

    protected function getActivationNotice()
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
              ? wp_nonce_url( admin_url( 'admin-post.php?action=shortpixel_deactivate_plugin&plugin=' . urlencode( $plugin['path'] ) ), 'sp_deactivate_plugin_nonce' )
              : $plugin['href'];
          $message .= '<li class="sp-conflict-plugins-list"><strong>' . $plugin['name'] . '</strong>';
          $message .= '<a href="' . $link . '" class="button button-primary">' . __( $action, 'shortpixel_image_optimiser' ) . '</a>';

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
      $message .= '  <button class="button button-primary" id="shortpixel-upgrade-advice" onclick="ShortPixel.proposeUpgrade()" style="margin-right:10px;"><strong>' .  __('Show me the best available options', 'shortpixel-image-optimiser') . '</strong></button>';
      $message .= $this->proposeUpgradePopup();
      //self::includeProposeUpgradePopup();
      return $message;
    }

    protected function getMonthlyUpgradeMessage($extra)
    {
      $message = '<p>' . sprintf(__("You are adding an average of <strong>%d images and thumbnails every month</strong> to your Media Library and you have <strong>a plan of %d images/month</strong>."
            . " You might need to upgrade your plan in order to have all your images optimized.", 'shortpixel-image-optimiser'), $extra['monthAvg'], $extra['monthlyQuota']) . '</p>';
      $message .= '  <button class="button button-primary" id="shortpixel-upgrade-advice" onclick="ShortPixel.proposeUpgrade()" style="margin-right:10px;"><strong>' .  __('Show me the best available options', 'shortpixel-image-optimiser') . '</strong></button>';
      $message .= $this->proposeUpgradePopup();
      return $message;
    }

    protected function getQuotaExceededMessage($quotaData)
    {
      $averageCompression = \wpSPIO()->getShortPixel()->getAverageCompression();

      $keyModel = new apiKeyModel();
      $keyModel->loadKey();


      $login_url = 'https://shortpixel.com/login/';
      $friend_url = $login_url;
      if (! $keyModel->is_hidden())
      {
        $login_url .= $keyModel->getkey() . '/';
        $friend_url = $login_url . 'tellafriend';
      }

     $message = '<div class="wrap sp-quota-exceeded-alert"  id="short-pixel-notice-exceed">';

     if($averageCompression) {

          $message .= '<div style="float:right; margin-top: 10px">
              <div class="bulk-progress-indicator" style="height: 110px">
                  <div style="margin-bottom:5px">' . __('Average image<br>reduction so far:','shortpixel-image-optimiser') . '</div>
                  <div id="sp-avg-optimization"><input type="text" id="sp-avg-optimization-dial" value="' . round($averageCompression) . '" class="dial"></div>
                  <script>
                      jQuery(function() {
                          ShortPixel.percentDial("#sp-avg-optimization-dial", 60);
                      });
                  </script>
              </div>
          </div>';

    }

      /*    <img src="<?php echo(wpSPIO()->plugin_url('res/img/robo-scared.png'));?>"
               srcset='<?php echo(wpSPIO()->plugin_url('res/img/robo-scared.png' ));?> 1x, <?php echo(wpSPIO()->plugin_url('res/img/robo-scared@2x.png' ));?> 2x'
               class='short-pixel-notice-icon'> */

        $message .= '<h3>' . __('Quota Exceeded','shortpixel-image-optimiser') . '</h3>';

    //    $recheck = isset($_GET['checkquota']) ? true : false;

    /*    if($recheck) {
             $message .= '<p style="color: red">' . __('You have no available image credits. If you just bought a package, please note that sometimes it takes a few minutes for the payment confirmation to be sent to us by the payment processor.','shortpixel-image-optimiser') . '</p>';
        } */

        $message .= '<p>' . sprintf(__('The plugin has optimized <strong>%s images</strong> and stopped because it reached the available quota limit.','shortpixel-image-optimiser'),
              number_format(max(0, $quotaData['APICallsMadeNumeric'] + $quotaData['APICallsMadeOneTimeNumeric'])));

        if($quotaData['totalProcessedFiles'] < $quotaData['totalFiles']) {

              $message .= sprintf(__('<strong> %s images and %s thumbnails</strong> are not yet optimized by ShortPixel.','shortpixel-image-optimiser'),
                      number_format(max(0, $quotaData['mainFiles'] - $quotaData['mainProcessedFiles'])),
                      number_format(max(0, ($quotaData['totalFiles'] - $quotaData['mainFiles']) - ($quotaData['totalProcessedFiles'] - $quotaData['mainProcessedFiles']))));
          }

         $message .= '</p>
            <div>
              <button class="button button-primary" id="shortpixel-upgrade-advice" onclick="ShortPixel.proposeUpgrade()" style="margin-right:10px;"><strong>' .  __('Show me the best available options', 'shortpixel-image-optimiser') . '</strong></button>
              <a class="button button-primary" href="' . $login_url . '"
                 title="' . __('Go to my account and select a plan','shortpixel-image-optimiser') . '" target="_blank" style="margin-right:10px;">
                  <strong>' . __('Upgrade','shortpixel-image-optimiser') . '</strong>
              </a>
              <input type="button" name="checkQuota" class="button" value="'.  __('Confirm New Credits','shortpixel-image-optimiser') . '"
                     onclick="ShortPixel.checkQuota()">
          </div>';

          $message .= '<p>' . __('Get more image credits by referring ShortPixel to your friends!','shortpixel-image-optimiser') . '
              <a href="' . $friend_url . '" target="_blank">' . __('Check your account','shortpixel-image-optimiser') .
              '</a> ' . __('for your unique referral link. For each user that joins, you will receive +100 additional image credits/month.','shortpixel-image-optimiser') . '
          </p>
          </div>';

        $message .= $this->proposeUpgradePopup();
        return $message;
    }

    protected function proposeUpgradePopup() {
        wp_enqueue_style('short-pixel-modal.min.css', plugins_url('/res/css/short-pixel-modal.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

        $message = '<div id="shortPixelProposeUpgradeShade" class="sp-modal-shade" style="display:none;">
            <div id="shortPixelProposeUpgrade" class="shortpixel-modal shortpixel-hide" style="min-width:650px;margin-left:-305px;">
                <div class="sp-modal-title">
                    <button type="button" class="sp-close-upgrade-button" onclick="ShortPixel.closeProposeUpgrade()">&times;</button>' .
                     __('Upgrade your ShortPixel account', 'shortpixel-image-optimiser') . '
                </div>
                <div class="sp-modal-body sptw-modal-spinner" style="height:auto;min-height:400px;padding:0;">
                </div>
            </div>
        </div>';
        return $message;
    }

    protected function getHelpOptinMessage()
    {

      //onclick='ShortPixel.optInHelp(0)'
       $message = __('Shortpixel needs to ask permission to load the help functionality');
       $message .= "<div><button type='button' id='sp-helpscout-disallow' class='button button-primary' >" . __('No, I don\'t need help', 'shortpixel-image-optimiser') . "</button> &nbsp;&nbsp;";
       $message .= "<button type='button' id='sp-helpscout-allow' class='button button-primary'>" . __('Yes, load the help widget', 'shortpixel-image-optimiser') . "</button></div>";

       $message .= "<p>" . __('Shortpixel uses third party services Helpscout and Quriobot to access our help easier. By giving permission you agree to opt-in and load these service on ShortPixel related pages', 'shortpixel-image-optimiser');

       $message .= "<script>window.addEventListener('load', function(){
            document.getElementById('sp-helpscout-allow').addEventListener('click', ShortPixel.optInHelp, {once: true} );
            document.getElementById('sp-helpscout-allow').toggleParam = 'on';
            document.getElementById('sp-helpscout-disallow').addEventListener('click', ShortPixel.optInHelp, {once: true} );
            document.getElementById('sp-helpscout-disallow').toggleParam = 'off';
       }); </script>";
       return $message;
    }

		protected function getAlreadyUpgradedMessage()
		{
			 $message = '<p>' . __('It seems you already upgraded to Shortpixel Image Optimizer version 5 and came back to version 4. We do not recommend using version 4 anymore because it might result in data loss. Please consult our support team if you have any issues with the new version.') . '</p>';

			 $message .= '<p>' . sprintf(__('If you are sure about what you\'re doing and you want to get rid of this message, remove the database table %s. All optimizations done with version 5 will be forgotten and they will be processed again with version 4, according to your settings. ', 'shortpixel_image_optimiser'), 'shortpixel_postmeta') . '<a class="shortpixel-help-link" href="https://shortpixel.com/knowledge-base/article/529-downgrading-shortpixel-image-optimizer-version-5-version-4" target="_blank"><span class="dashicons dashicons-editor-help"></span>' . __('Reaad more', 'shortpixel-image-optimiser') . '</a>' . '</p>';

			 return $message;
		}

    protected function monthlyUpgradeNeeded($quotaData) {
        return isset($quotaData['APICallsQuotaNumeric']) && $this->getMonthAvg($quotaData) > $quotaData['APICallsQuotaNumeric'] + ($quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'])/6 + 20;
    }

    protected function bulkUpgradeNeeded($stats) {
        $quotaData = $stats;
        return $stats['totalFiles'] - $stats['totalProcessedFiles'] > $quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'];
    }

    protected function getMonthAvg($stats) {
        for($i = 4, $count = 0; $i>=1; $i--) {
            if($count == 0 && $stats['totalM' . $i] == 0) continue;
            $count++;
        }
        return ($stats['totalM1'] + $stats['totalM2'] + $stats['totalM3'] + $stats['totalM4']) / max(1,$count);
    }


    public function pluginUpdateMessage($data, $response)
    {
  //    $message = $this->getPluginUpdateMessage($plugin['new_version']);

      $message = $this->get_update_notice($data, $response);

      if( $message !== false && strlen(trim($message)) > 0) {
    		$wp_list_table = _get_list_table( 'WP_Plugins_List_Table' );
    		printf(
    			'<tr class="plugin-update-tr active"><td colspan="%s" class="plugin-update colspanchange"><div class="notice inline notice-error notice-alt"><h4>%s</h4> %s</div></td></tr>',
    			$wp_list_table->get_column_count(), __('Version', 'shortpixel_image_optimiser') . ' ' . $response->new_version,
    			wpautop( $message )
    		);
    	}

    }


    private function get_remote_notices()
    {
         $transient_name = 'shortpixel_remote_notice';
         $transient_duration = DAY_IN_SECONDS;

         if (\wpSPIO()->env()->is_debug)
          $transient_duration = 30;

         $keyModel = new apiKeyModel();
         $keyModel->loadKey();

         $notices = get_transient($transient_name);
         $url = $this->remote_message_endpoint;
         $url = add_query_arg(array(
            'key' => $keyModel->getKey(),
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

	                $update_notice = false;

	               // foreach ( $check_for_notices as $id => $check_version ) {
	                        if ( version_compare( SHORTPIXEL_IMAGE_OPTIMISER_VERSION, $new_version, '>' ) ) {
	                                return '';
	                        }

	                        $result = $this->parse_readme_content( $content, $new_version, $response );

	                        if ( !empty( $result ) && strlen($result) > 0 ) {
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

                    $match = $matches[1];
                //  $match = str_replace('\n', '', $matches[1]);
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
                        if (isset($curver))
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


	                return trim($notice);
	        }

	        /*private function replace_readme_constants( $content, $response ) {
	                $constants    = [ '{{ NEW VERSION }}', '{{ CURRENT VERSION }}', '{{ PHP VERSION }}', '{{ REQUIRED PHP VERSION }}' ];
	                $replacements = [ $response->new_version, SHORTPIXEL_IMAGE_OPTIMISER_VERSION, PHP_VERSION, $response->requires_php ];

	                return str_replace( $constants, $replacements, $content );
	        } */

	        private function markdown2html( $content ) {

                  $content = str_replace(array(PHP_EOL, '\n', '\n\r'), '<br>', $content);

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
