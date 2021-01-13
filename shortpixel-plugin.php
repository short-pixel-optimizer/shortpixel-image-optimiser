<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Controller\OptimizeController as OptimizeController;
use ShortPixel\Controller\AjaxController as AjaxController;
//use ShortPixel\Controller;

use ShortPixel\Controller\Queue\MediaLibraryQueue as MediaLibraryQueue;
use ShortPixel\Controller\Queue\CustomQueue as CustomQueue;

/** Plugin class
* This class is meant for: WP Hooks, init of runtime and Controller Routing.

*/
class ShortPixelPlugin
{
  private static $instance;
  protected static $modelsLoaded = array(); // don't require twice, limit amount of require looksups..

  private $paths = array('class', 'class/controller', 'class/external', 'class/controller/views'); // classes that are autoloaded

  protected $is_noheaders = false;

  protected $plugin_path;
  protected $plugin_url;

  protected $shortPixel; // shortpixel megaclass
  protected $settings; // settings object.

  protected $admin_pages = array();  // admin page hooks.

  public function __construct()
  {
      $this->plugin_path = plugin_dir_path(SHORTPIXEL_PLUGIN_FILE);
      $this->plugin_url = plugin_dir_url(SHORTPIXEL_PLUGIN_FILE);

      //$this->initHooks();
      add_action('plugins_loaded', array($this, 'lowInit'), 5); // early as possible init.
  }


  /** LowInit after all Plugins are loaded. Core WP function can still be missing. This should mostly add hooks */
  public function lowInit()
  {
    if(isset($_REQUEST['noheader'])) {
        $this->is_noheaders = true;
    }

    /* Filter to prevent SPIO from starting. This can be used by third-parties to prevent init when needed for a particular situation.
    * Hook into plugins_loaded with priority lower than 5 */
    $init = apply_filters('shortpixel/plugin/init', true);

    if (! $init)
    {
      return;
    }

    // @todo Transitionary init for the time being, since plugin init functionality is still split between.
    global $shortPixelPluginInstance;
    $shortPixelPluginInstance = new \wpShortPixel();
    $this->shortPixel = $shortPixelPluginInstance;

    $front = new Controller\FrontController();
    $admin = Controller\AdminController::getInstance();
    $adminNotices = Controller\AdminNoticesController::getInstance(); // Hook in the admin notices.

    $this->initHooks();
    $this->ajaxHooks();


    add_action('admin_init', array($this, 'init'));
  }


  /** Mainline Admin Init. Tasks that can be loaded later should go here */
  public function init()
  {
      $this->shortPixel->loadHooks();

      $notices = Notices::getInstance(); // This hooks the ajax listener

  }

  /** Function to get plugin settings
  *
  * @return SettingsModel The settings model object.
  */
  public function settings()
  {
    if (is_null($this->settings))
      $this->settings = new \WPShortPixelSettings();

    return $this->settings;
  }

  /** Function to get all enviromental variables
  *
  * @return EnvironmentModel
  */
  public function env()
  {
    return Model\EnvironmentModel::getInstance();
  }

  public function fileSystem()
  {
    return new Controller\FileSystemController();
  }

  /** Create instance. This should not be needed to call anywhere else than main plugin file
  * This should not be called *after* plugins_loaded action
  **/
  public static function getInstance()
  {
    if (is_null(self::$instance))
    {
      self::$instance = new ShortPixelPlugin();
    }
    return self::$instance;

  }


