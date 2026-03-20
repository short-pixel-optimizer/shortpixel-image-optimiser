<?php

namespace ShortPixel\Controller;

if (! defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

use ShortPixel\ShortPixelLogger\ShortPixelLogger as Log;

use ShortPixel\Model\File\DirectoryModel as DirectoryModel;
use ShortPixel\Model\File\FileModel as FileModel;

use ShortPixel\Model\Image\MediaLibraryModel as MediaLibraryModel;
use ShortPixel\Model\Image\MediaLibraryThumbnailModel as MediaLibraryThumbnailModel;
use ShortPixel\Model\Image\CustomImageModel as CustomImageModel;

/**
 * Controller for FileSystem operations.
 *
 * This controller is used for compound (complex) FS operations, using the provided
 * models File and Directory. USE via \wpSPIO()->filesystem();
 *
 * @package ShortPixel\Controller
 */
class FileSystemController extends \ShortPixel\Controller
{
  /** @var object Environment object from wpSPIO */
  protected $env;

  /** @var array Static cache of loaded MediaLibraryModel objects, keyed by post ID */
  static $mediaItems = array();

  /** @var array Static cache of loaded CustomImageModel objects, keyed by ID */
  static $customItems = array();

  public function __construct()
  {
    $this->env = wpSPIO()->env();
  }

  /** Get FileModel for a certain path. This can exist or not
   *
   * @param string $path Full Path to the file
   * @return FileModel FileModel Object. If file does not exist, not all values are set.
   */
  public function getFile($path)
  {
    return new FileModel($path);
  }

  /**
   * Get MediaLibraryModel for a given attachment post ID.
   *
   * @param int  $id        The WordPress attachment post ID.
   * @param bool $useCache  If false, bypasses the static cache and fetches fresh data from the database.
   * @param bool $cacheOnly If true, only returns the item when it exists in cache; prevents a database fetch.
   * @return MediaLibraryModel|false The image model, or false if ID is invalid or file path could not be determined.
   */
  public function getMediaImage($id, $useCache = true, $cacheOnly = false)
  {

    if (! is_numeric($id)) {
      Log::addWarn('Get Media Image called without valid ID', $id);
      return false;
    }

    if ($useCache === true && isset(self::$mediaItems[$id])) {
      return self::$mediaItems[$id];
    }

    if (true === $cacheOnly)
      return false;

    $filepath = get_attached_file($id);
    $filepath = apply_filters('shortpixel_get_attached_file', $filepath, $id);

    // Somehow get_attached_file can return other random stuff.
    if ($filepath === false || strlen($filepath) == 0)
      return false;

    $imageObj = new MediaLibraryModel($id, $filepath);

    if (is_object($imageObj)) {
      self::$mediaItems[$id] = $imageObj;
    }

    return $imageObj;
  }

  /**
   * Get CustomImageModel for a given custom media item ID.
   *
   * @param int  $id       The custom media item ID.
   * @param bool $useCache Whether to use the static in-memory cache.
   * @return CustomImageModel The custom image model object.
   */
  public function getCustomImage($id, $useCache = true)
  {
    if ($useCache === true && isset(self::$customItems[$id])) {
      return self::$customItems[$id];
    }

    $imageObj = new CustomImageModel($id);

    if (is_object($imageObj)) {
      self::$customItems[$id] = $imageObj;
    }

    return $imageObj;
  }

  /**
   * Flush all cached image objects from static caches.
   *
   * Use sparingly — required for files that change (e.g. Enable Media Replace or
   * other filesystem-changing operations). Every call has a performance cost.
   *
   * @return void
   */
  public function flushImageCache()
  {
    self::$mediaItems = array();
    self::$customItems = array();
    MediaLibraryModel::onFlushImageCache();
  }

  /**
   * Remove a single image object from the static cache.
   *
   * @param MediaLibraryModel|CustomImageModel $imageObj The image model to evict from cache.
   * @return void
   */
  public function flushImage($imageObj)
  {
    $id = $imageObj->get('id');
    $type = $imageObj->get('type');

    if ('media' == $type && isset(self::$mediaItems[$id])) {
      unset(self::$mediaItems[$id]);
      MediaLibraryModel::onFlushImageCache();
    }
    if ('custom' == $type && isset(self::$customItems[$id])) {
      unset(self::$customItems[$id]);
    }
  }

  /**
   * Get a CustomImageModel stub for a path that is not yet in the database.
   *
   * Used to check whether a path qualifies as a custom-media path (not media library)
   * and whether the file should be included according to exclusion rules.
   *
   * @param string $path Full filesystem path to the image.
   * @param bool   $load Whether to load file metadata on the stub.
   * @return CustomImageModel A stub model with ID 0, not persisted to the database.
   */
  public function getCustomStub($path, $load = true)
  {
    $imageObj = new CustomImageModel(0);
    $imageObj->setStub($path, $load);
    return $imageObj;
  }

  /**
   * Generic helper that returns the correct image model based on type, avoiding
   * repeated type switches throughout the codebase.
   *
   * @param int    $id       The image ID (attachment post ID for media, custom ID for custom).
   * @param string $type     The image type: 'media' or 'custom'.
   * @param bool   $useCache Whether to use the static in-memory cache.
   * @return MediaLibraryModel|CustomImageModel|false The image model, or false on failure.
   */
  public function getImage($id,  $type, $useCache = true)
  {
    $imageObj = false;

    if ($type == 'media') {
      $imageObj = $this->getMediaImage($id, $useCache);
    } elseif ($type == 'custom') {
      $imageObj = $this->getCustomImage($id, $useCache);
    } else {
      Log::addError('FileSystemController GetImage - no correct type given: ' . $type);
    }
    return $imageObj;
  }


  /**
   * Get a thumbnail model for the original (pre-scaling) image of an attachment.
   *
   * Wraps wp_get_original_image_path() and applies a ShortPixel-specific filter
   * before returning the model.
   *
   * @param int $id The WordPress attachment post ID.
   * @return MediaLibraryThumbnailModel Thumbnail model representing the original image file.
   */
  public function getOriginalImage($id)
  {
    $filepath = \wp_get_original_image_path($id);
    $filepath = apply_filters('shortpixel_get_original_image_path', $filepath, $id);
    return new MediaLibraryThumbnailModel($filepath, $id, 'original');
  }

  /** Get DirectoryModel for a certain path. This can exist or not
   *
   * @param string $path Full Path to the Directory.
   * @return DirectoryModel Object with status set on current directory.
   */
  public function getDirectory($path)
  {
    return new DirectoryModel($path);
  }

  /**
   * Resolve and return the backup directory for a given file.
   *
   * Intended for new backup files only; does not account for legacy storage layouts.
   * If the file is virtual (e.g. offloaded), the path is translated before resolving.
   *
   * @param FileModel $file   The file whose backup directory should be found.
   * @param bool      $create Whether to create the backup directory if it does not yet exist.
   * @return DirectoryModel|false DirectoryModel pointing to the backup directory, or false if
   *                              the directory could not be created or initialised.
   */
  public function getBackupDirectory(FileModel $file, $create = false)
  {
    if (! function_exists('get_home_path')) {
      require_once(ABSPATH . 'wp-admin/includes/file.php');
    }
    $wp_home = \get_home_path();
    $filepath = $file->getFullPath();

    if ($file->is_virtual()) {
      $filepath = apply_filters('shortpixel/file/virtual/translate', $filepath, $file);
    }

    //  translate can return false if not properly offloaded / not found there.
    if ($filepath !== $file->getFullPath() && $filepath !== false) {
      $file = $this->getFile($filepath);
    }

    $fileDir = $file->getFileDir();


    $backup_subdir = $fileDir->getRelativePath();

    /*if ($backup_subdir === false)
      {
         $backup_subdir = $this->returnOldSubDir($filepath);
      } */

    $backup_fulldir = SHORTPIXEL_BACKUP_FOLDER . '/' . $backup_subdir;

    $directory = $this->getDirectory($backup_fulldir);

    $directory = apply_filters("shortpixel/file/backup_folder", $directory, $file);

    if ($create === false && $directory->exists())
      return $directory;
    elseif ($create === true && $directory->check()) // creates directory if needed.
      return $directory;
    else {
      return false;
    }
  }

  /**
   * Get the base directory from which custom media paths are resolved.
   *
   * Returns ABSPATH for the main site, or the uploads base directory for subsites in
   * a multisite network.
   *
   * @return DirectoryModel The resolved WP file base directory.
   */
  public function getWPFileBase()
  {
    if (\wpSPIO()->env()->is_mainsite) {
      $path = (string) $this->getWPAbsPath();
    } else {
      $up = wp_upload_dir();
      $path = realpath($up['basedir']);
    }
    $dir = $this->getDirectory($path);
    if (! $dir->exists())
      Log::addWarn('getWPFileBase - Base path doesnt exist');

    return $dir;
  }

  /**
   * Return the WordPress uploads base directory (e.g. /wp-content/uploads).
   *
   * @return DirectoryModel DirectoryModel pointing to the uploads base directory.
   */
  public function getWPUploadBase()
  {
    $upload_dir = wp_upload_dir(null, false);

    return $this->getDirectory($upload_dir['basedir']);
  }

  /**
   * Return the absolute path of the WordPress installation where the content directory lives.
   *
   * Normally identical to ABSPATH, but handles non-standard installations where wp-content
   * is placed outside the document root. The returned path is used to convert file paths
   * to URLs by replacing it with home_url().
   *
   * @return DirectoryModel Either ABSPATH or the directory that contains WP_CONTENT_DIR.
   */
  public function getWPAbsPath()
  {

    $wpContentPos = strpos(WP_CONTENT_DIR, 'wp-content');
    // Check if Content DIR actually has wp-content in it.
    if (false !== $wpContentPos) {
      $wpContentAbs = substr(WP_CONTENT_DIR, 0, $wpContentPos); //str_replace( 'wp-content', '', WP_CONTENT_DIR);
    } else {
      $wpContentAbs = WP_CONTENT_DIR;
    }

    if (ABSPATH == $wpContentAbs)
      $abspath = ABSPATH;
    else
      $abspath = $wpContentAbs;

    // If constants UPLOADS is defined -AND- there is a blogs.dir in it, add it like this. UPLOAD constant alone is not enough since it can cause ugly doublures in the path if there is another style config.
    if (defined('UPLOADS') && strpos(UPLOADS, 'blogs.dir') !== false) {
      $abspath = trailingslashit(ABSPATH) . UPLOADS;
    }

    $abspath = apply_filters('shortpixel/filesystem/abspath', $abspath);

    return $this->getDirectory($abspath);
  }



  /** Not in use yet, do not use. Future replacement. */
  public function checkBackUpFolder($folder = SHORTPIXEL_BACKUP_FOLDER)
  {
    $dirObj = $this->getDirectory($folder);
    $result = $dirObj->check(true);  // check creates the whole structure if needed.
    return $result;
  }


  /**
   * Attempt to convert a filesystem path to a web-accessible URL.
   *
   * Handles standard uploads, multisite sub-directory and sub-domain setups,
   * legacy pre-2.7 upload paths, and protocol-relative or scheme-less URLs.
   * When possible, prefer WordPress-native functions or WP attachment functions
   * over this utility.
   *
   * @param FileModel $file The file whose URL should be determined.
   * @return string|false The absolute URL string, or false if it could not be resolved.
   */
  public function pathToUrl(FileModel $file)
  {
    $filepath = $file->getFullPath();
    $directory = $file->getFileDir();

    $is_multi_site = $this->env->is_multisite;
    $is_main_site =  $this->env->is_mainsite;

    // stolen from wp_get_attachment_url
    if (($uploads = wp_get_upload_dir()) && (false === $uploads['error'] || strlen(trim($uploads['error'])) == 0)) {
      // Check that the upload base exists in the file location.
      if (0 === strpos($filepath, $uploads['basedir'])) { // Simple as it should, filepath and basedir share.
        // Replace file location with url location.
        $url = str_replace($uploads['basedir'], $uploads['baseurl'], $filepath);
      }
      // Multisite backups are stored under uploads/ShortpixelBackups/etc , but basedir would include uploads/sites/2 etc, not matching above
      // If this is case, test if removing the last two directories will result in a 'clean' uploads reference.
      // This is used by getting preview path ( backup pathToUrl) in bulk and for comparer..
      elseif ($is_multi_site && ! $is_main_site  && 0 === strpos($filepath, dirname(dirname($uploads['basedir'])))) {

        $url = str_replace(dirname(dirname($uploads['basedir'])), dirname(dirname($uploads['baseurl'])), $filepath);
        $homeUrl = home_url();

        // The result didn't end in a full URL because URL might have less subdirs ( dirname dirname) .
        // This happens when site has blogs.dir (sigh) on a subdomain . Try to substitue the ABSPATH root with the home_url
        if (strpos($url, $homeUrl) === false) {
          $url = str_replace(trailingslashit(ABSPATH), trailingslashit($homeUrl), $filepath);
        }
      } elseif (false !== strpos($filepath, 'wp-content/uploads')) {
        // Get the directory name relative to the basedir (back compat for pre-2.7 uploads)
        $url = trailingslashit($uploads['baseurl'] . '/' . _wp_get_attachment_relative_path($filepath)) . wp_basename($filepath);
      } else {
        // It's a newly-uploaded file, therefore $file is relative to the basedir.
        $url = $uploads['baseurl'] . "/$filepath";
      }
    }

    $wp_home_path = (string) $this->getWPAbsPath();
    // If the whole WP homepath is still in URL, assume the replace when wrong ( not replaced w/ URL)
    // This happens when file is outside of wp_uploads_dir
    if (strpos($url, $wp_home_path) !== false) {

      // Check if content URL and dir are defined, go look there.
      if (defined('WP_CONTENT_URL') && defined('WP_CONTENT_DIR')) {
        $content_dir = WP_CONTENT_DIR;
        $relative = str_replace(WP_CONTENT_DIR, '', $filepath);
        $url = content_url($relative);
      }


      if (strpos($url, $wp_home_path) !== false) {
        // This is SITE URL, for the same reason it should be home_url in FILEMODEL. The difference is when the site is running on a subdirectory
        // (1) ** This is a fix for a real-life issue, do not change if this causes issues, another fix is needed then.
        // (2) ** Also a real life fix when a path is /wwwroot/assets/sites/2/ etc, in get site url, the home URL is the site URL, without appending the sites stuff. Fails on original image.
        if ($is_multi_site && ! $is_main_site) {
          $wp_home_path = trailingslashit($uploads['basedir']);
          $home_url = trailingslashit($uploads['baseurl']);
        } else
          $home_url = trailingslashit(get_site_url()); // (1)
        $url = str_replace($wp_home_path, $home_url, $filepath);
      }
    }

    // can happen if there are WP path errors.
    if (is_null($url))
      return false;

    $parsed = parse_url($url); // returns array, null, or false.

    // Some hosts set the content dir to a relative path instead of a full URL. Api can't handle that, so add domain and such if this is the case.
    if (!isset($parsed['scheme'])) { //no absolute URLs used -> we implement a hack

      if (isset($parsed['host'])) // This is for URL's for // without http or https. hackhack.
      {
        $scheme = is_ssl() ? 'https:' : 'http:';
        return $scheme . $url;
      } else {
        // From Metafacade. Multiple solutions /hacks.
        $home_url = trailingslashit((function_exists("is_multisite") && is_multisite()) ? trim(network_site_url("/")) : trim(home_url()));
        return $home_url . ltrim($url, '/'); //get the file URL
      }
    }

    if (! is_null($parsed) && $parsed !== false)
      return $url;

    return false;
  }

  /**
   * Ensure a URL is absolute by prepending the site URL when needed, then apply filters.
   *
   * @param string $url The URL or path to normalise.
   * @return string The verified, filter-applied URL.
   */
  public function checkURL($url)
  {
    if (! $this->pathIsURL($url)) {
      //$siteurl = get_option('siteurl');
      if (strpos($url, get_site_url()) == false) {
        $url = get_site_url(null, $url);
      }
    }

    return apply_filters('shortpixel/filesystem/url', $url);
  }

  /**
   * Check if a given string looks like a URL rather than a filesystem path.
   *
   * Detects http/https prefixes, protocol-relative URLs (//), and custom schemes
   * such as those used by S3 offload plugins.
   *
   * @param string $path The path or URL string to check.
   * @return bool True if the string appears to be a URL, false otherwise.
   */
  public function pathIsUrl($path)
  {
    $is_http = (substr($path, 0, 4) == 'http') ? true : false;
    $is_https = (substr($path, 0, 5) == 'https') ? true : false;
    $is_neutralscheme = (substr($path, 0, 2) == '//') ? true : false; // when URL is relative like //wp-content/etc
    $has_urldots = (strpos($path, '://') !== false) ? true : false; // Like S3 offloads

    if ($is_http || $is_https || $is_neutralscheme || $has_urldots)
      return true;
    else
      return false;
  }

  /**
   * Sort an array of FileModel or DirectoryModel objects alphabetically by name.
   *
   * @param array $array Array of FileModel or DirectoryModel objects to sort.
   * @param array $args  Optional future arguments for alternative sort modes (currently unused).
   * @return array The sorted array, or the original array if it is empty.
   */
  public function sortFiles($array, $args = array())
  {
    if (count($array) == 0)
      return $array;

    // what are we sorting.
    $class = get_class($array[0]);
    $is_files = ($class == 'ShortPixel\FileModel') ? true : false; // if not files, then dirs.

    usort(
      $array,
      function ($a, $b) use ($is_files) {
        if ($is_files)
          return strcmp($a->getFileName(), $b->getFileName());
        else {
          return strcmp($a->getName(), $b->getName());
        }
      }
    );

    return $array;
  }


  /**
   * Recursively collect all files under a directory tree.
   *
   * @param DirectoryModel $dir     The root directory to start from.
   * @param array          $filters Optional filter arguments as accepted by DirectoryModel::getFiles().
   * @return array Array of FileModel objects found under the given directory tree.
   */
  public function getFilesRecursive(DirectoryModel $dir, $filters = array())
  {
    $fileArray = array();

    if (! $dir->exists())
      return $fileArray;

    $files = $dir->getFiles($filters);
    $fileArray = array_merge($fileArray, $files);

    $subdirs = $dir->getSubDirectories();

    foreach ($subdirs as $subdir) {
      $fileArray = array_merge($fileArray, $this->getFilesRecursive($subdir, $filters));
    }

    return $fileArray;
  }

  /**
   * Check whether a remote URL responds with an HTTP 200 status code.
   *
   * Uses cURL. Returns null when cURL is not available. Use sparingly as each call
   * makes a live HTTP request.
   *
   * @param string $url The URL to check.
   * @return bool|null True if the URL is reachable with HTTP 200, false otherwise, or null if cURL is unavailable.
   */
  public function url_exists($url)
  {
    if (! \wpSPIO()->env()->is_function_usable('curl_init')) {
      return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseCode == 200) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Enable trusted mode for filesystem models.
   *
   * While active, FileModel and DirectoryModel instances skip existence checks and
   * other filesystem operations for performance. Only activates when the environment
   * reports that trusted mode is allowed.
   *
   * @return void
   */
  public function startTrustedMode()
  {
    if (\wpSPIO()->env()->useTrustedMode()) {
      FileModel::$TRUSTED_MODE = true;
      DirectoryModel::$TRUSTED_MODE = true;
    }
  }

  /**
   * Disable trusted mode, restoring normal filesystem validation in models.
   *
   * @return void
   */
  public function endTrustedMode()
  {
    if (\wpSPIO()->env()->useTrustedMode()) {
      FileModel::$TRUSTED_MODE = false;
      DirectoryModel::$TRUSTED_MODE = false;
    }
  }

  /**
   * Move log files between the backup folder and a temporary directory, or vice versa.
   *
   * Used to preserve log files across operations that clear the backup folder (e.g.
   * plugin reinstallation). Pass `to_temp => true` to move logs to the system temp
   * directory, or `to_temp => false` to move them back.
   *
   * @param array $args {
   *     Optional. Arguments controlling the move direction.
   *
   *     @type bool $to_temp True to move from backup folder to temp (default), false for the reverse.
   * }
   * @return false Always returns false.
   */
  public function moveLogFiles($args = [])
  {
    $defaults = [
      'to_temp' => true,
    ];

    $args = wp_parse_args($args, $defaults);

    $tempDir = trailingslashit(sys_get_temp_dir());
    $tmpLocation = $tempDir . 'logmove';

    if (true === $args['to_temp'])
    {
      $sourcePath = SHORTPIXEL_BACKUP_FOLDER;
      $targetPath = $tmpLocation;
    }
    else
    {
      $sourcePath = $tmpLocation;
      $targetPath = SHORTPIXEL_BACKUP_FOLDER;
    }

    $logFiles = $files = glob(trailingslashit($sourcePath) . "*.log");



    if (false !== $logFiles && is_array($logFiles) && count($logFiles) > 0)
    {
        $sourceDir = $this->getDirectory($sourcePath);
        $sourceDir->check(); // Check if not create, will be needed if backup dir is removed.

        $destinationDir = $this->getDirectory($targetPath);
        $destinationDir->check();

       foreach($logFiles as $filePath)
       {
         $file = $this->getFile($filePath);
         $fileName = $file->getFileName();

         $targetFile = $this->getFile($destinationDir->getPath() . $fileName);

         $bool = $file->move($targetFile);

         if ( false === $bool )
         {
           Log::addWarn('FAILED moving LogFile from '  . $file->getFullPath() . ' to ' . $targetFile->getFullPath());
         }
         else
         {
          Log::addInfo('LogFile moved from '  . $file->getFullPath() . ' to ' . $targetFile->getFullPath() );
         }
       }

    }

    if (false === $args['to_temp'])
    {
       rmdir($tmpLocation); //cleanup
    }


    return false;
  }

} // class
