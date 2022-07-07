<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use ShortPixel\Controller\QuotaController as QuotaController;

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

		private $sources; // cache for url > source_id lookup, to prevent duplicate queries.

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
				return false;
      }

      $this->itemClassName = '\DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item';

			if (method_exists($as3cf, 'get_item_handler'))
			{
				 $this->useHandlers = true; // we have a new version
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
      add_action('shortpixel/image/optimised', array($this, 'image_upload'), 10);
      add_action('shortpixel/image/after_restore', array($this, 'image_restore'), 10, 3); // hit this when restoring.

      add_action('shortpixel_restore_after_pathget', array($this, 'remove_remote')); // not optimal -> has to do w/ doRestore and when URL/PATH is available when not on server .

      // Seems this better served by _after? If it fails, it's removed from remote w/o filechange.
    //  add_action('shortpixel/image/convertpng2jpg_before', array($this, 'remove_remote'));
      add_filter('as3cf_attachment_file_paths', array($this, 'add_webp_paths'));

			if ($this->useHandlers)
			{
			//	add_filter('as3cf_remove_source_files_from_provider', array($this, 'remove_webp_paths'), 10);
				add_action('shortpixel/image/convertpng2jpg_success', array($this, 'image_converted'), 10);
			}
			else {
      	add_filter('as3cf_remove_attachment_paths', array($this, 'remove_webp_paths'));
				add_action('shortpixel/image/convertpng2jpg_after', array($this, 'image_converted_legacy'), 10, 2);
			}

      add_filter('shortpixel/restore/targetfile', array($this, 'returnOriginalFile'),10,2);
      add_filter('as3cf_pre_update_attachment_metadata', array($this, 'preventInitialUpload'), 10,4);

      //add_filter('shortpixel_get_attached_file', array($this, 'get_raw_attached_file'),10, 2);
    //  add_filter('shortpixel_get_original_image_path', array($this, 'get_raw_original_path'), 10, 2);
      add_filter('shortpixel/image/urltopath', array($this, 'checkIfOffloaded'), 10,2);
      add_filter('shortpixel/file/virtual/translate', array($this, 'getLocalPathByURL'));

      // for webp picture paths rendered via output
      add_filter('shortpixel_webp_image_base', array($this, 'checkWebpRemotePath'), 10, 2);
      add_filter('shortpixel/front/webp_notfound', array($this, 'fixWebpRemotePath'), 10, 4);

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

    /**
    * @param $id attachment id (WP)
    * @param $mediaItem  MediaLibraryModel SPIO
    * @param $clean - boolean - if restore did all files (clean) or partial (not clean)
    */
    public function image_restore($mediaItem, $id, $clean)
    {
      if (! $clean)
        return false; // don't do anything until we have restored all ( for now )

      $settings = \wpSPIO()->settings();

			// Only medialibrary offloading supported.
			if ('media' !== $mediaItem->get('type') )
			{
				 return false;
			}

      // If there are excluded sizes, there are not in backups. might not be left on remote, or ( if delete ) on server, so just generate the images and move them.
      $mediaItem->wpCreateImageSizes();

      $this->remove_remote($id);
      $this->image_upload($mediaItem);
    }

    public function remove_remote($id)
    {
      $item = $this->getItemById($id); // MediaItem is AS3CF Object
      if ($item === false)
      {
        Log::addDebug('S3-Offload MediaItem not remote - ' . $id);
        return false;
      }

			// Backwards compat.
			if ($this->useHandlers)
			{
				$remove = \DeliciousBrains\WP_Offload_Media\Items\Remove_Provider_Handler::get_item_handler_key_name();
				$itemHandler = $this->as3cf->get_item_handler($remove);
			//	$files = $a3cfItem->offloaded_files();
				$result = $itemHandler->handle($item, array( 'verify_exists_on_local' => false)); //handle it then.
			//	$result = $itemHandler->handle($item, array( 'verify_exists_on_local' => false, 'offloaded_files' => $files )); //handle it then.

			}
			else // compat.
			{
					$this->as3cf->remove_attachment_files_from_provider($id, $item);
			}

    }


    /** @return Returns S3Ofload MediaItem, or false when this does not exist */
    protected function getItemById($id)
    {
				$class = $this->getMediaClass();
			  $mediaItem = $class::get_by_source_id($id);

				if ($this->useHandlers && $mediaItem === false)
				{
					 $mediaItem = $class::create_from_source_id($id);
				}

        return $mediaItem;
    }

		private function sourceCache($url, $source_id = null)
		{
			if ($source_id === null && isset($this->sources[$url]))
			{
				$source_id = $this->sources[$url];
				return $source_id;
			}
			elseif ($source_id !== null)
			{
				 if (! isset($this->sources[$url]))
				 {
					  $this->sources[$url]  = $source_id;
				 }
				 return $source_id;
			}

			return false;
		}

    public function checkIfOffloaded($bool, $url)
    {

			$source_id = $this->sourceCache($url);


			if (false === $source_id)
			{
				$extension = substr($url, strrpos($url, '.') + 1);
				// If these filetypes are not in the cache, they cannot be found via geSourceyIDByUrl method ( not in path DB ), so it's pointless to try. If they are offloaded, at some point the extra-info might load.
				if ($extension == 'webp' || $extension == 'avif')
				{
					return false;
				}

     		$source_id = $this->getSourceIDByURL($url);
			}

      if ($source_id !== false)
			{
        return true;
			}
      else
        return false;
    }

    protected function getSourceIDByURL($url)
    {

			$source_id = $this->sourceCache($url); // check cache first.

			if (false === $source_id) // check on the raw url.
			{
      	$class = $this->getMediaClass();
      	$source = $class::get_item_source_by_remote_url($url);
				$source_id = isset($source['id']) ? intval($source['id']) : false;
			}

			if (false === $source_id) // check now via the thumbnail hocus.
			{
				$pattern = '/(.*)-\d+[xX]\d+(\.\w+)/m';
				$url = preg_replace($pattern, '$1$2', $url);

				$source_id = $this->sourceCache($url); // check cache first.

				if (false === $source_id)
				{
					$source = $class::get_item_source_by_remote_url($url);
					$source_id = isset($source['id']) ? intval($source['id']) : false;
				}

      }

			if ($source_id !== false)
			{

				$this->sourceCache($url, $source_id);  // cache it.

				// get item
				$item = $this->getItemById($source_id);
				if (is_object($item) && method_exists($item, 'extra_info'))
				{
					$baseUrl = str_replace(basename($url),'', $url);
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

				return $source_id;
			}

      return false;
    }

    //** The thumbnails are not recorded by offload, but still offloaded.
    private function checkIfThumbnail($original_url)
    {
        //$result = \attachment_url_to_postid($url);

        if ($source_id !== false)
				{
					$this->sourceCache($url, $source_id);
          return $source_id;
				}
        else
          return false;

    }

		// @param s3 based URL that which is needed for finding local path
		// @return String Filepath.  Translated file path
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

				// Do nothing if not successfull
				if ($params['success'] === false)
					return;

				$id = $mediaItem->get('id');
				$this->remove_remote($id);

				$item = $this->getItemById($id, $mediaItem);
				$item->delete();

				$this->image_upload($mediaItem);
				return;
        // delete the old file
       // $item = $this->getItemById($id);
       // if ($mediaItem === false) // mediaItem seems not present. Probably not a remote file
       //   return;

        //$providerFile = $fs->getFile($providerSourcePath);
        //$newFile = $fs->getFile($this->returnOriginalFile(null, $id));


        // convert
        if ($providerFile->getExtension() !== $newFile->getExtension())
        {
          $data = $mediaItem->key_values(true);
          $record_id = $data['id'];

          $data['path'] = str_replace($providerFile->getFileName(), $newFile->getFileName(), $data['path']);


					//$provider, $region, $bucket, $path, $is_private, $source_id, $source_path, $original_filename = null, $private_sizes = array(), $id = null
					$class = $this->getMediaClass();
          $newItem = new $class($data['provider'], $data['region'], $data['bucket'], $data['path'], $data['is_private'], $data['source_id'], $data['source_path'], $newFile->getFileName(), $data['extra_info'], $record_id );

          $newItem->save();

            Log::addDebug('S3Offload - Uploading converted file ');
        }

        // upload
        $this->image_upload($mediaItem); // delete and reupload
    }


    public function image_converted_legacy($id)
    {
        $fs = \wpSPIO()->fileSystem();

        // Don't offload when setting is off.
        // delete the old file.
      //  $provider_object = $this->as3cf->get_attachment_provider_info($id);

  //      $this->as3cf->remove_attachment_files_from_provider($id, $provider_object);
        // get some new ones.

        // delete the old file
        $item = $this->getItemById($id);
        if ($item === false) // mediaItem seems not present. Probably not a remote file
          return;

        $this->as3cf->remove_attachment_files_from_provider($id, $item);
        $providerSourcePath = $item->source_path();

        //$providerFile = $fs->getFile($provider_object['key']);
        $providerFile = $fs->getFile($providerSourcePath);
        $newFile = $fs->getFile($this->returnOriginalFile(null, $id));

        // convert
        //$newfilemeta = $provider_object['key'];
        if ($providerFile->getExtension() !== $newFile->getExtension())
        {
          //  $newfilemeta = str_replace($providerFile->getFileName(), $newFile->getFileName(), $newfilemeta);
          $data = $item->key_values(true);
          $record_id = $data['id'];
/*          $data['path']
          $data['original_path']
          $data['original_source_path']
          $data['source_path'] */

          $data['path'] = str_replace($providerFile->getFileName(), $newFile->getFileName(), $data['path']);
          /*$data['original_path'] = str_replace($providerFile->getFileName(), $newFile->getFileName(), $data['original_path']);
          $data['source_path'] = str_replace($providerFile->getFileName(), $newFile->getFileName(), $data['source_path']);
          $data['original_source_path'] = str_replace($providerFile->getFileName(), $newFile->getFileName(), $data['original_source_path']);
*/


//$provider, $region, $bucket, $path, $is_private, $source_id, $source_path, $original_filename = null, $private_sizes = array(), $id = null
          $newItem = new $this->itemClassName($data['provider'], $data['region'], $data['bucket'], $data['path'], $data['is_private'], $data['source_id'], $data['source_path'], $newFile->getFileName(), $data['extra_info'], $record_id );

          $newItem->save();

            Log::addDebug('S3Offload - Uploading converted file ');
        }

				$mediaItem = $fs->getImage($post_id, 'media');

        // upload
        $this->image_upload($mediaItem); // delete and reupload
    }


    public function image_upload($imageItem)
    {
        if (! $this->offloading)
          return false;

				// Only medialibrary offloading supported.
				if ('media' !== $imageItem->get('type') )
				{
					 return false;
				}

				$id = $imageItem->get('id');
        $item = $this->getItemById($id);

        if ( $item === false && ! $this->as3cf->get_setting( 'copy-to-s3' ) ) {
          // abort if not already uploaded to provider and the copy setting is off
          Log::addDebug('As3cf image upload is off and object not previously uploaded');
          return false;
        }

        if ($this->useHandlers)
        {

					// Add Web/Avifs back under new method.

					$fullPaths = $item->full_source_paths();
					$extra_info = $item->extra_info();

					$file_paths = $this->add_webp_paths($fullPaths);

					if (! isset($extra_info['objects']))
					 	$extra_info['objects']= array();


					foreach ( $file_paths as $size => $size_file_path ) {
						if ( $size === 'file' ) {
							continue;
						}

						$new_object = array(
							'source_file' => wp_basename( $size_file_path ),
							'is_private'  => false,
						);

						$extra_info['objects'][ $size ] = $new_object;
					}

					if (count($file_paths) > 0)
					{
						 $item->set_extra_info($extra_info);
					}

					// This should load the A3cf UploadHandler
					$upload = \DeliciousBrains\WP_Offload_Media\Items\Upload_Handler::get_item_handler_key_name();
          $itemHandler = $this->as3cf->get_item_handler($upload);

          $result = $itemHandler->handle($item); //handle it then.
        }
        else {
					   $this->as3cf->upload_attachment($id);
        }
    }

    /** This function will cut out the initial upload to S3Offload and rely solely on the image_upload function provided here, after shortpixel optimize.
    * Function will only work when plugin is set to auto-optimize new entries to the media library
    * Since S3-Offload 2.3 this will be called on every thumbnail ( changes in WP 5.3 )
    */
    public function preventInitialUpload($bool, $data, $post_id, $old_provider_object)
    {
        $fs = \wpSPIO()->filesystem();
				$settings = \WPSPIO()->settings();

				$quotaController = quotaController::getInstance();
				if ($quotaController->hasQuota() === false)
				{
					return false;
				}
        if (! $this->offloading)
          return false;

				if ($this->shouldPrevent === false) // if false is returned, it's NOT prevented, so on-going.
						return false;

        if (\wpSPIO()->env()->is_autoprocess)
        {
          // Don't prevent whaffever if shortpixel is already done. This can be caused by plugins doing a metadata update, we don't care then.
          $mediaItem = $fs->getImage($post_id, 'media');
          if ($mediaItem && ! $mediaItem->isOptimized())
          {

						$image_file = $mediaItem->getFileName();
						if ($mediaItem->getExtension() == 'pdf' && ! $settings->optimizePdfs  )
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
        $paths = $this->getWebpPaths($paths, true);
        return $paths;
    }


    public function remove_webp_paths($paths)
    {
      $paths = $this->getWebpPaths($paths, false);
      //Log::addDebug('Remove S3 Paths', array($paths));

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
