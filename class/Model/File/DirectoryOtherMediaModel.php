<?php
namespace ShortPixel\Model\File;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use \ShortPixel\Model\File\DirectoryModel as DirectoryModel;

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

    if (is_object($path)) // Load directly via Database object, this saves a query.
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


  public function get($name)
  {
     if (property_exists($this, $name))
      return $this->$name;

     return null;
  }

  public function set($name, $value)
  {
     if (property_exists($this, $name))
     {
        $this->name = $value;
        return true;
     }

     return null;
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
        // @todo This should be done here
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

      // @todo This should be query here.
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

      //$shortpixel = \wpSPIO()->getShortPixel();
      // check processable by invoking filter, for now processablepath takes only paths, not objects.
      $files = array_filter($files, function($file) { // use($fs)
        $imageObj = $fs->getCustomStub($file);
        return $imageObj->isProcessable();
        //return $shortpixel->isProcessablePath($file->getFullPath());
       });

      Log::addDebug('Refreshing from ' . $time . ', found Files for custom media ID ' . $this-> id . ' -> ' . count($files));

    //  $folderObj->setFileCount( count($files) );

      \wpSPIO()->settings()->hasCustomFolders = time(); // note, check this against bulk when removing. Custom Media Bulk depends on having a setting.
      $result = $this->batchInsertImages($files);

      $stats = $this->getStats();
      $this->fileCount = $stats->Total;
      $this->save();

  }

  /** This function is called by OtherMediaController / RefreshFolders. Other scripts should not call it
  * @private
  */
  protected function batchInsertImages($files) {
      //facem un delete pe cele care nu au shortpixel_folder, pentru curatenie - am mai intalnit situatii in care stergerea s-a agatat (stop monitoring)
      global $wpdb;

      $sqlCleanup = "DELETE FROM {$this->db->getPrefix()}shortpixel_meta WHERE folder_id NOT IN (SELECT id FROM {$this->db->getPrefix()}shortpixel_folders)";
      $wpdb->query($sqlCleanup);

      $values = array();
      $sql = "INSERT IGNORE INTO {$this->db->getPrefix()}shortpixel_meta(folder_id, path, name, path_md5, status, ts_added) VALUES ";
      $format = '(%d,%s,%s,%s,%d,%s)';
      $i = 0;
      $count = 0;
      $placeholders = array();
      $status = (\wpSPIO()->settings()->autoMediaLibrary == 1) ? ShortPixelMeta::FILE_STATUS_PENDING : ShortPixelMeta::FILE_STATUS_UNPROCESSED;
      $created = date("Y-m-d H:i:s");

      foreach($files as $file) {
          $filepath = $file->getFullPath();
          $filename = $file->getFileName();

          array_push($values, $this->id, $filepath, $filename, md5($filepath), $status, $created);
          $placeholders[] = $format;

          if($i % 500 == 499) {
              $query = $sql;
              $query .= implode(', ', $placeholders);
              $wpdb->query( $wpdb->prepare("$query ", $values));

              $values = array();
              $placeholders = array();
          }
          $i++;
      }
      if(count($values) > 0) {
        $query = $sql;
        $query .= implode(', ', $placeholders);
        $result = $wpdb->query( $wpdb->prepare("$query ", $values) );
        Log::addDebug('Q Result', array($result, $wpdb->last_error));
        //$this->db->query( $this->db->prepare("$query ", $values));
        return $result;
      }
  }


    private function loadFolderByPath($path)
    {
        //$folders = self::getFolders(array('path' => $path));
         //s\wpSPIO()->getShortPixel()->getSpMetaDao()->getFolder($path);
         global $wpdb;

         $sql = 'SELECT * FROM ' . $wpdb->prefix . 'shortpixel_folders where path = %s ';
         $sql = $wpdb->prepare($sql, $path);

        $folder = $wpdb->get_row($sql);
        if (! is_object($folder))
          return false;
        else
        {
          $this->loadFolder($folder);
          $this->in_db = true; // exists in database
          return true;
        }
    }

    /** Loads from database into model, the extra data of this model. */
    private function loadFolder($folder)
    {

      if (is_object($folder))
      {
        // suboptimally, this function needs to work both with database record output and instances of itself
        $class = get_class($folder);

        $this->id = $folder->id;
        $this->in_db = true;

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

}