  /** Hooks for all WordPress related hooks
  * For now hooks in the lowInit, asap.
  */
  public function initHooks()
  {
      add_action('admin_menu', array($this,'admin_pages'));
      add_action('admin_enqueue_scripts', array($this, 'admin_scripts')); // admin scripts
      add_action('admin_enqueue_scripts', array($this, 'admin_styles')); // admin styles
      add_action('admin_enqueue_scripts', array($this, 'load_admin_scripts'), 90); // loader via route.
      // defer notices a little to allow other hooks ( notable adminnotices )

    //  add_action( 'shortpixel-thumbnails-before-regenerate', array( $this->shortPixel, 'thumbnailsBeforeRegenerateHook' ), 10, 1);
      add_action( 'shortpixel-thumbnails-regenerated', array( OptimizeController::getInstance(), 'thumbnailsRegeneratedHook' ), 10, 4);

      // Media Library
      add_action('load-upload.php', array($this, 'route'));

      $admin = Controller\AdminController::getInstance();

      if ($this->settings()->autoMediaLibrary)
      {
          // compat filter to shortcircuit this in cases.  (see external - visualcomposer)
          if (apply_filters('shortpixel/init/automedialibrary', true))
          {
            if($this->settings()->autoMediaLibrary && $this->settings()->png2jpg) {
                add_action( 'wp_handle_upload', array($admin,'handlePng2JpgHook'));
                // @todo Document what plugin does mpp
                add_action( 'mpp_handle_upload', array($admin,'handlePng2JpgHook'));
            }
            add_action('wp_handle_replace', array($admin,'handleReplaceHook'));

            if($this->settings()->autoMediaLibrary) {

                add_filter( 'wp_generate_attachment_metadata', array($admin,'handleImageUploadHook'), 10, 2 );
                // @todo Document what plugin does mpp
                add_filter( 'mpp_generate_metadata', array($admin,'handleImageUploadHook'), 10, 2 );
            }
          }
      }
      elseif($this->settings()->frontBootstrap && $this->env()->is_front)
      {
        // if automedialibrary is off, but we do want to auto-optimize on the front, still load the hook.
        add_filter( 'wp_generate_attachment_metadata', array($admin,'handleImageUploadHook'), 10, 2 );
      }

  }

  public function ajaxHooks()
  {

    // Ajax hooks. Should always be prepended with ajax_ and *must* check on nonce in function
    add_action( 'wp_ajax_shortpixel_image_processing', array(AjaxController::getInstance(), 'ajax_processQueue') );
    add_action( 'wp_ajax_shortpixel_exit_process', array(AjaxController::getInstance() , 'ajax_removeProcessorKey'));
    add_action( 'wp_ajax_shortpixel_get_item_view', array(AjaxController::getInstance(), 'ajax_getItemView'));
    add_action( 'wp_ajax_shortpixel_manual_optimization', array(AjaxController::getInstance(), 'ajax_addItem'));

    // @todo should probably go through ajaxrequest.
    add_action( 'wp_ajax_shortpixel_get_comparer_data', array(AjaxController::getInstance(), 'ajax_getComparerData'));

    add_action( 'wp_ajax_shortpixel_ajaxRequest', array(AjaxController::getInstance(), 'ajaxRequest'));

    // *** AJAX HOOKS  @todo These must be moved from wp-short-pixel in future */
    //add_action('wp_ajax_shortpixel_helpscoutOptin', array(\wpSPIO()->settings(), 'ajax_helpscoutOptin'));

  }

  /** Hook in our admin pages */
  public function admin_pages()
  {
      $admin_pages = array();
      // settings page
      $admin_pages[] = add_options_page( __('ShortPixel Settings','shortpixel-image-optimiser'), 'ShortPixel', 'manage_options', 'wp-shortpixel-settings', array($this, 'route'));

      if($this->shortPixel->getSpMetaDao()->hasFoldersTable() && count($this->shortPixel->getSpMetaDao()->getFolders())) {
          /*translators: title and menu name for the Other media page*/
        $admin_pages[] = add_media_page( __('Other Media Optimized by ShortPixel','shortpixel-image-optimiser'), __('Other Media','shortpixel-image-optimiser'), 'edit_others_posts', 'wp-short-pixel-custom', array( $this, 'route' ) );
      }
      /*translators: title and menu name for the Bulk Processing page*/
      $admin_pages[] = add_media_page( __('ShortPixel Bulk Process','shortpixel-image-optimiser'), __('Bulk ShortPixel','shortpixel-image-optimiser'), 'edit_others_posts', 'wp-short-pixel-bulk', array( $this, 'route' ) );

      $this->admin_pages = $admin_pages;
  }

