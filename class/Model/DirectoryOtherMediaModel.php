<?php
namespace ShortPixel\Model;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use \ShortPixel\Model\DirectoryModel as DirectoryModel;

// extends DirectoryModel. Handles Shortpixel_meta database table
// Replacing main parts of shortpixel-folder
class DirectoryOtherMediaModel extends DirectoryModel
{

  protected $id = -1; // if -1, this might not exist yet in Dbase. Null is not used, because that messes with isset

  protected $name;
  protected $status = 0;
  protected $fileCount = 0; // inherent onreliable statistic in dbase. When insert / batch insert the folder count could not be updated, only on refreshFolder which is a relative heavy function to use on every file upload. Totals are better gotten from a stat-query, on request.
  protected $updated = 0;
  protected $created = 0;

  protected $is_nextgen;
  protected $in_db = false;
  protected $is_removed = false;

  protected $stats;

  const DIRECTORY_STATUS_REMOVED = -1;
  const DIRECTORY_STATUS_NORMAL = 0;
  const DIRECTORY_STATUS_NEXTGEN = 1;

  /** Path or Folder Object, from SpMetaDao
  *
  */
  public function __construct($path)
  {

    if (is_object($path))
    {
       $folder = $path;
       $path = $folder->path;

       parent::__construct($path);
       $this->loadFolder($folder);
    }
    else
    {
      parent::__construct($path);
      $this->loadFolderbyPath($path);
    }
  }


  private function loadFolderByPath($path)
  {
      $folders = self::get(array('path' => $path)); //s\wpSPIO()->getShortPixel()->getSpMetaDao()->getFolder($path);
      $folder = false;

      if ($folders && count($folders) > 0)
        $folder = $folders[0];

      return $this->loadFolder($folder);

  }

  /** Loads from database into model, the extra data of this model. */
  private function loadFolder($folder)
  {

    if (is_object($folder))
    {
      // suboptimally, this function needs to work both with database record output and instances of itself
      $class = get_class($folder);

      $this->id = $folder->id;

      if ($this->id > 0)
       $this->in_db = true;

      if ($class == 'ShortPixel\DirectoryOtherMediaModel')
      {
        $this->updated = $folder->updated;
        $this->create = $folder->created;
        $this->fileCount = $folder->fileCount;
      }
      else
      {
        $this->updated = isset($folder->ts_updated) ? $this->DBtoTimestamp($folder->ts_updated) : time();
        $this->created = isset($folder->ts_created) ? $this->DBtoTimestamp($folder->ts_created) : time();
        $this->fileCount = isset($folder->file_count) ? $folder->file_count : 0;
      }
      if (strlen($folder->name) == 0)
        $this->name = basename($folder->path);
      else
        $this->name = $folder->name;

      $this->status = $folder->status;

      if ($this->status == -1)
        $this->is_removed = true;

      do_action('shortpixel/othermedia/folder/load', $this->id, $this);

    }
  }

  public function getStatus()
  {
      return $this->status;
  }

  public function setStatus($status)
  {
     $this->status = $status;
  }

  public function getFileCount()
  {
     return $this->fileCount;
  }

  public function getId()
  {
    return $this->id;
  }

  public function getUpdated()
  {
     return $this->updated;
  }

  public function setUpdated($time)
  {
    $this->updated = $time;
  }

  public function setNextGen($bool = true)
  {
    $this->is_nextgen = $bool;
  }

  public function isNextGen()
  {
    return $this->is_nextgen;
  }

  public function hasDBEntry()
  {
    return $this->in_db;
  }

  public function isRemoved()
  {
     return $this->is_removed;
  }

  public function getStats()
  {
    if (is_null($this->stats))
    {
        $this->stats = \wpSPIO()->getShortPixel()->getSpMetaDao()->getFolderOptimizationStatus($this->id);
    }

    return $this->stats;
  }

  public function save()
  {
    // Simple Update
        $args = array(
            'id' => $this->id,
            'status' => $this->status,
            'file_count' => $this->fileCount,
            'ts_updated' => $this->timestampToDB($this->updated),
            'name' => $this->name,
            'path' => $this->getPath(),
        );
        $result = \wpSPIO()->getShortPixel()->getSpMetaDao()->saveDirectory($args);
        if ($result) // reloading because action can create a new DB-entry, which will not be reflected (in id )
          $this->loadFolderByPath($this->getPath());

        return $result;
  }

  public function delete()
  {
      $id = $this->id;
      if (! $this->in_db)
      {
         Log::addError('Trying to remove Folder without ID ' . $id, $this->getPath());
      }

      return \wpSPIO()->getShortPixel()->getSpMetaDao()->removeFolder($id);

  }

