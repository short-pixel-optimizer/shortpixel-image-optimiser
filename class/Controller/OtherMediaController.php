<?php
namespace ShortPixel\Controller;

if ( ! defined( 'ABSPATH' ) ) {
 exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Model\File\DirectoryOtherMediaModel as DirectoryOtherMediaModel;
use ShortPixel\Model\File\DirectoryModel as DirectoryModel;

use ShortPixel\Controller\OptimizeController as OptimizeController;

use ShortPixel\Helper\InstallHelper as InstallHelper;
use ShortPixel\Helper\UtilHelper as UtilHelper;


// Future contoller for the edit media metabox view.
class OtherMediaController extends \ShortPixel\Controller
{
    private $folderIDCache;
    private static $hasFoldersTable;
    private static $hasCustomImages;


    protected static $instance;

    public function __construct()
    {
        parent::__construct();
    }

    public static function getInstance()
    {
        if (is_null(self::$instance))
           self::$instance = new OtherMediaController();

        return self::$instance;
    }

    public function getFolderTable()
    {
        global $wpdb;
        return $wpdb->prefix . 'shortpixel_folders';
    }

    public function getMetaTable()
    {
        global $wpdb;
        return  $wpdb->prefix . 'shortpixel_meta';
    }

    // Get CustomFolder for usage.
    public function getAllFolders()
    {
        $folders = $this->getFolders();
        return $this->loadFoldersFromResult($folders);
        //return $folders;
    }

    public function getActiveFolders()
    {
      $folders = $this->getFolders(array('remove_hidden' => true));
      return $this->loadFoldersFromResult($folders);
    }

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

		public function getHiddenDirectoryIDS()
		{
      global $wpdb;

      $sql = 'SELECT id from ' . $wpdb->prefix  .'shortpixel_folders where status = -1';
      $results = $wpdb->get_col($sql);

			return $results;
		}


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

    public function getFolderByPath($path)
    {
       $folder = new DirectoryOtherMediaModel($path);
       return $folder;
    }


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

    /* Check if installation has custom image, or anything. To show interface */
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
        $count = $wpdb->get_var($sql); //$this->getFolders(['only_count' => true, 'remove_hidden' => true]);
			 }
       if ($count == 0)
        $result = false;
      else
        $result = true;

      self::$hasCustomImages = $result;

      return $result;
    }

		public function showMenuItem()
		{
			  $settings = \wpSPIO()->settings();
				if ( $settings->hideCustomMedia)
				{
					 return false;
				}
				return true;
		}

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

		// Recursive check if any of the directories is not addable. If so cancel the whole thing.
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

    // Main function to add a path to the Custom Media.
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

		/* New structure for folder refresing based on checked value in database + interval.  Via Scan interface
		*
		* @param $args Array  ( force true / false )
		* @return Array - Should return folder_id, folder_path, amount of new files / result / warning
		*/
		public function doNextRefreshableFolder($args = array())
		{
				$defaults = array(
						'force' => false,
						'interval' => apply_filters('shortpixel/othermedia/refreshfolder_interval', HOUR_IN_SECONDS),
				);

				$args = wp_parse_args($args, $defaults);

				global $wpdb;

				$folderTable = $this->getFolderTable();

				$tsInterval = UtilHelper::timestampToDB(time() - $args['interval']);
				$sql = ' SELECT id FROM ' . $folderTable . '	WHERE status >= 0 AND (ts_checked <= %s OR ts_checked IS NULL)';

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
					$message = print_f(__(' %s files added', 'shortpixel-image-optimiser'), ($new_count-$old_count));
				}
				else {
					$message = print_f(__(' %s files removed', 'shortpixel-image-optimiser'), ($old_count-$new_count));
				}

				$return['message'] = $message;

				return $return;
		}

		public function resetCheckedTimestamps()
		{
				global $wpdb;
				$folderTable = $this->getFolderTable();

			  $sql = 'UPDATE ' . $folderTable . ' set ts_checked = NULL ';
				$wpdb->query($sql);

		}

		/**
		 * Function to clean the folders and meta from unused stuff
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

    /* Check if this directory is part of the MediaLibrary */
    public function checkifMediaLibrary(DirectoryModel $directory)
    {
      $fs = \wpSPIO()->filesystem();
      $uploadDir = $fs->getWPUploadBase();
		  $wpUploadDir = wp_upload_dir(null, false);

			$is_year_based = (isset($wpUploadDir['subdir']) && strlen(trim($wpUploadDir['subdir'])) > 0) ? true : false;

        // if it's the uploads base dir, check if the library is year-based, then allow. If all files are in uploads root, don't allow.
      if ($directory->getPath() == $uploadDir->getPath() )
			{
				 if ($is_year_based)
				 {
					 	return false;
				 }
         return true;
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

      if (! is_null($postDir))
      {
         $postDir = rawurldecode($postDir);
         $children = explode('/', $postDir );

         foreach($children as $child)
         {
            if ($child == '.' || $child == '..')
              continue;

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

          //$dir = $fs->getDirectory($postDir);
    //      $files = $dirObj->getFiles();
          $subdirs = $fs->sortFiles($dirObj->getSubDirectories()); // runs through FS sort.


          foreach($subdirs as $index => $dir) // weed out the media library subdirectories.
          {
            $dirname = $dir->getName();
						// @todo This should probably be checked via getBackupDirectory or so, not hardcoded ShortipxelBackups
            if($dirname == 'ShortpixelBackups' || $this->checkifMediaLibrary($dir) )
            {
               unset($subdirs[$index]);
            }
          }

          if( count($subdirs) > 0 ) {

            //  echo "<ul class='jqueryFileTree'>";
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
                        //  echo "<li class='directory collapsed'><a rel=' " .esc_attr($htmlRel) . "'>" . esc_html($htmlName) . "</a></li>";
                        $htmlRel = esc_attr($htmlRel);
                       $folders[] = array(
                          'relpath' => $htmlRel,
                          'name' => esc_html($htmlName),
                          'type' => 'folder',
                          'is_active' => (true === $folderObj->get('in_db') && false === $folderObj->isRemoved()),
                       );
                  }

              }

          //    echo "</ul>";
          }
          elseif ($_POST['dir'] == '/')
          {
            $error['message'] = __('No Directories found that can be added to Custom Folders', 'shortpixel-image-optimiser');
            return $error;
            /*    echo "<ul class='jqueryFileTree'>";
            esc_html_e('No Directories found that can be added to Custom Folders', 'shortpixel-image-optimiser');
            echo "</ul>"; */
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

    /* Get the custom Folders from DB, put them in model
    @return Array  Array database result
    @todo Has been replaced by getItems in FoldersViewController
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


      private function hasFoldersTable()
      {
				return InstallHelper::checkTableExists('shortpixel_folders');
      }



} // Class
