<?php
namespace ShortPixel\Controller;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;
use ShortPixel\Notices\NoticeController as Notices;

use ShortPixel\Model\File\DirectoryOtherMediaModel as DirectoryOtherMediaModel;
use ShortPixel\Model\File\DirectoryModel as DirectoryModel;

use ShortPixel\Controller\OptimizeController as OptimizeController;

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

    private function LoadFoldersFromResult($folders)
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

    /* Check if installation has custom image, or anything. To show interface */
    public function hasCustomImages()
    {
       if (! is_null(self::$hasCustomImages)) // prevent repeat
         return self::$hasCustomImages;

       $count = $this->getFolders(['only_count' => true, 'remove_hidden' => true]);

       if ($count == 0)
        $result = false;
      else
        $result = true;

      self::$hasCustomImages = $result;

      return $result;
    }


    public function addDirectory($path)
    {
       $fs = \wpSPIO()->filesystem();
       $directory = new DirectoryOtherMediaModel($path);
       $rootDir = $fs->getWPFileBase();
       $backupDir = $fs->getDirectory(SHORTPIXEL_BACKUP_FOLDER);

       if (! $directory->exists())
       {
          Notices::addError(__('Could not be added, directory not found: ' . $path ,'shortpixel-image-optimiser'));
          return false;
       }
       elseif (! $directory->isSubFolderOf($rootDir) && $directory->getPath() != $rootDir->getPath() )
       {
          Notices::addError( sprintf(__('The %s folder cannot be processed as it\'s not inside the root path of your website (%s).','shortpixel-image-optimiser'),$directory->getPath(), $rootDir->getPath()));
          return false;
       }
       elseif($directory->isSubFolderOf($backupDir) || $directory->getPath() == $backupDir->getPath() )
       {
          Notices::addError( __('This folder contains the ShortPixel Backups. Please select a different folder.','shortpixel-image-optimiser'));
          return false;
       }
       elseif( $this->checkIfMediaLibrary($directory) )
       {
          Notices::addError(__('This folder contains Media Library images. To optimize Media Library images please go to <a href="upload.php?mode=list">Media Library list view</a> or to <a href="upload.php?page=wp-short-pixel-bulk">ShortPixel Bulk page</a>.','shortpixel-image-optimiser'));
          return false;
       }
       elseif (! $directory->is_writable())
       {
         Notices::addError( sprintf(__('Folder %s is not writeable. Please check permissions and try again.','shortpixel-image-optimiser'),$directory->getPath()) );
         return false;
       }

       if (! $directory->get('in_db'))
       {
         Log::addDebug('Has no DB entry, on addDirectory', $directory);
         if ($directory->save())
         {
          $directory->updateFileContentChange();
          $directory->refreshFolder(true);
         }
       }
       else // if directory is already added, fail silently, but still refresh it.
       {
         if ($directory->isRemoved())
         {
            $directory->setStatus(DirectoryOtherMediaModel::DIRECTORY_STATUS_NORMAL);
            $directory->updateFileContentChange(); // does a save. Dunno if that's wise.
            $directory->refreshFolder(true);
         }
         else
          $directory->refreshFolder(false);
       }

      if ($directory->exists() && $directory->get('id') > 0)
        return $directory;
      else
        return false;
    }

