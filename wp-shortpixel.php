<?php
/**
 * Plugin Name: ShortPixel Image Optimizer
 * Plugin URI: https://shortpixel.com/
 * Description: ShortPixel optimizes images automatically, while guarding the quality of your images. Check your <a href="options-general.php?page=wp-shortpixel-settings" target="_blank">Settings &gt; ShortPixel</a> page on how to start optimizing your image library and make your website load faster.
 * Version: 4.14.5
 * Author: ShortPixel
 * Author URI: https://shortpixel.com
 * Text Domain: shortpixel-image-optimiser
 * Domain Path: /lang
 */
if (! defined('SHORTPIXEL_RESET_ON_ACTIVATE'))
  define('SHORTPIXEL_RESET_ON_ACTIVATE', false); //if true TODO set false
//define('SHORTPIXEL_DEBUG', true);
//define('SHORTPIXEL_DEBUG_TARGET', true);

define('SHORTPIXEL_PLUGIN_FILE', __FILE__);
define('SHORTPIXEL_PLUGIN_DIR', __DIR__);

//define('SHORTPIXEL_AFFILIATE_CODE', '');

define('SHORTPIXEL_IMAGE_OPTIMISER_VERSION', "4.14.5");
define('SHORTPIXEL_MAX_TIMEOUT', 10);
define('SHORTPIXEL_VALIDATE_MAX_TIMEOUT', 15);
define('SHORTPIXEL_BACKUP', 'ShortpixelBackups');
define('SHORTPIXEL_MAX_API_RETRIES', 50);
define('SHORTPIXEL_MAX_ERR_RETRIES', 5);
define('SHORTPIXEL_MAX_FAIL_RETRIES', 3);
if(!defined('SHORTPIXEL_MAX_THUMBS')) { //can be defined in wp-config.php
    define('SHORTPIXEL_MAX_THUMBS', 149);
}

if(!defined('SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION')) { //can be defined in wp-config.php
    define('SHORTPIXEL_USE_DOUBLE_WEBP_EXTENSION', false);
}

define('SHORTPIXEL_PRESEND_ITEMS', 3);
define('SHORTPIXEL_API', 'api.shortpixel.com');

$max_exec = intval(ini_get('max_execution_time'));
if ($max_exec === 0) // max execution time of zero means infinite. Quantify.
  $max_exec = 60;
define('SHORTPIXEL_MAX_EXECUTION_TIME', $max_exec);

// ** @todo For what is this needed? */
//require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(SHORTPIXEL_PLUGIN_DIR . '/build/shortpixel/autoload.php');


$sp__uploads = wp_upload_dir();
define('SHORTPIXEL_UPLOADS_BASE', (file_exists($sp__uploads['basedir']) ? '' : ABSPATH) . $sp__uploads['basedir'] );
//define('SHORTPIXEL_UPLOADS_URL', is_main_site() ? $sp__uploads['baseurl'] : dirname(dirname($sp__uploads['baseurl'])));
define('SHORTPIXEL_UPLOADS_NAME', basename(is_main_site() ? SHORTPIXEL_UPLOADS_BASE : dirname(dirname(SHORTPIXEL_UPLOADS_BASE))));
$sp__backupBase = is_main_site() ? SHORTPIXEL_UPLOADS_BASE : dirname(dirname(SHORTPIXEL_UPLOADS_BASE));
define('SHORTPIXEL_BACKUP_FOLDER', $sp__backupBase . '/' . SHORTPIXEL_BACKUP);
define('SHORTPIXEL_BACKUP_URL',
    ((is_main_site() || (defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL))
        ? $sp__uploads['baseurl']
        : dirname(dirname($sp__uploads['baseurl'])))
    . '/' . SHORTPIXEL_BACKUP);

/*
 if ( is_numeric(SHORTPIXEL_MAX_EXECUTION_TIME)  && SHORTPIXEL_MAX_EXECUTION_TIME > 10 )
    define('SHORTPIXEL_MAX_EXECUTION_TIME', SHORTPIXEL_MAX_EXECUTION_TIME - 5 );   //in seconds
else
    define('SHORTPIXEL_MAX_EXECUTION_TIME', 25 );
*/

define('SHORTPIXEL_MAX_EXECUTION_TIME2', 2 );
define("SHORTPIXEL_MAX_RESULTS_QUERY", 30);
//define("SHORTPIXEL_NOFLOCK", true); // don't use flock queue, can cause instability.

