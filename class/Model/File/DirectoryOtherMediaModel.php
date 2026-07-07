<?php
namespace ShortPixel\Model\File;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notice;

use \ShortPixel\Model\File\DirectoryModel as DirectoryModel;
use \ShortPixel\Model\Image\ImageModel as ImageModel;

use ShortPixel\Controller\QueueController as QueueController;
use ShortPixel\Controller\OtherMediaController as OtherMediaController;

/**
 * A directory registered in the SPIO custom-folders subsystem
 * (`shortpixel_folders` table).
 *
 * Extends the plain-directory {@see DirectoryModel} with the database
 * layer used by the "Other Media" pipeline: refresh / re-scan, per-folder
 * statistics, activation state, and the guard rails that keep the media
 * library, backup folder, and out-of-root paths from being registered.
 *
 * Populated either directly from a `shortpixel_folders` row (fast path,
 * used by list-loaders that already ran the query) or by-path from the
 * constructor.
 *
 * @package ShortPixel\Model\File
 */
class DirectoryOtherMediaModel extends DirectoryModel
{

  /**
   * Row id in `shortpixel_folders`. Defaults to `-1` (not `null`) so
   * `isset($this->id)` reliably returns true — the constructor's
   * loadFolder / loadFolderByPath overwrites this on a successful lookup.
   *
   * @var int
   */
  protected $id = -1;

  /** @var string|null Display name (falls back to `basename($path)` when not stored on the row). */
  protected $name;

  /** @var int Folder-status code — one of the DIRECTORY_STATUS_* constants. */
  protected $status = 0;

  /**
   * File count cached on the DB row.
   *
   * @var int
   * @deprecated The DB column is only updated by refreshFolder() (a
   *             relatively heavy call) — insert/batch flows leave it
   *             stale. Prefer `getStats()['total']` for a live count.
   */
  protected $fileCount = 0;

  /** @var int Unix timestamp — when the folder was last refreshed (files added, subfolders walked). */
  protected $updated = 0;

  /** @var int Unix timestamp — when the folder was first inserted into `shortpixel_folders`. */
  protected $created = 0;

  /** @var int Unix timestamp — when the folder's mtime tree was last checked for changes. */
  protected $checked = 0;

  /** @var string|null MD5 hash of the folder path (legacy field). */
  protected $path_md5;

  /** @var bool True when the folder came from the NextGen Gallery integration. */
  protected $is_nextgen = false;

  /** @var bool True once the folder has been resolved against `shortpixel_folders`. */
  protected $in_db = false;

  /** @var bool True when status = DIRECTORY_STATUS_REMOVED (soft-deleted). */
  protected $is_removed = false;

  /** @var string|null Last diagnostic message set by checkDirectory / refreshFolder. */
  protected $last_message;

  /**
   * Per-request stats cache keyed by folder id. Populated on first call
   * to getAllStats() from a single grouped SQL query so per-folder
   * lookups don't each round-trip the DB.
   *
   * @var array<int, array{optimized: int, waiting: int, total: int}>|null
   */
	protected static $stats;

  /** Folder is soft-deleted (kept as a row so historical optimizations can still be looked up). */
  const DIRECTORY_STATUS_REMOVED = -1;
  /** Folder is a regular custom-folder registered by the user. */
  const DIRECTORY_STATUS_NORMAL = 0;
  /** Folder is a NextGen Gallery folder, discovered via the NextGen integration. */
  const DIRECTORY_STATUS_NEXTGEN = 1;

  /**
   * Constructor.
   *
   * Accepts either a path string (the model self-loads via
   * loadFolderByPath) or a raw DB-row-shaped object (typically supplied
   * by SpMetaDao when it has already run the query for a list of
   * folders — saves a per-instance round-trip).
   *
   * @param string|object $path Filesystem path OR a `shortpixel_folders` row object.
   */
  public function __construct($path)
  {

    if (is_object($path) && isset($path->path)) // Load directly via Database object, this saves a query.
    {
       $folder = $path;
       $path = $folder->path;

       parent::__construct($path);
       $this->loadFolder($folder);
    }
    else
    {
      parent::__construct($path);
      $this->loadFolderByPath($path);
    }
  }


  /**
   * Read-only accessor for declared properties (avoids exposing them
   * publicly). Returns null for unknown names.
   *
   * @param string $name Property name.
   * @return mixed|null
   */
  public function get($name)
  {
     if (property_exists($this, $name))
      return $this->$name;

     return null;
  }

