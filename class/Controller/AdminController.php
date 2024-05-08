<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;
use ShortPixel\Controller\Queue\Queue as Queue;

use ShortPixel\Model\Converter\Converter as Converter;
use ShortPixel\Model\Converter\ApiConverter as ApiConverter;

use ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Model\AccessModel as AccessModel;
use ShortPixel\Helper\UtilHelper as UtilHelper;


/* AdminController is meant for handling events, hooks, filters in WordPress where there is *NO* specific or more precise  ShortPixel Page active.
*
* This should be a delegation class connection global hooks and such to the best shortpixel handler.
*/
class AdminController extends \ShortPixel\Controller
{
    protected static $instance;

		private static $preventUploadHook = array();

    public static function getInstance()
    {
      if (is_null(self::$instance))
          self::$instance = new AdminController();

      return self::$instance;
    }



    public function addAttachmentHook($post_id)
    {
          $fs = \wpSPIO()->filesystem();
          
          // If attachment doesn't come back as an valid image
          $mediaItem = $fs->getImage($post_id, 'media');
          if (false === $mediaItem)
          {
             return;
          }

          $converter = Converter::getConverter($mediaItem, true);

            if (is_object($converter) && $converter->isConvertable())
            {
              do_action('shortpixel/converter/prevent-offload', $post_id);
            }
    }


    /** Handling upload actions
    * @hook wp_generate_attachment_metadata
    */
    public function handleImageUploadHook($meta, $id)
    {
        Log::addTemp('Handle Image Upload');

        // Media only hook
				if ( in_array($id, self::$preventUploadHook))
				{
					 return $meta;
				}

        // todo add check here for mediaitem
			  $fs = \wpSPIO()->filesystem();
				$fs->flushImageCache(); // it's possible file just changed by external plugin.
        $mediaItem = $fs->getImage($id, 'media');

				if ($mediaItem === false)
				{
					 Log::addError('Handle Image Upload Hook triggered, by error in image :' . $id );
					 return $meta;
				}

				if ($mediaItem->getExtension()  == 'pdf')
				{
					$settings = \wpSPIO()->settings();
					if (! $settings->optimizePdfs)
					{
						 Log::addDebug('Image Upload Hook detected PDF, which is turned off - not optimizing');
						 return $meta;
					}
				}

        $handleImage = apply_filters('shortpixel/media/uploadhook', true, $mediaItem, $meta, $id);

        // Short-circuit in certain cases if needed.
        if (false === $handleImage)
        {
           return $meta;
        }

        // Load compat stuff if ajax, just to be sure. When null is meta, this can be an integration
        if (wp_doing_ajax() || is_null($meta))
        {
          $this->loadCronCompat();
        }

				if ($mediaItem->isProcessable())
				{
					$converter = Converter::getConverter($mediaItem, true);

          // Convert only done by PNG atm, the rest is done via ImageModelToQueue.
          if (is_object($converter) && $converter->isConvertable())
					{
							$args = array('runReplacer' => false);

						 	$res = $converter->convert($args);
							$mediaItem = $fs->getImage($id, 'media', false);

							$meta = $converter->getUpdatedMeta();

              //do_action('shortpixel/converter/prevent-offload-off', $id);
					}

        	$control = new OptimizeController();
        	$control->addItemToQueue($mediaItem);
				}
				else {
					Log::addWarn('Passed mediaItem is not processable', $mediaItem);
				}
        return $meta; // It's a filter, otherwise no thumbs
    }


    /**
     * Prevent autohandling image for integrations, i.e. when external source wants to generate thumbnails or edit attachments
     * @param  integer $id            media id
     * @return null
     */
		public function preventImageHook($id)
		{
			  self::$preventUploadHook[] = $id;
		}

		// Placeholder function for heic and such, return placeholder URL in image to help w/ database replacements after conversion.
		public function checkPlaceHolder($url, $post_id)
		{

			$extension = pathinfo($url,  PATHINFO_EXTENSION);
			if (false === in_array($extension, ApiConverter::CONVERTABLE_EXTENSIONS))
			{
				 return $url;
			}

			$fs = \wpSPIO()->filesystem();
			$mediaImage = $fs->getImage($post_id, 'media');

			if (false === $mediaImage)
			{
				 return $url;
			}

			if (false === $mediaImage->getMeta()->convertMeta()->hasPlaceholder())
			{
				return $url;
			}

			$url = str_replace($extension, 'jpg', $url);

			return $url;
		}

