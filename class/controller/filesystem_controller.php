<?php
namespace ShortPixel;
use ShortPixel\ShortpixelLogger\ShortPixelLogger as Log;


/** Controller for FileSystem operations
*
* This controller is used for -compound- ( complex ) FS operations, using the provided models File en Directory.
*/
Class FileSystemController extends ShortPixelController
{
    public function __construct()
    {
      $this->loadModel('file');
      $this->loadModel('directory');
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
}