  /**
   * Setter for declared properties.
   *
   * @param string $name  Property name.
   * @param mixed  $value Value to assign.
   * @return bool True when the property exists and was assigned, false otherwise.
   */
  public function set($name, $value)
  {
     if (property_exists($this, $name))
     {
        $this->$name = $value;
        return true;
     }

     return false;
  }

  /**
   * Return per-folder statistics for every folder in one grouped query,
   * memoised on the static `$stats` cache for the rest of the request.
   *
   * The status codes summed here:
   *   - `2` (FILE_STATUS_SUCCESS) and `-11` (FILE_STATUS_MARKED_DONE) → optimized
   *   - `0` (FILE_STATUS_UNPROCESSED) → waiting
   *
   * @return array<int, array{optimized: int, waiting: int, total: int}> Stats keyed by `folder_id`.
   */
	public static function getAllStats()
	{
			if (is_null(self::$stats))
			{
				global $wpdb;
			 	$sql = 'SELECT SUM(CASE WHEN status = 2 OR status = -11 THEN 1 ELSE 0 END) optimized, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) waiting, count(*) total, folder_id FROM  ' . $wpdb->prefix . 'shortpixel_meta GROUP BY folder_id';

				$result = $wpdb->get_results($sql, ARRAY_A);

				$stats = array();
				foreach($result as $rawdata)
				{
					 $folder_id = $rawdata['folder_id'];

           $data = array(
             'optimized' => (int) $rawdata['optimized'],
             'waiting' => (int) $rawdata['waiting'],
             'total' => (int) $rawdata['total'],
           );
					 $stats[$folder_id] = $data;
				}

				self::$stats = $stats;
			}

		 return self::$stats;
	}

  /**
   * Return the stats bucket for THIS folder.
   *
   * Fast path: consults `getAllStats()`'s bulk cache. When the folder
   * isn't present in the bulk result (typically because it was just
   * inserted or is not part of the last grouping), falls back to a
   * per-folder SQL query.
   *
   * @return array{optimized: int, waiting: int, total: int}|false Stats
   *         bucket, or false when no rows exist and the per-folder query
   *         returned nothing.
   */
  public function getStats()
  {
			$stats = self::getAllStats();  // Querying all stats is more efficient than one-by-one

			if (isset($stats[$this->id]))
			{
				 return $stats[$this->id];
			}
			else {
				global $wpdb;
	      $sql = "SELECT SUM(CASE WHEN status = 2 OR status = -11 THEN 1 ELSE 0 END) optimized, "
	          . "SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) waiting, count(*) total "
	          . "FROM  " . $wpdb->prefix . "shortpixel_meta "
	          . "WHERE folder_id = %d";
	      $sql = $wpdb->prepare($sql, $this->id);
	      $res = $wpdb->get_row($sql, ARRAY_A);

        if (is_array($res))
        {
          $result = array(
            'optimized' => (int) $res['optimized'],
            'waiting' => (int) $res['waiting'],
            'total' => (int) $res['total'],

          );
          return $result;
        }
        else {
          return false;
        }

			}

  }

  /**
   * Persist this folder's state to `shortpixel_folders`, inserting a new
   * row when `in_db` is false or updating the existing one otherwise.
   *
   * Race protection: even on the "new" branch, one more `loadFolderByPath`
   * probe runs first so that if a parallel process (e.g. NextGen's own
   * folder discovery) already inserted the row, we UPDATE instead of
   * INSERTing a duplicate. On successful insert, a follow-up
   * `loadFolderByPath` refreshes `$this->id` from the newly-created row.
   *
   * @return int|false Number of rows affected on UPDATE, insert-id on
   *                   INSERT, or false when the wpdb call failed.
   */
  public function save()
  {
    // Simple Update
      global $wpdb;
        $data = array(
        //    'id' => $this->id,
            'status' => $this->status,
            'file_count' => $this->fileCount,
            'ts_updated' => $this->timestampToDB($this->updated),
            'ts_checked' => $this->timestampToDB($this->checked),
            'name' => $this->name,
            'path' => $this->getPath(),
        );
        $format = array('%d', '%d', '%s', '%s', '%s', '%s');
        $table = $wpdb->prefix . 'shortpixel_folders';

        $is_new = false;
				$result = false;

        if ($this->in_db) // Update
        {
            $result = $wpdb->update($table, $data, array('id' => $this->id), $format);
        }
        else // Add new
        {
					 // Fallback.  In case some other process adds it. This happens with Nextgen.
						if (true === $this->loadFolderByPath($this->getPath()))
						{
								$result = $wpdb->update($table, $data, array('id' => $this->id), $format);
						}
						else
						{
              $data['ts_created'] = $this->timestampToDB(time());
							$this->id = $wpdb->insert($table, $data);
							if ($this->id !== false)
							{
								$is_new = true;
								$result = $this->id;
							}
						}
        }

				// reloading because action can create a new DB-entry, which will not be reflected (in id )
        if ($is_new)
				{
        	$this->loadFolderByPath($this->getPath());
				}

				return $result;
  }

