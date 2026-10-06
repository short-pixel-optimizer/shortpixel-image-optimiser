<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

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

    protected $definedNotices = array( // NoticeModels by Class.  This is not optimal but until solution found, workable.
        'CompatNotice',
        'UnlistedNotice',
        'AvifNotice',
        'QuotaNoticeMonth',
        'QuotaNoticeReached',
        'ApiNotice',
        'ApiNoticeRepeat',
        'ApiNoticeRepeatLong',
        'ReviewNotice',
        'NextgenNotice',
   //     'SmartcropNotice',
        'LegacyNotice',
        'ListviewNotice',
		//		'HeicFeatureNotice',
        'NewExclusionFormat',
        'LitespeedCache',
        'SpaiCDN',
    );
    protected $adminNotices; // Models

    private $remote_message_endpoint = 'https://api.shortpixel.com/v2/notices.php'; 
    private $remote_readme_endpoint = 'https://plugins.svn.wordpress.org/shortpixel-image-optimiser/trunk/readme.txt';

    private $silent_mode = false;

    /**
	 * Register admin-notice display hooks and initialise notice model instances.
	 *
	 * Attaches `displayNotices()` to both `admin_notices` (priority 50) and
	 * `admin_footer`, and registers the plugin-update-message hook.
	 *
	 * When `SHORTPIXEL_SILENT_MODE` is true, returns early after setting the silent-mode
	 * flag without loading any notice models.  Otherwise registers `check_admin_notices`
	 * at priority 5 on `admin_notices` and calls `initNotices()`.
	 */
    public function __construct()
    {
        add_action('admin_notices', array($this, 'displayNotices'), 50); // notices occured before page load
        add_action('admin_footer', array($this, 'displayNotices'));  // called in views.

        add_action('in_plugin_update_message-' . plugin_basename(SHORTPIXEL_PLUGIN_FILE), array($this, 'pluginUpdateMessage') , 50, 2 );

        // no persistent notifications with this flag set.
        if (defined('SHORTPIXEL_SILENT_MODE') && SHORTPIXEL_SILENT_MODE === true)
        {
            $this->silent_mode = true;
            return;
        }
        add_action('admin_notices', array($this, 'check_admin_notices'), 5); // run before the plugin admin notices

				$this->initNotices();
    }

    /**
     * Return the singleton instance, creating it on first call.
     *
     * @return static The singleton AdminNoticesController instance.
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
            self::$instance = new static();

        return self::$instance;
    }

    /**
     * Remove all persistent plugin notices from the notice store.
     *
     * @return void
     */
    public static function resetAllNotices()
    {
        Notices::resetNotices();
    }

		/**
		 * Remove notices that were used in older plugin versions but are no longer relevant.
		 *
		 * Cleans up `MSG_FEATURE_SMARTCROP`, `MSG_FEATURE_HEIC`, and `MSG_AVIF_ERROR`
		 * so stale notices do not linger after an upgrade.
		 *
		 * @return void
		 */
		public static function resetOldNotices()
		{
			Notices::removeNoticeByID('MSG_FEATURE_SMARTCROP');
			Notices::removeNoticeByID('MSG_FEATURE_HEIC');
			Notices::removeNoticeByID('MSG_AVIF_ERROR');

      // This one is not old,
      Notices::removeNoticeByID('MSG_AVIF_ERROR');
		}

    /** Triggered when plugin is activated */
    public static function resetCompatNotice()
    {
        Notices::removeNoticeByID('MSG_COMPAT');
    }

    /**
     * Remove all API-key-related notices from the notice store.
     *
     * Clears `MSG_NO_APIKEY`, `MSG_NO_APIKEY_REPEAT`, and `MSG_NO_APIKEY_REPEAT_LONG`.
     *
     * @return void
     */
    public static function resetAPINotices()
    {
        Notices::removeNoticeByID('MSG_NO_APIKEY');
        Notices::removeNoticeByID('MSG_NO_APIKEY_REPEAT');
        Notices::removeNoticeByID('MSG_NO_APIKEY_REPEAT_LONG');
    }

    /**
     * Remove all quota-exceeded notices from the notice store.
     *
     * Clears `MSG_UPGRADE_MONTH`, `MSG_UPGRADE_BULK`, and `MSG_QUOTA_REACHED`.
     *
     * @return void
     */
    public static function resetQuotaNotices()
    {
        Notices::removeNoticeByID('MSG_UPGRADE_MONTH');
        Notices::removeNoticeByID('MSG_UPGRADE_BULK');
        Notices::removeNoticeByID('MSG_QUOTA_REACHED');
    }

    /**
     * Remove third-party integration notices from the notice store.
     *
     * Clears `MSG_INTEGRATION_NGGALLERY`.
     *
     * @return void
     */
    public static function resetIntegrationNotices()
    {
        Notices::removeNoticeByID('MSG_INTEGRATION_NGGALLERY');
    }

    /**
     * Remove the legacy-conversion notice from the notice store.
     *
     * Clears `MSG_CONVERT_LEGACY`.
     *
     * @return void
     */
    public static function resetLegacyNotice()
    {
        Notices::removeNoticeByID('MSG_CONVERT_LEGACY');
    }

    /**
     * Return whether the controller is operating in silent mode.
     *
     * In silent mode (`SHORTPIXEL_SILENT_MODE === true`), persistent notices are
     * suppressed; only the transient display hook remains active.
     *
     * @return bool True when silent mode is active.
     */
    public function isSilentMode()
    {
       return $this->silent_mode;
    }

    /**
     * Output all queued notices that are appropriate for the current admin screen.
     *
     * Hooked to `admin_notices` (priority 50) and `admin_footer`.  On non-ShortPixel
     * screens only the dashboard is allowed through (where plugin notices may appear).
     *
     * Loads the shortpixel-notices and notices-module stylesheets on the dashboard.
     * For each displayable notice, checks screen scope and the current user's
     * `noticeIsAllowed()` capability before outputting HTML.  Also enqueues the knob
     * and shortpixel scripts for quota-exceeded notices.
     *
     * Calls `NoticeController::update()` after rendering to dismiss one-shot notices.
     *
     * @return void
     */
    public function displayNotices()
    {
        if (! \wpSPIO()->env()->is_screen_to_use)
        {
            if(get_current_screen()->base !== 'dashboard') // ugly exception for dashboard.
            {
                return; // suppress all when not our screen.
            }
            else {
              \wpSPIO()->load_style('shortpixel-notices');
              \wpSPIO()->load_style('notices-module');
            }
        }

        $access = AccessModel::getInstance();
        $screen = get_current_screen();
        $screen_id = \wpSPIO()->env()->screen_id;
        $is_our_screen = \wpSPIO()->env()->is_our_screen; 

        $noticeControl = Notices::getInstance();


        if ($noticeControl->countNotices() > 0)
        {

            $notices = $noticeControl->getNoticesForDisplay();
            if (count($notices) > 0)
            {

                foreach($notices as $notice)
                {
                    
                    if ($notice->checkScreen($screen_id) === false)
                    {
                        continue;
                    }
                    // Bit hacky; limit global messages to our screens. Next step here @todo would be to include a remotenotice flag in the noticemodel
                    elseif (is_string($notice->getID()) && strpos($notice->getID(), 'Global') !== false && false === $is_our_screen)
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


                    if ($notice->getID() == 'MSG_QUOTA_REACHED' || $notice->getID() == 'MSG_UPGRADE_MONTH')
                    {
                        // This is still needed
                        wp_enqueue_script('jquery.knob.min.js');
                        wp_enqueue_script('shortpixel');
                    }
                }
            }
        }
        $noticeControl->update(); // puts views, and updates
    }

    /**
     * Hook callback that loads and evaluates notice models on admin page load.
     *
     * Runs at priority 5 on `admin_notices`, before `displayNotices()`.  Skips
     * non-ShortPixel screens (with a dashboard exception) and delegates to
     * `loadNotices()` to run each notice model and fetch remote notices.
     *
     * @return void
     */
    public function check_admin_notices()
    {
        if (! \wpSPIO()->env()->is_screen_to_use)
        {
            if(get_current_screen()->base !== 'dashboard') // ugly exception for dashboard.
                return; // suppress all when not our screen.
        }

       $this->loadNotices();
    }

    /**
     * Instantiate each notice model and register notice icons.
     *
     * Creates one instance of each class listed in `$definedNotices` and indexes
     * them by their notice key.  Also calls `NoticeController::loadIcons()` with the
     * plugin's custom robo-images.
     *
     * @return void
     */
    protected function initNotices()
    {
        foreach($this->definedNotices as $className)
        {
            $ns = '\ShortPixel\Model\AdminNotices\\' . $className;
            $class = new $ns();

            $this->adminNotices[$class->getKey()] = $class;
        }

        // Init the notice icons
        $noticeControl = Notices::getInstance();
        $noticeControl->loadIcons(array(
            'normal' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/slider.png', SHORTPIXEL_PLUGIN_FILE) . '">',
            'success' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-cool.png', SHORTPIXEL_PLUGIN_FILE) . '">',
            'warning' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-scared.png', SHORTPIXEL_PLUGIN_FILE) . '">',
            'error' => '<img class="short-pixel-notice-icon" src="' . plugins_url('res/img/robo-scared.png', SHORTPIXEL_PLUGIN_FILE) . '">',
        ));

    }

		/**
		 * Evaluate all registered notice models and process remote notices.
		 *
		 * Calls `load()` on each notice model so it can decide whether to queue
		 * itself, then delegates to `doRemoteNotices()`.
		 *
		 * @return void
		 */
		protected function loadNotices()
		{
			 foreach($this->adminNotices as $key => $class)
			 {
				  $class->load();
			 }

       $this->doRemoteNotices();

		}

    /**
     * Return a specific notice model by its notice key.
     *
     * @param string $key The notice key (e.g. 'MSG_QUOTA_REACHED').
     * @return \ShortPixel\Model\AdminNotices\AbstractNotice|false The notice model, or false if not found.
     */
    public function getNoticeByKey($key)
    {
        if (isset($this->adminNotices[$key]))
        {
            return $this->adminNotices[$key];
        }
        else {
            return false;
        }
    }

    /**
     * Return all registered notice model instances indexed by key.
     *
     * @return array<string, \ShortPixel\Model\AdminNotices\AbstractNotice> Map of key → notice model.
     */
    public function getAllNotices()
    {
        return $this->adminNotices;
    }


    /**
     * Queue the legacy-conversion notice if it has not been dismissed.
     *
     * Called by `MediaLibraryModel` when it detects legacy metadata format.
     * Adds the notice via `addManual()` only when the notice model exists and has not
     * already been dismissed by the user.
     *
     * @return void
     */
    public function invokeLegacyNotice()
    {
        $noticeModel = $this->getNoticeByKey('MSG_CONVERT_LEGACY');
        if (is_object($noticeModel) && false ==  $noticeModel->isDismissed())
        {
            $noticeModel->addManual();
        }
    }

    /**
     * Return the first active 'offer' type from the remote notices feed, or false.
     *
     * Fetches remote notices (cached via transient) and returns the first entry whose
     * `type` is 'offer' and whose `suppressedafter` date (if set) has not yet passed.
     * Returns false when no notices are available or no active offer is found.
     *
     * @return array|false Offer data array (keys lower-cased) or false when none found.
     */
    public function getRemoteOffer()
    {
       $notices = $this->get_remote_notices(); 
       
       if (false == $notices)
       {
            return false;
       }

       foreach($notices as $remoteNotice)
       {
           if (! isset($remoteNotice->type) || $remoteNotice->type !== 'offer')
           {
                continue; 
           }

           $offer = (array) $remoteNotice; 

           if (isset($offer['suppressedafter']))
           {
              $time = strtotime($offer['suppressedafter']); 
              if ($time === false || $time <= time() )
              {
                continue; 
              }
           }

           $offer = array_change_key_case($offer, CASE_LOWER);
           // Perhaps parse some here or not 
           return $offer;
       }

       return false;
    }

    /**
     * Fetch remote notices and queue any new ones as persistent admin notices.
     *
     * Skips execution on non-ShortPixel admin screens.  Iterates over the remote
     * notices feed (type 'offer' entries are skipped here — handled by `getRemoteOffer()`).
     * For entries not yet stored, creates a persistent notice via `Notices::addWarning()`,
     * `addError()`, or `addNormal()` (default) with a one-month TTL.
     *
     * @return void
     */
    protected function doRemoteNotices()
    {
         // Don't load on ajax, or other complicated things
        if (! \wpSPIO()->env()->is_screen_to_use)
        {
           return;
        }

        $notices = $this->get_remote_notices();

        if ($notices == false)
            return;

        foreach($notices as $remoteNotice)
        {
            if (! isset($remoteNotice->id) && ! isset($remoteNotice->message))
                return;

            if (! isset($remoteNotice->type))
                $remoteNotice->type = 'notice';

            // Ignore this type in the regular notices. 
            if ('offer' == $remoteNotice->type)
            {
                continue;  
            }

            if (property_exists($remoteNotice, 'message'))
            {
                $message = esc_html($remoteNotice->message);
            }
            elseif (property_exists($remoteNotice, 'Message'))
            {
                $message = esc_html($remoteNotice->Message);
            }
            else
            {
                 continue; // no message no notice.
            }

            if (property_exists($remoteNotice, 'link'))
            {
                $link = $remoteNotice->link; 
               // $message_link = $remoteNotice->message_link; 

                if (substr_count($message, '%s') == 2)
                {
                     $message = sprintf($message, '<a href="' . $link . '" target="_blank">', '</a>'); 
                }
            }
            

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


    /**
     * Fetch the remote notices feed from the ShortPixel API (cached for one day).
     *
     * Builds the request URL from the API endpoint, current API key, plugin version,
     * and target identifier.  Uses a WordPress transient for caching; in debug mode
     * the TTL is reduced to 180 seconds.  Stores `false` on HTTP error so repeated
     * failures do not hammer the API.
     *
     * @return array|false Array of notice objects from the API, or false on failure / empty response.
     */
    private function get_remote_notices()
    {
        $transient_name = 'shortpixel_remote_notice';
        $transient_duration = DAY_IN_SECONDS;

        if (\wpSPIO()->env()->is_debug)
            $transient_duration = 180;

        $keyControl = new ApiKeyController();
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
                Log::addError('Error in fetching Remote Notices!', $notices_response);
                set_transient( $transient_name, false, $transient_duration );
            }
        }

        return $notices;
    }

    /**
     * Display a contextual upgrade notice below the plugin's update entry in the plugins list.
     *
     * Hooked to `in_plugin_update_message-{plugin}` (priority 50).  Fetches the
     * update notice text from the plugin's readme.txt and renders it inside an inline
     * WP notice `<div>` when non-empty.
     *
     * @param array    $data     Plugin data array provided by WordPress.
     * @param object   $response WordPress update response object; `$response->new_version` is used.
     * @return void
     */
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

            $match = $matches[1];
            $versions = [];
            $current_version = null;
            $current_message = '';

            foreach (preg_split('/\R/', trim($match)) as $line)
            {
                if (preg_match('/^\s*=\s*([^=\r\n]+?)\s*=\s*$/', $line, $version_match))
                {
                    if ($current_version !== null)
                    {
                        $versions[$current_version] = trim($current_message);
                    }

                    $current_version = trim($version_match[1]);
                    $current_message = '';
                }
                elseif ($current_version !== null)
                {
                    $current_message .= $line . "\n";
                }
            }

            if ($current_version !== null)
            {
                $versions[$current_version] = trim($current_message);
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
