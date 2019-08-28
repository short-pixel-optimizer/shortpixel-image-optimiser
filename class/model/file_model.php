<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;

/* FileModel class.
*
*
* - Represents a -single- file.
* - Can handle any type
* - Usually controllers would use a collection of files
* - Meant for all low-level file operations and checks.
* - Every file can have a backup counterpart.
*
*/
class FileModel extends ShortPixelModel
{

  // File info
  protected $fullpath;
  protected $filename; // filename + extension
  protected $filebase; // filename without extension
  protected $directory;
  protected $extension;

  // File Status
  protected $exists = false;
  protected $is_writable = false;
  protected $is_readable = false;
  protected $status;

  protected $backupDirectory;

  const FILE_OK = 1;
  const FILE_UNKNOWN_ERROR = 2;


  /** Creates a file model object. FileModel files don't need to exist on FileSystem */
  public function __construct($path)
  {
      $processed_path = $this->processPath($path);
      if ($processed_path !== false)
        $this->fullpath = $processed_path; // set processed path if that went alright
      else {
        $this->fullpath = $path;  // fallback, but there should be error state
      }

    //  $this->fullpath =
      $this->setFileInfo();
  }

  public function __toString()
  {
    return (string) $this->fullpath;
  }

  protected function setFileInfo()
  {
    if (file_exists($this->fullpath))
    {
      $this->exists = true;
      $info = pathinfo($this->fullpath);
      $this->filename = isset($info['basename']) ? $info['basename'] : null; // filename + extension
      $this->filebase = isset($info['filename']) ? $info['filename'] : null; // only filename
      $this->extension = isset($info['extension']) ? $info['extension'] : null; // only (last) extension
      $this->directory = isset($info['dirname']) ? new DirectoryModel($info['dirname']) : null;
      $this->is_writable();
      $this->is_readable();
    }
    else {
      $this->exists = false;
      $this->is_writable = false;
      $this->is_readable = false;

      if (is_null($this->filename))
        $this->filename = basename($this->fullpath);

      if (is_null($this->directory) && ! is_null($this->filename) && strlen($this->filename) > 0)
        $this->directory = new DirectoryModel(dirname($this->fullpath));
    }
  }

  public function exists()
  {
    $this->exists = file_exists($this->fullpath);
    return $this->exists;
  }

  public function is_writable()
  {
    $this->is_writable = is_writable($this->fullpath);
    return $this->is_writable;
  }

  public function is_readable()
  {
    $this->is_readable = is_readable($this->fullpath);
    return $this->is_readable;
  }

  public function hasBackup()
  {
      $directory = $this->getBackupDirectory();
      if (! $directory)
        return false;

      $backupFile =  $directory . $this->filename;

      if (file_exists($backupFile))
        return true;
      else {
        return false;
      }
  }

  /** Tries to retrieve an *existing* BackupFile. Returns false if not present.
  * This file might not be writable.
  * To get writable directory reference to backup, use FileSystemController
  */
  public function getBackupFile()
  {
     if ($this->hasBackup())
        return new FileModel($this->getBackupDirectory() . $this->filename);
     else
       return false;
  }

  /** Returns the Directory Model this file resides in
  *
  * @return DirectoryModel Directorymodel Object
  */
  public function getFileDir()
  {
      return $this->directory;
  }

  /** Copy a file to somewhere
  *
  * @param $destination String Full Path to new file.
  */
  public function copy(FileModel $destination)
  {
      $sourcePath = $this->getFullPath();
      $destinationPath = $destination->getFullPath();
      Log::addDebug("Copy from $sourcePath to $destinationPath ");

      if (! strlen($sourcePath) > 0 || ! strlen($destinationPath) > 0)
      {
        Log::addWarn('Attempted Copy on Empty Path', array($sourcePath, $destinationPath));
        return false;
      }

      $is_new = ($destination->exists()) ? false : true;
      $status = copy($sourcePath, $destinationPath);

      if (! $status)
        Log::addWarn('Could not copy file ' . $sourcePath . ' to' . $destinationPath);
      else {
        $destination->setFileInfo(); // refresh info.
      }
      //
      do_action('shortpixel/filesystem/addfile', array($destinationPath, $destination, $this, $is_new));
      return $status;
  }

  /** Move a file to somewhere
  * This uses copy and delete functions and will fail if any of those fail.
  * @param $destination String Full Path to new file.
  */
  public function move(FileModel $destination)
  {
     $result = false;
     if ($this->copy($destination))
     {
       $result = $this->delete();
     }
     return $result;
  }

  /** Deletes current file
  * This uses the WP function since it has a filter that might be useful
  */
  public function delete()
  {
      \wp_delete_file($this->fullpath);  // delete file hook via wp_delet_file
      $this->setFileInfo(); // update info

      if (! file_exists($this->fullpath))
      {
        return true;
      }
      else {
        return false;
        Log::addWarn('File seems not removed - ' . $this->fullpath);
      }
  }