  /**
   * Remove this folder from the SPIO custom-folders subsystem.
   *
   * Two-phase behaviour so historical optimizations aren't lost:
   *   1. Delete every `shortpixel_meta` row for this folder whose status
   *      is NOT `FILE_STATUS_SUCCESS` (2). Optimized images are kept so
   *      the user can still restore them from backup after "deleting"
   *      the folder.
   *   2. If any rows remain (i.e. optimizations were preserved), the
   *      folder row is soft-deleted by setting `status = -1`
   *      (DIRECTORY_STATUS_REMOVED). Otherwise, the folder row is
   *      hard-deleted.
   *
   * @return int|false Rows affected on the final update/delete, or
   *                   false on wpdb error.
   */
  public function delete()
  {
      $id = $this->id;
      if (! $this->in_db)
      {
         Log::addError('Trying to remove Folder without being in the database (in_db false) ' . $id, $this->getPath());
      }

      global $wpdb;
			$otherMedia = OtherMediaController::getInstance();

			// Remove all files from this folder that are not optimized.
			$sql = "DELETE FROM " . $otherMedia->getMetaTable() . ' WHERE status <> 2 and folder_id = %d';
			$sql = $wpdb->prepare($sql, $this->id);
			$wpdb->query($sql);

			// Check if there are any images left.
			$sql = 'SELECT count(id) FROM ' . $otherMedia->getMetaTable() . ' WHERE folder_id = %d';
			$sql = $wpdb->prepare($sql, $this->id);
			$numImages = $wpdb->get_var($sql);

			if ($numImages > 0)
			{
					$sql = 'UPDATE ' . $otherMedia->getFolderTable() . ' SET status = -1 where id = %d';
					$sql = $wpdb->prepare($sql, $this->id);
					$result = $wpdb->query($sql);
			}
			else
			{
		      $sql = 'DELETE FROM ' . $otherMedia->getFolderTable() . ' where id = %d';
		      $sql = $wpdb->prepare($sql, $this->id);
		      $result = $wpdb->query($sql);
			}

			return $result;
  }

  /**
   * Whether this folder has been soft-deleted (status = DIRECTORY_STATUS_REMOVED).
   *
   * @return bool
   */
  public function isRemoved()
  {
      if ($this->is_removed)
        return true;
      else
        return false;
  }

  /**
   * Walk the folder tree with `recurseLastChangeFile()`, update
   * `$this->updated` with the newest mtime found, and persist.
   *
   * NOTE: `recurseLastChangeFile()` only inspects directory mtimes (not
   * individual file mtimes) — this is normally sufficient because most
   * filesystems bump the containing directory's mtime when a file
   * changes, but exotic mounts (some SSHFS / network volumes) may not.
   *
   * @return bool True when a change was detected since the last update
   *              (i.e. mtime moved forward), false otherwise.
   */
  public function updateFileContentChange()
  {
      if (! $this->exists() )
        return false;

      $old_time = $this->updated;

      $time = $this->recurseLastChangeFile();
      $this->updated = $time;
      if (! $this->save())
      {
          return false;
      }

      if ($old_time !== $time)
        return true;
      else
        return false;
  }



