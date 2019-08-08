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

  /** Creates a directory model object. DirectoryModel directories don't need to exist on FileSystem */
  public function __construct($path)
  {
      //$this->new_directory_permission = octdec(06440);
      $this->path = wp_normalize_path(trailingslashit($path));

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
  * @return Mixed False if this didn't work, Path as string without basedir if it did.
  */
  public function getRelativePath()
  {
     $upload_dir = wp_upload_dir(null, false);

     Log::addDebug('Upload Dir - ', array($upload_dir));
     $relativePath = str_replace($upload_dir['basedir'], '', $this->getPath());

     // if relativePath has less amount of characters, changes are this worked.
     if (strlen($this->getPath()) > strlen($relativePath))
     {
       return $relativePath;
     }
     return false;
  }

  /** Checks the directory
  *
  */
  public function check($check_writable = false)
  {
     if (! $this->exists())
     {
        Log::addInfo('Direct does not exists. Try to create recursive ' . $this->path . ' with '  . $this->new_directory_permission);
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
