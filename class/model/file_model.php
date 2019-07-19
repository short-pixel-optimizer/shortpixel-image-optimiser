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
  protected $filename;
  protected $directory;
  protected $extension;

  // File Status
  protected $exists = false;
  protected $is_writable = false;

  protected $backupDirectory;

  /** Creates a file model object. FileModel files don't need to exist on FileSystem */
  public function __construct($path)
  {
      $this->fullpath = wp_normalize_path($path);
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
      $this->filename = isset($info['basename']) ? $info['basename'] : null;
      $this->extension = isset($info['extension']) ? $info['extension'] : null;
      $this->directory = isset($info['dirname']) ? new DirectoryModel($info['dirname']) : null;
      Log::addDebug('File info', array($info));
      $this->is_writable = is_writable($this->fullpath);
    }
    else {
      $this->exists = false;
      $this->writable = false;

      if (is_null($this->filename))
        $this->filename = basename($this->fullpath);

      if (is_null($this->directory))
        $this->directory = new DirectoryModel(dirname($this->fullpath));
    }
  }

  // Util function to get location of backup Directory.
  private function getBackupDirectory()
  {
    if (is_null($this->backupDirectory))
    {
      $backup_dir = str_replace(get_home_path(), "", $this->directory->getPath());
      Log::addDebug('Bkup ' . get_home_path() . ' ' . $this->directory->getPath() . '-->' . $backup_dir );
      $backupDirectory = SHORTPIXEL_BACKUP_FOLDER . '/' . $backup_dir;
      $directory = new DirectoryModel($backupDirectory);

      if (! $directory->exists()) // check if exists. FileModel should not attempt to create.
        return false;

      $this->backupDirectory = $directory;
    }

    return $this->backupDirectory;
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
      $status = copy($this->getFullPath(), $destination->getFullPath());
      if (! $status)
        Log::addWarn('Could not copy file ' . $this->getFullPath() . ' to' . $destination->getFullPath());
      else {
        $destination->setFileInfo(); // refresh info.
      }
      return $status;
  }

  /** Deletes current file
  * This uses the WP function since it has a filter that might be useful
  */
  public function delete()
  {
      \wp_delete_file($this->fullpath);
      if (! file_exists($this->fullpath))
      {
        $this->setFileInfo(); // update info
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

  public function getExtension()
  {
    return $this->extension;
  }

}

/*
// do this before putting the meta down, since maybeDump check for last timestamp
$URLsAndPATHs = $itemHandler->getURLsAndPATHs(false);
$this->maybeDumpFromProcessedOnServer($itemHandler, $URLsAndPATHs);

*/
