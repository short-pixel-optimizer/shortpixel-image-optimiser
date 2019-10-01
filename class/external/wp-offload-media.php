<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\FileSystemController as FileSystem;

class wpOffload
{
    protected $as3cf;
    protected $active = false;

    protected $settings;

    public function __construct()
    {
       // This must be called before WordPress' init.
       add_action('as3cf_init', array($this, 'init'));
    }

    public function init($as3cf)
    {



      $this->as3cf = $as3cf;
      $this->active = true;

      add_action('shortpixel_image_optimised', array($this, 'image_upload'));
      add_action('shortpixel_after_restore_image', array($this, 'image_restore')); // hit this when restoring.
      add_action('shortpixel/image/convertpng2jpg_after', array($this, 'image_converted'));
      add_action('shortpixel_before_restore_image', array($this, 'remove_remote')); // not optimal, when backup fails this will cause issues.
      add_action('shortpixel/image/convertpng2jpg_before', array($this, 'remove_remote'));
      add_filter('as3cf_attachment_file_paths', array($this, 'add_webp_paths'));
      add_filter('as3cf_remove_attachment_paths', array($this, 'remove_webp_paths'));

      add_filter('shortpixel/restore/targetfile', array($this, 'returnOriginalFile'),10,2);

      add_filter('as3cf_pre_update_attachment_metadata', array($this, 'preventInitialUpload'), 10,4);

      add_filter('get_attached_file', function($file, $id)
      {
          $scheme = parse_url($file, PHP_URL_SCHEME);
          if ($scheme !== false && strpos($scheme, 's3') !== false)
          {
            return get_attached_file($id, true);
          }
          return $file;
      },10, 2);
    }

    public function addURLforDownload($bool, $url, $host)
    {
      $provider = $this->as3cf->get_provider();
      $provider->get_url_domain();

      //as3cf_aws_s3_client_args filter?
      return $url;
    }

    public function returnOriginalFile($file, $attach_id)
    {
      $file = get_attached_file($attach_id, true);
      return $file;
    }

    public function image_restore($id)
    {
      //$provider_object = $this->as3cf->get_attachment_provider_info($id);
      //$this->as3cf->remove_attachment_files_from_provider($id, $provider_object);
      $this->remove_remote($id);

      //Log::addDebug('S3Offload - Image restore  - ', array($id, $provider_object, get_attached_file($id)));
    //  $provider_object['key']  =

    //  add_post_meta( $id, 'amazonS3_info', $provider_object );
    //  delete_post_meta( $post_id, 'amazonS3_info' );

      $this->image_upload($id);

    }

    public function remove_remote($id)
    {
      $provider_object = $this->as3cf->get_attachment_provider_info($id);
      $this->as3cf->remove_attachment_files_from_provider($id, $provider_object);
    }

    public function image_converted($id)
    {
        $fs = new \ShortPixel\FileSystemController();

        // delete the old file.
        $provider_object = $this->as3cf->get_attachment_provider_info($id);
  //      $this->as3cf->remove_attachment_files_from_provider($id, $provider_object);

        // get some new ones.
        $providerFile = $fs->getFile($provider_object['key']);
        $newFile = $fs->getFile($this->returnOriginalFile(null, $id));

        // convert
        $newfilemeta = $provider_object['key'];
        if ($providerFile->getExtension() !== $newFile->getExtension())
        {
            $newfilemeta = str_replace($providerFile->getFileName(), $newFile->getFileName(), $newfilemeta);
            Log::addDebug('S3Offload, replacing image in provider meta', array($newfilemeta));
        }
        else {
           Log::addDebug('ProviderFile and NewFile same extension', array($providerFile->getFullPath(), $newFile->getFullPath()));
        }

        // upload
        $provider_object['key'] = $newfilemeta;
        update_post_meta( $id, 'amazonS3_info', $provider_object );

        $this->image_upload($id); // delete and reupload
    }

    public function image_upload($id)
    {
        //$this->as3cf->get_setting( 'copy-to-s3' )
        if ( ! ( $old_provider_object = $this->as3cf->get_attachment_provider_info( $id ) ) && ! $this->as3cf->get_setting( 'copy-to-s3' ) ) {
          // abort if not already uploaded to provider and the copy setting is off
          Log::addDebug('As3cf image upload is off and object not previously uploaded');
          return false;
        }

        Log::addDebug('Uploading New Attachment');
        $this->as3cf->upload_attachment($id);
    }

    /** This function will cut out the initial upload to S3Offload and rely solely on the image_upload function provided here, after shortpixel optimize.
    * Function will only work when plugin is set to auto-optimize new entries to the media library */
    public function preventInitialUpload($bool, $data, $post_id, $old_provider_object)
    {
        // @todo weak call. See how in future settings might come via central provider.
        $settings = new \WPShortPixelSettings();

        if ($settings->autoMediaLibrary)
        {
          return true;
        }
        return $bool;
    }

    private function getWebpPaths($paths, $check_exists = true)
    {
      $newPaths = array();
      $fs = new FileSystem();

      foreach($paths as $size => $path)
      {
         $file = $fs->getFile($path);
         $basepath = $file->getFileDir()->getPath();
         $newPaths[$size] = $path;

         $webpformat1 = $basepath . $file->getFileName() . '.webp';
         $webpformat2 = $basepath . $file->getFileBase() . '.webp';

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
        Log::addDebug('Webp Path Founder (S3)', array($paths));
        return $paths;
    }

    public function remove_webp_paths($paths)
    {
      $paths = $this->getWebpPaths($paths, false);
      Log::addDebug('Remove S3 Paths', array($paths));
      return $paths;
    }

}

$wpOff = new wpOffload();