  /**
   * Re-scan the folder, register any new / changed files with the queue,
   * and update the folder's row.
   *
   * Flow:
   *   1. `checkDirectory(true)` (silent) rejects folders that shouldn't
   *      be here (media library, backup dir, outside root, unwritable).
   *   2. Skip when we have no DB row yet (`id <= 0`) — the caller
   *      should have persisted the folder first.
   *   3. Filter to files newer than `$this->updated` unless `$force=true`.
   *   4. Only include files whose extensions are in
   *      `ImageModel::PROCESSABLE_EXTENSIONS`; explicitly exclude `.avif`.
   *   5. Hand off to `addImages()` which stages the discovered files
   *      into the queue.
   *   6. Reset the static stats cache for this folder and reload
   *      `fileCount` from a fresh grouped query.
   *   7. Stamp `$this->checked = time()` and save.
   *
   * @param bool $force When true, ignore `$this->updated` and re-scan
   *                    every file. Used by the user-triggered "refresh"
   *                    action in the admin UI.
   * @return array{optimized: int, waiting: int, total: int, new: int}|false
   *                   Post-refresh stats bucket with an added `new` key
   *                   (difference against the pre-refresh total), or
   *                   false when any of the guard checks failed.
   */
  public function refreshFolder($force = false)
  {
      if ($force === false)
      {
        $time = $this->updated;
      }
      else
      {
        $time = 0; //force refresh of the whole.
      }

      $stats = $this->getStats();
      $total_before = $stats['total'];

			if (false === $this->checkDirectory(true))
			{
				Log::addWarn('Refreshing directory, something wrong in checkDirectory ' . $this->getPath(), $this->last_message);

				return false;
			}

      if ($this->id <= 0)
      {
        Log::addWarn('FolderObj from database is not there, while folder seems ok ' . $this->getPath() );
        return false;
      }
      elseif (! $this->exists())
      {
				$message = sprintf(__('Folder %s does not exist! ', 'shortpixel-image-optimiser'), $this->getPath());
				$this->last_message = $message;
        Notice::addError( $message );
        return false;
      }
      elseif (! $this->is_writable())
      {
				$message = sprintf(__('Folder %s is not writeable. Please check permissions and try again.','shortpixel-image-optimiser'),$this->getPath());
				$this->last_message = $message;
        Notice::addWarning( $message );
        return false;
      }

      $fs = \wpSPIO()->filesystem();
      $filter = ($time > 0)  ? array('date_newer' => $time) : array();
      $filter['exclude_files'] = array('.avif');
			$filter['include_files'] = ImageModel::PROCESSABLE_EXTENSIONS;

      $files = $fs->getFilesRecursive($this, $filter);

      \wpSPIO()->settings()->hasCustomFolders = time(); // note, check this against bulk when removing. Custom Media Bulk depends on having a setting.

    	$result = $this->addImages($files);

    	// Reset stat.
			unset(self::$stats[$this->id]);

      $stats = $this->getStats();
      $this->fileCount = $stats['total'];

      $this->checked = time();
      $this->save();

      $stats['new'] = $stats['total'] - $total_before;

      return $stats;
  }


	/**
	 * Whether this directory is eligible to be registered as a custom
	 * folder.
	 *
	 * Rejection reasons, in order:
	 *   - Directory does not exist on disk.
	 *   - Directory is outside the WordPress root path.
	 *   - Directory is (or is inside) the ShortPixel backup folder.
	 *   - Directory contains Media Library images (delegated to
	 *     `OtherMediaController::checkIfMediaLibrary`).
	 *   - Directory is not writable.
	 *   - Directory is a subfolder of an already-registered custom folder.
	 *
	 * On rejection, a diagnostic message is stored on `$last_message` and
	 * (unless `$silent`) a `Notice::addError` is raised for the admin UI.
	 *
	 * @param bool $silent When true, skip the notice emission — used by
	 *                     `refreshFolder()` where the caller handles UI
	 *                     messages itself.
	 * @return bool True when eligible.
	 */
	public function checkDirectory($silent = false)
	{
			$fs = \wpSPIO()->filesystem();
       $rootDir = $fs->getWPFileBase();
       $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);
			 $otherMediaController = OtherMediaController::getInstance();

