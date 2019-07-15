<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

/* Model for Directories
*
* For all low-level operations on directories
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
      Log::addDebug("DirectoryModel LoadPath - " . $this->path);
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

  /** Checks the directory
  *
  */
  public function check($check_writable = false)
  {
     if (! $this->exists())
     {
        Log::addDebug('Direct does not exists. Try to create recursive ' . $this->path . ' with '  . $this->new_directory_permission);
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