  public function getFullPath()
  {
    return $this->fullpath;
  }

  public function getFileName()
  {
    return $this->filename;
  }

  public function getFileBase()
  {
    return $this->filebase;
  }

  public function getExtension()
  {
    return $this->extension;
  }

  /* Util function to get location of backup Directory.
  * @return Boolean | DirectModel  Returns false if directory is not properly set, otherwhise with a new directoryModel
  */
  private function getBackupDirectory()
  {
    if (is_null($this->directory))
    {
        return false;
    }
    if (is_null($this->backupDirectory))
    {
      $backup_dir = str_replace(get_home_path(), "", $this->directory->getPath());
      $backupDirectory = SHORTPIXEL_BACKUP_FOLDER . '/' . $backup_dir;
      $directory = new DirectoryModel($backupDirectory);

      if (! $directory->exists()) // check if exists. FileModel should not attempt to create.
        return false;

      $this->backupDirectory = $directory;
    }

    return $this->backupDirectory;
  }

  /* Internal function to check if path is a real path
  *  - Test for URL's based on http / https
  *  - Test if given path is absolute, from the filesystem root.
  * @param $path String The file path
  * @param String The Fixed filepath.
  */
  protected function processPath($path)
  {
    $original_path = $path;
    $path = trim($path);

    if ($this->pathIsUrl($path))
    {
      $path = $this->UrlToPath($path);
    }

    if ($path === false)
      return false;

    $path = wp_normalize_path($path);

    // if path does not contain basepath.
    if (strpos($path, ABSPATH) === false && strpos($path, $this->getUploadPath()) === false)
      $path = $this->relativeToFullPath($path);


    $path = apply_filters('shortpixel/filesystem/processFilePath', $path, $original_path);
    /* This needs some check here on malformed path's, but can't be test for existing since that's not a requirement.
    if (file_exists($path) === false) // failed to process path to something workable.
    {
    //  Log::addInfo('Failed to process path', array($path));
      $path = false;
    } */

    return $path;
  }

  private function pathIsUrl($path)
  {
    $is_http = (substr($path, 0, 4) == 'http') ? true : false;
    $is_https = (substr($path, 0, 5) == 'https') ? true : false;
    $is_neutralscheme = (substr($path, 0, 1) == '//') ? true : false; // when URL is relative like //wp-content/etc
    $has_urldots = (strpos($path, '://') !== false) ? true : false;

    if ($is_http || $is_https || $is_neutralscheme || $has_urldots)
      return true;
    else {
      return false;
    }

  }

  private function UrlToPath($url)
  {
     //$uploadDir = wp_upload_dir();
     $site_url = str_replace('http:', '', get_site_url(null, '', 'http'));
     $url = str_replace(array('http:', 'https:'), '', $url);

     if (strpos($url, $site_url) !== false)
     {
       // try to replace URL for Path
       $path = str_replace($site_url, rtrim(ABSPATH,'/'), $url);
       if (! $this->pathIsUrl($path)) // test again.
       {
        return $path;
       }
     }

     return false; // seems URL from other server, can't file that.
  }

  /** Tries to find the full path for a perceived relative path.
  *
  * Relative path is detected on basis of WordPress ABSPATH. If this doesn't appear in the file path, it might be a relative path.
  * Function checks for expections on this rule ( tmp path ) and returns modified - or not - path.
  * @param $path The path for the file_exists
  * @returns String The updated path, if that was possible.
  */
  private function relativeToFullPath($path)
  {
      // A file with no path, can never be created to a fullpath.
      if (strlen($path) == 0)
        return $path;

      // if the file plainly exists, it's usable /**
      if (file_exists($path))
      {
        return $path;
      }

      // Test if our 'relative' path is not a path to /tmp directory.

      // This ini value might not exist.
      $tempdirini = ini_get('upload_tmp_dir');
      if ( (strlen($tempdirini) > 0) && strpos($path, $tempdirini) !== false)
        return $path;

      $tempdir = sys_get_temp_dir();
      if ( (strlen($tempdir) > 0) && strpos($path, $tempdir) !== false)
        return $path;

      // Path contains upload basedir. This happens when upload dir is outside of usual WP.
      if (strpos($path, $this->getUploadPath()) !== false)
      {
        return $path;
      }


      // this is probably a bit of a sharp corner to take.
      // if path starts with / remove it due to trailingslashing ABSPATH
      $path = ltrim($path, '/');
      $fullpath = trailingslashit(ABSPATH) . $path;
      // We can't test for file_exists here, since file_model allows non-existing files.
      return $fullpath;
  }

  private function getUploadPath()
  {
    $upload_dir = wp_upload_dir(null, false);
    $basedir = $upload_dir['basedir'];

    return $basedir;
  }

} // FileModel Class

/*
// do this before putting the meta down, since maybeDump check for last timestamp
$URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);
$this->maybeDumpFromProcessedOnServer($itemHandler, $URLsAndPATHs);

*/