    /* Function to process Hook coming from the WP cron system */
    public function processCronHook($bulk)
    {
        $args = array(
            'max_runs' => 10,
            'run_once' => false,
            'bulk' => $bulk,
            'source' => 'cron',
            'timelimit' => 50,
            'wait' => 1,
        );

        return $this->processQueueHook($args);
    }

		public function processQueueHook($args = array())
		{
				$defaults = array(
					'wait' => 3, // amount of time to wait for next round. Prevents high loads
					'run_once' => false, //  If true queue must be run at least every few minutes. If false, it tries to complete all.
					'queues' => array('media','custom'),
					'bulk' => false, // changing this might change important behavior
          'max_runs' => -1, // if < 0 run until end, otherwise cut out at some point.
          'source' => 'hook', // not used but can be used in the filter to see what type of job is running
          'timelimit' => false, //timelimit in seconds or false
				);

				if (wp_doing_cron())
				{
					 $this->loadCronCompat();
				}

				$args = wp_parse_args($args, $defaults);
        $args = apply_filters('shortpixel/process_hook/options', $args);


			  $control = new OptimizeController();
        $env = \wpSPIO()->env();

				if ($args['bulk'] === true)
				{
					 $control->setBulk(true);
				}

			 	if ($args['run_once'] === true)
				{
					 return	$control->processQueue($args['queues']);
				}

				$running = true;
				$i = 0;
        $max_runs = $args['max_runs'];
        $timelimit = $args['timelimit'];

				while($running)
				{
							 	$results = $control->processQueue($args['queues']);
								$running = false;

								foreach($args['queues'] as $qname)
								{
									  if (property_exists($results, $qname))
										{
											  $result = $results->$qname;
												// If Queue is not completely empty, there should be something to do.
												if ($result->qstatus != QUEUE::RESULT_QUEUE_EMPTY)
												{
													 $running = true;
													 continue;
												}
										}
								}

              $i++;
              if($max_runs > 0 && $i >= $max_runs)
              {
                 break;
              }
              if ($timelimit !== false && true === $env->IsOverTimeLimit(['limit' => $timelimit]))
              {
                 Log::addDebug('Hook: over timelimit detected, returning', $timelimit);
                 break;
              }
							sleep($args['wait']);
				}
		}

    public function scanCustomFoldersHook($args = array() )
    {
      $defaults = array(
        'force' => false,
        'wait' => 3,
        'amount' => -1,  // amount of directories to refresh.
        'interval' => 6 * HOUR_IN_SECONDS,
      );

      $args = wp_parse_args($args, $defaults);

      $otherMediaController = OtherMediaController::getInstance();

      $args = apply_filters('shortpixel/othermedia/scan_custom_folder', $args);

      $running = true;
      $i = 0;

      while (true === $running)
      {
        $result = $otherMediaController->doNextRefreshableFolder($args);
        if (false === $result) // stop on false return.
        {
           $running = false;
        }
        sleep($args['wait']);

        $i++;
        if ($args['amount'] > 0 && $i >= $args['amount'])
        {
           Log::addTemp($args['amount'] . ' lower than ' . $i . ' breaking');
           break;
        }

      }

    }

		// WP functions that are not loaded during Cron Time.
		protected function loadCronCompat()
		{
			  if (false === function_exists('download_url'))
				{
					 include_once(ABSPATH . "wp-admin/includes/admin.php");
				}

         if (false === function_exists('wp_generate_attachment_metadata'))
         {
           include_once(ABSPATH . 'wp-admin/includes/image.php' );
         }



		}

    /** Filter for Medialibrary items in list and grid view. Because grid uses ajax needs to be caught more general.
    * @handles pre_get_posts
    * @param WP_Query $query
    *
    * @return WP_Query
    */
    public function filter_listener($query)
    {
      global $pagenow;

      if ( empty( $query->query_vars["post_type"] ) || 'attachment' !== $query->query_vars["post_type"] ) {
        return $query;
      }

      if ( ! in_array( $pagenow, array( 'upload.php', 'admin-ajax.php' ) ) ) {
        return $query;
      }

      $filter = $this->selected_filter_value( 'shortpixel_status', 'all' );

      // No filter
      if ($filter == 'all')
      {
         return $query;
      }

//      add_filter( 'posts_join', array( $this, 'filter_join' ), 10, 2 );
  		add_filter( 'posts_where', array( $this, 'filter_add_where' ), 10, 2 );
//  		add_filter( 'posts_orderby', array( $this, 'query_add_orderby' ), 10, 2 );

      return $query;
    }

