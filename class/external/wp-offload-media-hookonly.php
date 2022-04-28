<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

// @integration WP Offload Media Lite
class wpOffload
{
    protected $as3cf;
    protected $active = false;
    protected $offloading = true;
		protected $shouldPrevent = true; // if offload should be prevented. This is turned off when SPIO want to tell S3 to offload. Better than removing filter.

    private $itemClassName;

    protected $settings;

    protected $is_cname = false;
    protected $cname;

    // if might have to do these checks many times for each thumbnails, keep it fastish.
    //protected $retrievedCache = array();

    public function __construct()
    {
       // This must be called before WordPress' init.
       add_action('as3cf_init', array($this, 'init'));
    }

    public function init($as3cf)
    {
      if (! class_exists('\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item'))
      {
        Notice::addWarning(__('Your S3-Offload plugin version doesn\'t seem to be compatible. Please upgrade the S3-Offload plugin', 'shortpixel-image-optimiser'), true);
      }
      else {
        $this->itemClassName = '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item';
      }

      $this->as3cf = $as3cf;
      $this->active = true;

      // if setting to upload to bucket is off, don't hook or do anything really.
      if (! $this->as3cf->get_setting( 'copy-to-s3' ))
      {
        $this->offloading = false;
      }

      if ('cloudfront' === $this->as3cf->get_setting( 'domain' ))
      {
        $this->is_cname = true;
        $this->cname = $this->as3cf->get_setting( 'cloudfront' );
      }

  //    $provider = $this->as3cf->get_provider();

      add_action('shortpixel_image_optimised', array($this, 'image_upload'));
      add_action('shortpixel_after_restore_image', array($this, 'image_restore'), 10, 3); // hit this when restoring.

			// @todo This hook does a lot of deep stuff in the S3offload database. Check if we can live without that now.  Problem might be with the changing extensions
      //add_action('shortpixel/image/convertpng2jpg_after', array($this, 'image_converted'));

			// Seems not in use.
		//  add_action('shortpixel_restore_after_pathget', array($this, 'remove_remote')); // not optimal -> has to do w/ doRestore and when URL/PATH is available when not on server .

      // Seems this better served by _after? If it fails, it's removed from remote w/o filechange.
    //  add_action('shortpixel/image/convertpng2jpg_before', array($this, 'remove_remote'));
      add_filter('as3cf_attachment_file_paths', array($this, 'add_webp_paths'));
      add_filter('as3cf_remove_attachment_paths', array($this, 'remove_webp_paths'));

			// Seems not in use(?)
      //add_filter('shortpixel/restore/targetfile', array($this, 'returnOriginalFile'),10,2);

      add_filter('as3cf_pre_update_attachment_metadata', array($this, 'preventInitialUpload'), 10,4);

    //  add_filter('shortpixel_get_attached_file', array($this, 'get_raw_attached_file'),10, 2);
    //  add_filter('shortpixel_get_original_image_path', array($this, 'get_raw_original_path'), 10, 2);

			// @Filemodel.php - UrlToPath function . This is absolutely needed to check if file exists, in a way we can communicate.
      add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,2);

