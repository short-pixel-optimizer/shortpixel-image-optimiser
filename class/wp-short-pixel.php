<?php

use ShortPixel\DebugItem as DebugItem;
use ShortPixel\ShortPixelLogger as Log;
use ShortPixel\NoticeController as Notice;


class WPShortPixel {

    const BULK_EMPTY_QUEUE = 0;

    private $_apiInterface = null;
    private $_settings = null;
    private $prioQ = null;
    private $view = null;
    private $thumbnailsRegenerating = array();

    private $hasNextGen = false;
    private $spMetaDao = null;

    private $jsSuffix = '.min.js';

    private $timer;

    public static $PROCESSABLE_EXTENSIONS = array('jpg', 'jpeg', 'gif', 'png', 'pdf');

    public function __construct() {
        $this->timer = time();

        if (SHORTPIXEL_DEBUG === true) {
            $this->jsSuffix = '.js'; //use unminified versions for easier debugging
        }

        load_plugin_textdomain('shortpixel-image-optimiser', false, plugin_basename(dirname( SHORTPIXEL_PLUGIN_FILE )).'/lang');

        $isAdminUser = current_user_can( 'manage_options' );

        $this->_settings = new WPShortPixelSettings();
        $this->_apiInterface = new ShortPixelAPI($this->_settings);
        $this->cloudflareApi = new ShortPixelCloudFlareApi($this->_settings->cloudflareEmail, $this->_settings->cloudflareAuthKey, $this->_settings->cloudflareZoneID);
        $this->hasNextGen = ShortPixelNextGenAdapter::hasNextGen();
        $this->spMetaDao = new ShortPixelCustomMetaDao(new WpShortPixelDb(), $this->_settings->excludePatterns);
        $this->prioQ = new ShortPixelQueue($this, $this->_settings);
        $this->view = new ShortPixelView($this);

        $controllerClass = ShortPixelTools::namespaceit('ShortPixelController');
        $controllerClass::init(); // load all subclassed controllers.

        /*$debugClass = ShortPixelTools::namespaceit('Debug');
        $debugClass->init();  */

        define('QUOTA_EXCEEDED', $this->view->getQuotaExceededHTML());

        if( !defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES')) {
            if(is_plugin_active('envira-gallery/envira-gallery.php') || is_plugin_active('soliloquy-lite/soliloquy-lite.php') || is_plugin_active('soliloquy/soliloquy.php')) {
                define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', '_c,_tl,_tr,_br,_bl');
            }
            elseif(defined('SHORTPIXEL_CUSTOM_THUMB_SUFFIX')) {
                define('SHORTPIXEL_CUSTOM_THUMB_SUFFIXES', SHORTPIXEL_CUSTOM_THUMB_SUFFIX);
            }
        }

        $this->setDefaultViewModeList();//set default mode as list. only @ first run

        //add hook for image upload processing
        //add_filter( 'wp_generate_attachment_metadata', array( &$this, 'handleMediaLibraryImageUpload' ), 10, 2 ); // now external
        add_filter( 'plugin_action_links_' . plugin_basename(SHORTPIXEL_PLUGIN_FILE), array(&$this, 'generatePluginLinks'));//for plugin settings page

        //add_action( 'admin_footer', array(&$this, 'handleImageProcessing'));

        //Media custom column
        add_filter( 'manage_media_columns', array( &$this, 'columns' ) );//add media library column header
        add_action( 'manage_media_custom_column', array( &$this, 'generateCustomColumn' ), 10, 2 );//generate the media library column
        //Sort and filter on ShortPixel Compression column
        add_filter( 'manage_upload_sortable_columns', array( &$this, 'columnRegisterSortable') );
        add_filter( 'request', array( &$this, 'columnOrderFilterBy') );
        add_action('restrict_manage_posts', array( &$this, 'mediaAddFilterDropdown'));
        //Edit media meta box
        add_action( 'add_meta_boxes', array( &$this, 'shortpixelInfoBox') );
        //for cleaning up the WebP images when an attachment is deleted
        add_action( 'delete_attachment', array( &$this, 'onDeleteImage') );

        //for NextGen
        if($this->_settings->hasCustomFolders) {
            add_filter( 'ngg_manage_images_columns', array( &$this, 'nggColumns' ) );
            add_filter( 'ngg_manage_images_number_of_columns', array( &$this, 'nggCountColumns' ) );
            add_filter( 'ngg_manage_images_column_7_header', array( &$this, 'nggColumnHeader' ) );
            add_filter( 'ngg_manage_images_column_7_content', array( &$this, 'nggColumnContent' ) );
            // hook on the NextGen gallery list update
            add_action('ngg_update_addgallery_page', array( &$this, 'addNextGenGalleriesToCustom'));
        }

        // integration with WP/LR Sync plugin
        add_action( 'wplr_update_media', array( &$this, 'onWpLrUpdateMedia' ), 10, 2);

        //custom hook
        add_action( 'shortpixel-optimize-now', array( &$this, 'optimizeNowHook' ), 10, 1);

        add_action( 'shortpixel-thumbnails-before-regenerate', array( &$this, 'thumbnailsBeforeRegenerateHook' ), 10, 1);
        add_action( 'shortpixel-thumbnails-regenerated', array( &$this, 'thumbnailsRegeneratedHook' ), 10, 4);
        add_filter( 'shortpixel_get_backup', array( &$this, 'shortpixelGetBackupFilter' ), 10, 1 );

        if($isAdminUser) {
            //add settings page
            //add_action( 'admin_menu', array( &$this, 'registerSettingsPage' ) );//display SP in Settings menu
            add_action( 'admin_menu', array( &$this, 'registerAdminPage' ) );

            add_action('wp_ajax_shortpixel_browse_content', array(&$this, 'browseContent'));
            add_action('wp_ajax_shortpixel_get_backup_size', array(&$this, 'getBackupSize'));
            add_action('wp_ajax_shortpixel_get_comparer_data', array(&$this, 'getComparerData'));

            add_action('wp_ajax_shortpixel_new_api_key', array(&$this, 'newApiKey'));
            add_action('wp_ajax_shortpixel_propose_upgrade', array(&$this, 'proposeUpgrade'));

            add_action( 'delete_attachment', array( &$this, 'handleDeleteAttachmentInBackup' ) );
            add_action( 'load-upload.php', array( &$this, 'handleCustomBulk'));

            //backup restore
            add_action('admin_action_shortpixel_restore_backup', array(&$this, 'handleRestoreBackup'));
            //reoptimize with a different algorithm (losless/lossy)
            add_action('wp_ajax_shortpixel_redo', array(&$this, 'handleRedo'));
            //optimize thumbnails
            add_action('wp_ajax_shortpixel_optimize_thumbs', array(&$this, 'handleOptimizeThumbs'));

            //toolbar notifications
            add_action( 'admin_bar_menu', array( &$this, 'toolbar_shortpixel_processing'), 999 );
            //deactivate plugin
            add_action( 'admin_post_shortpixel_deactivate_plugin', array(&$this, 'deactivatePlugin'));
            //only if the key is not yet valid or the user hasn't bought any credits.
            $stats = $this->_settings->currentStats;
            $totalCredits = isset($stats["APICallsQuotaNumeric"]) ? $stats['APICallsQuotaNumeric'] + $stats['APICallsQuotaOneTimeNumeric'] : 0;
            if(true || !$this->_settings->verifiedKey || $totalCredits < 4000) {
                require_once 'view/shortpixel-feedback.php';
                new ShortPixelFeedback( SHORTPIXEL_PLUGIN_FILE, 'shortpixel-image-optimiser', $this->_settings->apiKey, $this);
            }
        }

        //automatic optimization
        add_action( 'wp_ajax_shortpixel_image_processing', array( &$this, 'handleImageProcessing') );
        //manual optimization
        add_action( 'wp_ajax_shortpixel_manual_optimization', array(&$this, 'handleManualOptimization'));
        //check status
        add_action( 'wp_ajax_shortpixel_check_status', array(&$this, 'checkStatus'));
        //dismiss notices
        add_action( 'wp_ajax_shortpixel_dismiss_notice', array(&$this, 'dismissAdminNotice'));
        add_action( 'wp_ajax_shortpixel_dismiss_media_alert', array(&$this, 'dismissMediaAlert'));
        //check quota
        add_action('wp_ajax_shortpixel_check_quota', array(&$this, 'handleCheckQuota'));
        add_action('admin_action_shortpixel_check_quota', array(&$this, 'handleCheckQuota'));
        //This adds the constants used in PHP to be available also in JS
        add_action( 'admin_enqueue_scripts', array( $this, 'shortPixelJS') );
        add_action( 'admin_footer', array($this, 'admin_footer_js') );
        add_action( 'admin_head', array( $this, 'headCSS') );

        if($this->_settings->frontBootstrap && shortPixelCheckQueue()) {
            //only if we have something in the queue - usually we never get here if the queue is empty but for some hooks...
            //also need to have it in the front footer then
            add_action( 'wp_footer', array( &$this, 'shortPixelJS') );
            //need to add the nopriv action for when items exist in the queue and no user is logged in
            add_action( 'wp_ajax_nopriv_shortpixel_image_processing', array( &$this, 'handleImageProcessing') );
        }
        //register a method to display admin notices if necessary
        add_action('admin_notices', array( &$this, 'displayAdminNotices'));

        $this->migrateBackupFolder();

        // [BS] Quite dangerous to do this in any constructor. Can hit if request is ajax to name something
        // @todo This is intended to run only once, on activation. ( it does )
        if(!$this->_settings->redirectedSettings && !$this->_settings->verifiedKey && (!function_exists("is_multisite") || !is_multisite())) {
            $this->_settings->redirectedSettings = 1;
            wp_redirect(admin_url("options-general.php?page=wp-shortpixel-settings"));
            exit();
        }

    }

    //handling older
    public function WPShortPixel() {
        $this->__construct();
    }

    // @hook admin menu
    // @todo move to plugin class
    public function registerSettingsPage() {

    }

    // @hook admin menu
    // @todo move to plugin class
    function registerAdminPage( ) {
        if($this->spMetaDao->hasFoldersTable() && count($this->spMetaDao->getFolders())) {
            /*translators: title and menu name for the Other media page*/
            add_media_page( __('Other Media Optimized by ShortPixel','shortpixel-image-optimiser'), __('Other Media','shortpixel-image-optimiser'), 'edit_others_posts', 'wp-short-pixel-custom', array( &$this, 'listCustomMedia' ) );
        }
        /*translators: title and menu name for the Bulk Processing page*/
        add_media_page( __('ShortPixel Bulk Process','shortpixel-image-optimiser'), __('Bulk ShortPixel','shortpixel-image-optimiser'), 'edit_others_posts', 'wp-short-pixel-bulk', array( &$this, 'bulkProcess' ) );
    }

    public static function shortPixelActivatePlugin()//reset some params to avoid trouble for plugins that were activated/deactivated/activated
    {
        self::shortPixelDeactivatePlugin();
        if(SHORTPIXEL_RESET_ON_ACTIVATE === true && WP_DEBUG === true) { //force reset plugin counters, only on specific occasions and on test environments
            WPShortPixelSettings::debugResetOptions();

            $settings = new WPShortPixelSettings();
            $spMetaDao = new ShortPixelCustomMetaDao(new WpShortPixelDb(), $settings->excludePatterns);
            $spMetaDao->dropTables();
        }
        if(WPShortPixelSettings::getOpt('deliverWebp') == 3) {
            self::alterHtaccess(); //add the htaccess lines
        }
        WPShortPixelSettings::onActivate();
    }

    public static function shortPixelDeactivatePlugin()//reset some params to avoid trouble for plugins that were activated/deactivated/activated
    {
        ShortPixelQueue::resetBulk();
        ShortPixelQueue::resetPrio();
        WPShortPixelSettings::onDeactivate();
        self::alterHtaccess(true);
        @unlink(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log");
    }

    public static function shortPixelUninstallPlugin()//reset some params to avoid trouble for plugins that were activated/deactivated/activated
    {
        $settings = new WPShortPixelSettings();
        if($settings->removeSettingsOnDeletePlugin == 1) {
            WPShortPixelSettings::debugResetOptions();
            insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '');
        }
    }

