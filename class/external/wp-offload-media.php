<?php
namespace ShortPixel;
use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\FileSystemController as FileSystem;

class wpOffload
{
    protected $as3cf;
    protected $active = false;

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
      add_action('shortpixel_after_restore_image', array($this, 'image_upload'));
      add_filter('as3cf_attachment_file_paths', array($this, 'add_webp_paths'));
      //add_action('as3cf_attachment_file_paths', array($this, 'check_webp'));
    }

    public function image_upload($id)
    {
        Log::addDebug('Uploading New Attachment');
        $this->as3cf->upload_attachment($id);
    }

    /** Paths - size : path values */
    public function add_webp_paths($paths)
    {
        Log::addDebug('Received Paths', array($paths));
        $fs = new FileSystem();
        $newPaths = array();
        foreach($paths as $size => $path)
        {
           $file = $fs->getFile($path);
           $basepath = $file->getFileDir()->getPath();
           $newPaths[$size] = $path;

           if (file_exists($basepath . $file->getFileName() . '.webp'))
           {
             $newPaths[$size . '_webp'] = (string) $basepath . $file->getFileName() . '.webp';
           }
           elseif (file_exists($basepath . $file->getFileBase() . '.webp'))
           {
             $newPaths[$size . '_webp'] = (string) $basepath . $file->getFileBase() . '.webp';
           }
        }

        Log::addDebug('Webp Path Founder (S3)', array($newPaths));
        return $newPaths;
    }

}

$wpOff = new wpOffload();
