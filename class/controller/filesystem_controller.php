<?php
namespace ShortPixel;

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

    /** Get FileModel for a certain path. This can exist or not */
    public function getFile($path)
    {
      return new FileModel($path);
    }

    /** Get DirectoryModel for a certain path. This can exist or not */
    public function getDirectory($path)
    {
      return new DirectoryModel($path);
    }

    /** Get the BackupLocation for a FileModel. FileModel should be not a backup itself or it will recurse */ 
    public function getBackupDirectory(FileModel $file)
    {

      //SHORTPIXEL_BACKUP_FOLDER
    }





}
