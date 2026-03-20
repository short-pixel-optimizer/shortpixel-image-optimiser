<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\File\DirectoryOtherMediaModel as DirectoryOtherMediaModel;
use ShortPixel\Model\File\DirectoryModel as DirectoryModel;

use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;


/**
 * Controller for the Custom (Other) Media directory management.
 *
 * Manages the custom folder and custom image tables, handles adding directories and
 * individual images, folder scanning/refresh cycles, and the folder-browser UI data.
 *
 * @package ShortPixel\Controller
 */
class OtherMediaController extends \ShortPixel\Controller
{
    /** @var array|null Cached list of active folder IDs, populated on first request */
    private $folderIDCache;

    /** @var bool|null Cached flag indicating whether the shortpixel_folders table exists */
    private static $hasFoldersTable;

    /** @var bool|null Cached flag indicating whether any custom images exist in the database */
    private static $hasCustomImages;


    /** @var OtherMediaController|null Singleton instance */
    protected static $instance;



    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Return the singleton instance, creating it on first call.
     *
     * @return static The singleton OtherMediaController instance.
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
				 self::$instance = new static();

        return self::$instance;
    }

    /**
     * Return the fully-qualified name of the custom folders database table.
     *
     * @return string Table name including the wpdb prefix.
     */
    public function getFolderTable()
    {
        global $wpdb;
        return $wpdb->prefix . 'shortpixel_folders';
    }

    /**
     * Return the fully-qualified name of the custom media meta database table.
     *
     * @return string Table name including the wpdb prefix.
     */
    public function getMetaTable()
    {
        global $wpdb;
        return  $wpdb->prefix . 'shortpixel_meta';
    }

    /**
     * Retrieve all registered custom folders (including hidden/removed ones).
     *
     * @return DirectoryOtherMediaModel[] Array of directory model objects for all folders.
     */
    public function getAllFolders()
    {
        $folders = $this->getFolders();
        return $this->loadFoldersFromResult($folders);
        //return $folders;
    }

    /**
     * Retrieve only active (non-hidden) custom folders.
     *
     * @return DirectoryOtherMediaModel[] Array of directory model objects for active folders.
     */
    public function getActiveFolders()
    {
      $folders = $this->getFolders(array('remove_hidden' => true));
      return $this->loadFoldersFromResult($folders);
    }

    /**
     * Convert raw database result rows into DirectoryOtherMediaModel objects.
     *
     * @param array $folders Array of database row objects returned by getFolders().
     * @return DirectoryOtherMediaModel[] Array of directory model objects.
     */
    private function loadFoldersFromResult($folders)
    {
       $dirFolders = array();
       foreach($folders as $result)
       {
          $dirObj = new DirectoryOtherMediaModel($result);
          $dirFolders[] = $dirObj;
       }
       return $dirFolders;
    }

    /**
     * Return an array of IDs for all active (non-removed) custom folders.
     *
     * Results are cached in memory after the first call to avoid repeated queries.
     *
     * @return array Array of folder ID strings from the database.
     */
    public function getActiveDirectoryIDS()
    {
      if (! is_null($this->folderIDCache))
        return $this->folderIDCache;

      global $wpdb;

      $sql = 'SELECT id from ' . $wpdb->prefix  .'shortpixel_folders where status <> -1';
      $results = $wpdb->get_col($sql);

      $this->folderIDCache = $results;
      return $this->folderIDCache;
    }

    /**
     * Return an array of IDs for all hidden (status = -1) custom folders.
     *
     * @return array Array of folder ID strings from the database.
     */
		public function getHiddenDirectoryIDS()
		{
      global $wpdb;

      $sql = 'SELECT id from ' . $wpdb->prefix  .'shortpixel_folders where status = -1';
      $results = $wpdb->get_col($sql);

			return $results;
		}

    /**
     * Retrieve a single custom folder by its database ID.
     *
     * @param int $id The folder's database ID.
     * @return DirectoryOtherMediaModel|false The folder model, or false if not found.
     */
    public function getFolderByID($id)
    {
        $folders = $this->getFolders(array('id' => $id));

        if (count($folders) > 0)
        {
          $folders = $this->loadFoldersFromResult($folders);
          return array_pop($folders);
        }
        return false;
    }

    /**
     * Return a DirectoryOtherMediaModel for the given filesystem path.
     *
     * @param string $path Full filesystem path to the folder.
     * @return DirectoryOtherMediaModel The directory model for the given path.
     */
    public function getFolderByPath($path)
    {
       $folder = new DirectoryOtherMediaModel($path);
       return $folder;
    }

    /**
     * Look up a custom image model by its filesystem path.
     *
     * Queries the meta table for a matching path; if found, returns the full image model.
     * If not found in the database, returns a stub model for the path.
     *
     * @param string $path Full filesystem path to the image file.
     * @return CustomImageModel A loaded custom image model or a stub model when not in DB.
     */
    public function getCustomImageByPath($path)
    {
         global $wpdb;
         $sql = 'SELECT id FROM ' . $this->getMetaTable() . ' WHERE path = %s';
         $sql = $wpdb->prepare($sql, $path);

         $custom_id = $wpdb->get_var($sql);
         $fs = \wpSPIO()->filesystem();

         if (! is_null($custom_id))
         {
            return $fs->getImage($custom_id, 'custom');
         }
         else
            return $fs->getCustomStub($path); // stub
    }

    /**
     * Check whether any custom images are present in the database.
     *
     * Result is cached statically to prevent repeated queries in the same request.
     *
     * @return bool True if at least one custom image record exists, false otherwise.
     */
    public function hasCustomImages()
    {
       if (! is_null(self::$hasCustomImages)) // prevent repeat
         return self::$hasCustomImages;

			if (InstallHelper::checkTableExists('shortpixel_meta') === false)
				$count = 0;
			else
			{
				global $wpdb;

				$sql = 'SELECT count(id) as count from ' . $wpdb->prefix . 'shortpixel_meta';
        $count = $wpdb->get_var($sql);
			 }
       if ($count == 0)
        $result = false;
      else
        $result = true;

      self::$hasCustomImages = $result;

      return $result;
    }

    /**
     * Determine whether the Custom Media admin menu item should be shown.
     *
     * @return bool True when the 'showCustomMedia' setting is enabled.
     */
		public function showMenuItem()
		{
			  $settings = \wpSPIO()->settings();
				if ( $settings->showCustomMedia)
				{
					 return true;
				}
				return false;
		}

    /**
     * Register a filesystem directory as a custom media folder.
     *
     * Validates the directory and all subdirectories before saving. If the directory
     * is already registered and active, it is refreshed rather than re-added. If it
     * was previously removed, it is restored and refreshed.
     *
     * @param string $path Full filesystem path to the directory to add.
     * @return DirectoryOtherMediaModel|false The saved directory model, or false on failure.
     */
	   public function addDirectory($path)
    {
       $fs = \wpSPIO()->filesystem();
       $directory = new DirectoryOtherMediaModel($path);

			 // Check if this directory is allowed.
			 if ($this->checkDirectoryRecursive($directory) === false)
			 {
         Log::addDebug('Check Recursive Directory not allowed');
				 return false;
			 }

       if (! $directory->get('in_db'))
       {
         if ($directory->save())
         {
					$this->folderIDCache = null;
          $directory->refreshFolder(true);
          $directory->updateFileContentChange();
         }
       }
       else // if directory is already added, fail silently, but still refresh it.
       {
         if ($directory->isRemoved())
         {
					 	$this->folderIDCache = null;
            $directory->set('status', DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL);
						$directory->refreshFolder(true);
            $directory->updateFileContentChange(); // does a save. Dunno if that's wise.
         }
         else
          $directory->refreshFolder(false);
       }

      if ($directory->exists() && $directory->get('id') > 0)
        return $directory;
      else
        return false;
    }

    /**
     * Recursively verify that a directory and all its subdirectories are eligible for addition.
     *
     * Returns false as soon as any directory in the tree fails the eligibility check,
     * preventing partial additions.
     *
     * @param DirectoryOtherMediaModel $directory The root directory to check.
     * @return bool True if all directories in the tree are allowed, false otherwise.
     */
		public function checkDirectoryRecursive($directory)
		{
				 if ($directory->checkDirectory() === false)
				 {
				 	return false;
				 }

				 $subDirs = $directory->getSubDirectories();
				 foreach($subDirs as $subDir)
				 {
					  if ($subDir->checkDirectory(true) === false)
						{
							 return false;
						}
						else
						{
							 $result = $this->checkDirectoryRecursive($subDir);
							 if ($result === false)
							 {
							 	return $result;
							}
						}

				 }

				 return true;
		}

    /**
     * Add a single image file to the custom media system.
     *
     * Accepts either a file path string or a FileModel object. The parent folder is
     * created in the database if it does not yet exist. Supports marking the folder
     * as a NextGEN Gallery source via args.
     *
     * @param string|\ShortPixel\Model\File\FileModel $path_or_file Full path or FileModel of the image to add.
     * @param array $args {
     *     Optional. Additional arguments.
     *
     *     @type bool $is_nextgen Whether this image belongs to a NextGEN Gallery folder. Default false.
     * }
     * @return void
     */
    public function addImage($path_or_file, $args = array())
    {
        $defaults = array(
          'is_nextgen' => false,
        );

        $args = wp_parse_args($args, $defaults);

        $fs = \wpSPIO()->filesystem();

        if (is_object($path_or_file)) // assume fileObject
        {
				  $file = $path_or_file;
			}
        else
        {
           $file = $fs->getFile($path_or_file);
        }
        $folder = $this->getFolderByPath( (string) $file->getFileDir());

        if ($folder->get('in_db') === false)
				{
            if ($args['is_nextgen'] == true)
            {
               $folder->set('status', DirectoryOtherMediaModel::DIRECTORY_STATUS_NEXTGEN );
            }
            $folder->save();
        }

        $folder->addImages(array($file));

    }

    /**
     * Refresh the next custom folder that is due for a content scan.
     *
     * Selects the folder with the oldest (or null) ts_checked timestamp that has not
     * exceeded the configured interval, refreshes it, and returns a summary of changes.
     *
     * @param array $args {
     *     Optional. Arguments controlling the refresh behaviour.
     *
     *     @type bool $force    Force a refresh even if the interval has not elapsed. Default false.
     *     @type int  $interval Minimum seconds between refreshes. Default HOUR_IN_SECONDS.
     * }
     * @return array|false Associative array with keys folder_id, old_count, new_count, path, message;
     *                     or false when no folder needs refreshing.
     */
		public function doNextRefreshableFolder($args = array())
		{
				$defaults = array(
						'force' => false,
						'interval' => HOUR_IN_SECONDS,
				);

				$args = wp_parse_args($args, $defaults);

        $args['interval'] = apply_filters('shortpixel/othermedia/refreshfolder_interval', $args['interval'], $args);
				global $wpdb;

				$folderTable = $this->getFolderTable();

				$tsInterval = UtilHelper::timestampToDB(time() - $args['interval']);
				$sql = ' SELECT id FROM ' . $folderTable . '	WHERE status >= 0 AND (ts_checked <= %s OR ts_checked IS NULL) order by ts_checked ASC';

				$sql = $wpdb->prepare($sql, $tsInterval);
				$folder_id = $wpdb->get_var($sql);

				if (is_null($folder_id))
				{
					 return false;
				}

				$directoryObj = $this->getFolderByID($folder_id);
				$old_count = $directoryObj->get('fileCount');

				$return = array(
					'folder_id' => $folder_id,
					'old_count' => $old_count,
					'new_count' => null,
					'path' => $directoryObj->getPath(),
					'message' => '',
				);

				// Always force here since last updated / interval is decided by interal on the above query
				$result = $directoryObj->refreshFolder($args['force']);

				if (false === $result)
				{
					 $directoryObj->set('checked', time()); // preventing loops here in case some wrong
					 $directoryObj->save();
					 // Probably should catch some notice here to return  @todo
				}

				$new_count = $directoryObj->get('fileCount');
				$return['new_count'] = $new_count;

				if ($old_count == $new_count)
				{
					 $message = __('No new files added', 'shortpixel-image-optimiser');
				}
				elseif ($old_count < $new_count)
				{
					$message = sprintf(__(' %s files added', 'shortpixel-image-optimiser'), ($new_count-$old_count));
				}
				else {
					$message = sprintf(__(' %s files removed', 'shortpixel-image-optimiser'), ($old_count-$new_count));
				}

				$return['message'] = $message;

				return $return;
		}

    /**
     * Reset all ts_checked timestamps to NULL so every folder will be rescanned on the next tick.
     *
     * @return void
     */
		public function resetCheckedTimestamps()
		{
				global $wpdb;
				$folderTable = $this->getFolderTable();

			  $sql = 'UPDATE ' . $folderTable . ' set ts_checked = NULL ';
				$wpdb->query($sql);

		}

		/**
		 * Remove orphaned folder and meta records from the database.
		 *
		 * Deletes folder rows with a removed status (status < 0) that have no associated
		 * images remaining in the meta table.
		 *
		 * @return void
		 */
		protected function cleanUp()
		{
			 global $wpdb;
			 $folderTable = $this->getFolderTable();
			 $metaTable = $this->getMetaTable();

			 // Remove folders that are removed, and have no images in MetaTable.
			 $sql = " DELETE FROM $folderTable WHERE status < 0 AND id NOT IN ( SELECT DISTINCT folder_id FROM $metaTable)";
			 $result = $wpdb->query($sql);

		}

    private function checkDirStatus()
    {
        $status = 0;



        return $status;
    }

    /**
     * Check whether a given directory is part of the WordPress Media Library upload structure.
     *
     * Returns true only for year-based direct subdirectories of the uploads folder (e.g.
     * /wp-content/uploads/2024/), which are managed by WordPress and should not be added
     * as custom folders.
     *
     * @param DirectoryModel $directory The directory to test.
     * @return bool True if the directory is a WP Media Library year folder, false otherwise.
     */
    public function checkifMediaLibrary(DirectoryModel $directory)
    {
      $fs = \wpSPIO()->filesystem();
      $uploadDir = $fs->getWPUploadBase();
		  $wpUploadDir = wp_upload_dir(null, false);

			$is_year_based = (isset($wpUploadDir['subdir']) && strlen(trim($wpUploadDir['subdir'])) > 0) ? true : false;

        // if it's the uploads base dir, check if the library is year-based, then allow. If all files are in uploads root, don't allow.
      if ($directory->getPath() == $uploadDir->getPath() )
			{
        // uploads always return ok, since if not-date-based still have 'other' folders that might be relevant.
        return false;
				 /*if ($is_year_based)
				 {
					 	return false;
				 }
         return true; */
			}
      elseif (! $directory->isSubFolderOf($uploadDir))// The easy check. No subdir of uploads, no problem.
			{
         return false;
			}
      elseif ($directory->isSubFolderOf($uploadDir)) // upload subdirs come in variation of year or month, both numeric. Exclude the WP-based years
      {
					// Only check if direct subdir of /uploads/ is a number-based directory name. Don't bother with deeply nested dirs with accidental year.
					if ($directory->getParent()->getPath() !== $uploadDir->getPath())
					{
							return false;
					}

					$name = $directory->getName();
					if (is_numeric($name) && strlen($name) == 4) // exclude year based stuff.
				  {
						return true;
					}
					else {
						return false;
					}
			}
    }

    /**
     * Return the list of subdirectories under a given path for the folder-browser UI.
     *
     * Validates that the requested path is within the WordPress file base, then builds
     * an array of folder descriptors (relative path, name, active/disabled state) for
     * each immediate subdirectory. Returns an error array on permission or path failures.
     *
     * @param string|null $postDir URL-encoded relative path submitted by the browser UI.
     * @return array Array of folder descriptor arrays, or an error array with is_error and message keys.
     */
    public function browseFolder($postDir)
    {
      $error = array('is_error' => true, 'message' => '');

      if ( ! $this->userIsAllowed )  {
          $error['message'] = __('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser');
          return $error;
      }

      $fs = \wpSPIO()->filesystem();
      $rootDirObj = $fs->getWPFileBase();
      $path = $rootDirObj->getPath();

      $folders = array();

      if (! is_null($postDir) && strlen($postDir) > 0)
      {
         $postDir = rawurldecode($postDir);
         $children = explode('/', $postDir );

         foreach($children as $child)
         {
            if ($child == '.' || $child == '..')
            {
              continue;
            }
             $path .= '/' . $child;
         }
      }

      $dirObj = $fs->getDirectory($path);
      if ($dirObj->getPath() !== $rootDirObj->getPath() && ! $dirObj->isSubFolderOf($rootDirObj))
      {
        $error['message'] = __('This directory seems not part of WordPress', 'shortpixel-image-optimiser');
        return $error;
      }

      if( $dirObj->exists() ) {

          $subdirs = $fs->sortFiles($dirObj->getSubDirectories()); // runs through FS sort.

          if( count($subdirs) > 0 ) {
              $i = 0;

              foreach($subdirs as $dir ) {


                  $returnDir = substr($dir->getPath(), strlen($rootDirObj->getPath())); // relative to root.

                  $dirpath = $dir->getPath();
                  $dirname = $dir->getName();

                  $folderObj = $this->getFolderByPath($dirpath);

                  $htmlRel	= str_replace("'", "&apos;", $returnDir );
                  $htmlName	= htmlentities($dirname);
                  //$ext	= preg_replace('/^.*\./', '', $file);

                  if( $dir->exists() ) {
                      //KEEP the spaces in front of the rel values - it's a trick to make WP Hide not replace the wp-content path
                      //KEEP
                      $is_active = (true === $folderObj->get('in_db') && false === $folderObj->isRemoved());


                       $htmlRel = esc_attr($htmlRel);
                       $folders[$i] = array(
                          'relpath' => $htmlRel,
                          'name' => esc_html($htmlName),
                          'type' => 'folder',
                          'is_active' => (true === $folderObj->get('in_db') && false === $folderObj->isRemoved()),
                          'is_disabled' => false,
                          'fullpath' => $dirpath,
                       );


                       if($dirpath == trailingslashit(SHORTPIXEL_BACKUP_FOLDER) || $this->checkifMediaLibrary($dir) )
                       {
                          $folders[$i]['is_disabled']  = true;
                         // unset($subdirs[$index]);
                       }
                  }

                  $i++;
              }

          }
          elseif ($_POST['dir'] == '/')
          {
            $error['message'] = __('No Directories found that can be added to Custom Folders', 'shortpixel-image-optimiser');
            return $error;
          }
          else {
            $error['message'] = 'Nothing found';
            return $error;
          }
      }
      else {
        $error['message'] = 'Dir not existing';
        return $error;
      }

      return $folders;
    }

    /**
     * Query the custom folders table with optional filters and return raw result rows.
     *
     * Supports filtering by ID, path, and hidden status, as well as count-only queries
     * and limit/offset pagination. Returns an empty array (or 0 for count queries) when
     * the folders table does not exist.
     *
     * @param array $args {
     *     Optional. Query arguments.
     *
     *     @type int|false    $id            Filter by folder ID. Default false.
     *     @type bool         $remove_hidden Exclude folders with status = -1. Default true.
     *     @type string|false $path          Filter by exact folder path. Default false.
     *     @type bool         $only_count    Return an integer count instead of rows. Default false.
     *     @type int|false    $limit         Limit the number of results. Default false.
     *     @type int|false    $offset        Offset for paginated results. Default false.
     * }
     * @return array|int Array of result row objects, or an integer count when only_count is true.
     */
    private function getFolders($args = array())
    {
      global $wpdb;
      $defaults = array(
          'id' => false,  // Get folder by Id
          'remove_hidden' => true, // Query only active folders
          'path' => false,
          'only_count' => false,
          'limit' => false,
          'offset' => false,
      );

      $args = wp_parse_args($args, $defaults);

      if (! $this->hasFoldersTable())
      {
        if ($args['only_count'])
           return 0;
        else
          return array();
      }
      $fs =  \wpSPIO()->fileSystem();

      if ($args['only_count'])
        $selector = 'count(id) as id';
      else
        $selector = '*';

      $sql = "SELECT " . $selector . "  FROM " . $wpdb->prefix . "shortpixel_folders WHERE 1=1 ";
      $prepare = array();
    //  $mask = array();

      if ($args['id'] !== false && $args['id'] > 0)
      {
          $sql .= ' AND id = %d';
          $prepare[] = $args['id'];

      }
      elseif($args['path'] !== false && strlen($args['path']) > 0)
      {
          $sql .= ' AND path = %s';
          $prepare[] = $args['path'];
      }

      if ($args['remove_hidden'])
      {
          $sql .= " AND status <> -1";
      }

      if (count($prepare) > 0)
        $sql = $wpdb->prepare($sql, $prepare);

      if ($args['only_count'])
        $results = intval($wpdb->get_var($sql));
      else
        $results = $wpdb->get_results($sql);


      return $results;
    }


      /**
       * Check whether the shortpixel_folders database table exists.
       *
       * @return bool True if the table exists, false otherwise.
       */
      private function hasFoldersTable()
      {
				return InstallHelper::checkTableExists('shortpixel_folders');
      }



} // Class
