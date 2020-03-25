<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


// extends DirectoryModel. Handles Shortpixel_meta database table
// Replacing main parts of shortpixel-folder
class DirectoryOtherMediaModel extends DirectoryModel
{

  protected $id = -1; // if -1, this might not exist yet in Dbase. Null is not used, because that messes with isset

  protected $status = 0;
  protected $fileCount = 0;
  protected $updated = 0;
  protected $created = 0;

  protected $is_nextgen;
  protected $in_db = false;


  protected $stats;

  /** Path or Folder Object, from SpMetaDao */
  public function __construct($path)
  {
    //$this->spMetaDao =

    if (is_object($path))
    {
       $folder = $path;
       $path = $folder->path;
       $this->loadFolder($folder);
    }
    else
    {
      $this->loadFolderbyPath($path);
    }

     parent::__construct($path);

  }

  private function loadFolderByPath($path)
  {
      $folder = \wpSPIO()->getShortPixel()->getSpMetaDao()->getFolder($path);
  }

  /** Loads from database into model, the extra data of this model. */
  private function loadFolder($folder)
  {
    if ($folder)
    {
      $this->id = $folder->id;
      if ($this->id > 0)
       $this->in_db = true;
      $this->updated = $folder->ts_updated;
      $this->status = $folder->status;
      $this->fileCount = $folder->file_count;
    }
  }

  public function getStatus()
  {
      return $this->status;
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
            'path' => $this->getPath(),
        );
        $result = \wpSPIO()->getShortPixel()->getSpMetaDao()->saveDirectory($args);
        if ($result) // reloading because action can create a new DB-entry, which will not be reflected (in id )
          $this->loadFolder($this->getPath());

        return $result;
  }

  /** Updates the updated variable on folder to indicating when the last file change was made
  * @return boolean  True if file were changed since last update, false if not
  */
  public function updateFileContentChange()
  {
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

      if (! $this->exists())
      {
        Notice::addError( sprintf(__('Folder %s does not exist! ', 'shortpixel-image-optimiser'), $this->getPath()) );
        return false;
      }
      if (! $this->is_writable())
      {
        Notice::addWarning( sprintf(__('Folder %s is not writeable. Please check permissions and try again.','shortpixel-image-optimiser'),$this->getPath()) );
      }

      $fs = \wpSPIO()->filesystem();

      $filter = ($time > 0)  ? array('date_newer' => $time) : array();
      $files = $fs->getFilesRecursive($this, $filter);

      $shortpixel = \wpSPIO()->getShortPixel();
      // check processable by invoking filter, for now processablepath takes only paths, not objects.
      $files = array_filter($files, function($file) use($shortpixel) { return $shortpixel->isProcessablePath($file->getFullPath());  });

      Log::addDebug('Refreshing from ' . $time . ', found Files for custom media ID ' . $this-> id . ' -> ' . count($files));

    //  $folderObj->setFileCount( count($files) );
      $this->fileCount = count($files);
      $this->save();

      \wpSPIO()->getShortPixel()->getSpMetaDao()->batchInsertImages($files, $this->id);
  }


  /* Get the custom Folders from DB, put them in model
  @return Array  Array of directoryOtherMediaModel
  */
  public static function get($args = array())
  {
    $defaults = array(
        'id' => false,  // Get folder by Id
        'status' => false, // not yet implemented.
    );

    $args = wp_parse_args($args, $defaults);

    $fs =  \wpSPIO()->fileSystem();
    $cache = new \ShortPixel\CacheController();

    $spMetaDao = \wpSPIO()->getShortPixel()->getSpMetaDao();

    if ($args['id'] !== false && $args['id'] > 0)
    {
        $folders = $spMetaDao->getFolderByID($args['id']);
    }
    else
    {

      $folders = $spMetaDao->getFolders();
    }

    $i = 0;
    $newfolders = array();
    foreach($folders as $index => $folder)
    {

      $dirObj = new DirectoryOtherMediaModel($folder);
      $dirObj->status = $folder->status;
      $dirObj->updated = $dirObj->DBtoTimestamp($folder->ts_updated);
      $dirObj->created = $dirObj->DBtoTimestamp($folder->ts_created);
      $dirObj->id = $folder->id;

      $newfolders[$i] = $dirObj; // $index is dbase id, we just want an array
      $i++;
    }

    return $newfolders;
  }




}