			// FileModel.php ( getBackupDirectory ) / Imagemodel.php ( restore ) - Needed to project the real path to copy to on restore / optimization
      add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));

      // for webp picture paths rendered via output
      add_filter('shortpixel_webp_image_base', array($this, 'checkWebpRemotePath'), 10, 2);
      add_filter('shortpixel/front/webp_notfound', array($this, 'fixWebpRemotePath'), 10, 4);

			// Debug hook
			add_filter('as3cf_remove_source_files_from_provider', function ($paths){  return $paths;
			});

    }


    public function returnOriginalFile($file, $attach_id)
    {
      $file = get_attached_file($attach_id, true);
      return $file;
    }

		private function getMediaClass()
		{
			if (method_exists($this->as3cf, 'get_source_type_class'))
			{
				$class = $this->as3cf->get_source_type_class('media-library');
			}
			else
			{
				$class = $this->itemClassName; //backward compat.
			}

			return $class;
		}

    /**
    * @param $id attachment id (WP)
    * @param $mediaItem  MediaLibraryModel SPIO
    * @param $clean - boolean - if restore did all files (clean) or partial (not clean)
    */
    public function image_restore($id, $mediaItem, $clean)
    {
      if (! $clean)
        return false; // don't do anything until we have restored all ( for now )

      $settings = \wpSPIO()->settings();

      // If there are excluded sizes, there are not in backups. might not be left on remote, or ( if delete ) on server, so just generate the images and move them.
      $mediaItem->wpCreateImageSizes();

      //$this->remove_remote($id);
			$post = get_post($id);
			do_action('delete_attachment', $id, $post);

      $this->image_upload($id);
    }

    /** @return Returns S3Ofload MediaItem, or false when this does not exist */
    protected function getItemById($id)
    {
				$class = $this->getMediaClass();
				if (! method_exists($class, 'create_from_source_id'))
        {
        	$mediaItem = $class::get_by_source_id($id);
				}
				else {
					 $mediaItem = $class::create_from_source_id($id);
				}
        return $mediaItem;
    }

    public function checkIfOffloaded($bool, $url)
    {
      $source_id = $this->getSourceIDByURL($url);

      if ($source_id !== false)
        return true;
      else
        return false;
    }

