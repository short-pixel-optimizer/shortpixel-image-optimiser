<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use \ShortPixel\ShortPixelPng2Jpg as ShortPixelPng2Jpg;


/* AdminController is meant for handling events, hooks, filters in WordPress where there is *NO* specific or more precise  Shortpixel Page active.
*
* This should be a delegation class connection global hooks and such to the best shortpixel handler.
*/
class AdminController extends \ShortPixel\Controller
{
    protected static $instance;

    public function __construct()
    {

    }

    public static function getInstance()
    {
      if (is_null(self::$instance))
          self::$instance = new AdminController();

      return self::$instance;
    }

    /** Handling upload actions
    * @hook wp_generate_attachment_metadata
    */
    public function handleImageUploadHook($meta, $id)
    {
    //    return \wpSPIO()->getShortPixel()->handleMediaLibraryImageUpload($meta, $ID);

        // Media only hook
        $mediaItem = \wpSPIO()->filesystem()->getImage($id, 'media');
        $control = new OptimizeController();
        $control->addItemToQueue($mediaItem);
        Log::addTemp('Handle Image Upload: Item Added to Queue' . $id);

        return $meta; // It's a filter, otherwise no thumbs

    }

    /** For conversion
    * @hook wp_handle_upload
    */
    public function handlePng2JpgHook($id)
    {
      $mediaItem = \wpSPIO()->filesystem()->getImage($id, 'media');
      // IsProcessable sets do_png2jpg flag.
      if ($mediaItem->isProcessable() && $mediaItem->get('do_png2jpg') == true)
      {
          $mediaItem->convertPNG();
      }
      //return \wpSPIO()->getShortPixel()->convertPng2Jpg($params);
    }

    /** When replacing happens.
    * @hook wp_handle_replace
    */
    public function handleReplaceHook($params)
    {
      if(isset($params['post_id'])) { //integration with EnableMediaReplace - that's an upload for replacing an existing ID
          $itemHandler = \wpSPIO()->getShortPixel()->onDeleteImage( intval($params['post_id']) );
          $itemHandler->deleteAllSPMeta();
      }
    }

    public function generatePluginLinks($links) {
        $in = '<a href="options-general.php?page=wp-shortpixel-settings">Settings</a>';
        array_unshift($links, $in);
        return $links;
    }

    /** If webp generating functionality is on, give mime-permissions for webp extension
    *
    */
    public function addWebpMime($mimes)
    {
        $settings = \wpSPIO()->settings();
        if ($settings->createWebp)
        {
            if (! isset($mimes['webp']))
              $mimes['webp'] = 'image/webp';
        }
        return $mimes;
    }

    /** When an image is deleted
    * @hook delete_attachment
    * @param int $post_id  ID of Post
    * @return itemHandler ItemHandler object.
    */
    public function onDeleteAttachment($post_id) {
        Log::addDebug('onDeleteImage - Image Removal Detected ' . $post_id);
        $result = null;
        $fs = \wpSPIO()->filesystem();

        try
        {
          $imageObj = $fs->getImage($post_id, 'media');
          if ($imageObj !== false)
            $result = $imageObj->onDelete();
        }
        catch(Exception $e)
        {
          Log::addError('OndeleteImage triggered an error. ' . $e->getMessage(), $e);
        }
        return $result;
    }

    /** Displays an icon in the toolbar when processing images
    *   hook - admin_bar_menu
    *  @param Obj $wp_admin_bar
    */
    function toolbar_shortpixel_processing( $wp_admin_bar ) {

        if (! \wpSPIO()->env()->is_screen_to_use )
          return; // not ours, don't load JS and such.

        $settings = \wpSPIO()->settings();

        $extraClasses = " shortpixel-hide";
        /*translators: toolbar icon tooltip*/
        $id = 'short-pixel-notice-toolbar';
        $tooltip = __('ShortPixel optimizing...','shortpixel-image-optimiser') . " " . __('Please do not close this admin page.','shortpixel-image-optimiser');
        $icon = "shortpixel.png";
        $successLink = $link = admin_url(current_user_can( 'edit_others_posts')? 'upload.php?page=wp-short-pixel-bulk' : 'upload.php');
        $blank = "";
    /*    if($this->prioQ->processing()) {
            $extraClasses = " shortpixel-processing";
        } */
        if($settings->quotaExceeded && !isset($settings->dismissedNotices['exceed'])) {
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
        $lastStatus = $settings->bulkLastStatus;

        $args = array(
                'id'    => 'shortpixel_processing',
                'title' => '<div id="' . $id . '" title="' . $tooltip . '" ><span class="stats hidden">0</span><img alt="' . __('ShortPixel icon','shortpixel-image-optimiser') . '" src="'
                         . plugins_url( 'res/img/'.$icon, SHORTPIXEL_PLUGIN_FILE ) . '" success-url="' . $successLink . '"><span class="shp-alert">!</span>'
                         . '<div class="controls">
                              <span class="dashicons dashicons-controls-pause pause" title="' . __('Pause', 'shortpixel-image-optimiser') . '">&nbsp;</span>
                              <span class="dashicons dashicons-controls-play play" title="' . __('Resume', 'shortpixel-image-optimiser') . '">&nbsp;</span>
                            </div>'

                         .'<div class="cssload-container"><div class="cssload-speeding-wheel"></div></div></div>',
                'href'  => 'javascript:void(0)', // $link,
                'meta'  => array('target'=> $blank, 'class' => 'shortpixel-toolbar-processing' . $extraClasses)
        );
        $wp_admin_bar->add_node( $args );

        if($settings->quotaExceeded && !isset($settings->dismissedNotices['exceed'])) {
            $wp_admin_bar->add_node( array(
                'id'    => 'shortpixel_processing-title',
                'parent' => 'shortpixel_processing',
                'title' => $exceedTooltip,
                'href'  => $link
            ));

        }
    }


}