    public function filter_add_where ($where, $query)
    {
        global $wpdb;
        $filter = $this->selected_filter_value( 'shortpixel_status', 'all' );
        $tableName = UtilHelper::getPostMetaTable();

        switch($filter)
        {
             case 'all':

             break;
             case 'unoptimized':
              // The parent <> %d exclusion is meant to also deselect duplicate items ( translations ) since they don't have a status, but shouldn't be in a list like this.
                $sql = " AND " . $wpdb->posts . '.ID not in ( SELECT attach_id FROM ' . $tableName . " WHERE (parent = %d and status = %d) OR parent <> %d ) ";
  					    $where .= $wpdb->prepare($sql, MediaLibraryModel::IMAGE_TYPE_MAIN, ImageModel::FILE_STATUS_SUCCESS, MediaLibraryModel::IMAGE_TYPE_MAIN);
             break;
             case 'optimized':
                $sql = ' AND ' . $wpdb->posts . '.ID in ( SELECT attach_id FROM ' . $tableName . ' WHERE parent = %d and status = %d) ';
   					    $where .= $wpdb->prepare($sql, MediaLibraryModel::IMAGE_TYPE_MAIN, ImageModel::FILE_STATUS_SUCCESS);
             break;
             case 'prevented':

                $sql = sprintf('AND %s.ID in (SELECT post_id FROM %s WHERE meta_key = %%s)', $wpdb->posts, $wpdb->postmeta);

                $sql .= sprintf(' AND %s.ID not in ( SELECT attach_id FROM %s WHERE parent = 0 and status = %s)', $wpdb->posts, $tableName, ImageModel::FILE_STATUS_MARKED_DONE);

                $where = $wpdb->prepare($sql, '_shortpixel_prevent_optimize');
            break;
        }


        return $where;
    }


    /**
  	 * Safely retrieve the selected filter value from a dropdown.
  	 *
  	 * @param string $key
  	 * @param string $default
  	 *
  	 * @return string
  	 */
  	private function selected_filter_value( $key, $default ) {
  		if ( wp_doing_ajax() ) {
  			if ( isset( $_REQUEST['query'][ $key ] ) ) {
  				$value = sanitize_text_field( $_REQUEST['query'][ $key ] );
  			}
  		} else {
  			if ( ! isset( $_REQUEST['filter_action'] )  ) {
  				return $default;
  			}

  			if ( ! isset( $_REQUEST[ $key ] ) ) {
  				return $default;
  			}

  			$value = sanitize_text_field( $_REQUEST[ $key ] );
  		}

  		return ! empty( $value ) ? $value : $default;
  	}

    /**
		* When replacing happens.
    * @hook wp_handle_replace
		* @integration Enable Media Replace
    */
    public function handleReplaceHook($params)
    {
      if(isset($params['post_id'])) { //integration with EnableMediaReplace - that's an upload for replacing an existing ID

          $post_id = intval($params['post_id']);
          $fs = \wpSPIO()->filesystem();

          $imageObj = $fs->getImage($post_id, 'media');
          // In case entry is corrupted data, this might fail.
          if (is_object($imageObj))
          {
            $imageObj->onDelete();
          }
      }

    }

		/** This function is bound to enable-media-replace hook and fire when a file was replaced
		*
		*
		*/
		public function handleReplaceEnqueue($target, $source, $post_id)
		{
				// Delegate this to the hook, so all checks are done there.
				$this->handleImageUploadHook(array(), $post_id);

		}

    public function generatePluginLinks($links) {
        $in = '<a href="options-general.php?page=wp-shortpixel-settings">Settings</a>';
        array_unshift($links, $in);
        return $links;
    }

    /** Allow certain mime-types if we will be using those.
    *
    */
    public function addMimes($mimes)
    {
        $settings = \wpSPIO()->settings();
        if ($settings->createWebp)
        {
            if (! isset($mimes['webp']))
              $mimes['webp'] = 'image/webp';
        }
        if ($settings->createAvif)
        {
            if (! isset($mimes['avif']))
              $mimes['avif'] = 'image/avif';
        }

				if (! isset($mimes['heic']))
				{
					$mimes['heic'] = 'image/heic';
				}

				if (! isset($mimes['heif']))
				{
					$mimes['heif'] = 'image/heif';
				}

        return $mimes;
    }

