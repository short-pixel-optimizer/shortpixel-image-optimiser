<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


/** Controller for FileSystem operations
*
* This controller is used for -compound- ( complex ) FS operations, using the provided models File en Directory.
*/
Class FileSystemController extends ShortPixelController
{
    protected $env;

    public function __construct()
    {
      $this->loadModel('file');
      $this->loadModel('directory');
      $this->loadModel('environment');

      $this->env = new EnvironmentModel();

    }

    /** Get FileModel for a certain path. This can exist or not
    *
    * @param String Path Full Path to the file
    * @return FileModel FileModel Object. If file does not exist, not all values are set.
    */
    public function getFile($path)
    {
      return new FileModel($path);
    }

    /** Get DirectoryModel for a certain path. This can exist or not
    *
    * @param String $path Full Path to the Directory.
    * @return DirectoryModel Object with status set on current directory.
    */
    public function getDirectory($path)
    {
      return new DirectoryModel($path);
    }

    /** Get the BackupLocation for a FileModel. FileModel should be not a backup itself or it will recurse
    *
    *  For now this function should only be used for *new* backup files. Retrieving backup files via this method
    *  doesn't take into account legacy ways of storage.
    *
    * @param FileModel $file FileModel with file that needs a backup.
    * @return DirectoryModel | Boolean DirectoryModel pointing to the backup directory. Returns false if the directory could not be created, or initialized.
    */
    public function getBackupDirectory(FileModel $file)
    {
      $wp_home = get_home_path();
      $filepath = $file->getFullPath();

      // Implement this bastard better here.
      $backup_subdir = \ShortPixelMetaFacade::returnSubDir($filepath);

      $backup_fulldir = SHORTPIXEL_BACKUP_FOLDER . '/' . $backup_subdir;
      Log::addDebug('Get File BackupDirectory' . $backup_fulldir);

      $directory = $this->getDirectory($backup_fulldir);

      if ($directory->check())
        return $directory;
      else {
        return false;
      }
    }

    /** Not in use yet, do not use. Future replacement. */
    public function createBackUpFolder($folder = SHORTPIXEL_BACKUP_FOLDER)
    {

    }

    /** Utility function that tries to convert a file-path to a webURL.
    *
    * If possible, rely on other better methods to find URL ( e.g. via WP functions ).
    */
    public function pathToUrl(FileModel $file)
    {
      $filepath = $file->getFullPath();
      $directory = $file->getFileDir();

      // stolen from wp_get_attachment_url
      if ( ( $uploads = wp_get_upload_dir() ) && false === $uploads['error'] ) {
            // Check that the upload base exists in the file location.
            if ( 0 === strpos( $file, $uploads['basedir'] ) ) {
                // Replace file location with url location.
                $url = str_replace( $uploads['basedir'], $uploads['baseurl'], $filepath );
            } elseif ( false !== strpos( $file, 'wp-content/uploads' ) ) {
                // Get the directory name relative to the basedir (back compat for pre-2.7 uploads)
                $url = trailingslashit( $uploads['baseurl'] . '/' . _wp_get_attachment_relative_path( $file ) ) . wp_basename( $filepath );
            } else {
                // It's a newly-uploaded file, therefore $file is relative to the basedir.
                $url = $uploads['baseurl'] . "/$filepath";
            }
        }
        return $url;
    }





}