function shortpixelInit() {
    global $shortPixelPluginInstance;
    //limit to certain admin pages if function available
    $loadOnThisPage = function_exists('get_current_screen');
    if($loadOnThisPage) {
        $screen = get_current_screen();
        if(is_object($screen) && !in_array($screen->id, array('upload', 'edit', 'edit-tags', 'post-new', 'post'))) {
            return;
        }
    }
    $isAjaxButNotSP = false; //defined( 'DOING_AJAX' ) && DOING_AJAX && !(isset($_REQUEST['action']) && (strpos($_REQUEST['action'], 'shortpixel_') === 0));
    if (!isset($shortPixelPluginInstance)
        && (   (shortPixelCheckQueue() && get_option('wp-short-pixel-front-bootstrap'))
            || is_admin() && !$isAjaxButNotSP
               && (function_exists("is_user_logged_in") && is_user_logged_in()) //is admin, is logged in - :) seems funny but it's not, ajax scripts are admin even if no admin is logged in.
               && (   current_user_can( 'manage_options' )
                   || current_user_can( 'upload_files' )
                   || current_user_can( 'edit_posts' )
                  )
           )
       )
    {
        require_once('wp-shortpixel-req.php');

        $shortPixelPluginInstance = new WPShortPixel;
    }

}


function shortPixelCheckQueue(){
    require_once('class/shortpixel_queue.php');
    require_once('class/external/shortpixel_queue_db.php');
    $prio = (! defined('SHORTPIXEL_NOFLOCK')) ? ShortPixelQueue::get() : ShortPixelQueueDB::get();
    return $prio && is_array($prio) && count($prio);
}

/**
 * this is hooked into wp_generate_attachment_metadata
 * @param $meta
 * @param null $ID
 * @return WPShortPixel the instance
 */
function shortPixelHandleImageUploadHook($meta, $ID = null) {
    global $shortPixelPluginInstance;
    if(!isset($shortPixelPluginInstance)) {
        require_once('wp-shortpixel-req.php');
        $shortPixelPluginInstance = new WPShortPixel;
    }
    return $shortPixelPluginInstance->handleMediaLibraryImageUpload($meta, $ID);
}

function shortPixelReplaceHook($params) {
    if(isset($params['post_id'])) { //integration with EnableMediaReplace - that's an upload for replacing an existing ID
        global $shortPixelPluginInstance;
        if (!isset($shortPixelPluginInstance)) {
            require_once('wp-shortpixel-req.php');
            $shortPixelPluginInstance = new WPShortPixel;
        }
        $itemHandler = $shortPixelPluginInstance->onDeleteImage($params['post_id']);
        $itemHandler->deleteAllSPMeta();
    }
}

function shortPixelPng2JpgHook($params) {
    global $shortPixelPluginInstance;
    if(!isset($shortPixelPluginInstance)) {
        require_once('wp-shortpixel-req.php');
        $shortPixelPluginInstance = new WPShortPixel;
    }
    return $shortPixelPluginInstance->convertPng2Jpg($params);
}

function shortPixelNggAdd($image) {
    global $shortPixelPluginInstance;
    if(!isset($shortPixelPluginInstance)) {
        require_once('wp-shortpixel-req.php');
        $shortPixelPluginInstance = new WPShortPixel;
    }
    $shortPixelPluginInstance->handleNextGenImageUpload($image);
}

function shortPixelActivatePlugin () {
    require_once('wp-shortpixel-req.php');
    WPShortPixel::shortPixelActivatePlugin();
}

function shortPixelDeactivatePlugin () {
    require_once('wp-shortpixel-req.php');
    WPShortPixel::shortPixelDeactivatePlugin();
}

function shortPixelUninstallPlugin () {
    require_once('wp-shortpixel-req.php');
    WPShortPixel::shortPixelUninstallPlugin();
}

//Picture generation, hooked on the_content filter
function shortPixelConvertImgToPictureAddWebp($content) {
    if(function_exists('is_amp_endpoint') && is_amp_endpoint()) {
        //for AMP pages the <picture> tag is not allowed
        return $content . (isset($_GET['SHORTPIXEL_DEBUG']) ? '<!-- SPDBG is AMP -->' : '');
    }
    require_once('wp-shortpixel-req.php');
    require_once('class/front/img-to-picture-webp.php');

    return ShortPixelImgToPictureWebp::convert($content);// . "<!-- PICTURE TAGS BY SHORTPIXEL -->";
}
function shortPixelAddPictureJs() {
    // Don't do anything with the RSS feed.
    if ( is_feed() || is_admin() ) { return; }

    echo '<script>'
       . 'var spPicTest = document.createElement( "picture" );'
       . 'if(!window.HTMLPictureElement && document.addEventListener) {'
            . 'window.addEventListener("DOMContentLoaded", function() {'
                . 'var scriptTag = document.createElement("script");'
                . 'scriptTag.src = "' . plugins_url('/res/js/picturefill.min.js', __FILE__) . '";'
                . 'document.body.appendChild(scriptTag);'
            . '});'
        . '}'
       . '</script>';
}