    public function getConflictingPlugins() {
        $conflictPlugins = array(
            'WP Smush - Image Optimization'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'wp-smushit/wp-smush.php',
                        'page'=>'wp-smush-bulk'
                ),
            'Imagify Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'imagify/imagify.php',
                        'page'=>'imagify'
                ),
            'Compress JPEG & PNG images (TinyPNG)'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'tiny-compress-images/tiny-compress-images.php',
                        'page'=>'tinify'
                ),
            'Kraken.io Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'kraken-image-optimizer/kraken.php',
                        'page'=>'wp-krakenio'
                ),
            'Optimus - WordPress Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'optimus/optimus.php',
                        'page'=>'optimus'
                ),
            'EWWW Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'ewww-image-optimizer/ewww-image-optimizer.php',
                        'page'=>'ewww-image-optimizer%2F'
                ),
            'EWWW Image Optimizer Cloud'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php',
                        'page'=>'ewww-image-optimizer-cloud%2F'
                ),
            'ImageRecycle pdf & image compression'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'imagerecycle-pdf-image-compression/wp-image-recycle.php',
                        'page'=>'option-image-recycle'
                ),
            'CheetahO Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'cheetaho-image-optimizer/cheetaho.php',
                        'page'=>'cheetaho'
                ),
            'Zara 4 Image Compression'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'zara-4/zara-4.php',
                        'page'=>'zara-4'
                ),
            'CW Image Optimizer'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'cw-image-optimizer/cw-image-optimizer.php',
                        'page'=>'cw-image-optimizer'
                ),
            'Simple Image Sizes'
                => array(
                        'action'=>'Deactivate',
                        'data'=>'simple-image-sizes/simple_image_sizes.php'
                ),
               //DEACTIVATED TEMPORARILY - it seems that the customers get scared.
            /* 'Jetpack by WordPress.com - The Speed up image load times Option'
                => array(
                        'action'=>'Change Setting',
                        'data'=>'jetpack/jetpack.php',
                        'href'=>'admin.php?page=jetpack#/settings'
                )
            */
        );
        if($this->_settings->processThumbnails) {
            $conflictPlugins = array_merge($conflictPlugins, array(
                'Regenerate Thumbnails: recreating image files may require re-optimization of the resulting thumbnails, even if they were previously optimized.'
                    => array(
                            'action'=>'Deactivate',
                            'data'=>'regenerate-thumbnails/regenerate-thumbnails.php',
                            'page'=>'regenerate-thumbnails'
                    ),
                'Force Regenerate Thumbnails: recreating image files may require re-optimization of the resulting thumbnails, even if they were previously optimized.'
                    => array(
                            'action'=>'Deactivate',
                            'data'=>'force-regenerate-thumbnails/force-regenerate-thumbnails.php',
                            'page'=>'force-regenerate-thumbnails'
                    )
            ));
        }
        $found = array();
        foreach($conflictPlugins as $name => $path) {
            $action = ( isset($path['action']) ) ? $path['action'] : null;
            $data = ( isset($path['data']) ) ? $path['data'] : null;
            $href = ( isset($path['href']) ) ? $path['href'] : null;
            $page = ( isset($path['page']) ) ? $path['page'] : null;
            if(is_plugin_active($data)) {
                if( $data == 'jetpack/jetpack.php' ){
                    $jetPackPhoton = get_option('jetpack_active_modules') ? in_array('photon', get_option('jetpack_active_modules')) : false;
                    if( !$jetPackPhoton ){ continue; }
                }
                $found[] = array( 'name' => $name, 'action'=> $action, 'path' => $data, 'href' => $href , 'page' => $page );
            }
        }
        return $found;
    }

    /** Displays notices to admin, if there are any
    * TODO - Probably should be a controller
    */
    public function displayAdminNotices() {
        if(!ShortPixelQueue::testQ()) {
            ShortPixelView::displayActivationNotice('fileperms');
        }
        if($this->catchNotice()) { //notices for errors like for example a failed restore notice - these are one time so display them with priority.
            return;
        }
        $dismissed = $this->_settings->dismissedNotices ? $this->_settings->dismissedNotices : array();
        $this->_settings->dismissedNotices = $dismissed;

        if(!$this->_settings->verifiedKey) {
            $now = time();
            $act = $this->_settings->activationDate ? $this->_settings->activationDate : $now;
            if($this->_settings->activationNotice && $this->_settings->redirectedSettings >= 2) {
                ShortPixelView::displayActivationNotice();
                $this->_settings->activationNotice = null;
            }
            if( ($now > $act + 7200)  && !isset($dismissed['2h'])) {
                ShortPixelView::displayActivationNotice('2h');
            } else if( ($now > $act + 72 * 3600) && !isset($dismissed['3d'])) {
                ShortPixelView::displayActivationNotice('3d');
            }
        }
        if(!isset($dismissed['compat'])) {
            $conflictPlugins = $this->getConflictingPlugins();
            if(count($conflictPlugins)) {
                ShortPixelView::displayActivationNotice('compat', $conflictPlugins);
                return;
            }
        }
        if(   !isset($dismissed['unlisted']) && !$this->_settings->optimizeUnlisted
           && isset($this->_settings->currentStats['foundUnlistedThumbs']) && is_array($this->_settings->currentStats['foundUnlistedThumbs'])) {
            ShortPixelView::displayActivationNotice('unlisted', $this->_settings->currentStats['foundUnlistedThumbs']);
            return;
        }
        //if(false)
        $currentStats = $this->_settings->currentStats;
        if(!is_array($currentStats) || isset($_GET['checkquota']) || isset($currentStats["quotaData"])) {
            $this->getQuotaInformation();
        }
        if($this->_settings->verifiedKey && !$this->_settings->quotaExceeded
           && (!isset($dismissed['upgmonth']) || !isset($dismissed['upgbulk'])) && isset($this->_settings->currentStats['optimizePdfs'])
           && $this->_settings->currentStats['optimizePdfs'] == $this->_settings->optimizePdfs ) {
            $screen = get_current_screen();
            $stats = $this->countAllIfNeeded($this->_settings->currentStats, 86400);
            $quotaData = $stats;

            //this is for bulk page - alert on the total credits for total images
            if( !isset($dismissed['upgbulk']) && $screen && $screen->id == 'media_page_wp-short-pixel-bulk' && $this->bulkUpgradeNeeded($stats)) {
                //looks like the user hasn't got enough credits to bulk process all media library
                ShortPixelView::displayActivationNotice('upgbulk', array('filesTodo' => $stats['totalFiles'] - $stats['totalProcessedFiles'],
                                                        'quotaAvailable' => max(0, $quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'])));
            }
            //consider the monthly plus 1/6 of the available one-time credits.
            elseif(!isset($dismissed['upgmonth']) && $this->monthlyUpgradeNeeded($stats)) {
                //looks like the user hasn't got enough credits to process the monthly images, display a notice telling this
                ShortPixelView::displayActivationNotice('upgmonth', array('monthAvg' => $this->getMonthAvg($stats), 'monthlyQuota' => $quotaData['APICallsQuotaNumeric']));
            }
        }
    }

    public function dismissAdminNotice() {
        $noticeId = preg_replace('|[^a-z0-9]|i', '', $_GET['notice_id']);
        $dismissed = $this->_settings->dismissedNotices ? $this->_settings->dismissedNotices : array();
        $dismissed[$noticeId] = true;
        $this->_settings->dismissedNotices = $dismissed;
        if($_GET['notice_id'] == 'unlisted' && isset($_GET['notice_data']) && $_GET['notice_data'] == 'true') {
            $this->_settings->optimizeUnlisted = 1;
        }
        die(json_encode(array("Status" => 'success', "Message" => 'Notice ID: ' . $noticeId . ' dismissed')));
    }

    public function dismissMediaAlert() {
        $this->_settings->mediaAlert = 1;
        die(json_encode(array("Status" => 'success', "Message" => __('Media alert dismissed','shortpixel-image-optimiser'))));
    }

    protected function getMonthAvg($stats) {
        for($i = 4, $count = 0; $i>=1; $i--) {
            if($count == 0 && $stats['totalM' . $i] == 0) continue;
            $count++;
        }
        return ($stats['totalM1'] + $stats['totalM2'] + $stats['totalM3'] + $stats['totalM4']) / max(1,$count);
    }

    protected function monthlyUpgradeNeeded($quotaData) {
        return isset($quotaData['APICallsQuotaNumeric']) && $this->getMonthAvg($quotaData) > $quotaData['APICallsQuotaNumeric'] + ($quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'])/6 + 20;
    }

    protected function bulkUpgradeNeeded($stats) {
        $quotaData = $stats;
        return $stats['totalFiles'] - $stats['totalProcessedFiles'] > $quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] - $quotaData['APICallsMadeNumeric'] - $quotaData['APICallsMadeOneTimeNumeric'];
    }

    //set default move as "list". only set once, it won't try to set the default mode again.
    public function setDefaultViewModeList()
    {
        if($this->_settings->mediaLibraryViewMode === false)
        {
            $this->_settings->mediaLibraryViewMode = 1;
            $currentUserID = false;
            if ( function_exists('wp_get_current_user') ) {
                $current_user = wp_get_current_user();
                $currentUserID = $current_user->ID;
                update_user_meta($currentUserID, "wp_media_library_mode", "list");
            }
        }

    }

    static function log($message, $force = false) {
        Log::addInfo($message);
        /*
        if (SHORTPIXEL_DEBUG === true || $force) {
            if (is_array($message) || is_object($message)) {
                self::doLog(print_r($message, true), $force);
            } else {
                self::doLog($message, $force);
            }
        } */
    }

    /** [TODO] This should report to the Shortpixel Logger **/
    static protected function doLog($message, $force = false) {

        /*if(defined('SHORTPIXEL_DEBUG_TARGET') || $force) {
                file_put_contents(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log", '[' . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
        } else {
            error_log($message);
        }*/
    }

    function headCSS() {
        echo('<style>.shortpixel-hide {display:none;}</style>');
    }

    /** @todo Plugin init class. Try to get rid of inline JS. Also still loads on all WP pages, prevent that. */
    function shortPixelJS() {

        //require_once(ABSPATH . 'wp-admin/includes/screen.php');
        if(function_exists('get_current_screen')) {
            $screen = get_current_screen();

             if(is_object($screen)) {


                wp_enqueue_style('short-pixel-bar.min.css', plugins_url('/res/css/short-pixel-bar.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);
                if( in_array($screen->id, array('attachment', 'upload', 'settings_page_wp-shortpixel', 'media_page_wp-short-pixel-bulk', 'media_page_wp-short-pixel-custom'))) {
                    wp_enqueue_style('short-pixel.min.css', plugins_url('/res/css/short-pixel.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);
                    //modal - used in settings for selecting folder
                    wp_enqueue_style('short-pixel-modal.min.css', plugins_url('/res/css/short-pixel-modal.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

                    // @todo Might need to be removed later on
                    wp_register_style('shortpixel-admin', plugins_url('/res/css/shortpixel-admin.css', SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
                    wp_enqueue_style('shortpixel-admin');
                }
            }
        }


        wp_register_script('shortpixel' . $this->jsSuffix, plugins_url('/res/js/shortpixel' . $this->jsSuffix,SHORTPIXEL_PLUGIN_FILE), array('jquery'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);


        // Using an Array within another Array to protect the primitive values from being cast to strings
        $ShortPixelConstants = array(array(
            'STATUS_SUCCESS'=>ShortPixelAPI::STATUS_SUCCESS,
            'STATUS_EMPTY_QUEUE'=>self::BULK_EMPTY_QUEUE,
            'STATUS_ERROR'=>ShortPixelAPI::STATUS_ERROR,
            'STATUS_FAIL'=>ShortPixelAPI::STATUS_FAIL,
            'STATUS_QUOTA_EXCEEDED'=>ShortPixelAPI::STATUS_QUOTA_EXCEEDED,
            'STATUS_SKIP'=>ShortPixelAPI::STATUS_SKIP,
            'STATUS_NO_KEY'=>ShortPixelAPI::STATUS_NO_KEY,
            'STATUS_RETRY'=>ShortPixelAPI::STATUS_RETRY,
            'STATUS_QUEUE_FULL'=>ShortPixelAPI::STATUS_QUEUE_FULL,
            'STATUS_MAINTENANCE'=>ShortPixelAPI::STATUS_MAINTENANCE,
            'WP_PLUGIN_URL'=>plugins_url( '', SHORTPIXEL_PLUGIN_FILE ),
            'WP_ADMIN_URL'=>admin_url(),
            'API_KEY'=> (defined("SHORTPIXEL_HIDE_API_KEY" )  || !is_admin() ) ? '' : $this->_settings->apiKey,
            'DEFAULT_COMPRESSION'=>0 + intval($this->_settings->compressionType), // no int can happen when settings are empty still
            'MEDIA_ALERT'=>$this->_settings->mediaAlert ? "done" : "todo",
            'FRONT_BOOTSTRAP'=>$this->_settings->frontBootstrap && (!isset($this->_settings->lastBackAction) || (time() - $this->_settings->lastBackAction > 600)) ? 1 : 0,
            'AJAX_URL'=>admin_url('admin-ajax.php'),
            'AFFILIATE'=>self::getAffiliateSufix()
        ));

        if (Log::isManualDebug() )
        {
          Log::addInfo('Ajax Manual Debug Mode');
          $logLevel = Log::getLogLevel();
          $ShortPixelConstants[0]['AJAX_URL'] = admin_url('admin-ajax.php?SHORTPIXEL_DEBUG=' . $logLevel);
        }

        $jsTranslation = array(
                'optimizeWithSP' => __( 'Optimize with ShortPixel', 'shortpixel-image-optimiser' ),
                'redoLossy' => __( 'Re-optimize Lossy', 'shortpixel-image-optimiser' ),
                'redoGlossy' => __( 'Re-optimize Glossy', 'shortpixel-image-optimiser' ),
                'redoLossless' => __( 'Re-optimize Lossless', 'shortpixel-image-optimiser' ),
                'restoreOriginal' => __( 'Restore Originals', 'shortpixel-image-optimiser' ),
                'changeMLToListMode' => __( 'In order to access the ShortPixel Optimization actions and info, please change to {0}List View{1}List View{2}Dismiss{3}', 'shortpixel-image-optimiser' ),
                'alertOnlyAppliesToNewImages' => __( 'This type of optimization will apply to new uploaded images. Images that were already processed will not be re-optimized unless you restart the bulk process.', 'shortpixel-image-optimiser' ),
                'areYouSureStopOptimizing' => __( 'Are you sure you want to stop optimizing the folder {0}?', 'shortpixel-image-optimiser' ),
                'reducedBy' => __( 'Reduced by', 'shortpixel-image-optimiser' ),
                'bonusProcessing' => __( 'Bonus processing', 'shortpixel-image-optimiser' ),
                'plusXthumbsOpt' => __( '+{0} thumbnails optimized', 'shortpixel-image-optimiser' ),
                'plusXretinasOpt' => __( '+{0} Retina images optimized', 'shortpixel-image-optimiser' ),
                'optXThumbs' => __( 'Optimize {0} thumbnails', 'shortpixel-image-optimiser' ),
                'reOptimizeAs' => __( 'Reoptimize {0}', 'shortpixel-image-optimiser' ),
                'restoreBackup' => __( 'Restore backup', 'shortpixel-image-optimiser' ),
                'getApiKey' => __( 'Get API Key', 'shortpixel-image-optimiser' ),
                'extendQuota' => __( 'Extend Quota', 'shortpixel-image-optimiser' ),
                'check__Quota' => __( 'Check&nbsp;&nbsp;Quota', 'shortpixel-image-optimiser' ),
                'retry' => __( 'Retry', 'shortpixel-image-optimiser' ),
                'thisContentNotProcessable' => __( 'This content is not processable.', 'shortpixel-image-optimiser' ),
                'imageWaitOptThumbs' => __( 'Image waiting to optimize thumbnails', 'shortpixel-image-optimiser' ),
                'pleaseDoNotSetLesserSize' => __( "Please do not set a {0} less than the {1} of the largest thumbnail which is {2}, to be able to still regenerate all your thumbnails in case you'll ever need this.", 'shortpixel-image-optimiser' ),
                'pleaseDoNotSetLesser1024' => __( "Please do not set a {0} less than 1024, to be able to still regenerate all your thumbnails in case you'll ever need this.", 'shortpixel-image-optimiser' ),
                'confirmBulkRestore' => __( "Are you sure you want to restore from backup all the images in your Media Library optimized with ShortPixel?", 'shortpixel-image-optimiser' ),
                'confirmBulkCleanup' => __( "Are you sure you want to cleanup the ShortPixel metadata info for the images in your Media Library optimized with ShortPixel? This will make ShortPixel 'forget' that it optimized them and will optimize them again if you re-run the Bulk Optimization process.", 'shortpixel-image-optimiser' ),
                'confirmBulkCleanupPending' => __( "Are you sure you want to cleanup the pending metadata?", 'shortpixel-image-optimiser' ),
                'alertDeliverWebPAltered' => __( "Warning: Using this method alters the structure of the rendered HTML code (IMG tags get included in PICTURE tags),\nwhich in some rare cases can lead to CSS/JS inconsistencies.\n\nPlease test this functionality thoroughly after activating!\n\nIf you notice any issue, just deactivate it and the HTML will will revert to the previous state.", 'shortpixel-image-optimiser' ),
                'alertDeliverWebPUnaltered' => __('This option will serve both WebP and the original image using the same URL, based on the web browser capabilities, please make sure you\'re serving the images from your server and not using a CDN which caches the images.', 'shortpixel-image-optimiser' ),
                );
        wp_localize_script( 'shortpixel' . $this->jsSuffix, '_spTr', $jsTranslation );
        wp_localize_script( 'shortpixel' . $this->jsSuffix, 'ShortPixelConstants', $ShortPixelConstants );
        wp_enqueue_script('shortpixel' . $this->jsSuffix);

        wp_enqueue_script('jquery.knob.min.js', plugins_url('/res/js/jquery.knob.min.js',SHORTPIXEL_PLUGIN_FILE) );
        wp_enqueue_script('jquery.tooltip.min.js', plugins_url('/res/js/jquery.tooltip.min.js',SHORTPIXEL_PLUGIN_FILE) );
        wp_enqueue_script('punycode.min.js', plugins_url('/res/js/punycode.min.js',SHORTPIXEL_PLUGIN_FILE) );
    }

    /** Outputs direct JS to the admin footer
    * @todo Find a better solution for this */
    public function admin_footer_js()
    {
      if(function_exists('get_current_screen')) {
          $screen = get_current_screen();
          if(is_object($screen)) {

              if( in_array($screen->id, array('attachment', 'upload', 'media_page_wp-short-pixel-custom'))) {
                  //output the comparer html
                  $this->view->outputComparerHTML();
                  //render a template of the list cell to be used by the JS
                  $this->view->renderListCell("__SP_ID__", 'imgOptimized', true, "__SP_THUMBS_TOTAL__", true, true,
                      array("__SP_FIRST_TYPE__", "__SP_SECOND_TYPE__"), "__SP_CELL_MESSAGE__", 'sp-column-actions-template');
              }
          }
      }
      ?>
      <script type="text/javascript" >
          //check after 10 seconds if ShortPixel initialized OK, if not, force the init (could happen if a JS error somewhere else stopped the JS execution).
          function delayedInit() {
              if(typeof ShortPixel !== "undefined") {
                  ShortPixel.init();
              } else {
                  setTimeout(delayedInit, 10000);
              }
          }
          setTimeout(delayedInit, 10000);
      </script>
      <?php
    }

    /** Displays an icon in the toolbar when processing images
    *   hook - admin_bar_menu
    *  @param Obj $wp_admin_bar
    */
    function toolbar_shortpixel_processing( $wp_admin_bar ) {

        $extraClasses = " shortpixel-hide";
        /*translators: toolbar icon tooltip*/
        $id = 'short-pixel-notice-toolbar';
        $tooltip = __('ShortPixel optimizing...','shortpixel-image-optimiser') . " " . __('Please do not close this admin page.','shortpixel-image-optimiser');
        $icon = "shortpixel.png";
        $successLink = $link = admin_url(current_user_can( 'edit_others_posts')? 'upload.php?page=wp-short-pixel-bulk' : 'upload.php');
        $blank = "";
        if($this->prioQ->processing()) {
            $extraClasses = " shortpixel-processing";
        }
        if($this->_settings->quotaExceeded && !isset($this->_settings->dismissedNotices['exceed'])) {
            $extraClasses = " shortpixel-alert shortpixel-quota-exceeded";
            /*translators: toolbar icon tooltip*/
            $id = 'short-pixel-notice-exceed';
            $tooltip = '';
            $exceedTooltip = __('ShortPixel quota exceeded. Click for details.','shortpixel-image-optimiser');
            //$link = "http://shortpixel.com/login/" . $this->_settings->apiKey;
            $link = "options-general.php?page=wp-shortpixel-settings";
            //$blank = '_blank';
            //$icon = "shortpixel-alert.png";
        }
        $lastStatus = $this->_settings->bulkLastStatus;
        if($lastStatus && $lastStatus['Status'] != ShortPixelAPI::STATUS_SUCCESS) {
            $extraClasses = " shortpixel-alert shortpixel-processing";
            $tooltip = $lastStatus['Message'];
            $successLink = $link = admin_url(current_user_can( 'edit_others_posts')? 'post.php?post=' . $lastStatus['ImageID'] . '&action=edit' : 'upload.php');
        }

        $args = array(
                'id'    => 'shortpixel_processing',
                'title' => '<div id="' . $id . '" title="' . $tooltip . '" ><img src="'
                         . plugins_url( 'res/img/'.$icon, SHORTPIXEL_PLUGIN_FILE ) . '" success-url="' . $successLink . '"><span class="shp-alert">!</span>'
                         .'<div class="cssload-container"><div class="cssload-speeding-wheel"></div></div></div>',
                'href'  => $link,
                'meta'  => array('target'=> $blank, 'class' => 'shortpixel-toolbar-processing' . $extraClasses)
        );
        $wp_admin_bar->add_node( $args );
        if($this->_settings->quotaExceeded && !isset($this->_settings->dismissedNotices['exceed'])) {
            $wp_admin_bar->add_node( array(
                'id'    => 'shortpixel_processing-title',
                'parent' => 'shortpixel_processing',
                'title' => $exceedTooltip,
                'href'  => $link
            ));
            $wp_admin_bar->add_node( array(
                'id'    => 'shortpixel_processing-dismiss',
                'parent' => 'shortpixel_processing',
                'title' => '<div style="text-align: right;">Dismiss</div>',
                'href'  => "#",
                'meta'  => array('onclick'=> 'dismissShortPixelNoticeExceed(event)')
            ));
        }
    }

    public function handleCustomBulk() {
        // 1. get the action
        $wp_list_table = _get_list_table('WP_Media_List_Table');
        $action = $wp_list_table->current_action();

        if(strpos($action, 'short-pixel-bulk') === 0 ) {
            // security check
            check_admin_referer('bulk-media');
            if(!is_array($_GET['media'])) {
                return;
            }
            $mediaIds = array_reverse($_GET['media']);
        }

        switch($action) {
            // 2. Perform the action
            case 'short-pixel-bulk':
                foreach( $mediaIds as $ID ) {

                    $meta = wp_get_attachment_metadata($ID);
                    if(!is_array($meta)) {
                        self::log('CUSTOM BULK META NOT AN ARRAY: ' . json_encode($meta));
                        $meta = ShortPixelMetaFacade::sanitizeMeta($meta, false);
                        if(isset($meta['previous_meta'])) {
                            self::log('COULDN\'T SANITIZE PROPERLY.');
                            continue;
                        }
                        else {
                            self::log('SANITIZED.');
                        }
                    }

                    if(!is_array($meta)) continue;
                    if(   (   !isset($meta['ShortPixel']) //never touched by ShortPixel
                           || (isset($meta['ShortPixel']['WaitingProcessing']) && $meta['ShortPixel']['WaitingProcessing'] == true))
                       && (!isset($meta['ShortPixelImprovement']) || $meta['ShortPixelImprovement'] == __('Optimization N/A','shortpixel-image-optimiser'))) {
                        $this->prioQ->push($ID);
                        if(!isset($meta['ShortPixel'])) {
                            $meta['ShortPixel'] = array();
                        }
                        $meta['ShortPixel']['WaitingProcessing'] = true;
                        //wp_update_attachment_metadata($ID, $meta);
                        update_post_meta($ID, '_wp_attachment_metadata', $meta);
                        ShortPixelMetaFacade::optimizationStarted($ID);
                    }
                }
                break;
            case 'short-pixel-bulk-lossy':
                $this->restoreAndQueueList($mediaIds, 'lossy');
                break;
            case 'short-pixel-bulk-glossy':
                $this->restoreAndQueueList($mediaIds, 'glossy');
                break;
            case 'short-pixel-bulk-lossless':
                $this->restoreAndQueueList($mediaIds, 'lossless');
                break;
            case 'short-pixel-bulk-restore':
                foreach( $mediaIds as $ID ) {
                    $this->doRestore($ID);
                }
                break;
        }
    }

    protected function restoreAndQueueList($mediaIds, $type) {
        foreach( $mediaIds as $ID ) {
            $meta = wp_get_attachment_metadata($ID);
            if(isset($meta['ShortPixel']['type']) && $meta['ShortPixel']['type'] !== $type) {
                $meta = $this->doRestore($ID);
                if($meta) { //restore succeeded
                    $meta['ShortPixel'] = array("type" => $type, 'WaitingProcessing' => true);
                    //wp_update_attachment_metadata($ID, $meta);
                    update_post_meta($ID, '_wp_attachment_metadata', $meta);
                    $this->prioQ->push($ID);
                }
            }
        }
    }

    /**
     * Optimize a new image - usually uploaded into the Media Library
     * @param array $meta - the wordpress postmeta structure
     * @param type $ID - the Media Library ID
     * @return the meta structure updated with ShortPixel info if case
     */
    public function handleMediaLibraryImageUpload($meta, $ID = null)
    {
        if( !$this->_settings->verifiedKey) {// no API Key set/verified -> do nothing here, just return
            return $meta;
        }

        // some plugins (e.g. WP e-Commerce) call the wp_attachment_metadata on just editing the image...
        $dbMeta = wp_get_attachment_metadata($ID);
        $refresh = false;

        if(isset($dbMeta['ShortPixelImprovement'])) {
            return $meta;
        }

        if(isset($this->thumbnailsRegenerating[$ID])) {
            return $meta;
        }

        self::log("Handle Media Library Image Upload #{$ID}");
        //self::log("STACK: " . json_encode(debug_backtrace()));

        if(!$this->_settings->optimizePdfs && 'pdf' === pathinfo(get_attached_file($ID), PATHINFO_EXTENSION)) {
            //pdf is not optimized automatically as per the option, but can be optimized by button. Nothing to do.
            return $meta;
        }
        elseif(!get_attached_file($ID) && isset($meta['file']) && in_array(strtolower(pathinfo($meta['file'], PATHINFO_EXTENSION)), self::$PROCESSABLE_EXTENSIONS)) {
            //in some rare cases (images added from the front-end) it's an image but get_attached_file returns null (the record is not yet saved in the DB)
            //in this case add it to the queue nevertheless
            $this->prioQ->push($ID);
            $meta['ShortPixel'] = array('WaitingProcessing' => true);
        }
        elseif( self::_isProcessable($ID, array(), $this->_settings->excludePatterns, $meta) == false )
        {
            //not a file that we can process
            $meta['ShortPixelImprovement'] = __('Optimization N/A', 'shortpixel-image-optimiser');
            return $meta;
        }
        else
        {//the kind of file we can process. goody.

            $this->prioQ->push($ID);
            $itemHandler = new ShortPixelMetaFacade($ID);
            $itemHandler->setRawMeta($meta);
            //that's a hack for watermarking plugins, don't send the image right away to processing, only add it in the queue
            include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            if(   !is_plugin_active('image-watermark/image-watermark.php')
               && !is_plugin_active('amazon-s3-and-cloudfront/wordpress-s3.php')
               && !is_plugin_active('amazon-s3-and-cloudfront-pro/amazon-s3-and-cloudfront-pro.php')
               && !is_plugin_active('easy-watermark/index.php')) {
                try {
                    $URLsAndPATHs = $this->getURLsAndPATHs($itemHandler);
                    //send a processing request right after a file was uploaded, do NOT wait for response
                    $this->_apiInterface->doRequests($URLsAndPATHs['URLs'], false, $itemHandler, false, $refresh);
                } catch(Exception $e) {
                    $meta['ShortPixelImprovement'] = $e->getMessage();
                    return $meta;
                }
                //self::log("IMG: sent: " . json_encode($URLsAndPATHs));
            }
            $meta['ShortPixel']['WaitingProcessing'] = true;

            // check if the image was converted from PNG upon uploading.
            if($itemHandler->getType() == ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE) {//for the moment
                $imagePath = $itemHandler->getMeta()->getPath();
                if(isset($this->_settings->convertedPng2Jpg[$imagePath])) {
                    $conv = $this->_settings->convertedPng2Jpg;
                    $params = $conv[$imagePath];
                    unset($conv[$imagePath]);
                    $this->_settings->convertedPng2Jpg == $conv;
                    $meta['ShortPixelPng2Jpg'] = array('originalFile' => $params['pngFile'], 'originalSizes' => array(),
                                       'backup' => $params['backup'], 'optimizationPercent' => $params['optimizationPercent']);
                }
            }

            return $meta;
        }
    }//end handleMediaLibraryImageUpload

    /**
     * if the image was optimized in the last hour, send a request to delete from picQueue
     * @param $itemHandler
     * @param bool $urlsAndPaths
     * @see ShortPixelImage/maybeDump
     */
    public function maybeDumpFromProcessedOnServer($itemHandler, $urlsAndPaths) {
        $meta = $itemHandler->getMeta();

        $doDump = false;

        if ($meta->getStatus() <= 0)
        {
            $doDump = true; // dump any caching on files that ended in an error.
        }
        else if(time() - strtotime($meta->getTsOptimized()) < 3600)  // check if this was optimized in last hour.
        {
            $doDump = true;
        }

        if ($doDump)
        {
          $this->_apiInterface->doDumpRequests($urlsAndPaths["URLs"]);
        }
    }

    /**
     * Convert an uploaded image from PNG to JPG
     * @param type $params
     * @return string
     */
    public function convertPng2Jpg($params) {
        $converter = new ShortPixelPng2Jpg($this->_settings);
        return $converter->convertPng2Jpg($params);
    }

    /**
     * convert PNG to JPEG if possible - already existing image in Media Library
     *
     * @param type $meta
     * @param type $ID
     * @return string
     */
    public function checkConvertMediaPng2Jpg($itemHandler) {
        $converter = new ShortPixelPng2Jpg($this->_settings);
        return $converter->checkConvertMediaPng2Jpg($itemHandler);
    }

    public function handleGravityFormsImageField($value) {
        if(!($folder = $this->spMetaDao->getFolder(SHORTPIXEL_UPLOADS_BASE . '/gravity_forms'))) {
            return;
        }
        if(strpos($value , '|:|')) {
            $cleanup = explode('|:|', $value);
            $value = $cleanup[0];
        }
        //ShortPixel is monitoring the gravity forms folder, add the image to queue
        $uploadDir   = wp_upload_dir();
        $localPath = str_replace($uploadDir['baseurl'], SHORTPIXEL_UPLOADS_BASE, $value);

        return $this->addPathToCustomFolder($localPath, $folder->getId(), 0);
    }

    /**
     * this is hooked onto the NextGen upload
     * @param type $image
     */
    public function handleNextGenImageUpload($image)
    {
        if ($this->_settings->includeNextGen == 1) {
            $imageFsPath = ShortPixelNextGenAdapter::getImageAbspath($image);

            $customFolders = $this->spMetaDao->getFolders();

            $folderId = -1;
            foreach ($customFolders as $folder) {
                if (strpos($imageFsPath, $folder->getPath()) === 0) {
                    $folderId = $folder->getId();
                    break;
                }
            }
            if ($folderId == -1) { //if not found, create
                $galleryPath = dirname($imageFsPath);
                $folder = new ShortPixelFolder(array("path" => $galleryPath), $this->_settings->excludePatterns);
                $folderMsg = $this->spMetaDao->saveFolder($folder);
                $folderId = $folder->getId();
                //self::log("NG Image Upload: created folder from path $galleryPath : Folder info: " .  json_encode($folder));
            }

            return $this->addPathToCustomFolder($imageFsPath, $folderId, $image->pid);
        }
    }

    protected function addPathToCustomFolder($imageFsPath, $folderId, $pid) {
        //prevent adding it multiple times if the action is called repeatedly (Gravity Forms does that)
        $existing = $this->spMetaDao->getMetaForPath($imageFsPath);
        if($existing) {
            return $existing;
        }
        $pathParts = explode('/', trim($imageFsPath));
        //Add the main image
        $meta = new ShortPixelMeta();
        $meta->setPath($imageFsPath);
        $meta->setName($pathParts[count($pathParts) - 1]);
        $meta->setFolderId($folderId);
        $meta->setExtMetaId($pid); // do this only for main image, not for thumbnais.
        $meta->setCompressionType($this->_settings->compressionType);
        $meta->setKeepExif($this->_settings->keepExif);
        $meta->setCmyk2rgb($this->_settings->CMYKtoRGBconversion);
        $meta->setResize($this->_settings->resizeImages);
        $meta->setResizeWidth($this->_settings->resizeWidth);
        $meta->setResizeHeight($this->_settings->resizeHeight);
        $ID = $this->spMetaDao->addImage($meta);
        $meta->setId($ID);
        $this->prioQ->push('C-' . $ID);
        //add the thumb image if exists
        $pathParts[] = "thumbs_" . $pathParts[count($pathParts) - 1];
        $pathParts[count($pathParts) - 2] = "thumbs";
        $thumbPath = implode('/', $pathParts);
        if(file_exists($thumbPath)) {
            $metaThumb = new ShortPixelMeta();
            $metaThumb->setPath($thumbPath);
            $metaThumb->setName($pathParts[count($pathParts) - 1]);
            $metaThumb->setFolderId($folderId);
            $metaThumb->setCompressionType($this->_settings->compressionType);
            $metaThumb->setKeepExif($this->_settings->keepExif);
            $metaThumb->setCmyk2rgb($this->_settings->CMYKtoRGBconversion);
            $metaThumb->setResize($this->_settings->resizeImages);
            $metaThumb->setResizeWidth($this->_settings->resizeWidth);
            $metaThumb->setResizeHeight($this->_settings->resizeHeight);
            $ID = $this->spMetaDao->addImage($metaThumb);
            $metaThumb->setId($ID);
            $this->prioQ->push('C-' . $ID);
        }
        return $meta;
    }

    public function optimizeCustomImage($id) {
        $itemHandler = new ShortPixelMetaFacade('C-' . $id);
        $meta = $itemHandler->getMeta();

        if ($meta->getStatus() <= 0)  // image is in errorState. Dump when retrying.
        {
          $URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);
          $this->maybeDumpFromProcessedOnServer($itemHandler, $URLsAndPATHs);
        }
        if($meta->getStatus() != ShortPixelMeta::FILE_STATUS_SUCCESS) {


            $meta->setStatus(ShortPixelMeta::FILE_STATUS_PENDING);
            $meta->setRetries(0);
            /* [BS] This is being set because meta in other states does not keep previous values. The value 0 is problematic
            since it can also mean not-initalized, new, etc . So push meta from settings.
            */
            $meta->setCompressionType($this->_settings->compressionType);
            $meta->setKeepExif($this->_settings->keepExif);
            $meta->setCmyk2rgb($this->_settings->CMYKtoRGBconversion);
            $meta->setResize($this->_settings->resizeImages);
            $meta->setResizeWidth($this->_settings->resizeWidth);
            $meta->setResizeHeight($this->_settings->resizeHeight);
            $this->spMetaDao->update($meta);
            $this->prioQ->push('C-' . $id);
        }
    }

    public function bulkRestore(){
        global $wpdb;

        $startQueryID = $crtStartQueryID = $this->prioQ->getStartBulkId();
        $endQueryID = $this->prioQ->getStopBulkId();

        if ( $startQueryID <= $endQueryID ) {
            return false;
        }

        $this->prioQ->resetPrio();

        $startTime = time();
        $maxTime = min(30, (is_numeric(SHORTPIXEL_MAX_EXECUTION_TIME)  && SHORTPIXEL_MAX_EXECUTION_TIME > 10 ? SHORTPIXEL_MAX_EXECUTION_TIME - 5 : 25));
        $maxResults = SHORTPIXEL_MAX_RESULTS_QUERY * 2;
        if(in_array($this->prioQ->getBulkType(), array(ShortPixelQueue::BULK_TYPE_CLEANUP, ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING))) {
            $maxResults *= 20;
        }
        $restored = array();

        //$ind = 0;
        while( $crtStartQueryID >= $endQueryID && time() - $startTime < $maxTime) {
            //if($ind > 1) break;
            //$ind++;

            // [BS] Request StartQueryID everytime to query for updated AdvanceBulk status
            $crtStartQueryID = $this->prioQ->getStartBulkId();
            $resultsPostMeta = WpShortPixelMediaLbraryAdapter::getPostMetaSlice($crtStartQueryID, $endQueryID, $maxResults);
            if ( empty($resultsPostMeta) ) {
                // check for custom work
                 $pendingCustomMeta = $this->spMetaDao->getPendingBulkRestore(SHORTPIXEL_MAX_RESULTS_QUERY * 2);
                 if (count($pendingCustomMeta) > 0)
                 {
                     foreach($pendingCustomMeta as $cObj)
                     {
                       $this->doCustomRestore($cObj->id);
                     }
                 }
                else
                {
                  $crtStartQueryID -= $maxResults; // this basically nukes the bulk.
                  $startQueryID = $crtStartQueryID;
                  $this->prioQ->setStartBulkId($startQueryID);
                  continue;
                }
            }

            foreach ( $resultsPostMeta as $itemMetaData ) {
                $crtStartQueryID = $itemMetaData->post_id;
                $item = new ShortPixelMetaFacade($crtStartQueryID);
                $meta = $item->getMeta();//wp_get_attachment_metadata($crtStartQueryID);

                if($meta->getStatus() == 2 || $meta->getStatus() == 1) {
                    if($meta->getStatus() == 2 && $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_RESTORE) {
                        $res = $this->doRestore($crtStartQueryID); //this is restore, the real
                    } else {
                        //this is only meta cleanup, no files are replaced (BACKUP REMAINS IN PLACE TOO)
                        $item->cleanupMeta($this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING);
                        $res = true;
                    }
                    $restored[] = array('id' => $crtStartQueryID, 'status' => $res ? 'success' : 'fail');
                }
                if($meta->getStatus() < 0) {//also cleanup errors either for restore or cleanup
                    $item->cleanupMeta();
                }
            }
            // [BS] Fixed Bug. Advance Bulk was outside of this loop, causing infinite loops to happen.
            $this->advanceBulk($crtStartQueryID);
        }

        return $restored;
    }

    //TODO muta in bulkProvider
    public function getBulkItemsFromDb(){
        global $wpdb;

        $startQueryID = $this->prioQ->getStartBulkId();
        $endQueryID = $this->prioQ->getStopBulkId();
        $skippedAlreadyProcessed = 0;

        if ( $startQueryID <= $endQueryID ) {
            return false;
        }
        $idList = array();
        $itemList = array();
        $timeoutMinThreshold = SHORTPIXEL_MAX_EXECUTION_TIME < 10 ? 2 : (SHORTPIXEL_MAX_EXECUTION_TIME < 30 ? 3 : 5);
        $maxTime = min(SHORTPIXEL_MAX_EXECUTION_TIME, 90);
        $timeoutThreshold = 5; // will adapt this with the maximum time needed for one pass
        $passTime = time();
        for ($sanityCheck = 0, $crtStartQueryID = $startQueryID;
             ($crtStartQueryID >= $endQueryID) && (count($itemList) < SHORTPIXEL_PRESEND_ITEMS) && ($sanityCheck < 150)
              && (time() - $this->timer < $maxTime - $timeoutThreshold); $sanityCheck++) {

            $timeoutThreshold = max($timeoutThreshold, $timeoutMinThreshold + time() - $passTime);
            $passTime = time();
            $maxResults = $timeoutThreshold > 15 ? SHORTPIXEL_MAX_RESULTS_QUERY / 3 :
                ($timeoutThreshold > 10 ? SHORTPIXEL_MAX_RESULTS_QUERY / 2 : SHORTPIXEL_MAX_RESULTS_QUERY);
            self::log("GETDB: pass $sanityCheck current StartID: $crtStartQueryID Threshold: $timeoutThreshold, MaxResults: $maxResults" );

            /* $queryPostMeta = "SELECT * FROM " . $wpdb->prefix . "postmeta
                WHERE ( post_id <= $crtStartQueryID AND post_id >= $endQueryID )
                  AND ( meta_key = '_wp_attached_file' OR meta_key = '_wp_attachment_metadata' )
                ORDER BY post_id DESC
                LIMIT " . SHORTPIXEL_MAX_RESULTS_QUERY;
            $resultsPostMeta = $wpdb->get_results($queryPostMeta);
            */

            $resultsPostMeta = WpShortPixelMediaLbraryAdapter::getPostMetaSlice($crtStartQueryID, $endQueryID, $maxResults);
            if(time() - $this->timer >= 60) self::log("GETDB is SLOW. Got meta slice.");

            if ( empty($resultsPostMeta) ) {
                $crtStartQueryID -= SHORTPIXEL_MAX_RESULTS_QUERY;
                $startQueryID = $crtStartQueryID;
                if(!count($idList)) { //none found so far, so decrease the start ID
                    self::log("GETDB: empty slice. setStartBulkID to $startQueryID");
                    $this->prioQ->setStartBulkId($startQueryID);
                }
                continue;
            }

            if($timeoutThreshold > 10) self::log("GETDB is SLOW. Meta slice has " . count($resultsPostMeta) . ' items.');

            foreach ( $resultsPostMeta as $itemMetaData ) {
                $crtStartQueryID = $itemMetaData->post_id;
                if(time() - $this->timer >= 60) self::log("GETDB is SO SLOW. Check processable for $crtStartQueryID.");
                if(time() - $this->timer >= $maxTime - $timeoutThreshold){
                    break;
                }

                if(!in_array($crtStartQueryID, $idList) && $this->isProcessable($crtStartQueryID, ($this->_settings->optimizePdfs ? array() : array('pdf')))) {
                    $item = new ShortPixelMetaFacade($crtStartQueryID);

                    if($timeoutThreshold > 15) self::log("GETDB is SO SLOW. Get meta for $crtStartQueryID.");
                    $meta = $item->getMeta();//wp_get_attachment_metadata($crtStartQueryID);
                    if($timeoutThreshold > 15) self::log("GETDB is SO SLOW. Got meta.");

                    if($meta->getStatus() != 2) {
                        $addIt = (strpos($meta->getMessage(), __('Image files are missing.', 'shortpixel-image-optimiser')) === false);

                        if(!$addIt) {
                            //in case the message is "Image files are missing", we first try a restore.
                            if (!$this->doRestore($crtStartQueryID)) {
                                delete_transient("shortpixel_thrown_notice"); // no need to display the error that a restore could not be performed.
                            }
                            $addIt = true;
                            $item = new ShortPixelMetaFacade($crtStartQueryID);
                        }
                        if($addIt) {
                            $itemList[] = $item;
                            $idList[] = $crtStartQueryID;
                            if(count($itemList) > SHORTPIXEL_PRESEND_ITEMS) break;
                        }
                    }
                    elseif($meta->getCompressionType() !== null && $meta->getCompressionType() != $this->_settings->compressionType) {//a different type of compression was chosen in settings
                        if($this->doRestore($crtStartQueryID)) {
                            $itemList[] = $item = new ShortPixelMetaFacade($crtStartQueryID); //force reload after restore
                            $idList[] = $crtStartQueryID;
                            if(count($itemList) > SHORTPIXEL_PRESEND_ITEMS) break;
                        } else {
                            $skippedAlreadyProcessed++;
                        }
                    }
                    elseif(   $this->_settings->processThumbnails && $meta->getThumbsOpt() !== null
                           && ($meta->getThumbsOpt() == 0 && count($meta->getThumbs()) > 0
                               || is_array($meta->getThumbsOptList())
                                  && count(array_diff(array_keys(WpShortPixelMediaLbraryAdapter::getSizesNotExcluded($meta->getThumbs(), $this->_settings->excludeSizes)),
                                                      $meta->getThumbsOptList())))) { //thumbs were chosen in settings
                        $URLsAndPATHs = $item->getURLsAndPATHs(true, true, $this->_settings->optimizeRetina, $this->_settings->excludeSizes);
                        if(count($URLsAndPATHs["URLs"])) {
                            $meta->setThumbsTodo(true);
                            $item->updateMeta($meta);//wp_update_attachment_metadata($crtStartQueryID, $meta);
                            $itemList[] = $item;
                            $idList[] = $crtStartQueryID;
                            if(count($itemList) > SHORTPIXEL_PRESEND_ITEMS) break;
                        }
                    }
                    elseif($itemMetaData->meta_key == '_wp_attachment_metadata') { //count skipped
                        $skippedAlreadyProcessed++;
                    }
                }
            }
            if(!count($idList) && $crtStartQueryID <= $startQueryID) {
                //daca n-am adaugat niciuna pana acum, n-are sens sa mai selectez zona asta de id-uri in bulk-ul asta.
                $leapStart = $this->prioQ->getStartBulkId();
                $crtStartQueryID = $startQueryID = $itemMetaData->post_id - 1; //decrement it so we don't select it again
                $res = WpShortPixelMediaLbraryAdapter::countAllProcessableFiles($this->_settings, $leapStart, $crtStartQueryID);
                $skippedAlreadyProcessed += $res["mainProcessedFiles"] - $res["mainProc".($this->getCompressionType() == 1 ? "Lossy" : "Lossless")."Files"];
                self::log("GETDB: empty list. setStartBulkID to $startQueryID");
                $this->prioQ->setStartBulkId($startQueryID);
            } else {
                $crtStartQueryID--;
                self::log("GETDB just decrementing. Crt: $crtStartQueryID Start: $startQueryID, list: " . json_encode($idList));
            }
        }
        $ret = array("items" => $itemList, "skipped" => $skippedAlreadyProcessed, "searching" => ($sanityCheck >= 150) || (time() - $this->timer >= $maxTime - $timeoutThreshold));
        self::log('GETDB returns ' . json_encode($ret));
        return $ret;
    }

    /**
     * Get last added items from priority
     * @return type
     */
    //TODO muta in bulkProvider - prio
    public function getFromPrioAndCheck($limit = PHP_INT_MAX) {
        $items = array();$i = 1;
        foreach ($this->prioQ->getFromPrioAndCheck() as $id) {
            $items[] = new ShortPixelMetaFacade($id);
            if($i++ > $limit) { break; }
        }
        return $items;
    }

    /** Checks the API key **/
    private function checkKey($ID) {
        if( $this->_settings->verifiedKey == false) {
            if($ID == null){
                $ids = $this->getFromPrioAndCheck(1);
                $itemHandler = (count($ids) > 0 ? $ids[0] : null);
            }
            $response = array("Status" => ShortPixelAPI::STATUS_NO_KEY, "ImageID" => $itemHandler ? $itemHandler->getId() : "-1", "Message" => __('Missing API Key','shortpixel-image-optimiser'));
            $this->_settings->bulkLastStatus = $response;
            die(json_encode($response));
        }
    }

    private function sendEmptyQueue() {
        $avg = $this->getAverageCompression();
        $fileCount = $this->_settings->fileCount;
        $response = array("Status" => self::BULK_EMPTY_QUEUE,
            /* translators: console message Empty queue 1234 -> 1234 */
            "Message" => __('Empty queue ','shortpixel-image-optimiser') . $this->prioQ->getStartBulkId() . '->' . $this->prioQ->getStopBulkId(),
            "BulkStatus" => ($this->prioQ->bulkRunning()
                    ? "1" : ($this->prioQ->bulkPaused() ? "2" : "0")),
            "AverageCompression" => $avg,
            "FileCount" => $fileCount,
            "BulkPercent" => $this->prioQ->getBulkPercent());
        die(json_encode($response));
    }

    /* Main Image Processing Function. Called from JS loop
    *
    * @param String $ID ApiKey
    */
    public function handleImageProcessing($ID = null) {
        //if(rand(1,2) == 2) {
        //    header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
        //    die("za stop");
        //}
        //0: check key
        $this->checkKey($ID);

        if($this->_settings->frontBootstrap && is_admin() && !ShortPixelTools::requestIsFrontendAjax()) {
            //if in backend, and front-end is activated, mark processing from backend to shut off the front-end for 10 min.
            $this->_settings->lastBackAction = time();
        }

        $rawPrioQ = $this->prioQ->get();
        if(count($rawPrioQ)) { self::log("HIP: 0 Priority Queue: ".json_encode($rawPrioQ)); }
        self::log("HIP: 0 Bulk running? " . $this->prioQ->bulkRunning() . " START " . $this->_settings->startBulkId . " STOP " . $this->_settings->stopBulkId . " MaxTime: " . SHORTPIXEL_MAX_EXECUTION_TIME);

        //handle the bulk restore and cleanup first - these are fast operations taking precedece over optimization
        if(   $this->prioQ->bulkRunning()
           && (   $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_RESTORE
               || $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_CLEANUP
               || $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING)) {
            $res = $this->bulkRestore();
            if($res === false) {
                $this->sendEmptyQueue();
            } else {
                die(json_encode(array("Status" => ShortPixelAPI::STATUS_RETRY,
                                     "Message" => __('Restoring images...  ','shortpixel-image-optimiser') . $this->prioQ->getStartBulkId() . '->' . $this->prioQ->getStopBulkId(),
                                     "BulkPercent" => $this->prioQ->getBulkPercent(),
                                     "Restored" => $res )));
            }

        }

        //1: get 3 ids to process. Take them with priority from the queue
        $ids = $this->getFromPrioAndCheck(SHORTPIXEL_PRESEND_ITEMS);
        if(count($ids) < SHORTPIXEL_PRESEND_ITEMS ) { //take from bulk if bulk processing active
            if($this->prioQ->bulkRunning()) {
                $res = $this->getBulkItemsFromDb();
                $bulkItems = $res['items'];
                //merge them into the $ids array based on the ID (the same ID could be in prio also)
                if($bulkItems){
                    foreach($bulkItems as $bi) {
                        $add = true;
                        foreach($ids as $pi) {
                            if($pi->getType() == ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE && $bi->getId() == $pi->getId()) {
                                $add = false;
                            }
                        }
                        $ids[] = $bi;
                    }
                }
            }
        }

        //self::log("HIP: 0 Bulk ran: " . $this->prioQ->bulkRan());
        $customIds = false;
        if(count($ids) < SHORTPIXEL_PRESEND_ITEMS && $this->prioQ->bulkRan() && $this->_settings->hasCustomFolders
           && (!$this->_settings->cancelPointer || $this->_settings->skipToCustom)
           && !$this->_settings->customBulkPaused)
        { //take from custom images if any left to optimize - only if bulk was ever started
            //but first refresh if it wasn't refreshed in the last hour
            if(time() - $this->_settings->hasCustomFolders > 3600) {
                $notice = null; $this->refreshCustomFolders($notice);
                $this->_settings->hasCustomFolders = time();
            }

            $customIds = $this->spMetaDao->getPendingMetas( SHORTPIXEL_PRESEND_ITEMS - count($ids));
            if(is_array($customIds)) {
                $ids = array_merge($ids, array_map(array('ShortPixelMetaFacade', 'getNewFromRow'), $customIds));
            }
        }
        //var_dump($ids);
        //die("za stop 2");

        //self::log("HIP: 1 Ids: ".json_encode($ids));
        if(count($ids)) {$idl='';foreach($ids as $i){$idl.=$i->getId().' ';} self::log("HIP: 1 Selected IDs: $idl");}

        //2: Send up to SHORTPIXEL_PRESEND_ITEMS files to the server for processing
        for($i = 0, $itemHandler = false; $ids !== false && $i < min(SHORTPIXEL_PRESEND_ITEMS, count($ids)); $i++) {
            $crtItemHandler = $ids[$i];
            $tmpMeta = $crtItemHandler->getMeta();

            $compType = ($tmpMeta->getCompressionType() !== null ? $tmpMeta->getCompressionType() : $this->_settings->compressionType);
            try {
                self::log("HIP: 1 sendToProcessing: ".$crtItemHandler->getId());
                $URLsAndPATHs = $this->sendToProcessing($crtItemHandler, $compType, $tmpMeta->getThumbsTodo());
                //self::log("HIP: 1 METADATA: ".json_encode($crtItemHandler->getRawMeta()));
                //self::log("HIP: 1 URLs and PATHs: ".json_encode($URLsAndPATHs));
                if(!$itemHandler) { //save for later use
                    $itemHandler = $ids[$i];
                    $firstUrlAndPaths = $URLsAndPATHs;
                }
            } catch(Exception $e) { // Exception("Post metadata is corrupt (No attachment URL)") or Exception("Image files are missing.")
                if($tmpMeta->getStatus() != 2) {
                    $crtItemHandler->incrementRetries(1, ($e->getCode() < 0 ? $e->getCode() : ShortPixelAPI::ERR_FILE_NOT_FOUND), $e->getMessage());
                }
                if(! $this->prioQ->remove($crtItemHandler->getQueuedId()) ){
                    $this->advanceBulk($crtItemHandler->getId());
                    $res['searching'] = true;
                }
            }
        }

        if (!$itemHandler){
            //if searching, than the script is searching for not processed items and found none yet, should be relaunced
            if(isset($res['searching']) && $res['searching']) {
                    die(json_encode(array("Status" => ShortPixelAPI::STATUS_RETRY,
                                          "Message" => __('Searching images to optimize...  ','shortpixel-image-optimiser') . $this->prioQ->getStartBulkId() . '->' . $this->prioQ->getStopBulkId() )));
            }
            //in this case the queue is really empty
            self::log("HIP: 1 STOP BULK");
            $bulkEverRan = $this->prioQ->stopBulk();
            $this->sendEmptyQueue();
        }

        self::log("HIP: 2 Prio Queue: ".json_encode($this->prioQ->get()));
        //3: $itemHandler contains the first element of the list
        $itemId = $itemHandler->getQueuedId();
        self::log("HIP: 2 Process Image: ".json_encode($itemHandler->getId()));
        $result = $this->_apiInterface->processImage($firstUrlAndPaths['URLs'], $firstUrlAndPaths['PATHs'], $itemHandler);

        $result["ImageID"] = $itemId;
        $meta = $itemHandler->getMeta();
        $result["Filename"] = ShortPixelAPI::MB_basename($meta->getPath());

        self::log("HIP: 3 Prio Queue: ".json_encode($this->prioQ->get()));

        //4: update counters and priority list
        if( $result["Status"] == ShortPixelAPI::STATUS_SUCCESS) {
            self::log("HIP: Image ID " . $itemId . " optimized successfully: ".json_encode($result));
            $prio = $this->prioQ->remove($itemId);
            //remove also from the failed list if it failed in the past
            $prio = $this->prioQ->removeFromFailed($itemId);
            $result["Type"] = $meta->getCompressionType() !== null ? ShortPixelAPI::getCompressionTypeName($meta->getCompressionType()) : '';
            $result["ThumbsTotal"] = $meta->getThumbs() && is_array($meta->getThumbs()) ? WpShortPixelMediaLbraryAdapter::countSizesNotExcluded($meta->getThumbs()): 0;
            $miss = $meta->getThumbsMissing();
            $result["ThumbsTotal"] -= is_array($miss) ? count($miss) : 0;
            $result["ThumbsCount"] = $meta->getThumbsOpt()
                ? $meta->getThumbsOpt() //below is the fallback for old optimized images that don't have thumbsOpt
                : ($this->_settings->processThumbnails ? $result["ThumbsTotal"] : 0);

            $result["RetinasCount"] = $meta->getRetinasOpt();
            $result["BackupEnabled"] = ($this->getBackupFolderAny($meta->getPath(), $meta->getThumbs()) ? true : false);//$this->_settings->backupImages;

            $tsOptimized = $meta->getTsOptimized();
            if (! is_null($tsOptimized))
            {
                $tsOptObj = new DateTime($tsOptimized);
                if ($tsOptObj)
                  $result['TsOptimized'] = ShortPixelTools::format_nice_date($tsOptObj);
            }

            if(!$prio && $itemId <= $this->prioQ->getStartBulkId()) {
                $this->advanceBulk($itemId);
                $this->setBulkInfo($itemId, $result);
            }

            $result["AverageCompression"] = $this->getAverageCompression();

            if($itemHandler->getType() == ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE) {

                $thumb = $bkThumb = "";
                //$percent = 0;
                $percent = $meta->getImprovementPercent();
                if($percent){
                    $filePath = explode("/", $meta->getPath());

                    //Get a suitable thumb
                    $sizes = $meta->getThumbs();
                    if('pdf' == strtolower(pathinfo($result["Filename"], PATHINFO_EXTENSION))) {
//                        echo($result["Filename"] . " ESTE --> "); die(var_dump(strtolower(pathinfo($result["Filename"], PATHINFO_EXTENSION))));
                        $thumb = plugins_url( 'shortpixel-image-optimiser/res/img/logo-pdf.png' );
                        $bkThumb = '';
                    } else {
                        if(count($sizes)) {
                            $exclude = $this->_settings->excludeSizes;
                            $exclude = is_array($exclude) ? $exclude : array();
                            $thumb = (isset($sizes["medium"]) && !in_array("medium", $exclude)
                                      ? $sizes["medium"]["file"]
                                      : (isset($sizes["thumbnail"]) && !in_array("thumbnail", $exclude)  ? $sizes["thumbnail"]["file"] : ""));
                            if (!strlen($thumb)) { //fallback to the first in the list
                                foreach($sizes as $sizeName => $sizeVal) {
                                    $exclude = $this->_settings->excludeSizes;
                                    if(!in_array($sizeName, $exclude)) {
                                        $thumb = isset($sizeVal['file']) ? $sizeVal['file'] : '';
                                        break;
                                    }
                                }
                            }
                        }
                        if(!strlen($thumb)) { //fallback to the image itself
                            $thumb = is_array($filePath) ? $filePath[count($filePath) - 1] : $filePath;
                        }

                        if(strlen($thumb) && $this->_settings->backupImages && $this->_settings->processThumbnails) {
                            //$backupUrl = SHORTPIXEL_UPLOADS_URL . "/" . SHORTPIXEL_BACKUP . "/";
                            // use the same method as in getComparerData (HelpScout case 771014296). Former method above.
                            //$backupUrl = content_url() . "/" . SHORTPIXEL_UPLOADS_NAME . "/" . SHORTPIXEL_BACKUP . "/";
                            //or even better:
                            $backupUrl = SHORTPIXEL_BACKUP_URL . "/";
                            //$urlBkPath = $this->_apiInterface->returnSubDir(get_attached_file($ID));
                            $urlBkPath = ShortPixelMetaFacade::returnSubDir($meta->getPath(), ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE);
                            $bkThumb = $backupUrl . $urlBkPath . $thumb;
                        }
                        if(strlen($thumb)) {
                            if($itemHandler->getType() == ShortPixelMetaFacade::CUSTOM_TYPE) {
                                $uploadsUrl = ShortPixelMetaFacade::getHomeUrl();
                                $urlPath = ShortPixelMetaFacade::returnSubDir($meta->getPath());
                                //$urlPath = implode("/", array_slice($filePath, 0, count($filePath) - 1));
                                $thumb = $uploadsUrl . $urlPath . $thumb;
                            } else {
                                $mainUrl = ShortPixelMetaFacade::safeGetAttachmentUrl($itemHandler->getId());
                                $thumb = dirname($mainUrl) . '/' . $thumb;
                            }
                        }
                    }

                    $result["Thumb"] = $thumb;
                    $result["BkThumb"] = $bkThumb;
                }
            }
            elseif( is_array($customIds)) { // this item is from custom bulk
                foreach($customIds as $customId) {
                    $rootUrl = ShortPixelMetaFacade::getHomeUrl();
                    if($customId->id == $itemHandler->getId()) {
                        if('pdf' == strtolower(pathinfo($meta->getName(), PATHINFO_EXTENSION))) {
                            $result["Thumb"] = plugins_url( 'shortpixel-image-optimiser/res/img/logo-pdf.png' );
                            $result["BkThumb"] = "";
                        } else {
                            $result["Thumb"] = $thumb = $rootUrl . $meta->getWebPath();
                            if($this->_settings->backupImages) {
                                $result["BkThumb"] = str_replace($rootUrl, $rootUrl. "/" . basename(dirname(dirname(SHORTPIXEL_BACKUP_FOLDER))) . "/" . SHORTPIXEL_UPLOADS_NAME . "/" . SHORTPIXEL_BACKUP . "/", $thumb);
                            }
                        }
                        $this->setBulkInfo($itemId, $result);
                        break;
                    }
                }
            }
        }
        elseif ($result["Status"] == ShortPixelAPI::STATUS_ERROR) {
            if($meta->getRetries() > SHORTPIXEL_MAX_ERR_RETRIES) {
                if(! $this->prioQ->remove($itemId) ){
                    self::log("HIP RES: advanceBulk - too many retries for $itemId - skipping.");
                    $this->advanceBulk($meta->getId());
                }
                if($itemHandler->getType() == ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE) {
                    $itemHandler->deleteMeta(); //this deletes only the ShortPixel fields from meta, in case of WP Media library
                }
                $result["Status"] = ShortPixelAPI::STATUS_SKIP;
                $result["Message"] .= __(' Retry limit reached. Skipping file ID ','shortpixel-image-optimiser') . $itemId . ".";
                $itemHandler->setError(isset($result['Code']) ? $result['Code'] : ShortPixelAPI::ERR_INCORRECT_FILE_SIZE, $result["Message"] );
            }
            else {
                if(isset($result['Code'])) {
                    $itemHandler->incrementRetries(1, $result['Code'], $result["Message"]);
                } else {
                    $itemHandler->incrementRetries(1, ShortPixelAPI::ERR_UNKNOWN, "Connection error (" . $result["Message"] . ")" );
                }
            }
        }
        elseif ($result["Status"] == ShortPixelAPI::STATUS_SKIP
             || $result["Status"] == ShortPixelAPI::STATUS_FAIL) {
            $meta = $itemHandler->getMeta();
            //$prio = $this->prioQ->remove($ID);
            $prio = $this->prioQ->remove($itemId);
            if(isset($result["Code"])
               && (   $result["Code"] == "write-fail" //could not write
                   || (in_array(0+$result["Code"], array(-201)) && $meta->getRetries() >= 3))) { //for -201 (invalid image format) we retry only 3 times.
                //put this one in the failed images list - to show the user at the end
                $prio = $this->prioQ->addToFailed($itemHandler->getQueuedId());
            }
            self::log("HIP RES: skipping $itemId");
            $this->advanceBulk($meta->getId());
            if($itemHandler->getType() == ShortPixelMetaFacade::CUSTOM_TYPE) {
                $result["CustomImageLink"] = ShortPixelMetaFacade::getHomeUrl() . $meta->getWebPath();
            }
        }
        elseif($result["Status"] == ShortPixelAPI::STATUS_QUEUE_FULL) {
            //nimic?
        }
        elseif($result["Status"] == ShortPixelAPI::STATUS_MAINTENANCE) {
            //nimic?
        }
        elseif ($this->prioQ->isPrio($itemId) && $result["Status"] == ShortPixelAPI::STATUS_QUOTA_EXCEEDED) {
            if(!$this->prioQ->skippedCount()) {
                $this->prioQ->reverse(); //for the first prio item with quota exceeded, revert the prio queue as probably the bottom ones were processed
            }
            if($this->prioQ->allSkipped()) {
                $result["Stop"] = true;
            } else {
                $result["Stop"] = false;
                $this->prioQ->skip($itemId);
            }
            self::log("HIP: 5 Prio Skipped: ".json_encode($this->prioQ->getSkipped()));
        }
        elseif($result["Status"] == ShortPixelAPI::STATUS_RETRY && is_array($customIds)) {
            $result["CustomImageLink"] = $thumb = ShortPixelMetaFacade::getHomeUrl() . $meta->getWebPath();
        }

        if($result["Status"] !== ShortPixelAPI::STATUS_RETRY) {
            $this->_settings->bulkLastStatus = $result;
        }
        $ret = json_encode($result);
        self::log("HIP RET " . $ret);
        die($ret);
    }


    private function advanceBulk($processedID) {
        if($processedID <= $this->prioQ->getStartBulkId()) {
            $this->prioQ->setStartBulkId($processedID - 1);
            $this->prioQ->logBulkProgress();
       }
    }

    private function setBulkInfo($processedID, &$result) {
        $deltaBulkPercent = $this->prioQ->getDeltaBulkPercent();
        $minutesRemaining = $this->prioQ->getTimeRemaining();
        $pendingMeta = $this->_settings->hasCustomFolders ? $this->spMetaDao->getPendingMetaCount() : 0;
        $percent = $this->prioQ->getBulkPercent();
        if(0 + $pendingMeta > 0) {
            $customMeta = $this->spMetaDao->getCustomMetaCount();
            $crtTotalFiles = is_array($this->_settings->currentStats) ? $this->_settings->currentStats['totalFiles'] : $this->_settings->currentStats;
            $totalPercent = round(($percent * $crtTotalFiles + ($customMeta - $pendingMeta) * 100) / ($crtTotalFiles + $customMeta));
            $minutesRemaining = round($minutesRemaining * (100 - $totalPercent) / max(1, 100 - $percent));
            $percent = $totalPercent;
        }
        $result["BulkPercent"] = $percent;
        $result["BulkMsg"] = $this->bulkProgressMessage($deltaBulkPercent, $minutesRemaining);
    }

    private function sendToProcessing($itemHandler, $compressionType = false, $onlyThumbs = false) {

        //conversion of PNG 2 JPG for existing images
        if($itemHandler->getType() == ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE) { //currently only for ML
            $rawMeta = $this->checkConvertMediaPng2Jpg($itemHandler);

            if(isset($rawMeta['type']) && $rawMeta['type'] == 'image/jpeg') {
                $itemHandler->getMeta(true);
            }
        }

        //WpShortPixelMediaLbraryAdapter::cleanupFoundThumbs($itemHandler);
        $URLsAndPATHs = $this->getURLsAndPATHs($itemHandler, NULL, $onlyThumbs);

        $meta = $itemHandler->getMeta();
        //find thumbs that are not listed in the metadata and add them in the sizes array
        if(   $itemHandler->getType() == ShortPixelMetaFacade::MEDIA_LIBRARY_TYPE
           && $this->_settings->optimizeUnlisted) {
            $mainFile = $meta->getPath();

            $foundThumbs = WpShortPixelMediaLbraryAdapter::findThumbs($mainFile);
            //first identify which thumbs are not in the sizes
            $sizes = $meta->getThumbs();
            $mimeType = false;
            foreach($foundThumbs as $id => $found) {
                //get the mime-type from one of the thumbs metas
                foreach($sizes as $size) {
                    if(pathinfo($mainFile, PATHINFO_EXTENSION) !== pathinfo($size['file'], PATHINFO_EXTENSION)){
                        continue;
                    }
                    if(isset($size['mime-type'])) { //situation from support case #9351 Ramesh Mehay
                        $mimeType = $size['mime-type'];
                    }
                    if($size['file'] === ShortPixelAPI::MB_basename($found)) {
                        $foundThumbs[$id] = false;
                    }
                }
            }
            // add the unfound ones to the sizes array
            $ind = 1;
            while (isset($sizes[ShortPixelMeta::FOUND_THUMB_PREFIX . str_pad("".$ind, 2, '0', STR_PAD_LEFT)])) $ind++;
            $start = $ind;
            foreach($foundThumbs as $found) {
                if($found !== false) {
                    $size = getimagesize($found);
                    $sizes[ShortPixelMeta::FOUND_THUMB_PREFIX . str_pad("".$ind, 2, '0', STR_PAD_LEFT)]= array( // it's a file that has no corresponding thumb so it's the WEBP for the main file
                        'file' => ShortPixelAPI::MB_basename($found),
                        'width' => $size[0],
                        'height' => $size[1],
                        'mime-type' => $mimeType
                    );
                    $ind++;
                }
            }
            if($ind > $start) { // at least one thumbnail added, update
                $meta->setThumbs($sizes);
                $itemHandler->updateMeta($meta);
                $URLsAndPATHs = $this->getURLsAndPATHs($itemHandler, NULL, $onlyThumbs);
            }
        }

        //find any missing thumbs files and mark them as such
        $miss = $meta->getThumbsMissing();
        /* TODO remove */if(is_numeric($miss)) $miss = array();
        if(   isset($URLsAndPATHs['sizesMissing']) && count($URLsAndPATHs['sizesMissing'])
           && (null === $miss || count(array_diff_key($miss, array_merge($URLsAndPATHs['sizesMissing'], $miss))))) {
            //fix missing thumbs in the metadata before sending to processing
            $meta->setThumbsMissing($URLsAndPATHs['sizesMissing']);
            $itemHandler->updateMeta();
        }
        //die(var_dump($itemHandler));
        $refresh = $meta->getStatus() === ShortPixelAPI::ERR_INCORRECT_FILE_SIZE;
        //echo("URLS: "); die(var_dump($URLsAndPATHs));
        $itemHandler->setWaitingProcessing();
        $this->_apiInterface->doRequests($URLsAndPATHs['URLs'], false, $itemHandler,
                $compressionType === false ? $this->_settings->compressionType : $compressionType, $refresh);//send a request, do NOT wait for response
        //$meta = wp_get_attachment_metadata($ID);
        //$meta['ShortPixel']['WaitingProcessing'] = true;
        //wp_update_attachment_metadata($ID, $meta);
        return $URLsAndPATHs;
    }

    /** Manual optimization request. This is only called from the Media Library, never from the Custom media */
    public function handleManualOptimization() {
        $imageId = intval($_GET['image_id']);
        $cleanup = $_GET['cleanup'];

        Log::addInfo("Handle Manual Optimization #{$imageId}");

        switch(substr($imageId, 0, 2)) {
            case "N-":
                return "Add the gallery to the custom folders list in ShortPixel settings.";
                // Later
                if(class_exists("C_Image_Mapper")) { //this is a NextGen image but not added to our tables, so add it now.
                    $image_mapper = C_Image_Mapper::get_instance();
                    $image = $image_mapper->find(intval(substr($imageId, 2)));
                    if($image) {
                        $this->handleNextGenImageUpload($image, true);
                        return array("Status" => ShortPixelAPI::STATUS_SUCCESS, "message" => "");
                    }
                }
                return array("Status" => ShortPixelAPI::STATUS_FAIL, "message" => __('NextGen image not found','shortpixel-image-optimiser'));
                break;
            case "C-":
                Log::addError("Throw: HandleManualOptimization for custom images not implemented");
                throw new Exception("HandleManualOptimization for custom images not implemented");
            default:
                $this->optimizeNowHook(intval($imageId), true);
                break;
        }
        //do_action('shortpixel-optimize-now', $imageId);

    }

    /** Returns status of an image *
    * Uses a request parameter for image_id, ends in json encode.
    * @hook - wp_ajax_shortpixel_check_status
    */
    public function checkStatus() {
        $itemHandler = new ShortPixelMetaFacade(intval($_GET['image_id']));
        $meta = $itemHandler->getMeta();
        die(json_encode(array("Status" => $meta->getStatus(), "Message" => $meta->getMessage())));
    }

    /** Hook action for optimization (Ajax-call)
    * @hook shortpixel-optimize-now ( seems not in use )
    * Call by handleManualOptimization
    */
    public function optimizeNowHook($imageId, $manual = false) {
        //WpShortPixel::log("OPTIMIZE NOW HOOK for ID: $imageId STACK: " . json_encode(debug_backtrace()));
        if($this->isProcessable($imageId)) {
            $this->prioQ->push($imageId);
            $itemHandler = new ShortPixelMetaFacade($imageId);
            $path = get_attached_file($imageId);//get the full file PATH
            if(!$manual && 'pdf' === pathinfo($path, PATHINFO_EXTENSION) && !$this->_settings->optimizePdfs) {
                $ret = array("Status" => ShortPixelAPI::STATUS_SKIP, "Message" => $imageId);
            } else {
                try {
                    $this->sendToProcessing($itemHandler, false, $itemHandler->getMeta()->getThumbsTodo());
                    $ret = array("Status" => ShortPixelAPI::STATUS_SUCCESS, "Message" => "");
                } catch(Exception $e) { // Exception("Post metadata is corrupt (No attachment URL)")
                    $itemHandler->getMeta();
                    $errCode = $e->getCode() < 0 ? $e->getCode() : ShortPixelAPI::ERR_FILE_NOT_FOUND;
                    $itemHandler->setError($errCode, $e->getMessage());
                    $ret = array("Status" => ShortPixelAPI::STATUS_FAIL, "Message" => $e->getMessage());
                }
            }
        } else {
            $ret = array("Status" => ShortPixelAPI::STATUS_SKIP, "Message" => $imageId);
        }
        die(json_encode($ret));
    }

    /**
     * To be called by thumbnail regeneration plugins before regenerating thumbnails for an image.
     * @param $postId
     */
    public function thumbnailsBeforeRegenerateHook($postId) {
        $this->thumbnailsRegenerating[$postId] = true;
    }


    /**
     * to be called by thumbnail regeneration plugins when regenerating the thumbnails for an image
     * @param $postId - the postId of the image
     * @param $originalMeta - the metadata before the regeneration
     * @param array $regeneratedSizes - the list of the regenerated thumbnails - if empty then all were regenerated.
     * @param bool $bulk - true if the regeneration is done in bulk - in this case the image will not be immediately scheduled for processing but the user will need to launch the ShortPixel bulk after regenerating.
     *
     *
     * Note - $regeneratedSizes expects part of the metadata array called [sizes], with filename, not just the resized data.
     */
    public function thumbnailsRegeneratedHook($postId, $originalMeta, $regeneratedSizes = array(), $bulk = false) {

        if(isset($originalMeta["ShortPixelImprovement"]) && is_numeric($originalMeta["ShortPixelImprovement"])) {
            $shortPixelMeta = $originalMeta["ShortPixel"];
            unset($shortPixelMeta['thumbsMissing']);
            if(count($regeneratedSizes) == 0 || !isset($shortPixelMeta["thumbsOptList"])) {
                $shortPixelMeta["thumbsOpt"] = 0;
                $shortPixelMeta["thumbsOptList"] = array();
                $shortPixelMeta["retinasOpt"] = 0;
            } else {
                $regeneratedThumbs = array();
                foreach($regeneratedSizes as $size) {
                    if(isset($size['file']) && in_array($size['file'], $shortPixelMeta["thumbsOptList"] )) {
                        $regeneratedThumbs[] = $size['file'];
                        $shortPixelMeta["thumbsOpt"] = max(0, $shortPixelMeta["thumbsOpt"] - 1); // this is a complicated count of number of thumbnails
                        $shortPixelMeta["retinasOpt"] = max(0, $shortPixelMeta["retinasOpt"] - 1);
                    }
                }
                // This retains the thumbnails that were already regenerated, and removes what is passed via regeneratedSizes.
                $shortPixelMeta["thumbsOptList"] = array_diff($shortPixelMeta["thumbsOptList"], $regeneratedThumbs);
            }
            $meta = wp_get_attachment_metadata($postId);
            $meta["ShortPixel"] = $shortPixelMeta;
            $meta["ShortPixelImprovement"] = $originalMeta["ShortPixelImprovement"];
            if(isset($originalMeta["ShortPixelPng2Jpg"])) {
                $meta["ShortPixelPng2Jpg"] = $originalMeta["ShortPixelPng2Jpg"];
            }
            //wp_update_attachment_metadata($postId, $meta);
            update_post_meta($postId, '_wp_attachment_metadata', $meta);

            if(!$bulk) {
                $this->prioQ->push($postId);
            }
        }
        unset($this->thumbnailsRegenerating[$postId]);
    }

    /** Check if a certain files exists in the backup
    * ( by calling a random number of dir functions )
    * TODO - Should be of the folder model.
    */
    public function shortpixelGetBackupFilter($imagePath) {
        $backup = str_replace(dirname(dirname(dirname(SHORTPIXEL_BACKUP_FOLDER))),SHORTPIXEL_BACKUP_FOLDER, $imagePath);
        return file_exists($backup) ? $backup : false;
    }

    //WP/LR Sync plugin integration
    public function onWpLrUpdateMedia($imageId, $galleryIdsUnused) {
        $meta = wp_get_attachment_metadata($imageId);
        if(is_array($meta)) {
            unset($meta['ShortPixel']);
            $meta['ShortPixel'] = array();
            $meta['ShortPixel']['WaitingProcessing'] = true;
            $this->prioQ->push($imageId);
            //wp_update_attachment_metadata($imageId, $meta);
            update_post_meta($imageId, '_wp_attachment_metadata', $meta);
            ShortPixelMetaFacade::optimizationStarted($imageId);
        }
    }


    /** on Image error, Save error in file's meta data
    * @param int $ID image_id
    * @param string $result - Error String
    */
    public function handleError($ID, $result)
    {
        $meta = wp_get_attachment_metadata($ID);
        $meta['ShortPixelImprovement'] = $result;
        //wp_update_attachment_metadata($ID, $meta);
        update_post_meta($ID, '_wp_attachment_metadata', $meta);
    }

    /* Gets backup folder of file
    * @param string $file  Filepath - probably
    * @return string backupFolder
    */
    public function getBackupFolder($file) {
        if(realpath($file)) {
            $ret = $this->getBackupFolderInternal(realpath($file)); //found cases when $file contains for example /wp/../wp-content - clean it up
            if($ret) return $ret;
        }
        //another chance at glory, maybe cleanup was too much? (we tried first the cleaned up version for historical reason, don't disturb the sleeping dragon, right? :))
        return $this->getBackupFolderInternal($file);
    }

    /** Gets backup from file
    * @param string $file Filename
    * @return string FolderName
    */
    private function getBackupFolderInternal($file) {
        $fileExtension = strtolower(substr($file,strrpos($file,".")+1));
        $SubDir = ShortPixelMetaFacade::returnSubDir($file);
        $SubDirOld = ShortPixelMetaFacade::returnSubDirOld($file);

        if (   !file_exists(SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir . ShortPixelAPI::MB_basename($file))
            && !file_exists(SHORTPIXEL_BACKUP_FOLDER . '/' . date("Y") . "/" . date("m") . "/" . ShortPixelAPI::MB_basename($file)) ) {
            $SubDir = $SubDirOld; //maybe the folder was saved with the old method that returned the full path if the wp-content was not inside the root of the site.
        }
        if (   !file_exists(SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir . ShortPixelAPI::MB_basename($file))
            && !file_exists(SHORTPIXEL_BACKUP_FOLDER . '/' . date("Y") . "/" . date("m") . "/" . ShortPixelAPI::MB_basename($file)) ) {
            $SubDir = trailingslashit(substr(dirname($file), 1)); //try this too
        }
        //sometimes the month of original file and backup can differ
        if ( !file_exists(SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir . ShortPixelAPI::MB_basename($file)) ) {
            $SubDir = date("Y") . "/" . date("m") . "/";
            if( !file_exists(SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir . ShortPixelAPI::MB_basename($file)) ) {
                return false;
            }
        }
        return SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir;
    }

    /** Gets BackupFolder. If that doesn't work, search thumbs for a backupFolder
    * @param string $file FileName
    * @param array $thumbs Array of thumbnails
    * @return string|boolean Returns either backupfolder or false.
    */
    public function getBackupFolderAny($file, $thumbs) {
        $ret = $this->getBackupFolder($file);
        //if(!$ret && !file_exists($file) && isset($thumbs)) {
        if(!$ret && isset($thumbs)) {
            //try with the thumbnails
            foreach($thumbs as $size) {
                $backup = $this->getBackupFolder(trailingslashit(dirname($file)) . $size['file']);
                if($backup) {
                    $ret = $backup;
                    break;
                }
            }
        }
        return apply_filters("shortpixel_backup_folder", $ret, $file, $thumbs);
    }

    /** Sets file permissions
    * @param string $file FileName
    * @return boolean Success
    * TODO - Move to File Model
    */
    protected function setFilePerms($file) {
        //die(getenv('USERNAME') ? getenv('USERNAME') : getenv('USER'));

        $perms = @fileperms($file);
        if(($perms & 0x0100) && ($perms & 0x0080)) {
            return true;
        }

        if(strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            //on *nix platforms check also the owner
            $owner = fileowner($file);
            if($owner !== false && function_exists('posix_getuid') && $owner != posix_getuid()) { //files with changed owner
                return false;
            }
        }

        if(!@chmod($file, $perms | 0x0100 | 0x0080)) {
            return false;
        }
        return true;
    }

    //TODO specific to Media Lib., move accordingly
    protected function doRestore($attachmentID, $rawMeta = null) {
        do_action("shortpixel_before_restore_image", $attachmentID);

        $file = $origFile = get_attached_file($attachmentID);

        $itemHandler = new ShortPixelMetaFacade($attachmentID);
        if($rawMeta) {
            $itemHandler->setRawMeta($rawMeta); //prevent another database trip
        } else {
            $itemHandler->getMeta();
            $rawMeta = $itemHandler->getRawMeta();
        }
        try {
            $toUnlink = $itemHandler->getURLsAndPATHs(true, false, true, array(), true);
        } catch(Exception $e) {
            //maybe better not notify, as I encountered a case when the post was actually an iframe and the _wp_attachment_metadata contained its size.
            //$this->throwNotice('generic-err', $e->getMessage());
            return false;
        }

        $pathInfo = pathinfo($file);
        $sizes = isset($rawMeta["sizes"]) ? $rawMeta["sizes"] : array();

        //check if the images were converted from PNG
        $png2jpgMain = isset($rawMeta['ShortPixelPng2Jpg']['originalFile']) ? $rawMeta['ShortPixelPng2Jpg']['originalFile'] : false;
        $bkFolder = $this->getBackupFolderAny($file, $sizes);
        $toReplace = array();
        if($png2jpgMain) {
            $png2jpgSizes = $png2jpgMain ? $rawMeta['ShortPixelPng2Jpg']['originalSizes'] : array();
            $image = $rawMeta['file'];
            $imageUrl = wp_get_attachment_url($attachmentID);
            $baseUrl = ShortPixelPng2Jpg::removeUrlProtocol(trailingslashit(str_replace($image, "", $imageUrl))); //make the base url protocol agnostic if it's not already
            $baseRelPath = trailingslashit(dirname($image));
            $toReplace[ShortPixelPng2Jpg::removeUrlProtocol($imageUrl)] = $baseUrl . $baseRelPath . wp_basename($png2jpgMain);
            foreach($sizes as $key => $size) {
                if(isset($png2jpgSizes[$key])) {
                    $toReplace[$baseUrl . $baseRelPath . $size['file']] = $baseUrl . $baseRelPath . wp_basename($png2jpgSizes[$key]['file']);
                }
            }
            $file = $png2jpgMain;
            $sizes = $png2jpgSizes;
        }
        $bkFile = trailingslashit($bkFolder) . ShortPixelAPI::MB_basename($file);

        //first check if the file is readable by the current user - otherwise it will be unaccessible for the web browser
        // - collect the thumbs paths in the process
        $bkCount = 0;
        if(isset($rawMeta["ShortPixel"]['ErrCode'])) {
            $lastStatus = $this->_settings->bulkLastStatus;
            if(isset($lastStatus['ImageID']) && $lastStatus['ImageID'] == $attachmentID) {
                $this->_settings->bulkLastStatus = null;
            }
        }
        if(file_exists($bkFile)) {
            if(!is_readable($bkFile) || (file_exists($file) && !$this->setFilePerms($file)) ) {
                $this->throwNotice('generic-err',
                    sprintf(__("File %s cannot be restored due to lack of permissions, please contact your hosting provider to assist you in fixing this.",'shortpixel-image-optimiser'),
                            (is_readable($bkFile) ? "" : "$bkFile and ") . "$file"));
                return false;
            }
            $bkCount++;
            $main = true;
        }
        $thumbsPaths = array();
        if($bkFolder && !empty($rawMeta['file']) && count($sizes) ) {
            foreach($sizes as $size => $imageData) {
                $dest = $pathInfo['dirname'] . '/' . $imageData['file'];
                $source = trailingslashit($bkFolder) . $imageData['file'];
                if(!file_exists($source)) continue; // if thumbs were not optimized, then the backups will not be there.
                if(!$this->setFilePerms($source) || (file_exists($dest) && !$this->setFilePerms($dest))) {
                    $failedFile = ($this->setFilePerms($bkFile) ? $file : $bkFile);
                    $this->throwNotice('generic-err',
                        sprintf(__("File %s cannot be restored due to lack of permissions, please contact your hosting provider to assist you in fixing this.",'shortpixel-image-optimiser'),
                                "$failedFile (current permissions: " . sprintf("%o", fileperms($failedFile)) . ")"));
                    return false;
                }
                $bkCount++;
                $thumbsPaths[$source] = $dest;
            }
        }
        if(!$bkCount) {
            $this->throwNotice('generic-err', __("No backup files found. Restore not performed.",'shortpixel-image-optimiser'));
            return false;
        }
        //either backups exist, or there was an error when trying to optimize, so it's normal no backup is present
        try {
            $width = false;
            if($bkCount) { // backups, if exist
                //main file
                if($main) {
                    $this->renameWithRetina($bkFile, $file);
                }
                //getSize to update meta if image was resized by ShortPixel
                if(file_exists($file)) {
                    $size = getimagesize($file);
                    $width = $size[0];
                    $height = $size[1];
                }

                //overwriting thumbnails
                foreach($thumbsPaths as $source => $destination) {
                    $this->renameWithRetina($source, $destination);
                }
            }

            $duplicates = ShortPixelMetaFacade::getWPMLDuplicates($attachmentID);
            foreach($duplicates as $ID) {
                //Added sanitizeMeta (improved with @unserialize) as per https://secure.helpscout.net/conversation/725053586/11656?folderId=1117588
                $crtMeta = $attachmentID == $ID ? $rawMeta : ShortPixelMetaFacade::sanitizeMeta(wp_get_attachment_metadata($ID));
                if(isset($crtMeta['previous_meta'])) continue;
                if(   isset($crtMeta["ShortPixelImprovement"]) && is_numeric($crtMeta["ShortPixelImprovement"])
                   && 0 + $crtMeta["ShortPixelImprovement"] < 5 && $this->_settings->under5Percent > 0) {
                    $this->_settings->under5Percent = $this->_settings->under5Percent - 1; // - (isset($crtMeta["ShortPixel"]["thumbsOpt"]) ? $crtMeta["ShortPixel"]["thumbsOpt"] : 0);
                }
                unset($crtMeta["ShortPixelImprovement"]);
                unset($crtMeta['ShortPixel']);
                unset($crtMeta['ShortPixelPng2Jpg']);
                delete_post_meta($ID, '_shortpixel_status');
                if($width && $height) {
                    $crtMeta['width'] = $width;
                    $crtMeta['height'] = $height;
                }
                if($png2jpgMain) {
                    $crtMeta['file'] = trailingslashit(dirname($crtMeta['file'])) . ShortPixelAPI::MB_basename($file);
                    update_attached_file($ID, $crtMeta['file']);
                    if($png2jpgSizes && count($png2jpgSizes)) {
                        $crtMeta['sizes'] = $png2jpgSizes;
                    } else {
                        //this was an image converted on upload, regenerate the thumbs using the PNG main image BUT deactivate temporarily the filter!!
                        remove_filter( 'wp_generate_attachment_metadata', 'shortPixelHandleImageUploadHook');
                        $crtMeta = wp_generate_attachment_metadata($ID, $png2jpgMain);
                        add_filter( 'wp_generate_attachment_metadata', 'shortPixelHandleImageUploadHook', 10, 2 );
                    }
                }
                //wp_update_attachment_metadata($ID, $crtMeta);
                update_post_meta($ID, '_wp_attachment_metadata', $crtMeta);
                if($attachmentID == $ID) { //copy back the metadata which will be returned.
                    $rawMeta = $crtMeta;
                }
            }

            if($png2jpgMain) {
                $spPng2Jpg = new ShortPixelPng2Jpg($this->_settings);
                $spPng2Jpg->png2JpgUpdateUrls(array(), $toReplace);
            }
            if(isset($toUnlink['PATHs'])) foreach($toUnlink['PATHs'] as $unlink) {
                if($png2jpgMain) {
                    WPShortPixel::log("PNG2JPG unlink $unlink");
                    @unlink($unlink);
                }
                //try also the .webp
                $unlinkWebpSymlink = trailingslashit(dirname($unlink)) . wp_basename($unlink, '.' . pathinfo($unlink, PATHINFO_EXTENSION)) . '.webp';
                $unlinkWebp = $unlink . '.webp';
                WPShortPixel::log("PNG2JPG unlink $unlinkWebp");
                @unlink($unlinkWebpSymlink);
                @unlink($unlinkWebp);
            }
        } catch(Exception $e) {
            $this->throwNotice('generic-err', $e->getMessage());
            return false;
        }

        do_action("shortpixel_after_restore_image", $attachmentID);
        return $rawMeta;
    }

    /**
     * used to store a notice to be displayed after the redirect, for ex. when having an error restoring.
     * @param string $when
     * @param string $extra
     */
    public function throwNotice($when = 'activate', $extra = '') {
        set_transient("shortpixel_thrown_notice", array('when' => $when, 'extra' => $extra), 120);
    }

    /** Checks if a notice was thrown
    * @return boolean true, if there are notices */
    protected function catchNotice() {
        $notice = get_transient("shortpixel_thrown_notice");
        if(isset($notice['when'])) {
            if($notice['when'] == 'spai' && ($this->_settings->deliverWebp == 0 || $this->_settings->deliverWebp == 3)) {
                delete_transient("shortpixel_thrown_notice");
                return true;
            }
            ShortPixelView::displayActivationNotice($notice['when'], $notice['extra']);
            delete_transient("shortpixel_thrown_notice");
            return true;
        }
        return false;
    }

    protected function renameWithRetina($bkFile, $file) {
        @rename($bkFile, $file);
        @rename($this->retinaName($bkFile), $this->retinaName($file));

    }

    protected function retinaName($file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        return substr($file, 0, strlen($file) - 1 - strlen($ext)) . "@2x." . $ext;
    }

    /** Restores a non-media-library image
    * @param int $ID image_id, without any prefixes
    */
    public function doCustomRestore($ID) {
        //$meta = $this->spMetaDao->getMeta($ID);
        // meta facade as a custom image
        $itemHandler = new ShortPixelMetaFacade('C-' . $ID);
        $meta = $itemHandler->getMeta();

        // do this before putting the meta down, since maybeDump check for last timestamp
        // do this before checks, so it can clear ahead, and in case or errors
        $URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);
        $this->maybeDumpFromProcessedOnServer($itemHandler, $URLsAndPATHs);

        // TODO On manual restore also put status to toRestore, then run this function.
        if(!$meta || ($meta->getStatus() != shortPixelMeta::FILE_STATUS_SUCCESS && $meta->getStatus() != shortpixelMeta::FILE_STATUS_TORESTORE ) )
        {
          return false;
        }

        $file = $meta->getPath();
        $fullSubDir = str_replace(get_home_path(), "", dirname($file)) . '/';
        $bkFile = SHORTPIXEL_BACKUP_FOLDER . '/' . $fullSubDir . ShortPixelAPI::MB_basename($file);

        if(file_exists($bkFile)) {
            $rename_result = @rename($bkFile, $file);
            if (! $rename_result)
            {
                Log::addError('Failure on rename to : ' . $file);
            }


            /* [BS] Reset all generated image meta. Bring back to start state.
            * Since Wpdb->prepare doesn't support 'null', zero values in this table should not be trusted */

            $meta->setTsOptimized(0);
            $meta->setCompressedSize(0);
            $meta->setCompressionType(0);
            $meta->setKeepExif(0);
            $meta->setCmyk2rgb(0);
            $meta->setMessage('');
            $meta->setRetries(0);
            $meta->setBackup(0);
            $meta->setResizeWidth(0);
            $meta->setResizeHeight(0);
            $meta->setResize(0);

            $meta->setStatus(3);
            $this->spMetaDao->update($meta);


        }
        else {
           Log::addWarn('File ' . $bkFile . ' not found in backups');
        }

        return $meta;
    }

    public function handleRestoreBackup() {
        $attachmentID = intval($_GET['attachment_ID']);

        self::log("Handle Restore Backup #{$attachmentID}");
        $this->doRestore($attachmentID);

        // get the referring webpage location
        $sendback = wp_get_referer();
        // sanitize the referring webpage location
        $sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
        // send the user back where they came from
        wp_redirect($sendback);
        // we are done
    }

    public function handleRedo() {
        self::log("Handle Redo #{$_GET['attachment_ID']} type {$_GET['type']}");

        die(json_encode($this->redo($_GET['attachment_ID'], $_GET['type'])));
    }

    public function redo($qID, $type = false) {
        $compressionType = ($type == 'lossless' ? 'lossless' : ($type == 'glossy' ? 'glossy' : 'lossy')); //sanity check

        if(ShortPixelMetaFacade::isCustomQueuedId($qID)) {
            $ID = ShortPixelMetaFacade::stripQueuedIdType($qID);
            $meta = $this->doCustomRestore($ID);
            if($meta) {
                $meta->setCompressionType(ShortPixelAPI::getCompressionTypeCode($compressionType));
                $meta->setStatus(1);
                $this->spMetaDao->update($meta);
                $this->prioQ->push($qID);
                $ret = array("Status" => ShortPixelAPI::STATUS_SUCCESS, "Message" => "");
            } else {
                $ret = array("Status" => ShortPixelAPI::STATUS_SKIP, "Message" => __('Could not restore from backup: ','shortpixel-image-optimiser') . $qID);
            }
        } else {
            $ID = intval($qID);
            $meta = $this->doRestore($ID);
            if($meta) { //restore succeeded
                $meta['ShortPixel'] = array("type" => $compressionType);
                //wp_update_attachment_metadata($ID, $meta);
                update_post_meta($ID, '_wp_attachment_metadata', $meta);
                try {
                    $this->sendToProcessing(new ShortPixelMetaFacade($ID), ShortPixelAPI::getCompressionTypeCode($compressionType));
                    $this->prioQ->push($ID);
                    $ret = array("Status" => ShortPixelAPI::STATUS_SUCCESS, "Message" => "");
                } catch(Exception $e) { // Exception("Post metadata is corrupt (No attachment URL)") or Exception("Image files are missing.")
                    $meta['ShortPixelImprovement'] = $e->getMessage();
                    $meta['ShortPixel']['ErrCode'] = $e->getCode() < 0 ? $e->getCode() : ShortPixelAPI::STATUS_FAIL;
                    unset($meta['ShortPixel']['WaitingProcessing']);
                    //wp_update_attachment_metadata($ID, $meta);
                    update_post_meta($ID, '_wp_attachment_metadata', $meta);
                    $ret = array("Status" => ShortPixelAPI::STATUS_FAIL, "Message" => $e->getMessage());
                }
            } else {
                $ret = array("Status" => ShortPixelAPI::STATUS_SKIP, "Message" => __('Could not restore from backup: ','shortpixel-image-optimiser') . $ID);
            }
        }
        return $ret;
    }

    // TODO - [BS] json_encode should be replaced by a call to shortPixelTools:sendJson, but this crashes the JS parse - for some reason -
    public function handleOptimizeThumbs() {
        $ID = intval($_GET['attachment_ID']);
        $meta = wp_get_attachment_metadata($ID);

        // default return;
        //$ret = array("Status" => ShortPixelAPI::STATUS_SKIP, "message" => (isset($meta['ShortPixelImprovement']) ? __('No thumbnails to optimize for ID: ','shortpixel-image-optimiser') : __('Please optimize image for ID: ','shortpixel-image-optimiser')) . $ID);
        $error = array('Status' => ShortPixelAPI::STATUS_SKIP, 'message' => __('Unspecified Error on Thumbnails for: ') . $ID);

        $includedSizes = WpShortPixelMediaLbraryAdapter::getSizesNotExcluded($meta['sizes'], $this->_settings->excludeSizes);
        $thumbsCount = count($includedSizes);

        if (! isset($meta['ShortPixelImprovement']))
        {
            $error['message'] = __('Please optimize image for ID: ','shortpixel-image-optimiser') . $ID;
            die(json_encode($error));
        }

        if (! isset($meta['sizes']) || count($meta['sizes']) == 0)
        {
            $error['message'] = __('No thumbnails to optimize for ID: ','shortpixel-image-optimiser') . $ID;
            die(json_encode($error));
        }


        /* Check ThumbList against current Sizes. It's possible when a size was dropped, the SP meta was not updated, playing
        * tricks with the thumbcount.
        *
        */
        if (isset($meta['ShortPixel']['thumbsOptList']) && is_array($meta['ShortPixel']['thumbsOptList']))
        {
          $thumbList = array();
          foreach($meta['ShortPixel']['thumbsOptList'] as $fileName)
          {
              if (isset($includedSizes[$fileName]))
              {
                  $thumbList[] = $fileName;
              }
          }
          $meta['ShortPixel']['thumbsOptList'] = $thumbList;
        }

/*
        if (isset($meta['Shortpixel']['thumbsOptList']))
        {
          $sizeFiles = array();
          foreach($sizeFiles as $size => $data)
          {
            $file = pathinfo($data['file'], )
          }

        } */

/*        if( $thumbsCount
           && ( !isset($meta['ShortPixel']['thumbsOpt']) || $meta['ShortPixel']['thumbsOpt'] == 0
                || (isset($meta['sizes']) && isset($meta['ShortPixel']['thumbsOptList']) && $meta['ShortPixel']['thumbsOpt'] < $thumbsCount))) { //optimized without thumbs, thumbs exist */

          if( $thumbsCount
                && (isset($meta['sizes']) && isset($meta['ShortPixel']['thumbsOptList']) && count($meta['ShortPixel']['thumbsOptList']) < $thumbsCount))
          {
            $meta['ShortPixel']['thumbsTodo'] = true;

            //wp_update_attachment_metadata($ID, $meta);
            update_post_meta($ID, '_wp_attachment_metadata', $meta);
            $this->prioQ->push($ID);
            try {
                $this->sendToProcessing(new ShortPixelMetaFacade($ID), false, true);
                $ret = array("Status" => ShortPixelAPI::STATUS_SUCCESS, "message" => "");
            } catch(Exception $e) { // Exception("Post metadata is corrupt (No attachment URL)") or Exception("Image files are missing.")
                if(!isset($meta['ShortPixelImprovement']) || !is_numeric($meta['ShortPixelImprovement'])) {
                    $meta['ShortPixelImprovement'] = $e->getMessage();
                    $meta['ShortPixel']['ErrCode'] = $e->getCode() < 0 ? $e->getCode() : ShortPixelAPI::STATUS_FAIL;
                    unset($meta['ShortPixel']['WaitingProcessing']);
                    //wp_update_attachment_metadata($ID, $meta);
                    update_post_meta($ID, '_wp_attachment_metadata', $meta);
                }
                $ret = array("Status" => ShortPixelAPI::STATUS_FAIL, "Message" => $e->getMessage());
            }
        } else {
            $ret = array("Status" => ShortPixelAPI::STATUS_SKIP, "message" => (isset($meta['ShortPixelImprovement']) ? __('No thumbnails to optimize for ID: ','shortpixel-image-optimiser') : __('Please optimize image for ID: ','shortpixel-image-optimiser')) . $ID);
        }
        //shortPixelTools::sendJSON($ret);
        die(json_encode($ret));
    }

    public function handleCheckQuota() {
        $this->getQuotaInformation();
        // store the referring webpage location
        $sendback = wp_get_referer();
        // sanitize the referring webpage location
        $sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
        // send the user back where they came from
        wp_redirect($sendback);
        // we are done
    }

    public function handleCheckQuotaAjax() {
        $this->getQuotaInformation();
    }

    public function handleDeleteAttachmentInBackup($ID) {
        $file = get_attached_file($ID);
        $meta = wp_get_attachment_metadata($ID);


        if(self::_isProcessable($ID) != false) //we use the static isProcessable to bypass the exclude patterns
        {
            try {
                    $SubDir = ShortPixelMetaFacade::returnSubDir($file);

                    @unlink(SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir . ShortPixelAPI::MB_basename($file));

                    if ( !empty($meta['file']) )
                    {
                        $filesPath =  SHORTPIXEL_BACKUP_FOLDER . '/' . $SubDir;//base BACKUP path
                        //remove thumbs thumbnails
                        if(isset($meta["sizes"])) {
                            foreach($meta["sizes"] as $size => $imageData) {
                                @unlink($filesPath . ShortPixelAPI::MB_basename($imageData['file']));//remove thumbs
                            }
                        }
                    }

                } catch(Exception $e) {
                //what to do, what to do?
            }
        }
    }

    /** Runs on plugin deactivation
    * @hook admin_post_shortpixel_deactivate_plugin
    */
    public function deactivatePlugin() {
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'sp_deactivate_plugin_nonce' ) ) {
                wp_nonce_ays( '' );
        }

        $referrer_url = wp_get_referer();
        $conflict = $this->getConflictingPlugins();
        foreach($conflict as $c => $value) {
            $conflictingString = $value['page'];
            if($conflictingString != null && strpos($referrer_url, $conflictingString) !== false){
                $this->deactivateAndRedirect(get_dashboard_url());
                break;
            }
        }
        $this->deactivateAndRedirect(wp_get_referer());

    }

    /** Deactivates plugin and redirects
    * @param string @url URL to redirect after deactivate
    */
    protected function deactivateAndRedirect($url){
        //die(ShortPixelVDD($url));
        deactivate_plugins( sanitize_text_field($_GET['plugin']) );
        wp_safe_redirect( $url );
        die();

    }

    public function countAllIfNeeded($quotaData, $time) {
        if( !(defined('SHORTPIXEL_DEBUG') && SHORTPIXEL_DEBUG === true) && is_array($this->_settings->currentStats)
           && $this->_settings->currentStats['optimizePdfs'] == $this->_settings->optimizePdfs
           && isset($this->_settings->currentStats['time'])
           && (time() - $this->_settings->currentStats['time'] < $time))
        {
            return $this->_settings->currentStats;
        } else {
            $imageCount = WpShortPixelMediaLbraryAdapter::countAllProcessableFiles($this->_settings);
            $quotaData['time'] = time();
            $quotaData['optimizePdfs'] = $this->_settings->optimizePdfs;
            //$quotaData['quotaData'] = $quotaData;
            foreach($imageCount as $key => $val) {
                $quotaData[$key] = $val;
            }

            if($this->_settings->hasCustomFolders) {
                $customImageCount = $this->spMetaDao->countAllProcessableFiles();
                foreach($customImageCount as $key => $val) {
                    $quotaData[$key] = isset($quotaData[$key])
                                       ? (is_array($quotaData[$key])
                                          ? array_merge($quotaData[$key], $val)
                                          : (is_numeric($quotaData[$key])
                                             ? $quotaData[$key] + $val
                                             : $quotaData[$key] . ", " . $val)) //array
                                       : $val; //string
                }
            }
            $this->_settings->currentStats = $quotaData;
            return $quotaData;
        }
    }

    public function checkQuotaAndAlert($quotaData = null, $recheck = false, $refreshFiles = 300) {
        if(!$quotaData) {
            $quotaData = $this->getQuotaInformation();
        }
        if ( !$quotaData['APIKeyValid']) {
            if(strlen($this->_settings->apiKey))
                Notice::addError(sprintf(__('Shortpixel Remote API Error: %s','shortpixel-image-optimizer'), $quotaData['Message'] ));
            return $quotaData;
        }
        //$tempus = microtime(true);
        $quotaData = $this->countAllIfNeeded($quotaData, $refreshFiles);
        //echo("Count took (seconds): " . (microtime(true) - $tempus));

        if($quotaData['APICallsQuotaNumeric'] + $quotaData['APICallsQuotaOneTimeNumeric'] > $quotaData['APICallsMadeNumeric'] + $quotaData['APICallsMadeOneTimeNumeric']) {
            $this->_settings->quotaExceeded = '0';
            $this->_settings->prioritySkip = NULL;
            Log::addInfo("CHECK QUOTA: Skipped: ".json_encode($this->prioQ->getSkipped()));

            ?><script>var shortPixelQuotaExceeded = 0;</script><?php
        }
        else {
            $this->view->displayQuotaExceededAlert($quotaData, self::getAverageCompression(), $recheck);
            ?><script>var shortPixelQuotaExceeded = 1;</script><?php
        }
        return $quotaData;
    }

    /** Checks if ID has a meta component
    * @param int $id $imageId
    * @return string|array|null Returns array custom metadata ( or null ) or URL to attachment.
    */
    public function isValidMetaId($id) {
        return substr($id, 0, 2 ) == "C-" ? $this->spMetaDao->getMeta(substr($id, 2)) : wp_get_attachment_url($id);
    }

    /** View for Custom media
    * @todo Move this
    */
    public function listCustomMedia() {
        if( ! class_exists( 'ShortPixelListTable' ) ) {
            require_once('view/shortpixel-list-table.php');
        }
        if(isset($_REQUEST['refresh']) && esc_attr($_REQUEST['refresh']) == 1) {
            $notice = null;
            $this->refreshCustomFolders($notice);
        }
        if(isset($_REQUEST['action']) && esc_attr($_REQUEST['action']) == 'optimize' && isset($_REQUEST['image'])) {
            //die(ShortPixelMetaFacade::queuedId(ShortPixelMetaFacade::CUSTOM_TYPE, $_REQUEST['image']));
            $this->prioQ->push(ShortPixelMetaFacade::queuedId(ShortPixelMetaFacade::CUSTOM_TYPE, $_REQUEST['image']));
        }

        $customMediaListTable = new ShortPixelListTable($this, $this->spMetaDao, $this->hasNextGen);
        $items = $customMediaListTable->prepare_items();
        if ( isset($_GET['noheader']) ) {
            require_once(ABSPATH . 'wp-admin/admin-header.php');
        }
        //$this->outputHSBeacon();
        \ShortPixel\HelpScout::outputBeacon($this->getApiKey());
        ?>
	    <div class="wrap shortpixel-other-media">
            <h2>
                <?php _e('Other Media optimized by ShortPixel','shortpixel-image-optimiser');?>
            </h2>

            <div id="legacy">
                <div id="legacy" class="metabox-holder">
                    <div id="legacy">
                      <div style="float:left;">
                          <a href="upload.php?page=wp-short-pixel-custom&refresh=1" id="refresh" class="button button-primary" title="<?php _e('Refresh custom folders content','shortpixel-image-optimiser');?>">
                              <?php _e('Refresh folders','shortpixel-image-optimiser');?>
                          </a>
                      </div>
                        <div class="meta-box-sortables ui-sortable">
                            <form method="get">
                                <input type="hidden" name="page" value="wp-short-pixel-custom" />
                                <?php $customMediaListTable->search_box("Search", "sp_search_file"); ?>
                            </form>
                            <form method="post" class="shortpixel-table">
                                <?php
                                $customMediaListTable->display();
                                //push to the processing list the pending ones, just in case
                                //$count = $this->spMetaDao->getCustomMetaCount();
                                if($this->_settings->autoMediaLibrary) foreach ($items as $item) {
                                    if($item->status == 1){
                                        $this->prioQ->push(ShortPixelMetaFacade::queuedId(ShortPixelMetaFacade::CUSTOM_TYPE, $item->id));
                                    }
                                }
                                ?>
                            </form>
                        </div>
                    </div>
                </div>
                <br class="clear">
            </div>
	</div> <?php
    }

    /** Front End function that controls bulk processes.
    * TODO This is a Bulk controller
    */
    public function bulkProcess() {
        global $wpdb;

        if( $this->_settings->verifiedKey == false ) {//invalid API Key
            ShortPixelView::displayActivationNotice();
            return;
        }

        $quotaData = $this->checkQuotaAndAlert(null, isset($_GET['checkquota']), 0);
        //if($this->_settings->quotaExceeded != 0) {
            //return;
        //}

        if(isset($_POST['bulkProcessPause']))
        {//pause an ongoing bulk processing, it might be needed sometimes
            $this->prioQ->pauseBulk();
            if($this->_settings->hasCustomFolders && $this->spMetaDao->getPendingMetaCount()) {
                $this->_settings->customBulkPaused = 1;
            }
        }

        if(isset($_POST['bulkProcessStop']))
        {//stop an ongoing bulk processing
            $this->prioQ->stopBulk();
            if($this->_settings->hasCustomFolders && $this->spMetaDao->getPendingMetaCount()) {
                $this->_settings->customBulkPaused = 1;
            }
            $this->_settings->cancelPointer = NULL;
        }

        if(isset($_POST["bulkProcess"]))
        {
            //set the thumbnails option
            if ( isset($_POST['thumbnails']) ) {
                $this->_settings->processThumbnails = 1;
            } else {
                $this->_settings->processThumbnails = 0;
            }
            //clean the custom files errors in order to process them again
            if($this->_settings->hasCustomFolders) {
                $this->spMetaDao->resetFailed();
                $this->spMetaDao->resetRestored();

            }

            $this->prioQ->startBulk(ShortPixelQueue::BULK_TYPE_OPTIMIZE);
            $this->_settings->customBulkPaused = 0;
            self::log("BULK:  Start:  " . $this->prioQ->getStartBulkId() . ", stop: " . $this->prioQ->getStopBulkId() . " PrioQ: "
                 .json_encode($this->prioQ->get()));
        }//end bulk process  was clicked

        if(isset($_POST["bulkRestore"]))
        {
            $bulkRestore = new \ShortPixel\BulkRestoreAll(); // controller
            $bulkRestore->setShortPixel($this);
            $bulkRestore->setupBulk();

            $this->prioQ->startBulk(ShortPixelQueue::BULK_TYPE_RESTORE);
            $this->_settings->customBulkPaused = 0;
        }//end bulk restore  was clicked

        if(isset($_POST["bulkCleanup"]))
        {
            $this->prioQ->startBulk(ShortPixelQueue::BULK_TYPE_CLEANUP);
            $this->_settings->customBulkPaused = 0;
        }//end bulk restore  was clicked

        if(isset($_POST["bulkCleanupPending"]))
        {
            $this->prioQ->startBulk(ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING);
            $this->_settings->customBulkPaused = 0;
        }//end bulk restore  was clicked

        if(isset($_POST["bulkProcessResume"]))
        {
            $this->prioQ->resumeBulk();
            $this->_settings->customBulkPaused = 0;
        }//resume was clicked

        if(isset($_POST["skipToCustom"]))
        {
            $this->_settings->skipToCustom = true;
        }//resume was clicked

        //figure out the files that are left to be processed
        $qry_left = "SELECT count(*) FilesLeftToBeProcessed FROM " . $wpdb->prefix . "postmeta
        WHERE meta_key = '_wp_attached_file' AND post_id <= " . (0 + $this->prioQ->getStartBulkId());
        $filesLeft = $wpdb->get_results($qry_left);

        //check the custom bulk
        $pendingMeta = $this->_settings->hasCustomFolders ? $this->spMetaDao->getPendingMetaCount() : 0;

        if (   ($filesLeft[0]->FilesLeftToBeProcessed > 0 && $this->prioQ->bulkRunning())
            || (0 + $pendingMeta > 0 && !$this->_settings->customBulkPaused && $this->prioQ->bulkRan())//bulk processing was started
                && (!$this->prioQ->bulkPaused() || $this->_settings->skipToCustom)) //bulk not paused or if paused, user pressed Process Custom button
        {
            $msg = $this->bulkProgressMessage($this->prioQ->getDeltaBulkPercent(), $this->prioQ->getTimeRemaining());

            $this->view->displayBulkProcessingRunning($this->getPercent($quotaData), $msg, $quotaData['APICallsRemaining'], $this->getAverageCompression(),
                     $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_RESTORE ? 0 :
                    (   $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_CLEANUP
                     || $this->prioQ->getBulkType() == ShortPixelQueue::BULK_TYPE_CLEANUP_PENDING ? -1 : ($pendingMeta !== null ? ($this->prioQ->bulkRunning() ? 3 : 2) : 1)), $quotaData);

        } else
        {
            if($this->prioQ->bulkRan() && !$this->prioQ->bulkPaused()) {
                $this->prioQ->markBulkComplete();
            }

            //image count
            $thumbsProcessedCount = $this->_settings->thumbsCount;//amount of optimized thumbnails
            $under5PercentCount =  $this->_settings->under5Percent;//amount of under 5% optimized imgs.

            //average compression
            $averageCompression = self::getAverageCompression();
            $percent = $this->prioQ->bulkPaused() ? $this->getPercent($quotaData) : false;

            // [BS] If some template part is around, use it and find the controller.
            $template_part = isset($_GET['part']) ? sanitize_text_field($_GET['part']) : false;
            $controller = ShortPixelTools::namespaceit('ShortPixelController');
            $partControl = $controller::findControllerbySlug($template_part);

            if ($partControl)
            {
              $viewObj = new $partControl();
              $viewObj->setShortPixel($this);
              $viewObj->loadView(); // TODO [BS] This should call load, which should init and call view inside controller.
            }

            if (! $template_part)
            {
              $this->view->displayBulkProcessingForm($quotaData, $thumbsProcessedCount, $under5PercentCount,
                    $this->prioQ->bulkRan(), $averageCompression, $this->_settings->fileCount,
                    self::formatBytes($this->_settings->savedSpace), $percent, $pendingMeta);
            }
        }
    }
    //end bulk processing

    public function getPercent($quotaData) {
            if($this->_settings->processThumbnails) {
                return $quotaData["totalFiles"] ? min(99, round($quotaData["totalProcessedFiles"]  *100.0 / $quotaData["totalFiles"])) : 0;
            } else {
                return $quotaData["mainFiles"] ? min(99, round($quotaData["mainProcessedFiles"]  *100.0 / $quotaData["mainFiles"])) : 0;
            }
    }

    // TODO - Calculate time left Utility function -Called in bulkProcess.
    public function bulkProgressMessage($percent, $minutes) {
        $timeEst = "";
        self::log("bulkProgressMessage(): percent: " . $percent);
        if($percent < 1 || $minutes == 0) {
            $timeEst = "";
        } elseif( $minutes > 2880) {
            $timeEst = "~ " . round($minutes / 1440) . " days left";
        } elseif ($minutes > 240) {
            $timeEst = "~ " . round($minutes / 60) . " hours left";
        } elseif ($minutes > 60) {
            $hours = round($minutes / 60);
            $minutes = round(max(0, $minutes - $hours * 60) / 10) * 10;
            $timeEst = "~ " . $hours . " hours " . ($minutes > 0 ? $minutes . " min." : "") . " left";
        } elseif ($minutes > 20) {
            $timeEst = "~ " . round($minutes / 10) * 10 . " minutes left";
        } else {
            $timeEst = "~ " . $minutes . " minutes left";
        }
        return $timeEst;
    }

    // TODO - Folder Model action
    public function emptyBackup(){
            if(file_exists(SHORTPIXEL_BACKUP_FOLDER)) {

                //extract all images from DB in an array. of course
                // Simon: WHY?!!! commenting for now...
                /*
                $attachments = null;
                $attachments = get_posts( array(
                    'numberposts' => -1,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image'
                ));
                */

                //delete the actual files on disk
                $this->deleteDir(SHORTPIXEL_BACKUP_FOLDER);//call a recursive function to empty files and sub-dirs in backup dir
            }
    }


    public function backupFolderIsEmpty() {
        if(file_exists(SHORTPIXEL_BACKUP_FOLDER)) {
            return count(scandir(SHORTPIXEL_BACKUP_FOLDER)) > 2 ? false : true;
        }
    }

    public function getBackupSize() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die(__('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser'));
        }
        die(self::formatBytes(self::folderSize(SHORTPIXEL_BACKUP_FOLDER)));
    }

    public function browseContent() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die(__('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser'));
        }

        $root = self::getCustomFolderBase();


        $postDir = rawurldecode($root.(isset($_POST['dir']) ? trim($_POST['dir']) : null ));
        // set checkbox if multiSelect set to true
        $checkbox = ( isset($_POST['multiSelect']) && $_POST['multiSelect'] == 'true' ) ? "<input type='checkbox' />" : null;
        $onlyFolders = ($_POST['dir'] == '/' || isset($_POST['onlyFolders']) && $_POST['onlyFolders'] == 'true' ) ? true : false;
        $onlyFiles = ( isset($_POST['onlyFiles']) && $_POST['onlyFiles'] == 'true' ) ? true : false;

        if( file_exists($postDir) ) {

            $files = scandir($postDir);
            $returnDir	= substr($postDir, strlen($root));

            natcasesort($files);

            if( count($files) > 2 ) { // The 2 accounts for . and ..
                echo "<ul class='jqueryFileTree'>";
                foreach( $files as $file ) {

                    if($file == 'ShortpixelBackups' || ShortPixelMetaFacade::isMediaSubfolder($postDir . $file, false)) continue;

                    $htmlRel	= str_replace("'", "&apos;", $returnDir . $file);
                    $htmlName	= htmlentities($file);
                    $ext	= preg_replace('/^.*\./', '', $file);

                    if( file_exists($postDir . $file) && $file != '.' && $file != '..' ) {
                        //KEEP the spaces in front of the rel values - it's a trick to make WP Hide not replace the wp-content path
                        if( is_dir($postDir . $file) && (!$onlyFiles || $onlyFolders) ) {
                            echo "<li class='directory collapsed'>{$checkbox}<a rel=' " .$htmlRel. "/'>" . $htmlName . "</a></li>";
                        } else if (!$onlyFolders || $onlyFiles) {
                            echo "<li class='file ext_{$ext}'>{$checkbox}<a rel=' " . $htmlRel . "'>" . $htmlName . "</a></li>";
                        }
                    }
                }

                echo "</ul>";
            }
        }
        die();
    }

    /** Gets data for image comparison. Returns JSON
    *
    * @return json JSON data.
    * TODO - Should return via JSON function in tools
    */
    public function getComparerData() {
        if (!isset($_POST['id']) || !current_user_can( 'upload_files' ) && !current_user_can( 'edit_posts' ) )  {
            wp_die(json_encode((object)array('origUrl' => false, 'optUrl' => false, 'width' => 0, 'height' => 0)));
        }

        $ret = array();
        // This shall not be Intval, since Post_id can be custom (C-xx)
        $handle = new ShortPixelMetaFacade( sanitize_text_field($_POST['id']) );

        $meta = $handle->getMeta();
        $rawMeta = $handle->getRawMeta();
        $backupUrl = content_url() . "/" . SHORTPIXEL_UPLOADS_NAME . "/" . SHORTPIXEL_BACKUP . "/";
        $uploadsUrl = ShortPixelMetaFacade::getHomeUrl();
        $urlBkPath = ShortPixelMetaFacade::returnSubDir($meta->getPath());
        $ret['origUrl'] = $backupUrl . $urlBkPath . $meta->getName();
        if ($meta->getType() == ShortPixelMetaFacade::CUSTOM_TYPE)
        {
          $ret['optUrl'] =  $uploadsUrl . $meta->getWebPath();
          self::log('Getting image - ' . $urlBkPath . $meta->getPath());
          // [BS] Another bug? Width / Height not stored in Shortpixel meta.
          $ret['width'] = $meta->getActualWidth();
          $ret['height'] = $meta->getActualHeight();

          if (is_null($ret['width']))
          {

          //  $imageSizes = getimagesize($ret['optUrl']);
          // [BS] Fix - Use real path instead of URL on getimagesize.
            $imageSizes = getimagesize($meta->getPath());

            if ($imageSizes)
            {
              $ret['width'] = $imageSizes[0];
              $ret['height']= $imageSizes[1];
            }
          }
        }
        else
        {
          $ret['optUrl'] = wp_get_attachment_url( $_POST['id'] ); //$uploadsUrl . $urlBkPath . $meta->getName();
          $ret['width'] = $rawMeta['width'];
          $ret['height'] = $rawMeta['height'];
        }

        die(json_encode((object)$ret));
    }

    // TODO This could be something in an install class.
    public function newApiKey() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die(__('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser'));
        }
        if( $this->_settings->verifiedKey ) {
            die(json_encode((object)array('Status' => 'success', 'Details' => $this->_settings->apiKey)));
        }
        $params = array(
            'method' => 'POST',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'sslverify' => false,
            'headers' => array(),
            'body' => array(
                'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
                'email' => isset($_POST['email']) ? trim($_POST['email']) : null,
                'ip' => isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? $_SERVER["HTTP_X_FORWARDED_FOR"]: $_SERVER['REMOTE_ADDR'],
                //'XDEBUG_SESSION_START' => 'session_name'
            )
        );

        $newKeyResponse = wp_remote_post("https://shortpixel.com/free-sign-up-plugin", $params);

        if ( is_object($newKeyResponse) && get_class($newKeyResponse) == 'WP_Error' ) {
            die(json_encode((object)array('Status' => 'fail', 'Details' => '503')));
        }
        elseif ( isset($newKeyResponse['response']['code']) && $newKeyResponse['response']['code'] <> 200 ) {
            die(json_encode((object)array('Status' => 'fail', 'Details' => $newKeyResponse['response']['code'])));
        }
        $body = (object)$this->_apiInterface->parseResponse($newKeyResponse);
        if($body->Status == 'success') {
            $key = trim($body->Details);
            $validityData = $this->getQuotaInformation($key, true, true);
            if($validityData['APIKeyValid']) {
                $this->_settings->apiKey = $key;
                $this->_settings->verifiedKey = true;
            }
        }
        die(json_encode($body));

    }

    public function proposeUpgrade() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die(__('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser'));
        }

        $stats = $this->countAllIfNeeded($this->_settings->currentStats, 300);

        //$proposal = wp_remote_post($this->_settings->httpProto . "://shortpixel.com/propose-upgrade-frag", array(
        //echo("<div style='color: #f50a0a; position: relative; top: -59px; right: -255px; height: 0px; font-weight: bold; font-size: 1.2em;'>atentie de trecut pe live propose-upgrade</div>");
        $proposal = wp_remote_post("https://shortpixel.com/propose-upgrade-frag", array(
            'method' => 'POST',
            'timeout' => 10,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => array("params" => json_encode(array(
                'plugin_version' => SHORTPIXEL_IMAGE_OPTIMISER_VERSION,
                'key' => $this->_settings->apiKey,
                //'m1' => 4125, 'm2' => 3392, 'm3' => 3511, 'm4' => 2921, 'filesTodo' => 23143, 'estimated' => 'true'
                //'m1' => 3125, 'm2' => 2392, 'm3' => 2511, 'm4' => 1921, 'filesTodo' => 23143, 'estimated' => 'true'
                //'m1' => 1925, 'm2' => 1392, 'm3' => 1511, 'm4' => 1721, 'filesTodo' => 30143, 'estimated' => 'false'
                //'m1' => 2125, 'm2' => 1392, 'm3' => 1511, 'm4' => 1921, 'filesTodo' => 13143, 'estimated' => 'false'
                //'m1' => 13125, 'm2' => 22392, 'm3' => 12511, 'm4' => 31921, 'filesTodo' => 93143, 'estimated' => 'true'
                //'m1' => 925, 'm2' => 592, 'm3' => 711, 'm4' => 121, 'filesTodo' => 2143, 'estimated' => 'true'
                //'m1' => 4625, 'm2' => 4592, 'm3' => 4711, 'm4' => 4121, 'filesTodo' => 51143, 'estimated' => 'true'
                //'m1' => 4625, 'm2' => 4592, 'm3' => 4711, 'm4' => 4121, 'filesTodo' => 41143, 'estimated' => 'true'
                //'m1' => 7625, 'm2' => 6592, 'm3' => 6711, 'm4' => 5121, 'filesTodo' => 41143, 'estimated' => 'true'
                //'m1' => 1010, 'm2' => 4875, 'm3' => 2863, 'm4' => 1026, 'filesTodo' => 239595, 'estimated' => 'true',
                'm1' => $stats['totalM1'],
                'm2' => $stats['totalM2'],
                'm3' => $stats['totalM3'],
                'm4' => $stats['totalM4'],
                'filesTodo' => $stats['totalFiles'] - $stats['totalProcessedFiles'],
                'estimated' => $this->_settings->optimizeUnlisted || $this->_settings->optimizeRetina ? 'true' : 'false',
                /* */
                'iconsUrl' => base64_encode(plugins_url('/shortpixel-image-optimiser/res/img'))
            ))),
            'cookies' => array()
        ));
        if(is_wp_error( $proposal )) {
            $proposal = array('body' => '');
        }
        die($proposal['body']);

    }

    // TODO - Part of the folder model.
    public static function getCustomFolderBase() {
        if(is_main_site()) {
            $base = get_home_path();
            return realpath(rtrim($base, '/'));
        } else {
            $up = wp_upload_dir();
            return realpath($up['basedir']);
        }
    }

    // TODO - Should be part of folder model
    protected function fullRefreshCustomFolder($path, &$notice) {
        $folder = $this->spMetaDao->getFolder($path);
        $diff = $folder->checkFolderContents(array('ShortPixelCustomMetaDao', 'getPathFiles'));
    }

    // @todo - Should be part of folder model
    public function refreshCustomFolders(&$notice, $ignore = false) {
        $customFolders = array();
        if($this->_settings->hasCustomFolders) {
            $customFolders = $this->spMetaDao->getFolders();
            foreach($customFolders as $folder) {
                if($folder->getPath() === $ignore) continue;
                try {
                    $mt = $folder->getFolderContentsChangeDate();
                    if($mt > strtotime($folder->getTsUpdated())) {
                        $fileList = $folder->getFileList(strtotime($folder->getTsUpdated()));
                        $this->spMetaDao->batchInsertImages($fileList, $folder->getId());
                        $folder->setTsUpdated(date("Y-m-d H:i:s", $mt));
                        $folder->setFileCount($folder->countFiles());
                        $this->spMetaDao->update($folder);
                    }
                } catch(ShortPixelFileRightsException $ex) {
                    if(is_array($notice)) {
                        if($notice['status'] == 'error') {
                            $notice['msg'] .= " " . $ex->getMessage();
                        }
                    } else {
                        $notice = array("status" => "error", "msg" => $ex->getMessage());
                    }
                }
            }
        }
        return $customFolders;
    }

    /** Updates HTAccess files for Webp
    * @param boolean $clear Clear removes all statements from htaccess. For disabling webp.
    */
    public static function alterHtaccess( $clear = false ){
      // [BS] Backward compat. 11/03/2019 - remove possible settings from root .htaccess
        $upload_dir = wp_upload_dir();
        $upload_base = trailingslashit($upload_dir['basedir']);

        if ( $clear ) {
            insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '');
            insert_with_markers( $upload_base . '.htaccess', 'ShortPixelWebp', '');
            insert_with_markers( trailingslashit(WP_CONTENT_DIR) . '.htaccess', 'ShortPixelWebp', '');
        } else {

            $rules = '
<IfModule mod_rewrite.c>
  RewriteEngine On

  ##### TRY FIRST the file appended with .webp (ex. test.jpg.webp) #####
  # Does browser explicitly support webp?
  RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
  # OR Is request from Page Speed
  RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
  # OR does this browser explicitly support webp
  RewriteCond %{HTTP_ACCEPT} image/webp
  # AND is the request a jpg or png?
  RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png)$
  # AND does a .ext.webp image exist?
  RewriteCond %{DOCUMENT_ROOT}%{REQUEST_URI}.webp -f
  # THEN send the webp image and set the env var webp
  RewriteRule ^(.+)$ $1.webp [NC,T=image/webp,E=webp,L]

  ##### IF NOT, try the file with replaced extension (test.webp) #####
  RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
  RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
  RewriteCond %{HTTP_ACCEPT} image/webp
  # AND is the request a jpg or png? (also grab the basepath %1 to match in the next rule)
  RewriteCond %{REQUEST_URI} ^(.+)\.(?:jpe?g|png)$
  # AND does a .ext.webp image exist?
  RewriteCond %{DOCUMENT_ROOT}/%1.webp -f
  # THEN send the webp image and set the env var webp
  RewriteRule (.+)\.(?:jpe?g|png)$ $1.webp [NC,T=image/webp,E=webp,L]

