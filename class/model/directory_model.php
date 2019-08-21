<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

/* Model for Directories
*
* For all low-level operations on directories
* Private model of FileSystemController. Please get your directories via there.
*
*/

class DirectoryModel extends ShortPixelModel
{
  // Directory info
  protected $path;

  // Directory status
  protected $exists = false;
  protected $is_writable = false;

  protected $new_directory_permission = 0755;

  /** Creates a directory model object. DirectoryModel directories don't need to exist on FileSystem
  *
  * When a filepath is given, it  will remove the file part.
  */
  public function __construct($path)
  {
      //$this->new_directory_permission = octdec(06440);

      $path = wp_normalize_path($path);
      if (! is_dir($path)) // path is wrong, *or* simply doesn't exist.
      {
        /* Test for file input.
        * If pathinfo is fed a fullpath, it rips of last entry without setting extension, don't further trust.
        * If it's a file extension is set, then trust.
        */
        $pathinfo = pathinfo($path);
        if (isset($pathinfo['extension']))
        {
          $path = $pathinfo['dirname'];
        }
        elseif (is_file($path))
          $path = dirname($path);
      }

      $this->path = trailingslashit($path);

      if (file_exists($this->path))
      {
        $this->exists();
        $this->is_writable();
      }
  }

  public function __toString()
  {
    return (string) $this->path;
  }

  /** Returns path *with* trailing slash
  *
  * @return String Path with trailing slash
  */
  public function getPath()
  {
    return $this->path;
  }

  public function exists()
  {
    $this->exists = file_exists($this->path);
    return $this->exists;
  }

  public function is_writable()
  {
    $this->is_writable = is_writable($this->path);
    return $this->is_writable;
  }

  /** Try to obtain the path, minus the installation directory.
  * @return Mixed False if this didn't work, Path as string without basedir if it did. With trailing slash, without starting slash.
  */
  public function getRelativePath()
  {
    //$_SERVER['DOCUMENT_ROOT'] <!-- another tool.
     $upload_dir = wp_upload_dir(null, false);

     $install_dir = get_home_path();
     if($install_dir == '/') {
         $install_dir = ABSPATH;
     }

     $install_dir = trailingslashit($install_dir);
//Log::addDebug('Install Dir - ' . $install_dir);

     $path = $this->getPath();
     // try to build relativePath without first slash.
     $relativePath = str_replace($install_dir, '', $path);

     if (! is_dir( $install_dir . $relativePath))
     {
        $test_path = $this->reverseConstructPath($path, $install_dir);
        if ($test_path !== false)
        {
            $relativePath = $test_path;
        }
        else {
           if($test_path = $this->constructUsualDirectories($path))
           {
             $relativePath = $test_path;
           }

        }
     }

     // if relativePath has less amount of characters, changes are this worked.
     if (strlen($path) > strlen($relativePath))
     {
       return ltrim(trailingslashit($relativePath), '/');
     }
     return false;
  }

  private function reverseConstructPath($path, $install_path)
  {
    $pathar = array_values(array_filter(explode('/', $path))); // array value to reset index
    $parts = array(); //

    if (is_array($pathar))
    {
      // reverse loop the structure until solid ground is found.
      for ($i = (count($pathar)); $i > 0; $i--)
      {
        $parts[]  = $pathar[$i-1];
        $testpath = implode('/', array_reverse($parts));
        if (is_dir($install_path . $testpath)) // if the whole thing exists
        {
          return $testpath;
        }
      }
    }
    return false;
  }

  /* Last Resort function to just reduce path to various known WorPress paths. */
  private function constructUsualDirectories($path)
  {
    $pathar = array_values(array_filter(explode('/', $path))); // array value to reset index
    $test_path = false;
    if ( ($key = array_search('wp-content', $pathar)) !== false)
    {
        $testpath = implode('/', array_slice($pathar, $key));
    }
    elseif ( ($key = array_search('uploads', $pathar)) !== false)
    {
        $testpath = implode('/', array_slice($pathar, $key));
    }

    return $testpath;
  }

  /** Checks the directory
  *
  */
  public function check($check_writable = false)
  {
     if (! $this->exists())
     {
        Log::addInfo('Directory does not exists. Try to create recursive ' . $this->path . ' with '  . $this->new_directory_permission);
        $result = @mkdir($this->path, $this->new_directory_permission , true);
        if (! $result)
        {
          $error = error_get_last();
          echo $error['message'];
          Log::addWarn('MkDir failed: ' . $error['message'], array($error));
        }

     }
     if ($this->exists() && $check_writable && ! $this->is_writable())
     {
       chmod($this->path, $this->new_directory_permission);
     }

     if (! $this->exists())
     {
       Log::addInfo('Directory does not exist :' . $this->path);
       return false;
     }
    if ($check_writable && !$this->is_writable())
    {
        Log::addInfo('Directory not writable :' . $this->path);
        return false;
    }
    return true;
  }


}