  /** Updates the updated variable on folder to indicating when the last file change was made
  * @return boolean  True if file were changed since last update, false if not
  */
  public function updateFileContentChange()
  {
      if (! $this->exists() )
        return false;

      $old_time = $this->updated;

      $time = $this->recurseLastChangeFile();
      $this->updated = $time;
      $this->save();

      if ($old_time !== $time)
        return true;
      else
        return false;
  }


  private function recurseLastChangeFile($mtime = 0)
  {
    $ignore = array('.','..');
    $path = $this->getPath();

    $files = scandir($path);
    $files = array_diff($files, $ignore);

    $mtime = max($mtime, filemtime($path));

    foreach($files as $file) {

        $filepath = $path . $file;

        if (is_dir($filepath)) {
            $mtime = max($mtime, filemtime($filepath));
            $subDirObj = new DirectoryOtherMediaModel($filepath);
            $subdirtime = $subDirObj->recurseLastChangeFile($mtime);
            if ($subdirtime > $mtime)
              $mtime = $subdirtime;
        }
    }
    return $mtime;
  }

  private function timestampToDB($timestamp)
  {
      return date("Y-m-d H:i:s", $timestamp);
  }

  private function DBtoTimestamp($date)
  {
      return strtotime($date);
  }



  /** Crawls the folder and check for files that are newer than param time, or folder updated
  * Note - last update timestamp is not updated here, needs to be done separately.
  */
  public function refreshFolder($time = false)
  {
      if ($time === false)
        $time = $this->updated;

      if ($this->id <= 0)
      {
        Log::addWarn('FolderObj from database is not there, while folder seems ok ' . $this->getPath() );
        return false;
      }
      elseif (! $this->exists())
      {
        Notice::addError( sprintf(__('Folder %s does not exist! ', 'shortpixel-image-optimiser'), $this->getPath()) );
        return false;
      }
      elseif (! $this->is_writable())
      {
        Notice::addWarning( sprintf(__('Folder %s is not writeable. Please check permissions and try again.','shortpixel-image-optimiser'),$this->getPath()) );
        return false;
      }

      $fs = \wpSPIO()->filesystem();

      $filter = ($time > 0)  ? array('date_newer' => $time) : array();
      $files = $fs->getFilesRecursive($this, $filter);

      $shortpixel = \wpSPIO()->getShortPixel();
      // check processable by invoking filter, for now processablepath takes only paths, not objects.
      $files = array_filter($files, function($file) use($shortpixel) { return $shortpixel->isProcessablePath($file->getFullPath());  });

      Log::addDebug('Refreshing from ' . $time . ', found Files for custom media ID ' . $this-> id . ' -> ' . count($files));

    //  $folderObj->setFileCount( count($files) );

      \wpSPIO()->settings()->hasCustomFolders = time(); // note, check this against bulk when removing. Custom Media Bulk depends on having a setting.
      \wpSPIO()->getShortPixel()->getSpMetaDao()->batchInsertImages($files, $this->id);

      $stats = $this->getStats();
      $this->fileCount = $stats->Total;
      $this->save();

  }


  /* Get the custom Folders from DB, put them in model
  @return Array  Array of directoryOtherMediaModel
  */
  public static function get($args = array())
  {
    $defaults = array(
        'id' => false,  // Get folder by Id
        'remove_hidden' => false,
        'path' => false,
    );

    $args = wp_parse_args($args, $defaults);

    $fs =  \wpSPIO()->fileSystem();
    $cache = new \ShortPixel\Controller\CacheController();

    $spMetaDao = \wpSPIO()->getShortPixel()->getSpMetaDao();

    if ($args['id'] !== false && $args['id'] > 0)
    {
        $folders = $spMetaDao->getFolderByID($args['id']);
    }
    elseif($args['path'] !== false && strlen($args['path']) > 0)
    {
        $folders = $spMetaDao->getFolder($args['path']);
    }
    else
    {
      $folders = $spMetaDao->getFolders();
    }

    if ($folders === false)  // no folders.
      return $folders;

    $i = 0;
    $newfolders = array();
    foreach($folders as $index => $folder)
    {

      $dirObj = new DirectoryOtherMediaModel($folder);

      if ($args['remove_hidden'])
      {
         if ($dirObj->is_removed)
          continue;
      }
      $newfolders[$i] = $dirObj; // $index is dbase id, we just want an array
      $i++;
    }

    return $newfolders;
  }




}