/* @todo This should just be model
    public function refreshFolder(DirectoryOtherMediaModel $directory, $force = false)
    {
      $updated = $directory->updateFileContentChange();
      $update_time = $directory->getUpdated();
      if ($updated || $force)
      {

        // when forcing, set to never updated.
        if ($force)
        {
          $update_time = 0; // force from begin of times.
        }

        if ($directory->exists() )
        {
          $directory->refreshFolder($update_time);
        }
        else {
          Log::addWarn('Custom folder does not exist: ', $directory);
          return false;
        }
      }

    } */


    /** Check directory structure for new files */
    public function refreshFolders($force = false, $expires = 5 * MINUTE_IN_SECONDS)
    {
      $customFolders = $this->getActiveFolders();

      $cache = new CacheController();
      $refreshDelay = $cache->getItem('othermedia_refresh_folder_delay');

      if ($refreshDelay->exists() && ! $force)
      {
        return true;
      }

      $refreshDelay->setExpires($expires);
      $refreshDelay->save();

      foreach($customFolders as $directory) {

        $directory->refreshFolder($force);

      } // folders

      return true;
    }

    /* Check if this directory is part of the MediaLibrary */
    protected function checkifMediaLibrary(DirectoryModel $directory)
    {
      $fs = \wpSPIO()->filesystem();
      $uploadDir = $fs->getWPUploadBase();

        // if it's the uploads base dir, the media library would be included, so don't allow.
      if ($directory->getPath() == $uploadDir->getPath() )
         return true;
      elseif (! $directory->isSubFolderOf($uploadDir))// The easy check. No subdir, no problem.
           return false;
      elseif (is_numeric($directory->getName() )) // upload subdirs come in variation of year or month, both numeric.
          return true;
    }


    public function ajaxBrowseContent()
    {
      if ( ! $this->userIsAllowed )  {
          wp_die(__('You do not have sufficient permissions to access this page.','shortpixel-image-optimiser'));
      }
      $fs = \wpSPIO()->filesystem();
      $rootDirObj = $fs->getWPFileBase();
      $path = $rootDirObj->getPath();


      $postDir = isset($_POST['dir']) ? trim(sanitize_text_field($_POST['dir'])) : null;
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
        exit( __('This directory seems not part of WordPress', 'shortpixel-image-optimiser'));
      }

      if( $dirObj->exists() ) {

          //$dir = $fs->getDirectory($postDir);
    //      $files = $dirObj->getFiles();
          $subdirs = $fs->sortFiles($dirObj->getSubDirectories()); // runs through FS sort.


          foreach($subdirs as $index => $dir) // weed out the media library subdirectories.
          {
            $dirname = $dir->getName();
            if($dirname == 'ShortpixelBackups' || $this->checkifMediaLibrary($dir))
            {
               unset($subdirs[$index]);
            }
          }

          if( count($subdirs) > 0 ) {
              echo "<ul class='jqueryFileTree'>";
              foreach($subdirs as $dir ) {

                  $returnDir = substr($dir->getPath(), strlen($rootDirObj->getPath())); // relative to root.
                  $dirpath = $dir->getPath();
                  $dirname = $dir->getName();
                  // @todo Should in time be moved to othermedia_controller / check if media library

                  $htmlRel	= str_replace("'", "&apos;", $returnDir );
                  $htmlName	= htmlentities($dirname);
                  //$ext	= preg_replace('/^.*\./', '', $file);

                  if( $dir->exists()  ) {
                      //KEEP the spaces in front of the rel values - it's a trick to make WP Hide not replace the wp-content path
                          echo "<li class='directory collapsed'><a rel=' " .$htmlRel. "'>" . $htmlName . "</a></li>";
                  }

              }

              echo "</ul>";
          }
          elseif ($_POST['dir'] == '/')
          {
            echo "<ul class='jqueryFileTree'>";
            _e('No Directories found that can be added to Custom Folders', 'shortpixel-image-optimiser');
            echo "</ul>";
          }
      }

      die();
    }

    /* Get the custom Folders from DB, put them in model
    @return Array  Array database result
    */
    private function getFolders($args = array())
    {
      global $wpdb;
      $defaults = array(
          'id' => false,  // Get folder by Id
          'remove_hidden' => true, // Query only active folders
          'path' => false,
          'only_count' => false,
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
          //$mask[] = '%d';
          //$folders = $spMetaDao->getFolderByID($args['id']);
      }
      elseif($args['path'] !== false && strlen($args['path']) > 0)
      {
          //$folders = $spMetaDao->getFolder($args['path']);
          $sql .= ' AND path = %s';
          $prepare[] = $args['path'];
        //  $mask[] = $args['%s'];
      }
      else
      {
      //  $folders = $spMetaDao->getFolders();
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
        if (! is_null(self::$hasFoldersTable))
          return self::$hasFoldersTable;

        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $foldersTable = $wpdb->get_results("SELECT COUNT(1) hasFoldersTable FROM information_schema.tables WHERE table_schema='{$wpdb->dbname}' AND table_name='{$wpdb->prefix}shortpixel_folders'");

        if(isset($foldersTable[0]->hasFoldersTable) && $foldersTable[0]->hasFoldersTable > 0) {
            $result = true;
        }
        else
           $result = false;

        self::$hasFoldersTable = $result;

        return $result;
      }

      private function createFolderTable()
      {
        global $wpdb;

        if ($this->hasFolderTable())
          return;

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );


        $sql = "CREATE TABLE {$tablePrefix}shortpixel_folders (
             id mediumint(9) NOT NULL AUTO_INCREMENT,
             path varchar(512),
             name varchar(64),
             path_md5 char(32),
             file_count int,
             status SMALLINT NOT NULL DEFAULT 0,
             ts_updated timestamp,
             ts_created timestamp,
             UNIQUE KEY id (id)
           ) $charsetCollate;";


          $result = dbDelta($sql);

          return $result;
      }


} // Class