  /** PluginRunTime. Items that should be initialized *only* when doing our pages and territory. */
  protected function initPluginRunTime()
  {

  }

  /** All scripts should be registed, not enqueued here (unless global wp-admin is needed )
  *
  * Not all those registered must be enqueued however.
  */
  public function admin_scripts()
  {
    if (Log::debugIsActive()) {
        $jsSuffix = '.js'; //use unminified versions for easier debugging
    }
    else
        $jsSuffix = '.min.js';

    $settings = \wpSPIO()->settings();
    $ajaxController = AjaxController::getInstance();

    $secretKey = $ajaxController->getProcessorKey();

    $keyControl = \ShortPixel\Controller\ApiKeyController::getInstance();
    $apikey = $keyControl->getKeyForDisplay();

    $is_bulk_page = \wpSPIO()->env()->is_bulk_page;

    // FileTree in Settings
    wp_register_script('sp-file-tree', plugins_url('/res/js/sp-file-tree.min.js',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

    wp_register_script('jquery.knob.min.js', plugins_url('/res/js/jquery.knob.min.js',SHORTPIXEL_PLUGIN_FILE), array(),SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true  );

    wp_register_script('jquery.tooltip.min.js', plugins_url('/res/js/jquery.tooltip.min.js',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true );

    wp_register_script('shortpixel-debug', plugins_url('/res/js/debug.js',SHORTPIXEL_PLUGIN_FILE), array('jquery', 'jquery-ui-draggable'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

    wp_register_script ('shortpixel-tooltip', plugins_url('/res/js/shortpixel-tooltip.js',SHORTPIXEL_PLUGIN_FILE), array('jquery' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

     wp_register_script('shortpixel-processor', plugins_url('/res/js/shortpixel-processor.js',SHORTPIXEL_PLUGIN_FILE), array('jquery', 'shortpixel-tooltip' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

    wp_localize_script('shortpixel-processor', 'ShortPixelProcessorData',  array(
        'bulkSecret' => $secretKey,
        'isBulkPage' => (bool) $is_bulk_page,
        'screenURL' => false,
        'workerURL' => $this->plugin_url('res/js/shortpixel-worker.js'),
        'nonce_process' => wp_create_nonce('processing'),
        'nonce_exit' => wp_create_nonce('exit_process'),
        'nonce_itemview' => wp_create_nonce('item_view'),
        'nonce_ajaxrequest' => wp_create_nonce('ajax_request'),
    ));



    wp_register_script ('shortpixel-screen-media', plugins_url('/res/js/screens/screen-media.js',SHORTPIXEL_PLUGIN_FILE), array('jquery', 'shortpixel-processor' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

    wp_register_script ('shortpixel-screen-custom', plugins_url('/res/js/screens/screen-custom.js',SHORTPIXEL_PLUGIN_FILE), array('jquery', 'shortpixel-processor' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

    wp_register_script ('shortpixel-screen-nolist', plugins_url('/res/js/screens/screen-nolist.js',SHORTPIXEL_PLUGIN_FILE), array('jquery', 'shortpixel-processor' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

    wp_register_script ('shortpixel-screen-bulk', plugins_url('/res/js/screens/screen-bulk.js',SHORTPIXEL_PLUGIN_FILE), array('jquery', 'shortpixel-processor' ), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);

    $mediaQ = MediaLibraryQueue::getInstance();
    $customQ = CustomQueue::getInstance();

    // Localize status of queue for resume function to start on proper panel.
    wp_localize_script('shortpixel-screen-bulk', 'ShortPixelScreenBulk', array(
           'custom' => $customQ->getStats(),
           'media' => $mediaQ->getStats(),
    ) );


    wp_register_script('shortpixel', plugins_url('/res/js/shortpixel' . $jsSuffix,SHORTPIXEL_PLUGIN_FILE), array('jquery', 'jquery.knob.min.js'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION, true);


    // Using an Array within another Array to protect the primitive values from being cast to strings
    $ShortPixelConstants = array(array(
      /*  'STATUS_SUCCESS'=>ShortPixelAPI::STATUS_SUCCESS,
        'STATUS_EMPTY_QUEUE'=>self::BULK_EMPTY_QUEUE,
        'STATUS_ERROR'=>ShortPixelAPI::STATUS_ERROR,
        'STATUS_FAIL'=>ShortPixelAPI::STATUS_FAIL,
        'STATUS_QUOTA_EXCEEDED'=>ShortPixelAPI::STATUS_QUOTA_EXCEEDED,
        'STATUS_SKIP'=>ShortPixelAPI::STATUS_SKIP,
        'STATUS_NO_KEY'=>ShortPixelAPI::STATUS_NO_KEY,
        'STATUS_RETRY'=>ShortPixelAPI::STATUS_RETRY,
        'STATUS_QUEUE_FULL'=>ShortPixelAPI::STATUS_QUEUE_FULL,
        'STATUS_MAINTENANCE'=>ShortPixelAPI::STATUS_MAINTENANCE,
        'STATUS_SEARCHING' => ShortPixelAPI::STATUS_SEARCHING, */
        'WP_PLUGIN_URL'=>plugins_url( '', SHORTPIXEL_PLUGIN_FILE ),
        'WP_ADMIN_URL'=>admin_url(),
        'API_IS_ACTIVE' => $keyControl->keyIsVerified(),
  //      'DEFAULT_COMPRESSION'=>0 + intval($this->_settings->compressionType), // no int can happen when settings are empty still
  //      'MEDIA_ALERT'=>$this->_settings->mediaAlert ? "done" : "todo",
        'FRONT_BOOTSTRAP'=> $settings->frontBootstrap && (!isset($settings->lastBackAction) || (time() - $settings->lastBackAction > 600)) ? 1 : 0,
        'AJAX_URL'=>admin_url('admin-ajax.php'),
        'BULK_SECRET' => $secretKey,

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
            'originalImage' => __('Original image', 'shortpixel-image-optimiser' ),
            'optimizedImage' => __('Optimized image', 'shortpixel-image-optimiser' ),
            'loading' => __('Loading...', 'shortpixel-image-optimiser' ),
            //'' => __('', 'shortpixel-image-optimiser' ),
    );

    /*$actions = array(
        'nonce_check_quota' => wp_create_nonce('check_quota')
    ); */
    wp_localize_script( 'shortpixel', '_spTr', $jsTranslation );
    wp_localize_script( 'shortpixel', 'ShortPixelConstants', $ShortPixelConstants );
    //wp_localize_script('shortpixel', 'ShortPixelActions', $actions);


    if (! \wpSPIO()->env()->is_screen_to_use )
    {
      if (! wpSPIO()->env()->is_front) // exeception if this is called to load from your frontie.
         return; // not ours, don't load JS and such.
    }


  }

  public function admin_styles()
  {

    wp_register_style('sp-file-tree', plugins_url('/res/css/sp-file-tree.min.css',SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

    wp_register_style('shortpixel', plugins_url('/res/css/short-pixel.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

    //modal - used in settings for selecting folder
    wp_register_style('shortpixel-modal', plugins_url('/res/css/short-pixel-modal.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

    // notices. additional styles for SPIO.
    wp_register_style('shortpixel-notices', plugins_url('/res/css/shortpixel-notices.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

    // other media screen
    wp_register_style('shortpixel-othermedia', plugins_url('/res/css/shortpixel-othermedia.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

    // load everywhere, because we are inconsistent.
    wp_enqueue_style('shortpixel-tooltip', plugins_url('/res/css/shortpixel-toolbar.css',SHORTPIXEL_PLUGIN_FILE), array('dashicons'), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

    if ( \wpSPIO()->env()->is_our_screen )
    {
    /*if( in_array($screen->id, array('attachment', 'upload', 'settings_page_wp-shortpixel', 'media_page_wp-short-pixel-bulk', 'media_page_wp-short-pixel-custom'))) { */
        wp_register_style('short-pixel.min.css', plugins_url('/res/css/short-pixel.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);
        //modal - used in settings for selecting folder
        wp_register_style('short-pixel-modal.min.css', plugins_url('/res/css/short-pixel-modal.min.css',SHORTPIXEL_PLUGIN_FILE), array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION);

        // @todo Might need to be removed later on
        wp_register_style('shortpixel-admin', plugins_url('/res/css/shortpixel-admin.css', SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );

        wp_register_style('shortpixel-bulk', plugins_url('/res/css/shortpixel-bulk.css', SHORTPIXEL_PLUGIN_FILE),array(), SHORTPIXEL_IMAGE_OPTIMISER_VERSION );
        //wp_register_style('shortpixel-admin');
    }

  }


  /** Load Style via Route, on demand */
  public function load_style($name)
  {
    if ($this->is_noheaders)  // fail silently, if this is a no-headers request.
      return;

    if (wp_style_is($name, 'registered'))
    {
      wp_enqueue_style($name);
    }
    else {
      Log::addWarn("Style $name was asked for, but not registered");
    }
  }

  /** Load Style via Route, on demand */
  public function load_script($script)
  {
    if ($this->is_noheaders)  // fail silently, if this is a no-headers request.
      return;

    if (! is_array($script))
       $script = array($script);

    foreach($script as $index => $name)
    {

        if (wp_script_is($name, 'registered'))
        {
          wp_enqueue_script($name);
        }
        else {
          Log::addWarn("Script $name was asked for, but not registered");
        }
    }
  }

  /** This is separated from route to load in head, preventing unstyled content all the time */
  public function load_admin_scripts()
  {
    global $plugin_page;
    $screen_id = \wpSPIO()->env()->screen_id;

    //$load = array();
    $load_processor = array('shortpixel', 'shortpixel-processor');  // a whole suit needed for processing, not more.
    $load_bulk = array();  // the whole suit needed for bulking.

    if ( \wpSPIO()->env()->is_screen_to_use )
    {
      $this->load_script('shortpixel-tooltip.js');
      $this->load_style('shortpixel-toolbar');
    }

    if ($plugin_page == 'wp-shortpixel-settings')
    {
      $this->load_style('shortpixel-admin');
      $this->load_style('shortpixel');
      $this->load_style('shortpixel-modal');
      $this->load_style('sp-file-tree');

      $this->load_script('jquery.tooltip.min.js');
      $this->load_script('sp-file-tree');

      $this->load_script($load_processor);
      $this->load_script('shortpixel-screen-nolist'); // screen

    }

    if ($plugin_page == 'wp-short-pixel-bulk')
    {
        $this->load_script($load_processor);
        $this->load_script('shortpixel-screen-bulk');

        $this->load_style('shortpixel-bulk');
    }


    if ($screen_id == 'upload' || $screen_id == 'attachment')
    {
       $this->load_script($load_processor);
       $this->load_script('shortpixel-screen-media'); // screen

       $this->load_style('shortpixel-admin');
       $this->load_style('shortpixel-modal'); // for comparer
       $this->load_style('shortpixel');

    }

    if ($plugin_page == 'wp-short-pixel-custom')
    {
      $this->load_style('shortpixel');
      $this->load_style('shortpixel-modal'); // for comparer
      $this->load_style('shortpixel-othermedia');

      $this->load_script($load_processor);
      $this->load_script('shortpixel-screen-custom'); // screen

    }


  }

  /** Route, based on the page slug
  *
  * Principially all page controller should be routed from here.
  */
  public function route()
  {
      global $plugin_page;
      $this->initPluginRunTime();

      $default_action = 'load'; // generic action on controller.
      $action = isset($_REQUEST['sp-action']) ? sanitize_text_field($_REQUEST['sp-action']) : $default_action;
      $template_part = isset($_GET['part']) ? sanitize_text_field($_GET['part']) : false;

      $controller = false;

      if ($this->env()->is_debug)
      {
         $this->load_script('shortpixel-debug');
      }

      $url = menu_page_url($plugin_page, false);
      $screen_id = \wpSPIO()->env()->screen_id;

        switch($plugin_page)
        {
            case 'wp-shortpixel-settings': // settings
              $controller = 'ShortPixel\Controller\SettingsController';
            break;
            case 'wp-short-pixel-custom': // other media
            /*  $this->load_style('shortpixel-othermedia'); */
              $controller = 'ShortPixel\Controller\View\OtherMediaViewController';
            break;
            case 'wp-short-pixel-bulk':
              if ($template_part)
              {
                switch($template_part)
                {
                  case 'bulk-restore-all':
                    $controller = '\ShortPixel\Controller\View\BulkRestoreAll';
                  break;
                }
              }
              else
                $controller = '\ShortPixel\Controller\View\BulkViewController';
            break;
            case null:
            default:
                switch($screen_id)
                {
                     case 'upload':
                        $controller = '\ShortPixel\Controller\View\ListMediaViewController';
                     break;

                }
            break;


      }

      if ($controller !== false)
      {
        $c = new $controller();
        $c->setShortPixel($this->shortPixel);
        $c->setControllerURL($url);
        if (method_exists($c, $action))
          $c->$action();
        else {
          Log::addWarn("Attempted Action $action on $controller does not exist!");
          $c->$default_action();
        }

      }
  }


  // Get the plugin URL, based on real URL.
  public function plugin_url($urlpath = '')
  {
    $url = trailingslashit($this->plugin_url);
    if (strlen($urlpath) > 0)
      $url .= $urlpath;
    return $url;
  }

  // Get the plugin path.
  public function plugin_path($path = '')
  {
    $plugin_path = trailingslashit($this->plugin_path);
    if (strlen($path) > 0)
      $plugin_path .= $path;

    return $plugin_path;
  }

  // Get the ShortPixel Object.
  public function getShortPixel()
  {
    return $this->shortPixel;
  }

  /** Returns defined admin page hooks. Internal use - check states via environmentmodel
  * @returns Array
  */
  public function get_admin_pages()
  {
    return $this->admin_pages;
  }

  public static function activatePlugin()
  {
      self::deactivatePlugin();
      if(SHORTPIXEL_RESET_ON_ACTIVATE === true && WP_DEBUG === true) { //force reset plugin counters, only on specific occasions and on test environments
          \WPShortPixelSettings::debugResetOptions();
          $settings = new \WPShortPixelSettings();
          $spMetaDao = new \ShortPixelCustomMetaDao(new \WpShortPixelDb(), $settings->excludePatterns);
          $spMetaDao->dropTables();
      }

      $env = wpSPIO()->env();

      if(\WPShortPixelSettings::getOpt('deliverWebp') == 3 && ! $env->is_nginx) {
          \WpShortPixel::alterHtaccess(); //add the htaccess lines
      }

      \WpShortPixelDb::checkCustomTables();

      Controller\AdminNoticesController::resetAllNotices();

    /*  Controller\AdminNoticesController::resetCompatNotice();
      Controller\AdminNoticesController::resetAPINotices();
      Controller\AdminNoticesController::resetQuotaNotices();
      Controller\AdminNoticesController::resetIntegrationNotices();
*/
      \WPShortPixelSettings::onActivate();

  }

  public static function deactivatePlugin()
  {
    \ShortPixelQueue::resetBulk();
    (! defined('SHORTPIXEL_NOFLOCK')) ? \ShortPixelQueue::resetPrio() : \ShortPixelQueueDB::resetPrio();
    \WPShortPixelSettings::onDeactivate();

    $env = wpSPIO()->env();

    if (! $env->is_nginx)
      \WpShortPixel::alterHtaccess(true);

    // save remove.
    $fs = new Controller\FileSystemController();
    $log = $fs->getFile(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log");
    if ($log->exists())
      $log->delete();
  }

  public static function uninstallPlugin()
  {
    $settings = new \WPShortPixelSettings();
    $env = \wpSPIO()->env();

    if($settings->removeSettingsOnDeletePlugin == 1) {
        \WPShortPixelSettings::debugResetOptions();
        if (! $env->is_nginx)
          insert_with_markers( get_home_path() . '.htaccess', 'ShortPixelWebp', '');

        $spMetaDao = new \ShortPixelCustomMetaDao(new \WpShortPixelDb());
        $spMetaDao->dropTables();
    }
  }




} // class plugin