       if (! $this->exists())
       {
				 $message = sprintf(__('Could not be added, directory not found: %s ','shortpixel-image-optimiser'),  $this->getPath() );
				 $this->last_message = $message;

				 if (false === $silent)
				 {
          Notice::addError($message);
				 }
          return false;
       }
       elseif (! $this->isSubFolderOf($rootDir) && $this->getPath() != $rootDir->getPath() )
       {
				 $message = sprintf(__('The %s folder cannot be processed as it\'s not inside the root path of your website (%s).','shortpixel-image-optimiser'),$this->getPath(), $rootDir->getPath());
				 $this->last_message = $message;

				 if (false === $silent)
			 	 {
          Notice::addError( $message );
				}
          return false;
       }
       elseif($this->isSubFolderOf($backupDir) || $this->getPath() == $backupDir->getPath() )
       {
				 $message = __('This folder contains the ShortPixel Backups. Please select a different folder.','shortpixel-image-optimiser');
				 $this->last_message = $message;

				 if (false === $silent)
				 {
          Notice::addError( $message );
				}
          return false;
       }
       elseif( $otherMediaController->checkIfMediaLibrary($this) )
       {
				 $message = __('This folder contains Media Library images. To optimize Media Library images please go to <a href="upload.php?mode=list">Media Library list view</a> or to <a href="upload.php?page=wp-short-pixel-bulk">ShortPixel Bulk page</a>.','shortpixel-image-optimiser');
				 $this->last_message = $message;

				 if (false === $silent)
				 {
          Notice::addError($message);
				}
          return false;
       }
       elseif (! $this->is_writable())
       {
				 $message = sprintf(__('Folder %s is not writeable. Please check permissions and try again.','shortpixel-image-optimiser'),$this->getPath());
				 $this->last_message = $message;

				 if (false === $silent)
				 {
         	Notice::addError( $message );
			 	 }
         return false;

       }
			 else
			 {
				 $folders = $otherMediaController->getAllFolders();

				 foreach($folders as $folder)
				 {
					   if ($this->isSubFolderOf($folder))
						 {

							 if (false === $silent)
							 {
							  Notice::addError(sprintf(__('This folder is a subfolder of an already existing Other Media folder. Folder %s can not be added', 'shortpixel-image-optimiser'), $this->getPath() ));
							 }
								return false;
						 }
				 }
			 }