add_filter( 'gform_save_field_value', 'shortPixelGravityForms', 10, 5 );

function shortPixelGravityForms( $value, $lead, $field, $form ) {
    global $shortPixelPluginInstance;
    if($field->type == 'post_image') {
        require_once('wp-shortpixel-req.php');
        $shortPixelPluginInstance = new WPShortPixel;
        $shortPixelPluginInstance->handleGravityFormsImageField($value);
    }
    return $value;
}

function shortPixelInitOB() {
    if(!is_admin() || (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined( 'DOING_AJAX' ) && DOING_AJAX)) {
        ob_start('shortPixelConvertImgToPictureAddWebp');
    }
}

function shortPixelIsPluginActive($plugin) {
    $activePlugins = apply_filters( 'active_plugins', get_option( 'active_plugins', array()));
    if ( is_multisite() ) {
        $activePlugins = array_merge($activePlugins, get_site_option( 'active_sitewide_plugins'));
    }
    return in_array( $plugin, $activePlugins);
}

// [BS] Start runtime here
$log = ShortPixel\ShortPixelLogger\ShortPixelLogger::getInstance();
$log->setLogPath(SHORTPIXEL_BACKUP_FOLDER . "/shortpixel_log");

// Pre-Runtime Checks
// @todo Better solution for pre-runtime inclusions of externals.
require_once('class/external/flywheel.php'); // check if SP runs on flywheel
require_once('class/external/wp-offload-media.php');


$option = get_option('wp-short-pixel-create-webp-markup');
if ( $option ) {
    if(shortPixelIsPluginActive('shortpixel-adaptive-images/short-pixel-ai.php')) {
        set_transient("shortpixel_thrown_notice", array('when' => 'spai', 'extra' => __('Please deactivate the ShortPixel Image Optimizer\'s
            <a href="options-general.php?page=wp-shortpixel-settings&part=adv-settings">Deliver WebP using PICTURE tag</a>
            option when the ShortPixel Adaptive Images plugin is active.','shortpixel-image-optimiser')), 1800);
    }
    elseif( $option == 1 ){
        add_action( 'wp_head', 'shortPixelAddPictureJs'); // adds polyfill JS to the header
        add_action( 'init', 'shortPixelInitOB', 1 ); // start output buffer to capture content
    } elseif ($option == 2){
        add_filter( 'the_content', 'shortPixelConvertImgToPictureAddWebp', 10000 ); // priority big, so it will be executed last
        add_filter( 'the_excerpt', 'shortPixelConvertImgToPictureAddWebp', 10000 );
        add_filter( 'post_thumbnail_html', 'shortPixelConvertImgToPictureAddWebp');
    }
//    add_action( 'wp_enqueue_scripts', 'spAddPicturefillJs' );
}

if ( !function_exists( 'vc_action' ) || vc_action() !== 'vc_inline' ) { //handle incompatibility with Visual Composer
    add_action( 'init',  'shortpixelInit');
    add_action('ngg_added_new_image', 'shortPixelNggAdd');

    $autoPng2Jpg = get_option('wp-short-pixel-png2jpg');
    $autoMediaLibrary = get_option('wp-short-pixel-auto-media-library');
    if($autoPng2Jpg && $autoMediaLibrary) {
        add_action( 'wp_handle_upload', 'shortPixelPng2JpgHook');
        add_action( 'mpp_handle_upload', 'shortPixelPng2JpgHook');
    }
    add_action('wp_handle_replace', 'shortPixelReplaceHook');
    if($autoMediaLibrary) {
        add_filter( 'wp_generate_attachment_metadata', 'shortPixelHandleImageUploadHook', 10, 2 );
        add_filter( 'mpp_generate_metadata', 'shortPixelHandleImageUploadHook', 10, 2 );
    }

    register_activation_hook( __FILE__, 'shortPixelActivatePlugin' );
    register_deactivation_hook( __FILE__, 'shortPixelDeactivatePlugin' );
    register_uninstall_hook(__FILE__, 'shortPixelUninstallPlugin');
}
?>