</IfModule>
<IfModule mod_headers.c>
  # If REDIRECT_webp env var exists, append Accept to the Vary header
  Header append Vary Accept env=REDIRECT_webp
</IfModule>

<IfModule mod_mime.c>
  AddType image/webp .webp
</IfModule>
        ' ;

            insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', $rules);
            insert_with_markers( $upload_base . '.htaccess', 'ShortPixelWebp', $rules);
            insert_with_markers( trailingslashit(WP_CONTENT_DIR) . '.htaccess', 'ShortPixelWebp', $rules);
           /* insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '
RewriteEngine On
RewriteBase /
RewriteCond %{HTTP_USER_AGENT} Chrome [OR]
RewriteCond %{HTTP_USER_AGENT} "Google Page Speed Insights" [OR]
RewriteCond %{HTTP_ACCEPT} image/webp [OR]
RewriteCond %{DOCUMENT_ROOT}/$1\.webp -f
RewriteRule (.+)\.(?:jpe?g|png)$ $1.webp [NC,T=image/webp,E=webp,L]
Header append Vary Accept env=REDIRECT_webp
' ); */
        }
    }

    /** Settings menu controller
    * @todo Make it a controller ||commented out means replaced
    */
    public function renderSettingsMenu() {
        if ( !current_user_can( 'manage_options' ) )  {
            wp_die(__('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser'));
        }

      //  wp_enqueue_style('sp-file-tree.min.css', plugins_url('/res/css/sp-file-tree.min.css',SHORTPIXEL_PLUGIN_FILE) );
      //  wp_enqueue_script('sp-file-tree.min.js', plugins_url('/res/js/sp-file-tree.min.js',SHORTPIXEL_PLUGIN_FILE) );

        //die(var_dump($_POST));
        $noticeHTML = "";
        $notice = null;
        $folderMsg = false;
        $addedFolder = false;

        //$this->_settings->redirectedSettings = 2;

        // Check if NGINX Server
        //$isNginx = strpos($_SERVER["SERVER_SOFTWARE"], 'nginx') !== false ? true : false;

        // BEGIN: Verify .htaccess writeability
        /*$htaccessWriteable = true;
        if( !$isNginx ) {
            $htaccessPath = get_home_path() . '.htaccess';
            $htaccessExisted = file_exists( $htaccessPath );
            //$htaccessWriteable = insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '' );
            $htaccessWriteable = @fopen($htaccessPath, "a+") ? true : false;
            if( !$htaccessExisted ){
                unlink( $htaccessPath );
            }
        } */
        // END: Verify .htaccess writeability


        //by default we try to fetch the API Key from wp-config.php (if defined)
        /*if ( defined("SHORTPIXEL_API_KEY") && strlen(SHORTPIXEL_API_KEY) == 20)
        {
            if(!isset($_POST['save']) && (strlen($this->getApiKey()) == 0 || SHORTPIXEL_API_KEY != $this->getApiKey())) {
                $_POST['validate'] = "validate";
            }
            $_POST['key'] = SHORTPIXEL_API_KEY;
        } */

        if(isset($_GET['setsparchive'])) {
            $this->_settings->downloadArchive = intval($_GET['setsparchive']);
        }

        //check all custom folders and update meta table if files appeared
        // This var is overwritten in the Save procedure. Notice is possibly overwritten below.
        //$customFolders = $this->refreshCustomFolders($notice, isset($_POST['removeFolder']) ? $_POST['removeFolder'] : null);

        if(isset($_POST['request']) && $_POST['request'] == 'request') {
            //a new API Key was requested
            if(filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {

            }
            else {
                $notice = array("status" => "error",
                    "msg" => __("Please provide a valid e-mail.",'shortpixel-image-optimiser')
                           . "<BR> "
                           . __('For any question regarding obtaining your API Key, please contact us at ','shortpixel-image-optimiser')
                           . "<a href='mailto:help@shortpixel.com?Subject=API Key issues' target='_top'>help@shortpixel.com</a>"
                           . __(' or ','shortpixel-image-optimiser')
                           . "<a href='https://shortpixel.com/contact' target='_blank'>" . __('here','shortpixel-image-optimiser') . "</a>.");
            }
        }

      /*  if ( isset( $_POST["saveCloudflare"] ) ) {
            $cfApi = $this->_settings->cloudflareEmail = sanitize_text_field( $_POST['cloudflare-email'] );
            $cfAuth = $this->_settings->cloudflareAuthKey = sanitize_text_field( $_POST['cloudflare-auth-key'] );
            $cfZone = $this->_settings->cloudflareZoneID = sanitize_text_field( $_POST['cloudflare-zone-id'] ); */
            $this->cloudflareApi->set_up($cfApi, $cfAuth, $cfZone);
        //}

        if(   isset($_POST['save']) || isset($_POST['saveAdv'])
           || (isset($_POST['validate']) && $_POST['validate'] == "validate")
           || isset($_POST['removeFolder']) || isset($_POST['recheckFolder'])) {

            //handle API Key - common for save and validate.
            //$_POST['key'] = trim(str_replace("*", "", isset($_POST['key']) ? $_POST['key'] : $this->_settings->apiKey)); //the API key might not be set if the editing is disabled.

          /*  if ( strlen($_POST['key']) <> 20 ){
                $KeyLength = strlen($_POST['key']);

                $notice = array("status" => "error",
                    "msg" => sprintf(__("The key you provided has %s characters. The API key should have 20 characters, letters and numbers only.",'shortpixel-image-optimiser'), $KeyLength)
                           . "<BR> <b>"
                           . __('Please check that the API key is the same as the one you received in your confirmation email.','shortpixel-image-optimiser')
                           . "</b><BR> "
                           . __('If this problem persists, please contact us at ','shortpixel-image-optimiser')
                           . "<a href='mailto:help@shortpixel.com?Subject=API Key issues' target='_top'>help@shortpixel.com</a>"
                           . __(' or ','shortpixel-image-optimiser')
                           . "<a href='https://shortpixel.com/contact' target='_blank'>" . __('here','shortpixel-image-optimiser') . "</a>.");
            }
            else {

                if(isset($_POST['save']) || isset($_POST['saveAdv'])) {
                    //these are needed for the call to api-status, set them first.
                    $this->_settings->siteAuthUser = (isset($_POST['siteAuthUser']) ? sanitize_text_field($_POST['siteAuthUser']) : $this->_settings->siteAuthUser);
                    $this->_settings->siteAuthPass = (isset($_POST['siteAuthPass']) ? sanitize_text_field($_POST['siteAuthPass']) : $this->_settings->siteAuthPass);
                }

                $validityData = $this->getQuotaInformation($_POST['key'], true, isset($_POST['validate']) && $_POST['validate'] == "validate", $_POST);
*/
              /*  $this->_settings->apiKey = $_POST['key'];
                if($validityData['APIKeyValid']) {
                    if(isset($_POST['validate']) && $_POST['validate'] == "validate") {
                        // delete last status if it was no valid key
                        $lastStatus = $this->_settings->bulkLastStatus;
                        if(isset($lastStatus['Status']) && $lastStatus['Status'] == ShortPixelAPI::STATUS_NO_KEY) {
                            $this->_settings->bulkLastStatus = null;
                        }
                        //display notification
                        $urlParts = explode("/", get_site_url());
                        if( $validityData['DomainCheck'] == 'NOT Accessible'){
                            $notice = array("status" => "warn", "msg" => __("API Key is valid but your site is not accessible from our servers. Please make sure that your server is accessible from the Internet before using the API or otherwise we won't be able to optimize them.",'shortpixel-image-optimiser'));
                        } else {
                            if ( function_exists("is_multisite") && is_multisite() && !defined("SHORTPIXEL_API_KEY"))
                                $notice = array("status" => "success", "msg" => __("Great, your API Key is valid! <br>You seem to be running a multisite, please note that API Key can also be configured in wp-config.php like this:",'shortpixel-image-optimiser')
                                    . "<BR> <b>define('SHORTPIXEL_API_KEY', '".$this->_settings->apiKey."');</b>");
                            else
                                $notice = array("status" => "success", "msg" => __('Great, your API Key is valid. Please take a few moments to review the plugin settings below before starting to optimize your images.','shortpixel-image-optimiser'));
                        }
                    }
                    $this->_settings->verifiedKey = true;
                    //test that the "uploads"  have the right rights and also we can create the backup dir for ShortPixel
                    if ( !file_exists(SHORTPIXEL_BACKUP_FOLDER) && ! ShortPixelFolder::createBackUpFolder() )
                        $notice = array("status" => "error",
                            "msg" => sprintf(__("There is something preventing us to create a new folder for backing up your original files.<BR>Please make sure that folder <b>%s</b> has the necessary write and read rights.",'shortpixel-image-optimiser'),
                                             WP_CONTENT_DIR . '/' . SHORTPIXEL_UPLOADS_NAME ));
                } else {
                    if(isset($_POST['validate'])) {
                        //display notification
                        $notice = array("status" => "error", "msg" => $validityData["Message"]);
                    }
                    $this->_settings->verifiedKey = false;
                } */
      //      }

            //if save button - we process the rest of the form elements
            if(isset($_POST['save']) || isset($_POST['saveAdv'])) {
            /*    $this->_settings->compressionType = intval($_POST['compressionType']);
                if(isset($_POST['thumbnails'])) { $this->_settings->processThumbnails = 1; } else { $this->_settings->processThumbnails = 0; }
                if(isset($_POST['backupImages'])) { $this->_settings->backupImages = 1; } else { $this->_settings->backupImages = 0; }
                if(isset($_POST['cmyk2rgb'])) { $this->_settings->CMYKtoRGBconversion = 1; } else { $this->_settings->CMYKtoRGBconversion = 0; }
                $this->_settings->keepExif = isset($_POST['removeExif']) ? 0 : 1;
                //delete_option('wp-short-pixel-keep-exif');
                $this->_settings->resizeImages = (isset($_POST['resize']) ? 1: 0);
                $this->_settings->resizeType = (isset($_POST['resize_type']) ? sanitize_text_field($_POST['resize_type']) : false);
                $this->_settings->resizeWidth = (isset($_POST['width']) ? intval($_POST['width']): $this->_settings->resizeWidth);
                $this->_settings->resizeHeight = (isset($_POST['height']) ? intval($_POST['height']): $this->_settings->resizeHeight);
                $uploadPath = realpath(SHORTPIXEL_UPLOADS_BASE); */

                /* if(isset($_POST['nextGen'])) {
                    WpShortPixelDb::checkCustomTables(); // check if custom tables are created, if not, create them
                    $prevNextGen = $this->_settings->includeNextGen;
                    $this->_settings->includeNextGen = 1;
                    $ret = $this->addNextGenGalleriesToCustom($prevNextGen);
                    $folderMsg = $ret["message"];
                    $customFolders = $ret["customFolders"];
                } else {
                    $this->_settings->includeNextGen = 0;
                } */
            /*    if(isset($_POST['addCustomFolder']) && strlen($_POST['addCustomFolder']) > 0) {
                    $folderMsg = $this->spMetaDao->newFolderFromPath(stripslashes($_POST['addCustomFolder']), $uploadPath, self::getCustomFolderBase());
                    if(!$folderMsg) {
                        $notice = array("status" => "success", "msg" => __('Folder added successfully.','shortpixel-image-optimiser'));
                    }
                    $customFolders = $this->spMetaDao->getFolders();
                    $this->_settings->hasCustomFolders = time();
                } */

              //  $this->_settings->createWebp = (isset($_POST['createWebp']) ? 1: 0);

              /*  if( isset( $_POST['createWebp'] ) && $_POST['createWebp'] == 'on' ){
                    if( isset( $_POST['deliverWebp'] ) && $_POST['deliverWebp'] == 'on' ){
                        if( isset( $_POST['deliverWebpType'] ) ) {
                            switch( $_POST['deliverWebpType'] ) {
                                case 'deliverWebpUnaltered':
                                    $this->_settings->deliverWebp = 3;
                                    if(!$isNginx) self::alterHtaccess();
                                    break;
                                case 'deliverWebpAltered':
                                    self::alterHtaccess(true);
                                    if( isset( $_POST['deliverWebpAlteringType'] ) ){
                                        switch ($_POST['deliverWebpAlteringType']) {
                                            case 'deliverWebpAlteredWP':
                                                $this->_settings->deliverWebp = 2;
                                                break;
                                            case 'deliverWebpAlteredGlobal':
                                                $this->_settings->deliverWebp = 1;
                                                break;
                                        }
                                    }
                                    break;
                            }
                        }
                    } else {
                        if(!$isNginx) self::alterHtaccess(true);
                        $this->_settings->deliverWebp = 0;
                    }
                } else {
                    if(!$isNginx) self::alterHtaccess(true);
                    $this->_settings->deliverWebp = 0;
                } */

                /*$this->_settings->optimizeRetina = (isset($_POST['optimizeRetina']) ? 1: 0);
                $this->_settings->optimizeUnlisted = (isset($_POST['optimizeUnlisted']) ? 1: 0);
                $this->_settings->optimizePdfs = (isset($_POST['optimizePdfs']) ? 1: 0);
                */
                //$this->_settings->png2jpg = (isset($_POST['png2jpg']) ? (isset($_POST['png2jpgForce']) ? 2 : 1): 0);

              /*  if(isset($_POST['excludePatterns']) && strlen($_POST['excludePatterns'])) {
                    $patterns = array();
                    $items = explode(',', $_POST['excludePatterns']);
                    foreach($items as $pat) {
                        $parts = explode(':', $pat);
                        if(count($parts) == 1) {
                            $patterns[] = array("type" =>"name", "value" => str_replace('\\\\','\\',trim($pat)));
                        } else {
                            $patterns[] = array("type" =>trim($parts[0]), "value" => str_replace('\\\\','\\',trim($parts[1])));
                        }
                    }
                    $this->_settings->excludePatterns = $patterns;
                } else {
                    $this->_settings->excludePatterns = array();
                }
                */
          //      $this->_settings->frontBootstrap = (isset($_POST['frontBootstrap']) ? 1: 0);
          //      $this->_settings->autoMediaLibrary = (isset($_POST['autoMediaLibrary']) ? 1: 0);
          //      $this->_settings->excludeSizes = (isset($_POST['excludeSizes']) ? $_POST['excludeSizes']: array());

                //Redirect to bulk processing if requested
                if(   isset($_POST['save']) && $_POST['save'] == __("Save and Go to Bulk Process",'shortpixel-image-optimiser')
                   || isset($_POST['saveAdv']) && $_POST['saveAdv'] == __("Save and Go to Bulk Process",'shortpixel-image-optimiser')) {
                    wp_redirect("upload.php?page=wp-short-pixel-bulk");
                    exit();
                }
            }
            /*if(isset($_POST['removeFolder']) && strlen(($_POST['removeFolder']))) {
                $this->spMetaDao->removeFolder($_POST['removeFolder']);
                $customFolders = $this->spMetaDao->getFolders();
                $_POST["saveAdv"] = true;
            } */
            if(isset($_POST['recheckFolder']) && strlen(($_POST['recheckFolder']))) {
                //$folder->fullRefreshCustomFolder($_POST['recheckFolder']); //aici singura solutie pare callback care spune daca exita url-ul complet
            }
        }

        //now output headers. They were prevented with noheaders=true in the form url in order to be able to redirect if bulk was pressed
      /*  if(isset($_REQUEST['noheader'])) {
            require_once(ABSPATH . 'wp-admin/admin-header.php');
        } */

        //empty backup
        /*if(isset($_POST['emptyBackup'])) {
            $this->emptyBackup();
        } */

        //$quotaData = $this->checkQuotaAndAlert(isset($validityData) ? $validityData : null, isset($_GET['checkquota']));

/*        if($this->hasNextGen) {
            $ngg = array_map(array('ShortPixelNextGenAdapter','pathToAbsolute'), ShortPixelNextGenAdapter::getGalleries());
            //die(var_dump($ngg));
            for($i = 0; $i < count($customFolders); $i++) {
                if(in_array($customFolders[$i]->getPath(), $ngg )) {
                    $customFolders[$i]->setType("NextGen");
                }
            }
        } */

/*        $showApiKey = (   (is_main_site() || (function_exists("is_multisite") && is_multisite() && !defined("SHORTPIXEL_API_KEY")))
                       && !defined("SHORTPIXEL_HIDE_API_KEY"));
        $editApiKey = !defined("SHORTPIXEL_API_KEY") && $showApiKey; */

        if($this->_settings->verifiedKey) {
            /* $fileCount = number_format($this->_settings->fileCount);
            $savedSpace = self::formatBytes($this->_settings->savedSpace,2);
            $averageCompression = $this->getAverageCompression();
            $savedBandwidth = self::formatBytes($this->_settings->savedSpace * 10000,2);
            if (is_numeric($quotaData['APICallsQuota'])) {
                $quotaData['APICallsQuota'] .= "/month";
            }
            $remainingImages = $quotaData['APICallsRemaining'];
            $remainingImages = ( $remainingImages < 0 ) ? 0 : number_format($remainingImages);
            $totalCallsMade = array( 'plan' => $quotaData['APICallsMadeNumeric'] , 'oneTime' => $quotaData['APICallsMadeOneTimeNumeric'] );
 */
          /*  $resources = wp_remote_post($this->_settings->httpProto . "://shortpixel.com/resources-frag");
            if(is_wp_error( $resources )) {
                $resources = array();
            } */

            $cloudflareAPI = true;

            $this->view->displaySettings($showApiKey, $editApiKey,
                   $quotaData, $notice, $resources, $averageCompression, $savedSpace, $savedBandwidth, $remainingImages,
                   $totalCallsMade, $fileCount, null /*folder size now on AJAX*/, $customFolders,
                   $folderMsg, $folderMsg ? $addedFolder : false, isset($_POST['saveAdv']), $cloudflareAPI, $htaccessWriteable, $isNginx );
        } else {
            $this->view->displaySettings($showApiKey, $editApiKey, $quotaData, $notice);
        }

    }

    /** Adds NextGenGalleries to Custom Images Library
    * @param boolean $silent Will not return messages if silent
    * @return array Array for information
    * @todo Move to a integration class || This can be removed after nextgen.php in externals is released.
    */
    public function addNextGenGalleriesToCustom($silent) {
        $customFolders = array();
        $folderMsg = "";
        if($this->_settings->includeNextGen) {
            //add the NextGen galleries to custom folders
            $ngGalleries = ShortPixelNextGenAdapter::getGalleries();
            foreach($ngGalleries as $gallery) {
                $msg = $this->spMetaDao->newFolderFromPath($gallery, get_home_path(), self::getCustomFolderBase());
                if($msg) { //try again with ABSPATH as maybe WP is in a subdir
                    $msg = $this->spMetaDao->newFolderFromPath($gallery, ABSPATH, self::getCustomFolderBase());
                }
                $folderMsg .= $msg;
                $this->_settings->hasCustomFolders = time();
            }
            $customFolders = $this->spMetaDao->getFolders();
        }
        return array("message" => $silent? "" : $folderMsg, "customFolders" => $customFolders);
    }

    /** Gets the average compression
    * @return int Average compressions percentage
    * @todo Move to utility (?)
    */
    public function getAverageCompression(){
        return $this->_settings->totalOptimized > 0
               ? round(( 1 -  ( $this->_settings->totalOptimized / $this->_settings->totalOriginal ) ) * 100, 2)
               : 0;
    }

    /**
     *
     * @param type $apiKey
     * @param type $appendUserAgent
     * @param type $validate - true if we are validating the api key, send also the domain name and number of pics
     * @return type
     */
    public function getQuotaInformation($apiKey = null, $appendUserAgent = false, $validate = false, $settings = false) {

        if(is_null($apiKey)) { $apiKey = $this->_settings->apiKey; }

        if($this->_settings->httpProto != 'https' && $this->_settings->httpProto != 'http') {
            $this->_settings->httpProto = 'https';
        }

        $requestURL = $this->_settings->httpProto . '://' . SHORTPIXEL_API . '/v2/api-status.php';
        $args = array(
            'timeout'=> SHORTPIXEL_VALIDATE_MAX_TIMEOUT,
            'body' => array('key' => $apiKey)
        );
        $argsStr = "?key=".$apiKey;

        if($appendUserAgent) {
            $args['body']['useragent'] = "Agent" . urlencode($_SERVER['HTTP_USER_AGENT']);
            $argsStr .= "&useragent=Agent".$args['body']['useragent'];
        }
        if($validate) {
            $args['body']['DomainCheck'] = get_site_url();
            $args['body']['Info'] = get_bloginfo('version') . '|' . phpversion();
            $imageCount = WpShortPixelMediaLbraryAdapter::countAllProcessableFiles($this->_settings);
            $args['body']['ImagesCount'] = $imageCount['mainFiles'];
            $args['body']['ThumbsCount'] = $imageCount['totalFiles'] - $imageCount['mainFiles'];
            $argsStr .= "&DomainCheck={$args['body']['DomainCheck']}&Info={$args['body']['Info']}&ImagesCount={$imageCount['mainFiles']}&ThumbsCount={$args['body']['ThumbsCount']}";
        }
        $args['body']['host'] = parse_url(get_site_url(),PHP_URL_HOST);
        $argsStr .= "&host={$args['body']['host']}";
        if(strlen($this->_settings->siteAuthUser)) {
            $args['body']['user'] = stripslashes($this->_settings->siteAuthUser);
            $args['body']['pass'] = stripslashes($this->_settings->siteAuthPass);
            $argsStr .= '&user=' . urlencode($args['body']['user']) . '&pass=' . urlencode($args['body']['pass']);
        }
        if($settings !== false) {
            $args['body']['Settings'] = $settings;
        }

        $time = microtime(true);
        $comm = array();

        //Try first HTTPS post. add the sslverify = false if https
        if($this->_settings->httpProto === 'https') {
            $args['sslverify'] = false;
        }
        $response = wp_remote_post($requestURL, $args);

        $comm['A: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);

        //some hosting providers won't allow https:// POST connections so we try http:// as well
        if(is_wp_error( $response )) {
            //echo("protocol " . $this->_settings->httpProto . " failed. switching...");
            $requestURL = $this->_settings->httpProto == 'https' ?
                str_replace('https://', 'http://', $requestURL) :
                str_replace('http://', 'https://', $requestURL);
            // add or remove the sslverify
            if($this->_settings->httpProto === 'http') {
                $args['sslverify'] = false;
            } else {
                unset($args['sslverify']);
            }
            $response = wp_remote_post($requestURL, $args);
            $comm['B: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);

            if(!is_wp_error( $response )){
                $this->_settings->httpProto = ($this->_settings->httpProto == 'https' ? 'http' : 'https');
                //echo("protocol " . $this->_settings->httpProto . " succeeded");
            } else {
                //echo("protocol " . $this->_settings->httpProto . " failed too");
            }
        }
        //Second fallback to HTTP get
        if(is_wp_error( $response )){
            $args['body'] = null;
            $requestURL .= $argsStr;
            $response = wp_remote_get($requestURL, $args);
            $comm['C: ' . (number_format(microtime(true) - $time, 2))] = array("sent" => "POST: " . $requestURL, "args" => $args, "received" => $response);
        }
        Log::addInfo("API STATUS COMM: " . json_encode($comm));

        $defaultData = array(
            "APIKeyValid" => false,
            "Message" => __('API Key could not be validated due to a connectivity error.<BR>Your firewall may be blocking us. Please contact your hosting provider and ask them to allow connections from your site to api.shortpixel.com (IP 176.9.21.94).<BR> If you still cannot validate your API Key after this, please <a href="https://shortpixel.com/contact" target="_blank">contact us</a> and we will try to help. ','shortpixel-image-optimiser'),
            "APICallsMade" => __('Information unavailable. Please check your API key.','shortpixel-image-optimiser'),
            "APICallsQuota" => __('Information unavailable. Please check your API key.','shortpixel-image-optimiser'),
            "APICallsMadeOneTime" => 0,
            "APICallsQuotaOneTime" => 0,
            "APICallsMadeNumeric" => 0,
            "APICallsQuotaNumeric" => 0,
            "APICallsMadeOneTimeNumeric" => 0,
            "APICallsQuotaOneTimeNumeric" => 0,
            "APICallsRemaining" => 0,
            "APILastRenewalDate" => 0,
            "DomainCheck" => 'NOT Accessible');
        $defaultData = is_array($this->_settings->currentStats) ? array_merge( $this->_settings->currentStats, $defaultData) : $defaultData;

        if(is_object($response) && get_class($response) == 'WP_Error') {

            $urlElements = parse_url($requestURL);
            $portConnect = @fsockopen($urlElements['host'],8,$errno,$errstr,15);
            if(!$portConnect) {
                $defaultData['Message'] .= "<BR>Debug info: <i>$errstr</i>";
            }
            return $defaultData;
        }

        if($response['response']['code'] != 200) {
            //$defaultData['Message'] .= "<BR><i>Debug info: response code {$response['response']['code']} URL $requestURL , Response ".json_encode($response)."</i>";
            return $defaultData;
        }

        $data = $response['body'];
        $data = json_decode($data);

        if(empty($data)) { return $defaultData; }

        if($data->Status->Code != 2) {
            $defaultData['Message'] = $data->Status->Message;
            return $defaultData;
        }

        if ( ( $data->APICallsMade + $data->APICallsMadeOneTime ) < ( $data->APICallsQuota + $data->APICallsQuotaOneTime ) ) //reset quota exceeded flag -> user is allowed to process more images.
            $this->resetQuotaExceeded();
        else
            $this->_settings->quotaExceeded = 1;//activate quota limiting

        //if a non-valid status exists, delete it
        $lastStatus = $this->_settings->bulkLastStatus = null;
        if($lastStatus && $lastStatus['Status'] == ShortPixelAPI::STATUS_NO_KEY) {
            $this->_settings->bulkLastStatus = null;
        }

        $dataArray = array(
            "APIKeyValid" => true,
            "APICallsMade" => number_format($data->APICallsMade) . __(' images','shortpixel-image-optimiser'),
            "APICallsQuota" => number_format($data->APICallsQuota) . __(' images','shortpixel-image-optimiser'),
            "APICallsMadeOneTime" => number_format($data->APICallsMadeOneTime) . __(' images','shortpixel-image-optimiser'),
            "APICallsQuotaOneTime" => number_format($data->APICallsQuotaOneTime) . __(' images','shortpixel-image-optimiser'),
            "APICallsMadeNumeric" => $data->APICallsMade,
            "APICallsQuotaNumeric" => $data->APICallsQuota,
            "APICallsMadeOneTimeNumeric" => $data->APICallsMadeOneTime,
            "APICallsQuotaOneTimeNumeric" => $data->APICallsQuotaOneTime,
            "APICallsRemaining" => $data->APICallsQuota + $data->APICallsQuotaOneTime - $data->APICallsMade - $data->APICallsMadeOneTime,
            "APILastRenewalDate" => $data->DateSubscription,
            "DomainCheck" => (isset($data->DomainCheck) ? $data->DomainCheck : null)
        );

        $crtStats = is_array($this->_settings->currentStats) ? array_merge( $this->_settings->currentStats, $dataArray) : $dataArray;
        $crtStats['optimizePdfs'] = $this->_settings->optimizePdfs;
        $this->_settings->currentStats = $crtStats;

        return $dataArray;
    }

    public function resetQuotaExceeded() {
        if( $this->_settings->quotaExceeded == 1) {
            $dismissed = $this->_settings->dismissedNotices ? $this->_settings->dismissedNotices : array();
            unset($dismissed['exceed']);
            $this->_settings->prioritySkip = array();
            $this->_settings->dismissedNotices = $dismissed;
        }
        $this->_settings->quotaExceeded = 0;
    }

    /** Generates column for custom media library
    * @todo Move this to custom media controller
    */
    public function generateCustomColumn( $column_name, $id, $extended = false ) {
        if( 'wp-shortPixel' == $column_name ) {

            if(!$this->isProcessable($id)) {
                $renderData['status'] = 'n/a';
                $this->view->renderCustomColumn($id, $renderData, $extended);
                return;
            }

            $file = get_attached_file($id);
            $data = ShortPixelMetaFacade::sanitizeMeta(wp_get_attachment_metadata($id));

            if($extended && isset($_GET['SHORTPIXEL_DEBUG'])) {
            //  var_dump($data);
                var_dump(wp_get_attachment_url($id));
                echo('<br><br>' . json_encode(ShortPixelMetaFacade::getWPMLDuplicates($id)));
                echo('<br><br>'); print_r($data);  echo ''; //json_encode($data))
                echo('<br><br>');
            }

            $fileExtension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $invalidKey = !$this->_settings->verifiedKey;
            $quotaExceeded = $this->_settings->quotaExceeded;
            $renderData = array("id" => $id, "showActions" => (current_user_can( 'manage_options' ) || current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' )));

            if($invalidKey) { //invalid key - let the user first register and only then
                $renderData['status'] = 'invalidKey';
                $this->view->renderCustomColumn($id, $renderData, $extended);
                return;
            }

            //empty data means document, we handle only PDF
            elseif (empty($data)) { //TODO asta devine if si decomentam returnurile
                if($fileExtension == "pdf") {
                    $renderData['status'] = $quotaExceeded ? 'quotaExceeded' : 'optimizeNow';
                    $renderData['message'] = __('PDF not processed.','shortpixel-image-optimiser');
                }
                else { //Optimization N/A
                    $renderData['status'] = 'n/a';
                }
                $this->view->renderCustomColumn($id, $renderData, $extended);
                return;
            }

            if(!isset($data['ShortPixelImprovement'])) { //new image
                $data['ShortPixelImprovement'] = '';
            }

            if(   is_numeric($data['ShortPixelImprovement'])
               && !($data['ShortPixelImprovement'] == 0 && isset($data['ShortPixel']['WaitingProcessing'])) //for images that erroneously have ShortPixelImprovement = 0 when WaitingProcessing
              ) { //already optimized
                $sizesCount = isset($data['sizes']) ? WpShortPixelMediaLbraryAdapter::countSizesNotExcluded($data['sizes']) : 0;

                $thumbsToOptimize = 0;
                $thumbsOptList = isset($data['ShortPixel']['thumbsOptList']) ? $data['ShortPixel']['thumbsOptList'] : array();
                if($sizesCount && $this->_settings->processThumbnails) {
                    $exclude = $this->_settings->excludeSizes;
                    $exclude = is_array($exclude) ? $exclude : array();
                    foreach($data['sizes'] as $size => $sizeData) {
                        if(!in_array($size, $exclude) && !in_array($sizeData['file'], $thumbsOptList)) {
                            $thumbsToOptimize++;
                        }
                    }
                }

                $renderData['status'] = $fileExtension == "pdf" ? 'pdfOptimized' : 'imgOptimized';
                $renderData['percent'] = $this->optimizationPercentIfPng2Jpg($data);
                $renderData['bonus'] = ($data['ShortPixelImprovement'] < 5);
                $renderData['backup'] = $this->getBackupFolderAny($file, $sizesCount? $data['sizes'] : array());
                $renderData['type'] = isset($data['ShortPixel']['type']) ? $data['ShortPixel']['type'] : '';
                $renderData['invType'] = ShortPixelAPI::getCompressionTypeName($this->getOtherCompressionTypes(ShortPixelAPI::getCompressionTypeCode($renderData['type'])));
                $renderData['thumbsTotal'] = $sizesCount;
                $renderData['thumbsOpt'] = isset($data['ShortPixel']['thumbsOpt']) ? $data['ShortPixel']['thumbsOpt'] : $sizesCount;
                $renderData['thumbsToOptimize'] = $thumbsToOptimize;
                $renderData['thumbsOptList'] = $thumbsOptList;
                $renderData['excludeSizes'] = isset($data['ShortPixel']['excludeSizes']) ? $data['ShortPixel']['excludeSizes'] : null;
                $renderData['thumbsMissing'] = isset($data['ShortPixel']['thumbsMissing']) ? $data['ShortPixel']['thumbsMissing'] : array();
                $renderData['retinasOpt'] = isset($data['ShortPixel']['retinasOpt']) ? $data['ShortPixel']['retinasOpt'] : null;
                $renderData['exifKept'] = isset($data['ShortPixel']['exifKept']) ? $data['ShortPixel']['exifKept'] : null;
                $renderData['png2jpg'] = isset($data['ShortPixelPng2Jpg']) ? $data['ShortPixelPng2Jpg'] : 0;
                $renderData['date'] = isset($data['ShortPixel']['date']) ? $data['ShortPixel']['date'] : null;
                $renderData['quotaExceeded'] = $quotaExceeded;
                $webP = 0;
                if($extended) {
                    if(file_exists(dirname($file) . '/' . ShortPixelAPI::MB_basename($file, '.'.$fileExtension) . '.webp' )){
                        $webP++;
                    }
                    if(isset($data['sizes'])) {
                    foreach($data['sizes'] as $key => $size) {
                        if (strpos($key, ShortPixelMeta::WEBP_THUMB_PREFIX) === 0) continue;
                        $sizeName = $size['file'];
                        if(file_exists(dirname($file) . '/' . ShortPixelAPI::MB_basename($sizeName, '.'.$fileExtension) . '.webp' )){
                            $webP++;
                        }
                    }
                    }
                }
                $renderData['webpCount'] = $webP;
            }
/*            elseif($data['ShortPixelImprovement'] == __('Optimization N/A','shortpixel-image-optimiser')) { //We don't optimize this
                $renderData['status'] = 'n/a';
            }*/
            elseif(isset($meta['ShortPixel']['BulkProcessing'])) { //Scheduled to bulk.
                $renderData['status'] = $quotaExceeded ? 'quotaExceeded' : 'optimizeNow';
                $renderData['message'] = 'Waiting for bulk processing.';
            }
            elseif( trim(strip_tags($data['ShortPixelImprovement'])) == __("Cannot write optimized file",'shortpixel-image-optimiser') ) {
                $renderData['status'] = $quotaExceeded ? 'quotaExceeded' : 'retry';
                $renderData['message'] = __("Cannot write optimized file",'shortpixel-image-optimiser') . " - <a href='https://shortpixel.com/faq#cannot-write-optimized-file' target='_blank'>"
                                       . __("Why?",'shortpixel-image-optimiser') . "</a>";
            }
            elseif( strlen(trim(strip_tags($data['ShortPixelImprovement']))) > 0 ) {
                $renderData['status'] = $quotaExceeded ? 'quotaExceeded' : 'retry';
                $renderData['message'] = $data['ShortPixelImprovement'];
                if(strpos($renderData['message'], __('The file(s) do not exist on disk: ','shortpixel-image-optimiser')) !== false) {
                    $renderData['cleanup'] = true;
                }
            }
             elseif(isset($data['ShortPixel']['NoFileOnDisk'])) {
                $renderData['status'] = 'notFound';
                $renderData['message'] = __('Image does not exist','shortpixel-image-optimiser');
            }
            elseif(isset($data['ShortPixel']['WaitingProcessing'])) {
                $renderData['status'] = $quotaExceeded ? 'quotaExceeded' : 'waiting';
                $renderData['message'] = "<img src=\"" . plugins_url( 'res/img/loading.gif', SHORTPIXEL_PLUGIN_FILE ) . "\" class='sp-loading-small'>&nbsp;" . __("Image waiting to be processed.",'shortpixel-image-optimiser');
                if($this->_settings->autoMediaLibrary && !$quotaExceeded && ($id > $this->prioQ->getFlagBulkId() || !$this->prioQ->bulkRunning())) {
                    $this->prioQ->unskip($id);
                    $this->prioQ->push($id); //should be there but just to make sure
                }
            }
            else { //finally
                $renderData['status'] = $quotaExceeded ? 'quotaExceeded' : 'optimizeNow';
                $sizes = isset($data['sizes']) ? WpShortPixelMediaLbraryAdapter::countSizesNotExcluded($data['sizes']) : 0;
                $renderData['thumbsTotal'] = $sizes;
                $renderData['message'] = ($fileExtension == "pdf" ? 'PDF' : __('Image','shortpixel-image-optimiser'))
                        . __(' not processed.','shortpixel-image-optimiser')
                        . ' (<a href="https://shortpixel.com/image-compression-test?site-url=' . urlencode(ShortPixelMetaFacade::safeGetAttachmentUrl($id)) . '" target="_blank">'
                        . __('Test&nbsp;for&nbsp;free','shortpixel-image-optimiser') . '</a>)';
            }
            $this->view->renderCustomColumn($id, $renderData, $extended);
        }
    }

    /** Make columns sortable in Media Library
    * @hook manage_upload_sortable_columns
    * @param array $columns Array of colums sortable
    * @todo Should be part of media library controller.
    */
    function columnRegisterSortable($columns) {
        $columns['wp-shortPixel'] = 'ShortPixel Compression';
        return $columns;
    }

    /** Apply sort filter in Media Library
    * @hook request
    * @param array $columns Array of colums sortable
    * @todo Should be part of media library controller.  ( is request best hook for this?)
    */

    function columnOrderFilterBy($vars) {
        if ( isset( $vars['orderby'] ) && 'ShortPixel Compression' == $vars['orderby'] ) {
            $vars = array_merge( $vars, array(
                'meta_key' => '_shortpixel_status',
                'orderby' => 'meta_value_num',
            ) );
        }
        if ( 'upload.php' == $GLOBALS['pagenow'] && !empty( $_GET['shortpixel_status'] ) ) {

            $status       = $_GET['shortpixel_status'];
            $metaKey = '_shortpixel_status';
            $metaCompare = $status == 0 ? 'NOT EXISTS' : ($status < 0 ? '<' : '=');

            $vars = array_merge( $vars, array(
                'meta_query' => array(
                    array(
                        'key'     => $metaKey,
                        'value'   => $status,
                        'compare' => $metaCompare,
                    ),
                )
            ));
        }
        return $vars;
    }

    /*
    * @hook restrict_manage_posts
    * @todo Should be part of media library controller.  ( is request best hook for this?)
    */
    function mediaAddFilterDropdown() {
        $scr = get_current_screen();
        if ( $scr->base !== 'upload' ) return;

        $status   = filter_input(INPUT_GET, 'shortpixel_status', FILTER_SANITIZE_STRING );
        $selected = (int)$status > 0 ? $status : 0;
        $args = array(
            'show_option_none'   => 'ShortPixel',
            'name'               => 'shortpixel_status',
            'selected'           => $selected
        );
//        wp_dropdown_users( $args );

        echo("<select name='shortpixel_status' id='shortpixel_status'>\n"
               . "\t<option value='0'" . ($status == 0 ? " selected='selected'" : "") . ">All images</option>\n"
               . "\t<option value='2'" . ($status == 2 ? " selected='selected'" : "") . ">Optimized</option>\n"
               . "\t<option value='none'" . ($status == 'none' ? " selected='selected'" : "") . ">Unoptimized</option>\n"
               . "\t<option value='1'" . ($status == 1 ? " selected='selected'" : "") . ">Pending</option>\n"
               . "\t<option value='-1'" . ($status < 0 ? " selected='selected'" : "") . ">Errors</option>\n"
            . "</select>");
    }

    /** Calculates Optimization if PNG2Jpg does something
    * @param array $meta Image metadata
    * @return string Formatted improvement
    */
    function optimizationPercentIfPng2Jpg($meta) {
        $png2jpgPercent = isset($meta['ShortPixelPng2Jpg']['optimizationPercent']) ? $meta['ShortPixelPng2Jpg']['optimizationPercent'] : 0;
        return number_format(100.0 - (100.0 - $png2jpgPercent) * (100.0 - $meta['ShortPixelImprovement']) / 100.0, 2);
    }

    /** Meta box for shortpixel in view image
    * @hook add_meta_boxes
    * @todo move to appr. controller
    */
    function shortpixelInfoBox() {
        if(get_post_type( ) == 'attachment') {
            add_meta_box(
                'shortpixel_info_box',          // this is HTML id of the box on edit screen
                __('ShortPixel Info', 'shortpixel-image-optimiser'),    // title of the box
                array( &$this, 'shortpixelInfoBoxContent'),   // function to be called to display the info
                null,//,        // on which edit screen the box should appear
                'side'//'normal',      // part of page where the box should appear
                //'default'      // priority of the box
            );
        }
    }

    /** Meta box for view image
    * @todo move to appr. controller
    */
    function shortpixelInfoBoxContent( $post ) {
        $this->generateCustomColumn( 'wp-shortPixel', $post->ID, true );
    }

    /** When an image is deleted
    * @hook delete_attachment
    * @param int $post_id  ID of Post
    * @return itemHandler ItemHandler object.
    */
    public function onDeleteImage($post_id) {
        $itemHandler = new ShortPixelMetaFacade($post_id);
        $urlsPaths = $itemHandler->getURLsAndPATHs(true, false, true, array(), true);
        if(count($urlsPaths['PATHs'])) {
            $this->maybeDumpFromProcessedOnServer($itemHandler, $urlsPaths);
            $this->deleteBackupsAndWebPs($urlsPaths['PATHs']);
        }
        return $itemHandler; //return it because we call it also on replace and on replace we need to follow this by deleting SP metadata, on delete it
    }

    /** Removes webp and backup from specified paths
    */
    public function deleteBackupsAndWebPs($paths) {
        /**
         * Passing a truthy value to the filter will effectively short-circuit this function.
         * So third party plugins can handle deletion by there own.
         */
        if(apply_filters('shortpixel_skip_delete_backups_and_webps', false, $paths)){
            return;
        }

        $backupFolder = trailingslashit($this->getBackupFolder($paths[0]));
        foreach($paths as $path) {
            $pos = strrpos($path, ".");
            if ($pos !== false) {
                //$webpPath = substr($path, 0, $pos) . ".webp";
                //echo($webpPath . "<br>");
                @unlink(substr($path, 0, $pos) . ".webp");
                @unlink(substr($path, 0, $pos) . "@2x.webp");
            }
            //delte also the backups for image and retina correspondent
            $fileName = wp_basename($path);
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            @unlink($backupFolder . $fileName);
            @unlink($backupFolder . preg_replace("/\." . $extension . "$/i", '@2x.' . $extension, $fileName));
        }
    }
//
    /**
    * @hook manage_media_columns
    * @todo Move to appr. controller.
    */
    public function columns( $defaults ) {
        $defaults['wp-shortPixel'] = __('ShortPixel Compression', 'shortpixel-image-optimiser');
        if(current_user_can( 'manage_options' )) {
            $defaults['wp-shortPixel'] .=
                      '&nbsp;<a href="options-general.php?page=wp-shortpixel-settings&part=stats" title="'
                    . __('ShortPixel Statistics','shortpixel-image-optimiser')
                    . '"><span class="dashicons dashicons-dashboard"></span></a>';
        }
        return $defaults;
    }

    // todo move NGG specific function to own integration
    public function nggColumns( $defaults ) {
        $this->nggColumnIndex = count($defaults) + 1;
        add_filter( 'ngg_manage_images_column_' . $this->nggColumnIndex . '_header', array( &$this, 'nggColumnHeader' ) );
        add_filter( 'ngg_manage_images_column_' . $this->nggColumnIndex . '_content', array( &$this, 'nggColumnContent' ), 10, 2 );
        $defaults['wp-shortPixelNgg'] = 'ShortPixel Compression';
        return $defaults;
    }

    public function nggCountColumns( $count ) {
        return $count + 1;
    }

    public function nggColumnHeader( $default ) {
        return __('ShortPixel Compression','shortpixel-image-optimiser');
    }

    public function nggColumnContent( $unknown, $picture ) {

        $meta = $this->spMetaDao->getMetaForPath($picture->imagePath);
        if($meta) {
            switch($meta->getStatus()) {
                case "0": echo("<div id='sp-msg-C-{$meta->getId()}' class='column-wp-shortPixel' style='color: #928B1E'>Waiting</div>"); break;
                case "1": echo("<div id='sp-msg-C-{$meta->getId()}' class='column-wp-shortPixel' style='color: #1919E2'>Pending</div>"); break;
                case "2": $this->view->renderCustomColumn("C-" . $meta->getId(), array(
                    'showActions' => false && current_user_can( 'manage_options' ),
                    'status' => 'imgOptimized',
                    'type' => ShortPixelAPI::getCompressionTypeName($meta->getCompressionType()),
                    'percent' => $meta->getImprovementPercent(),
                    'bonus' => $meta->getImprovementPercent() < 5,
                    'thumbsOpt' => 0,
                    'thumbsOptList' => array(),
                    'thumbsTotal' => 0,
                    'retinasOpt' => 0,
                    'backup' => true
                ));
                break;
            }
        } else {
            $this->view->renderCustomColumn($meta ? "C-" . $meta->getId() : "N-" . $picture->pid, array(
                    'showActions' => false && current_user_can( 'manage_options' ),
                    'status' => 'optimizeNow',
                    'thumbsOpt' => 0,
                    'thumbsOptList' => array(),
                    'thumbsTotal' => 0,
                    'retinasOpt' => 0,
                    'message' => "Not optimized"
                ));
        }
//        return var_dump($meta);
    }

    public function generatePluginLinks($links) {
        $in = '<a href="options-general.php?page=wp-shortpixel-settings">Settings</a>';
        array_unshift($links, $in);
        return $links;
    }

    // @todo Should be utility function
    static public function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /** Checks if file can be processed. Mainly against exclusion
    *  @param int $ID ImageID
    *  @param array $excludeExtensions Excludes Extentions from settings
    *  @todo Part of Image model
    */
    public function isProcessable($ID, $excludeExtensions = array()) {
        $excludePatterns = $this->_settings->excludePatterns;
        return self::_isProcessable($ID, $excludeExtensions, $excludePatterns);
    }

    /** Checks if path can be processed. Mainly against exclusion
    *  @param string $path Path
    *  @param array $excludeExtensions Excludes Extentions from settings
    *  @todo Part of Image / Folder(?) model
    */
    public function isProcessablePath($path, $excludeExtensions = array()) {
        $excludePatterns = $this->_settings->excludePatterns;
        return self::_isProcessablePath($path, $excludeExtensions, $excludePatterns);
    }

    static public function _isProcessable($ID, $excludeExtensions = array(), $excludePatterns = array(), $meta = false) {
        $path = get_attached_file($ID);//get the full file PATH
        if(isset($excludePatterns) && is_array($excludePatterns)) {
            foreach($excludePatterns as $excludePattern) {
                $type = $excludePattern["type"];
                if($type == "size") {
                    $meta = $meta? $meta : wp_get_attachment_metadata($ID);
                    if(   isset($meta["width"]) && isset($meta["height"])
                       && self::isProcessableSize($meta["width"], $meta["height"], $excludePattern["value"]) === false){
                        return false;
                    }
                }
            }
        }
        return $path ? self::_isProcessablePath($path, $excludeExtensions, $excludePatterns) : false;
    }

    static public function _isProcessablePath($path, $excludeExtensions = array(), $excludePatterns = array()) {
        $pathParts = pathinfo($path);
        $ext = isset($pathParts['extension']) ? $pathParts['extension'] : false;
        if( $ext && in_array(strtolower($ext), array_diff(self::$PROCESSABLE_EXTENSIONS, $excludeExtensions))) {
            //apply patterns defined by user to exclude some file names or paths
            if(!$excludePatterns || !is_array($excludePatterns)) { return true; }
            foreach($excludePatterns as $item) {
                $type = trim($item["type"]);
                if(in_array($type, array("name", "path"))) {
                    $pattern = trim($item["value"]);
                    $target = $type == "name" ? ShortPixelAPI::MB_basename($path) : $path;
                    if( self::matchExcludePattern($target, $pattern) ) { //search as a substring if not
                        return false;
                    }
                }
            }
            return true;
        } else {
            return false;
        }
    }

    static public function isProcessableSize($width, $height, $excludePattern) {
        $ranges = preg_split("/(x|×)/",$excludePattern);
        $widthBounds = explode("-", $ranges[0]);
        if(!isset($widthBounds[1])) $widthBounds[1] = $widthBounds[0];
        $heightBounds = isset($ranges[1]) ? explode("-", $ranges[1]) : false;
        if(!isset($heightBounds[1])) $heightBounds[1] = $heightBounds[0];
        if(   $width >= 0 + $widthBounds[0] && $width <= 0 + $widthBounds[1]
           && (   $heightBounds === false
               || ($height >= 0 + $heightBounds[0] && $height <= 0 + $heightBounds[1]))) {
            return false;
        }
        return true;
    }

    static public function matchExcludePattern($target, $pattern) {
        return (
            $pattern[0] == '/' && @preg_match($pattern, false) !== false && preg_match($pattern,  $target) //search as regex pattern if starts with a / and regex is valid
            || $pattern[0] != '/' && strpos($target, $pattern) !== false ); //search as a substring if not
    }

    //return an array with URL(s) and PATH(s) for this file
    public function getURLsAndPATHs($itemHandler, $meta = NULL, $onlyThumbs = false) {
        return $itemHandler->getURLsAndPATHs($this->_settings->processThumbnails, $onlyThumbs, $this->_settings->optimizeRetina, $this->_settings->excludeSizes);
    }

    /** Remove a directory
    * @param string $dirPath Path of directory to remove.
    * @todo Part of folder model.
    */
    public static function deleteDir($dirPath) {
        if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteDir($file);
                @rmdir($file);//remove empty dir
            } else {
                @unlink($file);//remove file
            }
        }
    }

    /** Gets size of folder recursivly
    * @param string $path Path
    * @todo Move to folder model
    */
    static public function folderSize($path) {
        $total_size = 0;
        if(file_exists($path)) {
            $files = scandir($path);
        } else {
            return $total_size;
        }
        $cleanPath = rtrim($path, '/'). '/';
        foreach($files as $t) {
            if ($t<>"." && $t<>"..")
            {
                $currentFile = $cleanPath . $t;
                if (is_dir($currentFile)) {
                    $size = self::folderSize($currentFile);
                    $total_size += $size;
                }
                else {
                    $size = filesize($currentFile);
                    $total_size += $size;
                }
            }
        }
        return $total_size;
    }

    public function migrateBackupFolder() {
        $oldBackupFolder = WP_CONTENT_DIR . '/' . SHORTPIXEL_BACKUP;

        if(file_exists($oldBackupFolder)) {  //if old backup folder does not exist then there is nothing to do

            if(!file_exists(SHORTPIXEL_BACKUP_FOLDER)) {
                //we check that the backup folder exists, if not we create it so we can copy into it
                if(! ShortPixelFolder::createBackUpFolder() ) return;
            }

            $scannedDirectory = array_diff(scandir($oldBackupFolder), array('..', '.'));
            foreach($scannedDirectory as $file) {
                @rename($oldBackupFolder.'/'.$file, SHORTPIXEL_BACKUP_FOLDER.'/'.$file);
            }
            $scannedDirectory = array_diff(scandir($oldBackupFolder), array('..', '.'));
            if(empty($scannedDirectory)) {
                @rmdir($oldBackupFolder);
            }
        }

        //now if the backup folder does not contain the uploads level, create it
        if(   !is_dir(SHORTPIXEL_BACKUP_FOLDER . '/' . SHORTPIXEL_UPLOADS_NAME )
           && !is_dir(SHORTPIXEL_BACKUP_FOLDER . '/' . basename(WP_CONTENT_DIR))) {
            @rename(SHORTPIXEL_BACKUP_FOLDER, SHORTPIXEL_BACKUP_FOLDER."_tmp");
            ShortPixelFolder::createBackUpFolder();
            @rename(SHORTPIXEL_BACKUP_FOLDER."_tmp", SHORTPIXEL_BACKUP_FOLDER.'/'.SHORTPIXEL_UPLOADS_NAME);
            if(!file_exists(SHORTPIXEL_BACKUP_FOLDER)) {//just in case..
                @rename(SHORTPIXEL_BACKUP_FOLDER."_tmp", SHORTPIXEL_BACKUP_FOLDER);
            }
        }
        //then create the wp-content level if not present
        if(!is_dir(SHORTPIXEL_BACKUP_FOLDER . '/' . basename(WP_CONTENT_DIR))) {
            @rename(SHORTPIXEL_BACKUP_FOLDER, SHORTPIXEL_BACKUP_FOLDER."_tmp");
            ShortPixelFolder::createBackUpFolder();
            @rename(SHORTPIXEL_BACKUP_FOLDER."_tmp", SHORTPIXEL_BACKUP_FOLDER.'/' . basename(WP_CONTENT_DIR));
            if(!file_exists(SHORTPIXEL_BACKUP_FOLDER)) {//just in case..
                @rename(SHORTPIXEL_BACKUP_FOLDER."_tmp", SHORTPIXEL_BACKUP_FOLDER);
            }
        }

        if (! file_exists( trailingslashit(SHORTPIXEL_BACKUP_FOLDER) . '.htaccess')  )
        {
            ShortPixelFolder::protectDirectoryListing(SHORTPIXEL_BACKUP_FOLDER);
        }
        return;
    }

    function getAllThumbnailSizes() {
        global $_wp_additional_image_sizes;

        $sizes_names = get_intermediate_image_sizes();
        $sizes = array();
        foreach ( $sizes_names as $size ) {
            $sizes[ $size ][ 'width' ] = intval( get_option( "{$size}_size_w" ) );
            $sizes[ $size ][ 'height' ] = intval( get_option( "{$size}_size_h" ) );
            $sizes[ $size ][ 'crop' ] = get_option( "{$size}_crop" ) ? get_option( "{$size}_crop" ) : false;
        }
        if(function_exists('wp_get_additional_image_sizes')) {
            $sizes = array_merge($sizes, wp_get_additional_image_sizes());
        } elseif(is_array($_wp_additional_image_sizes)) {
            $sizes = array_merge($sizes, $_wp_additional_image_sizes);
        }
        return $sizes;
    }

/** @todo Remove here
* */
    function getMaxIntermediateImageSize() {
        global $_wp_additional_image_sizes;

        $width = 0;
        $height = 0;
        $get_intermediate_image_sizes = get_intermediate_image_sizes();

        // Create the full array with sizes and crop info
        if(is_array($get_intermediate_image_sizes)) foreach( $get_intermediate_image_sizes as $_size ) {
            if ( in_array( $_size, array( 'thumbnail', 'medium', 'large' ) ) ) {
                $width = max($width, get_option( $_size . '_size_w' ));
                $height = max($height, get_option( $_size . '_size_h' ));
                //$sizes[ $_size ]['crop'] = (bool) get_option( $_size . '_crop' );
            } elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
                $width = max($width, $_wp_additional_image_sizes[ $_size ]['width']);
                $height = max($height, $_wp_additional_image_sizes[ $_size ]['height']);
                //'crop' =>  $_wp_additional_image_sizes[ $_size ]['crop']
            }
        }
        return array('width' => max(100, $width), 'height' => max(100, $height));
    }

    public function getOtherCompressionTypes($compressionType = false) {
        return array_values(array_diff(array(0, 1, 2), array(0 + $compressionType)));
    }

    function outputHSBeacon() {
        Log::addWarn('OutputHSBeacon called on old function');
	?>
        <style>
            .shortpixel-hs-blind {
                position: fixed;
                bottom: 18px;
                right: 0;
                z-index: 20003;
                background-color: white;
                width: 87px;
                height: 174px;
                border-radius: 20px 0 0 20px;
            }
            .shortpixel-hs-button-blind {
                display:none;
                position: fixed;
                bottom: 115px;right: 0;
                z-index: 20003;
                background-color: white;
                width: 237px;
                height: 54px;
            }
            .shortpixel-hs-tools {
                position: fixed;
                bottom: 116px;
                right: 0px;
                z-index: 20003;
                background-color: #ecf9fc;
                padding: 8px 18px 3px 12px;
                border-radius: 26px 0 0 26px;
                -webkit-box-shadow: 1px 1px 5px 0px rgba(6,109,117,1);
                -moz-box-shadow: 1px 1px 5px 0px rgba(6,109,117,1);
                box-shadow: 1px 1px 10px 0px rgb(172, 173, 173);
            }
            @media (max-width: 767px) {
                .shortpixel-hs-blind {
                    bottom: 8px;
                    height: 194px;
                }
                .shortpixel-hs-button-blind {
                    bottom: 100px;
                }
            }
        </style>
        <div id="shortpixel-hs-blind" class="shortpixel-hs-blind"></div>
        <div id="shortpixel-hs-button-blind" class="shortpixel-hs-button-blind"></div>
        <div id="shortpixel-hs-tools" class="shortpixel-hs-tools">
            <a href="javascript:shortpixelToggleHS();" class="shortpixel-hs-tools-docs" title="Search through our online documentation.">
                <img src="<?php echo(plugins_url('/shortpixel-image-optimiser/res/img/notes-sp.png'));?>" style="margin-bottom: 2px;width: 36px;">
            </a>
        </div>
        <script>
            window.shortpixelHSOpen = -1;
            function shortpixelToggleHS() {
                if(window.shortpixelHSOpen == -1) {
                    HS.beacon.init();
                }
                if(window.shortpixelHSOpen == 1) {
                    HS.beacon.close();
                    jQuery("#shortpixel-hs-button-blind").css('display', 'none');
                    window.shortpixelHSOpen = 0;
                } else {
                    HS.beacon.open();
                    jQuery("#shortpixel-hs-button-blind").css('display', 'block');
                    window.shortpixelHSOpen = 1;
                }
            }
        </script>
        <script type="text/javascript" src="https://quriobot.com/qb/widget/KoPqxmzqzjbg5eNl/V895xbyndnmeqZYd" async defer></script>
        <script>
            <?php
            $screen = get_current_screen();
            if($screen) {
                switch($screen->id) {
                    case 'media_page_wp-short-pixel-bulk':
                        echo("var shortpixel_suggestions =              [ '5a5de2782c7d3a19436843af', '5a5de6902c7d3a19436843e9', '5a5de5c42c7d3a19436843d0', '5a9945e42c7d3a75495145d0', '5a5de1c2042863193801047c', '5a5de66f2c7d3a19436843e0', '5a9946e62c7d3a75495145d8', '5a5de4f02c7d3a19436843c8', '5a5de65f042863193801049f', '5a5de2df0428631938010485' ]; ");
                        $suggestions = "shortpixel_suggestions";
                        break;
                    case 'settings_page_wp-shortpixel':
                        echo("var shortpixel_suggestions_settings =     [ '5a5de1de2c7d3a19436843a8', '5a6612032c7d3a39e6263a1d', '5a5de1c2042863193801047c', '5a5de2782c7d3a19436843af', '5a6610c62c7d3a39e6263a02', '5a9945e42c7d3a75495145d0', '5a5de66f2c7d3a19436843e0', '5a6597e80428632faf620487', '5a5de5c42c7d3a19436843d0', '5a5de5642c7d3a19436843cc' ]; ");
                        echo("var shortpixel_suggestions_adv_settings = [ '5a5de4f02c7d3a19436843c8', '5a8431f00428634376d01dc4', '5a5de58b0428631938010497', '5a5de65f042863193801049f', '5a9945e42c7d3a75495145d0', '5a9946e62c7d3a75495145d8', '5a5de57c0428631938010495', '5a5de2d22c7d3a19436843b1', '5a5de5c42c7d3a19436843d0', '5a5de5642c7d3a19436843cc' ]; ");
                        echo("var shortpixel_suggestions_cloudflare =   [ '5a5de1f62c7d3a19436843a9', '5a5de58b0428631938010497', '5a5de66f2c7d3a19436843e0', '5a5de5c42c7d3a19436843d0', '5a5de6902c7d3a19436843e9', '5a5de51a2c7d3a19436843c9', '5a9946e62c7d3a75495145d8', '5a5de46c2c7d3a19436843c1', '5a5de1de2c7d3a19436843a8', '5a6597e80428632faf620487' ]; ");
                        $suggestions = "shortpixel_suggestions_settings";
                        break;
                    case 'media_page_wp-short-pixel-custom':
                        echo("var shortpixel_suggestions =              [ '5a9946e62c7d3a75495145d8', '5a5de1c2042863193801047c', '5a5de2782c7d3a19436843af', '5a5de6902c7d3a19436843e9', '5a5de4f02c7d3a19436843c8', '5a6610c62c7d3a39e6263a02', '5a9945e42c7d3a75495145d0', '5a5de46c2c7d3a19436843c1', '5a5de1de2c7d3a19436843a8', '5a5de25c2c7d3a19436843ad' ]; ");
                        $suggestions = "shortpixel_suggestions";
                        break;
                }
            }
            ?>
            !function(e,o,n){ window.HSCW=o,window.HS=n,n.beacon=n.beacon||{};var t=n.beacon;t.userConfig={
                color: "#1CBECB",
                icon: "question",
                instructions: "Send ShortPixel a message",
                topArticles: true,
                poweredBy: false,
                showContactFields: true,
                showName: false,
                showSubject: true,
                translation: {
                    searchLabel: "What can ShortPixel help you with?",
                    contactSuccessDescription: "Thanks for reaching out! Someone from our team will get back to you in 24h max."
                }

            },t.readyQueue=[],t.config=function(e){this.userConfig=e},t.ready=function(e){this.readyQueue.push(e)},o.config={docs:{enabled:!0,baseUrl:"//shortpixel.helpscoutdocs.com/"},contact:{enabled:!0,formId:"278a7825-fce0-11e7-b466-0ec85169275a"}};var r=e.getElementsByTagName("script")[0],c=e.createElement("script");
                c.type="text/javascript",c.async=!0,c.src="https://djtflbt20bdde.cloudfront.net/",r.parentNode.insertBefore(c,r);
            }(document,window.HSCW||{},window.HS||{});

            window.HS.beacon.ready(function(){
                HS.beacon.identify({
                    email: "<?php $u = wp_get_current_user(); echo($u->user_email); ?>",
                    apiKey: "<?php echo($this->getApiKey());?>"
                });
                HS.beacon.suggest( <?php echo( $suggestions ) ?> );
            });
        </script><?php
    }

    public function validateFeedback($params) {
        if(isset($params['keep-settings'])) {
            $this->_settings->removeSettingsOnDeletePlugin = 1 - $params['keep-settings'];
        }
        return $params;
    }


    /*    public function getEncryptedData() {
            return base64_encode(self::encrypt($this->getApiKey() . "|" . get_site_url(), "sh0r+Pix3l8im1N3r"));
        }
    */

    /**
     * Returns an encrypted & utf8-encoded
     */
/*    public static function encrypt($pure_string, $encryption_key)
    {
        if(!function_exists("mcrypt_get_iv_size") || !function_exists('utf8_encode')) {
            return "";
        }
        $iv_size = \mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
        $iv = \mcrypt_create_iv($iv_size, MCRYPT_RAND);
        $encrypted_string = \mcrypt_encrypt(MCRYPT_BLOWFISH, $encryption_key, utf8_encode($pure_string), MCRYPT_MODE_ECB, $iv);
        return $encrypted_string;
    }
*/

    public function getApiKey() {
        return $this->_settings->apiKey;
    }

    public function getPrioQ() {
        return $this->prioQ;
    }

    public function backupImages() {
        return $this->_settings->backupImages;
    }

    public function processThumbnails() {
        return $this->_settings->processThumbnails;
    }

    public function getCMYKtoRGBconversion() {
        return $this->_settings->CMYKtoRGBconversion;
    }

    public function getSettings() {
        return $this->_settings;
    }

    public function getResizeImages() {
        return $this->_settings->resizeImages;
    }

    public function getResizeWidth() {
        return $this->_settings->resizeWidth;
    }

    public function getResizeHeight() {
        return $this->_settings->resizeHeight;
    }
    public static function getAffiliateSufix() {
      Log::addDebug('Function call - getAffiliateSufix should be removed');
// not allowed anymore by WP as of Sept.27 2018
//        return isset($_COOKIE["AffiliateShortPixel"])
//            ? "/affiliate/" . $_COOKIE["AffiliateShortPixel"]
//            : (defined("SHORTPIXEL_AFFILIATE_CODE") && strlen(SHORTPIXEL_AFFILIATE_CODE) ? "/affiliate/" . SHORTPIXEL_AFFILIATE_CODE : "");
        return "";
    }
    public function getVerifiedKey() {
        return $this->_settings->verifiedKey;
    }
    public function getCompressionType() {
        return $this->_settings->compressionType;
    }
    public function hasNextGen() {
        return $this->hasNextGen;
    }

    public function getSpMetaDao() {
        return $this->spMetaDao;
    }

	/**
	 * @desc Return CloudFlare API E-mail
	 *
	 * @return mixed|null
	 */
	public function fetch_cloudflare_api_email() {
		return $this->_settings->cloudflareEmail;
	}

	/**
	 * @desc Return CloudFlare API key
	 *
	 * @return mixed|null
	 */
	public function fetch_cloudflare_api_key() {
		return $this->_settings->cloudflareAuthKey;
	}

	/**
	 * @desc Return CloudFlare API Zone ID
	 *
	 * @return mixed|null
	 */
	public function fetch_cloudflare_api_zoneid() {
		return $this->_settings->cloudflareZoneID;
	}

}