		/** Media library gallery view, attempt to add fields that looks like the SPIO status */
		public function editAttachmentScreen($fields, $post)
		{
      return;
				// Prevent this thing running on edit media screen. The media library grid is before the screen is set, so just check if we are not on the attachment window.
				$screen_id = \wpSPIO()->env()->screen_id;
				if ($screen_id == 'attachment')
				{
					return $fields;
				}

				$fields["shortpixel-image-optimiser"] = array(
							"label" => esc_html__("ShortPixel", "shortpixel-image-optimiser"),
							"input" => "html",
							"html" => '<div id="sp-msg-' . $post->ID . '">--</div>',
						);

				return $fields;
		}

		public function printComparer()
		{

				$screen_id = \wpSPIO()->env()->screen_id;
				if ($screen_id !== 'upload')
				{
					return false;
				}

				$view = \ShortPixel\Controller\View\ListMediaViewController::getInstance();
				$view->loadComparer();
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
					//Log::addDebug('OnDelete ImageObj', $imageObj);
          if ($imageObj !== false)
            $result = $imageObj->onDelete();
        }
        catch(\Exception $e)
        {
          Log::addError('OndeleteImage triggered an error. ' . $e->getMessage(), $e);
        }
        return $result;
    }



    /** Displays an icon in the toolbar when processing images
    *   hook - admin_bar_menu
    *  @param Obj $wp_admin_bar
    */
    public function toolbar_shortpixel_processing( $wp_admin_bar ) {

        if (! \wpSPIO()->env()->is_screen_to_use )
          return; // not ours, don't load JS and such.

        $settings = \wpSPIO()->settings();
        $access = AccessModel::getInstance();
				$quotaController = QuotaController::getInstance();

        $extraClasses = " shortpixel-hide";
        /*translators: toolbar icon tooltip*/
        $id = 'short-pixel-notice-toolbar';
        $tooltip = __('ShortPixel optimizing...','shortpixel-image-optimiser');
        $icon = "shortpixel.png";
        $successLink = $link = admin_url(current_user_can( 'edit_others_posts')? 'upload.php?page=wp-short-pixel-bulk' : 'upload.php');
        $blank = "";

        if($quotaController->hasQuota() === false)
				{
            $extraClasses = " shortpixel-alert shortpixel-quota-exceeded";
            /*translators: toolbar icon tooltip*/
            $id = 'short-pixel-notice-exceed';
            $tooltip = '';

            if ($access->userIsAllowed('quota-warning'))
            {
              $exceedTooltip = __('ShortPixel quota exceeded. Click for details.','shortpixel-image-optimiser');
              //$link = "http://shortpixel.com/login/" . $this->_settings->apiKey;
              $link = "options-general.php?page=wp-shortpixel-settings";
            }
            else {
              $exceedTooltip = __('ShortPixel quota exceeded. Click for details.','shortpixel-image-optimiser');
              //$link = "http://shortpixel.com/login/" . $this->_settings->apiKey;
              $link = false;
            }
        }

        $args = array(
                'id'    => 'shortpixel_processing',
                'title' => '<div id="' . $id . '" title="' . $tooltip . '"><span class="stats hidden">0</span><img alt="' . __('ShortPixel icon','shortpixel-image-optimiser') . '" src="'
                         . plugins_url( 'res/img/'.$icon, SHORTPIXEL_PLUGIN_FILE ) . '" success-url="' . $successLink . '"><span class="shp-alert">!</span>'
                         . '<div class="controls">
                              <span class="dashicons dashicons-controls-pause pause" title="' . __('Pause', 'shortpixel-image-optimiser') . '">&nbsp;</span>
                              <span class="dashicons dashicons-controls-play play" title="' . __('Resume', 'shortpixel-image-optimiser') . '">&nbsp;</span>
                            </div>'

                         .'<div class="cssload-container"><div class="cssload-speeding-wheel"></div></div></div>',
    //            'href'  => 'javascript:void(0)', // $link,
                'meta'  => array('target'=> $blank, 'class' => 'shortpixel-toolbar-processing' . $extraClasses)
        );
        $wp_admin_bar->add_node( $args );

        if($quotaController->hasQuota() === false)
				{
            $wp_admin_bar->add_node( array(
                'id'    => 'shortpixel_processing-title',
                'parent' => 'shortpixel_processing',
                'title' => $exceedTooltip,
                'href'  => $link
            ));

        }
    }

} // class