// @todo This needs some backward compat.
    protected function getSourceIDByURL($url)
    {
      $class = $this->getMediaClass();
      $source = $class::get_item_source_by_remote_url($url);

			/// Function can return false
			if ($source === false)
				return false;

			$source_id = isset($source['id']) ? intval($source['id']) : false;

      if ($source_id !== false)
        return $source_id;
      else
      {
        $source_id = $this->checkIfThumbnail($url); // can be item or false.
        return $source_id;
      }

      return false;
    }

    //** The thumbnails are not recorded by offload, but still offloaded.
    private function checkIfThumbnail($original_url)
    {
        //$result = \attachment_url_to_postid($url);
        $pattern = '/(.*)-\d+[xX]\d+(\.\w+)/m';
        $url = preg_replace($pattern, '$1$2', $original_url);

        $class = $this->getMediaClass();
        $source = $class::get_item_source_by_remote_url($url);

				$source_id = isset($source['id']) ? intval($source['id']) : false;

        if ($source_id !== false)
          return $source_id;
        else
          return false;

    }

    public function getLocalPathByURL($url)
    {
       $source_id = $this->getSourceIDByURL($url);
       if ($source_id == false)
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

       $file  = $fs->getFile($base . $original_path);
       return $file;
    }



    public function image_upload($id)
    {
        if (! $this->offloading)
          return false;

        $item = $this->getItemById($id);

        /* This is not needed, since offloading is connected to copy-to-s3 setting.
				if ( $item === false && ! $this->as3cf->get_setting( 'copy-to-s3' ) ) {
          // abort if not already uploaded to provider and the copy setting is off
          Log::addDebug('As3cf image upload is off and object not previously uploaded');
          return false;
        } */

        Log::addDebug('Offloading Attachment - ' . $id);
        $mediaItem = $this->getItemById($id);  // A3cf MediaItem.


				// Get attachemnt data, just hard call the filter.
				$this->shouldPrevent = false;
				$data = wp_get_attachment_metadata($id);
				$data = apply_filters('wp_update_attachment_metadata', $data, $id);
				$this->shouldPrevent = true;


				return true;
        // This is old version as3cf
        if (method_exists($this->as3cf, 'upload_attachment'))
        {
          $this->as3cf->upload_attachment($id);
        }
        else {
          // This should load the A3cf UploadHandler
          $itemHandler = $this->as3cf->get_item_handler('upload');
         $result = $itemHandler->handle($mediaItem); //handle it then.
        }
    }

    /** This function will cut out the initial upload to S3Offload and rely solely on the image_upload function provided here, after shortpixel optimize.
    * Function will only work when plugin is set to auto-optimize new entries to the media library
    * Since S3-Offload 2.3 this will be called on every thumbnail ( changes in WP 5.3 )
    */
    public function preventInitialUpload($bool, $data, $post_id, $old_provider_object)
    {
        $fs = \wpSPIO()->filesystem();

        if (! $this->offloading)
          return false;

				if ($this->shouldPrevent === false)
					return false;

        if (\wpSPIO()->env()->is_autoprocess)
        {
          // Don't prevent whaffever if shortpixel is already done. This can be caused by plugins doing a metadata update, we don't care then.
          $mediaItem = $fs->getImage($post_id, 'media');
          if ($mediaItem && ! $mediaItem->isOptimized())
          {

						$image_file = $mediaItem->getFileName();
						if (strpos($image_file, '.pdf') !== false && ! $settings->optimizePdfs  )
						{
							 Log::addDebug('S3 Prevent Initial Upload detected PDF, which will not be optimized', $post_id);
							 return false;
						}

            Log::addDebug('Preventing Initial Upload', $post_id);
            return true;
          }
        }
        return $bool;
    }

		// Doubling for avif as well atm.
    private function getWebpPaths($paths, $check_exists = true)
    {
      $newPaths = array();
      $fs = \wpSPIO()->fileSystem();

      foreach($paths as $size => $path)
      {
         $file = $fs->getFile($path);
         $basepath = $file->getFileDir()->getPath();
         $newPaths[$size] = $path;

         $webpformat1 = $basepath . $file->getFileName() . '.webp';
         $webpformat2 = $basepath . $file->getFileBase() . '.webp';

         $avifformat =  $basepath . $file->getFileBase() . '.avif';


         if ($check_exists)
         {
           if (file_exists($webpformat1))
            $newPaths[$size . '_webp'] =  $webpformat1;
         }
         else {
           $newPaths[$size . '_webp1'] =  $webpformat1;
         }

         if ($check_exists)
         {
           if(file_exists($webpformat2))
            $newPaths[$size . '_webp'] =  $webpformat2;
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

      }

      return $newPaths;
    }

    /**  Get Webp Paths that might be generated and offload them as well.
    * Paths - size : path values
    */
    public function add_webp_paths($paths)
    {
      //  Log::addDebug('Received Paths', array($paths));
        $paths = $this->getWebpPaths($paths, true);
  //      Log::addDebug('Webp Path Founder (S3)', array($paths));
        return $paths;
    }

    public function remove_webp_paths($paths)
    {

      $paths = $this->getWebpPaths($paths, false);
    //  Log::addDebug('Remove S3 Paths', array($paths));

      return $paths;
    }

    public function checkWebpRemotePath($url, $original)
    {
      if ($url === false)
      {
        return $this->convertWebPRemotePath($url, $original);
        //  return $url;
      }
      elseif($this->is_cname) // check this. the webp to picture will convert subdomains with CNAME to some local path when offloaded.
      {
          Log::addDebug('S3 active, checking on CNAME for path' . $this->cname);
          if (strpos($original, $this->cname) !== false)
            return $this->convertWebPRemotePath($url, $original);
      }

      return $url;

    }

    private function convertWebPRemotePath($url, $original)
    {
      $mediaItem = $this->getByURL($original); // test if exists remote.
      Log::addDebug('ImageBaseName check for S3 - ', array($original, $mediaItem));

      if ($mediaItem === false)
      {
        $pattern = '/-\d+x\d*/i';
        $replaced_url = preg_replace($pattern, '', $original);
        $mediaItem = $this->getByURL($replaced_url);
      }

      if ($mediaItem === false)
      {
         return $url;
      }
      $parsed = parse_url($original);
      $url = str_replace($parsed['scheme'], '', $original);
      $url = str_replace(basename($url), '',  $url);
      Log::addDebug('New BasePath, External' . $url);

      return $url;
    }

    // GetbyURL can't find thumbnails, only the main image. We are going to assume, if imagebase is ok, the webp might be there.
    public function fixWebpRemotePath($bool, $file, $url, $imagebase)
    {
        if (strpos($url, $imagebase ) !== false)
          return $file;
        else
          return $bool;
    }

}

$wpOff = new wpOffload();
