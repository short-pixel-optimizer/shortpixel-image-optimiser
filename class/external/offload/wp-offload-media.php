<?php
namespace ShortPixel\External\Offload;

use ShortPixel\Model\File\FileModel as FileModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use ShortPixel\Controller\QuotaController as QuotaController;
use ShortPixel\Controller\ResponseController as ResponseController;

// @integration WP Offload Media Lite
class wpOffload
{
    protected $as3cf;
    protected $active = false;
    protected $offloading = true;

    private $itemClassName;
		private $useHandlers =  false; // Check for newer ItemHandlers or Compat mode.
		protected $shouldPrevent = true; // if offload should be prevented. This is turned off when SPIO want to tell S3 to offload. Better than removing filter.

    protected $settings;

    protected $is_cname = false;
    protected $cname;

		private static $sources; // cache for url > source_id lookup, to prevent duplicate queries.

		private static $offloadPrevented = array();

    // if might have to do these checks many times for each thumbnails, keep it fastish.
    //protected $retrievedCache = array();

    public function __construct($as3cf)
    {
       // This must be called before WordPress' init.
		 	  $this->init($as3cf);
    }

    public function init($as3cf)
    {

      if (! class_exists('\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item'))
      {
        Notice::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'shortpixel-image-optimiser'), true);
				return false;
      }

      $this->itemClassName = '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item';

			if (method_exists($as3cf, 'get_item_handler'))
			{
				 $this->useHandlers = true; // we have a new version
			}
			else {
				Notice::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'shortpixel-image-optimiser'), true);
				return false;
			}

      $this->as3cf = $as3cf;
      $this->active = true;

      // if setting to upload to bucket is off, don't hook or do anything really.
      if (! $this->as3cf->get_setting( 'copy-to-s3' ))
      {
        $this->offloading = false;
      }

    /*	// Lets see if this can be without
			if ('cloudfront' === $this->as3cf->get_setting( 'domain' ))
      {
        $this->is_cname = true;
        $this->cname = $this->as3cf->get_setting( 'cloudfront' );
      } */

  //    $provider = $this->as3cf->get_provider();
      add_action('shortpixel/image/optimised', array($this, 'image_upload'), 10);
      add_action('shortpixel/image/after_restore', array($this, 'image_restore'), 10, 3); // hit this when restoring.
			add_action('shortpixel-thumbnails-before-regenerate', array($this, 'remove_remote'), 10);
			add_action('shortpixel/converter/prevent-offload', array($this, 'preventOffload'), 10);
			add_action('shortpixel/converter/prevent-offload-off', array($this, 'preventOffloadOff'), 10);

     // add_action('shortpixel_restore_after_pathget', array($this, 'remove_remote')); // not optimal -> has to do w/ doRestore and when URL/PATH is available when not on server .

      // Seems this better served by _after? If it fails, it's removed from remote w/o filechange.
    //  add_action('shortpixel/image/convertpng2jpg_before', array($this, 'remove_remote'));
      add_filter('as3cf_attachment_file_paths', array($this, 'add_webp_paths'));


			//	add_filter('as3cf_remove_source_files_from_provider', array($this, 'remove_webp_paths'), 10);
	//		add_action('shortpixel/image/convertpng2jpg_success', array($this, 'image_converted'), 10);
				add_filter('as3cf_remove_source_files_from_provider', array($this, 'remove_webp_paths'));


    //  add_filter('shortpixel/restore/targetfile', array($this, 'returnOriginalFile'),10,2);
     add_filter('as3cf_pre_update_attachment_metadata', array($this, 'preventUpdateMetaData'), 10,4);
		 add_filter('as3cf_pre_handle_item_upload', array($this, 'preventInitialUploadHandler'), 10,3);

		 //add_filter('as3cf_get_attached_file', array($this, 'fixScaledUrl'), 10, 4);
		 add_filter('shortpixel_get_original_image_path', array($this, 'checkScaledUrl'), 10,2);
		// add_filter('as3cf_get_attached_file_noop', array($this, 'fixScaledUrl'), 10,4);

      //add_filter('shortpixel_get_attached_file', array($this, 'get_raw_attached_file'),10, 2);
    //  add_filter('shortpixel_get_original_image_path', array($this, 'get_raw_original_path'), 10, 2);
      add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,2);
      add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));

      // for webp picture paths rendered via output
     // add_filter('shortpixel_webp_image_base', array($this, 'checkWebpRemotePath'), 10, 2);
      add_filter('shortpixel/front/webp_notfound', array($this, 'fixWebpRemotePath'), 10, 4);

			// Fix for updating source paths when converting
			add_action('shortpixel/image/convertpng2jpg_success', array($this, 'updateOriginalPath'));
    }

    public function returnOriginalFile($file, $attach_id)
    {
      $file = get_attached_file($attach_id, true);
      return $file;
    }

		private function getMediaClass()
		{
			if ($this->useHandlers)
			{
				$class = $this->as3cf->get_source_type_class('media-library');
			}
			else
			{
				$class = $this->itemClassName; //backward compat.
			}

			return $class;
		}

		// This is used in the converted. Might be deployed elsewhere for better control.
		public function preventOffload($attach_id)
		{
			 self::$offloadPrevented[$attach_id] = true;
		}

		public function preventOffloadOff($attach_id)
		{
			  unset(self::$offloadPrevented[$attach_id]);
		}

		// When Offload is not offloaded but is created during the process of generate metadata in WP, wp_create_image_subsizes fires an update metadata after just moving the upload, before making any thumbnails.  If this is the case and the file has an -scaled / original image setup, the original_source_path becomes the same as the source_path which creates issue later on when dealing with optimizing it, if the file is deleted on local server.  Prevent this, and lean on later update metadata.
		public function preventUpdateMetaData($bool, $data, $post_id, $old_provider_object)
		{
			if (isset(self::$offloadPrevented[$post_id]))
			{
					return true ; // return true to cancel.
			}

			return $bool;

		}

    /**
    * @param $id attachment id (WP)
    * @param $mediaItem  MediaLibraryModel SPIO
    * @param $clean - boolean - if restore did all files (clean) or partial (not clean)
    */
    public function image_restore($mediaItem, $id, $clean)
    {
      $settings = \wpSPIO()->settings();

			// Only medialibrary offloading supported.
			if ('media' !== $mediaItem->get('type') )
			{
				 return false;
			}

      // If there are excluded sizes, there are not in backups. might not be left on remote, or ( if delete ) on server, so just generate the images and move them.
      $mediaItem->wpCreateImageSizes();

      $result = $this->remove_remote($id);
      $this->image_upload($mediaItem);
    }

    public function remove_remote($id)
    {
      $a3cfItem = $this->getItemById($id); // MediaItem is AS3CF Object
      if ($a3cfItem === false)
      {
        Log::addDebug('S3-Offload MediaItem not remote - ' . $id);
        return false;
      }

				$remove = \DeliciousBrains\WP_Offload_Media\Items\Remove_Provider_Handler::get_item_handler_key_name();
				$itemHandler = $this->as3cf->get_item_handler($remove);

				$result = $itemHandler->handle($a3cfItem, array( 'verify_exists_on_local' => false)); //handle it then.
				return $result;
    }


    /** @return Returns S3Ofload MediaItem, or false when this does not exist */
    protected function getItemById($id, $create = false)
    {
				$class = $this->getMediaClass();
			  $mediaItem = $class::get_by_source_id($id);

				if (true === $create && $mediaItem === false)
				{
					 $mediaItem = $class::create_from_source_id($id);
				}

        return $mediaItem;
    }

		/** Cache source requests to improve performance
		* @param $url string  The URL that is being checked
		* @param $source_id int  Source ID of the item URL to be cached
		* @return int|boolean|null  Returns source_if or false ( not offloaded ) if found, returns null if not sourcecached.
		*/
		private function sourceCache($url, $source_id = null)
		{
			if ($source_id === null && isset(static::$sources[$url]))
			{
				$source_id = static::$sources[$url];
				return $source_id;
			}
			elseif ($source_id !== null)
			{
				 if (! isset(static::$sources[$url]))
				 {
					  static::$sources[$url]  = $source_id;
				 }

				 return $source_id;
			}

			return null;
		}

    public function checkIfOffloaded($bool, $url)
    {

			$source_id = $this->sourceCache($url);
			$orig_url = $url;

			if (is_null($source_id))
			{
				$extension = substr($url, strrpos($url, '.') + 1);

				// If these filetypes are not in the cache, they cannot be found via geSourceyIDByUrl method ( not in path DB ), so it's pointless to try. If they are offloaded, at some point the extra-info might load.
				if ($extension == 'webp' || $extension == 'avif')
				{
					return false;
				}

     		$source_id = $this->getSourceIDByURL($url);

			}
			else {
			}

      if ($source_id !== false)
			{
        return FileModel::$VIRTUAL_REMOTE;
			}
      else
			{
        return false;
			}
    }

    protected function getSourceIDByURL($url)
    {
			$source_id = $this->sourceCache($url); // check cache first.
			$cacheHit = false; // prevent a cache hit to be cached again.
			$raw_url = $url; // keep raw. If resolved, add the raw url to the cache.

			// If in cache, we are done.
			if (! is_null($source_id))
			{
				return $source_id;
			}

			if (is_null($source_id)) // check on the raw url.
			{
      	$class = $this->getMediaClass();

				$parsedUrl = parse_url($url);

				if (! isset($parsedUrl['scheme']) || ! in_array($parsedUrl['scheme'], array('http','https')))
				{
					 $url = 'http://' . $url; //str_replace($parsedUrl['scheme'], 'https', $url);
				}

				$source_id = $this->sourceCache($url);

				if(is_null($source_id))
				{
      		$source = $class::get_item_source_by_remote_url($url);
					$source2 = $class::get_item_source_by_remote_url($raw_url);

					$source_id = isset($source['id']) ? intval($source['id']) : null;
				}
				else {
					$cacheHit = true; // hit the cache. Yeah.
					$this->sourceCache($raw_url, $source_id);
				}
			}


			if (is_null($source_id)) // check now via the thumbnail hocus.
			{
				$pattern = '/(.*)-\d+[xX]\d+(\.\w+)/m';
				$url = preg_replace($pattern, '$1$2', $url);

				$source_id = $this->sourceCache($url); // check cache first.

				if (is_null($source_id))
				{
					$source = $class::get_item_source_by_remote_url($url);
					$source_id = isset($source['id']) ? intval($source['id']) : null;
				}
				else {
					$cacheHit = true;
					$this->sourceCache($raw_url , $source_id);
				}

      }

			// Check issue with double extensions. If say double webp/avif is on, the double extension causes the URL not to be found (ie .jpg)
			if (is_null($source_id))
			{
				 if (substr_count($parsedUrl['path'], '.') > 1)
				 {
					  // Get extension
						$ext = substr(strrchr($url, '.'), 1);

						// Remove all extensions from the URL
					  $checkurl = substr($url, 0, strpos($url,'.')) ;

						// Add back the last one.
						$checkurl .= '.' . $ext;

						// Retry
						$source_id = $this->sourceCache($checkurl); // check cache first.

						if (is_null($source_id))
						{
							$source = $class::get_item_source_by_remote_url($url);
							$source_id = isset($source['id']) ? intval($source['id']) : null;
						}
						else {
							$cacheHit = true;
							$this->sourceCache($raw_url , $source_id);
						}

				 }
			}

			if(is_null($source_id))
			{
				 $source_id = false;
			}

			if (false === $cacheHit)
			{
				$this->sourceCache($url, $source_id);  // cache it.
			}

			if ($source_id !== false && false === $cacheHit)
			{

				// get item
				$item = $this->getItemById($source_id);
				if (is_object($item) && method_exists($item, 'extra_info'))
				{
					$baseUrl = str_replace(basename($url),'', $url);
					//$rawBaseUrl =
					$extra_info = $item->extra_info();

					if (isset($extra_info['objects']))
					{
						foreach($extra_info['objects'] as $extraItem)
						{
							 if (is_array($extraItem) && isset($extraItem['source_file']))
							 {
								 // Add source stuff into cache.
								  $this->sourceCache($baseUrl . $extraItem['source_file'], $source_id);
							 }
						}
					}
				}
			}

      return $source_id;
    }

		// @param s3 based URL that which is needed for finding local path
		// @return String Filepath.  Translated file path
    public function getLocalPathByURL($url)
    {
       $source_id = $this->getSourceIDByURL($url);

       if ($source_id === false)
       {
        return false;
      }

       $item = $this->getItemById($source_id);

       $original_path = $item->original_source_path(); // $values['original_source_path'];

       if (wp_basename($url) !== wp_basename($original_path)) // thumbnails translate to main file.
       {
          $original_path = str_replace(wp_basename($original_path), wp_basename($url), $original_path);
       }

       $fs = \wpSPIO()->filesystem();
       $base = $fs->getWPUploadBase();

       $file  = $base . $original_path;
       return $file;
    }


		/** Converted after png2jpg
		*
		*  @param MediaItem Object SPIO
		*/
    public function image_converted($mediaItem)
    {
        $fs = \wpSPIO()->fileSystem();

				$id = $mediaItem->get('id');
				//$this->remove_remote($id);
				$this->image_upload($mediaItem);

    }

    public function image_upload($mediaLibraryObject)
    {
				$id = $mediaLibraryObject->get('id');
				$a3cfItem = $this->getItemById($id);

				// Only medialibrary offloading supported.
				if ('media' !== $mediaLibraryObject->get('type') )
				{
					 return false;
				}

				if ( false === $a3cfItem)
				{
					 return false;
				}

        $item = $this->getItemById($id, true);

        if ( $item === false && ! $this->as3cf->get_setting( 'copy-to-s3' ) ) {
          // abort if not already uploaded to provider and the copy setting is off
          Log::addDebug('As3cf image upload is off and object not previously uploaded');
          return false;
        }

 					// Add Web/Avifs back under new method.
					$this->shouldPrevent = false;

					// The Handler doesn't work properly /w local removal if not the exact correct files are passed (?) . Offload does this probably via update metadata function, so let them sort it out with this . (until it breaks)
					$meta = wp_get_attachment_metadata($id);
					wp_update_attachment_metadata($id, $meta);

					$this->shouldPrevent = true;
    }


		// WP Offload -for some reason - returns the same result of get_attached_file and wp_get_original_image_path , which are different files (one scaled) which then causes a wrong copy action after optimizing the image ( wrong destination download of the remote file ).   This happens if offload with delete is on.  Attempt to fix the URL to reflect the differences between -scaled and not.
		public function checkScaledUrl($filepath, $id)
		{
				// Original filepath can never have a scaled in there.
				// @todo This should probably check -scaled.<extension> as string end preventing issues.
				if (strpos($filepath, '-scaled') !== false)
				{
					$filepath = str_replace('-scaled', '', $filepath);
				}
			 return $filepath;
		}

 		/** This function will cut out the initial upload to S3Offload . This cuts it off in the new handle area, leaving other updating in tact.
		*/
		public function preventInitialUploadHandler($bool, $as3cf_item, $options)
		{

				$fs = \wpSPIO()->filesystem();
				$settings = \WPSPIO()->settings();

				$post_id = $as3cf_item->source_id();

				$quotaController = quotaController::getInstance();
				if ($quotaController->hasQuota() === false)
				{
					return false;
				}

				if (! $this->offloading)
				{
					return false;
				}

				if ($this->shouldPrevent === false) // if false is returned, it's NOT prevented, so on-going.
				{
						return false;
				}

				if (isset(self::$offloadPrevented[$post_id]))
				{
					Log::addDebug('Offload Prevented via static for '. $post_id);
					$error = new \WP_Error( 'upload-prevented', 'No offloading at this time, thanks' );
					return $error;
				}

				Log::addDebug('Not preventing S3 Offload');
				return $bool;
		}

		public function updateOriginalPath($imageModel)
		{
				$post_id = $imageModel->get('id');

				$item = $this->getItemById($post_id);

				if (false === $item) // item not offloaded.
				{
					 return false;
				}

				$original_path = $item->original_path(); // Original path (non-scaled-)
				$original_source_path = $item->original_source_path();
				$path = $item->path();
				$source_path = $item->source_path();

				$wp_original = wp_get_original_image_path($post_id, apply_filters( 'emr_unfiltered_get_attached_file', true ));
				$wp_original = apply_filters('emr/replace/original_image_path', $wp_original, $post_id);
				$wp_source = trim(get_attached_file($post_id, apply_filters( 'emr_unfiltered_get_attached_file', true )));

				$updated = false;


				// If image is replaced with another name, the original soruce path will not match.  This could also happen when an image is with -scaled as main is replaced by an image that doesn't have it.  In all cases update the table to reflect proper changes.
				if (wp_basename($wp_original) !== wp_basename($original_path))
				{

					 $newpath = str_replace( wp_basename( $original_path ), wp_basename($wp_original), $original_path );

					 $item->set_original_path($newpath);

					 $newpath = str_replace( wp_basename( $original_source_path ), wp_basename($wp_original), $original_source_path );
					 $updated = true;

					 $item->set_original_source_path($newpath);

					 $item->save();
				}
		}

    private function getWebpPaths($paths, $check_exists = true)
    {
      $newPaths = array();
      $fs = \wpSPIO()->fileSystem();

      foreach($paths as $size => $path)
      {
         $file = $fs->getFile($path);

				 $basedir = $file->getFileDir();

				 if (is_null($basedir)) // This could only happen if path is completely empty.
				 {
					  continue;
				 }

         $basepath = $basedir->getPath();

         $newPaths[$size] = $path;

         $webpformat1 = $basepath . $file->getFileName() . '.webp';
         $webpformat2 = $basepath . $file->getFileBase() . '.webp';

         $avifformat =  $basepath . $file->getFileName() . '.avif';
				 $avifformat2 = $basepath . $file->getFileBase() . '.avif';


         if ($check_exists)
         {
           if (file_exists($webpformat1))
            $newPaths[$size . '_webp'] =  $webpformat1;
         }
         else {
           $newPaths[$size . '_webp'] =  $webpformat1;
         }

         if ($check_exists)
         {
           if(file_exists($webpformat2))
            $newPaths[$size . '_webp2'] =  $webpformat2;
         }
         else {
           $newPaths[$size . '_webp2'] =  $webpformat2;
         }

         if ($check_exists)
         {
            if (file_exists($avifformat))
            {
               $newPaths[$size . '_avif'] = $avifformat;
            }
         }
				 else {
				 	 $newPaths[$size . '_avif'] = $avifformat;
				 }

				 if ($check_exists)
				 {
						if (file_exists($avifformat2))
						{
							 $newPaths[$size . '_avif2'] = $avifformat2;
						}
				 }
				else {
					$newPaths[$size . '_avif2'] = $avifformat2;
				}
      }

      return $newPaths;
    }

    /**  Get Webp Paths that might be generated and offload them as well.
    * Paths - size : path values
    */
    public function add_webp_paths($paths)
    {
        $paths = $this->getWebpPaths($paths, true);
				 //Log::addDebug('Add S3 Paths - ', array($paths));
        return $paths;
    }


    public function remove_webp_paths($paths)
    {
      $paths = $this->getWebpPaths($paths, false);
      //Log::addDebug('Remove S3 Paths', array($paths));

      return $paths;
    }

    // GetbyURL can't find thumbnails, only the main image. Check via extrainfo method if we can find needed filetype
		// @param $bool Boolean
		// @param $fileObj FileModel  The webp file we are searching for
		// @param $url  string  The URL of the main file ( aka .jpg )
		// @param $imagebaseDir DirectoryModel  The remote path / path this all takes place at.
    public function fixWebpRemotePath($bool, $fileObj, $url, $imagebaseDir)
    {
			 $source_id = $this->getSourceIDByURL($url);
			 if (false === $source_id)
			 		return false;

			 $item = $this->getItemById($source_id);
			 $extra_info = $item->extra_info();

			 if (! isset( $extra_info['objects'] ) || ! is_array( $extra_info['objects'] ) )
			 	return false;

			 $bool = false;

			 foreach($extra_info['objects'] as $data)
			 {
				   $sourceFile = $data['source_file'];
					 if ($sourceFile == $fileObj->getFileName())
					 {
						  $bool = true;
							return $fileObj;
							break;
					 }
			 }

			 return $bool;

    }

}