			 return true;
	}


    /**
     * Walk the directory tree recursively and return the newest mtime
     * seen across every directory (including this one).
     *
     * NOTE: only inspects DIRECTORY mtimes — individual regular files
     * are not stat'd. This is a deliberate perf trade-off: on most
     * filesystems a file change bumps the containing directory's mtime
     * anyway.
     *
     * Unreadable directories short-circuit to the passed-in mtime,
     * making the method safe against permission errors mid-walk.
     *
     * @param int $mtime Highest mtime seen so far (initial call: 0).
     * @return int Highest directory mtime discovered.
     */
    private function recurseLastChangeFile($mtime = 0)
    {
      $ignore = array('.','..');

			// Directories without read rights should not be checked at all.
			if (! $this->is_readable())
				return $mtime;

      $path = $this->getPath();

      $files = scandir($path);

			// no files, nothing to update.
			if (! is_array($files))
			{
					return $mtime;
			}

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

    /**
     * Convert a Unix timestamp to the `Y-m-d H:i:s` string shape the
     * `shortpixel_folders` datetime columns expect.
     *
     * An empty timestamp (null / false / '' / 0) falls back to time() —
     * the datetime columns are non-null, so "unset" callers still get a
     * useful value. Literal epoch (0) is treated as "unset" too.
     *
     * @param int|null $timestamp Unix timestamp; empty substitutes `time()`.
     * @return string MySQL-shaped datetime.
     */
    private function timestampToDB($timestamp)
    {
        if (empty($timestamp)) // when adding / or empty.
          $timestamp = time();
        return date("Y-m-d H:i:s", $timestamp);
    }

    /**
     * Convert a `Y-m-d H:i:s` datetime string from the DB back to a
     * Unix timestamp. Null input falls back to `time()`.
     *
     * @param string|null $date Datetime string, or null.
     * @return int Unix timestamp.
     */
    private function DBtoTimestamp($date)
    {
        if (is_null($date))
        {
            $timestamp = time();
        }
        else {
            $timestamp =strtotime($date);
        }
        return $timestamp;
    }

  /**
   * Stage a batch of discovered file objects into the queue.
   *
   * For each file:
   *   - If the file already has a `shortpixel_meta` row and its
   *     recorded `folder_id` points at a folder that is no longer
   *     active (removed / inactive), reassign it to this folder.
   *   - If the file is new and passes `isProcessable()`, insert the
   *     stub with this folder's id and (when `is_autoprocess` is on)
   *     add it to the queue.
   *
   * Also fires the `shortpixel/othermedia/addfiles` filter so
   * integrations can short-circuit the whole batch by returning false.
   *
   * @param array $files Array of FileModel-shaped objects (typically
   *                     the output of `Filesystem::getFilesRecursive`).
   * @return bool False when the pre-filter vetoes the batch; true after
   *              the batch has been processed (even if no files were
   *              actually queued — e.g. all were already in the DB).
   *
   * @internal Called by OtherMediaController / refreshFolder — other
   *           scripts should not call this directly.
   */
  public function addImages($files) {

			if ( apply_filters('shortpixel/othermedia/addfiles', true, $files, $this) === false)
			{
				 return false;
			}

      $queueControl = new QueueController();
			$otherMediaControl = OtherMediaController::getInstance();
			$activeFolders = $otherMediaControl->getActiveDirectoryIDS();

      $fs = \wpSPIO()->filesystem();
			$updated = false;

      foreach($files as $fileObj)
      {
					// Note that load is set to false here.
          $imageObj = $fs->getCustomStub($fileObj->getFullPath(), false);

					// image already exists
          if ($imageObj->get('in_db') == true)
					{
						// Load meta to make it check the folder_id.
						$imageObj->loadMeta();

						// Check if folder id is something else. This might indicate removed or inactive folders.
						// If in inactive folder, move to current active.
						if ($imageObj->get('folder_id') !== $this->id)
						{
							 if (! in_array($imageObj->get('folder_id'), $activeFolders) )
							 {
								   $imageObj->setFolderId($this->id);
									 $imageObj->saveMeta();
									 $updated = true;
							 }
						}

						// If in Db, but not optimized and autoprocess is on; add to queue for optimizing
						if (\wpSPIO()->env()->is_autoprocess && $imageObj->isProcessable())
						{
							 $queueControl->addItemToQueue($imageObj);
						}

            continue;
					}
          elseif ($imageObj->isProcessable()) // Check strict on Processable here.
          {
  	         $imageObj->setFolderId($this->id);
             $imageObj->saveMeta();
						 $updated = true;

             if (\wpSPIO()->env()->is_autoprocess)
             {
                $queueControl->addItemToQueue($imageObj);
             }
          }

      }

			if (true === $updated)
			{
				$this->updated = time();
			}

			return true;
  }


    /**
     * Look up a folder in `shortpixel_folders` by its path column and
     * hydrate this instance from the resulting row (via `loadFolder()`)
     * if one is found.
     *
     * @param string $path Filesystem path.
     * @return bool True when a matching row was found and loaded.
     */
    private function loadFolderByPath($path)
    {
        //$folders = self::getFolders(array('path' => $path));
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

    /**
     * Hydrate this instance from a `shortpixel_folders` row object.
     *
     * Behaviour worth noting:
     *   - `in_db` is set to true only when `$folder->id > 0` — a stub
     *     row with id = 0 stays "not in DB" from the model's perspective.
     *   - Timestamp columns are optional on the input object; missing
     *     ones fall back to `time()` via `DBtoTimestamp(null)`.
     *   - `name` falls back to `basename($folder->path)` when the stored
     *     name is empty.
     *   - Fires the `shortpixel/othermedia/folder/load` action so
     *     integrations can enrich the model before status-derived flags
     *     (`is_removed`, `is_nextgen`) are computed.
     *
     * @param object $folder A `shortpixel_folders`-shaped row.
     * @return void
     */
    private function loadFolder($folder)
    {
      //  $class = get_class($folder);
				// Setters before action
        $this->id = $folder->id;

        if ($this->id > 0)
         $this->in_db = true;

        $this->updated = property_exists($folder,'ts_updated') ? $this->DBtoTimestamp($folder->ts_updated) : time();
        $this->created = property_exists($folder,'ts_created') ? $this->DBtoTimestamp($folder->ts_created) : time();
        $this->checked = property_exists($folder,'ts_checked') ? $this->DBtoTimestamp($folder->ts_checked) : time();
        $this->fileCount = property_exists($folder,'file_count') ? $folder->file_count : 0; // deprecated, do not rely on.

        $this->status = $folder->status;

        if (strlen($folder->name) == 0)
          $this->name = basename($folder->path);
        else
          $this->name = $folder->name;

        do_action('shortpixel/othermedia/folder/load', $this->id, $this);

				// Making conclusions after action.
        if ($this->status == -1)
          $this->is_removed = true;

        if ($this->status == self::DIRECTORY_STATUS_NEXTGEN)
        {
          $this->is_nextgen = true;
        }

    }

}
